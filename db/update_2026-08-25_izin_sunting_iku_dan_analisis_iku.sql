-- =====================================================================
-- 1. Izin sunting untuk IKU     2. Analisis Faktor bersumber IKU
-- =====================================================================
--
-- BAGIAN 1 — IZIN SUNTING IKU
--
-- Tabel `dokumen_izin_sunting` sudah generik sejak awal: ia punya kolom
-- `modul`, dan IzinSuntingService bekerja atas VersionScope yang sudah
-- mengenal MODUL_IKU. Jadi TIDAK ADA tabel baru di sini — yang kurang hanya
-- dua izin akses, supaya alurnya sama persis dengan Renstra:
--
--     iku.izin_sunting.request   -> OPD memohon kunci dibuka
--     iku.izin_sunting.verify    -> Admin Kabupaten memutuskan
--
-- BAGIAN 2 — ANALISIS FAKTOR BERSUMBER IKU
--
-- `lakip_analisis_faktor` hanya mengenal `renstra_target_id` dan
-- `rpjmd_target_id`. Sejak LAKIP OPD boleh bersumber IKU, id yang dipakai
-- baris IKU adalah id INDIKATOR IKU BERJALAN — dan tanpa kolomnya sendiri, id
-- itu terpaksa ditulis ke `renstra_target_id`, kolom yang artinya lain.
--
-- Akibatnya sama persis dengan yang sudah diperbaiki pada
-- `lakip_penyesuaian` (migrasi 2026-08-24): indikator IKU #57 dan target
-- Renstra #57 tertukar, dan analisis yang ditulis untuk satu sumber muncul
-- pada sumber yang lain begitu operator berganti pilihan.
--
-- Pola perbaikannya SENGAJA dibuat identik dengan migrasi itu, supaya kedua
-- tabel bisa dibaca dengan satu aturan yang sama.
--
-- Aman diulang: setiap langkah memeriksa dirinya sendiri lebih dulu.
-- =====================================================================

SET @db := DATABASE();

-- =====================================================================
-- BAGIAN 1 — IZIN AKSES
-- =====================================================================

INSERT INTO `permissions` (`name`, `label`, `grup`, `created_at`, `updated_at`)
SELECT * FROM (
    SELECT 'iku.izin_sunting.request' AS n,
           'IKU — Ajukan izin sunting' AS l,
           'IKU' AS g, NOW() AS c, NOW() AS u
) AS x
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'iku.izin_sunting.request');

INSERT INTO `permissions` (`name`, `label`, `grup`, `created_at`, `updated_at`)
SELECT * FROM (
    SELECT 'iku.izin_sunting.verify' AS n,
           'IKU — Putuskan izin sunting' AS l,
           'IKU' AS g, NOW() AS c, NOW() AS u
) AS x
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'iku.izin_sunting.verify');

-- Peran yang sudah memegang izin Renstra yang setara langsung mendapat
-- padanannya untuk IKU. Tanpa ini, fiturnya terpasang tetapi tidak terlihat
-- oleh siapa pun sampai ada yang mencentangnya satu per satu di menu Peran.
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT rp.`role_id`, p_baru.`id`
  FROM `role_permissions` rp
  JOIN `permissions` p_lama ON p_lama.`id` = rp.`permission_id`
  JOIN `permissions` p_baru
    ON p_baru.`name` = REPLACE(p_lama.`name`, 'renstra.izin_sunting.', 'iku.izin_sunting.')
 WHERE p_lama.`name` IN ('renstra.izin_sunting.request', 'renstra.izin_sunting.verify')
   AND NOT EXISTS (
       SELECT 1 FROM `role_permissions` x
        WHERE x.`role_id` = rp.`role_id` AND x.`permission_id` = p_baru.`id`
   );

-- =====================================================================
-- BAGIAN 2 — ANALISIS FAKTOR
-- =====================================================================

-- ---------------------------------------------------------------------
-- 2a. Kolom kunci IKU
-- ---------------------------------------------------------------------
SET @ada := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'lakip_analisis_faktor'
               AND COLUMN_NAME = 'iku_indikator_id');

SET @sql := IF(@ada = 0,
    'ALTER TABLE `lakip_analisis_faktor`
       ADD COLUMN `iku_indikator_id` INT UNSIGNED NULL AFTER `rpjmd_target_id`',
    'DO 0');

PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 2b. Kolom sumber
-- ---------------------------------------------------------------------
SET @ada := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'lakip_analisis_faktor'
               AND COLUMN_NAME = 'source_type');

SET @sql := IF(@ada = 0,
    'ALTER TABLE `lakip_analisis_faktor`
       ADD COLUMN `source_type` VARCHAR(10) NULL AFTER `iku_indikator_id`',
    'DO 0');

PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 2c. Back-fill baris lama.
--
-- Semuanya lahir sebelum sumber IKU mungkin ada, jadi tidak ada baris yang
-- perlu berpindah kunci — hanya diberi nama sumbernya.
-- ---------------------------------------------------------------------
UPDATE `lakip_analisis_faktor`
   SET `source_type` = CASE
                          WHEN `rpjmd_target_id` IS NOT NULL THEN 'rpjmd'
                          ELSE 'renstra'
                       END
 WHERE `source_type` IS NULL;

-- ---------------------------------------------------------------------
-- 2d. Indeks pencarian.
--
-- SENGAJA TANPA kolom hasil hitung seperti pada `lakip_penyesuaian`. Di sana
-- `target_key`/`sumber_key` menegakkan UNIQUE — satu penyesuaian aktif per
-- (baris, jenis). Di sini tidak ada invariant semacam itu: satu indikator
-- memang boleh punya beberapa baris analisis, dan view merendernya sebagai
-- daftar. Kolom hasil hitung tanpa invariant hanya menambah biaya: ia STORED,
-- memaksa ALTER menyalin seluruh tabel, dan pada tabel ber-foreign-key
-- penyalinan itu gagal membangun ulang constraint-nya.
--
-- Yang benar-benar dibutuhkan hanya indeks pencarian, dan itu terpasang
-- di tempat tanpa menyalin apa pun.
-- ---------------------------------------------------------------------
SET @ada := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'lakip_analisis_faktor'
               AND INDEX_NAME = 'idx_analisis_iku');

SET @sql := IF(@ada = 0,
    'ALTER TABLE `lakip_analisis_faktor`
       ADD INDEX `idx_analisis_iku` (`iku_indikator_id`, `tahun`)',
    'DO 0');

PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 3. Ringkasan
-- ---------------------------------------------------------------------
SELECT
    (SELECT COUNT(*) FROM `permissions`
      WHERE `name` IN ('iku.izin_sunting.request', 'iku.izin_sunting.verify')) AS izin_iku_terpasang,
    (SELECT COUNT(*) FROM `role_permissions` rp JOIN `permissions` p ON p.`id` = rp.`permission_id`
      WHERE p.`name` LIKE 'iku.izin_sunting.%') AS peran_diberi_izin,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'lakip_analisis_faktor'
        AND COLUMN_NAME IN ('iku_indikator_id', 'source_type')) AS kolom_analisis_baru,
    (SELECT COUNT(*) FROM `lakip_analisis_faktor` WHERE `source_type` IS NULL) AS analisis_tanpa_sumber;
