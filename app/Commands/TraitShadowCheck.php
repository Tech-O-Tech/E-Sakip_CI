<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Cari metode kelas yang MEMBAYANGI metode trait yang dipakainya.
 *
 *   php spark versi:sapu-bayang
 *
 * PHP memenangkan metode kelas atas metode trait tanpa peringatan apa pun.
 * `php -l` tetap bersih, dan yang meledak adalah pemanggil lama — di jalur yang
 * mungkin jarang dibuka, seperti tombol cetak. Cacat itu pernah nyata di sini:
 * LakipOpdController::sumberLakip() membayangi LakipSnapshotTrait::sumberLakip()
 * dengan tanda tangan yang sama sekali berbeda.
 *
 * Pembayangan yang DISENGAJA (override) dinyatakan dengan meng-alias metode
 * trait-nya di blok `use`:
 *
 *     use LakipSnapshotTrait {
 *         barisHidupUntukSnapshot as private barisHidupBawaan;
 *     }
 *
 * Alias itulah yang membedakan "saya tahu saya menimpanya" dari "saya tidak
 * sadar nama ini sudah dipakai". Yang beralias dilaporkan sebagai catatan,
 * yang tidak beralias dilaporkan sebagai temuan.
 */
class TraitShadowCheck extends BaseCommand
{
    protected $group       = 'Versioning';
    protected $name        = 'versi:sapu-bayang';
    protected $description = 'Cari metode kelas yang membayangi metode trait tanpa alias.';
    protected $usage       = 'versi:sapu-bayang';
    protected $arguments   = [];
    protected $options     = [];

    public function run(array $params)
    {
        $akar = rtrim(APPPATH, '/\\');

        $berkas = [];

        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($akar));

        foreach ($iter as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                $berkas[] = $f->getPathname();
            }
        }

        sort($berkas);

        // 1. Metode milik tiap trait.
        $traitMetode = [];

        foreach ($berkas as $jalur) {
            $isi = (string) file_get_contents($jalur);

            if (! preg_match('/^\s*trait\s+(\w+)/m', $isi, $m)) {
                continue;
            }

            preg_match_all('/^\s*(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)/m', $isi, $mm);
            $traitMetode[$m[1]] = array_flip($mm[1]);
        }

        // 2. Bandingkan tiap kelas dengan trait yang dipakainya.
        $temuan  = [];
        $sengaja = [];

        foreach ($berkas as $jalur) {
            $isi = (string) file_get_contents($jalur);

            if (! preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+\w+/m', $isi)) {
                continue;
            }

            // Dua bentuk `use` di dalam kelas: diakhiri `;` atau diikuti blok
            // `{ ... }` berisi alias. Bentuk kedua yang dulu terlewat, dan
            // justru bentuk itulah yang menandai override disengaja.
            preg_match_all(
                '/^[ \t]+use\s+([A-Za-z0-9_,\s\\\\]+?)\s*(;|\{(.*?)\})/ms',
                $isi,
                $blok,
                PREG_SET_ORDER
            );

            $dipakai   = [];
            $beralias  = [];

            foreach ($blok as $b) {
                foreach (explode(',', $b[1]) as $t) {
                    $nama = trim($t);

                    if ($nama === '') {
                        continue;
                    }

                    $potong    = explode('\\', $nama);
                    $dipakai[] = end($potong);
                }

                if (isset($b[3]) && preg_match_all('/(\w+)\s+as\s+/i', $b[3], $al)) {
                    foreach ($al[1] as $nm) {
                        $beralias[$nm] = true;
                    }
                }
            }

            if ($dipakai === []) {
                continue;
            }

            preg_match_all(
                '/^[ \t]{4}(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)/m',
                $isi,
                $mk
            );

            $relatif = str_replace('\\', '/', substr($jalur, strlen(dirname($akar)) + 1));

            foreach (array_unique($dipakai) as $t) {
                foreach ($mk[1] as $nm) {
                    if (! isset($traitMetode[$t][$nm])) {
                        continue;
                    }

                    $baris = sprintf('%s  membayangi %s::%s()', $relatif, $t, $nm);

                    if (isset($beralias[$nm])) {
                        $sengaja[] = $baris;
                    } else {
                        $temuan[] = $baris;
                    }
                }
            }
        }

        $temuan  = array_values(array_unique($temuan));
        $sengaja = array_values(array_unique($sengaja));

        CLI::write('Berkas diperiksa : ' . count($berkas));
        CLI::write('Trait dikenali   : ' . count($traitMetode));
        CLI::newLine();

        if ($sengaja !== []) {
            CLI::write(CLI::color('Override disengaja (beralias di blok use):', 'yellow'));

            foreach ($sengaja as $s) {
                CLI::write('  ' . $s);
            }

            CLI::newLine();
        }

        if ($temuan === []) {
            CLI::write(CLI::color('OK  tidak ada pembayangan trait yang tak disengaja.', 'green'));

            return EXIT_SUCCESS;
        }

        CLI::write(CLI::color('TEMUAN — pembayangan tanpa alias:', 'red'));

        foreach ($temuan as $t) {
            CLI::write('  ' . $t);
        }

        CLI::newLine();
        CLI::write('Bila memang disengaja, nyatakan lewat alias di blok `use`.');

        return EXIT_ERROR;
    }
}
