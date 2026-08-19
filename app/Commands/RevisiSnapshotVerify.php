<?php

namespace App\Commands;

use App\Models\LakipPenyesuaianModel;
use App\Models\LakipSnapshotModel;
use App\Models\Opd\IkuModel;
use App\Models\Opd\IkuRevisiModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Verifikasi invariant Revisi IKU + Snapshot LAKIP + Penyesuaian Kebijakan.
 *
 *   php spark revisi:verify
 *
 * Menjalankan Case 11-15 terhadap DATA UJI pada periode 2090-2094 (tingkat
 * kabupaten), lalu membersihkannya sendiri. Data produksi tidak disentuh.
 *
 * ---------------------------------------------------------------------
 * MENGAPA TIDAK MEMBUNGKUS SEMUA DALAM SATU TRANSAKSI LALU ROLLBACK
 * (seperti yang dilakukan spark dash:verify)
 *
 * Karena yang sedang diuji ADALAH transaksinya. Model revisi & snapshot
 * membuka transaksinya sendiri, dan TransaksiAman sengaja MENOLAK berjalan di
 * dalam transaksi lain — sebab transaksi bersarang CodeIgniter tidak melakukan
 * rollback sungguhan. Membungkus uji ini dalam transaksi justru akan membuat
 * seluruh berkas gagal dengan alasan yang benar.
 *
 * Karena itu pembersihannya eksplisit di blok akhir.
 * ---------------------------------------------------------------------
 */
class RevisiSnapshotVerify extends BaseCommand
{
    protected $group       = 'SAKIP';
    protected $name        = 'revisi:verify';
    protected $description = 'Uji Case 11-15: konflik revisi, snapshot ganda, rollback, indikator pengganti, usulan revisi.';

    private const MULAI = 2090;
    private const AKHIR = 2094;
    private const TANDA = 'UJI-REVISI-SNAPSHOT';

    private $db;
    private int $lulus = 0;
    private int $gagal = 0;

    public function run(array $params)
    {
        $this->db = db_connect();

        $revisi     = new IkuRevisiModel();
        $snapshot   = new LakipSnapshotModel();
        $penyesuaian = new LakipPenyesuaianModel();
        $iku        = new IkuModel();

        if (! $revisi->siap() || ! $snapshot->siap() || ! $penyesuaian->siap()) {
            CLI::error('Tabel revisi/snapshot/penyesuaian belum ada.');
            CLI::write('Jalankan dulu: mysql -u root test_sakip < db/update_2026-08-18_iku_revisi_lakip_snapshot.sql');

            return 1;
        }

        $this->bersihkan();

        try {
            [$sasaranId, $indikatorA] = $this->siapkanDataUji();

            $r1 = $this->case14($revisi, $iku, $sasaranId, $indikatorA);
            $this->case11($revisi, $r1);
            $this->case12($snapshot);
            $this->case13($snapshot);
            $this->case15($revisi, $penyesuaian, $iku, $sasaranId);
        } catch (Throwable $e) {
            $this->gagal++;
            CLI::error('GALAT TAK TERDUGA: ' . $e->getMessage());
            CLI::write($e->getFile() . ':' . $e->getLine(), 'dark_gray');
        } finally {
            $this->bersihkan();
        }

        CLI::newLine();
        CLI::write(str_repeat('=', 62), 'dark_gray');
        CLI::write('LULUS: ' . $this->lulus . '   GAGAL: ' . $this->gagal, $this->gagal ? 'red' : 'green');

        return $this->gagal ? 1 : 0;
    }

    /* =========================================================
     * CASE 14 — INDIKATOR PENGGANTI + LINEAGE
     * =======================================================*/

    /**
     * Indikator A digantikan Indikator B mulai 2092.
     * LAKIP 2091 tetap A, LAKIP 2092 memakai B, lineage A -> B tetap terlacak.
     */
    private function case14(IkuRevisiModel $revisi, IkuModel $iku, int $sasaranId, int $indikatorA): int
    {
        CLI::newLine();
        CLI::write('CASE 14 — Indikator pengganti & lineage', 'yellow');

        $r1 = $revisi->buatDraft([
            'opd_id'              => null,
            'tahun_mulai'         => self::MULAI,
            'tahun_akhir'         => self::AKHIR,
            'nama'                => self::TANDA . ' Revisi 1',
            'berlaku_mulai_tahun' => 2092,
        ]);

        $baseline = $this->db->table('iku_revisi')
            ->where('opd_key', 0)->where('tahun_mulai', self::MULAI)->where('nomor', 0)
            ->get()->getRowArray();

        $this->cek('baseline (revisi ke-0) dibuat otomatis & berlaku', $baseline && $baseline['status'] === 'berlaku');
        $this->cek('baseline memuat Indikator A', $this->arsipPunya((int) $baseline['id'], 'Indikator A'));

        // --- sunting isi draft: A dihentikan, B menggantikannya ---
        $arsipA = $this->db->table('iku_revisi_indikator')
            ->where('revisi_id', $r1)->where('sumber_indikator_id', $indikatorA)->get()->getRowArray();

        $this->db->table('iku_revisi_indikator')->where('id', (int) $arsipA['id'])
            ->update(['jenis_perubahan' => IkuRevisiModel::UBAH_DIHENTIKAN]);

        $this->db->table('iku_revisi_indikator')->insert([
            'revisi_id'               => $r1,
            'revisi_sasaran_id'       => (int) $arsipA['revisi_sasaran_id'],
            'sumber_indikator_id'     => null,
            'indikator'               => self::TANDA . ' Indikator B',
            'satuan'                  => '%',
            'satuan_nama'             => '%',
            'urutan'                  => 1,
            'status'                  => 'selesai',
            'jenis_perubahan'         => IkuRevisiModel::UBAH_PENGGANTI,
            'indikator_sebelumnya_id' => $indikatorA,
            'perubahan_substansial'   => 1,
        ]);
        $arsipB = (int) $this->db->insertID();

        foreach ([2092, 2093, 2094] as $th) {
            $this->db->table('iku_revisi_target')->insert([
                'revisi_indikator_id' => $arsipB, 'tahun' => $th, 'target' => '90',
            ]);
        }

        // --- INVARIANT 1 & 5: draft belum menyentuh tabel live ---
        $bLive = $this->db->table('iku_indikator')
            ->like('indikator', self::TANDA . ' Indikator B')->countAllResults();
        $aMasihAktif = $this->db->table('iku_indikator')
            ->where('id', $indikatorA)->where('dihentikan_pada IS NULL', null, false)->countAllResults();

        $this->cek('draft TIDAK membuat indikator baru di tabel live', $bLive === 0);
        $this->cek('draft TIDAK memensiunkan indikator A', $aMasihAktif === 1);

        // --- sahkan ---
        $revisi->sahkan($r1, null);

        $a = $this->db->table('iku_indikator')->where('id', $indikatorA)->get()->getRowArray();
        $b = $this->db->table('iku_indikator')
            ->like('indikator', self::TANDA . ' Indikator B')->get()->getRowArray();

        $this->cek('setelah disahkan: A DIPENSIUNKAN, bukan dihapus', $a !== null && $a['dihentikan_pada'] !== null);
        $this->cek('A berlaku sampai 2091 (setahun sebelum revisi)', $a && (int) $a['berlaku_sampai'] === 2091);
        $this->cek('target lama A tetap utuh (tidak ikut terhapus)',
            $this->db->table('iku_target')->where('iku_indikator_id', $indikatorA)->countAllResults() === 5);
        $this->cek('B lahir di tabel live', $b !== null);
        $this->cek('lineage B -> A tersimpan', $b && (int) $b['indikator_sebelumnya_id'] === $indikatorA);
        $this->cek('B ditandai perubahan substansial (tren tidak boleh disambung)',
            $b && (int) $b['perubahan_substansial'] === 1);
        $this->cek('B ditandai jenis_perubahan = pengganti',
            $b && $b['jenis_perubahan'] === IkuRevisiModel::UBAH_PENGGANTI);

        // Penjaga regresi. Penyapuan indikator yang hilang pernah TIDAK dibatasi
        // periode, sehingga mengesahkan revisi periode uji ikut memensiunkan
        // seluruh IKU periode lain milik pemilik yang sama. Pemeriksaan ini yang
        // menangkapnya.
        $this->cekPeriodeLainUtuh();

        // --- LAKIP 2091 lihat A, LAKIP 2092 lihat B ---
        $e2091 = $revisi->resolveEfektif(null, 2091);
        $e2092 = $revisi->resolveEfektif(null, 2092);

        $this->cek('tahun 2091 memakai baseline', $e2091['revisi'] && (int) $e2091['revisi']['nomor'] === 0);
        $this->cek('tahun 2092 memakai revisi ke-1', $e2092['revisi'] && (int) $e2092['revisi']['nomor'] === 1);
        $this->cek('arsip 2091 berisi Indikator A', $this->arsipPunya((int) $e2091['revisi']['id'], 'Indikator A'));
        $this->cek('arsip 2092 berisi Indikator B', $this->arsipPunya((int) $e2092['revisi']['id'], 'Indikator B'));
        $this->cek('isi revisi 2092 TIDAK lagi memuat Indikator A',
            ! $this->arsipPunya((int) $e2092['revisi']['id'], 'Indikator A'));
        $this->cek('tapi JEJAK penghentian A tetap tersimpan di arsip revisi',
            $this->jejakArsipPunya((int) $e2092['revisi']['id'], 'Indikator A', IkuRevisiModel::UBAH_DIHENTIKAN));

        // --- versi berjalan (tabel live) menyembunyikan yang dipensiunkan ---
        $matrix = $iku->getMatrix([
            'level' => 'kabupaten', 'tahun_mulai' => self::MULAI, 'tahun_akhir' => self::AKHIR,
        ]);
        $namaAktif = [];
        foreach ($matrix as $s) {
            foreach ($s['indikator'] as $i) {
                $namaAktif[] = $i['indikator'];
            }
        }
        $this->cek('daftar IKU berjalan menyembunyikan A yang dipensiunkan',
            ! in_array(self::TANDA . ' Indikator A', $namaAktif, true));
        $this->cek('daftar IKU berjalan menampilkan B',
            in_array(self::TANDA . ' Indikator B', $namaAktif, true));

        return $r1;
    }

    /* =========================================================
     * CASE 11 — DUA REVISI AKTIF
     * =======================================================*/

    private function case11(IkuRevisiModel $revisi, int $r1): void
    {
        CLI::newLine();
        CLI::write('CASE 11 — Konflik dua revisi aktif', 'yellow');

        $r2 = $revisi->buatDraft([
            'opd_id'              => null,
            'tahun_mulai'         => self::MULAI,
            'tahun_akhir'         => self::AKHIR,
            'nama'                => self::TANDA . ' Revisi 2 (tabrakan)',
            'berlaku_mulai_tahun' => 2092, // TAHUN YANG SAMA dengan revisi 1
        ]);

        $this->cekMelempar(
            'sahkan revisi kedua utk tahun berlaku yang sama DITOLAK',
            static fn () => $revisi->sahkan($r2, null)
        );

        $masihDraft = $this->db->table('iku_revisi')->where('id', $r2)->get()->getRowArray();
        $this->cek('revisi yang ditolak tetap draft (tidak separuh disahkan)',
            $masihDraft && $masihDraft['status'] === 'draft');
        $this->cek('revisi ke-1 tetap satu-satunya yang berlaku utk 2092',
            $this->db->table('iku_revisi')->where('opd_key', 0)->where('tahun_mulai', self::MULAI)
                ->where('status', 'berlaku')->where('berlaku_mulai_tahun', 2092)->countAllResults() === 1);

        $hasil = $revisi->resolveEfektif(null, 2092);
        $this->cek('resolver menghasilkan satu versi, tanpa konflik',
            $hasil['revisi'] !== null && empty($hasil['konflik']) && (int) $hasil['revisi']['id'] === $r1);

        // Penjaga lapis basis data diuji langsung: index harus menolak,
        // bukan cuma kode PHP yang kebetulan memeriksa lebih dulu.
        $ditolakEngine = false;

        try {
            $this->db->table('iku_revisi')->where('id', $r2)->update(['status' => 'berlaku']);
        } catch (Throwable $e) {
            $ditolakEngine = true;
        }

        if (! $ditolakEngine) {
            $ditolakEngine = $this->db->table('iku_revisi')->where('opd_key', 0)
                ->where('tahun_mulai', self::MULAI)->where('status', 'berlaku')
                ->where('berlaku_mulai_tahun', 2092)->countAllResults() === 1;
        }

        $this->cek('UNIQUE index basis data menolak revisi berlaku kembar', $ditolakEngine);
    }

    /* =========================================================
     * CASE 12 — SNAPSHOT GANDA
     * =======================================================*/

    private function case12(LakipSnapshotModel $snapshot): void
    {
        CLI::newLine();
        CLI::write('CASE 12 — Snapshot LAKIP ganda', 'yellow');

        $scope = ['tahun' => '2092', 'mode' => 'kabupaten', 'opdScope' => 0];
        $bahan = $this->bahanSnapshot();

        $s1 = $snapshot->siapkan($scope, $bahan, null);
        $this->cek('snapshot pertama dibuat', $s1 > 0);

        $this->cekMelempar(
            'tekan "Siapkan LAKIP" lagi TIDAK membuat snapshot kedua',
            static fn () => $snapshot->siapkan($scope, $bahan, null)
        );

        $this->cek('tetap hanya satu snapshot aktif',
            $this->db->table('lakip_snapshot')->where('tahun', 2092)->where('aktif', 1)->countAllResults() === 1);

        $beda = $this->bahanSnapshot('88');
        $banding = $snapshot->bandingkan($s1, $beda);
        $this->cek('Bandingkan mendeteksi target yang berubah', count($banding['berubah']) === 1);

        $s2 = $snapshot->sinkronkan($scope, $beda, null);
        $this->cek('Sinkronkan membuat versi 2', $s2 > 0 && $s2 !== $s1);
        $this->cek('versi 1 dinonaktifkan, bukan dihapus',
            $this->db->table('lakip_snapshot')->where('id', $s1)->where('aktif', 0)->countAllResults() === 1);
        $this->cek('masih tepat satu snapshot aktif',
            $this->db->table('lakip_snapshot')->where('tahun', 2092)->where('aktif', 1)->countAllResults() === 1);

        // Rehidrasi harus mengembalikan bentuk yang dikenal view.
        $rh = $snapshot->rehidrasi($s2);
        $baris = $rh['rows'][0] ?? [];
        $kunci = ['target_id', 'tahun', 'target_tahun_ini', 'indikator_id', 'indikator_sasaran',
            'satuan', 'jenis_indikator', 'sasaran_id', 'sasaran'];
        $this->cek('rehidrasi menghasilkan kunci yang sama dengan LakipModel',
            count(array_diff($kunci, array_keys($baris))) === 0);
        $this->cek('rehidrasi mode kabupaten TIDAK menyisipkan opd_id (bisa menggeser rowspan)',
            ! array_key_exists('opd_id', $baris));
        $this->cek('lakipMap ber-key target_id', isset($rh['lakipMap'][901]));

        $snapshot->finalkan($s2, null);
        $this->cek('snapshot difinalkan', $snapshot->terkunci('2092', 'kabupaten', 0));

        $this->cekMelempar(
            'snapshot final TIDAK boleh disinkronkan ulang',
            static fn () => $snapshot->sinkronkan($scope, $beda, null)
        );
    }

    /* =========================================================
     * CASE 13 — TRANSAKSI GAGAL
     * =======================================================*/

    private function case13(LakipSnapshotModel $snapshot): void
    {
        CLI::newLine();
        CLI::write('CASE 13 — Transaksi gagal di tengah jalan', 'yellow');

        $scope = ['tahun' => '2093', 'mode' => 'kabupaten', 'opdScope' => 0];
        $bahan = $this->bahanSnapshot();

        $v1 = $snapshot->siapkan($scope, $bahan, null);

        // Penghalang: versi 2 sudah "terpakai" oleh baris nonaktif, sehingga
        // penyisipan versi 2 berikutnya pasti ditolak UNIQUE uq_lakip_snapshot_versi.
        //
        // Pelanggaran kunci ganda dipilih sebagai pemicu, BUKAN string kepanjangan,
        // karena app/Config/Database.php menyetel strictOn = false — CodeIgniter
        // membuang STRICT_TRANS_TABLES dari sesi koneksinya, jadi data kepanjangan
        // DIPOTONG diam-diam alih-alih ditolak. Error 1062 tidak pernah dilunakkan
        // oleh sql_mode apa pun.
        $this->db->table('lakip_snapshot')->insert([
            'tahun' => 2093, 'mode' => 'kabupaten', 'opd_id' => 0,
            'versi' => 2, 'label' => self::TANDA . ' penghalang', 'aktif' => 0,
        ]);

        // sinkronkan() menonaktifkan versi lama LEBIH DULU, baru menyisipkan
        // versi baru. Jadi saat penyisipan gagal, sudah ada perubahan yang
        // tertulis — persis keadaan "setengah jadi" yang harus dibatalkan.
        $this->cekMelempar(
            'sinkronisasi yang gagal di tengah melempar galat',
            static fn () => $snapshot->sinkronkan($scope, $bahan, null)
        );

        $v1Row = $this->db->table('lakip_snapshot')->where('id', $v1)->get()->getRowArray();

        $this->cek('versi 1 KEMBALI aktif — penonaktifan ikut dibatalkan',
            $v1Row !== null && (int) $v1Row['aktif'] === 1);
        $this->cek('tidak ada versi baru yang tertinggal',
            $this->db->table('lakip_snapshot')->where('tahun', 2093)->countAllResults() === 2);
        $this->cek('tetap tepat satu snapshot aktif setelah rollback',
            $this->db->table('lakip_snapshot')->where('tahun', 2093)->where('aktif', 1)->countAllResults() === 1);

        // Setelah rollback, jalur normal harus tetap bisa dipakai. Ini menguji
        // bahwa $transStatus yang lengket (tidak di-reset transBegin) memang
        // sudah ditangani TransaksiAman.
        $this->db->table('lakip_snapshot')->where('tahun', 2093)->where('versi', 2)->where('aktif', 0)->delete();

        $v2 = 0;
        try {
            $v2 = $snapshot->sinkronkan($scope, $this->bahanSnapshot('77'), null);
        } catch (Throwable $e) {
            CLI::write('          ' . $e->getMessage(), 'dark_gray');
        }

        $this->cek('operasi berikutnya tetap berhasil setelah rollback', $v2 > 0);
    }

    /* =========================================================
     * CASE 15 — USULAN PERUBAHAN IKU
     * =======================================================*/

    private function case15(
        IkuRevisiModel $revisi,
        LakipPenyesuaianModel $penyesuaian,
        IkuModel $iku,
        int $sasaranId
    ): void {
        CLI::newLine();
        CLI::write('CASE 15 — Penyesuaian diusulkan jadi perubahan IKU', 'yellow');

        $scope = ['tahun' => '2092', 'mode' => 'kabupaten', 'opdScope' => 0];

        $pid = $penyesuaian->simpan($scope, [
            'target_id'         => 901,
            'jenis'             => 'target',
            'nilai_asli'        => '100',
            'nilai_disesuaikan' => '80',
            'dasar_kebijakan'   => 'Peraturan Bupati tentang Refocusing Anggaran',
            'nomor_dasar'       => '12/2092',
            'alasan'            => 'Pagu dipangkas untuk penanganan bencana.',
        ], null);

        $baris = $penyesuaian->ambil($pid);
        $this->cek('penyesuaian tersimpan', $baris !== null);
        $this->cek('dibuat setelah finalisasi -> ditandai setelah_final',
            $baris && (int) $baris['setelah_final'] === 1);
        $this->cek('nilai asli ikut dibekukan', $baris && $baris['nilai_asli'] === '100');

        $this->cekMelempar(
            'penyesuaian tanpa dasar kebijakan ditolak',
            static fn () => $penyesuaian->simpan($scope, [
                'target_id' => 903, 'jenis' => 'target',
                'nilai_disesuaikan' => '1', 'alasan' => 'apa saja',
            ], null)
        );

        $sebelum = $revisi->resolveEfektif(null, 2092);

        $draftId = $penyesuaian->usulkanRevisiIku($pid, null, self::MULAI, self::AKHIR, 2093, null);
        $draft   = $revisi->ambil($draftId);

        $this->cek('usulan membuat revisi IKU', $draft !== null);
        $this->cek('revisi yang dibuat berstatus DRAFT', $draft && $draft['status'] === 'draft');
        $this->cek('penyesuaian tertaut ke draft',
            (int) ($penyesuaian->ambil($pid)['iku_revisi_id'] ?? 0) === $draftId);

        $sesudah = $revisi->resolveEfektif(null, 2092);
        $this->cek('IKU yang berlaku TIDAK berubah oleh usulan',
            (int) $sebelum['revisi']['id'] === (int) $sesudah['revisi']['id']);

        $e2093 = $revisi->resolveEfektif(null, 2093);
        $this->cek('draft TIDAK menjadi sumber tahun 2093',
            $e2093['revisi'] !== null && (int) $e2093['revisi']['id'] !== $draftId);

        $this->cekMelempar(
            'usulan kedua dari penyesuaian yang sama ditolak',
            static fn () => $penyesuaian->usulkanRevisiIku($pid, null, self::MULAI, self::AKHIR, 2094, null)
        );
    }

    /* =========================================================
     * DATA UJI
     * =======================================================*/

    /** @return array{0:int,1:int} [sasaran_id, indikator_a_id] */
    private function siapkanDataUji(): array
    {
        $this->db->table('iku_sasaran')->insert([
            'opd_id'      => null,
            'sasaran'     => self::TANDA . ' Sasaran',
            'tahun_mulai' => self::MULAI,
            'tahun_akhir' => self::AKHIR,
            'urutan'      => 0,
        ]);
        $sasaranId = (int) $this->db->insertID();

        $this->db->table('iku_indikator')->insert([
            'iku_sasaran_id' => $sasaranId,
            'indikator'      => self::TANDA . ' Indikator A',
            'satuan'         => '%',
            'urutan'         => 0,
            'status'         => 'selesai',
        ]);
        $indikatorA = (int) $this->db->insertID();

        foreach (range(self::MULAI, self::AKHIR) as $th) {
            $this->db->table('iku_target')->insert([
                'iku_indikator_id' => $indikatorA, 'tahun' => $th, 'target' => '100',
            ]);
        }

        return [$sasaranId, $indikatorA];
    }

    /** Bahan snapshot berbentuk sama dengan keluaran LakipModel::getLakipByMode(). */
    private function bahanSnapshot(string $target = '100'): array
    {
        return [
            'rows' => [[
                'target_id'         => 901,
                'tahun'             => '2092',
                'target_tahun_ini'  => $target,
                'indikator_id'      => 80,
                'indikator_sasaran' => self::TANDA . ' Indikator LAKIP',
                'satuan'            => '%',
                'jenis_indikator'   => 'positif',
                'sasaran_id'        => 90,
                'sasaran'           => self::TANDA . ' Sasaran LAKIP',
            ]],
            'lakipMap' => [901 => [
                'id'                => 9001,
                'target_hitung'     => null,
                'target_lalu'       => '95',
                'capaian_lalu'      => '93',
                'capaian_tahun_ini' => '97',
                'capaian_hitung'    => null,
                'status'            => 'selesai',
            ]],
            'analisisMap' => [901 => [
                ['id' => 1, 'faktor_pendukung' => 'A', 'faktor_penghambat' => 'B', 'upaya_peningkatan' => 'C'],
                ['id' => 2, 'faktor_pendukung' => 'D', 'faktor_penghambat' => 'E', 'upaya_peningkatan' => 'F'],
            ]],
            'efisiensiRows' => [
                ['program_id' => 1, 'nama_program' => 'Program Uji', 'anggaran' => 1000, 'realisasi' => 900, 'efisiensi' => 100],
            ],
            'filter_status' => '',
        ];
    }

    /**
     * Tidak ada sasaran/indikator DI LUAR periode uji yang ikut dipensiunkan.
     *
     * Satu pemilik boleh punya beberapa periode IKU sekaligus; revisi satu
     * periode tidak boleh menyentuh periode lainnya sama sekali.
     */
    private function cekPeriodeLainUtuh(): void
    {
        $indikator = $this->db->table('iku_indikator i')
            ->join('iku_sasaran s', 's.id = i.iku_sasaran_id', 'left')
            ->where('i.dihentikan_pada IS NOT NULL', null, false)
            ->groupStart()
                ->where('s.tahun_mulai !=', self::MULAI)
                ->orWhere('s.tahun_akhir !=', self::AKHIR)
            ->groupEnd()
            ->countAllResults();

        $sasaran = $this->db->table('iku_sasaran')
            ->where('dihentikan_pada IS NOT NULL', null, false)
            ->groupStart()
                ->where('tahun_mulai !=', self::MULAI)
                ->orWhere('tahun_akhir !=', self::AKHIR)
            ->groupEnd()
            ->countAllResults();

        $this->cek('IKU periode LAIN tidak ikut terpensiun (' . $indikator . ' indikator, ' . $sasaran . ' sasaran)',
            $indikator === 0 && $sasaran === 0);
    }

    /**
     * ISI revisi (yang akan dicetak LAKIP) memuat indikator tersebut.
     * Sengaja lewat isiRevisi(), bukan query langsung, supaya penyaringan nisan
     * ikut teruji.
     */
    private function arsipPunya(int $revisiId, string $potongan): bool
    {
        foreach ((new IkuRevisiModel())->isiRevisi($revisiId) as $sas) {
            foreach ($sas['indikator'] as $ind) {
                if (str_contains((string) $ind['indikator'], $potongan)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Baris apa pun di TABEL arsip, termasuk nisan 'dihentikan'. */
    private function jejakArsipPunya(int $revisiId, string $potongan, ?string $jenis = null): bool
    {
        $b = $this->db->table('iku_revisi_indikator')
            ->where('revisi_id', $revisiId)
            ->like('indikator', $potongan);

        if ($jenis !== null) {
            $b->where('jenis_perubahan', $jenis);
        }

        return $b->countAllResults() > 0;
    }

    private function bersihkan(): void
    {
        $this->db->table('lakip_penyesuaian')->whereIn('tahun', [2092, 2093])->delete();
        $this->db->table('lakip_snapshot')->whereIn('tahun', [2092, 2093])->delete();
        $this->db->table('iku_revisi')->where('tahun_mulai', self::MULAI)->where('tahun_akhir', self::AKHIR)->delete();

        $sasaran = $this->db->table('iku_sasaran')
            ->select('id')->like('sasaran', self::TANDA)->get()->getResultArray();

        foreach ($sasaran as $s) {
            $this->db->table('iku_sasaran')->where('id', (int) $s['id'])->delete();
        }
    }

    /* =========================================================
     * PEMBANDING
     * =======================================================*/

    private function cek(string $judul, bool $lulus): void
    {
        if ($lulus) {
            $this->lulus++;
            CLI::write('  [OK]    ' . $judul, 'green');

            return;
        }

        $this->gagal++;
        CLI::write('  [GAGAL] ' . $judul, 'red');
    }

    /** Lulus bila $kerja melempar. Dipakai untuk aturan yang harus MENOLAK. */
    private function cekMelempar(string $judul, callable $kerja): void
    {
        try {
            $kerja();
        } catch (Throwable $e) {
            $this->lulus++;
            CLI::write('  [OK]    ' . $judul, 'green');
            CLI::write('          ditolak: ' . $this->potong($e->getMessage()), 'dark_gray');

            return;
        }

        $this->gagal++;
        CLI::write('  [GAGAL] ' . $judul . ' — seharusnya ditolak, tapi diterima', 'red');
    }

    private function potong(string $teks, int $maks = 110): string
    {
        $teks = preg_replace('/\s+/', ' ', trim($teks));

        return mb_strlen($teks) > $maks ? mb_substr($teks, 0, $maks) . '...' : $teks;
    }
}
