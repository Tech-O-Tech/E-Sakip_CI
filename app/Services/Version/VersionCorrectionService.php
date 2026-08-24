<?php

namespace App\Services\Version;

use App\Models\Concerns\TransaksiAman;
use App\Models\DokumenVersiModel;
use App\Models\Versi\ArsipVersiModel;
use CodeIgniter\Database\ConnectionInterface;
use RuntimeException;

/**
 * Permintaan koreksi pada versi yang sudah ditetapkan (§20, §21).
 *
 * =====================================================================
 * ALUR — PERMINTAAN DULU, BARU DITERAPKAN
 *
 *   Published  →  Ajukan Koreksi  →  pending
 *                                      ├── Admin Kabupaten: Kembalikan
 *                                      └── Admin Kabupaten: Setujui
 *                                              → diterapkan transaksional
 *                                              → old/new tercatat di audit
 *
 * Bukan "koreksi langsung yang dicatat", melainkan usulan yang menunggu
 * keputusan. §21 menyebutnya eksplisit, dan alasannya masuk akal: yang
 * mengetik salah dan yang menilai apakah itu benar-benar salah ketik
 * sebaiknya bukan orang yang sama.
 * =====================================================================
 *
 * =====================================================================
 * BATAS DAFTAR PUTIH — DAN APA YANG TIDAK BISA DIJAGA MESIN
 *
 * §20 membolehkan koreksi untuk salah ketik, nomor dokumen, catatan
 * administratif, dan perapian teks. Melarangnya untuk perubahan target,
 * penambahan/penghapusan sasaran & indikator, perubahan rumusan, satuan
 * substantif, tanggal berlaku, dan hierarki.
 *
 * Sebagian larangan itu bisa ditegakkan mesin, dan memang ditegakkan di sini:
 * kolom target, satuan, baseline, penunjuk induk, dan effective_from TIDAK
 * ADA dalam daftar putih, jadi tidak bisa disentuh lewat jalur ini sama sekali.
 * Menambah atau menghapus baris juga mustahil — koreksi hanya menyunting satu
 * kolom pada baris yang sudah ada.
 *
 * Tetapi satu hal TIDAK BISA dijaga mesin: mengganti teks indikator bisa berarti
 * memperbaiki ejaan, bisa juga berarti mengganti indikatornya sama sekali.
 * Keduanya menyentuh kolom yang sama. Itulah justru mengapa ada persetujuan
 * Admin Kabupaten — daftar putih menutup yang pasti salah, manusia menilai
 * sisanya. Halaman verifikasi menampilkan nilai lama & baru berdampingan
 * supaya penilaian itu bisa dilakukan.
 * =====================================================================
 */
class VersionCorrectionService
{
    use TransaksiAman;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_RETURNED  = 'returned';
    public const STATUS_CANCELLED = 'cancelled';

    private ConnectionInterface $db;

    private DokumenVersiModel $versi;

    private ArsipRegistry $registry;

    private VersionAuditService $audit;

    public function __construct(
        ?ConnectionInterface $db = null,
        ?DokumenVersiModel $versi = null,
        ?ArsipRegistry $registry = null,
        ?VersionAuditService $audit = null
    ) {
        $this->db       = $db ?? db_connect();
        $this->versi    = $versi ?? new DokumenVersiModel($this->db);
        $this->registry = $registry ?? new ArsipRegistry($this->db);
        $this->audit    = $audit ?? new VersionAuditService($this->db);
    }

    public function siap(): bool
    {
        try {
            return $this->db->tableExists('version_correction_requests');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /* =========================================================
     * DAFTAR PUTIH (§21)
     * =======================================================*/

    /** Koreksi teks naratif — salah ketik, ejaan, catatan. */
    public const KELAS_TEKS = 'teks';

    /** Koreksi NILAI — satuan, baseline, target. Bersyarat lebih ketat. */
    public const KELAS_NILAI = 'nilai';

    /**
     * Kolom yang boleh dikoreksi, per jenis entitas, beserta KELAS-nya.
     *
     * =================================================================
     * KOLOM NILAI: PENYIMPANGAN SADAR DARI §20, ATAS PERMINTAAN PEMILIK SISTEM
     *
     * §20 melarang koreksi mengubah target dan satuan, dan §66 mencontohkan
     * "target 80 → 85 harus ditolak". Larangan itu punya alasan: mengoreksi
     * nilai pada versi yang sudah resmi berarti mengubah apa yang dokumen itu
     * katakan — dan itulah yang justru ingin dicegah versioning.
     *
     * Tetapi larangan mutlak juga punya ongkos: satu salah ketik "80" menjadi
     * "800" memaksa penerbitan versi baru, yang menggeser timeline dan mengotori
     * riwayat demi satu kesalahan ketik.
     *
     * Jalan tengah yang dipakai di sini: kolom nilai BOLEH dikoreksi, tetapi
     *   1. WAJIB menyertakan dasar tertulis (bukan sekadar alasan);
     *   2. DITOLAK bila versi itu sudah pernah dipakai LAKIP sebagai sumber —
     *      di titik itu koreksi benar-benar menulis ulang apa yang sudah
     *      dilaporkan, dan §2.1/§2.10 tidak boleh ditawar;
     *   3. ditandai berbeda di layar & audit, sehingga verifikator tahu ia
     *      sedang menilai perubahan nilai, bukan perapian kalimat.
     *
     * Yang TETAP tidak bisa dikoreksi: hierarki (penunjuk induk), tanggal
     * berlaku, dan penambahan/penghapusan baris. Ketiganya mengubah bentuk
     * dokumen, bukan isinya.
     * =================================================================
     *
     * @return array<string,array{label:string,kolom:array<string,array{label:string,kelas:string}>}>
     */
    public function daftarPutih(): array
    {
        $teks  = static fn (string $l): array => ['label' => $l, 'kelas' => self::KELAS_TEKS];
        $nilai = static fn (string $l): array => ['label' => $l, 'kelas' => self::KELAS_NILAI];

        return [
            // --- kepala versi ---
            'dokumen_versi' => ['label' => 'Keterangan Versi', 'kolom' => [
                'label'            => $teks('Label versi'),
                'dasar_perubahan'  => $teks('Dasar perubahan'),
                'nomor_dasar'      => $teks('Nomor dasar'),
                'alasan_perubahan' => $teks('Alasan perubahan'),
                'catatan'          => $teks('Catatan'),
            ]],

            // --- arsip RPJMD ---
            'rpjmd_versi_misi' => ['label' => 'Misi', 'kolom' => [
                'misi' => $teks('Rumusan misi'), 'visi' => $teks('Rumusan visi'),
            ]],
            'rpjmd_versi_tujuan' => ['label' => 'Tujuan', 'kolom' => [
                'tujuan_rpjmd' => $teks('Rumusan tujuan'), 'catatan_perubahan' => $teks('Catatan'),
            ]],
            'rpjmd_versi_sasaran' => ['label' => 'Sasaran', 'kolom' => [
                'sasaran_rpjmd' => $teks('Rumusan sasaran'), 'csf' => $teks('CSF'),
                'catatan_perubahan' => $teks('Catatan'),
            ]],
            'rpjmd_versi_indikator_sasaran' => ['label' => 'Indikator', 'kolom' => [
                'indikator_sasaran' => $teks('Rumusan indikator'),
                'definisi_op'       => $teks('Definisi operasional'),
                'catatan_perubahan' => $teks('Catatan'),
                'satuan'            => $nilai('Satuan'),
                'baseline'          => $nilai('Baseline'),
            ]],
            'rpjmd_versi_target' => ['label' => 'Target Tahunan', 'kolom' => [
                'target_tahunan' => $nilai('Nilai target'),
            ]],

            // --- arsip Renstra ---
            'renstra_versi_tujuan' => ['label' => 'Tujuan', 'kolom' => [
                'tujuan' => $teks('Rumusan tujuan'), 'catatan_perubahan' => $teks('Catatan'),
            ]],
            'renstra_versi_sasaran' => ['label' => 'Sasaran', 'kolom' => [
                'sasaran' => $teks('Rumusan sasaran'), 'csf' => $teks('CSF'),
                'catatan_perubahan' => $teks('Catatan'),
            ]],
            'renstra_versi_indikator_sasaran' => ['label' => 'Indikator', 'kolom' => [
                'indikator_sasaran' => $teks('Rumusan indikator'),
                'catatan_perubahan' => $teks('Catatan'),
                'satuan'            => $nilai('Satuan'),
                'baseline'          => $nilai('Baseline'),
            ]],
            'renstra_versi_target' => ['label' => 'Target Tahunan', 'kolom' => [
                'target' => $nilai('Nilai target'),
            ]],
        ];
    }

    public function bolehKoreksi(string $entityType, string $field): bool
    {
        return isset($this->daftarPutih()[$entityType]['kolom'][$field]);
    }

    /** KELAS_TEKS atau KELAS_NILAI; null bila kolom tidak boleh dikoreksi. */
    public function kelasKolom(string $entityType, string $field): ?string
    {
        return $this->daftarPutih()[$entityType]['kolom'][$field]['kelas'] ?? null;
    }

    /** Tabel arsip yang barisnya tidak punya `version_id` sendiri. */
    private function tabelTarget(): array
    {
        return [
            'rpjmd_versi_target' => [
                'induk' => 'rpjmd_versi_indikator_sasaran', 'fk' => 'versi_indikator_id',
            ],
            'renstra_versi_target' => [
                'induk' => 'renstra_versi_indikator_sasaran', 'fk' => 'versi_indikator_id',
            ],
        ];
    }

    /**
     * Versi ini sudah pernah dipakai LAKIP sebagai sumber?
     *
     * Inilah garis yang tidak dilewati. Selama belum ada LAKIP yang menariknya,
     * mengoreksi nilai hanya memperbaiki dokumen yang belum dijadikan dasar
     * laporan apa pun. Begitu sebuah snapshot LAKIP mengambil darinya, koreksi
     * nilai berarti arsip dan laporan menyatakan angka yang berbeda — dan yang
     * begitu wajib lewat versi baru (§2.1, §2.10).
     */
    public function dipakaiLakip(int $versiId): int
    {
        try {
            if (! $this->db->tableExists('lakip_snapshot')
                || ! $this->db->fieldExists('source_version_id', 'lakip_snapshot')) {
                return 0;
            }

            return (int) $this->db->table('lakip_snapshot')
                ->where('source_version_id', $versiId)->countAllResults();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Padanan kolom arsip -> kolom tabel LIVE, agar koreksi ikut terlihat di data berjalan. */
    private function petaLive(): array
    {
        return [
            'rpjmd_versi_misi' => ['tabel' => 'rpjmd_misi', 'sumber' => 'source_misi_id',
                'kolom' => ['misi' => 'misi']],
            'rpjmd_versi_tujuan' => ['tabel' => 'rpjmd_tujuan', 'sumber' => 'source_tujuan_id',
                'kolom' => ['tujuan_rpjmd' => 'tujuan_rpjmd']],
            'rpjmd_versi_sasaran' => ['tabel' => 'rpjmd_sasaran', 'sumber' => 'source_sasaran_id',
                'kolom' => ['sasaran_rpjmd' => 'sasaran_rpjmd', 'csf' => 'csf']],
            'rpjmd_versi_indikator_sasaran' => ['tabel' => 'rpjmd_indikator_sasaran', 'sumber' => 'source_indikator_id',
                'kolom' => ['indikator_sasaran' => 'indikator_sasaran', 'definisi_op' => 'definisi_op']],
            'renstra_versi_tujuan' => ['tabel' => 'renstra_tujuan', 'sumber' => 'source_tujuan_id',
                'kolom' => ['tujuan' => 'tujuan']],
            'renstra_versi_sasaran' => ['tabel' => 'renstra_sasaran', 'sumber' => 'source_sasaran_id',
                'kolom' => ['sasaran' => 'sasaran', 'csf' => 'csf']],
            'renstra_versi_indikator_sasaran' => ['tabel' => 'renstra_indikator_sasaran', 'sumber' => 'source_indikator_id',
                'kolom' => ['indikator_sasaran' => 'indikator_sasaran']],
        ];
    }

    /* =========================================================
     * PEMBACAAN
     * =======================================================*/

    public function ambil(int $id): ?array
    {
        if (! $this->siap()) {
            return null;
        }

        return $this->db->table('version_correction_requests')->where('id', $id)
            ->get()->getRowArray() ?: null;
    }

    /** Koreksi milik satu versi, terbaru di atas. */
    public function daftar(int $versiId): array
    {
        if (! $this->siap()) {
            return [];
        }

        return $this->db->table('version_correction_requests')
            ->where('version_id', $versiId)
            ->orderBy('requested_at', 'DESC')->orderBy('id', 'DESC')
            ->get()->getResultArray();
    }

    /** Antrean koreksi menunggu keputusan, disaring modul yang boleh diverifikasi. */
    public function menunggu(?array $modul = null): array
    {
        if (! $this->siap()) {
            return [];
        }

        $b = $this->db->table('version_correction_requests k')
            ->select('k.*, d.modul, d.label AS label_versi, d.version_no, d.opd_id,
                      d.periode_mulai, d.periode_akhir, o.nama_opd')
            ->join('dokumen_versi d', 'd.id = k.version_id')
            ->join('opd o', 'o.id = d.opd_id', 'left')
            ->where('k.status', self::STATUS_PENDING);

        if ($modul !== null && $modul !== []) {
            $b->whereIn('d.modul', $modul);
        }

        return $b->orderBy('k.requested_at', 'ASC')->get()->getResultArray();
    }

    /** Nilai kolom yang sedang berlaku pada arsip — dipakai membekukan `old_value`. */
    public function nilaiSekarang(string $entityType, int $entityId, string $field): ?string
    {
        if (! $this->bolehKoreksi($entityType, $field)) {
            return null;
        }

        $row = $this->db->table($entityType)->select($field)->where('id', $entityId)
            ->get()->getRowArray();

        return $row === null ? null : ($row[$field] === null ? null : (string) $row[$field]);
    }

    /* =========================================================
     * PENGAJUAN
     * =======================================================*/

    /**
     * Ajukan koreksi atas satu kolom.
     *
     * `old_value` DIBEKUKAN di sini, bukan dibaca ulang saat ditampilkan —
     * kalau tidak, kolom "sebelum" ikut bergerak setiap kali sumbernya berubah
     * dan perbandingan sebelum/sesudah kehilangan artinya.
     */
    public function ajukan(int $versiId, array $data, ?int $userId = null): int
    {
        if (! $this->siap()) {
            throw new RuntimeException('Tabel permintaan koreksi belum terpasang.');
        }

        $versi = $this->versi->ambil($versiId);

        if ($versi === null) {
            throw new RuntimeException('Versi tidak ditemukan.');
        }

        // §20: koreksi hanya untuk versi yang SUDAH ditetapkan. Draft cukup
        // disunting biasa — tidak perlu antre persetujuan untuk sesuatu yang
        // belum mengikat siapa pun.
        if ($versi['status'] !== DokumenVersiModel::STATUS_PUBLISHED) {
            throw new RuntimeException(
                'Koreksi hanya untuk versi yang sudah ditetapkan. Versi ini masih '
                . $versi['status'] . ' — sunting saja langsung.'
            );
        }

        $entityType = trim((string) ($data['entity_type'] ?? ''));
        $entityId   = (int) ($data['entity_id'] ?? 0);
        $field      = trim((string) ($data['field'] ?? ''));
        $usulan     = $data['requested_value'] ?? null;
        $alasan     = trim((string) ($data['reason'] ?? ''));

        if (! $this->bolehKoreksi($entityType, $field)) {
            throw new RuntimeException(
                'Kolom "' . $field . '" tidak boleh dikoreksi. Hierarki, tanggal berlaku, '
                . 'serta penambahan/penghapusan baris mengubah bentuk dokumen — '
                . 'jalannya menerbitkan versi baru.'
            );
        }

        if ($alasan === '') {
            throw new RuntimeException('Alasan koreksi wajib diisi.');
        }

        // --- syarat tambahan untuk koreksi NILAI ---
        if ($this->kelasKolom($entityType, $field) === self::KELAS_NILAI) {
            if ($this->kosong($data['dasar'] ?? null) === null) {
                throw new RuntimeException(
                    'Koreksi nilai (satuan, baseline, target) WAJIB menyertakan dasar tertulis — '
                    . 'nota dinas, berita acara, atau surat yang menerangkan kekeliruannya. '
                    . 'Alasan saja tidak cukup untuk mengubah angka pada dokumen resmi.'
                );
            }

            $dipakai = $this->dipakaiLakip($versiId);

            if ($dipakai > 0) {
                throw new RuntimeException(
                    'Versi ini sudah dipakai ' . $dipakai . ' snapshot LAKIP sebagai sumber, '
                    . 'sehingga angkanya sudah masuk laporan. Mengoreksinya sekarang akan '
                    . 'membuat arsip dan laporan menyatakan angka yang berbeda. '
                    . 'Untuk perubahan ini, terbitkan versi baru.'
                );
            }
        }

        $this->pastikanMilikVersi($entityType, $entityId, $versiId);

        $lama = $entityType === 'dokumen_versi'
            ? (string) ($versi[$field] ?? '')
            : (string) $this->nilaiSekarang($entityType, $entityId, $field);

        $usulan = $usulan === null ? '' : trim((string) $usulan);

        if ($usulan === $lama) {
            throw new RuntimeException('Nilai usulan sama dengan nilai sekarang.');
        }

        // Satu kolom hanya boleh punya satu permintaan menggantung, supaya dua
        // usulan berbeda tidak saling menimpa saat disetujui berurutan.
        $bentrok = $this->db->table('version_correction_requests')
            ->where('version_id', $versiId)->where('entity_type', $entityType)
            ->where('entity_id', $entityId)->where('field', $field)
            ->where('status', self::STATUS_PENDING)
            ->countAllResults();

        if ($bentrok > 0) {
            throw new RuntimeException('Sudah ada permintaan koreksi menggantung untuk kolom ini.');
        }

        $this->db->table('version_correction_requests')->insert([
            'version_id'      => $versiId,
            'entity_type'     => $entityType,
            'entity_id'       => $entityId,
            'field'           => $field,
            'old_value'       => $lama,
            'requested_value' => $usulan,
            'reason'          => $alasan,
            'dasar'           => $this->kosong($data['dasar'] ?? null),
            'status'          => self::STATUS_PENDING,
            'requested_by'    => $userId,
        ]);

        $id = (int) $this->db->insertID();

        $this->audit->catat($versiId, VersionAuditService::AKSI_CORRECTION_REQUESTED, [
            'entitas'    => $entityType,
            'entitas_id' => $entityId,
            'ringkasan'  => 'Koreksi diajukan pada kolom "' . $field . '"',
            'sebelum'    => [$field => $lama],
            'sesudah'    => [$field => $usulan],
            'alasan'     => $alasan,
            'dasar'      => $data['dasar'] ?? null,
            'oleh'       => $userId,
        ]);

        return $id;
    }

    /* =========================================================
     * KEPUTUSAN
     * =======================================================*/

    /**
     * Setujui dan terapkan koreksi (§21).
     *
     * Diterapkan ke ARSIP dan — bila baris arsipnya masih menunjuk baris live
     * yang ada — juga ke tabel LIVE. Tanpa yang kedua, salah ketik akan tetap
     * terbaca salah di seluruh halaman berjalan, dan koreksinya jadi tidak ada
     * gunanya bagi pembaca.
     */
    public function setujui(int $id, ?int $userId = null): array
    {
        return $this->dalamTransaksi(function () use ($id, $userId) {
            $k = $this->ambil($id);

            if ($k === null) {
                throw new RuntimeException('Permintaan koreksi tidak ditemukan.');
            }

            if ($k['status'] !== self::STATUS_PENDING) {
                throw new RuntimeException(
                    'Permintaan ini sudah tidak menunggu keputusan (status: ' . $k['status'] . ').'
                );
            }

            if (! $this->bolehKoreksi($k['entity_type'], $k['field'])) {
                // Daftar putih bisa saja menyempit setelah permintaan dibuat;
                // yang berlaku adalah aturan SAAT DITERAPKAN.
                throw new RuntimeException('Kolom ini sudah tidak termasuk yang boleh dikoreksi.');
            }

            // Diperiksa ULANG di sini, bukan hanya saat pengajuan: sebuah LAKIP
            // bisa saja menarik versi ini selagi permintaan menunggu keputusan.
            if ($this->kelasKolom($k['entity_type'], $k['field']) === self::KELAS_NILAI) {
                $dipakai = $this->dipakaiLakip((int) $k['version_id']);

                if ($dipakai > 0) {
                    throw new RuntimeException(
                        'Sejak permintaan ini diajukan, versi tersebut sudah dipakai '
                        . $dipakai . ' snapshot LAKIP. Koreksi nilai tidak bisa lagi diterapkan — '
                        . 'terbitkan versi baru.'
                    );
                }
            }

            $now = date('Y-m-d H:i:s');

            // --- terapkan ke arsip / kepala versi ---
            if ($k['entity_type'] === 'dokumen_versi') {
                $this->versi->perbarui((int) $k['version_id'], [$k['field'] => $k['requested_value']]);
                $keLive = false;
            } else {
                $set = [$k['field'] => $k['requested_value']];

                // Satuan disimpan sebagai id master ATAU teks bebas; arsip juga
                // membekukan namanya. Tanpa menyegarkan `satuan_nama`, arsip akan
                // menampilkan satuan lama walau nilainya sudah dikoreksi.
                if ($k['field'] === 'satuan') {
                    $set['satuan_nama'] = $this->namaSatuan((string) $k['requested_value']);
                }

                $this->db->table($k['entity_type'])->where('id', (int) $k['entity_id'])
                    ->update($set);

                $keLive = $this->terapkanKeLive($k);
            }

            $this->db->table('version_correction_requests')->where('id', $id)->update([
                'status'      => self::STATUS_APPROVED,
                'reviewed_by' => $userId,
                'reviewed_at' => $now,
                'applied_at'  => $now,
            ]);

            $this->audit->catat((int) $k['version_id'], VersionAuditService::AKSI_CORRECTION_APPROVED, [
                'entitas'    => $k['entity_type'],
                'entitas_id' => (int) $k['entity_id'],
                'ringkasan'  => 'Koreksi disetujui & diterapkan pada kolom "' . $k['field'] . '"'
                    . ($keLive ? ' (arsip + data berjalan)' : ' (arsip)'),
                'sebelum'    => [$k['field'] => $k['old_value']],
                'sesudah'    => [$k['field'] => $k['requested_value']],
                'alasan'     => $k['reason'],
                'dasar'      => $k['dasar'],
                'oleh'       => $userId,
            ]);

            return ['id' => $id, 'ke_live' => $keLive];
        }, 'penerapan koreksi');
    }

    public function kembalikan(int $id, string $catatan, ?int $userId = null): void
    {
        $catatan = trim($catatan);

        if ($catatan === '') {
            throw new RuntimeException('Catatan pengembalian wajib diisi.');
        }

        $k = $this->ambil($id);

        if ($k === null || $k['status'] !== self::STATUS_PENDING) {
            throw new RuntimeException('Permintaan koreksi tidak dalam keadaan bisa dikembalikan.');
        }

        $this->db->table('version_correction_requests')->where('id', $id)->update([
            'status'      => self::STATUS_RETURNED,
            'reviewed_by' => $userId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'review_note' => $catatan,
        ]);

        $this->audit->catat((int) $k['version_id'], VersionAuditService::AKSI_CORRECTION_RETURNED, [
            'entitas'    => $k['entity_type'],
            'entitas_id' => (int) $k['entity_id'],
            'ringkasan'  => 'Koreksi dikembalikan pada kolom "' . $k['field'] . '"',
            'catatan'    => $catatan,
            'oleh'       => $userId,
        ]);
    }

    /** Pengaju menarik permintaannya sendiri selama belum diputus. */
    public function batalkan(int $id, ?int $userId = null): void
    {
        $k = $this->ambil($id);

        if ($k === null || $k['status'] !== self::STATUS_PENDING) {
            throw new RuntimeException('Permintaan koreksi tidak dalam keadaan bisa dibatalkan.');
        }

        $this->db->table('version_correction_requests')->where('id', $id)->update([
            'status'      => self::STATUS_CANCELLED,
            'reviewed_by' => $userId,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /* =========================================================
     * INTERNAL
     * =======================================================*/

    /**
     * Terapkan koreksi ke baris LIVE yang bersangkutan, bila masih ada.
     *
     * Sengaja diam-diam melewati bila baris live sudah tidak ada atau sudah
     * dipensiunkan: arsip tetap terkoreksi, dan itu yang jadi rekaman resmi.
     */
    private function terapkanKeLive(array $k): bool
    {
        // Target ditelusuri lewat indikator induknya + tahun, karena arsip
        // target memang tidak menyimpan id target live.
        if (isset($this->tabelTarget()[$k['entity_type']])) {
            return $this->terapkanTargetKeLive($k);
        }

        $peta = $this->petaLive()[$k['entity_type']] ?? null;

        if ($peta === null || ! isset($peta['kolom'][$k['field']])) {
            return false;
        }

        $arsip = $this->db->table($k['entity_type'])->select($peta['sumber'])
            ->where('id', (int) $k['entity_id'])->get()->getRowArray();

        $liveId = (int) ($arsip[$peta['sumber']] ?? 0);

        if ($liveId <= 0) {
            return false;
        }

        $ada = $this->db->table($peta['tabel'])->where('id', $liveId)->countAllResults() > 0;

        if (! $ada) {
            return false;
        }

        $this->db->table($peta['tabel'])->where('id', $liveId)
            ->update([$peta['kolom'][$k['field']] => $k['requested_value']]);

        return true;
    }

    /**
     * Terapkan koreksi target ke tabel live.
     *
     * Jalurnya: arsip target → indikator arsip → `source_indikator_id`
     * (indikator live) → baris target live untuk tahun yang sama.
     */
    private function terapkanTargetKeLive(array $k): bool
    {
        $rpjmd = $k['entity_type'] === 'rpjmd_versi_target';

        $baris = $this->db->table($k['entity_type'] . ' t')
            ->select('t.tahun, i.source_indikator_id')
            ->join(($rpjmd ? 'rpjmd_versi_indikator_sasaran' : 'renstra_versi_indikator_sasaran') . ' i',
                'i.id = t.versi_indikator_id')
            ->where('t.id', (int) $k['entity_id'])
            ->get()->getRowArray();

        if ($baris === null || empty($baris['source_indikator_id'])) {
            return false;
        }

        [$tabel, $fk, $kolom] = $rpjmd
            ? ['rpjmd_target', 'indikator_sasaran_id', 'target_tahunan']
            : ['renstra_target', 'renstra_indikator_id', 'target'];

        $live = $this->db->table($tabel)->select('id')
            ->where($fk, (int) $baris['source_indikator_id'])
            ->where('tahun', (int) $baris['tahun'])
            ->get()->getRowArray();

        if ($live === null) {
            return false;
        }

        // Kolom target di tabel live NOT NULL — kosong disimpan sebagai ''.
        $this->db->table($tabel)->where('id', (int) $live['id'])
            ->update([$kolom => (string) ($k['requested_value'] ?? '')]);

        return true;
    }

    /**
     * Pastikan baris yang dikoreksi memang milik versi tersebut.
     *
     * Penjaga anti-IDOR: entity_id datang dari form dan tidak boleh dipercaya.
     * Tanpa ini, seseorang bisa mengoreksi arsip versi OPD lain lewat id tebakan.
     */
    private function pastikanMilikVersi(string $entityType, int $entityId, int $versiId): void
    {
        if ($entityType === 'dokumen_versi') {
            if ($entityId !== $versiId) {
                throw new RuntimeException('Entitas koreksi bukan milik versi ini.');
            }

            return;
        }

        // Tabel target tidak punya `version_id` sendiri — kepemilikannya
        // ditelusuri lewat indikator induknya.
        $target = $this->tabelTarget()[$entityType] ?? null;

        if ($target !== null) {
            $ada = $this->db->table($entityType . ' t')
                ->join($target['induk'] . ' i', 'i.id = t.' . $target['fk'])
                ->where('t.id', $entityId)->where('i.version_id', $versiId)
                ->countAllResults() > 0;

            if (! $ada) {
                throw new RuntimeException('Entitas koreksi bukan milik versi ini.');
            }

            return;
        }

        $ada = $this->db->table($entityType)
            ->where('id', $entityId)->where('version_id', $versiId)
            ->countAllResults() > 0;

        if (! $ada) {
            throw new RuntimeException('Entitas koreksi bukan milik versi ini.');
        }
    }

    private function kosong($nilai): ?string
    {
        $nilai = trim((string) $nilai);

        return $nilai === '' ? null : $nilai;
    }

    /**
     * Terjemahkan satuan (id master atau teks bebas) menjadi namanya.
     *
     * Pola yang sama dengan ArsipVersiModel::namaSatuan(); disalin ke sini
     * karena service ini tidak memegang model arsip mana pun secara langsung.
     */
    private function namaSatuan(?string $satuan): ?string
    {
        $satuan = trim((string) $satuan);

        if ($satuan === '') {
            return null;
        }

        if (! preg_match('/^[0-9]+$/', $satuan)) {
            return $satuan;
        }

        $row = $this->db->table('satuan')->select('satuan')
            ->where('id', (int) $satuan)->get()->getRowArray();

        return $row['satuan'] ?? $satuan;
    }
}
