<?php

namespace App\Services\Version;

use InvalidArgumentException;

/**
 * Lingkup satu dokumen ber-versi: (modul, scope, opd, periode).
 *
 * Dibuat sebagai objek nilai — bukan empat argumen lepas — karena keempatnya
 * SELALU bergerak bersama dan tertukarnya dua di antaranya menghasilkan bug
 * yang senyap: versi milik OPD lain ikut terbaca, atau periode 2020-2024 dan
 * 2025-2029 tercampur. Dengan satu objek, salah pasang menjadi galat langsung.
 *
 * `opdKey()` mencerminkan generated column `dokumen_versi.opd_key`
 * (COALESCE(opd_id,0)). Tingkat kabupaten memakai opd_id NULL, dan MySQL
 * menganggap dua NULL selalu berbeda — tanpa normalisasi ini UNIQUE index
 * penegak invariant tidak akan pernah mengikat untuk tingkat kabupaten.
 */
final class VersionScope
{
    public const MODUL_RPJMD   = 'rpjmd';
    public const MODUL_RENSTRA = 'renstra';
    public const MODUL_IKU     = 'iku';
    public const MODUL_LAKIP   = 'lakip';

    public const SCOPE_KABUPATEN = 'kabupaten';
    public const SCOPE_OPD       = 'opd';

    public const MODUL = [
        self::MODUL_RPJMD,
        self::MODUL_RENSTRA,
        self::MODUL_IKU,
        self::MODUL_LAKIP,
    ];

    private string $modul;
    private string $scope;
    private ?int $opdId;
    private int $periodeMulai;
    private int $periodeAkhir;

    public function __construct(
        string $modul,
        string $scope,
        ?int $opdId,
        int $periodeMulai,
        int $periodeAkhir
    ) {
        $modul = strtolower(trim($modul));
        $scope = strtolower(trim($scope));

        if (! in_array($modul, self::MODUL, true)) {
            throw new InvalidArgumentException('Modul versi tidak dikenal: ' . $modul);
        }

        if (! in_array($scope, [self::SCOPE_KABUPATEN, self::SCOPE_OPD], true)) {
            throw new InvalidArgumentException('Scope versi tidak dikenal: ' . $scope);
        }

        // RPJMD tidak punya pemilik OPD — memaksanya lewat konstruktor mencegah
        // "RPJMD milik OPD 12" yang tidak berarti apa-apa tetapi akan membuat
        // baseline dan resolver meleset tanpa pesan galat.
        if ($modul === self::MODUL_RPJMD && $scope !== self::SCOPE_KABUPATEN) {
            throw new InvalidArgumentException('RPJMD selalu berlingkup kabupaten.');
        }

        if ($scope === self::SCOPE_OPD && empty($opdId)) {
            throw new InvalidArgumentException('Scope OPD wajib menyertakan opd_id.');
        }

        if ($scope === self::SCOPE_KABUPATEN) {
            $opdId = null;
        }

        if ($periodeMulai <= 0 || $periodeAkhir <= 0) {
            throw new InvalidArgumentException('Periode versi tidak boleh nol.');
        }

        if ($periodeAkhir < $periodeMulai) {
            throw new InvalidArgumentException(
                'Periode terbalik: ' . $periodeMulai . '-' . $periodeAkhir
            );
        }

        $this->modul        = $modul;
        $this->scope        = $scope;
        $this->opdId        = $opdId;
        $this->periodeMulai = $periodeMulai;
        $this->periodeAkhir = $periodeAkhir;
    }

    /** LAKIP berumur satu tahun: periode mulai = periode akhir = tahun laporan. */
    public static function lakip(string $scope, ?int $opdId, int $tahun): self
    {
        return new self(self::MODUL_LAKIP, $scope, $opdId, $tahun, $tahun);
    }

    public static function rpjmd(int $periodeMulai, int $periodeAkhir): self
    {
        return new self(self::MODUL_RPJMD, self::SCOPE_KABUPATEN, null, $periodeMulai, $periodeAkhir);
    }

    public static function renstra(int $opdId, int $periodeMulai, int $periodeAkhir): self
    {
        return new self(self::MODUL_RENSTRA, self::SCOPE_OPD, $opdId, $periodeMulai, $periodeAkhir);
    }

    /** IKU ada di dua tingkat: opdId NULL = kabupaten. */
    public static function iku(?int $opdId, int $periodeMulai, int $periodeAkhir): self
    {
        return new self(
            self::MODUL_IKU,
            $opdId === null ? self::SCOPE_KABUPATEN : self::SCOPE_OPD,
            $opdId,
            $periodeMulai,
            $periodeAkhir
        );
    }

    public function modul(): string
    {
        return $this->modul;
    }

    public function scope(): string
    {
        return $this->scope;
    }

    public function opdId(): ?int
    {
        return $this->opdId;
    }

    /** Cerminan generated column dokumen_versi.opd_key. */
    public function opdKey(): int
    {
        return (int) ($this->opdId ?? 0);
    }

    public function periodeMulai(): int
    {
        return $this->periodeMulai;
    }

    public function periodeAkhir(): int
    {
        return $this->periodeAkhir;
    }

    /** True bila $tahun berada di dalam periode dokumen ini. */
    public function memuatTahun(int $tahun): bool
    {
        return $tahun >= $this->periodeMulai && $tahun <= $this->periodeAkhir;
    }

    /** Kondisi WHERE untuk query builder — satu-satunya tempat nama kolom lingkup ditulis. */
    public function kondisi(string $prefix = ''): array
    {
        $p = $prefix === '' ? '' : rtrim($prefix, '.') . '.';

        return [
            $p . 'modul'         => $this->modul,
            $p . 'scope_key'     => $this->scope,
            $p . 'opd_key'       => $this->opdKey(),
            $p . 'periode_mulai' => $this->periodeMulai,
            $p . 'periode_akhir' => $this->periodeAkhir,
        ];
    }

    /** Nilai kolom untuk INSERT baris dokumen_versi. */
    public function kolomBaru(): array
    {
        return [
            'modul'         => $this->modul,
            'scope'         => $this->scope,
            'opd_id'        => $this->opdId,
            'periode_mulai' => $this->periodeMulai,
            'periode_akhir' => $this->periodeAkhir,
        ];
    }

    public function sama(self $lain): bool
    {
        return $this->kunci() === $lain->kunci();
    }

    public function kunci(): string
    {
        return implode('|', [
            $this->modul,
            $this->scope,
            $this->opdKey(),
            $this->periodeMulai,
            $this->periodeAkhir,
        ]);
    }

    public function label(): string
    {
        $nama = strtoupper($this->modul);
        $milik = $this->opdId === null ? 'Kabupaten' : ('OPD #' . $this->opdId);

        return $nama . ' ' . $milik . ' ' . $this->periodeMulai . '-' . $this->periodeAkhir;
    }

    public static function dariBaris(array $row): self
    {
        return new self(
            (string) ($row['modul'] ?? ''),
            (string) ($row['scope'] ?? self::SCOPE_KABUPATEN),
            isset($row['opd_id']) && $row['opd_id'] !== null && $row['opd_id'] !== ''
                ? (int) $row['opd_id']
                : null,
            (int) ($row['periode_mulai'] ?? 0),
            (int) ($row['periode_akhir'] ?? 0)
        );
    }
}
