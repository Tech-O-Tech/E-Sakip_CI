<?php

namespace App\Commands;

use App\Models\Opd\IkuModel;
use App\Models\Opd\IkuRevisiModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Sync IKU dari Renstra lewat baris perintah.
 *
 *   php spark iku:sync-renstra                    laporan seluruh OPD
 *   php spark iku:sync-renstra --opd 37           pratinjau satu OPD
 *   php spark iku:sync-renstra --opd 37 --fix     kerjakan
 *
 * =====================================================================
 * MENGAPA ADA
 *
 * Tombol "Sync IKU dari Renstra" hanya bisa dijalankan oleh akun yang
 * terikat pada satu OPD. Menyiapkan IKU untuk beberapa OPD sekaligus —
 * misalnya kecamatan yang IKU-nya belum pernah disusun — berarti masuk
 * bergantian ke tiap akun. Perintah ini menempuh jalur yang SAMA tanpa
 * itu.
 *
 * =====================================================================
 * MEMAKAI MESIN YANG SAMA, BUKAN TIRUANNYA
 *
 * Seluruh pekerjaan diserahkan ke IkuModel::getKandidatSync() dan
 * IkuModel::importSync() — dua metode yang dipanggil layar Sync. Tidak
 * ada logika penyalinan yang ditulis ulang di sini, sehingga perintah ini
 * tidak bisa menyimpang dari perilaku layarnya.
 *
 * =====================================================================
 * YANG SENGAJA DITOLAK
 *
 * Bila lingkup itu punya revisi IKU yang BERLAKU, hasil sync seharusnya
 * masuk ke DRAFT revisi, bukan ke IKU berjalan — dan pemilihan draftnya
 * butuh keputusan manusia. Perintah ini menolak mengerjakannya dan
 * menyuruh memakai layar Sync. Lihat IkuFormTrait::muaraSync().
 */
class IkuSyncRenstra extends BaseCommand
{
    protected $group       = 'SAKIP';
    protected $name        = 'iku:sync-renstra';
    protected $description = 'Salin sasaran & indikator Renstra ke IKU OPD (jalur yang sama dengan tombol Sync).';
    protected $usage       = 'iku:sync-renstra [--opd <id>] [--periode 2025-2029] [--fix]';
    protected $options     = [
        '--opd'     => 'Batasi pada satu OPD. Tanpa ini hanya menampilkan daftar.',
        '--periode' => 'Periode Renstra, mis. 2025-2029. Tanpa ini seluruh periode OPD itu.',
        '--fix'     => 'Benar-benar mengerjakan. Tanpa ini hanya pratinjau.',
        '--ganti'   => 'MODE GANTI: buang juga isi IKU yang tidak ada di Renstra.',
    ];

    /**
     * Keranjang sync: 'baru' ditambahkan, 'berubah' diambil ulang dari sumber.
     * 'sama' tidak masuk keranjang mana pun.
     *
     * Sumber kebenaran perilakunya ada di
     * App\Controllers\Concerns\IkuFormTrait::keranjangSyncPenuh(); trait itu
     * terikat pada $this->request sehingga tidak bisa dipakai dari CLI.
     *
     * @return array{0: array<int,int[]>, 1: array<int,int[]>}
     */
    private function keranjang(array $kandidat): array
    {
        $baru = $berubah = [];

        foreach ($kandidat as $sasaran) {
            $idSasaran = (int) ($sasaran['sumber_id'] ?? 0);
            if ($idSasaran <= 0) {
                continue;
            }

            foreach ($sasaran['indikator'] ?? [] as $ind) {
                $idInd = (int) ($ind['sumber_id'] ?? 0);
                if ($idInd <= 0) {
                    continue;
                }

                if (($ind['banding'] ?? '') === 'baru') {
                    $baru[$idSasaran][] = $idInd;
                } elseif (($ind['banding'] ?? '') === 'berubah') {
                    $berubah[$idSasaran][] = $idInd;
                }
            }
        }

        return [$baru, $berubah];
    }

    /** Daftar OPD yang punya Renstra, beserta seberapa jauh IKU-nya tertinggal. */
    private function daftar($db): void
    {
        $rows = $db->query("
            SELECT o.id, o.nama_opd,
                   COUNT(DISTINCT rs.id) AS sasaran_renstra,
                   (SELECT COUNT(*) FROM iku_sasaran s WHERE s.opd_id = o.id) AS sasaran_iku,
                   (SELECT COUNT(*) FROM cascading_sasaran_opd c
                     WHERE c.opd_id = o.id AND c.iku_indikator_id IS NULL) AS cascading_belum_berjangkar
              FROM opd o
              JOIN renstra_sasaran rs ON rs.opd_id = o.id
             GROUP BY o.id, o.nama_opd
            HAVING cascading_belum_berjangkar > 0
             ORDER BY cascading_belum_berjangkar DESC
        ")->getResultArray();

        if ($rows === []) {
            CLI::write('  Semua baris cascading sudah berjangkar IKU.', 'green');

            return;
        }

        CLI::write('OPD yang cascading-nya masih ada yang belum berjangkar IKU:', 'cyan');
        CLI::newLine();
        CLI::table(
            array_map(static fn ($r) => [
                $r['id'], $r['nama_opd'], $r['sasaran_renstra'], $r['sasaran_iku'],
                $r['cascading_belum_berjangkar'],
                $r['sasaran_iku'] == 0 ? 'IKU belum ada -> sync' : 'IKU ada -> periksa redaksinya',
            ], $rows),
            ['OPD', 'Nama', 'Sasaran Renstra', 'Sasaran IKU', 'Baris belum berjangkar', 'Dugaan']
        );
        CLI::newLine();
        CLI::write('Jalankan: php spark iku:sync-renstra --opd <id>   untuk pratinjau.', 'yellow');
    }

    public function run(array $params)
    {
        $db    = Database::connect();
        $model = new IkuModel();
        $rev   = new IkuRevisiModel();

        CLI::write('Basis data: ' . $db->getDatabase(), 'yellow');
        CLI::newLine();

        $opdOpt = CLI::getOption('opd');
        $fix    = array_key_exists('fix', $params) || CLI::getOption('fix') !== null;
        $ganti  = array_key_exists('ganti', $params) || CLI::getOption('ganti') !== null;

        if ($opdOpt === null || $opdOpt === true) {
            $this->daftar($db);

            return 0;
        }

        $opdId = (int) $opdOpt;
        $nama  = $db->table('opd')->select('nama_opd')->where('id', $opdId)
            ->get()->getRowArray()['nama_opd'] ?? null;

        if ($nama === null) {
            CLI::error('OPD ' . $opdId . ' tidak ada.');

            return 1;
        }

        CLI::write($nama . ' (opd ' . $opdId . ')', 'cyan');

        $periodeOpt = CLI::getOption('periode');
        $periodes   = $model->getPeriodeSumber('renstra', $opdId);

        if ($periodeOpt !== null && $periodeOpt !== true) {
            $periodes = array_intersect_key($periodes, [(string) $periodeOpt => true]);
        }

        if ($periodes === []) {
            CLI::error('Tidak ada periode Renstra untuk OPD ini' . ($periodeOpt ? ' pada ' . $periodeOpt : '') . '.');

            return 1;
        }

        $adaKerja = false;

        foreach ($periodes as $kunci => $p) {
            $tm = (int) $p['tahun_mulai'];
            $ta = (int) $p['tahun_akhir'];

            CLI::newLine();
            CLI::write('  Periode ' . $kunci, 'cyan');

            // Revisi berlaku -> hasil sync harus masuk draft revisi, dan draftnya
            // dipilih manusia. Bukan urusan perintah ini.
            if ($rev->siap() && $rev->revisiBerlaku($opdId, $tm, $ta) !== null) {
                CLI::write('    DILEWATI — ada revisi IKU yang berlaku pada periode ini.', 'yellow');
                CLI::write('    Hasil sync harus masuk draft revisi; pakai layar Sync IKU.');

                continue;
            }

            $kandidat = $model->getKandidatSync('renstra', $opdId, $tm, $ta);

            if ($kandidat === []) {
                CLI::write('    Tidak ada sasaran Renstra pada periode ini.');

                continue;
            }

            [$baru, $berubah] = $this->keranjang($kandidat);

            $jmlBaru    = array_sum(array_map('count', $baru));
            $jmlBerubah = array_sum(array_map('count', $berubah));

            // MODE GANTI dilaporkan lebih dulu, dan tetap diperiksa walau tidak
            // ada yang perlu disalin: "sudah sama" bukan berarti tidak ada
            // kelebihan yang harus dibuang.
            if ($ganti) {
                $buang = $model->buangTanpaPadanan($opdId, $tm, $ta, false);

                if ($buang['dibuang_indikator'] > 0 || $buang['dipertahankan'] !== []) {
                    $adaKerja = true;
                    CLI::write('    MODE GANTI — isi IKU yang tidak ada di Renstra:', 'yellow');
                    CLI::write('      akan DIBUANG      : ' . $buang['dibuang_indikator'] . ' indikator');

                    foreach ($buang['dipertahankan'] as $d) {
                        CLI::write('      DIPERTAHANKAN #' . $d['id'] . '  ' . mb_substr($d['indikator'], 0, 46)
                            . '  (' . $d['alasan'] . ')');
                    }
                }
            }

            if ($jmlBaru === 0 && $jmlBerubah === 0) {
                CLI::write('    Tidak ada yang perlu disalin dari Renstra.', 'green');

                if ($ganti && $fix) {
                    $buang = $model->buangTanpaPadanan($opdId, $tm, $ta, true);
                    CLI::write('    ' . CLI::color('DIKERJAKAN', 'green') . '  '
                        . $buang['dibuang_indikator'] . ' indikator & '
                        . $buang['dibuang_sasaran'] . ' sasaran dibuang.');
                }

                continue;
            }

            $adaKerja = true;

            // PERINGATAN KEMBAR.
            //
            // Indikator IKU tanpa silsilah hanya bisa dikenali lewat teks.
            // Bila redaksinya sudah menyimpang dari Renstra — walau satu kata —
            // sync tidak mengenalinya, menganggap versi Renstra sebagai
            // indikator baru, lalu menyalinnya DI SEBELAHNYA. Lahir kembar.
            //
            // Lebih baik diberitahu sebelum dijalankan daripada dibersihkan
            // sesudahnya.
            $rawan = $db->table('iku_indikator ii')
                ->select('ii.id, ii.indikator')
                ->join('iku_sasaran s', 's.id = ii.iku_sasaran_id')
                ->where('s.opd_id', $opdId)
                ->where('s.tahun_mulai', $tm)
                ->where('s.tahun_akhir', $ta)
                ->where('ii.source_indikator_id IS NULL', null, false)
                ->where('ii.dihentikan_pada IS NULL', null, false)
                ->get()->getResultArray();

            if ($rawan !== []) {
                CLI::newLine();
                CLI::write('    ' . CLI::color('PERHATIAN', 'yellow') . '  '
                    . count($rawan) . ' indikator IKU belum punya jejak asal Renstra.');
                CLI::write('    Bila redaksinya berbeda dari Renstra, sync akan MENAMBAH versi');
                CLI::write('    Renstra di sebelahnya — bukan memperbaruinya. Samakan dulu teksnya,');
                CLI::write('    atau rapikan sesudahnya dengan');
                CLI::write('    db/perbaiki_2026-08-28_iku_kembar_sisa_sync.sql');

                foreach ($rawan as $x) {
                    CLI::write('      - #' . $x['id'] . ' ' . mb_substr($x['indikator'], 0, 60));
                }

                CLI::newLine();
            }

            CLI::write('    indikator akan DITAMBAHKAN  : ' . $jmlBaru);
            CLI::write('    indikator akan DIPERBARUI   : ' . $jmlBerubah
                . ($jmlBerubah > 0 ? '  (definisi & rumusan TIDAK ikut tertimpa)' : ''));

            foreach ($kandidat as $s) {
                $ditambah = count($baru[(int) $s['sumber_id']] ?? []);
                $diubah   = count($berubah[(int) $s['sumber_id']] ?? []);

                if ($ditambah === 0 && $diubah === 0) {
                    continue;
                }

                CLI::write('      - ' . mb_substr(trim(preg_replace('/\s+/', ' ', $s['sasaran'])), 0, 62)
                    . '  (+' . $ditambah . ($diubah > 0 ? ', ~' . $diubah : '') . ')');
            }

            if (! $fix) {
                continue;
            }

            try {
                $stat = $model->importSync('renstra', $opdId, $baru, $tm, $ta, null, $berubah);

                CLI::write('    ' . CLI::color('DIKERJAKAN', 'green') . '  '
                    . $stat['sasaran_baru'] . ' sasaran baru, '
                    . $stat['indikator_baru'] . ' indikator, '
                    . $stat['target'] . ' target'
                    . ($stat['diperbarui'] > 0 ? ', ' . $stat['diperbarui'] . ' diperbarui' : '')
                    . ($stat['ditautkan'] > 0 ? ', ' . $stat['ditautkan'] . ' baris lama ditautkan' : ''));

                // Pembuangan dijalankan SESUDAH penyalinan: baris yang baru saja
                // tertaut lewat tautkanSilsilah() tidak boleh ikut terbuang
                // hanya karena tadi belum bersilsilah.
                if ($ganti) {
                    $buang = $model->buangTanpaPadanan($opdId, $tm, $ta, true);
                    CLI::write('    ' . CLI::color('GANTI', 'green') . '       '
                        . $buang['dibuang_indikator'] . ' indikator & '
                        . $buang['dibuang_sasaran'] . ' sasaran dibuang, '
                        . count($buang['dipertahankan']) . ' dipertahankan karena punya turunan.');
                }
            } catch (Throwable $e) {
                CLI::error('    GAGAL: ' . $e->getMessage());

                return 1;
            }
        }

        CLI::newLine();

        if (! $fix && $adaKerja) {
            CLI::write('Belum ada yang diubah. Ulangi dengan --fix untuk mengerjakan.', 'yellow');
        }

        if ($fix && $adaKerja) {
            CLI::write('Jalankan db/update_2026-08-27_cascading_sumber_iku.sql sesudah ini', 'yellow');
            CLI::write('supaya baris cascading-nya ikut berjangkar ke IKU.', 'yellow');
        }

        return 0;
    }
}
