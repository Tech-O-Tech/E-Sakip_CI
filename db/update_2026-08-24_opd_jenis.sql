-- =====================================================================
-- PENANDA JENIS ENTITAS PADA TABEL `opd`
-- Dipakai Dashboard Eksekutif (Bupati & admin_kab) untuk MENGELUARKAN
-- kecamatan, kelurahan, dan UPT dari agregat lintas Perangkat Daerah.
--
-- MENGAPA KOLOM, BUKAN DETEKSI DARI DATA
--   Sebelumnya jenis entitas ditebak dari `pk.jenis` ('camat') atau dari
--   pola nama. Keduanya rapuh:
--     * Kecamatan Sukoharjo (opd 34) punya PK 'camat' (211) DAN PK 'jpt'
--       (490) pada tahun 2026 — dashboard memilih dokumen JPT yang nyasar.
--     * Ejaan nama tidak seragam: 'KECAMATAN GADING REJO' vs
--       'Kecamatan Gadingrejo'.
--   Dengan kolom eksplisit, klasifikasinya jadi data yang bisa dikoreksi
--   Super Admin lewat menu Master OPD, bukan aturan tersembunyi di kode.
--
-- Kembaran migration-nya:
--   app/Database/Migrations/2026-08-24-000001_AddJenisToOpd.php
-- Idempoten: aman dijalankan berulang.
-- =====================================================================

SET @ada := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'opd' AND COLUMN_NAME = 'jenis');
SET @sql := IF(@ada = 0,
  "ALTER TABLE `opd`
     ADD COLUMN `jenis` VARCHAR(20) NOT NULL DEFAULT 'opd'
     COMMENT 'opd|kecamatan|kelurahan|upt|non_opd' AFTER `singkatan`",
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @ada := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'opd' AND INDEX_NAME = 'idx_opd_jenis');
SET @sql := IF(@ada = 0, 'CREATE INDEX `idx_opd_jenis` ON `opd` (`jenis`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- Klasifikasi awal. Memakai pola nama HANYA sekali di sini (saat seed);
-- sesudah ini yang berlaku adalah isi kolom, bukan nama.
-- Baris yang sudah pernah diubah manual (jenis <> 'opd') tidak ditimpa.
-- ---------------------------------------------------------------------
UPDATE `opd` SET `jenis` = 'kecamatan'
 WHERE `jenis` = 'opd' AND `nama_opd` LIKE 'Kecamatan%';

UPDATE `opd` SET `jenis` = 'kelurahan'
 WHERE `jenis` = 'opd' AND `nama_opd` LIKE 'Kelurahan%';

UPDATE `opd` SET `jenis` = 'upt'
 WHERE `jenis` = 'opd' AND (`nama_opd` LIKE 'UPT %' OR `nama_opd` LIKE 'UPTD %');

-- Entitas yang bukan Perangkat Daerah: pemda induk, jabatan, bagian.
UPDATE `opd` SET `jenis` = 'non_opd'
 WHERE `jenis` = 'opd' AND (
       `nama_opd` LIKE 'Kabupaten %'
    OR `nama_opd` = 'BUPATI'
    OR `nama_opd` LIKE 'BAGIAN %'
 );
