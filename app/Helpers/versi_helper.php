<?php

use Config\Database;

/**
 * Bantuan kecil untuk menu versi dokumen.
 *
 * Dipisah dari model supaya sidebar tidak perlu memuat DokumenVersiModel hanya
 * demi satu angka, dan supaya kegagalan apa pun di sini TIDAK PERNAH memblok
 * halaman — menu yang gagal berarti seluruh aplikasi tidak bisa dipakai.
 */

if (! function_exists('versi_pending_count')) {
    /**
     * Jumlah pengajuan versi yang menunggu verifikasi pemakai ini.
     *
     * Di-cache per request: sidebar dirender di setiap halaman, dan tanpa cache
     * ini akan menjadi satu query tambahan pada setiap permintaan.
     *
     * Mengembalikan 0 bila tabel registri belum terpasang, sehingga aplikasi
     * yang belum menjalankan SQL-nya tetap berjalan normal.
     */
    function versi_pending_count(): int
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        if (! function_exists('user_can')) {
            return $cache = 0;
        }

        $modul = array_values(array_filter(
            ['rpjmd', 'renstra', 'iku', 'lakip'],
            static fn ($m) => user_can($m . '.version.verify')
        ));

        if ($modul === []) {
            return $cache = 0;
        }

        try {
            $db = Database::connect();

            if (! $db->tableExists('dokumen_versi')) {
                return $cache = 0;
            }

            $jml = (int) $db->table('dokumen_versi')
                ->where('status', 'pending_approval')
                ->whereIn('modul', $modul)
                ->countAllResults();

            // Permintaan koreksi ikut dihitung: keduanya sama-sama menunggu
            // keputusan Admin Kabupaten di halaman yang sama.
            if ($db->tableExists('version_correction_requests') && user_can('version_correction.verify')) {
                $jml += (int) $db->table('version_correction_requests k')
                    ->join('dokumen_versi d', 'd.id = k.version_id')
                    ->where('k.status', 'pending')
                    ->whereIn('d.modul', $modul)
                    ->countAllResults();
            }

            return $cache = $jml;
        } catch (Throwable $e) {
            // Basis belum siap / koneksi bermasalah — menu tetap tampil tanpa badge.
            return $cache = 0;
        }
    }
}

if (! function_exists('versi_boleh_verifikasi')) {
    /** True bila pemakai berwenang memverifikasi setidaknya satu jenis dokumen. */
    function versi_boleh_verifikasi(): bool
    {
        if (! function_exists('user_can')) {
            return false;
        }

        foreach (['rpjmd', 'renstra', 'iku', 'lakip'] as $m) {
            if (user_can($m . '.version.verify')) {
                return true;
            }
        }

        return false;
    }
}
