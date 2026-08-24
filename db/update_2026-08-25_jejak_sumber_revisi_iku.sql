-- =====================================================================
-- e-SAKIP / AKSARA — JEJAK SUMBER IKU IKUT MASUK ARSIP REVISI
--
-- WAJIB: --default-character-set=utf8mb4
--   mysql -u root -p --default-character-set=utf8mb4 "e-sakip_6" < berkas.sql
-- Tanpa opsi itu, klien mysql memakai CODEPAGE KONSOL dan "—" jadi "ÔÇö".
--
-- Berkas ini IDEMPOTEN: aman dijalankan berulang kali.
-- PRASYARAT: db/update_2026-08-18_iku_revisi_lakip_snapshot.sql &
--            db/update_2026-08-20_versioning_dokumen.sql sudah dijalankan.
--
-- =====================================================================
-- MASALAH YANG DIPECAHKAN
--
-- `iku_sasaran` dan `iku_indikator` sudah mencatat asal-usulnya: dari modul
-- apa, dari VERSI Renstra yang mana, dan dari baris berjalan yang mana.
--
-- Tetapi arsip revisi tidak. `bekukanLiveKeRevisi()` menyalin belasan kolom
-- dan TIDAK menyertakan ketiganya, sedangkan `terapkanKeLive()` menulis ulang
-- baris live tanpa menyentuhnya. Akibatnya:
--
--   * membekukan IKU ke sebuah revisi MEMBUANG jejak asalnya
--   * baris live BARU yang lahir dari pengesahan revisi tidak punya jejak
--   * arsip revisi tidak pernah bisa menjawab "indikator ini dari Renstra V?"
--
-- Kehilangan itu tidak memunculkan galat apa pun. Kolomnya hanya menjadi
-- NULL, dan tidak ada yang tahu ia pernah terisi.
--
-- =====================================================================
-- MENGAPA `source_ref_id`, BUKAN `source_indikator_id`
--
-- Tabel arsip sudah punya `sumber_sasaran_id` / `sumber_indikator_id` yang
-- artinya "baris IKU BERJALAN yang dibekukan baris arsip ini". Menambahkan
-- `source_indikator_id` di sebelahnya akan melahirkan dua nama nyaris kembar
-- dengan arti berbeda — sumber kekeliruan yang tinggal menunggu waktu.
--
--   sumber_*_id   -> baris IKU berjalan  (di dalam modul IKU)
--   source_ref_id -> baris RENSTRA berjalan yang menjadi asal-usulnya
-- =====================================================================

SET NAMES utf8mb4;
SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';

-- ---------------------------------------------------------------------
-- 0. Prasyarat
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _js_cek_prasyarat;

DELIMITER $$

CREATE PROCEDURE _js_cek_prasyarat()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'iku_revisi_indikator'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Tabel iku_revisi_indikator belum ada. Jalankan dulu db/update_2026-08-18_iku_revisi_lakip_snapshot.sql';
  END IF;
END$$

DELIMITER ;

CALL _js_cek_prasyarat();
DROP PROCEDURE IF EXISTS _js_cek_prasyarat;

-- ---------------------------------------------------------------------
-- 1. Helper idempoten
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _js_add_col_if_absent;

DELIMITER $$

CREATE PROCEDURE _js_add_col_if_absent(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table
  ) AND NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_col
  ) THEN
    SET @sql = p_ddl; PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$

-- Tiga kolom jejak untuk satu tabel arsip revisi.
CREATE PROCEDURE _js_kolom_jejak(IN p_table VARCHAR(64))
BEGIN
  CALL _js_add_col_if_absent(p_table, 'source_type',
    CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `source_type` VARCHAR(20) NULL COMMENT ''rpjmd | renstra — modul asal sync'''));
  CALL _js_add_col_if_absent(p_table, 'source_version_id',
    CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `source_version_id` INT UNSIGNED NULL COMMENT ''dokumen_versi sumber; NULL = disalin dari kondisi berjalan'''));
  CALL _js_add_col_if_absent(p_table, 'source_ref_id',
    CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `source_ref_id` INT UNSIGNED NULL COMMENT ''baris RENSTRA berjalan asalnya — jangan tertukar dengan sumber_*_id yang menunjuk baris IKU berjalan'''));
END$$

DELIMITER ;

CALL _js_kolom_jejak('iku_revisi_sasaran');
CALL _js_kolom_jejak('iku_revisi_indikator');

DROP PROCEDURE IF EXISTS _js_kolom_jejak;
DROP PROCEDURE IF EXISTS _js_add_col_if_absent;

-- ---------------------------------------------------------------------
-- 2. Isi mundur dari baris live yang dibekukan
--
-- Arsip yang sudah ada dibekukan sebelum kolom ini lahir. Selama baris live
-- asalnya masih menyimpan jejaknya, jejak itu bisa dipulihkan — bukan
-- ditebak. Yang live-nya sudah tidak ada dibiarkan NULL, sebab menebaknya
-- berarti mengarang.
-- ---------------------------------------------------------------------
UPDATE `iku_revisi_sasaran` a
  JOIN `iku_sasaran` s ON s.id = a.sumber_sasaran_id
   SET a.source_type       = s.source_type,
       a.source_version_id = s.source_version_id,
       a.source_ref_id     = s.source_sasaran_id
 WHERE a.source_type IS NULL AND s.source_type IS NOT NULL;

UPDATE `iku_revisi_indikator` a
  JOIN `iku_indikator` i ON i.id = a.sumber_indikator_id
   SET a.source_type       = i.source_type,
       a.source_version_id = i.source_version_id,
       a.source_ref_id     = i.source_indikator_id
 WHERE a.source_type IS NULL AND i.source_type IS NOT NULL;

-- ---------------------------------------------------------------------
-- 3. Verifikasi
-- ---------------------------------------------------------------------
SELECT 'kolom jejak pada arsip revisi' AS pemeriksaan,
       IF(COUNT(*) = 6, 'OK', 'GAGAL') AS hasil,
       CONCAT(COUNT(*), ' dari 6 kolom') AS keterangan
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN ('iku_revisi_sasaran', 'iku_revisi_indikator')
   AND COLUMN_NAME IN ('source_type', 'source_version_id', 'source_ref_id')
UNION ALL
SELECT 'arsip yang jejaknya berhasil dipulihkan',
       'INFO',
       CONCAT(
         (SELECT COUNT(*) FROM `iku_revisi_sasaran` WHERE source_type IS NOT NULL), ' sasaran, ',
         (SELECT COUNT(*) FROM `iku_revisi_indikator` WHERE source_type IS NOT NULL), ' indikator'
       )
UNION ALL
SELECT 'baris IKU berjalan yang punya jejak',
       'INFO',
       CONCAT(
         (SELECT COUNT(*) FROM `iku_sasaran` WHERE source_type IS NOT NULL), ' sasaran, ',
         (SELECT COUNT(*) FROM `iku_indikator` WHERE source_type IS NOT NULL), ' indikator'
       );

SET SQL_MODE = @OLD_SQL_MODE;
