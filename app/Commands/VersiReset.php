<?php

namespace App\Commands;

use App\Models\DokumenVersiModel;
use App\Services\Version\ArsipRegistry;
use App\Services\Version\VersionScope;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Kembalikan keadaan versi sebuah dokumen ke kondisi bersih pasca-migrasi.
 *
 *   php spark versi:reset --modul renstra --opd 11
 *   php spark versi:reset --modul renstra --semua
 *   php spark versi:reset --modul renstra --opd 11 --db dv_test
 *
 * =====================================================================
 * YANG DIHAPUS DAN YANG TIDAK
 *
 * DIHAPUS  : versi hasil percobaan (V2 ke atas), seluruh arsipnya, jejak
 *            auditnya, dan permintaan koreksinya.
 * DIKEMBALIKAN : baseline V1 ke keadaan semula — published, arsip kosong,
 *            berlaku 1 Januari awal periode, tanpa pemilik.
 * TIDAK DISENTUH : isi Renstra/RPJMD yang sebenarnya. Sasaran, indikator, dan
 *            target di tabel live tetap utuh — yang dibersihkan hanya lapisan
 *            versinya.
 *
 * Baris yang sempat dipensiunkan oleh versi yang dihapus DIPULIHKAN, supaya
 * pembersihan tidak meninggalkan data yang tak terlihat di layar.
 * =====================================================================
 */
class VersiReset extends BaseCommand
{
    protected $group       = 'SAKIP';
    protected $name        = 'versi:reset';
    protected $description = 'Kembalikan lapisan versi ke kondisi bersih pasca-migrasi (isi dokumen tidak disentuh).';
    protected $usage       = 'versi:reset --modul <modul> [--opd <id>|--semua] [--db <basis>] [--paksa]';
    protected $options     = [
        '--modul' => 'rpjmd | renstra | iku | lakip (wajib)',
        '--opd'   => 'opd_key yang dibersihkan',
        '--semua' => 'bersihkan seluruh OPD pada modul tersebut',
        '--db'    => 'basis data lain (mis. salinan uji)',
        '--paksa' => 'lewati konfirmasi',
    ];

    public function run(array $params)
    {
        $modul  = $params['modul'] ?? CLI::getOption('modul');
        $opd    = $params['opd'] ?? CLI::getOption('opd');
        $semua  = in_array('--semua', $_SERVER['argv'] ?? [], true);
        $paksa  = in_array('--paksa', $_SERVER['argv'] ?? [], true);
        $namaDb = $params['db'] ?? CLI::getOption('db');

        if ($modul === null || $modul === true || ! in_array($modul, VersionScope::MODUL, true)) {
            CLI::error('Sebutkan --modul: ' . implode(' | ', VersionScope::MODUL));

            return 1;
        }

        if (! $semua && ($opd === null || $opd === true)) {
            CLI::error('Sebutkan --opd <id> atau --semua.');

            return 1;
        }

        $cfg = config('Database')->default;

        if ($namaDb !== null && $namaDb !== true && trim((string) $namaDb) !== '') {
            $cfg['database'] = trim((string) $namaDb);
        }

        $db    = db_connect($cfg, false);
        $model = new DokumenVersiModel($db);
        $arsip = (new ArsipRegistry($db))->untuk($modul);

        CLI::write('Basis data : ' . CLI::color($db->getDatabase(), 'yellow'));
        CLI::write('Modul      : ' . CLI::color($modul, 'yellow'));
        CLI::write('Lingkup    : ' . CLI::color($semua ? 'SELURUH OPD' : ('opd_key ' . (int) $opd), 'yellow'));

        if (! $model->siap()) {
            CLI::error('Tabel dokumen_versi belum ada.');

            return 1;
        }

        $b = $db->table('dokumen_versi')->where('modul', $modul);

        if (! $semua) {
            $b->where('opd_key', (int) $opd);
        }

        $versi = $b->orderBy('opd_key', 'ASC')->orderBy('version_no', 'ASC')->get()->getResultArray();

        if ($versi === []) {
            CLI::write(CLI::color('Tidak ada versi pada lingkup itu — sudah bersih.', 'green'));

            return 0;
        }

        // Baseline = V1 per lingkup; sisanya hasil percobaan.
        $baseline = [];
        $buang    = [];

        foreach ($versi as $v) {
            $kunci = $v['opd_key'] . '|' . $v['periode_mulai'] . '|' . $v['periode_akhir'];

            if ((int) $v['version_no'] === 1 && ! isset($baseline[$kunci])) {
                $baseline[$kunci] = $v;
            } else {
                $buang[] = $v;
            }
        }

        CLI::newLine();
        CLI::write('Akan DIHAPUS ' . CLI::color((string) count($buang), 'red') . ' versi:');

        foreach ($buang as $v) {
            CLI::write('  - V' . $v['version_no'] . '  ' . $v['label']
                . '  [' . $v['status'] . ']  opd ' . $v['opd_key']);
        }

        CLI::write('Akan DIKEMBALIKAN ' . CLI::color((string) count($baseline), 'yellow')
            . ' baseline ke kondisi semula (arsip dikosongkan).');
        CLI::write(CLI::color('Isi Renstra/RPJMD di tabel live TIDAK disentuh.', 'green'));
        CLI::newLine();

        if (! $paksa && strtolower((string) CLI::prompt('Lanjutkan? (y/N)', 'N')) !== 'y') {
            CLI::write('Dibatalkan.');

            return 0;
        }

        $idBuang = array_map(static fn ($v) => (int) $v['id'], $buang);
        $n       = [
            'versi_dihapus' => 0, 'arsip_dikosongkan' => 0, 'pensiun_dipulihkan' => 0,
            'live_ditautkan' => 0, 'izin_sunting_dibuang' => 0,
        ];

        try {
            $db->transBegin();
            $db->resetTransStatus();

            // 1. Pulihkan baris yang dipensiunkan versi-versi yang dibuang.
            //    Dilakukan LEBIH DULU: setelah versinya hilang, alasannya tidak
            //    bisa lagi dicocokkan dan barisnya akan tinggal tak terlihat.
            foreach ($idBuang as $vid) {
                foreach ($this->tabelPensiun($modul) as $tabel) {
                    if (! $db->tableExists($tabel)) {
                        continue;
                    }

                    $n['pensiun_dipulihkan'] += (int) $db->table($tabel)
                        ->like('alasan_dihentikan', 'dokumen_versi id ' . $vid . ')', 'before')
                        ->countAllResults(false);

                    $db->table($tabel)
                        ->like('alasan_dihentikan', 'dokumen_versi id ' . $vid . ')', 'before')
                        ->update([
                            'dihentikan_pada'   => null,
                            'berlaku_sampai'    => null,
                            'alasan_dihentikan' => null,
                        ]);
                }
            }

            // 1b. Izin sunting pada lingkup itu ikut dibuang.
            //
            // Izin yang tertinggal menggantung pada versi yang sudah tidak ada,
            // dan yang lebih buruk: izin berstatus `disetujui` membuat Renstra
            // terbaca "sedang disunting" padahal seluruh versinya baru saja
            // dikembalikan ke titik nol. Keadaan yang tidak mungkin terjadi
            // lewat alur normal, jadi tidak ada satu pun layar yang siap
            // menampilkannya dengan benar.
            if ($db->tableExists('dokumen_izin_sunting')) {
                $bIzin = $db->table('dokumen_izin_sunting')->where('modul', $modul);

                if (! $semua) {
                    $bIzin->where('opd_key', (int) $opd);
                }

                $n['izin_sunting_dibuang'] = (int) $bIzin->countAllResults(false);
                $bIzin->delete();
            }

            // 2. Buang jejak & koreksi lebih dulu — FK-nya sengaja RESTRICT.
            if ($idBuang !== []) {
                $db->table('version_correction_requests')->whereIn('version_id', $idBuang)->delete();
                $db->table('version_submission_history')->whereIn('version_id', $idBuang)->delete();
                $db->table('dokumen_versi')->whereIn('id', $idBuang)->delete();
                $n['versi_dihapus'] = count($idBuang);
            }

            // 3. Kembalikan baseline ke keadaan semula.
            foreach ($baseline as $v) {
                $vid = (int) $v['id'];

                if ($arsip !== null && $arsip->siap()) {
                    $arsip->kosongkan($vid);
                    $n['arsip_dikosongkan']++;
                }

                $db->table('version_correction_requests')->where('version_id', $vid)->delete();

                // Jejak baseline disisakan SATU baris 'published' saja — itulah
                // yang dibuat migrasi; sisanya jejak percobaan.
                $db->table('version_submission_history')->where('version_id', $vid)
                    ->where('aksi !=', 'published')->delete();

                $db->table('dokumen_versi')->where('id', $vid)->update([
                    'label'                  => 'V1 — Kondisi Awal ' . strtoupper($modul)
                        . ' ' . $v['periode_mulai'] . '-' . $v['periode_akhir'],
                    'effective_from'         => $v['periode_mulai'] . '-01-01',
                    'effective_to'           => null,
                    'status'                 => DokumenVersiModel::STATUS_PUBLISHED,
                    'created_by'             => null,
                    'submitted_by'           => null,
                    'submitted_at'           => null,
                    'approved_by'            => null,
                    'cancelled_by'           => null,
                    'cancelled_at'           => null,
                    'copied_from_version_id' => null,
                    'mulai_dari_kosong'      => 0,
                    'ref_id'                 => null,
                    'alasan_perubahan'       => null,
                    'dasar_perubahan'        => null,
                    'nomor_dasar'            => null,
                    'tanggal_dasar'          => null,
                ]);

                // Tunjukan tampilan utama ikut dilepas: setelah dikembalikan ke
                // kondisi pasca-migrasi, arsipnya kosong, dan menunjuk versi
                // kosong sebagai tampilan utama hanya menyajikan tabel kosong.
                if ($db->fieldExists('tampilan_utama', 'dokumen_versi')) {
                    $db->table('dokumen_versi')->where('id', $vid)->update([
                        'tampilan_utama' => 0, 'tampilan_oleh' => null, 'tampilan_pada' => null,
                    ]);
                }

                // 4. Tautkan ulang baris live ke baseline, supaya tidak ada
                //    version_id yang menunjuk versi yang sudah dihapus.
                $n['live_ditautkan'] += $this->tautkanUlang($db, $modul, $v, $vid);
            }

            if ($db->transStatus() === false) {
                $db->transRollback();
                CLI::error('Gagal: salah satu query ditolak.');

                return 1;
            }

            $db->transCommit();
        } catch (Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            CLI::error('Gagal: ' . $e->getMessage());

            return 1;
        }

        CLI::newLine();

        foreach ($n as $k => $v) {
            CLI::write('  ' . CLI::color('OK   ', 'green') . str_replace('_', ' ', $k) . ': ' . $v);
        }

        CLI::newLine();
        CLI::write(CLI::color('Selesai. Jalankan db/postdeploy_2026-08-20_versioning.sql untuk memastikan.', 'green'));

        return 0;
    }

    /** Tabel live yang punya kolom pensiun, per modul. */
    private function tabelPensiun(string $modul): array
    {
        return $modul === VersionScope::MODUL_RPJMD
            ? ['rpjmd_misi', 'rpjmd_tujuan', 'rpjmd_indikator_tujuan', 'rpjmd_sasaran', 'rpjmd_indikator_sasaran']
            : ['renstra_tujuan', 'renstra_indikator_tujuan', 'renstra_sasaran', 'renstra_indikator_sasaran'];
    }

    /** Arahkan kembali version_id baris live ke baseline lingkupnya. */
    private function tautkanUlang($db, string $modul, array $v, int $vid): int
    {
        if ($modul === VersionScope::MODUL_RENSTRA) {
            $db->table('renstra_sasaran')
                ->where('opd_id', (int) $v['opd_key'])
                ->where('tahun_mulai', (int) $v['periode_mulai'])
                ->where('tahun_akhir', (int) $v['periode_akhir'])
                ->update(['version_id' => $vid]);

            $jml = $db->affectedRows();

            $db->query(
                'UPDATE renstra_indikator_sasaran i
                   JOIN renstra_sasaran s ON s.id = i.renstra_sasaran_id
                    SET i.version_id = ?
                  WHERE s.opd_id = ? AND s.tahun_mulai = ? AND s.tahun_akhir = ?',
                [$vid, (int) $v['opd_key'], (int) $v['periode_mulai'], (int) $v['periode_akhir']]
            );

            return $jml;
        }

        if ($modul === VersionScope::MODUL_RPJMD) {
            $db->table('rpjmd_misi')
                ->where('tahun_mulai', (int) $v['periode_mulai'])
                ->where('tahun_akhir', (int) $v['periode_akhir'])
                ->update(['version_id' => $vid]);

            return $db->affectedRows();
        }

        return 0;
    }
}
