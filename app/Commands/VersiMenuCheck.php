<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use Config\Services;
use Throwable;

/**
 * Periksa butir menu versi benar-benar terender untuk sebuah role.
 *
 *   php spark versi:menu
 *
 * Menu digerbangi user_can(), dan permission dibaca dari basis data. Memeriksa
 * permission-nya saja tidak cukup: butirnya bisa saja ada tapi tertutup gerbang
 * lain di atasnya (mis. blok Perencanaan Kinerja yang punya syarat sendiri).
 * Karena itu yang diuji di sini adalah HTML yang benar-benar keluar.
 */
class VersiMenuCheck extends BaseCommand
{
    protected $group       = 'SAKIP';
    protected $name        = 'versi:menu';
    protected $description = 'Periksa butir menu versi terender untuk sebuah role.';
    protected $usage       = 'versi:menu --role <nama_role>';
    protected $options     = ['--role' => 'Role yang diuji. WAJIB satu role per proses.'];

    public function run(array $params)
    {
        $role = $params['role'] ?? CLI::getOption('role');

        if ($role === null || $role === true || trim((string) $role) === '') {
            CLI::error('Sebutkan --role. Satu role per proses.');
            CLI::write('MENGAPA: user_permissions() menyimpan cache STATIS per request. Menguji');
            CLI::write('beberapa role dalam satu proses membuat role kedua dan seterusnya memakai');
            CLI::write('izin role pertama, sehingga hasilnya menyesatkan.');

            return 1;
        }

        $role = trim((string) $role);

        Services::injectMock('request', new IncomingRequest(
            config('App'),
            new SiteURI(config('App')),
            null,
            new UserAgent()
        ));

        // Butir yang HARUS ada per role.
        $harap = [
            'admin_kab'       => ['Versi RPJMD', 'Verifikasi'],
            'admin_opd'       => ['Versi Renstra'],
            'admin_kecamatan' => ['Versi Renstra'],
            'bupati'          => [],
            // admin_inspektorat sengaja MELIHAT daftar versi — perannya auditor.
            // Ia hanya punya *.version.view; tidak ada create/submit/verify/publish,
            // sehingga halamannya terbuka tanpa satu pun tombol tulis.
            'admin_inspektorat' => ['Versi RPJMD'],
        ];

        // Butir yang TIDAK BOLEH ada per role — inilah pemeriksaan kebocoran.
        $terlarang = [
            'admin_kab'       => [],
            'admin_opd'       => ['adminkab/verifikasi', 'Versi RPJMD'],
            'admin_kecamatan' => ['adminkab/verifikasi', 'Versi RPJMD'],
            'bupati'          => ['Versi RPJMD', 'Versi Renstra', 'adminkab/verifikasi'],
            'admin_inspektorat' => ['adminkab/verifikasi'],
        ];

        session()->set(['role' => $role, 'isLoggedIn' => true, 'opd_id' => 1]);

        try {
            ob_start();
            $html = view('templates/admin_menu');
            ob_end_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            CLI::write('  ' . CLI::color('GAGAL', 'red') . ' ' . $role . ' — ' . $e->getMessage());

            return 1;
        }

        $gagal = 0;

        foreach ($harap[$role] ?? [] as $b) {
            $ada = str_contains($html, $b);
            CLI::write('  ' . CLI::color($ada ? 'OK   ' : 'GAGAL', $ada ? 'green' : 'red')
                . ' ' . $role . ' -> "' . $b . '"');

            if (! $ada) {
                $gagal++;
            }
        }

        foreach ($terlarang[$role] ?? [] as $b) {
            $bocor = str_contains($html, $b);
            CLI::write('  ' . CLI::color($bocor ? 'GAGAL' : 'OK   ', $bocor ? 'red' : 'green')
                . ' ' . $role . ' -> TIDAK melihat "' . $b . '"');

            if ($bocor) {
                $gagal++;
            }
        }

        return $gagal > 0 ? 1 : 0;
    }
}
