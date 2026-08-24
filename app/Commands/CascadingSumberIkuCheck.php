<?php

namespace App\Commands;

use App\Models\CascadingModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * Periksa pembacaan cascading dua sumber (IKU dengan jaring pengaman Renstra).
 *
 *   php spark casc:verify
 *
 * Sejak db/update_2026-08-27_cascading_sumber_iku.sql, baris cascading boleh
 * berjangkar ke IKU. Yang diuji di sini bukan skemanya — itu sudah dijamin
 * migrasinya — melainkan PERILAKU yang dilihat pemakai:
 *
 *   1. baris yang sudah dipetakan membaca teks dari IKU;
 *   2. revisi teks IKU langsung terbaca, tanpa menunggu Renstra diubah;
 *   3. baris yang BELUM dipetakan tetap tampil lewat Renstra, tidak kosong;
 *   4. nama kolom lama tidak berubah, karena 20+ pemanggil bergantung padanya;
 *   5. baris cascading BARU ikut mendapat jangkar IKU.
 *
 * Perintah ini menulis sementara ke `iku_indikator` untuk menguji butir 2,
 * lalu MENGEMBALIKANNYA. Jalankan pada basis data uji bila ragu.
 */
class CascadingSumberIkuCheck extends BaseCommand
{
    protected $group       = 'SAKIP';
    protected $name        = 'casc:verify';
    protected $description = 'Uji cascading membaca IKU dengan jaring pengaman Renstra.';
    protected $usage       = 'casc:verify';

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

        // Basis data yang belum dimigrasi TIDAK dianggap gagal: perilaku lama
        // masih sah sepenuhnya. Yang diperiksa di sana justru sebaliknya —
        // pastikan kode baru tidak mematikan menu Cascading di server lama.
        if (! $db->fieldExists('iku_indikator_id', 'cascading_sasaran_opd')) {
            CLI::write('Kolom jangkar IKU belum ada — menguji MODE KOMPATIBILITAS.', 'yellow');
            CLI::write('(untuk mode penuh, jalankan db/update_2026-08-27_cascading_sumber_iku.sql)');
            CLI::newLine();

            return $this->ujiTanpaMigrasi($db, $model);
        }

        // Pilih OPD contoh dari DATA, bukan id yang dipatok: perintah ini harus
        // tetap berguna di basis data mana pun.
        $terpetakan = $db->table('cascading_sasaran_opd')
            ->select('opd_id, COUNT(*) AS jml', false)
            ->where('iku_indikator_id IS NOT NULL', null, false)
            ->groupBy('opd_id')->orderBy('jml', 'DESC')->limit(1)
            ->get()->getRowArray();

        if ($terpetakan === null) {
            CLI::error('Tidak ada satu pun baris cascading berjangkar IKU.');
            CLI::write('Jalankan backfill-nya lebih dulu (migrasi 2026-08-27).');

            return 1;
        }

        $opdIku  = (int) $terpetakan['opd_id'];
        $periode = $this->periodeOpd($db, $opdIku);

        if ($periode === null) {
            CLI::error('Periode Renstra OPD contoh tidak terbaca.');

            return 1;
        }

        [$mulai, $akhir] = $periode;

        // -------------------------------------------------------------
        CLI::write('== 1. OPD yang sudah terpetakan ke IKU ==', 'cyan');
        // -------------------------------------------------------------
        $rows = $model->getCascadingMatrixByOpd($opdIku, $mulai, $akhir);

        $this->cek('matriks terbaca', $rows !== [], 'baris: ' . count($rows));

        if ($rows === []) {
            return $this->tutup();
        }

        $berjangkar = array_values(array_filter(
            $rows,
            static fn ($r) => ! empty($r['es2_iku_indikator_id'])
        ));

        $this->cek('ada baris bersumber IKU', $berjangkar !== [], count($berjangkar) . ' baris');
        $this->cek('penanda sumber ikut terbawa',
            $berjangkar !== [] && ($berjangkar[0]['es2_source_type'] ?? null) === 'iku');

        foreach (['renstra_sasaran', 'indikator_sasaran', 'satuan', 'indikator_id', 'renstra_sasaran_id'] as $kolom) {
            $this->cek("nama kolom lama `{$kolom}` tidak berubah", array_key_exists($kolom, $rows[0]));
        }

        // -------------------------------------------------------------
        CLI::newLine();
        CLI::write('== 2. Revisi teks IKU langsung terbaca di cascading ==', 'cyan');
        // -------------------------------------------------------------
        if ($berjangkar === []) {
            $this->cek('menemukan baris berjangkar IKU untuk diuji', false);
        } else {
            $contoh   = $berjangkar[0];
            $ikuId    = (int) $contoh['es2_iku_indikator_id'];
            $risId    = (int) $contoh['indikator_id'];
            $teksBaru = 'UJI REVISI IKU ' . $ikuId;

            $asli = $db->table('iku_indikator')->select('indikator')
                ->where('id', $ikuId)->get()->getRowArray();

            $db->table('iku_indikator')->where('id', $ikuId)
                ->update(['indikator' => $teksBaru]);

            try {
                $sesudah = $model->getCascadingMatrixByOpd($opdIku, $mulai, $akhir);

                $terbaca = false;
                foreach ($sesudah as $r) {
                    if ((int) ($r['es2_iku_indikator_id'] ?? 0) === $ikuId
                        && $r['indikator_sasaran'] === $teksBaru) {
                        $terbaca = true;
                        break;
                    }
                }

                $this->cek('teks IKU yang diubah langsung terbaca', $terbaca);

                $ris = $db->table('renstra_indikator_sasaran')->select('indikator_sasaran')
                    ->where('id', $risId)->get()->getRowArray();

                $this->cek('teks Renstra TIDAK ikut tersentuh',
                    ($ris['indikator_sasaran'] ?? '') !== $teksBaru);
            } finally {
                // Wajib pulih walau pemeriksaan di atas melempar.
                $db->table('iku_indikator')->where('id', $ikuId)
                    ->update(['indikator' => $asli['indikator']]);
            }

            $pulih = $db->table('iku_indikator')->select('indikator')
                ->where('id', $ikuId)->get()->getRowArray();

            $this->cek('teks IKU dikembalikan seperti semula',
                $pulih['indikator'] === $asli['indikator']);
        }

        // -------------------------------------------------------------
        CLI::newLine();
        CLI::write('== 3. Jaring pengaman: OPD yang belum terpetakan ==', 'cyan');
        // -------------------------------------------------------------
        $belum = $db->table('cascading_sasaran_opd')
            ->select('opd_id, COUNT(*) AS jml', false)
            ->where('iku_indikator_id IS NULL', null, false)
            ->groupBy('opd_id')->orderBy('jml', 'DESC')->limit(1)
            ->get()->getRowArray();

        if ($belum === null) {
            CLI::write('  (dilewati — seluruh baris sudah terpetakan)', 'dark_gray');
        } else {
            $opdRenstra = (int) $belum['opd_id'];
            $periodeR   = $this->periodeOpd($db, $opdRenstra);

            if ($periodeR === null) {
                $this->cek('periode OPD jaring pengaman terbaca', false);
            } else {
                $barisR = $model->getCascadingMatrixByOpd($opdRenstra, $periodeR[0], $periodeR[1]);

                $this->cek('matriksnya tetap terbaca', $barisR !== [], 'baris: ' . count($barisR));

                $adaTeks = false;
                foreach ($barisR as $r) {
                    if (! empty($r['indikator_sasaran'])) {
                        $adaTeks = true;
                        break;
                    }
                }

                $this->cek('teks indikator tetap tampil, bukan kosong', $adaTeks);
            }
        }

        // -------------------------------------------------------------
        CLI::newLine();
        CLI::write('== 4. Padanan IKU untuk baris cascading BARU ==', 'cyan');
        // -------------------------------------------------------------
        $sudah = $db->table('cascading_sasaran_opd')
            ->select('renstra_indikator_sasaran_id, iku_indikator_id')
            ->where('iku_indikator_id IS NOT NULL', null, false)
            ->where('renstra_indikator_sasaran_id IS NOT NULL', null, false)
            ->limit(1)->get()->getRowArray();

        if ($sudah === null) {
            $this->cek('menemukan baris terpetakan untuk diuji', false);
        } else {
            $padanan = $model->padananIkuIndikator((int) $sudah['renstra_indikator_sasaran_id']);

            $this->cek('padanan ketemu lewat silsilah', $padanan !== null);
            $this->cek('hasilnya sama dengan backfill migrasi',
                $padanan === (int) $sudah['iku_indikator_id'],
                'helper: ' . var_export($padanan, true) . ' vs backfill: ' . $sudah['iku_indikator_id']);
        }

        $this->cek('id tak dikenal mengembalikan null', $model->padananIkuIndikator(999999) === null);
        $this->cek('id nol mengembalikan null', $model->padananIkuIndikator(0) === null);

        // -------------------------------------------------------------
        CLI::newLine();
        CLI::write('== 5. Baris cascading yang tak lagi terlihat di layar ==', 'cyan');
        // -------------------------------------------------------------
        // Matriks digerakkan dari rantai Renstra. Baris yang kehilangan jangkar
        // Renstra-nya (akibat ON DELETE SET NULL) MASIH TERSIMPAN tetapi belum
        // ikut terender. Dihitung di sini supaya tidak pernah senyap.
        $tersembunyi = (int) $db->table('cascading_sasaran_opd')
            ->where('renstra_indikator_sasaran_id IS NULL', null, false)
            ->countAllResults();

        $this->cek('tidak ada baris yang tersembunyi dari layar', $tersembunyi === 0,
            $tersembunyi . ' baris kehilangan jangkar Renstra — datanya aman, '
            . 'tapi belum ikut terender; perlu jalur render bersumber IKU');

        return $this->tutup();
    }

    /**
     * Mode kompatibilitas: basis data belum punya kolom jangkar IKU.
     *
     * Yang dijamin di sini cuma satu hal, tapi hal itu penting — kode baru
     * TIDAK BOLEH mematikan menu Cascading di server yang belum dimigrasi.
     * Tanpa penjaga fieldExists(), query akan tumbang dengan "Unknown column"
     * dan seluruh menu ikut mati.
     */
    private function ujiTanpaMigrasi($db, CascadingModel $model): int
    {
        $opd = $db->table('cascading_sasaran_opd')
            ->select('opd_id, COUNT(*) AS jml', false)
            ->groupBy('opd_id')->orderBy('jml', 'DESC')->limit(1)
            ->get()->getRowArray();

        if ($opd === null) {
            CLI::write('  (dilewati — belum ada data cascading sama sekali)', 'dark_gray');

            return $this->tutup();
        }

        $opdId   = (int) $opd['opd_id'];
        $periode = $this->periodeOpd($db, $opdId);

        if ($periode === null) {
            $this->cek('periode Renstra OPD contoh terbaca', false);

            return $this->tutup();
        }

        $rows = $model->getCascadingMatrixByOpd($opdId, $periode[0], $periode[1]);

        $this->cek('matriks tetap terbaca tanpa migrasi', $rows !== [], 'baris: ' . count($rows));

        if ($rows === []) {
            return $this->tutup();
        }

        foreach (['renstra_sasaran', 'indikator_sasaran', 'satuan', 'indikator_id'] as $kolom) {
            $this->cek("kolom `{$kolom}` tetap tersedia", array_key_exists($kolom, $rows[0]));
        }

        $adaTeks = false;
        foreach ($rows as $r) {
            if (! empty($r['indikator_sasaran'])) {
                $adaTeks = true;
                break;
            }
        }

        $this->cek('teks indikator tetap tampil', $adaTeks);
        $this->cek('penanda sumber diisi "renstra"', ($rows[0]['es2_source_type'] ?? null) === 'renstra');
        $this->cek('jangkar IKU dilaporkan kosong', ($rows[0]['es2_iku_indikator_id'] ?? null) === null);
        $this->cek('padananIkuIndikator() aman dipanggil', $model->padananIkuIndikator(1) === null
            || is_int($model->padananIkuIndikator(1)));

        return $this->tutup();
    }

    /** @return array{0:int,1:int}|null */
    private function periodeOpd($db, int $opdId): ?array
    {
        $row = $db->table('renstra_sasaran')
            ->select('tahun_mulai, tahun_akhir, COUNT(*) AS jml', false)
            ->where('opd_id', $opdId)
            ->groupBy(['tahun_mulai', 'tahun_akhir'])
            ->orderBy('jml', 'DESC')->limit(1)
            ->get()->getRowArray();

        return $row === null ? null : [(int) $row['tahun_mulai'], (int) $row['tahun_akhir']];
    }

    private function tutup(): int
    {
        CLI::newLine();
        CLI::write('LULUS: ' . CLI::color((string) $this->lulus, 'green')
            . '   GAGAL: ' . CLI::color((string) $this->gagal, $this->gagal > 0 ? 'red' : 'green'));

        return $this->gagal > 0 ? 1 : 0;
    }
}
