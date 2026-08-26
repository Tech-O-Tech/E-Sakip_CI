<?php

namespace App\Services\Version;

use App\Models\Concerns\TransaksiAman;
use App\Models\DokumenVersiModel;
use CodeIgniter\Database\ConnectionInterface;
use RuntimeException;

/**
 * Mesin status versi: draft -> pending_approval -> published (§17, §19).
 *
 * =====================================================================
 *                    ┌─────────┐
 *        buat ──────►│  draft  │◄──── kembalikan (catatan WAJIB)
 *                    └────┬────┘
 *                 ajukan  │  (pemilik dokumen)
 *                         ▼
 *                   ┌───────────┐
 *                   │ pending   │  pemilik TIDAK boleh menyunting
 *                   └─────┬─────┘
 *        ┌────────────────┴────────────────┐
 * setujui│ (Admin Kabupaten)               │ kembalikan
 *        ▼                                 ▼
 *  ┌────────────┐                     ke draft lagi
 *  │ published  │  immutable (§16)
 *  └────────────┘
 * =====================================================================
 *
 * CATATAN PENTING — STATUS BUKAN PENENTU KEBERLAKUAN.
 * Tidak ada status 'berlaku'/'superseded' yang perlu dibalik oleh cron ketika
 * tanggal versi masa depan tiba. Yang menentukan CURRENT/HISTORICAL/UPCOMING
 * adalah interval [effective_from, effective_to) — lihat VersionResolver::badge().
 * Ini konsekuensi langsung §2.6 dan §2.7, dan alasan §7 melarang menyimpan
 * ketiga label itu sebagai status manual.
 *
 * Kelas ini TIDAK memeriksa permission. Otorisasi ada di controller/trait
 * pemanggil (§55), supaya service yang sama bisa dipakai perintah CLI dan
 * migrasi data yang memang tidak punya sesi.
 */
class VersionApprovalService
{
    use TransaksiAman;

    private ConnectionInterface $db;

    private DokumenVersiModel $versi;

    private VersionTimelineService $timeline;

    private VersionAuditService $audit;

    private ArsipRegistry $registry;

    public function __construct(
        ?ConnectionInterface $db = null,
        ?DokumenVersiModel $versi = null,
        ?VersionTimelineService $timeline = null,
        ?VersionAuditService $audit = null,
        ?ArsipRegistry $registry = null
    ) {
        $this->db       = $db ?? db_connect();
        $this->versi    = $versi ?? new DokumenVersiModel($this->db);
        $this->audit    = $audit ?? new VersionAuditService($this->db);
        $this->timeline = $timeline ?? new VersionTimelineService($this->db, $this->versi, $this->audit);
        $this->registry = $registry ?? new ArsipRegistry($this->db);
    }

    /* =========================================================
     * TRANSISI
     * =======================================================*/

    /**
     * draft -> pending_approval.
     *
     * Validasi timeline dijalankan DI SINI, bukan hanya saat menyetujui:
     * memaksa Admin Kabupaten menemukan tanggal yang bentrok setelah antre
     * verifikasi hanya membuang waktu dua pihak.
     */
    public function ajukan(int $versiId, ?int $userId = null): array
    {
        return $this->dalamTransaksi(function () use ($versiId, $userId) {
            $baris = $this->wajibAda($versiId);

            if ($baris['status'] !== DokumenVersiModel::STATUS_DRAFT) {
                throw new RuntimeException(
                    'Hanya versi berstatus draft yang bisa diajukan. Status sekarang: ' . $baris['status'] . '.'
                );
            }

            $galat = $this->timeline->validasi($versiId);

            if ($galat !== []) {
                throw new RuntimeException('Versi belum bisa diajukan: ' . implode(' ', $galat));
            }

            $this->versi->perbarui($versiId, [
                'status'       => DokumenVersiModel::STATUS_PENDING,
                'submitted_by' => $userId,
                'submitted_at' => date('Y-m-d H:i:s'),
            ]);

            // Pengajuan ULANG setelah dikembalikan diberi aksi berbeda supaya
            // riwayatnya bercerita: "diajukan, dikembalikan, diajukan lagi".
            $pernahKembali = $this->pernahDikembalikan($versiId);

            $this->audit->catat($versiId, $pernahKembali
                ? VersionAuditService::AKSI_RESUBMITTED
                : VersionAuditService::AKSI_SUBMITTED, [
                    'dari_status'    => DokumenVersiModel::STATUS_DRAFT,
                    'ke_status'      => DokumenVersiModel::STATUS_PENDING,
                    'ringkasan'      => 'Diajukan untuk ditetapkan: ' . ($baris['label'] ?? ''),
                    'effective_from' => $baris['effective_from'] ?? null,
                    'oleh'           => $userId,
                ]);

            return $this->wajibAda($versiId);
        }, 'pengajuan versi');
    }

    /**
     * pending_approval -> draft, dengan catatan WAJIB (§17).
     *
     * Catatan kosong ditolak, bukan disimpan kosong: OPD yang menerima
     * pengembalian tanpa alasan akan mengajukan hal yang sama lagi.
     */
    public function kembalikan(int $versiId, string $catatan, ?int $userId = null): array
    {
        $catatan = trim($catatan);

        if ($catatan === '') {
            throw new RuntimeException('Catatan pengembalian wajib diisi.');
        }

        return $this->dalamTransaksi(function () use ($versiId, $catatan, $userId) {
            $baris = $this->wajibAda($versiId);

            if ($baris['status'] !== DokumenVersiModel::STATUS_PENDING) {
                throw new RuntimeException(
                    'Hanya pengajuan yang menunggu verifikasi yang bisa dikembalikan. Status sekarang: '
                    . $baris['status'] . '.'
                );
            }

            $this->versi->perbarui($versiId, [
                'status'       => DokumenVersiModel::STATUS_DRAFT,
                'submitted_by' => null,
                'submitted_at' => null,
            ]);

            $this->audit->catat($versiId, VersionAuditService::AKSI_RETURNED, [
                'dari_status' => DokumenVersiModel::STATUS_PENDING,
                'ke_status'   => DokumenVersiModel::STATUS_DRAFT,
                'ringkasan'   => 'Dikembalikan untuk diperbaiki',
                'catatan'     => $catatan,
                'oleh'        => $userId,
            ]);

            return $this->wajibAda($versiId);
        }, 'pengembalian versi');
    }

    /**
     * pending_approval -> draft, DITARIK OLEH PENGAJU SENDIRI.
     *
     * Berbeda dari kembalikan(): itu keputusan verifikator dan wajib bercatatan,
     * karena penyusun perlu tahu apa yang harus diperbaiki. Menarik pengajuan
     * sendiri tidak butuh itu — tidak ada pihak lain yang perlu diberi tahu
     * alasannya, dan mewajibkannya hanya menghalangi orang membetulkan
     * pekerjaannya sendiri.
     */
    public function tarikPengajuan(int $versiId, ?int $userId = null): array
    {
        return $this->dalamTransaksi(function () use ($versiId, $userId) {
            $baris = $this->wajibAda($versiId);

            if ($baris['status'] !== DokumenVersiModel::STATUS_PENDING) {
                throw new RuntimeException(
                    'Hanya permohonan yang sedang menunggu verifikasi yang bisa ditarik. '
                    . 'Status sekarang: ' . $baris['status'] . '.'
                );
            }

            $this->versi->perbarui($versiId, [
                'status'       => DokumenVersiModel::STATUS_DRAFT,
                'submitted_by' => null,
                'submitted_at' => null,
            ]);

            $this->audit->catat($versiId, VersionAuditService::AKSI_RETURNED, [
                'dari_status' => DokumenVersiModel::STATUS_PENDING,
                'ke_status'   => DokumenVersiModel::STATUS_DRAFT,
                'ringkasan'   => 'Permohonan ditarik oleh penyusun untuk diperbaiki',
                'oleh'        => $userId,
            ]);

            return $this->wajibAda($versiId);
        }, 'penarikan permohonan');
    }

    /**
     * pending_approval -> published, lalu timeline disusun ulang (§19).
     *
     * @param bool $langsungDariDraft izinkan publish tanpa antre verifikasi.
     *                                Dipakai dokumen tingkat Kabupaten (§18)
     *                                yang pembuat dan penetapnya memang sama.
     */
    public function setujui(int $versiId, ?int $userId = null, bool $langsungDariDraft = false): array
    {
        return $this->dalamTransaksi(function () use ($versiId, $userId, $langsungDariDraft) {
            $baris = $this->wajibAda($versiId);

            $bolehDari = $langsungDariDraft
                ? [DokumenVersiModel::STATUS_PENDING, DokumenVersiModel::STATUS_DRAFT]
                : [DokumenVersiModel::STATUS_PENDING];

            if (! in_array($baris['status'], $bolehDari, true)) {
                throw new RuntimeException(
                    'Versi tidak dalam keadaan yang bisa ditetapkan. Status sekarang: ' . $baris['status'] . '.'
                );
            }

            $galat = $this->timeline->validasi($versiId);

            if ($galat !== []) {
                throw new RuntimeException('Versi tidak lolos validasi: ' . implode(' ', $galat));
            }

            $this->versi->perbarui($versiId, [
                'approved_by' => $userId,
                'approved_at' => date('Y-m-d H:i:s'),
            ]);

            // terbitkan() yang menulis status published + menyusun timeline,
            // dengan urutan "lepas dulu, baru klaim" yang wajib itu.
            $perubahan = $this->timeline->terbitkan($versiId);

            // Isi arsip HANYA diterapkan ke tabel live bila versi ini menjadi
            // UJUNG timeline — sesudah terbitkan(), versi terbaru adalah satu-
            // satunya yang effective_to-nya NULL. Penyisipan retrospektif di
            // tengah timeline (§60) selalu diberi effective_to oleh penerusnya,
            // dan memang tidak boleh diterapkan: menimpa live dengan isi lampau
            // berarti memensiunkan baris yang justru dibawa versi lebih baru,
            // sementara resolver tetap menunjuk versi baru itu sebagai CURRENT.
            // Arsip versi retrospektif tetap utuh dan tetap terbaca lewat
            // pemilih versi; hanya penerapan ke live yang dilewati.
            $penerapan   = null;
            $barisSesudah = $this->wajibAda($versiId); // effective_to baru ditulis terbitkan()

            if ($barisSesudah['effective_to'] === null) {
                // Urutannya penting: penerapan memakai effective_from untuk
                // menentukan sampai tahun berapa baris yang hilang dipensiunkan.
                $penerapan = $this->terapkanArsip($versiId, $baris);
            }

            $this->audit->catat($versiId, VersionAuditService::AKSI_PUBLISHED, [
                'dari_status'    => $baris['status'],
                'ke_status'      => DokumenVersiModel::STATUS_PUBLISHED,
                'ringkasan'      => 'Ditetapkan berlaku mulai ' . ($baris['effective_from'] ?? '-'),
                'sesudah'        => ['perubahan_timeline' => $perubahan, 'penerapan' => $penerapan],
                'effective_from' => $baris['effective_from'] ?? null,
                'oleh'           => $userId,
            ]);

            return $this->wajibAda($versiId);
        }, 'penetapan versi');
    }

    /**
     * draft/pending -> cancelled.
     *
     * Barisnya TIDAK dihapus (§2.4, §81): draft yang dibuang tetap menyisakan
     * jejak bahwa pernah ada usulan dan mengapa ditarik.
     *
     * Versi published TIDAK bisa dibatalkan lewat jalur ini — menariknya berarti
     * mengubah sejarah, dan itu keputusan yang butuh penanganan tersendiri.
     */
    public function batalkan(int $versiId, string $alasan, ?int $userId = null): array
    {
        $alasan = trim($alasan);

        if ($alasan === '') {
            throw new RuntimeException('Alasan pembatalan wajib diisi.');
        }

        return $this->dalamTransaksi(function () use ($versiId, $alasan, $userId) {
            $baris = $this->wajibAda($versiId);

            if ($baris['status'] === DokumenVersiModel::STATUS_PUBLISHED) {
                throw new RuntimeException(
                    'Versi yang sudah ditetapkan tidak bisa dibatalkan. '
                    . 'Untuk perubahan substantif, buat versi baru; untuk typo, ajukan koreksi.'
                );
            }

            if ($baris['status'] === DokumenVersiModel::STATUS_CANCELLED) {
                return $baris;
            }

            $this->versi->perbarui($versiId, [
                'status'       => DokumenVersiModel::STATUS_CANCELLED,
                'cancelled_by' => $userId,
                'cancelled_at' => date('Y-m-d H:i:s'),
            ]);

            $this->audit->catat($versiId, VersionAuditService::AKSI_CANCELLED, [
                'dari_status' => $baris['status'],
                'ke_status'   => DokumenVersiModel::STATUS_CANCELLED,
                'ringkasan'   => 'Versi dibatalkan',
                'alasan'      => $alasan,
                'oleh'        => $userId,
            ]);

            return $this->wajibAda($versiId);
        }, 'pembatalan versi');
    }

    /* =========================================================
     * PEMERIKSAAN KEADAAN — dipakai view untuk memadamkan tombol
     * =======================================================*/

    /** Versi published immutable terhadap sunting normal (§16). */
    public function bolehSunting(array $versi): bool
    {
        return ($versi['status'] ?? '') === DokumenVersiModel::STATUS_DRAFT;
    }

    public function bolehAjukan(array $versi): bool
    {
        return ($versi['status'] ?? '') === DokumenVersiModel::STATUS_DRAFT;
    }

    public function bolehVerifikasi(array $versi): bool
    {
        return ($versi['status'] ?? '') === DokumenVersiModel::STATUS_PENDING;
    }

    public function bolehKoreksi(array $versi): bool
    {
        return ($versi['status'] ?? '') === DokumenVersiModel::STATUS_PUBLISHED;
    }

    /**
     * Boleh mengubah keterangan versi (label, tanggal, dasar, alasan)?
     *
     * Hanya draft. Setelah diajukan, isinya sedang dibaca verifikator dan
     * mengubahnya di tengah jalan membuat keputusan mereka menilai sesuatu
     * yang sudah berbeda.
     */
    public function bolehUbahKeterangan(array $versi): bool
    {
        return ($versi['status'] ?? '') === DokumenVersiModel::STATUS_DRAFT;
    }

    /**
     * Baris ini dibuat MIGRASI, bukan manusia?
     *
     * `created_by` kosong adalah penandanya: setiap versi yang dibuat lewat
     * aplikasi selalu membawa id pembuatnya dari sesi, sedangkan INSERT baseline
     * pada db/update_2026-08-20 tidak mengisinya sama sekali.
     */
    public function adalahBaselineOtomatis(array $versi): bool
    {
        return (int) ($versi['version_no'] ?? 0) === 1
            && ($versi['created_by'] ?? null) === null;
    }

    /**
     * Boleh memperbaiki tanggal berlaku BASELINE otomatis?
     *
     * =================================================================
     * MENGAPA INI BUKAN KELONGGARAN
     *
     * Tanggal baseline (1 Januari awal periode) adalah TEBAKAN SISTEM, bukan
     * keputusan yang pernah ditetapkan manusia. Tidak ada sejarah di sana untuk
     * dilindungi — yang ada justru penghalang: ia menempati tanggal pertama
     * periode, sehingga versi historis yang benar-benar berlaku sejak awal
     * periode tidak punya tempat, dan §6 menolak keduanya berbagi tanggal.
     *
     * Versi yang tanggalnya DIPILIH MANUSIA tetap terkunci. Menggeser masa
     * berlaku dokumen resmi adalah perubahan substantif, dan §20 menegaskan
     * `effective_from` bukan field yang boleh dikoreksi — jalannya versi baru.
     * =================================================================
     */
    public function bolehPerbaikiTanggalBaseline(array $versi): bool
    {
        return ($versi['status'] ?? '') === DokumenVersiModel::STATUS_PUBLISHED
            && $this->adalahBaselineOtomatis($versi);
    }

    /**
     * Boleh mengisi baseline kosong dengan salinan kondisi berjalan?
     *
     * §43 memaksudkan baseline sebagai rekaman "data existing sebagai Version 1
     * Published". SQL murni tidak bisa mewujudkannya — pembekuan menuntut
     * terjemahan satuan dan penomoran urut yang sama persis dengan aplikasi,
     * dan menebaknya lewat SQL berisiko menghasilkan arsip yang berbeda dari
     * yang dihasilkan kode.
     *
     * Karena itu baselinenya lahir kosong, dan pengisiannya dilakukan dari sini
     * — sekali saja, selama isinya memang masih kosong.
     */
    public function bolehIsiBaseline(array $versi, bool $arsipKosong): bool
    {
        // Berlaku untuk SETIAP versi published yang arsipnya kosong, bukan hanya
        // baseline migrasi. Alasannya: arsip kosong tidak merekam apa pun, jadi
        // mengisinya dari kondisi berjalan hanya bisa membuatnya lebih benar —
        // tidak ada sejarah yang tertimpa. Tanpa jalan ini, versi yang terlanjur
        // ditetapkan dalam keadaan kosong akan terkunci selamanya tanpa isi.
        return $arsipKosong
            && ($versi['status'] ?? '') === DokumenVersiModel::STATUS_PUBLISHED;
    }

    public function bolehBatalkan(array $versi): bool
    {
        return in_array(
            $versi['status'] ?? '',
            [DokumenVersiModel::STATUS_DRAFT, DokumenVersiModel::STATUS_PENDING],
            true
        );
    }

    /* =========================================================
     * INTERNAL
     * =======================================================*/

    /**
     * Terapkan isi arsip ke tabel live (§3.2: live = versi yang sedang berlaku).
     *
     * Modul yang masih memakai arsip lamanya sendiri (IKU lewat IkuRevisiModel,
     * LAKIP lewat LakipSnapshotModel) mengembalikan null dari registry dan
     * dilewati di sini — penerapannya diurus model masing-masing (§42).
     *
     * Batas pensiun memakai TAHUN dari `effective_from`: baris yang tidak lagi
     * tercantum berlaku sampai tahun sebelum versi ini mulai. Untuk versi
     * retrospektif angkanya otomatis ikut mundur, karena diambil dari tanggal
     * berlaku dan bukan dari tanggal hari ini.
     *
     * @return array<string,int>|null
     */
    private function terapkanArsip(int $versiId, array $baris): ?array
    {
        $scope = VersionScope::dariBaris($baris);
        $arsip = $this->registry->untuk($scope->modul());

        if ($arsip === null || ! $arsip->siap()) {
            return null;
        }

        $mulai = (string) ($baris['effective_from'] ?? '');
        $tahun = $mulai !== '' && strtotime($mulai) !== false
            ? (int) date('Y', strtotime($mulai))
            : $scope->periodeMulai();

        return $arsip->terapkanKeLive($versiId, $scope, $tahun);
    }

    private function wajibAda(int $versiId): array
    {
        $baris = $this->versi->ambil($versiId);

        if ($baris === null) {
            throw new RuntimeException('Versi tidak ditemukan: ' . $versiId);
        }

        return $baris;
    }

    private function pernahDikembalikan(int $versiId): bool
    {
        foreach ($this->audit->riwayat($versiId) as $r) {
            if (($r['aksi'] ?? '') === VersionAuditService::AKSI_RETURNED) {
                return true;
            }
        }

        return false;
    }
}
