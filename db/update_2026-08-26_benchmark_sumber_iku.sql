-- =====================================================================
-- Benchmark Provinsi/Nasional: kenali indikator bersumber IKU
-- =====================================================================
--
-- MASALAH
--
-- `lakip_benchmark` hanya mengenal `rpjmd_indikator_id` dan
-- `renstra_indikator_id`, dan keduanya ber-FOREIGN KEY. Sejak layar LAKIP OPD
-- menampilkan indikator IKU, id yang dikirim form adalah id INDIKATOR IKU
-- BERJALAN — id yang tidak ada di `renstra_indikator_sasaran`.
--
-- Akibatnya bukan tertukar diam-diam, melainkan buntu total: setiap upaya
-- mengisi angka pembanding untuk baris IKU ditolak, entah oleh
-- `indikatorSah()` ("Indikator tidak ditemukan pada tahun & unit yang
-- dipilih") atau oleh foreign key.
--
-- YANG DIUBAH
--
--   + kolom  `iku_indikator_id`  — kunci baris untuk sumber IKU
--   + kolom  `source_type`       — sumber baris ('renstra' | 'rpjmd' | 'iku')
--   + unique `uq_benchmark_iku`  — satu benchmark per (indikator IKU, tahun),
--                                  sejalan dengan dua UNIQUE yang sudah ada
--
-- SENGAJA TANPA FOREIGN KEY ke `iku_indikator`.
--
-- Dua FK yang ada memakai ON DELETE CASCADE. Untuk Renstra/RPJMD itu aman:
-- indikatornya dihapus hanya bila dokumennya dibongkar. IKU tidak begitu —
-- `terapkanKeLive` mengelola baris live tiap kali revisi disahkan atau
-- diterapkan ulang, dan CASCADE di sana berisiko menghapus angka pembanding
-- yang masih dipakai laporan. Asimetri ini disengaja, bukan terlupa.
--
-- CATATAN PERAN
--
-- Izin `lakip_benchmark.manage_own` dan `.manage_all` SUDAH dibuat migrasi
-- 2026-08-20 dan sudah diberikan ke peran (admin_opd memegang `manage_own`),
-- tetapi kodenya belum pernah membacanya — `benchmarkCanManage()` hanya
-- memeriksa `lakip_benchmark.manage`. Itu diperbaiki di sisi kode, bukan di
-- sini; migrasi ini tidak perlu menyentuh permission sama sekali.
--
-- Aman diulang: setiap langkah memeriksa dirinya sendiri lebih dulu.
-- =====================================================================

SET @db := DATABASE();

-- ---------------------------------------------------------------------
-- 1. Kolom kunci IKU
-- ---------------------------------------------------------------------
SET @ada := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'lakip_benchmark'
               AND COLUMN_NAME = 'iku_indikator_id');

SET @sql := IF(@ada = 0,
    'ALTER TABLE `lakip_benchmark`
       ADD COLUMN `iku_indikator_id` INT UNSIGNED NULL AFTER `renstra_indikator_id`',
    'DO 0');

PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 2. Kolom sumber
-- ---------------------------------------------------------------------
SET @ada := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'lakip_benchmark'
               AND COLUMN_NAME = 'source_type');

SET @sql := IF(@ada = 0,
    'ALTER TABLE `lakip_benchmark`
       ADD COLUMN `source_type` VARCHAR(10) NULL AFTER `iku_indikator_id`',
    'DO 0');

PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 3. Back-fill baris lama.
--
-- Semuanya lahir sebelum sumber IKU mungkin ada, jadi tidak ada baris yang
-- berpindah kunci — hanya diberi nama sumbernya.
-- ---------------------------------------------------------------------
UPDATE `lakip_benchmark`
   SET `source_type` = CASE
                          WHEN `rpjmd_indikator_id` IS NOT NULL THEN 'rpjmd'
                          ELSE 'renstra'
                       END
 WHERE `source_type` IS NULL;

-- ---------------------------------------------------------------------
-- 4. UNIQUE per (indikator IKU, tahun).
--
-- Invariantnya sama dengan dua UNIQUE yang sudah ada: satu angka pembanding
-- per indikator per tahun. MySQL memperlakukan tiap NULL sebagai berbeda,
-- sehingga baris Renstra/RPJMD (yang kolom ini NULL) tidak saling mengunci.
-- ---------------------------------------------------------------------
SET @ada := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'lakip_benchmark'
               AND INDEX_NAME = 'uq_benchmark_iku');

SET @sql := IF(@ada = 0,
    'ALTER TABLE `lakip_benchmark`
       ADD UNIQUE KEY `uq_benchmark_iku` (`iku_indikator_id`, `tahun`)',
    'DO 0');

PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 5. Ringkasan
-- ---------------------------------------------------------------------
SELECT
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'lakip_benchmark'
        AND COLUMN_NAME IN ('iku_indikator_id', 'source_type')) AS kolom_baru,
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'lakip_benchmark'
        AND INDEX_NAME = 'uq_benchmark_iku') AS unique_iku,
    (SELECT COUNT(*) FROM `lakip_benchmark` WHERE `source_type` IS NULL) AS tanpa_sumber;
