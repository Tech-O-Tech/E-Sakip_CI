<?php

namespace App\Commands;

use App\Models\Opd\IkuRevisiModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Uji penjaga penyimpanan draft revisi IKU.
 *
 *   php spark iku:jaga-draft --db e-sakip_7
 *
 * Empat penjagaan yang diuji di sini semuanya lahir dari cacat nyata, dan
 * semuanya SENYAP — tidak ada galat, hanya data yang salah di kemudian hari:
 *
 *   1. Teks indikator baris arsip boleh dikosongkan -> indikator tanpa nama
 *      ikut tersalin ke IKU berjalan dan LAKIP saat draft disahkan.
 *   2. `indikator_sebelumnya_id` tetap terkirim meski jenis perubahan bukan
 *      "pengganti" (dropdown-nya cuma disembunyikan) -> riwayat indikator
 *      menunjuk leluhur yang sudah tidak berlaku.
 *   3. Kartu indikator baru yang terisi sebagian tetapi lupa nama indikatornya
 *      dibuang diam-diam, sementara pemakai menerima pesan "tersimpan".
 *   4. Kartu yang benar-benar kosong TETAP boleh diabaikan — pemakai menekan
 *      "Tambah Indikator" lalu berubah pikiran. Tanpa pengujian ini, perbaikan
 *      nomor 3 gampang kebablasan jadi galat untuk kartu yang tak tersentuh.
 *
 * Perintah ini menulis, jadi ia MENOLAK berjalan di basis data yang sedang
 * dipakai aplikasi. Jalankan pada salinan.
 */
class IkuDraftGuardCheck extends BaseCommand
{
    protected $group       = 'Versioning';
    protected $name        = 'iku:jaga-draft';
    protected $description = 'Uji penjaga simpanSuntinganDraft() pada salinan basis data.';
    protected $usage       = 'iku:jaga-draft --db <salinan>';
    protected $arguments   = [];
    protected $options     = ['--db' => 'basis data salinan (wajib, tidak boleh sama dengan yang dipakai aplikasi)'];

    private $db;
    private int $lulus = 0;
    private int $gagal = 0;

    public function run(array $params)
    {
        $namaDb = trim((string) ($params['db'] ?? CLI::getOption('db') ?: ''));
        $cfg    = config('Database')->default;

        if ($namaDb === '' || $namaDb === '1') {
            CLI::error('Wajib --db <salinan>. Perintah ini menulis ke basis data.');

            return EXIT_ERROR;
        }

        if ($namaDb === $cfg['database']) {
            CLI::error('Menolak berjalan di "' . $namaDb . '": itu basis data aplikasi. Pakai salinan.');

            return EXIT_ERROR;
        }

        $cfg['database'] = $namaDb;
        $this->db        = db_connect($cfg, false);

        CLI::write('Basis data : ' . CLI::color($this->db->getDatabase(), 'yellow'));
        CLI::newLine();

        [$revisiId, $sasaranId, $arsipId] = $this->siapkanDraft();

        if ($revisiId === 0) {
            CLI::error('Tidak berhasil menyiapkan draft uji.');

            return EXIT_ERROR;
        }

        try {
            $this->ujiSemua($revisiId, $sasaranId, $arsipId);
        } finally {
            $this->bersihkan($revisiId);
        }

        CLI::newLine();
        CLI::write('LULUS: ' . CLI::color((string) $this->lulus, 'green')
            . '   GAGAL: ' . CLI::color((string) $this->gagal, $this->gagal > 0 ? 'red' : 'green'));

        return $this->gagal > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }

    /* =========================================================
     * PERSIAPAN
     * =======================================================*/

    /** @return array{0:int,1:int,2:int} [revisiId, sasaranArsipId, indikatorArsipId] */
    private function siapkanDraft(): array
    {
        $now = date('Y-m-d H:i:s');

        // Nomor sengaja jauh di atas yang wajar supaya tidak bertabrakan dengan
        // data uji lain di salinan yang sama.
        $this->db->table('iku_revisi')->insert([
            'opd_id'               => 11,
            'tahun_mulai'          => 2030,
            'tahun_akhir'          => 2034,
            'nomor'                => 9900,
            'nama'                 => '[UJI PENJAGA] draft sementara',
            'berlaku_mulai_tahun'  => 2030,
            'berlaku_sampai_tahun' => null,
            'status'               => 'draft',
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);

        $revisiId = (int) $this->db->insertID();

        $this->db->table('iku_revisi_sasaran')->insert([
            'revisi_id'       => $revisiId,
            'sasaran'         => 'Sasaran uji penjaga',
            'tahun_mulai'     => 2030,
            'tahun_akhir'     => 2034,
            'urutan'          => 1,
            'jenis_perubahan' => 'tetap',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $sasaranId = (int) $this->db->insertID();

        $this->db->table('iku_revisi_indikator')->insert([
            'revisi_id'               => $revisiId,
            'revisi_sasaran_id'       => $sasaranId,
            'indikator'               => 'Indikator uji penjaga',
            'urutan'                  => 1,
            'status'                  => 'aktif',
            'jenis_perubahan'         => 'pengganti',
            'indikator_sebelumnya_id' => 777,
            'perubahan_substansial'   => 0,
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);

        return [$revisiId, $sasaranId, (int) $this->db->insertID()];
    }

    private function bersihkan(int $revisiId): void
    {
        $idInd = array_column($this->db->table('iku_revisi_indikator')
            ->select('id')->where('revisi_id', $revisiId)->get()->getResultArray(), 'id');

        if ($idInd !== []) {
            $this->db->table('iku_revisi_target')->whereIn('revisi_indikator_id', $idInd)->delete();
        }

        $this->db->table('iku_revisi_indikator')->where('revisi_id', $revisiId)->delete();
        $this->db->table('iku_revisi_sasaran')->where('revisi_id', $revisiId)->delete();
        $this->db->table('iku_revisi')->where('id', $revisiId)->delete();
    }

    /* =========================================================
     * PENGUJIAN
     * =======================================================*/

    private function ujiSemua(int $revisiId, int $sasaranId, int $arsipId): void
    {
        $model = new IkuRevisiModel($this->db);

        $barisUtuh = static fn (array $ubah = []): array => [$ubah + [
            'indikator'       => 'Indikator uji penjaga',
            'jenis_perubahan' => 'tetap',
        ]];

        // --- 1. teks arsip dikosongkan -> ditolak ---
        $this->harusGagal(
            'teks indikator arsip dikosongkan ditolak',
            fn () => $model->simpanSuntinganDraft($revisiId, [
                $arsipId => ['indikator' => '   ', 'jenis_perubahan' => 'tetap'],
            ]),
            'dikosongkan'
        );

        $this->samaDengan(
            'teks arsip tidak berubah setelah penolakan',
            'Indikator uji penjaga',
            (string) $this->kolomArsip($arsipId, 'indikator')
        );

        // --- 2. lineage basi dibersihkan saat jenis bukan "pengganti" ---
        $model->simpanSuntinganDraft($revisiId, [
            $arsipId => [
                'indikator'               => 'Indikator uji penjaga',
                'jenis_perubahan'         => 'revisi',
                'indikator_sebelumnya_id' => 777,   // tetap terkirim: select cuma disembunyikan
            ],
        ]);

        $this->samaDengan(
            'lineage di-NULL-kan saat jenis bukan pengganti',
            null,
            $this->kolomArsip($arsipId, 'indikator_sebelumnya_id')
        );

        // --- 2b. lineage tetap tersimpan saat jenis memang "pengganti" ---
        $model->simpanSuntinganDraft($revisiId, [
            $arsipId => [
                'indikator'               => 'Indikator uji penjaga',
                'jenis_perubahan'         => 'pengganti',
                'indikator_sebelumnya_id' => 777,
            ],
        ]);

        $this->samaDengan(
            'lineage bertahan saat jenis memang pengganti',
            '777',
            (string) $this->kolomArsip($arsipId, 'indikator_sebelumnya_id')
        );

        // --- 3. kartu baru terisi sebagian tanpa nama -> ditolak, bukan dibuang ---
        $sebelum = $this->jumlahIndikator($revisiId);

        $this->harusGagal(
            'kartu baru terisi sebagian tanpa nama ditolak',
            fn () => $model->simpanSuntinganDraft($revisiId, $barisUtuh([]), [[
                'revisi_sasaran_id' => $sasaranId,
                'indikator'         => '',
                'definisi'          => 'Sudah diketik pemakai, tidak boleh hilang diam-diam.',
                'target'            => [2030 => '80'],
            ]]),
            'masih kosong'
        );

        $this->samaDengan(
            'tidak ada indikator yang ditambahkan saat ditolak',
            $sebelum,
            $this->jumlahIndikator($revisiId)
        );

        // --- 4. kartu yang benar-benar kosong tetap diabaikan diam-diam ---
        try {
            $model->simpanSuntinganDraft($revisiId, $barisUtuh([]), [[
                'revisi_sasaran_id' => $sasaranId,
                'indikator'         => '',
                'definisi'          => '',
                'target'            => [2030 => '', 2031 => ''],
            ]]);

            $this->lulus('kartu kosong diabaikan tanpa galat');
        } catch (Throwable $e) {
            $this->gagal('kartu kosong diabaikan tanpa galat', 'malah melempar: ' . $e->getMessage());
        }

        $this->samaDengan(
            'kartu kosong tidak menambah baris',
            $sebelum,
            $this->jumlahIndikator($revisiId)
        );

        // --- 5. sasaran asing ditolak, bukan dibuang ---
        $this->harusGagal(
            'indikator baru bersasaran asing ditolak',
            fn () => $model->simpanSuntinganDraft($revisiId, $barisUtuh([]), [[
                'revisi_sasaran_id' => 999999,
                'indikator'         => 'Indikator nyasar',
            ]]),
            'tidak terhubung ke sasaran'
        );
    }

    /* =========================================================
     * ALAT
     * =======================================================*/

    private function kolomArsip(int $arsipId, string $kolom)
    {
        $baris = $this->db->table('iku_revisi_indikator')
            ->select($kolom)->where('id', $arsipId)->get()->getRowArray();

        return $baris[$kolom] ?? null;
    }

    private function jumlahIndikator(int $revisiId): int
    {
        return (int) $this->db->table('iku_revisi_indikator')
            ->where('revisi_id', $revisiId)->countAllResults();
    }

    private function harusGagal(string $judul, callable $aksi, string $petikPesan): void
    {
        try {
            $aksi();
            $this->gagal($judul, 'tersimpan tanpa galat');
        } catch (Throwable $e) {
            if (mb_stripos($e->getMessage(), $petikPesan) === false) {
                $this->gagal($judul, 'galatnya bukan yang dimaksud: ' . $e->getMessage());

                return;
            }

            $this->lulus($judul);
        }
    }

    private function samaDengan(string $judul, $harap, $dapat): void
    {
        if ($harap === $dapat) {
            $this->lulus($judul);

            return;
        }

        $this->gagal($judul, 'harap ' . var_export($harap, true) . ', dapat ' . var_export($dapat, true));
    }

    private function lulus(string $judul): void
    {
        $this->lulus++;
        CLI::write('  ' . CLI::color('OK  ', 'green') . $judul);
    }

    private function gagal(string $judul, string $sebab): void
    {
        $this->gagal++;
        CLI::write('  ' . CLI::color('GAGAL', 'red') . ' ' . $judul . ' — ' . $sebab);
    }
}
