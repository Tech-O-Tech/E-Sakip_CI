<?php

namespace App\Commands;

use App\Models\CascadingModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Periksa Cascading Kabupaten membaca IKU Kabupaten, dengan RPJMD sebagai
 * jaring pengaman.
 *
 *   php spark casc:kab-verify
 *
 * Kembaran casc:verify untuk sisi kabupaten. Yang diuji bukan skemanya —
 * itu urusan migrasi — melainkan yang dilihat pemakai:
 *
 *   1. baris yang berjembatan menampilkan teks & target dari IKU Kabupaten;
 *   2. revisi teks IKU langsung terbaca, tanpa menunggu RPJMD ikut diubah;
 *   3. baris yang TIDAK punya padanan IKU tetap tampil lewat RPJMD;
 *   4. nama kolom lama tidak berubah — 8+ pemanggil bergantung padanya;
 *   5. tidak ada baris RPJMD yang hilang gara-gara penyambungan ini.
 *
 * Menulis sementara ke `iku_indikator` untuk butir 2, lalu mengembalikannya.
 */
class CascadingKabIkuCheck extends BaseCommand
{
    protected $group       = 'SAKIP';
    protected $name        = 'casc:kab-verify';
    protected $description = 'Uji Cascading Kabupaten membaca IKU Kabupaten (jaring pengaman RPJMD).';

    private int $lulus = 0;
    private int $gagal = 0;

    private function cek(string $nama, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            $this->lulus++;
            CLI::write('  ' . CLI::color('LULUS', 'green') . '  ' . $nama);

            return;
        }

        $this->gagal++;
        CLI::write('  ' . CLI::color('GAGAL', 'red') . '  ' . $nama
            . ($detail !== '' ? '  -> ' . $detail : ''));
    }

    public function run(array $params)
    {
        $db    = Database::connect();
        $model = new CascadingModel();

        CLI::write('Basis data: ' . $db->getDatabase(), 'yellow');
        CLI::newLine();

        $periode = $db->table('rpjmd_misi')
            ->select('tahun_mulai, tahun_akhir, COUNT(*) AS n', false)
            ->groupBy(['tahun_mulai', 'tahun_akhir'])
            ->orderBy('n', 'DESC')->limit(1)
            ->get()->getRowArray();

        if ($periode === null) {
            CLI::error('Belum ada periode RPJMD.');

            return 1;
        }

        $tm = (int) $periode['tahun_mulai'];
        $ta = (int) $periode['tahun_akhir'];

        $rows = $model->getMatrix($tm, $ta);

        // ---------------------------------------------------------------
        CLI::write('== 1. Bentuk hasil tidak berubah ==', 'cyan');
        // ---------------------------------------------------------------
        $this->cek('matriks terbaca', $rows !== [], 'baris: ' . count($rows));

        if ($rows === []) {
            return $this->tutup();
        }

        foreach (['misi', 'tujuan_rpjmd', 'sasaran_rpjmd', 'indikator_sasaran',
                  'satuan', 'baseline', 'indikator_id', 'targets'] as $kolom) {
            $this->cek("kolom `{$kolom}` tetap ada", array_key_exists($kolom, $rows[0]));
        }

        // Jumlah indikator RPJMD yang tampil harus SAMA dengan yang ada —
        // penyambungan IKU tidak boleh menggandakan atau menghilangkan baris.
        $indikatorTampil = count(array_unique(array_filter(array_column($rows, 'indikator_id'))));

        $indikatorAda = (int) $db->table('rpjmd_indikator_sasaran ris')
            ->join('rpjmd_sasaran rs', 'rs.id = ris.sasaran_id')
            ->join('rpjmd_tujuan rt', 'rt.id = rs.tujuan_id')
            ->join('rpjmd_misi rm', 'rm.id = rt.misi_id')
            ->where('rm.tahun_mulai', $tm)->where('rm.tahun_akhir', $ta)
            ->countAllResults();

        $this->cek('tidak ada indikator RPJMD yang hilang/ganda',
            $indikatorTampil === $indikatorAda,
            "tampil: {$indikatorTampil}, ada: {$indikatorAda}");

        if (! $db->fieldExists('source_indikator_id', 'iku_indikator')) {
            CLI::newLine();
            CLI::write('Kolom silsilah belum ada — MODE KOMPATIBILITAS.', 'yellow');
            CLI::write('(jalankan db/update_2026-08-28_silsilah_iku_kabupaten.sql untuk mode penuh)');

            $adaTeks = false;
            foreach ($rows as $r) {
                if (! empty($r['indikator_sasaran'])) {
                    $adaTeks = true;
                    break;
                }
            }

            $this->cek('teks indikator tetap tampil lewat RPJMD', $adaTeks);

            return $this->tutup();
        }

        // ---------------------------------------------------------------
        CLI::newLine();
        CLI::write('== 2. Baris yang berjembatan ke IKU Kabupaten ==', 'cyan');
        // ---------------------------------------------------------------
        $berjembatan = array_values(array_filter(
            $rows,
            static fn ($r) => ! empty($r['iku_indikator_id'])
        ));

        if ($berjembatan === []) {
            CLI::write('  (dilewati — belum ada padanan IKU Kabupaten)', 'dark_gray');
        } else {
            $this->cek('ada baris bersumber IKU Kabupaten', true, count($berjembatan) . ' baris');

            $contoh = $berjembatan[0];
            $ikuId  = (int) $contoh['iku_indikator_id'];
            $risId  = (int) $contoh['indikator_id'];

            $asli = $db->table('iku_indikator')->select('indikator')
                ->where('id', $ikuId)->get()->getRowArray();

            $teksBaru = 'UJI CASCADING KAB ' . $ikuId;
            $db->table('iku_indikator')->where('id', $ikuId)->update(['indikator' => $teksBaru]);

            try {
                $sesudah = $model->getMatrix($tm, $ta);

                $terbaca = false;
                foreach ($sesudah as $r) {
                    if ((int) ($r['iku_indikator_id'] ?? 0) === $ikuId
                        && $r['indikator_sasaran'] === $teksBaru) {
                        $terbaca = true;
                        break;
                    }
                }

                $this->cek('teks IKU yang diubah langsung terbaca di cascading', $terbaca);

                $ris = $db->table('rpjmd_indikator_sasaran')->select('indikator_sasaran')
                    ->where('id', $risId)->get()->getRowArray();

                $this->cek('teks RPJMD TIDAK ikut tersentuh',
                    ($ris['indikator_sasaran'] ?? '') !== $teksBaru);
            } finally {
                $db->table('iku_indikator')->where('id', $ikuId)
                    ->update(['indikator' => $asli['indikator']]);
            }

            $pulih = $db->table('iku_indikator')->select('indikator')
                ->where('id', $ikuId)->get()->getRowArray();

            $this->cek('teks IKU dikembalikan seperti semula',
                $pulih['indikator'] === $asli['indikator']);

            // Target: bila IKU punya target untuk indikator itu, angkanya
            // yang dipakai — bukan target RPJMD.
            $adaTargetIku = (int) $db->table('iku_target')
                ->where('iku_indikator_id', $ikuId)->countAllResults();

            if ($adaTargetIku > 0) {
                $tahunIku = $db->table('iku_target')->select('tahun, target')
                    ->where('iku_indikator_id', $ikuId)->orderBy('tahun', 'ASC')
                    ->get()->getRowArray();

                $cocok = false;
                foreach ($rows as $r) {
                    if ((int) ($r['iku_indikator_id'] ?? 0) === $ikuId) {
                        $cocok = (string) ($r['targets'][$tahunIku['tahun']] ?? '')
                            === (string) $tahunIku['target'];
                        break;
                    }
                }

                $this->cek('target yang tampil berasal dari IKU, bukan RPJMD', $cocok);
            } else {
                CLI::write('  (uji target dilewati — IKU indikator itu belum punya target)', 'dark_gray');
            }
        }

        // ---------------------------------------------------------------
        CLI::newLine();
        CLI::write('== 3. Jaring pengaman: baris tanpa padanan IKU ==', 'cyan');
        // ---------------------------------------------------------------
        $tanpaIku = array_values(array_filter(
            $rows,
            static fn ($r) => empty($r['iku_indikator_id']) && ! empty($r['indikator_id'])
        ));

        if ($tanpaIku === []) {
            CLI::write('  (dilewati — seluruh indikator RPJMD punya padanan IKU)', 'dark_gray');
        } else {
            $this->cek('baris tanpa padanan tetap tampil', true, count($tanpaIku) . ' baris');

            $adaTeks = true;
            foreach ($tanpaIku as $r) {
                if (empty($r['indikator_sasaran'])) {
                    $adaTeks = false;
                    break;
                }
            }

            $this->cek('teksnya tetap terisi dari RPJMD (tidak kosong)', $adaTeks);
        }

        return $this->tutup();
    }

    private function tutup(): int
    {
        CLI::newLine();
        CLI::write('LULUS: ' . CLI::color((string) $this->lulus, 'green')
            . '   GAGAL: ' . CLI::color((string) $this->gagal, $this->gagal > 0 ? 'red' : 'green'));

        return $this->gagal > 0 ? 1 : 0;
    }
}
