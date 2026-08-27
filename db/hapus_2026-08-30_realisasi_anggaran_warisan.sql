-- =====================================================================
-- HAPUS REALISASI ANGGARAN "WARISAN" (monev_anggaran)
-- Tanggal : 2026-08-30
-- Sifat   : MENGHAPUS DATA SECARA PERMANEN. Bukan idempoten dalam arti
--           "aman diulang tanpa akibat" — sekali jalan, datanya hilang.
--
-- =====================================================================
-- BACA INI DULU SEBELUM MENJALANKAN
-- =====================================================================
--
-- 1. BARIS WARISAN = SELURUH REALISASI YANG ADA.
--    Sampai naskah ini ditulis, BELUM ADA satu pun OPD yang merinci
--    realisasi per program. Semua angka realisasi masih berupa baris
--    warisan. Jadi "menghapus yang warisan" pada praktiknya =
--    MENGHAPUS SELURUH CATATAN REALISASI ANGGARAN.
--
--    Periksa dulu di server dengan LANGKAH 1 di bawah. Bila di sana sudah
--    ada baris per-program, angkanya akan berbeda dan naskah ini tetap
--    hanya menyentuh yang warisan.
--
-- 2. DAMPAKNYA BUKAN HANYA HALAMAN MONEV.
--    `monev_anggaran` dibaca juga oleh:
--      * Dashboard OPD       - kartu "Penyerapan Anggaran", status realisasi
--                              per indikator, dan butir Prioritas Tindak
--                              Lanjut "Realisasi anggaran belum dilaporkan"
--      * Dashboard Kabupaten - penyerapan agregat seluruh OPD
--      * Dashboard Bupati    - memakai layanan yang sama
--    Sesudah dihapus, ketiganya menampilkan penyerapan 0% / "—" sampai
--    operator mengisi ulang.
--
-- 3. TIDAK ADA TABEL LAIN YANG BERGANTUNG PADANYA.
--    Sudah diperiksa: nol foreign key menunjuk ke `monev_anggaran`.
--    Menghapusnya tidak merembet ke tabel mana pun. Capaian kinerja
--    (`monev`), rencana aksi, PK, LAKIP, dan cascading TIDAK tersentuh.
--
-- 4. WAJIB BACKUP LEBIH DULU.
--    mysqldump -u USER -p NAMA_DB monev_anggaran > backup_monev_anggaran.sql
--    Tanpa ini, angka yang sudah diinput operator tidak bisa dipulihkan.
--
-- =====================================================================


-- =====================================================================
-- LANGKAH 1 - LIHAT DULU (tidak mengubah apa pun)
-- Jalankan ini SENDIRIAN, baca hasilnya, baru lanjut.
-- =====================================================================

SELECT 'Total baris realisasi'                AS keterangan, COUNT(*) AS jumlah,
       CONCAT('Rp ', FORMAT(SUM(COALESCE(realisasi_triwulan_1,0)
            + COALESCE(realisasi_triwulan_2,0)
            + COALESCE(realisasi_triwulan_3,0)
            + COALESCE(realisasi_triwulan_4,0)), 0)) AS nilai
FROM monev_anggaran
UNION ALL
SELECT 'AKAN DIHAPUS (warisan)', COUNT(*),
       CONCAT('Rp ', FORMAT(SUM(COALESCE(realisasi_triwulan_1,0)
            + COALESCE(realisasi_triwulan_2,0)
            + COALESCE(realisasi_triwulan_3,0)
            + COALESCE(realisasi_triwulan_4,0)), 0))
FROM monev_anggaran
WHERE ref_level IS NULL OR ref_level = '' OR COALESCE(ref_key, '') IN ('', ':0')
UNION ALL
SELECT 'AKAN DIPERTAHANKAN (sudah per program)', COUNT(*),
       CONCAT('Rp ', FORMAT(SUM(COALESCE(realisasi_triwulan_1,0)
            + COALESCE(realisasi_triwulan_2,0)
            + COALESCE(realisasi_triwulan_3,0)
            + COALESCE(realisasi_triwulan_4,0)), 0))
FROM monev_anggaran
WHERE ref_level IS NOT NULL AND ref_level <> '' AND COALESCE(ref_key, '') NOT IN ('', ':0');


-- Rincian per OPD: inilah daftar yang harus diminta mengisi ulang.
SELECT o.nama_opd AS opd,
       COUNT(*)   AS baris,
       CONCAT('Rp ', FORMAT(SUM(COALESCE(ma.realisasi_triwulan_1,0)
            + COALESCE(ma.realisasi_triwulan_2,0)
            + COALESCE(ma.realisasi_triwulan_3,0)
            + COALESCE(ma.realisasi_triwulan_4,0)), 0)) AS realisasi_hilang
FROM monev_anggaran ma
LEFT JOIN opd o ON o.id = ma.opd_id
WHERE ma.ref_level IS NULL OR ma.ref_level = '' OR COALESCE(ma.ref_key, '') IN ('', ':0')
GROUP BY o.nama_opd
ORDER BY SUM(COALESCE(ma.realisasi_triwulan_1,0)
           + COALESCE(ma.realisasi_triwulan_2,0)
           + COALESCE(ma.realisasi_triwulan_3,0)
           + COALESCE(ma.realisasi_triwulan_4,0)) DESC;


-- =====================================================================
-- LANGKAH 2 - CADANGKAN KE TABEL ARSIP
--
-- Bukan pengganti mysqldump, melainkan jaring kedua yang hidup di dalam
-- basis data itu sendiri, sehingga pemulihan bisa dilakukan lewat SQL
-- biasa tanpa menyentuh berkas dump.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `_arsip_monev_anggaran_20260830` LIKE `monev_anggaran`;

-- Kolomnya disebut satu per satu, TIDAK memakai SELECT *: `ref_key` adalah
-- kolom GENERATED (dihitung dari ref_level + ref_id), dan MySQL menolak nilai
-- yang disodorkan untuknya. Saat dipulihkan nanti ia terisi sendiri.
INSERT INTO `_arsip_monev_anggaran_20260830`
    (`id`, `target_rencana_id`, `opd_id`, `ref_level`, `ref_id`,
     `realisasi_triwulan_1`, `realisasi_triwulan_2`,
     `realisasi_triwulan_3`, `realisasi_triwulan_4`,
     `created_at`, `updated_at`)
SELECT `id`, `target_rencana_id`, `opd_id`, `ref_level`, `ref_id`,
     `realisasi_triwulan_1`, `realisasi_triwulan_2`,
     `realisasi_triwulan_3`, `realisasi_triwulan_4`,
     `created_at`, `updated_at`
FROM `monev_anggaran`
WHERE ref_level IS NULL OR ref_level = '' OR COALESCE(ref_key, '') IN ('', ':0');

-- Pastikan jumlahnya cocok SEBELUM menghapus. Bila kedua angka ini
-- berbeda, HENTIKAN — jangan jalankan LANGKAH 3.
SELECT (SELECT COUNT(*) FROM `_arsip_monev_anggaran_20260830`) AS tersalin,
       (SELECT COUNT(*) FROM `monev_anggaran`
         WHERE ref_level IS NULL OR ref_level = '' OR COALESCE(ref_key,'') IN ('', ':0')) AS akan_dihapus;


-- =====================================================================
-- LANGKAH 3 - HAPUS
--
-- Jalankan HANYA bila LANGKAH 2 menunjukkan dua angka yang sama.
-- =====================================================================

DELETE FROM `monev_anggaran`
WHERE ref_level IS NULL OR ref_level = '' OR COALESCE(ref_key, '') IN ('', ':0');


-- =====================================================================
-- LANGKAH 4 - PERIKSA HASIL
-- =====================================================================

SELECT 'Sisa baris realisasi' AS keterangan, COUNT(*) AS jumlah FROM monev_anggaran
UNION ALL
SELECT 'Tersimpan di arsip', COUNT(*) FROM `_arsip_monev_anggaran_20260830`;


-- =====================================================================
-- PEMULIHAN (bila ternyata keliru)
--
--   INSERT INTO `monev_anggaran`
--       (`id`, `target_rencana_id`, `opd_id`, `ref_level`, `ref_id`,
--        `realisasi_triwulan_1`, `realisasi_triwulan_2`,
--        `realisasi_triwulan_3`, `realisasi_triwulan_4`,
--        `created_at`, `updated_at`)
--   SELECT `id`, `target_rencana_id`, `opd_id`, `ref_level`, `ref_id`,
--          `realisasi_triwulan_1`, `realisasi_triwulan_2`,
--          `realisasi_triwulan_3`, `realisasi_triwulan_4`,
--          `created_at`, `updated_at`
--   FROM `_arsip_monev_anggaran_20260830`;
--
-- Tabel arsipnya sengaja TIDAK dibuang otomatis. Hapus sendiri bila
-- sudah yakin, misalnya sesudah seluruh OPD selesai mengisi ulang:
--
--   DROP TABLE `_arsip_monev_anggaran_20260830`;
-- =====================================================================


-- =====================================================================
-- VARIAN: HAPUS SATU OPD SAJA
--
-- Lebih aman dijalankan bertahap — satu OPD diminta mengisi ulang,
-- dipastikan beres, baru lanjut ke OPD berikutnya. Ganti 29 dengan
-- id OPD yang dimaksud (29 = BPBD).
--
--   INSERT INTO `_arsip_monev_anggaran_20260830`
--       (`id`, `target_rencana_id`, `opd_id`, `ref_level`, `ref_id`,
--        `realisasi_triwulan_1`, `realisasi_triwulan_2`,
--        `realisasi_triwulan_3`, `realisasi_triwulan_4`, `created_at`, `updated_at`)
--   SELECT `id`, `target_rencana_id`, `opd_id`, `ref_level`, `ref_id`,
--          `realisasi_triwulan_1`, `realisasi_triwulan_2`,
--          `realisasi_triwulan_3`, `realisasi_triwulan_4`, `created_at`, `updated_at`
--   FROM `monev_anggaran`
--   WHERE opd_id = 29
--     AND (ref_level IS NULL OR ref_level = '' OR COALESCE(ref_key,'') IN ('', ':0'));
--
--   DELETE FROM `monev_anggaran`
--   WHERE opd_id = 29
--     AND (ref_level IS NULL OR ref_level = '' OR COALESCE(ref_key,'') IN ('', ':0'));
-- =====================================================================
