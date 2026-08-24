<?php

namespace App\Commands;

use App\Models\Opd\IkuRevisiModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Pulihkan Kondisi Awal (revisi ke-0) IKU yang hilang.
 *
 *   php spark iku:baseline-repair          (laporan saja, tidak mengubah apa pun)
 *   php spark iku:baseline-repair --fix    (perbaiki sekalian)
 *
 * Lingkup yang punya revisi bernomor tapi tidak punya revisi ke-0 membuat
 * tahun-tahun sebelum revisi pertama tak dipayungi siapa pun — LAKIP bersumber
 * IKU lalu menjawab "belum ada versi" untuk tahun-tahun itu. Alur aplikasi
 * selalu membuat baseline lebih dulu, jadi lubang seperti ini hanya lahir dari
 * baris yang terhapus di luar alur; perintah ini menemukannya dan menjahitnya
 * kembali lewat IkuRevisiModel::pulihkanBaseline().
 */
class IkuBaselineRepair extends BaseCommand
{
    protected $group       = 'SAKIP';
    protected $name        = 'iku:baseline-repair';
    protected $description = 'Temukan & pulihkan Kondisi Awal IKU yang hilang dari timeline revisi.';
    protected $usage       = 'iku:baseline-repair [--fix]';
    protected $options     = ['--fix' => 'Benar-benar memulihkan. Tanpa ini hanya melaporkan.'];

    public function run(array $params)
    {
        $db  = Database::connect();
        $fix = array_key_exists('fix', $params) || CLI::getOption('fix') !== null;

        CLI::write('Basis data: ' . $db->getDatabase(), 'yellow');

        if (! $db->tableExists('iku_revisi')) {
            CLI::error('Tabel iku_revisi belum ada. Jalankan migrasi 2026-08-18 dulu.');

            return 1;
        }

        // Lingkup bernomor yang kehilangan revisi ke-0. Baris ke-0 TIDAK boleh
        // disaring di WHERE — ia justru yang sedang dicari ada-tidaknya, jadi
        // keduanya dihitung di HAVING atas kelompok yang utuh.
        $bolong = $db->table('iku_revisi r')
            ->select('r.opd_key, r.opd_id, r.tahun_mulai, r.tahun_akhir, o.nama_opd,
                      MIN(CASE WHEN r.nomor > 0 THEN r.berlaku_mulai_tahun END) AS revisi_terawal', false)
            ->join('opd o', 'o.id = r.opd_id', 'left')
            ->groupBy('r.opd_key, r.opd_id, r.tahun_mulai, r.tahun_akhir, o.nama_opd')
            ->having('SUM(r.nomor > 0) >', 0, false)
            ->having('SUM(r.nomor = 0) =', 0, false)
            ->get()->getResultArray();

        if ($bolong === []) {
            CLI::write('Semua timeline sehat: tiap lingkup bernomor punya Kondisi Awal.', 'green');

            return 0;
        }

        CLI::newLine();
        CLI::write(count($bolong) . ' lingkup tanpa Kondisi Awal:', 'red');

        foreach ($bolong as $b) {
            CLI::write(sprintf(
                '  - %s  periode %d-%d  (revisi terawal berlaku %s -> tahun %d..%s tak terpayungi)',
                $b['nama_opd'] ?? 'IKU Kabupaten',
                (int) $b['tahun_mulai'],
                (int) $b['tahun_akhir'],
                $b['revisi_terawal'] ?? '-',
                (int) $b['tahun_mulai'],
                $b['revisi_terawal'] !== null ? (string) ((int) $b['revisi_terawal'] - 1) : '?'
            ));
        }

        if (! $fix) {
            CLI::newLine();
            CLI::write('Tidak ada yang diubah. Jalankan ulang dengan --fix untuk memulihkan.', 'yellow');

            return 1;
        }

        CLI::newLine();
        $model = new IkuRevisiModel();
        $gagal = 0;

        foreach ($bolong as $b) {
            $label = ($b['nama_opd'] ?? 'IKU Kabupaten') . ' ' . $b['tahun_mulai'] . '-' . $b['tahun_akhir'];

            try {
                $id = $model->pulihkanBaseline(
                    $b['opd_id'] !== null ? (int) $b['opd_id'] : null,
                    (int) $b['tahun_mulai'],
                    (int) $b['tahun_akhir'],
                    (int) (session()->get('user_id') ?? 0) ?: null
                );

                if ($id === null) {
                    CLI::write('  LEWAT   ' . $label . ' (sudah sehat saat diproses)');
                } else {
                    CLI::write('  ' . CLI::color('PULIH', 'green') . '   ' . $label . ' -> revisi ke-0 #' . $id);
                }
            } catch (Throwable $e) {
                $gagal++;
                CLI::write('  ' . CLI::color('GAGAL', 'red') . '   ' . $label . ' -> ' . $e->getMessage());
            }
        }

        CLI::newLine();
        CLI::write('Periksa hasilnya: menu LAKIP tahun sebelum revisi pertama kini menawarkan versi IKU.', 'yellow');

        return $gagal > 0 ? 1 : 0;
    }
}
