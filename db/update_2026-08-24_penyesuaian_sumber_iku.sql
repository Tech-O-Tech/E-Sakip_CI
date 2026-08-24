-- =====================================================================
-- Penyesuaian Kebijakan LAKIP: kenali baris bersumber IKU
-- =====================================================================
--
-- MASALAH
--
-- `lakip_penyesuaian` hanya punya dua kolom kunci: `renstra_target_id` dan
-- `rpjmd_target_id`. Sejak LAKIP OPD boleh bersumber IKU, id yang dipilih
-- operator pada baris IKU adalah id INDIKATOR IKU BERJALAN — dan id itu
-- selama ini ditulis ke `renstra_target_id`, kolom yang artinya lain.
--
-- Dua akibat nyata:
--
--   1. TABRAKAN. `uq_lakip_penyesuaian_aktif` memakai `target_key` yang
--      dihitung dari kedua kolom itu, sementara `mode` bernilai 'opd' baik
--      untuk sumber Renstra maupun IKU. Indikator IKU #57 dan target Renstra
--      #57 karena itu dianggap SATU baris yang sama: menyimpan penyesuaian
--      untuk yang satu akan menonaktifkan penyesuaian milik yang lain.
--
--   2. TAUTAN KE SNAPSHOT PUTUS. `simpan()` mencari baris snapshot lewat
--      `lakip_snapshot_baris.renstra_target_id`, yang pada snapshot bersumber
--      IKU memang NULL. Penyesuaian setelah finalisasi jadi tidak pernah
--      tertaut ke baris beku yang dikoreksinya — padahal setelah tahun
--      dikunci, inilah SATU-SATUNYA jalur koreksi yang diizinkan.
--
-- YANG DIUBAH
--
--   + kolom  `iku_indikator_id`  — kunci baris untuk sumber IKU
--   + kolom  `source_type`       — sumber baris ('renstra' | 'rpjmd' | 'iku')
--   ~ kolom  `target_key`        — ikut menghitung `iku_indikator_id`
--   + kolom  `sumber_key`        — `source_type` yang sudah dinormalkan
--   ~ index  `uq_lakip_penyesuaian_aktif` — `sumber_key` masuk kunci
--
-- Baris lama tidak punya `source_type`; semuanya lahir sebelum sumber IKU
-- mungkin ada, jadi keduanya di-backfill sebagai 'renstra'/'rpjmd' sesuai
-- kolom yang terisi. Tidak ada baris yang berpindah kunci.
--
-- Aman diulang: setiap langkah memeriksa dirinya sendiri lebih dulu.
-- =====================================================================

SET @db := DATABASE();

-- ---------------------------------------------------------------------
-- 1. Kolom kunci IKU
-- ---------------------------------------------------------------------
SET @ada := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'lakip_penyesuaian'
               AND COLUMN_NAME = 'iku_indikator_id');

SET @sql := IF(@ada = 0,
    'ALTER TABLE `lakip_penyesuaian`
       ADD COLUMN `iku_indikator_id` INT UNSIGNED NULL AFTER `rpjmd_target_id`',
    'DO 0');

PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 2. Kolom sumber
-- ---------------------------------------------------------------------
SET @ada := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'lakip_penyesuaian'
               AND COLUMN_NAME = 'source_type');

SET @sql := IF(@ada = 0,
    'ALTER TABLE `lakip_penyesuaian`
       ADD COLUMN `source_type` VARCHAR(10) NULL AFTER `iku_indikator_id`',
    'DO 0');

PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 3. Back-fill sumber baris lama (sebelum IKU mungkin jadi sumber)
-- ---------------------------------------------------------------------
UPDATE `lakip_penyesuaian`
   SET `source_type` = CASE
                          WHEN `rpjmd_target_id` IS NOT NULL THEN 'rpjmd'
                          ELSE 'renstra'
                       END
 WHERE `source_type` IS NULL;

-- ---------------------------------------------------------------------
-- 4. Lepas index lebih dulu — kolom hasil hitung tidak bisa diubah
--    selagi dipakai UNIQUE.
-- ---------------------------------------------------------------------
SET @ada := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'lakip_penyesuaian'
               AND INDEX_NAME = 'uq_lakip_penyesuaian_aktif');

SET @sql := IF(@ada > 0,
    'ALTER TABLE `lakip_penyesuaian` DROP INDEX `uq_lakip_penyesuaian_aktif`',
    'DO 0');

PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 5. `target_key` ikut menghitung kunci IKU
-- ---------------------------------------------------------------------
ALTER TABLE `lakip_penyesuaian`
  MODIFY COLUMN `target_key` INT UNSIGNED
    GENERATED ALWAYS AS (
        COALESCE(`renstra_target_id`, `rpjmd_target_id`, `iku_indikator_id`, 0)
    ) STORED;

-- ---------------------------------------------------------------------
-- 6. `sumber_key` — supaya indikator IKU #57 dan target Renstra #57
--    tidak lagi dianggap baris yang sama
-- ---------------------------------------------------------------------
SET @ada := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'lakip_penyesuaian'
               AND COLUMN_NAME = 'sumber_key');

SET @sql := IF(@ada = 0,
    'ALTER TABLE `lakip_penyesuaian`
       ADD COLUMN `sumber_key` VARCHAR(10)
         GENERATED ALWAYS AS (COALESCE(`source_type`, ''renstra'')) STORED
         AFTER `target_key`',
    'DO 0');

PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 7. Pasang kembali UNIQUE, kini dengan sumbernya
-- ---------------------------------------------------------------------
SET @ada := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'lakip_penyesuaian'
               AND INDEX_NAME = 'uq_lakip_penyesuaian_aktif');

SET @sql := IF(@ada = 0,
    'ALTER TABLE `lakip_penyesuaian`
       ADD UNIQUE KEY `uq_lakip_penyesuaian_aktif`
         (`tahun`, `mode`, `opd_id`, `sumber_key`, `target_key`, `jenis`, `aktif_key`)',
    'DO 0');

PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 8. Ringkasan
-- ---------------------------------------------------------------------
SELECT
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'lakip_penyesuaian'
        AND COLUMN_NAME IN ('iku_indikator_id', 'source_type', 'sumber_key')) AS kolom_baru_terpasang,
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'lakip_penyesuaian'
        AND INDEX_NAME = 'uq_lakip_penyesuaian_aktif') AS bagian_unique,
    (SELECT COUNT(*) FROM `lakip_penyesuaian` WHERE `source_type` IS NULL) AS sisa_tanpa_sumber;
