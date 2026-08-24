<?php

namespace App\Services\Version;

use RuntimeException;

/**
 * Dilempar ketika lebih dari satu versi published efektif pada tanggal yang sama.
 *
 * Ini BUKAN galat teknis melainkan galat ADMINISTRATIF: datanya tidak konsisten
 * dan hanya manusia yang boleh memutuskan mana yang benar. §26 dan §81 sama-sama
 * melarang sistem memilih salah satu diam-diam, karena versi yang salah pilih
 * akan mengubah bunyi seluruh LAKIP tahun itu tanpa jejak.
 *
 * Halaman yang menangkapnya menampilkan daftar kandidat, bukan angka.
 */
class VersionConflictException extends RuntimeException
{
    /** @var array<int,array> kandidat yang bertabrakan */
    private array $kandidat;

    private VersionScope $scope;

    private string $tanggal;

    public function __construct(VersionScope $scope, string $tanggal, array $kandidat)
    {
        $this->scope    = $scope;
        $this->tanggal  = $tanggal;
        $this->kandidat = $kandidat;

        $nomor = implode(', ', array_map(
            static fn ($k) => 'V' . ($k['version_no'] ?? '?') . ' (' . ($k['label'] ?? '-') . ')',
            $kandidat
        ));

        parent::__construct(
            'Konflik versi pada ' . $scope->label() . ' tanggal ' . $tanggal . ': '
            . count($kandidat) . ' versi berlaku bersamaan — ' . $nomor . '. '
            . 'Perbaiki tanggal berlakunya lebih dulu; sistem sengaja tidak memilih salah satu.'
        );
    }

    /** @return array<int,array> */
    public function kandidat(): array
    {
        return $this->kandidat;
    }

    public function scope(): VersionScope
    {
        return $this->scope;
    }

    public function tanggal(): string
    {
        return $this->tanggal;
    }
}
