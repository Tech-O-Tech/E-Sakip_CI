<?php

namespace App\Commands;

use App\Models\Opd\IkuRevisiModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Rapikan akibat lanjutan dari periode IKU yang dipindahkan.
 *
 *   php spark iku:rapikan-periode           (laporan saja)
 *   php spark iku:rapikan-periode --fix     (kerjakan)
 *
 * Dijalankan SESUDAH db/update_2026-08-29_periode_iku_nyasar.sql. Dua hal yang
 * tidak bisa dikerjakan SQL murni karena butuh logika aplikasi:
 *
 *   1. PULIHKAN KONDISI AWAL. Periode yang baru saja menerima pindahan bisa
 *      punya revisi bernomor tanpa revisi ke-0. Tanpa Kondisi Awal,
 *      tahun-tahun sebelum revisi pertama tidak dipayungi siapa pun.
 *
 *   2. BEKUKAN ULANG ARSIP REVISI. Revisi yang lahir di periode nyasar hanya
 *      memuat indikator yang ada DI SANA. Sesudah penggabungan, arsipnya tidak
 *      lagi mencerminkan IKU yang sebenarnya berlaku pada tahun itu — dan
 *      arsip itulah yang dibaca LAKIP. Dibekukan ulang dari isi terkini.
 *
 * Pembekuan ulang HANYA menyentuh revisi yang ditandai lewat --revisi, atau
 * yang terdeteksi timpang (arsipnya memuat lebih sedikit indikator daripada
 * IKU berjalan pada periode yang sama).
 */
class IkuRapikanPeriode extends BaseCommand
{
    protected $group       = 'SAKIP';
    protected $name        = 'iku:rapikan-periode';
    protected $description = 'Pulihkan Kondisi Awal & bekukan ulang arsip revisi sesudah periode IKU dipindah.';
    protected $usage       = 'iku:rapikan-periode [--fix] [--revisi <id>]';
    protected $options     = [
        '--fix'    => 'Benar-benar mengerjakan. Tanpa ini hanya melaporkan.',
        '--revisi' => 'Batasi pembekuan ulang pada satu id revisi.',
    ];

    public function run(array $params)
    {
        $db     = Database::connect();
        $model  = new IkuRevisiModel();
        $fix    = array_key_exists('fix', $params) || CLI::getOption('fix') !== null;
        $revisi = CLI::getOption('revisi');

        CLI::write('Basis data: ' . $db->getDatabase(), 'yellow');
        CLI::newLine();

        if (! $db->tableExists('iku_revisi')) {
            CLI::error('Tabel iku_revisi belum ada.');

            return 1;
        }

        // ---- 1. Kondisi Awal yang hilang --------------------------------
        CLI::write('== Kondisi Awal ==', 'cyan');

        $bolong = $db->table('iku_revisi r')
            ->select('r.opd_key, r.opd_id, r.tahun_mulai, r.tahun_akhir, o.nama_opd', false)
            ->join('opd o', 'o.id = r.opd_id', 'left')
            ->groupBy('r.opd_key, r.opd_id, r.tahun_mulai, r.tahun_akhir, o.nama_opd')
            ->having('SUM(r.nomor > 0) >', 0, false)
            ->having('SUM(r.nomor = 0) =', 0, false)
            ->get()->getResultArray();

        if ($bolong === []) {
            CLI::write('  Semua lingkup bernomor sudah punya Kondisi Awal.', 'green');
        } else {
            foreach ($bolong as $b) {
                $label = ($b['nama_opd'] ?? 'IKU Kabupaten') . ' ' . $b['tahun_mulai'] . '-' . $b['tahun_akhir'];

                if (! $fix) {
                    CLI::write('  PERLU   ' . $label);

                    continue;
                }

                try {
                    $id = $model->pulihkanBaseline(
                        $b['opd_id'] !== null ? (int) $b['opd_id'] : null,
                        (int) $b['tahun_mulai'],
                        (int) $b['tahun_akhir']
                    );

                    CLI::write('  ' . CLI::color('PULIH', 'green') . '   ' . $label
                        . ($id === null ? ' (sudah sehat)' : ' -> revisi ke-0 #' . $id));
                } catch (Throwable $e) {
                    CLI::write('  ' . CLI::color('GAGAL', 'red') . '   ' . $label . ' -> ' . $e->getMessage());
                }
            }
        }

        // ---- 2. Arsip revisi yang timpang -------------------------------
        CLI::newLine();
        CLI::write('== Arsip revisi vs IKU berjalan ==', 'cyan');

        $b = $db->table('iku_revisi r')
            ->select("r.id, r.opd_key, r.opd_id, r.nomor, r.nama, r.tahun_mulai, r.tahun_akhir,
                      (SELECT COUNT(*) FROM iku_revisi_indikator ri WHERE ri.revisi_id = r.id) AS jml_arsip,
                      (SELECT COUNT(*) FROM iku_indikator ii
                         JOIN iku_sasaran s ON s.id = ii.iku_sasaran_id
                        WHERE s.tahun_mulai = r.tahun_mulai AND s.tahun_akhir = r.tahun_akhir
                          AND ii.dihentikan_pada IS NULL
                          AND ((r.opd_id IS NULL AND s.opd_id IS NULL) OR s.opd_id = r.opd_id)
                      ) AS jml_live", false)
            ->where('r.status !=', 'batal');

        if ($revisi !== null && $revisi !== true) {
            $b->where('r.id', (int) $revisi);
        }

        $timpang = array_values(array_filter(
            $b->get()->getResultArray(),
            static fn ($r) => (int) $r['jml_arsip'] < (int) $r['jml_live']
        ));

        if ($timpang === []) {
            CLI::write('  Tidak ada arsip revisi yang timpang.', 'green');

            return $this->tutup($fix);
        }

        foreach ($timpang as $t) {
            $label = '#' . $t['id'] . ' ' . $t['nama']
                . ' (' . $t['jml_arsip'] . ' arsip vs ' . $t['jml_live'] . ' berjalan)';

            if (! $fix) {
                CLI::write('  PERLU   ' . $label);

                continue;
            }

            try {
                $model->bekukanUlangArsip((int) $t['id']);
                CLI::write('  ' . CLI::color('BEKU ULANG', 'green') . ' ' . $label);
            } catch (Throwable $e) {
                CLI::write('  ' . CLI::color('GAGAL', 'red') . '   ' . $label . ' -> ' . $e->getMessage());
            }
        }

        return $this->tutup($fix);
    }

    private function tutup(bool $fix): int
    {
        if (! $fix) {
            CLI::newLine();
            CLI::write('Tidak ada yang diubah. Jalankan ulang dengan --fix untuk mengerjakan.', 'yellow');
        }

        return 0;
    }
}
