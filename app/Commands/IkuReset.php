<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Kembalikan lapisan REVISI IKU ke keadaan bersih.
 *
 *   php spark iku:reset --opd 11
 *   php spark iku:reset --semua --paksa
 *   php spark iku:reset --semua --db klon_uji
 *
 * =====================================================================
 * YANG DIHAPUS DAN YANG TIDAK
 *
 * DIHAPUS       : seluruh baris `iku_revisi` beserta arsipnya (sasaran,
 *                 indikator, target, program) pada lingkup yang dipilih.
 * DIKOSONGKAN   : `revisi_id` pada baris IKU berjalan — penanda "revisi
 *                 terakhir yang mengubah baris ini" ikut tidak berlaku lagi.
 * TIDAK DISENTUH: isi IKU yang sebenarnya. Sasaran, indikator, dan targetnya
 *                 tetap utuh di tabel berjalan.
 *
 * =====================================================================
 * MENGAPA INI PERINTAH ADMINISTRATOR, BUKAN TOMBOL
 *
 * Membuang arsip revisi berarti membuang jawaban atas "IKU mana yang berlaku
 * pada tahun sekian". Untuk data sungguhan itu tidak boleh terjadi — yang
 * benar adalah membatalkan pengesahan, bukan menghapusnya.
 *
 * Perintah ini ada untuk keadaan lain: membersihkan sisa percobaan sebelum
 * pengujian dimulai. Karena itu ia hidup di terminal, di tangan orang yang
 * tahu persis apa yang sedang dibuangnya — bukan di layar yang bisa terklik
 * tanpa sengaja.
 */
class IkuReset extends BaseCommand
{
    protected $group       = 'SAKIP';
    protected $name        = 'iku:reset';
    protected $description = 'Buang seluruh revisi IKU pada satu lingkup (isi IKU tidak disentuh).';
    protected $usage       = 'iku:reset [--opd <id>|--semua] [--db <basis>] [--paksa]';
    protected $options     = [
        '--opd'   => 'opd_id yang dibersihkan',
        '--semua' => 'bersihkan seluruh OPD (termasuk tingkat kabupaten)',
        '--db'    => 'basis data lain (mis. salinan uji)',
        '--paksa' => 'lewati konfirmasi',
    ];

    public function run(array $params)
    {
        $opd    = $params['opd'] ?? CLI::getOption('opd');
        $semua  = in_array('--semua', $_SERVER['argv'] ?? [], true);
        $paksa  = in_array('--paksa', $_SERVER['argv'] ?? [], true);
        $namaDb = $params['db'] ?? CLI::getOption('db');

        if (! $semua && ($opd === null || $opd === true)) {
            CLI::error('Sebutkan --opd <id> atau --semua.');

            return 1;
        }

        $cfg = config('Database')->default;

        if ($namaDb !== null && $namaDb !== true && trim((string) $namaDb) !== '') {
            $cfg['database'] = trim((string) $namaDb);
        }

        $db = db_connect($cfg, false);

        CLI::write('Basis data : ' . CLI::color($db->getDatabase(), 'yellow'));
        CLI::write('Lingkup    : ' . CLI::color($semua ? 'SELURUH OPD' : ('opd_id ' . (int) $opd), 'yellow'));

        if (! $db->tableExists('iku_revisi')) {
            CLI::error('Tabel iku_revisi belum ada.');

            return 1;
        }

        $b = $db->table('iku_revisi');

        if (! $semua) {
            $b->where('opd_id', (int) $opd);
        }

        $revisi = $b->orderBy('opd_id', 'ASC')->orderBy('nomor', 'ASC')->get()->getResultArray();

        if ($revisi === []) {
            CLI::write(CLI::color('Tidak ada revisi IKU pada lingkup itu — sudah bersih.', 'green'));

            return 0;
        }

        CLI::newLine();
        CLI::write('Akan DIHAPUS ' . CLI::color((string) count($revisi), 'red') . ' revisi IKU:');

        foreach ($revisi as $r) {
            CLI::write('  - opd ' . $r['opd_key'] . '  revisi ke-' . $r['nomor']
                . '  [' . $r['status'] . ']  berlaku ' . $r['berlaku_mulai_tahun']
                . '  ' . $r['nama']);
        }

        CLI::write(CLI::color('Isi IKU di tabel berjalan TIDAK disentuh.', 'green'));
        CLI::newLine();

        if (! $paksa && strtolower((string) CLI::prompt('Lanjutkan? (y/N)', 'N')) !== 'y') {
            CLI::write('Dibatalkan.');

            return 0;
        }

        $ids = array_map(static fn ($r) => (int) $r['id'], $revisi);
        $n   = ['revisi' => 0, 'arsip_sasaran' => 0, 'arsip_indikator' => 0, 'tautan_dilepas' => 0];

        try {
            $db->transBegin();
            $db->resetTransStatus();

            $idInd = array_column(
                $db->table('iku_revisi_indikator')->select('id')->whereIn('revisi_id', $ids)
                    ->get()->getResultArray(),
                'id'
            );

            if ($idInd !== []) {
                $db->table('iku_revisi_target')->whereIn('revisi_indikator_id', $idInd)->delete();
                $db->table('iku_revisi_program')->whereIn('revisi_indikator_id', $idInd)->delete();
            }

            $n['arsip_indikator'] = count($idInd);

            $n['arsip_sasaran'] = (int) $db->table('iku_revisi_sasaran')
                ->whereIn('revisi_id', $ids)->countAllResults(false);

            $db->table('iku_revisi_indikator')->whereIn('revisi_id', $ids)->delete();
            $db->table('iku_revisi_sasaran')->whereIn('revisi_id', $ids)->delete();

            // Penanda pada baris berjalan dilepas LEBIH DULU. FK-nya memang
            // SET NULL, tetapi mengandalkan itu berarti membiarkan basis data
            // diam-diam mengubah baris yang tidak sedang kita bicarakan.
            foreach (['iku_sasaran', 'iku_indikator'] as $tabel) {
                $n['tautan_dilepas'] += (int) $db->table($tabel)
                    ->whereIn('revisi_id', $ids)->countAllResults(false);

                $db->table($tabel)->whereIn('revisi_id', $ids)->update(['revisi_id' => null]);
            }

            if ($db->tableExists('lakip_penyesuaian')) {
                $db->table('lakip_penyesuaian')->whereIn('iku_revisi_id', $ids)
                    ->update(['iku_revisi_id' => null]);
            }

            $db->table('iku_revisi')->whereIn('id', $ids)->delete();
            $n['revisi'] = count($ids);

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

        return 0;
    }
}
