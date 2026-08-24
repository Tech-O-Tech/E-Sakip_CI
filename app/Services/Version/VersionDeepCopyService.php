<?php

namespace App\Services\Version;

use App\Models\Concerns\TransaksiAman;
use App\Models\DokumenVersiModel;
use CodeIgniter\Database\ConnectionInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Pembuat versi baru beserta isinya (§9, §10, §11).
 *
 * Tiga cara mengisi versi baru:
 *
 *   SUMBER_LIVE   salin kondisi tabel live saat ini  (perilaku bawaan)
 *   SUMBER_COPY   DEEP COPY dari versi lain          (§10)
 *   SUMBER_KOSONG mulai dari kosong                  (§9)
 *
 * =====================================================================
 * DEEP COPY, BUKAN BERBAGI BARIS
 *
 * §10 dan §81 sama-sama melarang "share mutable child rows antar version".
 * Salinan mendapat ID BARU di seluruh hierarki, sehingga menyunting V2 tidak
 * pernah menyentuh V1. Yang dibawa hanya lineage: `copied_from_id` per baris
 * dan `copied_from_version_id` pada kepalanya.
 *
 * `source_*_id` (penunjuk ke tabel live) ikut disalin dengan sengaja — tanpa
 * itu, menetapkan V2 akan MENYISIPKAN baris live kembar alih-alih memperbarui
 * baris yang sudah ada, dan Cascading/Renaksi yang menunjuk id lama akan
 * menggantung.
 *
 * Seluruhnya dalam satu transaksi: satu anak gagal disalin = seluruh versi
 * baru dibatalkan (§10, §52).
 * =====================================================================
 */
class VersionDeepCopyService
{
    use TransaksiAman;

    public const SUMBER_LIVE   = 'live';
    public const SUMBER_COPY   = 'copy';
    public const SUMBER_KOSONG = 'kosong';

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

    /**
     * Buat versi baru berstatus draft, lengkap dengan isinya.
     *
     * @param array{
     *     label?:string, effective_from?:string, sumber?:string,
     *     copied_from_version_id?:int,
     *     alasan_perubahan?:?string, dasar_perubahan?:?string,
     *     nomor_dasar?:?string, tanggal_dasar?:?string, catatan?:?string,
     *     created_by?:?int
     * } $opsi
     *
     * @return array{version_id:int, ringkasan:array, sumber:string}
     */
    public function buatVersi(VersionScope $scope, array $opsi = []): array
    {
        $sumber = $opsi['sumber'] ?? self::SUMBER_LIVE;

        if (! in_array($sumber, [self::SUMBER_LIVE, self::SUMBER_COPY, self::SUMBER_KOSONG], true)) {
            throw new InvalidArgumentException('Cara pengisian versi tidak dikenal: ' . $sumber);
        }

        $mulai = $this->normalkanTanggal($opsi['effective_from'] ?? null, $scope);
        $this->tolakDiLuarPeriode($scope, $mulai);

        $asal = null;

        if ($sumber === self::SUMBER_COPY) {
            $asal = $this->versiAsalSah($scope, (int) ($opsi['copied_from_version_id'] ?? 0));
        }

        return $this->dalamTransaksi(function () use ($scope, $opsi, $sumber, $mulai, $asal) {
            $arsip = $this->registry->untuk($scope->modul());

            if ($arsip === null) {
                throw new RuntimeException(
                    'Modul ' . $scope->modul() . ' memakai arsip lamanya sendiri; '
                    . 'pembuatan versinya tidak lewat service ini.'
                );
            }

            if (! $arsip->siap()) {
                throw new RuntimeException(
                    'Tabel arsip ' . $scope->modul() . ' belum terpasang. '
                    . 'Jalankan db/update_2026-08-20_versioning_dokumen.sql lebih dulu.'
                );
            }

            $nomor    = $this->versi->nomorBerikutnya($scope);
            $versiId  = $this->versi->sisipkan(array_merge($scope->kolomBaru(), [
                'version_no'             => $nomor,
                'label'                  => $this->labelSah($opsi['label'] ?? null, $nomor, $scope),
                'effective_from'         => $mulai,
                'status'                 => DokumenVersiModel::STATUS_DRAFT,
                'copied_from_version_id' => $asal['id'] ?? null,
                'mulai_dari_kosong'      => $sumber === self::SUMBER_KOSONG ? 1 : 0,
                'alasan_perubahan'       => $opsi['alasan_perubahan'] ?? null,
                'dasar_perubahan'        => $opsi['dasar_perubahan'] ?? null,
                'nomor_dasar'            => $opsi['nomor_dasar'] ?? null,
                'tanggal_dasar'          => $opsi['tanggal_dasar'] ?? null,
                'catatan'                => $opsi['catatan'] ?? null,
                'created_by'             => $opsi['created_by'] ?? null,
            ]));

            switch ($sumber) {
                case self::SUMBER_COPY:
                    $ringkasan = $arsip->salinDariVersi((int) $asal['id'], $versiId);
                    break;

                case self::SUMBER_KOSONG:
                    $ringkasan = [];
                    break;

                default:
                    $ringkasan = $arsip->bekukanDariLive($versiId, $scope);
            }

            $this->audit->catat($versiId, VersionAuditService::AKSI_CREATED, [
                'ke_status'      => DokumenVersiModel::STATUS_DRAFT,
                'ringkasan'      => $this->ringkasanTeks($sumber, $asal, $ringkasan),
                'sesudah'        => $ringkasan,
                'alasan'         => $opsi['alasan_perubahan'] ?? null,
                'dasar'          => $opsi['dasar_perubahan'] ?? null,
                'effective_from' => $mulai,
                'oleh'           => $opsi['created_by'] ?? null,
            ]);

            return ['version_id' => $versiId, 'ringkasan' => $ringkasan, 'sumber' => $sumber];
        }, 'pembuatan versi ' . $scope->modul());
    }

    /**
     * Ganti isi sebuah DRAFT dengan salinan versi lain.
     *
     * Dipakai ketika operator berubah pikiran soal titik awal. Isi lama dibuang
     * seluruhnya — aman karena draft belum pernah menjadi rujukan siapa pun.
     */
    public function isiUlangDraft(int $versiId, string $sumber, ?int $dariVersiId = null): array
    {
        return $this->dalamTransaksi(function () use ($versiId, $sumber, $dariVersiId) {
            $baris = $this->versi->ambil($versiId);

            if ($baris === null) {
                throw new RuntimeException('Versi tidak ditemukan: ' . $versiId);
            }

            if ($baris['status'] !== DokumenVersiModel::STATUS_DRAFT) {
                throw new RuntimeException(
                    'Hanya draft yang boleh diisi ulang. Versi published bersifat immutable.'
                );
            }

            $scope = VersionScope::dariBaris($baris);
            $arsip = $this->registry->untuk($scope->modul());

            if ($arsip === null || ! $arsip->siap()) {
                throw new RuntimeException('Arsip modul ' . $scope->modul() . ' tidak tersedia.');
            }

            $arsip->kosongkan($versiId);

            switch ($sumber) {
                case self::SUMBER_COPY:
                    $asal = $this->versiAsalSah($scope, (int) $dariVersiId);
                    $ringkasan = $arsip->salinDariVersi((int) $asal['id'], $versiId);
                    $this->versi->perbarui($versiId, [
                        'copied_from_version_id' => (int) $asal['id'],
                        'mulai_dari_kosong'      => 0,
                    ]);
                    break;

                case self::SUMBER_KOSONG:
                    $ringkasan = [];
                    $this->versi->perbarui($versiId, [
                        'copied_from_version_id' => null,
                        'mulai_dari_kosong'      => 1,
                    ]);
                    break;

                default:
                    $ringkasan = $arsip->bekukanDariLive($versiId, $scope);
                    $this->versi->perbarui($versiId, [
                        'copied_from_version_id' => null,
                        'mulai_dari_kosong'      => 0,
                    ]);
            }

            $this->audit->catat($versiId, VersionAuditService::AKSI_EDITED_DRAFT, [
                'ringkasan' => 'Isi draft diganti dari sumber: ' . $sumber,
                'sesudah'   => $ringkasan,
            ]);

            return $ringkasan;
        }, 'pengisian ulang draft');
    }

    /* =========================================================
     * PEMERIKSAAN
     * =======================================================*/

    /**
     * Versi asal salinan wajib PUBLISHED dan satu lingkup.
     *
     * Menyalin dari draft berarti menjadikan usulan yang belum resmi sebagai
     * titik awal dokumen resmi berikutnya; menyalin lintas lingkup berarti
     * Renstra OPD A diam-diam menjadi Renstra OPD B (§13, §55 anti-IDOR).
     */
    private function versiAsalSah(VersionScope $scope, int $dariVersiId): array
    {
        if ($dariVersiId <= 0) {
            throw new InvalidArgumentException('Versi asal salinan belum dipilih.');
        }

        $asal = $this->versi->ambilDalamLingkup($dariVersiId, $scope);

        if ($asal === null) {
            throw new RuntimeException(
                'Versi asal tidak ditemukan pada lingkup ' . $scope->label() . '.'
            );
        }

        if ($asal['status'] !== DokumenVersiModel::STATUS_PUBLISHED) {
            throw new RuntimeException(
                'Hanya versi yang sudah ditetapkan yang boleh disalin. Status versi asal: '
                . $asal['status'] . '.'
            );
        }

        return $asal;
    }

    private function tolakDiLuarPeriode(VersionScope $scope, string $mulai): void
    {
        $tahun = (int) date('Y', strtotime($mulai));

        if (! $scope->memuatTahun($tahun)) {
            throw new InvalidArgumentException(
                'Tanggal mulai berlaku (' . $mulai . ') di luar periode dokumen '
                . $scope->periodeMulai() . '-' . $scope->periodeAkhir() . '.'
            );
        }
    }

    private function normalkanTanggal(?string $tanggal, VersionScope $scope): string
    {
        if ($tanggal === null || trim($tanggal) === '') {
            // Bawaan: 1 Januari tahun awal periode. Dipilih supaya versi pertama
            // sebuah periode memayungi periode itu sejak hari pertamanya.
            return VersionResolver::awalTahun($scope->periodeMulai());
        }

        $ts = strtotime($tanggal);

        if ($ts === false) {
            throw new InvalidArgumentException('Tanggal mulai berlaku tidak sah: ' . $tanggal);
        }

        return date('Y-m-d', $ts);
    }

    private function labelSah(?string $label, int $nomor, VersionScope $scope): string
    {
        $label = trim((string) $label);

        if ($label !== '') {
            return mb_substr($label, 0, 255);
        }

        return 'V' . $nomor . ' — ' . $scope->label();
    }

    private function ringkasanTeks(string $sumber, ?array $asal, array $ringkasan): string
    {
        $isi = [];

        foreach ($ringkasan as $nama => $jml) {
            $isi[] = $nama . '=' . $jml;
        }

        $dari = match ($sumber) {
            self::SUMBER_COPY   => 'salinan dari V' . ($asal['version_no'] ?? '?'),
            self::SUMBER_KOSONG => 'mulai dari kosong',
            default             => 'salinan kondisi berjalan',
        };

        return 'Versi dibuat (' . $dari . ')' . ($isi === [] ? '' : ': ' . implode(', ', $isi));
    }
}
