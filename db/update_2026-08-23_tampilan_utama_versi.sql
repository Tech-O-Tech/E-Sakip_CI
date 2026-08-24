-- =====================================================================
-- e-SAKIP / AKSARA — TUNJUKAN "TAMPILAN UTAMA" UNTUK SEBUAH VERSI
--
-- WAJIB: --default-character-set=utf8mb4
--   mysql -u root -p --default-character-set=utf8mb4 "e-sakip_6" < berkas.sql
-- Tanpa opsi itu, klien mysql memakai CODEPAGE KONSOL dan "—" jadi "ÔÇö".
--
-- Berkas ini IDEMPOTEN: aman dijalankan berulang kali.
-- PRASYARAT: db/update_2026-08-20_versioning_dokumen.sql sudah dijalankan.
--
-- =====================================================================
-- APA YANG DITAMBAHKAN DAN MENGAPA
--
-- Sampai sekarang, "Renstra mana yang berlaku" dijawab oleh RENTANG TANGGAL
-- (`effective_from`/`effective_to`). Perubahan ini menambahkan jawaban KEDUA:
-- sebuah versi boleh ditunjuk sebagai TAMPILAN UTAMA menu Renstra.
--
-- Dua jawaban atas satu pertanyaan itu berbahaya kalau dibiarkan diam-diam.
-- Karena itu:
--
--   1. Tunjukan disimpan sebagai KOLOM TERSENDIRI, bukan dengan mengarang
--      tanggal. Riwayat tanggal berlaku tetap jujur apa adanya.
--   2. Paling banyak SATU tunjukan per dokumen — dijamin UNIQUE di engine,
--      bukan oleh kode aplikasi yang bisa lupa.
--   3. Siapa dan kapan menunjuk ikut dicatat, supaya bisa dipertanggungjawabkan.
--
-- Aplikasi menambahkan pengaman keempat yang tidak bisa dinyatakan di sini:
-- bila tunjukan BERBEDA dari versi yang berlaku menurut tanggal, perbedaannya
-- ditampilkan terang-terangan di layar, tidak didiamkan.
--
-- =====================================================================
-- CARA KERJA JAMINAN "PALING BANYAK SATU"
--
-- `tampilan_key` bernilai 1 hanya bila `tampilan_utama` = 1, selain itu NULL.
-- MySQL memperbolehkan NULL berulang di dalam UNIQUE, tetapi menolak angka 1
-- yang kedua. Jadi UNIQUE-nya membaca: "boleh berapa pun versi yang TIDAK
-- ditunjuk, tetapi hanya satu yang ditunjuk". Cara yang sama sudah dipakai
-- `terbuka_key` untuk menjamin satu versi terbuka.
--
-- KONSEKUENSI URUTAN: MySQL memeriksa UNIQUE per-pernyataan, jadi memindahkan
-- tunjukan HARUS melepas dulu, baru memasang. Aplikasi melakukannya dalam satu
-- transaksi (DokumenVersiModel::tetapkanTampilanUtama).
-- =====================================================================

SET NAMES utf8mb4;
SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';

-- ---------------------------------------------------------------------
-- 0. Prasyarat
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _tu_cek_prasyarat;

DELIMITER $$

CREATE PROCEDURE _tu_cek_prasyarat()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dokumen_versi'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Tabel dokumen_versi belum ada. Jalankan dulu db/update_2026-08-20_versioning_dokumen.sql';
  END IF;
END$$

DELIMITER ;

CALL _tu_cek_prasyarat();
DROP PROCEDURE IF EXISTS _tu_cek_prasyarat;

-- ---------------------------------------------------------------------
-- 1. Helper idempoten
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _tu_add_col_if_absent;
DROP PROCEDURE IF EXISTS _tu_add_idx_if_absent;

DELIMITER $$

CREATE PROCEDURE _tu_add_col_if_absent(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_col
  ) THEN
    SET @sql = p_ddl; PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$

CREATE PROCEDURE _tu_add_idx_if_absent(IN p_table VARCHAR(64), IN p_idx VARCHAR(64), IN p_ddl TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_idx
  ) THEN
    SET @sql = p_ddl; PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$

DELIMITER ;

-- ---------------------------------------------------------------------
-- 2. Kolom tunjukan + jejak siapa/kapan
--
-- `tampilan_oleh` sengaja TANPA foreign key ke `users`: pengguna bisa
-- dinonaktifkan atau berganti, sedangkan catatan siapa yang menunjuk harus
-- tetap terbaca. Pola yang sama dipakai kolom pelaku lain di dokumen_versi.
-- ---------------------------------------------------------------------
CALL _tu_add_col_if_absent('dokumen_versi', 'tampilan_utama',
  'ALTER TABLE `dokumen_versi` ADD COLUMN `tampilan_utama` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = dipakai sebagai tampilan utama menu dokumennya''');

CALL _tu_add_col_if_absent('dokumen_versi', 'tampilan_oleh',
  'ALTER TABLE `dokumen_versi` ADD COLUMN `tampilan_oleh` INT UNSIGNED NULL COMMENT ''pengguna yang menunjuk; tanpa FK (sengaja)''');

CALL _tu_add_col_if_absent('dokumen_versi', 'tampilan_pada',
  'ALTER TABLE `dokumen_versi` ADD COLUMN `tampilan_pada` DATETIME NULL COMMENT ''kapan tunjukan dipasang''');

-- Kolom generated HARUS ditambahkan setelah `tampilan_utama` ada.
CALL _tu_add_col_if_absent('dokumen_versi', 'tampilan_key',
  'ALTER TABLE `dokumen_versi` ADD COLUMN `tampilan_key` TINYINT AS (CASE WHEN `tampilan_utama` = 1 THEN 1 ELSE NULL END) STORED');

-- ---------------------------------------------------------------------
-- 3. Jaminan engine: paling banyak SATU tunjukan per dokumen
-- ---------------------------------------------------------------------
CALL _tu_add_idx_if_absent('dokumen_versi', 'uq_dokver_tampilan',
  'ALTER TABLE `dokumen_versi` ADD UNIQUE KEY `uq_dokver_tampilan` (`modul`, `scope_key`, `opd_key`, `periode_mulai`, `periode_akhir`, `tampilan_key`)');

DROP PROCEDURE IF EXISTS _tu_add_col_if_absent;
DROP PROCEDURE IF EXISTS _tu_add_idx_if_absent;

-- ---------------------------------------------------------------------
-- 4. Pembersihan: tunjukan hanya sah pada versi yang sudah ditetapkan
--
-- Dijalankan juga saat pemasangan ulang, sebagai jaring pengaman bila ada
-- versi yang statusnya berubah di luar jalur aplikasi.
-- ---------------------------------------------------------------------
UPDATE `dokumen_versi`
   SET `tampilan_utama` = 0, `tampilan_oleh` = NULL, `tampilan_pada` = NULL
 WHERE `tampilan_utama` = 1 AND `status` <> 'published';

-- ---------------------------------------------------------------------
-- 5. Izin baru: siapa yang boleh menunjuk tampilan utama
--
-- Menunjuk BUKAN menyunting isi — tidak ada satu pun angka yang berubah
-- karenanya. Tetapi ia mengubah apa yang dilihat SELURUH pengguna OPD itu,
-- jadi ia tetap izin tersendiri, bukan menumpang `.view`.
-- ---------------------------------------------------------------------
INSERT INTO `permissions` (`name`, `label`, `grup`) VALUES
  ('rpjmd.version.pin',   'RPJMD — Tunjuk Tampilan Utama',   'Kabupaten'),
  ('renstra.version.pin', 'Renstra — Tunjuk Tampilan Utama', 'OPD'),
  ('iku.version.pin',     'IKU — Tunjuk Tampilan Utama',     'Umum'),
  ('lakip.version.pin',   'LAKIP — Tunjuk Tampilan Utama',   'Umum')
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `grup` = VALUES(`grup`);

-- admin_kab: seluruh modul
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r JOIN `permissions` p
  ON p.name IN ('rpjmd.version.pin', 'renstra.version.pin', 'iku.version.pin', 'lakip.version.pin')
WHERE r.name = 'admin_kab';

-- OPD & kecamatan: hanya dokumen lingkupnya sendiri
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r JOIN `permissions` p
  ON p.name IN ('renstra.version.pin', 'iku.version.pin', 'lakip.version.pin')
WHERE r.name IN ('admin_opd', 'admin_kecamatan');

-- Read-only TIDAK diberi: menunjuk mengubah apa yang dilihat orang lain.

-- ---------------------------------------------------------------------
-- 6. Verifikasi
-- ---------------------------------------------------------------------
SELECT 'kolom tampilan_utama' AS pemeriksaan,
       IF(COUNT(*) = 4, 'OK', 'GAGAL') AS hasil,
       CONCAT(COUNT(*), ' dari 4 kolom') AS keterangan
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dokumen_versi'
   AND COLUMN_NAME IN ('tampilan_utama', 'tampilan_oleh', 'tampilan_pada', 'tampilan_key')
UNION ALL
SELECT 'unique satu tunjukan per dokumen',
       IF(COUNT(*) > 0, 'OK', 'GAGAL'),
       CONCAT(COUNT(*), ' kolom pada uq_dokver_tampilan')
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dokumen_versi'
   AND INDEX_NAME = 'uq_dokver_tampilan'
UNION ALL
SELECT 'izin .version.pin',
       IF(COUNT(*) = 4, 'OK', 'GAGAL'),
       CONCAT(COUNT(*), ' dari 4 izin')
  FROM `permissions`
 WHERE `name` IN ('rpjmd.version.pin', 'renstra.version.pin', 'iku.version.pin', 'lakip.version.pin')
UNION ALL
SELECT 'tidak ada tunjukan pada versi belum ditetapkan',
       IF(COUNT(*) = 0, 'OK', 'GAGAL'),
       CONCAT(COUNT(*), ' baris menyimpang')
  FROM `dokumen_versi`
 WHERE `tampilan_utama` = 1 AND `status` <> 'published';

SET SQL_MODE = @OLD_SQL_MODE;
