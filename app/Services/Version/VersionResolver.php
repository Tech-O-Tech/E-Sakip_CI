<?php

namespace App\Services\Version;

use App\Models\DokumenVersiModel;
use CodeIgniter\Database\ConnectionInterface;

/**
 * Penentu "versi mana yang berlaku pada tanggal tertentu" (§26).
 *
 * Satu-satunya tempat aturan resolusi ditulis. Empat modul memakainya, jadi
 * memperbaikinya di sini memperbaiki semuanya sekaligus.
 *
 * ---------------------------------------------------------------------
 * ATURAN
 *
 *   status = published
 *   effective_from <= tanggal
 *   (effective_to IS NULL OR effective_to > tanggal)
 *
 * Draft & pending_approval TIDAK PERNAH lolos (§2.11, §4, §27, §81): dokumen
 * yang belum resmi tidak boleh menjadi sumber LAKIP. cancelled juga tidak.
 *
 * Interval SETENGAH TERBUKA — lihat catatan di
 * DokumenVersiModel::kandidatEfektif() untuk alasannya.
 *
 * Bila kandidatnya lebih dari satu, melempar VersionConflictException alih-alih
 * memilih. Ini disengaja dan diuji.
 * ---------------------------------------------------------------------
 */
class VersionResolver
{
    private DokumenVersiModel $versi;

    public function __construct(?ConnectionInterface $db = null, ?DokumenVersiModel $versi = null)
    {
        $this->versi = $versi ?? new DokumenVersiModel($db);
    }

    public function model(): DokumenVersiModel
    {
        return $this->versi;
    }

    /**
     * Versi yang berlaku pada $tanggal, atau null bila memang belum ada.
     *
     * @param string|null $tanggal Y-m-d; null = hari ini
     *
     * @throws VersionConflictException bila >1 versi berlaku bersamaan
     */
    public function getEffectiveVersion(VersionScope $scope, ?string $tanggal = null): ?array
    {
        $tanggal   = $this->normalkanTanggal($tanggal);
        $kandidat  = $this->versi->kandidatEfektif($scope, $tanggal);

        if ($kandidat === []) {
            return null;
        }

        if (count($kandidat) > 1) {
            throw new VersionConflictException($scope, $tanggal, $kandidat);
        }

        return $kandidat[0];
    }

    /**
     * Rekomendasi versi sumber untuk LAKIP tahun $tahun (§26).
     *
     * Tanggal rujukan bakunya 31 Desember tahun laporan: LAKIP menilai kinerja
     * SATU TAHUN PENUH, jadi yang relevan adalah dokumen yang berlaku di ujung
     * tahun itu — bukan yang berlaku saat LAKIP-nya disusun (bisa tahun
     * berikutnya) dan bukan pula yang berlaku di awal tahun.
     *
     * Dipakai lintas periode: LAKIP 2025 tidak tahu apakah RPJMD yang
     * memayunginya berperiode 2020-2024 atau 2025-2029 (§28).
     */
    public function rekomendasiUntukTahun(
        string $modul,
        string $scopeNama,
        ?int $opdId,
        int $tahun,
        ?string $tanggalRujukan = null
    ): ?array {
        $tanggal  = $tanggalRujukan ?? self::akhirTahun($tahun);
        $kandidat = $this->versi->kandidatEfektifLintasPeriode(
            $modul,
            $scopeNama,
            $opdId,
            $tahun,
            $tanggal
        );

        if ($kandidat === []) {
            return null;
        }

        if (count($kandidat) > 1) {
            // Lingkup dibuat seadanya hanya untuk pesan galat; periode diambil
            // dari kandidat pertama supaya labelnya masuk akal bagi operator.
            $scope = VersionScope::dariBaris($kandidat[0]);

            throw new VersionConflictException($scope, $tanggal, $kandidat);
        }

        return $kandidat[0];
    }

    /**
     * Daftar versi yang boleh muncul di dropdown sumber LAKIP (§27).
     *
     * Hanya published. Draft sengaja tidak ditampilkan sama sekali — bukan
     * ditampilkan lalu ditolak saat disimpan, karena menawarkan pilihan yang
     * pasti ditolak hanya membuang waktu operator.
     */
    public function pilihanSumber(
        string $modul,
        string $scopeNama,
        ?int $opdId,
        int $tahun
    ): array {
        $rows = $this->versi->publishedUntukTahun($modul, $scopeNama, $opdId, $tahun);

        if ($rows === []) {
            return [];
        }

        $rekomendasi = null;

        try {
            $rekomendasi = $this->rekomendasiUntukTahun($modul, $scopeNama, $opdId, $tahun);
        } catch (VersionConflictException $e) {
            // Dropdown tetap boleh tampil walau timeline-nya bermasalah; yang
            // hilang hanya tanda "rekomendasi". Operator akan melihat peringatan
            // konflik dari halaman pemanggil.
            $rekomendasi = null;
        }

        $idRekomendasi = $rekomendasi['id'] ?? null;

        foreach ($rows as &$r) {
            $r['rekomendasi'] = ((int) $r['id'] === (int) $idRekomendasi);
            $r['badge']       = $this->badge($r);
        }
        unset($r);

        return $rows;
    }

    /**
     * True bila $versiId adalah rekomendasi untuk tahun tsb.
     *
     * Dipakai untuk menegakkan §27: memilih versi selain rekomendasi WAJIB
     * disertai alasan. Pemeriksaannya di server, bukan hanya di form.
     */
    public function apakahRekomendasi(
        int $versiId,
        string $modul,
        string $scopeNama,
        ?int $opdId,
        int $tahun
    ): bool {
        try {
            $rek = $this->rekomendasiUntukTahun($modul, $scopeNama, $opdId, $tahun);
        } catch (VersionConflictException $e) {
            return false;
        }

        return $rek !== null && (int) $rek['id'] === $versiId;
    }

    /* =========================================================
     * BADGE — DIHITUNG, TIDAK DISIMPAN (§7)
     * =======================================================*/

    /**
     * CURRENT / HISTORICAL / UPCOMING untuk versi published; selain itu
     * statusnya sendiri yang ditampilkan.
     *
     * §7 melarang menyimpan ketiganya sebagai status manual — kalau disimpan,
     * ia harus diperbarui setiap hari oleh sesuatu, dan sehari terlewat berarti
     * seluruh halaman berbohong.
     */
    public function badge(array $versi, ?string $hariIni = null): string
    {
        $status = (string) ($versi['status'] ?? '');

        if ($status !== DokumenVersiModel::STATUS_PUBLISHED) {
            return strtoupper($status === DokumenVersiModel::STATUS_PENDING ? 'MENUNGGU VERIFIKASI' : $status);
        }

        $hariIni = $this->normalkanTanggal($hariIni);
        $dari    = (string) ($versi['effective_from'] ?? '');
        $sampai  = $versi['effective_to'] ?? null;

        if ($dari !== '' && $dari > $hariIni) {
            return DokumenVersiModel::BADGE_UPCOMING;
        }

        if ($sampai !== null && $sampai !== '' && $sampai <= $hariIni) {
            return DokumenVersiModel::BADGE_HISTORICAL;
        }

        return DokumenVersiModel::BADGE_CURRENT;
    }

    /** Rentang berlaku dalam bentuk yang bisa dibaca manusia. */
    public function rentangTeks(array $versi): string
    {
        $dari   = (string) ($versi['effective_from'] ?? '');
        $sampai = $versi['effective_to'] ?? null;

        if ($dari === '') {
            return '-';
        }

        if ($sampai === null || $sampai === '') {
            return $this->tanggalId($dari) . ' — seterusnya';
        }

        // effective_to EKSKLUSIF: hari terakhir berlakunya adalah sehari sebelumnya.
        // Menampilkan tanggal eksklusif apa adanya akan membuat operator mengira
        // dua versi tumpang tindih satu hari.
        $terakhir = date('Y-m-d', strtotime($sampai . ' -1 day'));

        return $this->tanggalId($dari) . ' — ' . $this->tanggalId($terakhir);
    }

    /* =========================================================
     * BANTU
     * =======================================================*/

    public static function akhirTahun(int $tahun): string
    {
        return sprintf('%04d-12-31', $tahun);
    }

    public static function awalTahun(int $tahun): string
    {
        return sprintf('%04d-01-01', $tahun);
    }

    private function normalkanTanggal(?string $tanggal): string
    {
        if ($tanggal === null || trim($tanggal) === '') {
            return date('Y-m-d');
        }

        $ts = strtotime($tanggal);

        return $ts === false ? date('Y-m-d') : date('Y-m-d', $ts);
    }

    private function tanggalId(string $ymd): string
    {
        static $bulan = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
            7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
        ];

        $ts = strtotime($ymd);

        if ($ts === false) {
            return $ymd;
        }

        return (int) date('j', $ts) . ' ' . $bulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);
    }
}
