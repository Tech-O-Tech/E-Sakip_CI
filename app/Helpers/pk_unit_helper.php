<?php

/**
 * "Unit" anggaran pada Perjanjian Kinerja (PK).
 *
 * Yang dimaksud "unit" di sini adalah satuan anggaran yang tampil di kolom
 * yang dulu bernama "Program" pada layar/cetak Target & Rencana Aksi dan MONEV.
 * Tingkat unit TIDAK sama untuk semua PK — bergantung pada jenis PK-nya:
 *
 *   pk.jenis = bupati | jpt | camat | kecamatan  -> 'program'     ("Program")
 *   pk.jenis = administrator                     -> 'kegiatan'    ("Kegiatan")
 *   pk.jenis = pengawas                          -> 'subkegiatan' ("Sub Kegiatan")
 *
 * PENTING — KENAPA 'camat' MASUK KELOMPOK PROGRAM:
 * Di layar, PK camat diberi label eselon "Eselon III" sehingga secara naluri
 * terlihat setara 'administrator' (Kegiatan). Namun kenyataan datanya lain:
 * 8 dari 9 OPD kecamatan TIDAK punya satu pun baris `kegiatan_pk`. Kalau 'camat'
 * dipaksa ke tingkat 'kegiatan', seluruh kolom unit untuk PK Kecamatan akan
 * kosong melompong. Karena itu 'camat' (dan 'kecamatan') tetap di tingkat
 * 'program'.
 *
 * ATURAN KERAS: penentuan tingkat SELALU memakai nilai `pk.jenis` MENTAH.
 * JANGAN memakai hasil eselonLabel() — label itu kosmetik (mis. relabel untuk
 * role admin_kecamatan) dan tidak mencerminkan struktur data anggaran.
 */

if (!function_exists('pk_unit_level')) {
    /**
     * Tingkat unit anggaran untuk sebuah jenis PK.
     *
     * @param string|null $pkJenis nilai kolom `pk.jenis` APA ADANYA (mentah)
     *
     * @return string 'program' | 'kegiatan' | 'subkegiatan'
     */
    function pk_unit_level(?string $pkJenis): string
    {
        $jenis = strtolower(trim((string) $pkJenis));

        switch ($jenis) {
            case 'administrator':
                return 'kegiatan';

            case 'pengawas':
                return 'subkegiatan';

            case 'bupati':
            case 'jpt':
            case 'camat':      // lihat catatan di atas: kecamatan nyaris tak punya kegiatan_pk
            case 'kecamatan':
            default:
                // Jenis tak dikenal pun diperlakukan sebagai 'program' — tingkat
                // paling atas dan paling aman, karena hampir selalu terisi.
                return 'program';
        }
    }
}

if (!function_exists('pk_unit_label_dari_level')) {
    /**
     * Label tampilan untuk sebuah tingkat unit.
     *
     * @param string|null $level 'program' | 'kegiatan' | 'subkegiatan'
     *
     * @return string 'Program' | 'Kegiatan' | 'Sub Kegiatan'
     */
    function pk_unit_label_dari_level(?string $level): string
    {
        switch (strtolower(trim((string) $level))) {
            case 'kegiatan':
                return 'Kegiatan';

            case 'subkegiatan':
                return 'Sub Kegiatan';

            case 'program':
            default:
                return 'Program';
        }
    }
}

if (!function_exists('pk_unit_label')) {
    /**
     * Label tampilan unit untuk sebuah jenis PK.
     *
     * @param string|null $pkJenis nilai kolom `pk.jenis` APA ADANYA (mentah)
     *
     * @return string 'Program' | 'Kegiatan' | 'Sub Kegiatan'
     */
    function pk_unit_label(?string $pkJenis): string
    {
        return pk_unit_label_dari_level(pk_unit_level($pkJenis));
    }
}

if (!function_exists('pk_unit_header')) {
    /**
     * Judul kolom unit pada tabel/cetak, mengikuti filter eselon yang aktif.
     *
     * Kalau filternya spesifik, judulnya ikut spesifik. Kalau tidak difilter,
     * satu tabel bisa memuat campuran tingkat sekaligus, jadi judulnya digabung.
     *
     * @param string|null $eselonFilter nilai filter eselon (setara `pk.jenis`), boleh null/kosong
     */
    function pk_unit_header(?string $eselonFilter): string
    {
        $filter = strtolower(trim((string) $eselonFilter));

        if ($filter === '') {
            return 'Program / Kegiatan / Sub Kegiatan';
        }

        switch ($filter) {
            case 'jpt':
                return 'Program';

            case 'administrator':
                return 'Kegiatan';

            case 'pengawas':
                return 'Sub Kegiatan';

            default:
                // Filter lain (mis. 'bupati', 'camat') mengikuti pemetaan tingkat biasa.
                return pk_unit_label($filter);
        }
    }
}

if (!function_exists('pk_bagi_baris')) {
    /**
     * Bagi tinggi satu indikator ($n baris) ke sejumlah item unit lewat rowspan,
     * supaya tidak ada sel Program/Anggaran yang menganga kosong.
     *
     * Contoh: 4 baris & 2 item -> rowspan 2 dan 2.
     *
     * Ini adalah SATU-SATUNYA salinan algoritme yang sebelumnya tersebar di
     * empat view pk_renaksi (index, monev, cetak, target_rencana_aksi_cetak);
     * perilakunya sengaja dibuat identik dengan salinan-salinan tersebut.
     *
     * Catatan: pemanggil selalu mengirim $n = max(barisRenaksi, jumlah item),
     * jadi $n >= count($items). Bila $n lebih kecil (di luar pemakaian normal),
     * jaminan "setiap item dapat minimal 1 baris" tetap dimenangkan sehingga
     * total span bisa melebihi $n — persis seperti kode aslinya.
     *
     * @param array $items daftar unit (kunci array dipertahankan apa adanya)
     * @param int   $n     jumlah baris yang tersedia untuk indikator ini
     *
     * @return array [$span, $mulai]
     *               $span[$i]       = rowspan untuk item berkunci $i
     *               $mulai[$barisKe]= indeks item yang mulai dicetak di baris ke-$barisKe (0-based)
     */
    function pk_bagi_baris(array $items, int $n): array
    {
        if (empty($items)) {
            return [[], []];
        }

        $span  = [];
        $mulai = [];

        $sisaBaris = $n;
        $sisaItem  = count($items);
        $awal      = 0;

        foreach ($items as $i => $_) {
            $s            = max(1, (int) ceil($sisaBaris / max(1, $sisaItem)));
            $span[$i]     = $s;
            $mulai[$awal] = $i;

            $awal += $s;
            $sisaBaris -= $s;
            $sisaItem--;
        }

        return [$span, $mulai];
    }
}
