<?php

namespace App\Models;

use App\Models\Concerns\TransaksiAman;
use App\Models\Opd\IkuRevisiModel;
use CodeIgniter\Model;
use RuntimeException;
use Throwable;

/**
 * PENYESUAIAN KEBIJAKAN LAKIP — koreksi yang berlaku HANYA untuk satu tahun
 * LAKIP, dengan dasar kebijakan yang tercatat.
 *
 * ---------------------------------------------------------------------
 * PENYESUAIAN ITU PENGECUALIAN, BUKAN CARA NORMAL MENGUBAH PERENCANAAN
 *
 * Kalau angka LAKIP tahun berjalan perlu berubah karena kebijakan (refocusing
 * anggaran, perubahan asumsi, koreksi pasca-audit), ada dua kemungkinan yang
 * sering tercampur:
 *
 *   a. perubahannya HANYA untuk tahun itu   -> penyesuaian LAKIP (di sini);
 *   b. perubahannya semestinya berlaku juga tahun-tahun berikutnya
 *                                            -> itu REVISI IKU, bukan penyesuaian.
 *
 * Modul ini memisahkan keduanya secara tegas. Untuk kasus (b) pemakai memilih
 * "Usulkan sebagai Perubahan IKU", dan yang terjadi HANYA satu: sebuah DRAFT
 * revisi IKU dibuat. IKU yang sedang berlaku tidak tersentuh sampai draft itu
 * disahkan lewat modul revisi (invariant 5 / Case 15).
 *
 * ---------------------------------------------------------------------
 * PENYESUAIAN SETELAH FINALISASI
 *
 * LAKIP yang sudah difinalkan tidak boleh disunting destruktif (invariant 6).
 * Tapi kebutuhan koreksi setelah dokumen ditandatangani itu nyata. Jalan
 * keluarnya: penyesuaian tetap boleh dibuat, TAPI `setelah_final` otomatis
 * disetel 1 dan tidak bisa dimatikan dari form. Angkanya boleh berubah;
 * kenyataan bahwa perubahan itu terjadi sesudah finalisasi tidak boleh hilang.
 *
 * ---------------------------------------------------------------------
 * NILAI ASLI DIBEKUKAN
 *
 * `nilai_asli` disalin saat penyesuaian dibuat, bukan dibaca ulang saat
 * ditampilkan. Kalau tidak, kolom "sebelum" ikut bergerak setiap kali sumbernya
 * berubah dan perbandingan sebelum/sesudah jadi tidak berarti.
 *
 * ---------------------------------------------------------------------
 * SATU PENYESUAIAN AKTIF PER (LINGKUP, TARGET, JENIS)
 *
 * Dijamin UNIQUE index di atas generated column `aktif_key`. Penyesuaian yang
 * digantikan tidak dihapus, hanya di-nonaktifkan — jejaknya bagian dari
 * pertanggungjawaban.
 */
class LakipPenyesuaianModel extends Model
{
    use TransaksiAman;

    protected $table         = 'lakip_penyesuaian';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    /** Bagian LAKIP yang boleh disesuaikan. */
    public const JENIS = ['target', 'realisasi', 'satuan', 'indikator', 'lainnya'];

    /* =========================================================
     * KESIAPAN
     * =======================================================*/

    public function siap(): bool
    {
        static $siap = null;

        if ($siap !== null) {
            return $siap;
        }

        try {
            return $siap = $this->db->tableExists('lakip_penyesuaian', false);
        } catch (Throwable $e) {
            return $siap = false;
        }
    }

    /* =========================================================
     * PEMBACAAN
     * =======================================================*/

    /**
     * Penyesuaian aktif satu lingkup, dipetakan per target supaya view bisa
     * menempelkannya ke baris yang tepat.
     *
     * @return array<int, array<string, array<string, mixed>>> [target_id => [jenis => baris]]
     */
    /**
     * Peta penyesuaian aktif, dikunci id baris.
     *
     * @param string|null $sumber batasi pada satu sumber ('iku'|'renstra'|
     *                            'rpjmd'). null = semua sumber; baris tetap
     *                            dikunci memakai kolom milik sumbernya
     *                            masing-masing.
     */
    public function petaAktif(string $tahun, string $mode, ?int $opdId, ?string $sumber = null): array
    {
        if (! $this->siap() || $tahun === '') {
            return [];
        }

        $mode = $mode === 'kabupaten' ? 'kabupaten' : 'opd';

        $b = $this->db->table('lakip_penyesuaian')
            ->where('tahun', $tahun)
            ->where('mode', $mode)
            ->where('aktif', 1)
            ->orderBy('id', 'ASC');

        // Mode kabupaten selalu opd_id 0. Untuk mode OPD, admin_kab yang belum
        // memilih OPD melihat lintas OPD — sejalan dengan
        // LakipAnalisisModel::getByTahunGrouped yang memakai !empty().
        if ($mode === 'kabupaten') {
            $b->where('opd_id', 0);
        } elseif (! empty($opdId)) {
            $b->where('opd_id', (int) $opdId);
        }

        $peta = [];

        $saring = $sumber !== null ? $this->sumberSah($mode, $sumber) : null;

        foreach ($b->get()->getResultArray() as $r) {
            // Sumber dibaca dari barisnya sendiri, bukan disimpulkan dari mode:
            // satu OPD bisa punya penyesuaian bersumber Renstra tahun lalu dan
            // bersumber IKU tahun ini. Baris lama tanpa `source_type` memang
            // Renstra/RPJMD — sumber IKU belum mungkin saat itu.
            $sumberBaris = (string) ($r['source_type'] ?? '');

            if ($sumberBaris === '') {
                $sumberBaris = $r['rpjmd_target_id'] !== null ? 'rpjmd' : 'renstra';
            }

            if ($saring !== null && $sumberBaris !== $saring) {
                continue;
            }

            $targetId = (int) ($r[$this->kolomKunci($sumberBaris)] ?? 0);

            if ($targetId <= 0) {
                continue;
            }

            $peta[$targetId][$r['jenis']] = $r;
        }

        return $peta;
    }

    /** Seluruh penyesuaian satu lingkup termasuk yang sudah digantikan. */
    public function riwayat(string $tahun, string $mode, ?int $opdId): array
    {
        if (! $this->siap() || $tahun === '') {
            return [];
        }

        $mode = $mode === 'kabupaten' ? 'kabupaten' : 'opd';

        $b = $this->db->table('lakip_penyesuaian')
            ->where('tahun', $tahun)
            ->where('mode', $mode)
            ->orderBy('id', 'DESC');

        if ($mode === 'kabupaten') {
            $b->where('opd_id', 0);
        } elseif (! empty($opdId)) {
            $b->where('opd_id', (int) $opdId);
        }

        return $b->get()->getResultArray();
    }

    public function ambil(int $id): ?array
    {
        if (! $this->siap() || $id <= 0) {
            return null;
        }

        return $this->db->table('lakip_penyesuaian')->where('id', $id)->get()->getRowArray() ?: null;
    }

    /* =========================================================
     * PENULISAN
     * =======================================================*/

    /**
     * Simpan satu penyesuaian.
     *
     * @param array $scope hasil LakipAddendumTrait::lakipScopeFromPost()
     * @param array $data  ['target_id','jenis','nilai_asli','nilai_disesuaikan',
     *                      'dasar_kebijakan','nomor_dasar','tanggal_dasar','alasan',
     *                      'usul_revisi_iku']
     */
    /**
     * Kolom kunci baris untuk satu sumber.
     *
     * Sengaja SATU tempat: `simpan()`, `petaAktif()`, dan `riwayat()` harus
     * memakai kolom yang sama persis. Kalau ketiganya menebak sendiri-sendiri,
     * penyesuaian bisa tersimpan lalu tidak pernah terbaca kembali — tanpa
     * galat apa pun.
     */
    private function kolomKunci(string $sumber): string
    {
        switch ($sumber) {
            case 'iku':
                return 'iku_indikator_id';

            case 'rpjmd':
                return 'rpjmd_target_id';

            default:
                return 'renstra_target_id';
        }
    }

    /** Sumber baris yang sah untuk satu mode; nilai asing jatuh ke bawaan mode. */
    private function sumberSah(string $mode, $diminta): string
    {
        $sumber = trim((string) ($diminta ?? ''));

        // IKU sah di KEDUA tingkat: Kabupaten memakai IKU Kabupaten (§24).
        if ($sumber === 'iku') {
            return 'iku';
        }

        return $mode === 'kabupaten' ? 'rpjmd' : 'renstra';
    }

    /** Apakah kolom sumber sudah dipasang (migrasi 2026-08-24). */
    private function punyaKolomSumber(): bool
    {
        return $this->db->fieldExists('source_type', 'lakip_penyesuaian');
    }

    public function simpan(array $scope, array $data, ?int $userId = null): int
    {
        if (! $this->siap()) {
            throw new RuntimeException(
                'Tabel penyesuaian LAKIP belum tersedia. Jalankan '
                . 'db/update_2026-08-18_iku_revisi_lakip_snapshot.sql terlebih dahulu.'
            );
        }

        $tahun = (string) ($scope['tahun'] ?? '');
        $mode  = ($scope['mode'] ?? 'opd') === 'kabupaten' ? 'kabupaten' : 'opd';
        $opdId = $mode === 'kabupaten' ? 0 : (int) ($scope['opdScope'] ?? 0);

        $targetId = (int) ($data['target_id'] ?? 0);
        $jenis    = (string) ($data['jenis'] ?? '');

        if ($tahun === '') {
            throw new RuntimeException('Tahun LAKIP belum ditentukan.');
        }
        if ($targetId <= 0) {
            throw new RuntimeException('Indikator yang disesuaikan belum dipilih.');
        }
        if (! in_array($jenis, self::JENIS, true)) {
            throw new RuntimeException('Jenis penyesuaian tidak dikenal.');
        }

        // Dasar kebijakan & alasan WAJIB. Inilah yang membedakan penyesuaian
        // dari sekadar mengetik ulang angka: tanpa keduanya, dokumen
        // pertanggungjawaban kehilangan alasannya berubah.
        $dasar  = trim((string) ($data['dasar_kebijakan'] ?? ''));
        $alasan = trim((string) ($data['alasan'] ?? ''));

        if ($dasar === '') {
            throw new RuntimeException('Dasar kebijakan wajib diisi.');
        }
        if ($alasan === '') {
            throw new RuntimeException('Alasan penyesuaian wajib diisi.');
        }

        // Koneksi diwariskan, tidak dibuat baru. Tanpa ini model pasangan
        // selalu bicara ke basis data BAWAAN — di produksi kebetulan sama,
        // tetapi pada salinan uji ia diam-diam membaca data produksi, dan
        // status "setelah final" jadi disimpulkan dari tahun yang salah.
        $snapshotModel = new LakipSnapshotModel($this->db);
        $snapshot      = $snapshotModel->aktif($tahun, $mode, $opdId);

        $setelahFinal = $snapshot !== null && $snapshot['status'] === LakipSnapshotModel::STATUS_FINAL;

        $sumber = $this->sumberSah($mode, $data['sumber'] ?? null);
        $kunci  = $this->kolomKunci($sumber);

        $barisSnapshotId = null;
        if ($snapshot !== null) {
            // Kolom kunci di `lakip_snapshot_baris` mengikuti sumber yang sama.
            // Sebelum ini selalu `renstra_target_id`, yang pada snapshot
            // bersumber IKU memang NULL — penyesuaian setelah finalisasi
            // karena itu tidak pernah tertaut ke baris beku yang dikoreksinya.
            $kolom = $kunci;
            $baris = $this->db->table('lakip_snapshot_baris')
                ->select('id')
                ->where('snapshot_id', (int) $snapshot['id'])
                ->where($kolom, $targetId)
                ->get()
                ->getRowArray();

            $barisSnapshotId = $baris ? (int) $baris['id'] : null;
        }

        $isi = [
            'tahun'             => $tahun,
            'mode'              => $mode,
            'opd_id'            => $opdId,
            'renstra_target_id' => $kunci === 'renstra_target_id' ? $targetId : null,
            'rpjmd_target_id'   => $kunci === 'rpjmd_target_id' ? $targetId : null,
            'snapshot_id'       => $snapshot !== null ? (int) $snapshot['id'] : null,
            'snapshot_baris_id' => $barisSnapshotId,
            'jenis'             => $jenis,
            'nilai_asli'        => $this->kosongJadiNull($data['nilai_asli'] ?? null),
            'nilai_disesuaikan' => $this->kosongJadiNull($data['nilai_disesuaikan'] ?? null),
            'dasar_kebijakan'   => $dasar,
            'nomor_dasar'       => $this->kosongJadiNull($data['nomor_dasar'] ?? null),
            'tanggal_dasar'     => $this->kosongJadiNull($data['tanggal_dasar'] ?? null),
            'alasan'            => $alasan,
            'setelah_final'     => $setelahFinal ? 1 : 0,
            'sumber_kolom_iku'  => $kunci === 'iku_indikator_id' ? $targetId : null,
            'sumber_tipe'       => $sumber,
            'usul_revisi_iku'   => ! empty($data['usul_revisi_iku']) ? 1 : 0,
            'aktif'             => 1,
            'dibuat_oleh'       => $userId,
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ];

        // Instalasi yang belum menjalankan migrasi 2026-08-24 tidak punya kedua
        // kolom ini. Menyisipkannya di sana akan menggagalkan SELURUH
        // penyimpanan penyesuaian, termasuk yang bersumber Renstra dan sudah
        // berjalan baik — jadi kolomnya hanya ikut bila memang ada.
        $tipeSumber = $isi['sumber_tipe'];
        $kunciIku   = $isi['sumber_kolom_iku'];
        unset($isi['sumber_tipe'], $isi['sumber_kolom_iku']);

        if ($this->punyaKolomSumber()) {
            $isi['source_type']      = $tipeSumber;
            $isi['iku_indikator_id'] = $kunciIku;
        } elseif ($kunci === 'iku_indikator_id') {
            throw new RuntimeException(
                'Penyesuaian untuk LAKIP bersumber IKU membutuhkan migrasi '
                . 'db/update_2026-08-24_penyesuaian_sumber_iku.sql. Jalankan dulu di server ini.'
            );
        }

        return $this->dalamTransaksi(function () use ($isi, $tahun, $mode, $opdId, $targetId, $jenis, $kunci, $tipeSumber) {
            // Penyesuaian sebelumnya untuk sasaran yang sama di-nonaktifkan
            // lebih dulu — bukan dihapus, dan bukan pula ditimpa. UNIQUE index
            // `uq_lakip_penyesuaian_aktif` akan menolak dua baris aktif.
            $lama = $this->db->table('lakip_penyesuaian')
                ->where('tahun', $tahun)
                ->where('mode', $mode)
                ->where('opd_id', $opdId)
                ->where($kunci, $targetId)
                ->where('jenis', $jenis)
                ->where('aktif', 1);

            // Baris lama (sebelum migrasi) tidak punya source_type; ia
            // dianggap 'renstra' oleh `sumber_key`, jadi penyaringnya pun
            // harus memperlakukan NULL sebagai 'renstra'.
            if ($this->punyaKolomSumber()) {
                if ($tipeSumber === 'renstra') {
                    $lama->groupStart()
                        ->where('source_type', 'renstra')
                        ->orWhere('source_type IS NULL', null, false)
                        ->groupEnd();
                } else {
                    $lama->where('source_type', $tipeSumber);
                }
            }

            $lama->update(['aktif' => 0, 'updated_at' => date('Y-m-d H:i:s')]);

            $this->db->table('lakip_penyesuaian')->insert($isi);

            return (int) $this->db->insertID();
        }, 'penyimpanan penyesuaian LAKIP');
    }

    /**
     * CASE 15 — "Usulkan sebagai Perubahan IKU".
     *
     * Yang terjadi HANYA ini: sebuah DRAFT revisi IKU dibuat dan ditautkan ke
     * baris penyesuaian. IKU yang sedang berlaku tidak berubah sedikit pun;
     * perubahannya baru terjadi kalau seseorang membuka modul revisi,
     * menyunting draftnya, lalu mengesahkannya.
     *
     * Keduanya dikerjakan dalam SATU transaksi supaya tidak pernah ada draft
     * revisi yang mengambang tanpa penyesuaian asalnya (invariant 7).
     *
     * @param int|null $opdIkuId lingkup IKU: NULL = kabupaten
     *
     * @return int id draft revisi IKU yang dibuat
     */
    public function usulkanRevisiIku(
        int $penyesuaianId,
        ?int $opdIkuId,
        int $tahunMulai,
        int $tahunAkhir,
        int $berlakuMulaiTahun,
        ?int $userId = null
    ): int {
        $penyesuaian = $this->ambil($penyesuaianId);

        if (! $penyesuaian) {
            throw new RuntimeException('Penyesuaian tidak ditemukan.');
        }

        if (! empty($penyesuaian['iku_revisi_id'])) {
            throw new RuntimeException(
                'Penyesuaian ini sudah pernah diusulkan sebagai perubahan IKU '
                . '(draft revisi #' . $penyesuaian['iku_revisi_id'] . ').'
            );
        }

        $revisiModel = new IkuRevisiModel();

        if (! $revisiModel->siap()) {
            throw new RuntimeException('Modul revisi IKU belum tersedia di basis data ini.');
        }

        return $this->dalamTransaksi(function () use (
            $revisiModel,
            $penyesuaian,
            $penyesuaianId,
            $opdIkuId,
            $tahunMulai,
            $tahunAkhir,
            $berlakuMulaiTahun,
            $userId
        ) {
            $catatan = 'Diusulkan dari Penyesuaian Kebijakan LAKIP tahun ' . $penyesuaian['tahun']
                . ' (' . $penyesuaian['jenis'] . '): ' . $penyesuaian['alasan']
                . ' [dasar: ' . $penyesuaian['dasar_kebijakan'] . ']';

            // buatDraftInti(), bukan buatDraft(): kita SUDAH di dalam transaksi
            // dan transaksi bersarang CodeIgniter tidak bisa di-rollback.
            $revisiId = $revisiModel->buatDraftInti(
                [
                    'nama'          => 'Usulan Perubahan IKU dari LAKIP ' . $penyesuaian['tahun'],
                    'dasar_hukum'   => $penyesuaian['dasar_kebijakan'],
                    'nomor_dasar'   => $penyesuaian['nomor_dasar'],
                    'tanggal_dasar' => $penyesuaian['tanggal_dasar'],
                    'catatan'       => $catatan,
                    'dibuat_oleh'   => $userId,
                ],
                $opdIkuId,
                $tahunMulai,
                $tahunAkhir,
                $berlakuMulaiTahun
            );

            $this->db->table('lakip_penyesuaian')->where('id', $penyesuaianId)->update([
                'usul_revisi_iku' => 1,
                'iku_revisi_id'   => $revisiId,
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

            return $revisiId;
        }, 'usulan perubahan IKU dari penyesuaian LAKIP');
    }

    /**
     * Cabut penyesuaian.
     *
     * Barisnya di-nonaktifkan, bukan dihapus: sebuah koreksi yang pernah
     * dipakai untuk mencetak dokumen tidak boleh lenyap tanpa bekas, apalagi
     * bila dibuat setelah finalisasi.
     */
    public function cabut(int $id): bool
    {
        $baris = $this->ambil($id);

        if (! $baris) {
            throw new RuntimeException('Penyesuaian tidak ditemukan.');
        }

        return (bool) $this->db->table('lakip_penyesuaian')->where('id', $id)->update([
            'aktif'      => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function kosongJadiNull($nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        $nilai = trim((string) $nilai);

        return $nilai === '' ? null : $nilai;
    }
}
