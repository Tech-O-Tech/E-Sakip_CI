-- =====================================================================
-- e-SAKIP / AKSARA — IZIN SUNTING DOKUMEN YANG SUDAH DITETAPKAN
--
-- WAJIB: --default-character-set=utf8mb4
--   mysql -u root -p --default-character-set=utf8mb4 "e-sakip_6" < berkas.sql
-- Tanpa opsi itu, klien mysql memakai CODEPAGE KONSOL dan "—" jadi "ÔÇö".
--
-- Berkas ini IDEMPOTEN: aman dijalankan berulang kali.
-- PRASYARAT: db/update_2026-08-20_versioning_dokumen.sql sudah dijalankan.
--
-- =====================================================================
-- APA YANG DIPECAHKAN
--
-- Sesudah Renstra ditetapkan, menu Renstra terkunci. Sampai sekarang satu-
-- satunya jalan keluar adalah "Ajukan Koreksi" — per-medan, kaku, dan berada
-- di menu lain sehingga terasa seperti jalan buntu.
--
-- Gantinya: OPD MEMINTA IZIN MENYUNTING. Admin Kabupaten menyetujui, lalu
-- kunci menu Renstra terbuka dan penyuntingan berjalan seperti biasa —
-- form yang sama, tombol yang sama, tanpa menu tersendiri.
--
-- =====================================================================
-- YANG TIDAK BERUBAH, DAN INI POKOKNYA
--
-- Izin ini membuka kunci TABEL BERJALAN. Arsip versi yang sudah ditetapkan
-- TIDAK ikut terbuka dan tidak pernah tersentuh. Karena itu:
--
--   * jejak audit tetap membuktikan apa yang dulu disetujui
--   * snapshot LAKIP tetap cocok dengan versi yang dirujuknya
--   * fitur Bandingkan tetap membandingkan dua hal yang keduanya diam
--
-- Hasil penyuntingan menjadi VERSI BERIKUTNYA saat diajukan ulang. Jadi
-- izin ini memperpendek jalan, bukan melubangi arsip.
--
-- =====================================================================
-- PALING BANYAK SATU PERMOHONAN TERBUKA
--
-- `aktif_key` bernilai 1 hanya selama status masih berjalan (pending atau
-- disetujui), selain itu NULL. MySQL memperbolehkan NULL berulang di dalam
-- UNIQUE tetapi menolak angka 1 yang kedua — sehingga satu dokumen tidak
-- bisa punya dua permohonan menggantung sekaligus. Cara yang sama dipakai
-- `terbuka_key` dan `tampilan_key`.
-- =====================================================================

SET NAMES utf8mb4;
SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';

-- ---------------------------------------------------------------------
-- 0. Prasyarat
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _is_cek_prasyarat;

DELIMITER $$

CREATE PROCEDURE _is_cek_prasyarat()
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

CALL _is_cek_prasyarat();
DROP PROCEDURE IF EXISTS _is_cek_prasyarat;

-- ---------------------------------------------------------------------
-- 1. Tabel permohonan izin sunting
--
-- Bentuknya sengaja generik (punya kolom `modul`) walaupun untuk sekarang
-- hanya Renstra yang memakainya. Menambah modul lain kelak cukup dengan
-- memberi izinnya, tanpa menyentuh skema lagi.
--
-- `diminta_oleh` / `diputus_oleh` TANPA foreign key ke `users`: pengguna bisa
-- dinonaktifkan atau berganti, sedangkan catatan siapa yang meminta dan siapa
-- yang menyetujui harus tetap terbaca. Pola yang sama dipakai dokumen_versi.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dokumen_izin_sunting` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,

  `modul`             VARCHAR(20)  NOT NULL COMMENT 'rpjmd | renstra | iku | lakip',
  `scope`             VARCHAR(12)  NULL COMMENT 'kabupaten | opd',
  `opd_id`            INT UNSIGNED NULL,
  `periode_mulai`     INT NOT NULL,
  `periode_akhir`     INT NOT NULL,

  `version_id`        INT UNSIGNED NULL COMMENT 'versi yang berlaku saat izin diminta; untuk jejak',

  `status`            VARCHAR(20) NOT NULL DEFAULT 'pending'
                      COMMENT 'pending | disetujui | ditolak | selesai | dicabut',

  `alasan`            TEXT NOT NULL COMMENT 'wajib: mengapa perlu menyunting',
  `catatan_keputusan` TEXT NULL COMMENT 'catatan Admin Kabupaten saat menolak/menyetujui',

  `diminta_oleh`      INT UNSIGNED NULL,
  `diminta_nama`      VARCHAR(150) NULL COMMENT 'dibekukan saat permohonan dibuat',
  `diminta_pada`      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,

  `diputus_oleh`      INT UNSIGNED NULL,
  `diputus_nama`      VARCHAR(150) NULL,
  `diputus_pada`      DATETIME NULL,

  `selesai_pada`      DATETIME NULL COMMENT 'terisi saat versi berikutnya diajukan',
  `updated_at`        DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- ---------- generated: penegak invariant ----------
  `opd_key`           INT UNSIGNED AS (COALESCE(`opd_id`, 0)) STORED,
  `scope_key`         VARCHAR(12)  AS (COALESCE(`scope`, 'kabupaten')) STORED,
  `aktif_key`         TINYINT AS (CASE WHEN `status` IN ('pending', 'disetujui') THEN 1 ELSE NULL END) STORED,

  PRIMARY KEY (`id`),

  -- paling banyak SATU permohonan yang masih berjalan per dokumen
  UNIQUE KEY `uq_izin_aktif`
    (`modul`, `scope_key`, `opd_key`, `periode_mulai`, `periode_akhir`, `aktif_key`),

  KEY `idx_izin_lingkup` (`modul`, `scope_key`, `opd_key`, `periode_mulai`, `periode_akhir`, `status`),
  KEY `idx_izin_antrean` (`status`, `diminta_pada`),
  KEY `idx_izin_versi`   (`version_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 2. Izin baru
--
-- Meminta izin dan memutuskannya adalah dua wewenang berbeda, sebagaimana
-- mengajukan versi dan menetapkannya. Tanpa pemisahan itu, OPD bisa membuka
-- kuncinya sendiri dan penguncian menjadi hiasan.
-- ---------------------------------------------------------------------
INSERT INTO `permissions` (`name`, `label`, `grup`) VALUES
  ('renstra.izin_sunting.request', 'Renstra — Ajukan Izin Sunting',       'OPD'),
  ('renstra.izin_sunting.verify',  'Renstra — Putuskan Izin Sunting',     'Kabupaten')
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `grup` = VALUES(`grup`);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r JOIN `permissions` p
  ON p.name = 'renstra.izin_sunting.verify'
WHERE r.name = 'admin_kab';

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r JOIN `permissions` p
  ON p.name = 'renstra.izin_sunting.request'
WHERE r.name IN ('admin_opd', 'admin_kecamatan');

-- Read-only (inspektorat, bupati) TIDAK diberi keduanya.

-- ---------------------------------------------------------------------
-- 3. Verifikasi
-- ---------------------------------------------------------------------
SELECT 'tabel dokumen_izin_sunting' AS pemeriksaan,
       IF(COUNT(*) = 1, 'OK', 'GAGAL') AS hasil,
       CONCAT(COUNT(*), ' tabel') AS keterangan
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dokumen_izin_sunting'
UNION ALL
SELECT 'unique satu permohonan berjalan',
       IF(COUNT(*) > 0, 'OK', 'GAGAL'),
       CONCAT(COUNT(*), ' kolom pada uq_izin_aktif')
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dokumen_izin_sunting'
   AND INDEX_NAME = 'uq_izin_aktif'
UNION ALL
SELECT 'izin izin_sunting',
       IF(COUNT(*) = 2, 'OK', 'GAGAL'),
       CONCAT(COUNT(*), ' dari 2 izin')
  FROM `permissions`
 WHERE `name` IN ('renstra.izin_sunting.request', 'renstra.izin_sunting.verify')
UNION ALL
SELECT 'admin_kab bisa memutuskan',
       IF(COUNT(*) = 1, 'OK', 'GAGAL'),
       CONCAT(COUNT(*), ' pemberian')
  FROM `role_permissions` rp
  JOIN `roles` r ON r.id = rp.role_id
  JOIN `permissions` p ON p.id = rp.permission_id
 WHERE r.name = 'admin_kab' AND p.name = 'renstra.izin_sunting.verify'
UNION ALL
SELECT 'OPD TIDAK bisa memutuskan sendiri',
       IF(COUNT(*) = 0, 'OK', 'GAGAL'),
       CONCAT(COUNT(*), ' pemberian menyimpang')
  FROM `role_permissions` rp
  JOIN `roles` r ON r.id = rp.role_id
  JOIN `permissions` p ON p.id = rp.permission_id
 WHERE r.name IN ('admin_opd', 'admin_kecamatan') AND p.name = 'renstra.izin_sunting.verify';

SET SQL_MODE = @OLD_SQL_MODE;
