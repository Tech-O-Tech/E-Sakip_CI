-- =====================================================================
-- REALISASI ANGGARAN MONEV: DARI PER-INDIKATOR MENJADI PER-UNIT
-- Tanggal : 2026-08-19
-- Sifat   : IDEMPOTEN (aman dijalankan berulang) & ADDITIVE.
--           TIDAK ADA DELETE, TIDAK ADA TRUNCATE, tidak ada kolom lama yang
--           dibuang. Seluruh baris produksi yang sudah terisi bertahan utuh.
--
-- Jalankan:
--   mysql -u root test_sakip < db/update_2026-08-19_monev_anggaran_per_unit.sql
--
-- =====================================================================
-- MASALAH YANG DIPERBAIKI
-- =====================================================================
-- `monev_anggaran` lahir (db/update_2026-07-27_monev_anggaran.sql) dengan
-- anggapan: satu indikator PK = satu baris realisasi anggaran. Itu terlihat
-- benar selama kolom anggarannya cuma menampilkan PROGRAM.
--
-- Kenyataannya satuan anggaran yang ditampilkan berbeda-beda menurut jenis PK
-- yang MENTAH (pk.jenis, bukan hasil eselonLabel()):
--
--   bupati / jpt / camat / kecamatan -> Program
--   administrator                    -> Kegiatan
--   pengawas                         -> Sub Kegiatan
--
-- dan satu indikator bisa ditopang BEBERAPA unit sekaligus. Dengan UNIQUE
-- (target_rencana_id) yang lama, semua unit itu terpaksa berbagi SATU baris
-- realisasi — angka realisasi program A dan program B tertumpuk jadi satu dan
-- tidak bisa dipisahkan lagi.
--
-- =====================================================================
-- BENTUK PENYELESAIANNYA
-- =====================================================================
-- Baris realisasi ditambatkan ke UNIT, bukan lagi cuma ke indikator:
--
--   ref_level  'program' | 'kegiatan' | 'subkegiatan'  (NULL = baris warisan)
--   ref_id     id tabel MASTER: program_pk.id | kegiatan_pk.id | sub_kegiatan_pk.id
--
-- MENGAPA id MASTER, BUKAN id TABEL JEMBATAN (pk_program / pk_kegiatan /
-- pk_sub_kegiatan): tabel jembatan berisi banyak baris untuk unit yang sama
-- (pk_kegiatan punya 2120 baris untuk hanya 433 kegiatan unik). Menambatkan
-- realisasi ke id jembatan berarti angka yang sama tercatat berkali-kali dan
-- tidak pernah bisa dijumlahkan dengan benar. Sisi pembacanya
-- (TargetModel::getUnitPkByIndikator) memang sudah dedup memakai id MASTER,
-- jadi kunci di sini harus memakai id yang sama.
--
-- MENGAPA ADA `ref_key` GENERATED, BUKAN LANGSUNG UNIQUE (target, level, id):
-- MySQL tidak pernah mengikat baris ber-NULL pada UNIQUE index. Dengan
-- UNIQUE (target_rencana_id, ref_level, ref_id), setiap baris warisan
-- (ref_level & ref_id NULL) akan lolos berkali-kali — persis lubang yang
-- membuat data ganda diam-diam menumpuk. `ref_key` menerjemahkan NULL menjadi
-- teks ':0' yang konkret, sehingga:
--
--   * baris warisan hanya boleh SATU per target_rencana_id (sama seperti dulu);
--   * baris per unit unik per (target_rencana_id, level, id master).
--
-- Nilainya STORED, bukan VIRTUAL, karena dipakai sebagai kolom UNIQUE dan
-- dibaca berulang kali di setiap halaman MONEV.
--
-- =====================================================================
-- NASIB BARIS PRODUKSI YANG SUDAH ADA
-- =====================================================================
-- Baris lama TIDAK disentuh: ref_level dan ref_id-nya NULL, sehingga ref_key
-- otomatis bernilai ':0'. Aplikasi membacanya sebagai "WARISAN — realisasi
-- gabungan yang belum dirinci per unit" dan tetap menampilkannya, bukan
-- membuangnya. Karena UNIQUE lama menjamin satu baris per target_rencana_id,
-- setelah perubahan ini setiap baris warisan otomatis unik pada
-- (target_rencana_id, ':0') — jadi index barunya pasti bisa dibuat.
--
-- Menebak ref_level/ref_id baris lama SENGAJA TIDAK dilakukan. Angka gabungan
-- tidak bisa dibongkar kembali tanpa mengarang, dan mengarang di atas data
-- anggaran adalah hal terakhir yang boleh dilakukan skrip migrasi.
-- =====================================================================


-- ---------------------------------------------------------------------
-- HELPER IDEMPOTEN
-- Pola yang sama dengan db/update_2026-08-18_iku_revisi_lakip_snapshot.sql:
-- MySQL tidak punya "ALTER TABLE ... ADD COLUMN IF NOT EXISTS", jadi
-- keberadaannya dicek ke information_schema lebih dulu.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _add_col_if_absent;
DROP PROCEDURE IF EXISTS _add_idx_if_absent;
DROP PROCEDURE IF EXISTS _drop_uq_target_tunggal;
DROP PROCEDURE IF EXISTS _tolak_bila_ganda;

DELIMITER $$

CREATE PROCEDURE _add_col_if_absent(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
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

CREATE PROCEDURE _add_idx_if_absent(IN p_table VARCHAR(64), IN p_idx VARCHAR(64), IN p_ddl TEXT)
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table
  ) AND NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_idx
  ) THEN
    SET @sql = p_ddl; PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$

-- Penjaga. Index unik baru hanya boleh dipasang kalau datanya memang sudah
-- memenuhi. Kalau ada duplikat, skrip BERHENTI dengan pesan yang jelas — jauh
-- lebih berguna daripada error 1062 mentah di tengah ALTER, dan tidak ada satu
-- baris pun yang dihapus untuk "membereskannya".
CREATE PROCEDURE _tolak_bila_ganda()
BEGIN
  DECLARE v_ganda INT DEFAULT 0;

  IF EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monev_anggaran' AND COLUMN_NAME = 'ref_key'
  ) THEN
    SELECT COUNT(*) INTO v_ganda FROM (
      SELECT 1 FROM `monev_anggaran`
      GROUP BY `target_rencana_id`, `ref_key`
      HAVING COUNT(*) > 1
    ) d;

    IF v_ganda > 0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'monev_anggaran memuat baris ganda pada (target_rencana_id, ref_key). Rapikan dulu sebelum index unik baru dipasang.';
    END IF;
  END IF;
END$$

-- Membuang UNIQUE tunggal lama pada (target_rencana_id), apa pun namanya.
--
-- Namanya tidak bisa dipastikan satu: berkas .sql pendahulunya memberi nama
-- `uq_monev_anggaran_target`, sedangkan basis data yang dibangun lewat
-- migration Forge (2026-07-27-000003_CreateMonevAnggaranTable) memakai nama
-- bawaan Forge. Karena itu index dicari berdasarkan BENTUKNYA — unik, satu
-- kolom, kolomnya target_rencana_id — bukan berdasarkan namanya.
CREATE PROCEDURE _drop_uq_target_tunggal()
BEGIN
  DECLARE v_idx  VARCHAR(64) DEFAULT NULL;
  DECLARE v_lagi TINYINT DEFAULT 1;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_idx = NULL;

  WHILE v_lagi = 1 DO
    SET v_idx = NULL;

    SELECT s.INDEX_NAME INTO v_idx
      FROM information_schema.STATISTICS s
     WHERE s.TABLE_SCHEMA = DATABASE()
       AND s.TABLE_NAME   = 'monev_anggaran'
       AND s.NON_UNIQUE   = 0
       AND s.INDEX_NAME  <> 'PRIMARY'
     GROUP BY s.INDEX_NAME
    HAVING COUNT(*) = 1 AND MAX(s.COLUMN_NAME) = 'target_rencana_id'
     LIMIT 1;

    IF v_idx IS NULL THEN
      SET v_lagi = 0;
    ELSE
      SET @sql = CONCAT('ALTER TABLE `monev_anggaran` DROP INDEX `', v_idx, '`');
      PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
  END WHILE;
END$$

DELIMITER ;


-- ---------------------------------------------------------------------
-- 1. KOLOM PENAMBAT UNIT
--
-- Keduanya NULLABLE tanpa default, jadi seluruh baris lama tetap sah apa
-- adanya dan seluruh kode lama yang menulis tanpa menyebut kolom ini tetap
-- jalan.
--
-- SENGAJA TANPA FOREIGN KEY ke program_pk / kegiatan_pk / sub_kegiatan_pk.
-- Dua sebab:
--   1. Teknis — `ref_id` adalah DASAR sebuah STORED generated column
--      (`ref_key`), dan MySQL menolak foreign key ber-aksi CASCADE/SET NULL
--      pada kolom semacam itu (error 1215).
--   2. Semantik — satu kolom tidak bisa ber-FK ke tiga tabel berbeda
--      sekaligus. Yang menentukan tabel tujuannya justru `ref_level`.
-- Keutuhan rujukannya dijaga di lapisan aplikasi: unit yang ditawarkan ke
-- operator selalu berasal dari TargetModel::getUnitPkByIndikator.
-- ---------------------------------------------------------------------
CALL _add_col_if_absent('monev_anggaran', 'ref_level',
  'ALTER TABLE `monev_anggaran` ADD COLUMN `ref_level` ENUM(''program'',''kegiatan'',''subkegiatan'') NULL COMMENT ''tingkat unit anggaran; NULL = baris warisan sebelum dirinci per unit'' AFTER `opd_id`');

CALL _add_col_if_absent('monev_anggaran', 'ref_id',
  'ALTER TABLE `monev_anggaran` ADD COLUMN `ref_id` INT UNSIGNED NULL COMMENT ''id tabel MASTER: program_pk.id | kegiatan_pk.id | sub_kegiatan_pk.id (bukan id tabel jembatan)'' AFTER `ref_level`');


-- ---------------------------------------------------------------------
-- 2. KUNCI GABUNGAN `ref_key`
--
-- Bentuknya "{level}:{id}", mis. 'program:12', 'subkegiatan:340'. Baris
-- warisan menjadi ':0' — itulah yang dicari view lewat
-- $anggaranMap[$targetId][':0'].
--
-- VARCHAR(40) berlebih dengan sengaja: label terpanjang 'subkegiatan' (11) +
-- ':' + INT UNSIGNED paling panjang 10 digit = 22 karakter.
-- ---------------------------------------------------------------------
CALL _add_col_if_absent('monev_anggaran', 'ref_key',
  'ALTER TABLE `monev_anggaran` ADD COLUMN `ref_key` VARCHAR(40) GENERATED ALWAYS AS (CONCAT(COALESCE(`ref_level`, ''''), '':'', COALESCE(`ref_id`, 0))) STORED COMMENT ''kunci unit; nilai '''':0'''' berarti baris warisan'' AFTER `ref_id`');


-- ---------------------------------------------------------------------
-- 3. UNIQUE BARU DULU, UNIQUE LAMA BELAKANGAN
--
-- Urutannya WAJIB begini, bukan sebaliknya. `fk_monev_anggaran_target`
-- membutuhkan sebuah index yang DIAWALI kolom target_rencana_id; kalau UNIQUE
-- lama dibuang lebih dulu, MySQL menolak dengan error 1553 karena sesaat tidak
-- ada index yang menopang foreign key itu. UNIQUE baru diawali kolom yang sama,
-- jadi begitu ia terpasang, FK-nya sudah punya penopang dan yang lama boleh
-- pergi.
-- ---------------------------------------------------------------------
CALL _tolak_bila_ganda();

CALL _add_idx_if_absent('monev_anggaran', 'uq_monev_anggaran_unit',
  'ALTER TABLE `monev_anggaran` ADD UNIQUE KEY `uq_monev_anggaran_unit` (`target_rencana_id`, `ref_key`)');

CALL _drop_uq_target_tunggal();


-- ---------------------------------------------------------------------
-- 4. INDEX BANTU
-- Untuk pertanyaan arah sebaliknya: "berapa realisasi program X di seluruh
-- indikator?" — bacaan yang tidak tertolong UNIQUE di atas karena kolom
-- pertamanya target_rencana_id.
-- ---------------------------------------------------------------------
CALL _add_idx_if_absent('monev_anggaran', 'idx_monev_anggaran_ref',
  'ALTER TABLE `monev_anggaran` ADD KEY `idx_monev_anggaran_ref` (`ref_level`, `ref_id`)');


-- ---------------------------------------------------------------------
-- BERSIH-BERSIH
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _add_col_if_absent;
DROP PROCEDURE IF EXISTS _add_idx_if_absent;
DROP PROCEDURE IF EXISTS _drop_uq_target_tunggal;
DROP PROCEDURE IF EXISTS _tolak_bila_ganda;
