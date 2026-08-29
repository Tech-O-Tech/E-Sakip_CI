<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Sahkan "Kondisi Awal" IKU untuk seluruh OPD sekaligus.
 *
 * =====================================================================
 * APA YANG SEBENARNYA DIBUAT — DAN APA YANG TIDAK
 *
 * Perintah ini TIDAK mengarang dokumen bertanda tangan. Ia memakai
 * `IkuRevisiModel::pastikanBaseline()`, mekanisme yang sudah ada di sistem:
 * membekukan IKU yang SEDANG BERLAKU menjadi revisi bernomor 0 bernama
 * "Kondisi Awal IKU <periode>". Isinya bukan karangan — ia potret persis
 * IKU berjalan, yang sendirinya berasal dari Renstra lewat Sync.
 *
 * Gunanya: LAKIP hanya mau menilai terhadap IKU bila ada revisi yang
 * MEMAYUNGI tahun laporan (lihat LakipSourceService::pilihanVersiIku).
 * Tanpa Kondisi Awal, 36 OPD selamanya jatuh ke cadangan Renstra meski
 * IKU-nya sudah lengkap dan bersilsilah.
 *
 * =====================================================================
 * PENJAGA
 *
 *   * Lingkup yang SUDAH punya revisi apa pun dilewati — pastikanBaseline()
 *     menolak bekerja di sana, dan itu memang benar.
 *   * OPD yang IKU-nya KOSONG dilewati dengan peringatan: baseline kosong
 *     akan membuat layar LAKIP-nya kosong, lebih buruk daripada cadangan
 *     Renstra.
 *   * Bawaannya PRATINJAU. Tidak ada yang ditulis tanpa --fix.
 *
 * Contoh:
 *     php spark iku:sahkan-massal                    (pratinjau)
 *     php spark iku:sahkan-massal --fix              (kerjakan)
 *     php spark iku:sahkan-massal --opd 29 --fix     (satu OPD saja)
 */
class IkuSahkanMassal extends BaseCommand
{
    protected $group       = 'IKU';
    protected $name        = 'iku:sahkan-massal';
    protected $description = 'Bekukan Kondisi Awal IKU (revisi 0) untuk semua OPD agar LAKIP menilai terhadap IKU.';
    protected $usage       = 'iku:sahkan-massal [--opd N] [--periode 2025-2029] [--fix]';

    public function run(array $params)
    {
        $db       = \Config\Database::connect();
        $kerjakan = array_key_exists('fix', $params) || CLI::getOption('fix');
        $opdFilter = CLI::getOption('opd');
        $periode   = CLI::getOption('periode') ?: '2025-2029';

        [$tm, $ta] = array_map('intval', explode('-', $periode) + [1 => 0]);

        if ($tm <= 0 || $ta < $tm) {
            CLI::error('Periode tidak sah. Contoh: --periode 2025-2029');
            return;
        }

        CLI::write('Basis data : ' . $db->getDatabase());
        CLI::write('Periode    : ' . $tm . '-' . $ta);
        CLI::write('Mode       : ' . ($kerjakan ? CLI::color('KERJAKAN', 'red') : CLI::color('pratinjau', 'yellow')));
        CLI::newLine();

        $b = $db->table('iku_sasaran s')
            ->select('s.opd_id, o.nama_opd, COUNT(DISTINCT s.id) AS sasaran, COUNT(i.id) AS indikator', false)
            ->join('opd o', 'o.id = s.opd_id')
            ->join('iku_indikator i', 'i.iku_sasaran_id = s.id AND i.dihentikan_pada IS NULL', 'left', false)
            ->where('s.opd_id IS NOT NULL', null, false)
            ->where('s.tahun_mulai', $tm)
            ->where('s.tahun_akhir', $ta)
            ->where('s.dihentikan_pada IS NULL', null, false)
            ->groupBy('s.opd_id, o.nama_opd')
            ->orderBy('o.nama_opd', 'ASC');

        if ($opdFilter) {
            $b->where('s.opd_id', (int) $opdFilter);
        }

        $daftar = $b->get()->getResultArray();

        if ($daftar === []) {
            CLI::write('Tidak ada OPD dengan IKU pada periode ini.');
            return;
        }

        $rev = new \App\Models\Opd\IkuRevisiModel();

        if (! $rev->siap()) {
            CLI::error('Tabel revisi IKU belum tersedia di basis data ini.');
            return;
        }

        $dibuat = 0; $dilewati = 0; $kosong = 0;

        foreach ($daftar as $d) {
            $opdId = (int) $d['opd_id'];
            $nama  = mb_substr($d['nama_opd'], 0, 42);

            $sudah = $db->table('iku_revisi')->where('opd_key', $opdId)->countAllResults();

            if ($sudah > 0) {
                CLI::write(sprintf('  %-44s %s', $nama, CLI::color('sudah punya revisi — dilewati', 'dark_gray')));
                $dilewati++;
                continue;
            }

            if ((int) $d['indikator'] === 0) {
                CLI::write(sprintf('  %-44s %s', $nama, CLI::color('IKU KOSONG — dilewati', 'yellow')));
                $kosong++;
                continue;
            }

            if (! $kerjakan) {
                CLI::write(sprintf('  %-44s akan dibekukan (%d sasaran, %d indikator)',
                    $nama, (int) $d['sasaran'], (int) $d['indikator']));
                $dibuat++;
                continue;
            }

            try {
                $id = $rev->pastikanBaseline($opdId, $tm, $ta, null);
                CLI::write(sprintf('  %-44s %s', $nama,
                    $id ? CLI::color('Kondisi Awal dibuat (revisi ' . $id . ')', 'green')
                        : CLI::color('tidak ada yang dibuat', 'dark_gray')));
                $id ? $dibuat++ : $dilewati++;
            } catch (\Throwable $e) {
                CLI::write(sprintf('  %-44s %s', $nama, CLI::color('GAGAL: ' . $e->getMessage(), 'red')));
            }
        }

        CLI::newLine();
        CLI::write(sprintf('Dibekukan: %d   Dilewati: %d   IKU kosong: %d', $dibuat, $dilewati, $kosong));

        if (! $kerjakan) {
            CLI::newLine();
            CLI::write(CLI::color('Belum ada yang ditulis. Ulangi dengan --fix untuk mengerjakan.', 'yellow'));
        }
    }
}
