-- =====================================================================
-- BUNDEL DEPLOY SERVER — Pringsewu
-- Basis  : esakippringsewu_e-sakip-new(1).sql  (67 tabel, pra-versioning)
-- Isi    : 9 migrasi pending, digabung berurutan.
-- Sifat  : IDEMPOTEN & ADDITIVE. Tidak ada DROP TABLE, DELETE, atau TRUNCATE
--          terhadap data produksi. Aman dijalankan ulang.
--
-- Sudah diuji pada salinan penuh dump server: 9/9 migrasi sukses,
-- 83/83 pemeriksaan postdeploy OK, 0 baris data hilang.
--
-- CARA JALANKAN (backup dulu!):
--   mysqldump -u USER -p NAMA_DB > backup_sebelum_versioning.sql
--   mysql -u USER -p NAMA_DB < db/deploy_2026-08-24_server_pringsewu.sql
--   mysql -u USER -p NAMA_DB -t < db/postdeploy_2026-08-20_versioning.sql
--
-- KALAU LEWAT phpMyAdmin: PILIH DULU DATABASE-nya di sidebar kiri, baru buka
-- tab SQL dari dalam database itu (atau tempel `USE `nama_db`;` di baris
-- pertama). Berkas ini memakai nama tabel tanpa kualifikasi — dijalankan di
-- level server, `permissions` dicari di `information_schema` dan gagal dengan
-- "#1109 - Unknown table 'PERMISSIONS' in information_schema", sementara
-- DATABASE() ikut salah sehingga verifikasi melaporkan GAGAL palsu.
-- =====================================================================



-- #####################################################################
-- ## BERKAS: update_2026-08-18_iku_revisi_lakip_snapshot.sql
-- #####################################################################

-- =====================================================================
-- REVISI IKU  +  SNAPSHOT TAHUNAN LAKIP  +  PENYESUAIAN KEBIJAKAN
-- Tanggal : 2026-08-18
-- Sifat   : IDEMPOTEN (aman dijalankan ulang) & ADDITIVE.
--           Tidak ada tabel/kolom lama yang dihapus atau diubah artinya.
--
-- Jalankan:
--   mysql -u root test_sakip < db/update_2026-08-18_iku_revisi_lakip_snapshot.sql
--
-- =====================================================================
-- PRINSIP: JANGAN MENGUBAH SEJARAH
-- =====================================================================
-- Dokumen sumber (IKU) berumur ~5 tahun dan bisa direvisi di tengah jalan.
-- LAKIP berumur 1 tahun. Sebelum berkas ini, mengedit IKU/Renstra/RPJMD hari
-- ini ikut mengubah makna LAKIP tahun-tahun lampau, karena semuanya dibaca
-- dari satu-satunya salinan data yang hidup.
--
-- Tiga lapis penyelesaiannya:
--
--   1. REVISI IKU      -> versi dokumen yang bernomor & bertanggal berlaku
--   2. SNAPSHOT LAKIP  -> pembekuan seluruh baris LAKIP satu tahun
--   3. PENYESUAIAN     -> koreksi kebijakan khusus tahun LAKIP tsb, tercatat
--
-- =====================================================================
-- MENGAPA TABEL LIVE `iku_*` TETAP DIPAKAI SEBAGAI "VERSI BERLAKU"
-- =====================================================================
-- Ada dua pilihan menaruh "versi yang sedang berlaku":
--   (a) hanya di tabel revisi, tabel live dipensiunkan; atau
--   (b) tabel live SELALU berisi versi yang sedang berlaku, tabel revisi
--       berisi arsip beku + draft usulan.
--
-- Dipilih (b). Alasannya bukan selera, tapi kompatibilitas: `iku_sasaran` /
-- `iku_indikator` / `iku_target` dibaca oleh API publik
-- (Api\PerangkatDaerahController), halaman publik (UserPublicModel), dan tiga
-- model dashboard. Dengan (b) semua pembaca itu TIDAK perlu diubah sama sekali
-- dan tetap melihat angka yang benar.
--
-- Alurnya menjadi:
--   buat revisi  -> isi draft disalin dari kondisi live saat itu (arsip usulan)
--   sahkan       -> isi draft DITERAPKAN ke tabel live, revisi sebelumnya
--                   ditandai superseded, dan arsipnya tetap utuh selamanya
--   LAKIP tahun Y-> membaca ARSIP revisi yang efektif pada tahun Y (beku),
--                   bukan tabel live
--
-- Karena itu draft TIDAK PERNAH menyentuh tabel live (invariant 1 & 5).
-- =====================================================================

-- ---------------------------------------------------------------------
-- HELPER IDEMPOTEN
-- Mengikuti pola yang sudah dipakai db/update_2026-06-29_pk_renaksi.sql dan
-- db/update_2026-06-30_iku_rumusan_sumber.sql. MySQL tidak punya
-- "ALTER TABLE ... ADD COLUMN IF NOT EXISTS", jadi dicek lewat
-- information_schema lebih dulu.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _add_col_if_absent;
DROP PROCEDURE IF EXISTS _drop_col_if_present;
DROP PROCEDURE IF EXISTS _add_idx_if_absent;
DROP PROCEDURE IF EXISTS _add_fk_if_absent;

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

CREATE PROCEDURE _drop_col_if_present(IN p_table VARCHAR(64), IN p_col VARCHAR(64))
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_col
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_table, '` DROP COLUMN `', p_col, '`');
    PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
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

CREATE PROCEDURE _add_fk_if_absent(IN p_table VARCHAR(64), IN p_name VARCHAR(64), IN p_ddl TEXT)
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table
  ) AND NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_table
      AND CONSTRAINT_NAME = p_name AND CONSTRAINT_TYPE = 'FOREIGN KEY'
  ) THEN
    SET @sql = p_ddl; PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$

DELIMITER ;


-- =====================================================================
-- BAGIAN 1 — REVISI IKU
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1.1  KEPALA REVISI
--
-- LIFECYCLE (invariant 1) — sesederhana mungkin, tanpa mesin approval baru:
--
--     draft ──sahkan──> berlaku ──tergeser──> superseded
--       └────batalkan────> batal
--
--   draft      : usulan. TIDAK PERNAH jadi sumber LAKIP, tidak menyentuh live.
--   berlaku    : sudah disahkan & efektif mulai `berlaku_mulai_tahun`.
--   superseded : digantikan revisi yang lebih baru; `berlaku_sampai_tahun`
--                diisi tahun terakhir revisi ini masih dipakai.
--   batal      : draft yang dibuang. Tidak dihapus supaya jejaknya ada.
--
-- ---------------------------------------------------------------------
-- SATU REVISI EFEKTIF (invariant 2 / Case 11) — DIJAMIN OLEH ENGINE
--
-- `berlaku_key` adalah generated column: bernilai 1 hanya bila status
-- 'berlaku', selain itu NULL. MySQL mengabaikan NULL pada UNIQUE index,
-- sehingga:
--   * draft/superseded/batal boleh berapa pun banyaknya;
--   * hanya SATU baris 'berlaku' per (scope, opd, periode, tahun mulai berlaku).
--
-- `opd_key` = COALESCE(opd_id, 0) diperlukan karena kabupaten memakai
-- opd_id NULL, sedangkan MySQL menganggap dua NULL selalu berbeda — tanpa ini
-- UNIQUE-nya tidak akan pernah mengikat untuk tingkat kabupaten.
--
-- Percobaan menyisipkan revisi 'berlaku' kedua akan gagal dengan error 1062.
-- Aplikasi TETAP memeriksa ulang dan menolak dengan pesan administratif
-- (lihat IkuRevisiModel::resolveEfektif) supaya basis data lama yang belum
-- punya index ini juga tidak memilih versi secara diam-diam.
--
-- ---------------------------------------------------------------------
-- MENGAPA `opd_id` DI SINI TIDAK BER-FOREIGN KEY
--
-- Dua sebab, keduanya kebetulan menunjuk arah yang sama:
--
--   1. Teknis. MySQL melarang foreign key ber-aksi CASCADE/SET NULL pada
--      kolom yang menjadi DASAR sebuah stored generated column. `opd_id`
--      adalah dasar `opd_key`, jadi FK-nya akan ditolak (error 1215).
--
--   2. Semantik, dan ini yang menentukan. `iku_sasaran.opd_id` memakai
--      ON DELETE CASCADE — menghapus OPD ikut menghapus IKU-nya. Perilaku itu
--      TIDAK boleh menular ke tabel revisi: arsip yang lenyap bersama sumbernya
--      bukan arsip (invariant 8). Revisi milik OPD yang dibubarkan tetap harus
--      bisa dibaca oleh LAKIP tahun-tahun sebelumnya.
--
-- Indeks biasa pada `opd_id` tetap ada supaya penyaringan per OPD tetap cepat.
-- Tabel snapshot LAKIP memakai pertimbangan yang sama.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `iku_revisi` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `opd_id`               INT UNSIGNED NULL COMMENT 'NULL = IKU tingkat kabupaten; terisi = IKU OPD/Kecamatan',
  `tahun_mulai`          INT NOT NULL COMMENT 'periode IKU induk',
  `tahun_akhir`          INT NOT NULL,
  `nomor`                INT NOT NULL DEFAULT 0 COMMENT '0 = kondisi awal (baseline), 1..n = revisi ke-n',
  `nama`                 VARCHAR(255) NOT NULL COMMENT 'mis. "Revisi ke-1 IKU 2025-2029"',
  `dasar_hukum`          VARCHAR(255) NULL COMMENT 'mis. "Peraturan Bupati"',
  `nomor_dasar`          VARCHAR(150) NULL,
  `tanggal_dasar`        DATE NULL,
  `berlaku_mulai_tahun`  INT NOT NULL COMMENT 'tahun pertama revisi ini dipakai (effective year)',
  `berlaku_sampai_tahun` INT NULL COMMENT 'diisi saat digeser revisi berikutnya; NULL = masih berlaku',
  `status`               VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft | berlaku | superseded | batal',
  `catatan`              TEXT NULL,
  `dibuat_oleh`          INT UNSIGNED NULL,
  `disahkan_oleh`        INT UNSIGNED NULL,
  `disahkan_pada`        DATETIME NULL,
  `dibekukan_pada`       DATETIME NULL COMMENT 'kapan arsip isi revisi ini dibekukan',
  `created_at`           DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  `opd_key`     INT UNSIGNED AS (COALESCE(`opd_id`, 0)) STORED
                COMMENT 'kabupaten = 0; dipakai UNIQUE karena NULL tidak mengikat',
  `berlaku_key` TINYINT AS (CASE WHEN `status` = 'berlaku' THEN 1 ELSE NULL END) STORED
                COMMENT 'NULL untuk non-berlaku supaya UNIQUE hanya mengikat yang berlaku',

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_iku_revisi_efektif`
    (`opd_key`, `tahun_mulai`, `tahun_akhir`, `berlaku_mulai_tahun`, `berlaku_key`),
  UNIQUE KEY `uq_iku_revisi_nomor`
    (`opd_key`, `tahun_mulai`, `tahun_akhir`, `nomor`),
  KEY `idx_iku_revisi_opd`   (`opd_id`),
  KEY `idx_iku_revisi_scope`  (`opd_key`, `tahun_mulai`, `tahun_akhir`, `status`),
  KEY `idx_iku_revisi_efektif` (`status`, `berlaku_mulai_tahun`, `berlaku_sampai_tahun`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ---------------------------------------------------------------------
-- 1.2  ARSIP SASARAN
--
-- `sumber_sasaran_id` menunjuk baris live yang menjadi asal/pasangannya.
-- NULLABLE dan TANPA foreign key yang menghapus: arsip harus selamat walau
-- baris live-nya kelak hilang (invariant 8). Itu inti sebuah arsip.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `iku_revisi_sasaran` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `revisi_id`         INT UNSIGNED NOT NULL,
  `sumber_sasaran_id` INT UNSIGNED NULL COMMENT 'iku_sasaran.id asalnya; NULL = sasaran baru pada revisi ini',
  `sasaran`           TEXT NOT NULL,
  `tahun_mulai`       INT NOT NULL,
  `tahun_akhir`       INT NOT NULL,
  `urutan`            INT NOT NULL DEFAULT 0,
  `jenis_perubahan`   VARCHAR(20) NOT NULL DEFAULT 'tetap' COMMENT 'tetap | revisi | baru | dihentikan',
  `catatan_perubahan` TEXT NULL,
  `created_at`        DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ikurev_sasaran_revisi` (`revisi_id`, `urutan`),
  KEY `idx_ikurev_sasaran_sumber` (`sumber_sasaran_id`),
  CONSTRAINT `fk_ikurev_sasaran_revisi` FOREIGN KEY (`revisi_id`) REFERENCES `iku_revisi` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ---------------------------------------------------------------------
-- 1.3  ARSIP INDIKATOR  — beserta LINEAGE (invariant 4 / Case 14)
--
-- `jenis_perubahan` membedakan tiga hal yang selama ini tercampur:
--   'tetap'      : tidak berubah dari revisi sebelumnya
--   'revisi'     : indikator YANG SAMA, redaksi/target/satuannya disesuaikan
--   'pengganti'  : indikator BARU yang MENGGANTIKAN indikator lama
--                  -> `indikator_sebelumnya_id` WAJIB terisi
--   'baru'       : indikator tambahan, tidak menggantikan apa pun
--   'dihentikan' : tidak dipakai lagi mulai revisi ini
--
-- `perubahan_substansial` = 1 berarti definisi/metodologi berubah sedemikian
-- rupa sehingga angka tahun ini TIDAK sebanding dengan tahun lalu. Dashboard
-- wajib MEMUTUS garis tren di titik ini, bukan menyambungnya begitu saja.
-- Nilai ini disetel manual oleh operator karena hanya dia yang tahu apakah
-- perubahan rumusannya mengubah makna angka.
--
-- `satuan_nama` dibekukan terpisah dari `satuan`. `satuan` bisa berisi id ke
-- tabel master `satuan`; kalau master itu kelak diganti namanya, arsip yang
-- hanya menyimpan id ikut berubah bunyinya. Menyimpan hasil terjemahannya
-- membuat arsip benar-benar beku.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `iku_revisi_indikator` (
  `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `revisi_id`               INT UNSIGNED NOT NULL,
  `revisi_sasaran_id`       INT UNSIGNED NOT NULL,
  `sumber_indikator_id`     INT UNSIGNED NULL COMMENT 'iku_indikator.id asalnya; NULL = indikator baru pada revisi ini',
  `indikator`               TEXT NOT NULL,
  `definisi`                TEXT NULL,
  `rumusan_perhitungan`     TEXT NULL,
  `satuan`                  VARCHAR(50) NULL COMMENT 'id satuan (numerik) atau teks bebas, apa adanya',
  `satuan_nama`             VARCHAR(150) NULL COMMENT 'hasil terjemahan satuan saat dibekukan',
  `sumber_data`             TEXT NULL,
  `penanggung_jawab`        VARCHAR(255) NULL,
  `jenis_indikator`         VARCHAR(100) NULL,
  `baseline`                VARCHAR(50) NULL,
  `urutan`                  INT NOT NULL DEFAULT 0,
  `status`                  VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft | selesai',

  `jenis_perubahan`         VARCHAR(20) NOT NULL DEFAULT 'tetap'
                            COMMENT 'tetap | revisi | pengganti | baru | dihentikan',
  `indikator_sebelumnya_id` INT UNSIGNED NULL
                            COMMENT 'iku_indikator.id yang digantikan; wajib bila jenis_perubahan = pengganti',
  `perubahan_substansial`   TINYINT(1) NOT NULL DEFAULT 0
                            COMMENT '1 = definisi/metodologi berubah, tren antar tahun TIDAK boleh disambung',
  `catatan_perubahan`       TEXT NULL,

  `created_at`              DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ikurev_ind_revisi`  (`revisi_id`, `urutan`),
  KEY `idx_ikurev_ind_sasaran` (`revisi_sasaran_id`, `urutan`),
  KEY `idx_ikurev_ind_sumber`  (`sumber_indikator_id`),
  KEY `idx_ikurev_ind_lineage` (`indikator_sebelumnya_id`),
  CONSTRAINT `fk_ikurev_ind_revisi` FOREIGN KEY (`revisi_id`) REFERENCES `iku_revisi` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ikurev_ind_sasaran` FOREIGN KEY (`revisi_sasaran_id`) REFERENCES `iku_revisi_sasaran` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ---------------------------------------------------------------------
-- 1.4  ARSIP TARGET TAHUNAN
--
-- UNIQUE-nya per (indikator arsip, tahun) — sama seperti `iku_target`. Yang
-- membuat target satu tahun bisa punya beberapa nilai sepanjang waktu adalah
-- ADANYA BEBERAPA REVISI, bukan beberapa baris dalam satu revisi. Karena itu
-- UNIQUE `iku_target` yang lama tidak perlu dibongkar sama sekali.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `iku_revisi_target` (
  `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `revisi_indikator_id`    INT UNSIGNED NOT NULL,
  `tahun`                  INT NOT NULL,
  `target`                 VARCHAR(100) NULL,
  `target_sebelumnya`      VARCHAR(100) NULL COMMENT 'nilai pada revisi sebelumnya, untuk tampilan perbandingan',
  `created_at`             DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ikurev_target` (`revisi_indikator_id`, `tahun`),
  CONSTRAINT `fk_ikurev_target_ind` FOREIGN KEY (`revisi_indikator_id`)
    REFERENCES `iku_revisi_indikator` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ---------------------------------------------------------------------
-- 1.5  ARSIP PROGRAM PENDUKUNG
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `iku_revisi_program` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `revisi_indikator_id` INT UNSIGNED NOT NULL,
  `program`             TEXT NOT NULL,
  `urutan`              INT NOT NULL DEFAULT 0,
  `created_at`          DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ikurev_program_ind` (`revisi_indikator_id`, `urutan`),
  CONSTRAINT `fk_ikurev_program_ind` FOREIGN KEY (`revisi_indikator_id`)
    REFERENCES `iku_revisi_indikator` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ---------------------------------------------------------------------
-- 1.6  KOLOM TAMBAHAN PADA TABEL LIVE
--
-- Semua NULLABLE / ber-default sehingga 75 sasaran + 97 indikator yang sudah
-- ada tidak berubah perilakunya sedikit pun.
--
-- `dihentikan_pada` adalah pengganti DELETE (invariant 8). Sebelum ini,
-- IkuModel::updateComplete menghapus indikator yang hilang dari form, dan
-- target/programnya ikut lenyap lewat FK ON DELETE CASCADE — itulah tempat
-- sejarah selama ini rusak. Sesudah ini, indikator yang sudah direferensikan
-- arsip revisi atau snapshot LAKIP hanya dipensiunkan, tidak dihapus.
-- ---------------------------------------------------------------------
CALL _add_col_if_absent('iku_sasaran', 'revisi_id',
  'ALTER TABLE `iku_sasaran` ADD COLUMN `revisi_id` INT UNSIGNED NULL COMMENT ''revisi terakhir yang mengubah baris ini'' AFTER `urutan`');
CALL _add_col_if_absent('iku_sasaran', 'berlaku_sampai',
  'ALTER TABLE `iku_sasaran` ADD COLUMN `berlaku_sampai` INT NULL COMMENT ''tahun terakhir sasaran ini dipakai; NULL = masih berlaku'' AFTER `revisi_id`');
CALL _add_col_if_absent('iku_sasaran', 'dihentikan_pada',
  'ALTER TABLE `iku_sasaran` ADD COLUMN `dihentikan_pada` DATETIME NULL COMMENT ''terisi = dipensiunkan, bukan dihapus'' AFTER `berlaku_sampai`');
CALL _add_col_if_absent('iku_sasaran', 'alasan_dihentikan',
  'ALTER TABLE `iku_sasaran` ADD COLUMN `alasan_dihentikan` TEXT NULL AFTER `dihentikan_pada`');

CALL _add_col_if_absent('iku_indikator', 'revisi_id',
  'ALTER TABLE `iku_indikator` ADD COLUMN `revisi_id` INT UNSIGNED NULL COMMENT ''revisi terakhir yang mengubah baris ini'' AFTER `status`');
CALL _add_col_if_absent('iku_indikator', 'indikator_sebelumnya_id',
  'ALTER TABLE `iku_indikator` ADD COLUMN `indikator_sebelumnya_id` INT UNSIGNED NULL COMMENT ''indikator yang digantikan baris ini (lineage)'' AFTER `revisi_id`');
CALL _add_col_if_absent('iku_indikator', 'jenis_perubahan',
  'ALTER TABLE `iku_indikator` ADD COLUMN `jenis_perubahan` VARCHAR(20) NOT NULL DEFAULT ''tetap'' COMMENT ''tetap | revisi | pengganti | baru | dihentikan'' AFTER `indikator_sebelumnya_id`');
CALL _add_col_if_absent('iku_indikator', 'perubahan_substansial',
  'ALTER TABLE `iku_indikator` ADD COLUMN `perubahan_substansial` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = tren antar tahun tidak boleh disambung'' AFTER `jenis_perubahan`');
CALL _add_col_if_absent('iku_indikator', 'berlaku_sampai',
  'ALTER TABLE `iku_indikator` ADD COLUMN `berlaku_sampai` INT NULL COMMENT ''tahun terakhir indikator ini dipakai; NULL = masih berlaku'' AFTER `perubahan_substansial`');
CALL _add_col_if_absent('iku_indikator', 'dihentikan_pada',
  'ALTER TABLE `iku_indikator` ADD COLUMN `dihentikan_pada` DATETIME NULL COMMENT ''terisi = dipensiunkan, bukan dihapus'' AFTER `berlaku_sampai`');
CALL _add_col_if_absent('iku_indikator', 'alasan_dihentikan',
  'ALTER TABLE `iku_indikator` ADD COLUMN `alasan_dihentikan` TEXT NULL AFTER `dihentikan_pada`');

CALL _add_idx_if_absent('iku_sasaran', 'idx_iku_sasaran_aktif',
  'ALTER TABLE `iku_sasaran` ADD KEY `idx_iku_sasaran_aktif` (`dihentikan_pada`)');
CALL _add_idx_if_absent('iku_indikator', 'idx_iku_indikator_aktif',
  'ALTER TABLE `iku_indikator` ADD KEY `idx_iku_indikator_aktif` (`dihentikan_pada`)');
CALL _add_idx_if_absent('iku_indikator', 'idx_iku_indikator_lineage',
  'ALTER TABLE `iku_indikator` ADD KEY `idx_iku_indikator_lineage` (`indikator_sebelumnya_id`)');

-- Lineage menunjuk ke tabel yang sama. ON DELETE SET NULL, bukan CASCADE:
-- kalau indikator lama toh terhapus, penggantinya harus tetap hidup.
CALL _add_fk_if_absent('iku_indikator', 'fk_iku_indikator_sebelumnya',
  'ALTER TABLE `iku_indikator` ADD CONSTRAINT `fk_iku_indikator_sebelumnya` FOREIGN KEY (`indikator_sebelumnya_id`) REFERENCES `iku_indikator` (`id`) ON DELETE SET NULL ON UPDATE CASCADE');
CALL _add_fk_if_absent('iku_sasaran', 'fk_iku_sasaran_revisi',
  'ALTER TABLE `iku_sasaran` ADD CONSTRAINT `fk_iku_sasaran_revisi` FOREIGN KEY (`revisi_id`) REFERENCES `iku_revisi` (`id`) ON DELETE SET NULL ON UPDATE CASCADE');
CALL _add_fk_if_absent('iku_indikator', 'fk_iku_indikator_revisi',
  'ALTER TABLE `iku_indikator` ADD CONSTRAINT `fk_iku_indikator_revisi` FOREIGN KEY (`revisi_id`) REFERENCES `iku_revisi` (`id`) ON DELETE SET NULL ON UPDATE CASCADE');


-- =====================================================================
-- BAGIAN 2 — SNAPSHOT TAHUNAN LAKIP
-- =====================================================================

-- ---------------------------------------------------------------------
-- 2.1  KEPALA SNAPSHOT
--
-- SATU SNAPSHOT AKTIF (invariant 3 / Case 12) — DIJAMIN OLEH ENGINE
-- Pola `aktif_key` sama dengan `berlaku_key` di atas: hanya baris aktif yang
-- ikut UNIQUE. Versi lama tetap tersimpan dengan aktif = 0.
--
-- Karena itu menekan "Siapkan LAKIP" untuk tahun yang sudah punya snapshot
-- TIDAK menghasilkan snapshot kedua. Controller mendeteksinya lebih dulu dan
-- menawarkan Lihat / Bandingkan / Sinkronkan.
--
-- `status`:
--   draft : boleh disinkronkan ulang dari data hidup
--   final : DIKUNCI. Tidak boleh auto-sync, tidak boleh regenerate, tidak
--           boleh diedit destruktif (invariant 6). Perubahan sesudah ini
--           hanya lewat `lakip_penyesuaian` yang tercatat.
--
-- `sumber_iku_revisi_id` mencatat revisi IKU mana yang efektif saat snapshot
-- diambil, sehingga angka LAKIP selalu bisa ditelusuri ke versi dokumennya.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lakip_snapshot` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tahun`                YEAR NOT NULL,
  `mode`                 VARCHAR(12) NOT NULL COMMENT 'kabupaten (RPJMD) | opd (RENSTRA)',
  `opd_id`               INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = tingkat kabupaten, sejalan pola tabel monev',
  `versi`                INT NOT NULL DEFAULT 1,
  `label`                VARCHAR(255) NULL,
  `status`               VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft | final',
  `aktif`                TINYINT(1) NOT NULL DEFAULT 1,
  `sumber_iku_revisi_id` INT UNSIGNED NULL COMMENT 'revisi IKU yang efektif saat snapshot diambil',
  `jumlah_baris`         INT NOT NULL DEFAULT 0,
  `filter_status`        VARCHAR(50) NULL COMMENT 'filter status LAKIP yang berlaku saat dibekukan; kosong = tanpa filter',
  `catatan`              TEXT NULL,
  `dibuat_oleh`          INT UNSIGNED NULL,
  `dibuat_pada`          DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `disinkronkan_oleh`    INT UNSIGNED NULL,
  `disinkronkan_pada`    DATETIME NULL,
  `difinalkan_oleh`      INT UNSIGNED NULL,
  `difinalkan_pada`      DATETIME NULL,
  `updated_at`           DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  `aktif_key` TINYINT AS (CASE WHEN `aktif` = 1 THEN 1 ELSE NULL END) STORED
              COMMENT 'NULL untuk versi lama supaya UNIQUE hanya mengikat yang aktif',

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lakip_snapshot_aktif` (`tahun`, `mode`, `opd_id`, `aktif_key`),
  UNIQUE KEY `uq_lakip_snapshot_versi` (`tahun`, `mode`, `opd_id`, `versi`),
  KEY `idx_lakip_snapshot_lingkup` (`tahun`, `mode`, `opd_id`, `status`),
  KEY `idx_lakip_snapshot_revisi`  (`sumber_iku_revisi_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ---------------------------------------------------------------------
-- 2.2  BARIS SNAPSHOT — salinan beku satu baris LAKIP
--
-- SENGAJA TANPA FOREIGN KEY ke renstra_target / rpjmd_target / lakip.
-- Id-id itu disimpan sebagai penunjuk penelusuran saja. Sebuah arsip yang
-- ikut terhapus saat sumbernya dihapus bukan arsip. Bandingkan dengan tabel
-- `lakip` yang memakai ON DELETE SET NULL — di sana itu benar, di sini tidak.
--
-- Kolomnya sengaja mencerminkan persis apa yang tercetak di kertas, supaya
-- view cetak yang sudah ada bisa disuapi struktur array yang sama.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lakip_snapshot_baris` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `snapshot_id`           INT UNSIGNED NOT NULL,
  `urutan`                INT NOT NULL DEFAULT 0,

  `sumber`                VARCHAR(12) NOT NULL COMMENT 'renstra | rpjmd',
  `renstra_target_id`     INT UNSIGNED NULL COMMENT 'penunjuk penelusuran, tanpa FK (arsip harus selamat)',
  `rpjmd_target_id`       INT UNSIGNED NULL,
  `lakip_id`              INT UNSIGNED NULL,

  `opd_id`                INT UNSIGNED NOT NULL DEFAULT 0,
  `nama_opd`              VARCHAR(255) NULL,

  `sasaran_id`            INT UNSIGNED NULL,
  `sasaran`               TEXT NULL,
  `indikator_id`          INT UNSIGNED NULL,
  `indikator`             TEXT NULL,
  `satuan`                VARCHAR(150) NULL COMMENT 'sudah diterjemahkan, bukan id',
  `jenis_indikator`       VARCHAR(100) NULL,

  `target`                VARCHAR(255) NULL,
  `target_hitung`         VARCHAR(255) NULL,
  `target_lalu`           VARCHAR(255) NULL,
  `capaian_lalu`          VARCHAR(255) NULL,
  `realisasi`             VARCHAR(255) NULL COMMENT 'capaian_tahun_ini pada tabel lakip',
  `capaian_hitung`        VARCHAR(255) NULL,
  `status_lakip`          VARCHAR(50) NULL,

  `iku_indikator_id`      INT UNSIGNED NULL COMMENT 'penambat ke IKU bila indikatornya sepadan',
  `iku_revisi_indikator_id` INT UNSIGNED NULL COMMENT 'baris arsip revisi IKU yang dipakai',
  `perubahan_substansial` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'diwarisi dari revisi IKU; pemutus garis tren',

  `created_at`            DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_snapbaris_snapshot` (`snapshot_id`, `urutan`),
  KEY `idx_snapbaris_renstra`  (`renstra_target_id`),
  KEY `idx_snapbaris_rpjmd`    (`rpjmd_target_id`),
  KEY `idx_snapbaris_iku`      (`iku_indikator_id`),
  CONSTRAINT `fk_snapbaris_snapshot` FOREIGN KEY (`snapshot_id`) REFERENCES `lakip_snapshot` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ---------------------------------------------------------------------
-- 2.3  PROGRAM & ANGGARAN BEKU
--     (padanan lakip_efisiensi_program, tapi di dalam snapshot)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lakip_snapshot_program` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `snapshot_id` INT UNSIGNED NOT NULL,
  `urutan`      INT NOT NULL DEFAULT 0,
  `program_id`  INT UNSIGNED NULL COMMENT 'program_pk.id, penunjuk saja tanpa FK',
  `program`     TEXT NULL,
  `anggaran`    DECIMAL(20,2) NOT NULL DEFAULT 0,
  `realisasi`   DECIMAL(20,2) NOT NULL DEFAULT 0,
  `efisiensi`   DECIMAL(20,2) NOT NULL DEFAULT 0,
  `created_at`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_snapprogram_snapshot` (`snapshot_id`, `urutan`),
  CONSTRAINT `fk_snapprogram_snapshot` FOREIGN KEY (`snapshot_id`) REFERENCES `lakip_snapshot` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ---------------------------------------------------------------------
-- 2.4  ANALISIS FAKTOR BEKU
--
-- Tabel terpisah, BUKAN kolom pada `lakip_snapshot_baris`, karena satu
-- indikator boleh punya BANYAK baris analisis — persis seperti tabel asalnya
-- `lakip_analisis_faktor` yang memang one-to-many.
--
-- Menyimpannya sebagai tiga kolom pada baris snapshot akan diam-diam membuang
-- analisis kedua dan seterusnya saat dokumen dibekukan.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lakip_snapshot_analisis` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `snapshot_id`       INT UNSIGNED NOT NULL,
  `snapshot_baris_id` INT UNSIGNED NOT NULL,
  `urutan`            INT NOT NULL DEFAULT 0,
  `sumber_analisis_id` INT UNSIGNED NULL COMMENT 'lakip_analisis_faktor.id asalnya, penunjuk saja tanpa FK',
  `faktor_pendukung`  TEXT NULL,
  `faktor_penghambat` TEXT NULL,
  `upaya_peningkatan` TEXT NULL,
  `created_at`        DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_snapanalisis_snapshot` (`snapshot_id`, `urutan`),
  KEY `idx_snapanalisis_baris`    (`snapshot_baris_id`, `urutan`),
  CONSTRAINT `fk_snapanalisis_snapshot` FOREIGN KEY (`snapshot_id`)
    REFERENCES `lakip_snapshot` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_snapanalisis_baris` FOREIGN KEY (`snapshot_baris_id`)
    REFERENCES `lakip_snapshot_baris` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ---------------------------------------------------------------------
-- 2.5  PENYELARASAN UNTUK BASIS DATA YANG SUDAH MENJALANKAN VERSI AWAL
--      BERKAS INI. CREATE TABLE IF NOT EXISTS tidak menyentuh tabel yang
--      sudah ada, jadi perubahan bentuknya dilakukan lewat ALTER terjaga.
-- ---------------------------------------------------------------------
CALL _add_col_if_absent('lakip_snapshot', 'filter_status',
  'ALTER TABLE `lakip_snapshot` ADD COLUMN `filter_status` VARCHAR(50) NULL COMMENT ''filter status LAKIP saat dibekukan'' AFTER `jumlah_baris`');

-- Ketiganya pindah ke `lakip_snapshot_analisis` (one-to-many).
CALL _drop_col_if_present('lakip_snapshot_baris', 'faktor_pendukung');
CALL _drop_col_if_present('lakip_snapshot_baris', 'faktor_penghambat');
CALL _drop_col_if_present('lakip_snapshot_baris', 'upaya_peningkatan');


-- =====================================================================
-- BAGIAN 3 — PENYESUAIAN KEBIJAKAN LAKIP
-- =====================================================================

-- ---------------------------------------------------------------------
-- Penyesuaian adalah PENGECUALIAN, bukan cara normal mengubah perencanaan
-- (invariant 5). Karena itu:
--
--   * `dasar_kebijakan` dan `alasan` WAJIB terisi (dipaksa di lapisan aplikasi;
--     di sini NOT NULL supaya tidak bisa disusupi lewat jalur lain);
--   * `setelah_final` = 1 dicatat otomatis bila penyesuaian dibuat setelah
--     snapshot difinalkan (invariant 6) — angkanya boleh berubah, tapi
--     kenyataan bahwa itu terjadi sesudah finalisasi tidak boleh hilang;
--   * `usul_revisi_iku` + `iku_revisi_id` menampung Case 15: memilih
--     "Usulkan sebagai Perubahan IKU" hanya MEMBUAT DRAFT revisi. Tidak ada
--     satu pun jalur di berkas ini yang mengubah IKU aktif.
--
-- `nilai_asli` dibekukan saat penyesuaian dibuat supaya "sebelum vs sesudah"
-- tetap terbaca walau sumbernya kelak berubah lagi.
--
-- UNIQUE-nya memakai pola generated column yang sama: satu penyesuaian AKTIF
-- per (lingkup, target, jenis). Penyesuaian yang digantikan disimpan dengan
-- aktif = 0, bukan dihapus.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lakip_penyesuaian` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tahun`             YEAR NOT NULL,
  `mode`              VARCHAR(12) NOT NULL COMMENT 'kabupaten | opd',
  `opd_id`            INT UNSIGNED NOT NULL DEFAULT 0,

  `renstra_target_id` INT UNSIGNED NULL,
  `rpjmd_target_id`   INT UNSIGNED NULL,
  `snapshot_id`       INT UNSIGNED NULL COMMENT 'snapshot yang berlaku saat penyesuaian dibuat',
  `snapshot_baris_id` INT UNSIGNED NULL,

  `jenis`             VARCHAR(20) NOT NULL COMMENT 'target | realisasi | satuan | indikator | lainnya',
  `nilai_asli`        VARCHAR(255) NULL COMMENT 'dibekukan saat penyesuaian dibuat',
  `nilai_disesuaikan` VARCHAR(255) NULL,

  `dasar_kebijakan`   VARCHAR(255) NOT NULL COMMENT 'mis. "Peraturan Bupati tentang Refocusing Anggaran"',
  `nomor_dasar`       VARCHAR(150) NULL,
  `tanggal_dasar`     DATE NULL,
  `alasan`            TEXT NOT NULL,

  `setelah_final`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = dibuat setelah snapshot difinalkan',
  `usul_revisi_iku`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = diusulkan berlaku juga untuk tahun berikutnya',
  `iku_revisi_id`     INT UNSIGNED NULL COMMENT 'draft revisi IKU yang lahir dari usulan ini',

  `aktif`             TINYINT(1) NOT NULL DEFAULT 1,
  `dibuat_oleh`       INT UNSIGNED NULL,
  `created_at`        DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  `target_key` INT UNSIGNED AS (COALESCE(`renstra_target_id`, `rpjmd_target_id`, 0)) STORED,
  `aktif_key`  TINYINT AS (CASE WHEN `aktif` = 1 THEN 1 ELSE NULL END) STORED,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lakip_penyesuaian_aktif` (`tahun`, `mode`, `opd_id`, `target_key`, `jenis`, `aktif_key`),
  KEY `idx_penyesuaian_lingkup` (`tahun`, `mode`, `opd_id`),
  KEY `idx_penyesuaian_snapshot` (`snapshot_id`),
  KEY `idx_penyesuaian_revisi`   (`iku_revisi_id`),
  CONSTRAINT `fk_penyesuaian_revisi` FOREIGN KEY (`iku_revisi_id`) REFERENCES `iku_revisi` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- =====================================================================
-- BAGIAN 4 — PERMISSION
-- =====================================================================
-- Mengikuti pola penamaan yang sudah ada (iku_kab.create, lakip_opd.update, ...).
-- Aksi baru dipisah dari aksi lama supaya bisa diberikan ke sebagian role saja:
-- mengunci LAKIP dan mengesahkan revisi bukan pekerjaan operator harian.
--
-- Role read-only (bupati, admin_inspektorat) SENGAJA tidak diberi satu pun
-- permission di bawah ini.
-- =====================================================================
INSERT IGNORE INTO `permissions` (`name`, `label`, `grup`) VALUES
  ('iku_kab.revisi',        'IKU Kabupaten - Buat & Ubah Revisi',              'Kabupaten'),
  ('iku_kab.revisi_sahkan', 'IKU Kabupaten - Sahkan Revisi',                  'Kabupaten'),
  ('iku_opd.revisi',        'IKU OPD - Buat & Ubah Revisi',                   'OPD'),
  ('iku_opd.revisi_sahkan', 'IKU OPD - Sahkan Revisi',                        'OPD'),
  ('lakip_kab.snapshot',    'LAKIP Kabupaten - Siapkan & Sinkronkan Snapshot', 'Kabupaten'),
  ('lakip_kab.finalisasi',  'LAKIP Kabupaten - Finalkan / Kunci Tahun',        'Kabupaten'),
  ('lakip_kab.penyesuaian', 'LAKIP Kabupaten - Penyesuaian Kebijakan',         'Kabupaten'),
  ('lakip_opd.snapshot',    'LAKIP OPD - Siapkan & Sinkronkan Snapshot',       'OPD'),
  ('lakip_opd.finalisasi',  'LAKIP OPD - Finalkan / Kunci Tahun',              'OPD'),
  ('lakip_opd.penyesuaian', 'LAKIP OPD - Penyesuaian Kebijakan',               'OPD');

-- admin_kab: seluruh aksi tingkat kabupaten
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r JOIN `permissions` p
WHERE r.name = 'admin_kab'
  AND p.name IN ('iku_kab.revisi', 'iku_kab.revisi_sahkan',
                 'lakip_kab.snapshot', 'lakip_kab.finalisasi', 'lakip_kab.penyesuaian');

-- admin_opd & admin_kecamatan: aksi tingkat OPD.
-- Mengesahkan revisi IKU OPD-nya sendiri ikut diberikan karena proyek ini
-- tidak punya mesin approval berjenjang (invariant 1: pakai yang paling
-- sederhana, draft -> berlaku). Cabut lewat menu Manajemen Peran bila
-- kebijakan daerah menghendaki pengesahan terpusat.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r JOIN `permissions` p
WHERE r.name IN ('admin_opd', 'admin_kecamatan')
  AND p.name IN ('iku_opd.revisi', 'iku_opd.revisi_sahkan',
                 'lakip_opd.snapshot', 'lakip_opd.finalisasi', 'lakip_opd.penyesuaian');


-- ---------------------------------------------------------------------
-- BERSIH-BERSIH
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _add_col_if_absent;
DROP PROCEDURE IF EXISTS _drop_col_if_present;
DROP PROCEDURE IF EXISTS _add_idx_if_absent;
DROP PROCEDURE IF EXISTS _add_fk_if_absent;


-- #####################################################################
-- ## BERKAS: update_2026-08-19_monev_anggaran_per_unit.sql
-- #####################################################################

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


-- #####################################################################
-- ## BERKAS: update_2026-08-20_versioning_dokumen.sql
-- #####################################################################

-- =====================================================================
-- VERSIONING DOKUMEN — RPJMD, RENSTRA, IKU & LAKIP
-- Tanggal : 2026-08-20
-- Acuan   : MASTER_PROMPT_VERSIONING_ESAKIP.md (82 bagian)
-- Sifat   : IDEMPOTEN & ADDITIVE.
--           Tidak ada tabel/kolom lama yang dihapus atau diubah artinya.
--           Tidak ada FOREIGN KEY lama yang diubah aturannya.
--           Tidak ada TRUNCATE / DELETE data historis (larangan §81).
--
-- PRASYARAT — WAJIB dijalankan lebih dulu:
--   mysql -u root -p "e-sakip_6" < db/update_2026-08-18_iku_revisi_lakip_snapshot.sql
--
-- Urutan lengkap:
--   1) db/preflight_2026-08-20_versioning.sql      (periksa, tidak mengubah)
--   2) db/update_2026-08-18_iku_revisi_lakip_snapshot.sql
--   3) db/update_2026-08-20_versioning_dokumen.sql (berkas ini)
--   4) db/postdeploy_2026-08-20_versioning.sql     (validasi, tidak mengubah)
--
-- =====================================================================
-- WAJIB: --default-character-set=utf8mb4
-- =====================================================================
--   mysql -u root -p --default-character-set=utf8mb4 "e-sakip_6" < berkas.sql
--
-- Tanpa opsi itu, klien mysql memakai CODEPAGE KONSOL (CP850/CP437 di Windows)
-- dan tanda "—" pada label baseline tersimpan ganda-encode menjadi "ÔÇö".
-- HeidiSQL sudah memakai utf8mb4 secara bawaan, jadi lewat sana aman.
--
-- Bila terlanjur tersimpan salah, perbaiki dengan:
--   SET @salah = CONVERT(UNHEX('C394C387C3B6') USING utf8mb4) COLLATE utf8mb4_general_ci;
--   SET @benar = CONVERT(UNHEX('E28094') USING utf8mb4) COLLATE utf8mb4_general_ci;
--   UPDATE dokumen_versi SET label = REPLACE(label, @salah, @benar) WHERE INSTR(label, @salah) > 0;
--   UPDATE version_submission_history SET ringkasan = REPLACE(ringkasan, @salah, @benar)
--    WHERE INSTR(COALESCE(ringkasan,''), @salah) > 0;
--
-- =====================================================================
-- CATATAN DESAIN: SATU REGISTRI, BUKAN EMPAT  (§51)
-- =====================================================================
-- §51 menyebut kemungkinan rpjmd_versions / renstra_versions / iku_versions /
-- lakip_versions, TETAPI juga melarang "membuat tabel duplikat dengan fungsi
-- sama". Keempatnya akan punya kolom, status, timeline, dan alur approval yang
-- identik — hanya ISI arsipnya yang berbeda.
--
-- Karena itu dipakai SATU tabel kepala `dokumen_versi` dengan diskriminator
-- `modul`, dan §76 (VersionResolver reusable) terlayani satu implementasi saja.
-- Arsip isi tetap terpisah karena bentuknya memang berbeda:
--
--   rpjmd    -> rpjmd_versi_*        (BARU)
--   renstra  -> renstra_versi_*      (BARU)
--   iku      -> iku_revisi_*         (SUDAH ADA — tidak diubah)
--   lakip    -> lakip_snapshot_*     (SUDAH ADA — tidak diubah)
--
-- `ref_id` menunjuk kepala arsip lama sehingga IkuRevisiModel dan
-- LakipSnapshotModel yang sudah ditulis tetap bekerja apa adanya (§42).
--
-- =====================================================================
-- MODEL WAKTU  (§3)
-- =====================================================================
--   created_at      -> kapan barisnya dibuat. TIDAK PERNAH dipakai resolusi.
--   version_no      -> nomor administratif. BUKAN penentu waktu berlaku (§2.6).
--   effective_from  -> DATE. SATU-SATUNYA penentu timeline (§2.7).
--   effective_to    -> DATE. DIHITUNG SISTEM dari published berikutnya (§3),
--                      bukan diedit bebas oleh user.
--
-- Interval SETENGAH TERBUKA [effective_from, effective_to) supaya tidak ada
-- satu tanggal pun yang dimiliki dua versi.
--
-- CATATAN: §26 menulis resolver "effective_to >= referenceDate OR NULL".
-- Di sini dipakai "effective_to > referenceDate" (eksklusif). Keduanya memberi
-- hasil sama untuk semua kasus di §60/§61, tetapi versi eksklusif menghindari
-- ambiguitas pada tanggal batas — pada §61, 2026-03-15 harus menghasilkan V2,
-- dan dengan ">=" tanggal itu dimiliki V1 DAN V2 sekaligus.
--
-- Status (§7) — TIDAK menyimpan historical/current/upcoming:
--   draft | pending_approval | published | cancelled
--   published + belum mulai  -> UPCOMING    (dihitung)
--   published + dalam range  -> CURRENT     (dihitung)
--   published + sudah lewat  -> HISTORICAL  (dihitung)
--
-- =====================================================================
-- PENEGAKAN INVARIANT DI LEVEL ENGINE  (§6, §62)
-- =====================================================================
-- MySQL tidak punya exclusion constraint. Dicapai lewat generated column:
--
--   published_key = CASE WHEN status='published' THEN 1 ELSE NULL END
--   terbuka_key   = CASE WHEN status='published' AND effective_to IS NULL
--                        THEN 1 ELSE NULL END
--
--   UNIQUE (modul, scope_key, opd_key, periode_mulai, periode_akhir, version_no)
--   UNIQUE (modul, scope_key, opd_key, periode_mulai, periode_akhir,
--           effective_from, published_key)   <-- §6 & §62
--   UNIQUE (modul, scope_key, opd_key, periode_mulai, periode_akhir,
--           terbuka_key)                     <-- tepat SATU versi terbuka
--
-- MySQL mengabaikan NULL pada UNIQUE, sehingga draft/cancelled bebas berapa pun.
--
-- ATURAN URUTAN SAAT RECALC TIMELINE (§3, §60) — WAJIB DIPATUHI SERVICE:
--   Slot "terbuka" hanya boleh dimiliki satu baris. Karena UNIQUE MySQL
--   diperiksa per-statement (bukan deferred), recalculation HARUS
--   MELEPAS slot terbuka SEBELUM MENGKLAIMNYA:
--
--     tutup dulu versi yang sedang terbuka  ->  baru buka versi penggantinya
--
--   Penyisipan retrospektif (§60: V3 efektif 2026-07-01 di antara V1 dan V2)
--   TIDAK menyentuh slot terbuka sama sekali — V3 lahir sudah ber-effective_to,
--   dan V2 tetap yang terbuka. Jadi tidak ada bentrokan transien.
--
-- opd_key = COALESCE(opd_id,0) diperlukan karena tingkat kabupaten memakai
-- opd_id NULL, dan MySQL menganggap dua NULL selalu berbeda.
-- =====================================================================


-- ---------------------------------------------------------------------
-- HELPER IDEMPOTEN
-- Mengikuti pola db/update_2026-08-18_iku_revisi_lakip_snapshot.sql.
-- MySQL tidak punya "ALTER TABLE ... ADD COLUMN IF NOT EXISTS".
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _dv_add_col_if_absent;
DROP PROCEDURE IF EXISTS _dv_add_idx_if_absent;
DROP PROCEDURE IF EXISTS _dv_add_fk_if_absent;
DROP PROCEDURE IF EXISTS _dv_pensiun_kolom;
DROP PROCEDURE IF EXISTS _dv_cek_prasyarat;

DELIMITER $$

CREATE PROCEDURE _dv_add_col_if_absent(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
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

CREATE PROCEDURE _dv_add_idx_if_absent(IN p_table VARCHAR(64), IN p_idx VARCHAR(64), IN p_ddl TEXT)
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

CREATE PROCEDURE _dv_add_fk_if_absent(IN p_table VARCHAR(64), IN p_name VARCHAR(64), IN p_ddl TEXT)
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table
  ) AND NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_table
      AND CONSTRAINT_NAME = p_name AND CONSTRAINT_TYPE = 'FOREIGN KEY'
  ) THEN
    SET @sql = p_ddl; PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$

-- Empat kolom "pensiun, bukan hapus" untuk satu tabel live (§2.4, §81).
CREATE PROCEDURE _dv_pensiun_kolom(IN p_table VARCHAR(64))
BEGIN
  CALL _dv_add_col_if_absent(p_table, 'version_id',
    CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `version_id` INT UNSIGNED NULL COMMENT ''dokumen_versi terakhir yang mengubah baris ini'''));
  CALL _dv_add_col_if_absent(p_table, 'berlaku_sampai',
    CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `berlaku_sampai` INT NULL COMMENT ''tahun terakhir dipakai; NULL = masih berlaku'''));
  CALL _dv_add_col_if_absent(p_table, 'dihentikan_pada',
    CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `dihentikan_pada` DATETIME NULL COMMENT ''terisi = dipensiunkan, BUKAN dihapus'''));
  CALL _dv_add_col_if_absent(p_table, 'alasan_dihentikan',
    CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `alasan_dihentikan` TEXT NULL'));
  CALL _dv_add_idx_if_absent(p_table, CONCAT('idx_', p_table, '_aktif'),
    CONCAT('ALTER TABLE `', p_table, '` ADD KEY `idx_', p_table, '_aktif` (`dihentikan_pada`)'));
  CALL _dv_add_idx_if_absent(p_table, CONCAT('idx_', p_table, '_versi'),
    CONCAT('ALTER TABLE `', p_table, '` ADD KEY `idx_', p_table, '_versi` (`version_id`)'));
END$$

-- Berhenti dengan pesan jelas bila prasyarat belum ada, daripada gagal di
-- tengah dan meninggalkan skema separuh jadi.
CREATE PROCEDURE _dv_cek_prasyarat()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'iku_revisi'
  ) OR NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lakip_snapshot'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'PRASYARAT BELUM ADA: jalankan db/update_2026-08-18_iku_revisi_lakip_snapshot.sql lebih dulu.';
  END IF;
END$$

DELIMITER ;

CALL _dv_cek_prasyarat();


-- =====================================================================
-- BAGIAN 1 — REGISTRI VERSI LINTAS DOKUMEN  (§3, §7, §8)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `dokumen_versi` (
  `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- ---------- identitas dokumen (§12, §13, §14) ----------
  `modul`                  VARCHAR(10) NOT NULL COMMENT 'rpjmd | renstra | iku | lakip',
  `scope`                  VARCHAR(12) NOT NULL DEFAULT 'kabupaten' COMMENT 'kabupaten | opd',
  `opd_id`                 INT UNSIGNED NULL COMMENT 'NULL bila scope=kabupaten',
  `periode_mulai`          INT NOT NULL,
  `periode_akhir`          INT NOT NULL COMMENT 'untuk modul=lakip: sama dengan periode_mulai (satu tahun)',

  -- ---------- nomor & label (§8) ----------
  `version_no`             INT NOT NULL DEFAULT 1 COMMENT 'BUKAN penentu waktu berlaku (§2.6)',
  `label`                  VARCHAR(255) NOT NULL COMMENT 'mis. "V3 — Penyesuaian RPJMD Tahun 2026"',

  -- ---------- timeline (§3) ----------
  `effective_from`         DATE NOT NULL COMMENT 'inklusif — SATU-SATUNYA penentu timeline',
  `effective_to`           DATE NULL COMMENT 'EKSKLUSIF; dihitung sistem, bukan diedit user',

  -- ---------- status (§7) ----------
  `status`                 VARCHAR(20) NOT NULL DEFAULT 'draft'
                           COMMENT 'draft | pending_approval | published | cancelled',

  -- ---------- asal isi versi (§9, §11, §15) ----------
  `copied_from_version_id` INT UNSIGNED NULL COMMENT 'deep copy dari versi ini (§10)',
  `mulai_dari_kosong`      TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = input dari kosong',
  `source_type`            VARCHAR(10) NULL COMMENT 'rpjmd | renstra | iku — sync IKU (§15) / source LAKIP (§25)',
  `source_version_id`      INT UNSIGNED NULL,
  `source_captured_at`     DATETIME NULL COMMENT 'terisi = tautan BEKU; sync ulang wajib versi baru (§15)',
  `source_override_reason` TEXT NULL COMMENT 'WAJIB bila source bukan rekomendasi resolver (§27)',
  `source_override_dasar`  VARCHAR(255) NULL,

  -- ---------- alasan & dasar perubahan (§8, §2.5) ----------
  `alasan_perubahan`       TEXT NULL,
  `dasar_perubahan`        VARCHAR(255) NULL,
  `nomor_dasar`            VARCHAR(150) NULL,
  `tanggal_dasar`          DATE NULL,
  `catatan`                TEXT NULL,

  -- ---------- penunjuk arsip per-dokumen ----------
  `ref_id`                 INT UNSIGNED NULL COMMENT 'iku_revisi.id / lakip_snapshot.id (§42)',

  -- ---------- jejak pelaku (§8) ----------
  `created_by`             INT UNSIGNED NULL,
  `created_at`             DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `submitted_by`           INT UNSIGNED NULL,
  `submitted_at`           DATETIME NULL,
  `approved_by`            INT UNSIGNED NULL,
  `approved_at`            DATETIME NULL,
  `cancelled_by`           INT UNSIGNED NULL,
  `cancelled_at`           DATETIME NULL,
  `applied_at`             DATETIME NULL COMMENT 'kapan arsip diterapkan ke tabel live',
  `updated_at`             DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- ---------- generated: penegak invariant ----------
  `opd_key`                INT UNSIGNED AS (COALESCE(`opd_id`, 0)) STORED,
  `scope_key`              VARCHAR(12)  AS (COALESCE(`scope`, 'kabupaten')) STORED,
  `published_key`          TINYINT AS (CASE WHEN `status` = 'published' THEN 1 ELSE NULL END) STORED,
  `terbuka_key`            TINYINT AS (CASE WHEN `status` = 'published' AND `effective_to` IS NULL THEN 1 ELSE NULL END) STORED,

  PRIMARY KEY (`id`),

  -- nomor versi unik dalam satu dokumen
  UNIQUE KEY `uq_dokver_nomor`
    (`modul`, `scope_key`, `opd_key`, `periode_mulai`, `periode_akhir`, `version_no`),

  -- §6 & §62: dua Published dengan effective_from sama HARUS ditolak
  UNIQUE KEY `uq_dokver_mulai`
    (`modul`, `scope_key`, `opd_key`, `periode_mulai`, `periode_akhir`, `effective_from`, `published_key`),

  -- tepat SATU versi terbuka per dokumen
  UNIQUE KEY `uq_dokver_terbuka`
    (`modul`, `scope_key`, `opd_key`, `periode_mulai`, `periode_akhir`, `terbuka_key`),

  -- index sesuai query nyata (§75)
  KEY `idx_dokver_lingkup` (`modul`, `scope_key`, `opd_key`, `periode_mulai`, `periode_akhir`, `status`),
  KEY `idx_dokver_efektif` (`modul`, `status`, `effective_from`, `effective_to`),
  KEY `idx_dokver_opd`     (`opd_id`),
  KEY `idx_dokver_ref`     (`modul`, `ref_id`),
  KEY `idx_dokver_copy`    (`copied_from_version_id`),
  KEY `idx_dokver_source`  (`source_type`, `source_version_id`),

  CONSTRAINT `fk_dokver_copy` FOREIGN KEY (`copied_from_version_id`)
    REFERENCES `dokumen_versi` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_dokver_source` FOREIGN KEY (`source_version_id`)
    REFERENCES `dokumen_versi` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Registri versi lintas dokumen (§3/§7/§8). version_no != waktu berlaku.';

-- CATATAN: `opd_id` sengaja TANPA foreign key ke `opd`.
-- MySQL melarang FK ber-aksi CASCADE/SET NULL pada kolom yang menjadi dasar
-- STORED generated column (error 1215). Secara semantik juga benar: arsip milik
-- OPD yang dibubarkan tetap harus terbaca oleh LAKIP tahun-tahun sebelumnya.


-- ---------------------------------------------------------------------
-- 1.2  RIWAYAT PENGAJUAN  (§17 "Simpan submission history", §22)
--
-- Satu baris per peristiwa lifecycle. ON DELETE RESTRICT disengaja: versi yang
-- punya jejak TIDAK BISA dihapus — §2.4 ditegakkan engine, bukan disiplin.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `version_submission_history` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version_id`  INT UNSIGNED NOT NULL,
  `aksi`        VARCHAR(30) NOT NULL
                COMMENT 'created|edited_draft|submitted|returned|resubmitted|published|cancelled|correction_requested|correction_approved|correction_returned|synced|applied|retired',
  `dari_status` VARCHAR(20) NULL,
  `ke_status`   VARCHAR(20) NULL,
  `entitas`     VARCHAR(50) NULL,
  `entitas_id`  INT UNSIGNED NULL,
  `ringkasan`   VARCHAR(500) NULL,
  `sebelum`     JSON NULL COMMENT '§22: before',
  `sesudah`     JSON NULL COMMENT '§22: after',
  `catatan`     TEXT NULL COMMENT 'WAJIB pada aksi=returned (§17)',
  `alasan`      TEXT NULL,
  `dasar`       VARCHAR(255) NULL COMMENT '§22: legal basis',
  `effective_from_saat_itu` DATE NULL,
  `source_version_id`       INT UNSIGNED NULL COMMENT '§22: source version',
  `oleh`        INT UNSIGNED NULL,
  `oleh_nama`   VARCHAR(150) NULL COMMENT 'dibekukan; user bisa dihapus/berganti nama',
  `oleh_role`   VARCHAR(50) NULL,
  `ip`          VARCHAR(45) NULL,
  `pada`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vsubhist_versi` (`version_id`, `pada`),
  KEY `idx_vsubhist_aksi`  (`aksi`, `pada`),
  CONSTRAINT `fk_vsubhist_versi` FOREIGN KEY (`version_id`)
    REFERENCES `dokumen_versi` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Riwayat pengajuan & audit lifecycle versi (§17/§22). Append-only.';


-- ---------------------------------------------------------------------
-- 1.3  CORRECTION REQUEST  (§20, §21)
--
-- PENTING — ini BUKAN "koreksi yang langsung diterapkan". §21 menuntut alur
-- permintaan -> review -> baru diterapkan:
--
--   Published -> Ajukan Koreksi -> pending -> AdminKab {kembalikan | setujui}
--                                              setujui -> apply transaksional
--
-- `requested_value` menyimpan usulan; `old_value` DIBEKUKAN saat permintaan
-- dibuat — kalau dibaca ulang saat ditampilkan, kolom "sebelum" ikut bergerak
-- dan perbandingannya jadi tidak berarti.
--
-- Whitelist field ditegakkan di backend (§21), BUKAN di sini: daftar field yang
-- boleh dikoreksi bergantung entity_type dan lebih aman dipelihara di PHP.
-- Yang dijamin skema: dasar & alasan tidak boleh kosong.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `version_correction_requests` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version_id`       INT UNSIGNED NOT NULL,
  `entity_type`      VARCHAR(50)  NOT NULL COMMENT 'nama tabel arsip yang dikoreksi',
  `entity_id`        INT UNSIGNED NOT NULL,
  `field`            VARCHAR(64)  NOT NULL,
  `old_value`        TEXT NULL COMMENT 'dibekukan saat permintaan dibuat',
  `requested_value`  TEXT NULL,
  `reason`           TEXT NOT NULL COMMENT 'WAJIB',
  `dasar`            VARCHAR(255) NULL,
  `status`           VARCHAR(20) NOT NULL DEFAULT 'pending'
                     COMMENT 'pending | approved | returned | cancelled',
  `requested_by`     INT UNSIGNED NULL,
  `requested_at`     DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_by`      INT UNSIGNED NULL,
  `reviewed_at`      DATETIME NULL,
  `review_note`      TEXT NULL COMMENT 'WAJIB bila status=returned (§17)',
  `applied_at`       DATETIME NULL,
  `updated_at`       DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vcorr_versi`   (`version_id`, `status`),
  KEY `idx_vcorr_entitas` (`entity_type`, `entity_id`),
  KEY `idx_vcorr_status`  (`status`, `requested_at`),
  CONSTRAINT `fk_vcorr_versi` FOREIGN KEY (`version_id`)
    REFERENCES `dokumen_versi` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Permintaan koreksi typo/non-substantif pada versi published (§20/§21).';


-- =====================================================================
-- BAGIAN 2 — ARSIP ISI RPJMD  (§12)
-- =====================================================================
-- Arsip menyimpan TEKS, bukan sekadar penunjuk id. Di basis produksi 111 dari
-- 214 baris `lakip` sudah yatim — arsip yang isinya cuma id akan ikut kosong
-- begitu sumbernya hilang. Karena itu tabel di bawah SENGAJA TANPA foreign key
-- ke tabel live; yang ada hanya FK ke dokumen_versi (pemiliknya sendiri).
--
-- Deep copy (§10) menghasilkan ID BARU di seluruh hierarki; `copied_from_*_id`
-- menyimpan lineage-nya (§11) supaya compare (§23) tidak perlu mencocokkan
-- nama sebagai lineage utama.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `rpjmd_versi_misi` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version_id`           INT UNSIGNED NOT NULL,
  `source_misi_id`       INT UNSIGNED NULL COMMENT 'id rpjmd_misi saat dibekukan; tanpa FK (sengaja)',
  `copied_from_id`       INT UNSIGNED NULL COMMENT 'baris arsip pada versi asal (§11)',
  `visi`                 TEXT NULL COMMENT 'teks visi dibekukan; rpjmd_visi tidak punya periode',
  `source_visi_id`       INT UNSIGNED NULL,
  `misi`                 TEXT NOT NULL,
  `tahun_mulai`          INT NOT NULL,
  `tahun_akhir`          INT NOT NULL,
  `urutan`               INT NOT NULL DEFAULT 0,
  `jenis_perubahan`      VARCHAR(20) NOT NULL DEFAULT 'tetap' COMMENT 'tetap|revisi|pengganti|baru|dihentikan',
  `catatan_perubahan`    TEXT NULL,
  `created_at`           DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rpjver_misi_versi`  (`version_id`, `urutan`),
  KEY `idx_rpjver_misi_source` (`source_misi_id`),
  KEY `idx_rpjver_misi_copy`   (`copied_from_id`),
  CONSTRAINT `fk_rpjver_misi_versi` FOREIGN KEY (`version_id`)
    REFERENCES `dokumen_versi` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rpjmd_versi_tujuan` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version_id`        INT UNSIGNED NOT NULL,
  `versi_misi_id`     INT UNSIGNED NOT NULL,
  `source_tujuan_id`  INT UNSIGNED NULL,
  `copied_from_id`    INT UNSIGNED NULL,
  `tujuan_rpjmd`      TEXT NOT NULL,
  `urutan`            INT NOT NULL DEFAULT 0,
  `jenis_perubahan`   VARCHAR(20) NOT NULL DEFAULT 'tetap',
  `catatan_perubahan` TEXT NULL,
  `created_at`        DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rpjver_tujuan_versi`  (`version_id`, `urutan`),
  KEY `idx_rpjver_tujuan_misi`   (`versi_misi_id`, `urutan`),
  KEY `idx_rpjver_tujuan_source` (`source_tujuan_id`),
  CONSTRAINT `fk_rpjver_tujuan_versi` FOREIGN KEY (`version_id`)
    REFERENCES `dokumen_versi` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rpjver_tujuan_misi` FOREIGN KEY (`versi_misi_id`)
    REFERENCES `rpjmd_versi_misi` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rpjmd_versi_indikator_tujuan` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version_id`         INT UNSIGNED NOT NULL,
  `versi_tujuan_id`    INT UNSIGNED NOT NULL,
  `source_indikator_id` INT UNSIGNED NULL,
  `copied_from_id`     INT UNSIGNED NULL,
  `indikator_tujuan`   TEXT NOT NULL,
  `urutan`             INT NOT NULL DEFAULT 0,
  `jenis_perubahan`    VARCHAR(20) NOT NULL DEFAULT 'tetap',
  `created_at`         DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rpjver_indtuj_versi`  (`version_id`, `urutan`),
  KEY `idx_rpjver_indtuj_tujuan` (`versi_tujuan_id`, `urutan`),
  CONSTRAINT `fk_rpjver_indtuj_versi` FOREIGN KEY (`version_id`)
    REFERENCES `dokumen_versi` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rpjver_indtuj_tujuan` FOREIGN KEY (`versi_tujuan_id`)
    REFERENCES `rpjmd_versi_tujuan` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rpjmd_versi_target_tujuan` (
  `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `versi_indikator_tujuan_id` INT UNSIGNED NOT NULL,
  `tahun`                     INT NOT NULL,
  `target_tahunan`            VARCHAR(255) NULL,
  `target_sebelumnya`         VARCHAR(255) NULL COMMENT 'nilai pada versi asal; untuk kolom sebelum/sesudah (§23)',
  `created_at`                DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rpjver_targettuj` (`versi_indikator_tujuan_id`, `tahun`),
  CONSTRAINT `fk_rpjver_targettuj_ind` FOREIGN KEY (`versi_indikator_tujuan_id`)
    REFERENCES `rpjmd_versi_indikator_tujuan` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rpjmd_versi_sasaran` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version_id`        INT UNSIGNED NOT NULL,
  `versi_tujuan_id`   INT UNSIGNED NOT NULL,
  `source_sasaran_id` INT UNSIGNED NULL,
  `copied_from_id`    INT UNSIGNED NULL,
  `sasaran_rpjmd`     TEXT NOT NULL,
  `csf`               TEXT NULL,
  `urutan`            INT NOT NULL DEFAULT 0,
  `jenis_perubahan`   VARCHAR(20) NOT NULL DEFAULT 'tetap',
  `catatan_perubahan` TEXT NULL,
  `created_at`        DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rpjver_sasaran_versi`  (`version_id`, `urutan`),
  KEY `idx_rpjver_sasaran_tujuan` (`versi_tujuan_id`, `urutan`),
  KEY `idx_rpjver_sasaran_source` (`source_sasaran_id`),
  CONSTRAINT `fk_rpjver_sasaran_versi` FOREIGN KEY (`version_id`)
    REFERENCES `dokumen_versi` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rpjver_sasaran_tujuan` FOREIGN KEY (`versi_tujuan_id`)
    REFERENCES `rpjmd_versi_tujuan` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rpjmd_versi_indikator_sasaran` (
  `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version_id`              INT UNSIGNED NOT NULL,
  `versi_sasaran_id`        INT UNSIGNED NOT NULL,
  `source_indikator_id`     INT UNSIGNED NULL,
  `copied_from_id`          INT UNSIGNED NULL,
  `indikator_sasaran`       TEXT NOT NULL,
  `definisi_op`             TEXT NULL,
  `satuan`                  VARCHAR(50) NULL COMMENT 'id satuan (numerik) atau teks bebas',
  `satuan_nama`             VARCHAR(150) NULL COMMENT 'hasil terjemahan satuan saat dibekukan',
  `jenis_indikator`         VARCHAR(100) NULL,
  `baseline`                VARCHAR(50) NULL,
  `urutan`                  INT NOT NULL DEFAULT 0,
  `jenis_perubahan`         VARCHAR(20) NOT NULL DEFAULT 'tetap',
  `indikator_sebelumnya_id` INT UNSIGNED NULL COMMENT 'WAJIB bila jenis_perubahan = pengganti',
  `perubahan_substansial`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = tren antar tahun tidak boleh disambung',
  `catatan_perubahan`       TEXT NULL,
  `created_at`              DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rpjver_indsas_versi`   (`version_id`, `urutan`),
  KEY `idx_rpjver_indsas_sasaran` (`versi_sasaran_id`, `urutan`),
  KEY `idx_rpjver_indsas_source`  (`source_indikator_id`),
  KEY `idx_rpjver_indsas_lineage` (`indikator_sebelumnya_id`),
  CONSTRAINT `fk_rpjver_indsas_versi` FOREIGN KEY (`version_id`)
    REFERENCES `dokumen_versi` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rpjver_indsas_sasaran` FOREIGN KEY (`versi_sasaran_id`)
    REFERENCES `rpjmd_versi_sasaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rpjmd_versi_target` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `versi_indikator_id` INT UNSIGNED NOT NULL,
  `tahun`              INT NOT NULL,
  `target_tahunan`     VARCHAR(255) NULL,
  `target_sebelumnya`  VARCHAR(255) NULL,
  `created_at`         DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rpjver_target` (`versi_indikator_id`, `tahun`),
  CONSTRAINT `fk_rpjver_target_ind` FOREIGN KEY (`versi_indikator_id`)
    REFERENCES `rpjmd_versi_indikator_sasaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- =====================================================================
-- BAGIAN 3 — ARSIP ISI RENSTRA  (§13)
-- =====================================================================
-- Perhatikan `renstra_versi_tujuan`: tabel live `renstra_tujuan` TIDAK punya
-- opd_id maupun periode — ia menggantung pada rpjmd_sasaran, dan kepemilikannya
-- hanya bisa disimpulkan lewat renstra_sasaran yang menunjuknya (55 dari 112
-- baris bahkan sudah yatim di basis aktif).
--
-- Karena itu arsip menaruh tujuan DI DALAM lingkup versi (version_id sudah
-- membawa opd_id + periode), dan membekukan TEKS rpjmd_sasaran induknya —
-- bukan hanya id-nya.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `renstra_versi_tujuan` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version_id`         INT UNSIGNED NOT NULL,
  `source_tujuan_id`   INT UNSIGNED NULL,
  `copied_from_id`     INT UNSIGNED NULL,
  `rpjmd_sasaran_id`   INT UNSIGNED NULL COMMENT 'penunjuk lemah; tanpa FK (sengaja)',
  `rpjmd_sasaran_teks` TEXT NULL COMMENT 'dibekukan supaya arsip tetap terbaca bila sumbernya hilang',
  `rpjmd_version_id`   INT UNSIGNED NULL COMMENT 'versi RPJMD yang dirujuk saat dibekukan',
  `tujuan`             TEXT NOT NULL,
  `urutan`             INT NOT NULL DEFAULT 0,
  `jenis_perubahan`    VARCHAR(20) NOT NULL DEFAULT 'tetap',
  `catatan_perubahan`  TEXT NULL,
  `created_at`         DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rsver_tujuan_versi`  (`version_id`, `urutan`),
  KEY `idx_rsver_tujuan_source` (`source_tujuan_id`),
  KEY `idx_rsver_tujuan_rpjmd`  (`rpjmd_sasaran_id`),
  CONSTRAINT `fk_rsver_tujuan_versi` FOREIGN KEY (`version_id`)
    REFERENCES `dokumen_versi` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `renstra_versi_indikator_tujuan` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version_id`          INT UNSIGNED NOT NULL,
  `versi_tujuan_id`     INT UNSIGNED NOT NULL,
  `source_indikator_id` INT UNSIGNED NULL,
  `copied_from_id`      INT UNSIGNED NULL,
  `indikator_tujuan`    TEXT NOT NULL,
  `urutan`              INT NOT NULL DEFAULT 0,
  `jenis_perubahan`     VARCHAR(20) NOT NULL DEFAULT 'tetap',
  `created_at`          DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rsver_indtuj_versi`  (`version_id`, `urutan`),
  KEY `idx_rsver_indtuj_tujuan` (`versi_tujuan_id`, `urutan`),
  CONSTRAINT `fk_rsver_indtuj_versi` FOREIGN KEY (`version_id`)
    REFERENCES `dokumen_versi` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rsver_indtuj_tujuan` FOREIGN KEY (`versi_tujuan_id`)
    REFERENCES `renstra_versi_tujuan` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `renstra_versi_target_tujuan` (
  `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `versi_indikator_tujuan_id` INT UNSIGNED NOT NULL,
  `tahun`                     INT NOT NULL,
  `target_tahunan`            VARCHAR(100) NULL,
  `target_sebelumnya`         VARCHAR(100) NULL,
  `created_at`                DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rsver_targettuj` (`versi_indikator_tujuan_id`, `tahun`),
  CONSTRAINT `fk_rsver_targettuj_ind` FOREIGN KEY (`versi_indikator_tujuan_id`)
    REFERENCES `renstra_versi_indikator_tujuan` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `renstra_versi_sasaran` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version_id`        INT UNSIGNED NOT NULL,
  `versi_tujuan_id`   INT UNSIGNED NOT NULL,
  `source_sasaran_id` INT UNSIGNED NULL,
  `copied_from_id`    INT UNSIGNED NULL,
  `opd_id`            INT UNSIGNED NULL COMMENT 'dibekukan dari renstra_sasaran.opd_id',
  `nama_opd`          VARCHAR(255) NULL COMMENT 'dibekukan; OPD bisa dibubarkan/berganti nama',
  `sasaran`           TEXT NOT NULL,
  `csf`               TEXT NULL,
  `tahun_mulai`       INT NOT NULL,
  `tahun_akhir`       INT NOT NULL,
  `urutan`            INT NOT NULL DEFAULT 0,
  `jenis_perubahan`   VARCHAR(20) NOT NULL DEFAULT 'tetap',
  `catatan_perubahan` TEXT NULL,
  `created_at`        DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rsver_sasaran_versi`  (`version_id`, `urutan`),
  KEY `idx_rsver_sasaran_tujuan` (`versi_tujuan_id`, `urutan`),
  KEY `idx_rsver_sasaran_source` (`source_sasaran_id`),
  CONSTRAINT `fk_rsver_sasaran_versi` FOREIGN KEY (`version_id`)
    REFERENCES `dokumen_versi` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rsver_sasaran_tujuan` FOREIGN KEY (`versi_tujuan_id`)
    REFERENCES `renstra_versi_tujuan` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `renstra_versi_indikator_sasaran` (
  `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version_id`              INT UNSIGNED NOT NULL,
  `versi_sasaran_id`        INT UNSIGNED NOT NULL,
  `source_indikator_id`     INT UNSIGNED NULL,
  `copied_from_id`          INT UNSIGNED NULL,
  `indikator_sasaran`       TEXT NOT NULL,
  `satuan`                  VARCHAR(50) NULL,
  `satuan_nama`             VARCHAR(150) NULL COMMENT 'hasil terjemahan satuan saat dibekukan',
  `baseline`                VARCHAR(50) NULL,
  `jenis_indikator`         VARCHAR(100) NULL,
  `urutan`                  INT NOT NULL DEFAULT 0,
  `jenis_perubahan`         VARCHAR(20) NOT NULL DEFAULT 'tetap',
  `indikator_sebelumnya_id` INT UNSIGNED NULL COMMENT 'WAJIB bila jenis_perubahan = pengganti',
  `perubahan_substansial`   TINYINT(1) NOT NULL DEFAULT 0,
  `catatan_perubahan`       TEXT NULL,
  `created_at`              DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rsver_indsas_versi`   (`version_id`, `urutan`),
  KEY `idx_rsver_indsas_sasaran` (`versi_sasaran_id`, `urutan`),
  KEY `idx_rsver_indsas_source`  (`source_indikator_id`),
  KEY `idx_rsver_indsas_lineage` (`indikator_sebelumnya_id`),
  CONSTRAINT `fk_rsver_indsas_versi` FOREIGN KEY (`version_id`)
    REFERENCES `dokumen_versi` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rsver_indsas_sasaran` FOREIGN KEY (`versi_sasaran_id`)
    REFERENCES `renstra_versi_sasaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `renstra_versi_target` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `versi_indikator_id` INT UNSIGNED NOT NULL,
  `tahun`              INT NOT NULL,
  `target`             VARCHAR(100) NULL,
  `target_sebelumnya`  VARCHAR(100) NULL,
  `created_at`         DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rsver_target` (`versi_indikator_id`, `tahun`),
  CONSTRAINT `fk_rsver_target_ind` FOREIGN KEY (`versi_indikator_id`)
    REFERENCES `renstra_versi_indikator_sasaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- =====================================================================
-- BAGIAN 4 — KOLOM "PENSIUN, BUKAN HAPUS" PADA TABEL LIVE  (§2.4, §81)
-- =====================================================================
-- INI PENEGAK INVARIANT PALING PENTING DI SELURUH BERKAS INI.
--
-- renstra_tujuan.rpjmd_sasaran_id ber-ON DELETE CASCADE. Rantainya:
--   rpjmd_sasaran -> renstra_tujuan -> renstra_sasaran
--                 -> renstra_indikator_sasaran -> renstra_target
--                 -> target_rencana (Renaksi) + lakip_analisis_faktor
-- dan `lakip` ikut di-SET NULL. Di basis aktif, 38 OPD terhubung lewat rantai
-- ini.
--
-- Artinya: MENERBITKAN VERSI RPJMD BARU TIDAK BOLEH PERNAH MENGHAPUS BARIS LIVE.
-- Penerapan versi = UPSERT + PENSIUN memakai kolom di bawah — persis pola
-- IkuRevisiModel::pensiunkanYangHilang() yang sudah terbukti.
--
-- Semua kolom NULLABLE, jadi seluruh baris yang ada sekarang tidak berubah
-- perilakunya. Filter `dihentikan_pada IS NULL` tidak menyaring apa pun pada
-- data lama.
--
-- FK CASCADE yang berbahaya SENGAJA TIDAK DIUBAH (§80.3, §81): mengubahnya akan
-- mengubah perilaku RpjmdModel::deleteMisi/deleteSasaran dan
-- RenstraModel::deleteCompleteRenstra yang sekarang mengandalkannya.
-- Pemblokiran hard-delete dilakukan di lapisan model.
-- ---------------------------------------------------------------------

CALL _dv_pensiun_kolom('rpjmd_misi');
CALL _dv_pensiun_kolom('rpjmd_tujuan');
CALL _dv_pensiun_kolom('rpjmd_indikator_tujuan');
CALL _dv_pensiun_kolom('rpjmd_sasaran');
CALL _dv_pensiun_kolom('rpjmd_indikator_sasaran');

CALL _dv_pensiun_kolom('renstra_tujuan');
CALL _dv_pensiun_kolom('renstra_indikator_tujuan');
CALL _dv_pensiun_kolom('renstra_sasaran');
CALL _dv_pensiun_kolom('renstra_indikator_sasaran');

-- Silsilah indikator (§11): membedakan "direvisi" dari "diganti indikator lain"
-- dari "indikator baru". perubahan_substansial = 1 memutus tren antar tahun.
CALL _dv_add_col_if_absent('rpjmd_indikator_sasaran', 'indikator_sebelumnya_id',
  'ALTER TABLE `rpjmd_indikator_sasaran` ADD COLUMN `indikator_sebelumnya_id` INT UNSIGNED NULL COMMENT ''indikator yang digantikan baris ini (lineage)''');
CALL _dv_add_col_if_absent('rpjmd_indikator_sasaran', 'jenis_perubahan',
  'ALTER TABLE `rpjmd_indikator_sasaran` ADD COLUMN `jenis_perubahan` VARCHAR(20) NOT NULL DEFAULT ''tetap'' COMMENT ''tetap|revisi|pengganti|baru|dihentikan''');
CALL _dv_add_col_if_absent('rpjmd_indikator_sasaran', 'perubahan_substansial',
  'ALTER TABLE `rpjmd_indikator_sasaran` ADD COLUMN `perubahan_substansial` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = tren antar tahun tidak boleh disambung''');
CALL _dv_add_idx_if_absent('rpjmd_indikator_sasaran', 'idx_rpjmd_indsas_lineage',
  'ALTER TABLE `rpjmd_indikator_sasaran` ADD KEY `idx_rpjmd_indsas_lineage` (`indikator_sebelumnya_id`)');

CALL _dv_add_col_if_absent('renstra_indikator_sasaran', 'indikator_sebelumnya_id',
  'ALTER TABLE `renstra_indikator_sasaran` ADD COLUMN `indikator_sebelumnya_id` INT UNSIGNED NULL COMMENT ''indikator yang digantikan baris ini (lineage)''');
CALL _dv_add_col_if_absent('renstra_indikator_sasaran', 'jenis_perubahan',
  'ALTER TABLE `renstra_indikator_sasaran` ADD COLUMN `jenis_perubahan` VARCHAR(20) NOT NULL DEFAULT ''tetap'' COMMENT ''tetap|revisi|pengganti|baru|dihentikan''');
CALL _dv_add_col_if_absent('renstra_indikator_sasaran', 'perubahan_substansial',
  'ALTER TABLE `renstra_indikator_sasaran` ADD COLUMN `perubahan_substansial` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = tren antar tahun tidak boleh disambung''');
CALL _dv_add_idx_if_absent('renstra_indikator_sasaran', 'idx_renstra_indsas_lineage',
  'ALTER TABLE `renstra_indikator_sasaran` ADD KEY `idx_renstra_indsas_lineage` (`indikator_sebelumnya_id`)');

-- FK lineage: SET NULL supaya menghapus indikator lama tidak ikut menghapus
-- penggantinya. `version_id` pada tabel live TANPA FK ke dokumen_versi karena
-- baris live boleh berumur lebih panjang daripada registri versinya.
CALL _dv_add_fk_if_absent('rpjmd_indikator_sasaran', 'fk_rpjmd_indsas_sebelumnya',
  'ALTER TABLE `rpjmd_indikator_sasaran` ADD CONSTRAINT `fk_rpjmd_indsas_sebelumnya` FOREIGN KEY (`indikator_sebelumnya_id`) REFERENCES `rpjmd_indikator_sasaran` (`id`) ON DELETE SET NULL ON UPDATE CASCADE');
CALL _dv_add_fk_if_absent('renstra_indikator_sasaran', 'fk_renstra_indsas_sebelumnya',
  'ALTER TABLE `renstra_indikator_sasaran` ADD CONSTRAINT `fk_renstra_indsas_sebelumnya` FOREIGN KEY (`indikator_sebelumnya_id`) REFERENCES `renstra_indikator_sasaran` (`id`) ON DELETE SET NULL ON UPDATE CASCADE');


-- =====================================================================
-- BAGIAN 5 — MENYAMBUNG ARSIP LAMA KE REGISTRI BARU  (§42, §51)
-- =====================================================================

-- ---------------------------------------------------------------------
-- 5.1  IKU  (§14, §15)
-- Kolom lama iku_revisi (nomor, berlaku_mulai_tahun, status) TIDAK diubah,
-- sehingga IkuRevisiModel yang sudah ditulis tetap bekerja apa adanya.
-- Yang berbutir TANGGAL adalah dokumen_versi.effective_from/to — itulah yang
-- akhirnya memungkinkan >1 revisi dalam satu tahun (§5), sesuatu yang tidak
-- bisa diwakili iku_revisi.berlaku_mulai_tahun yang bertipe INT.
-- ---------------------------------------------------------------------
CALL _dv_add_col_if_absent('iku_revisi', 'version_id',
  'ALTER TABLE `iku_revisi` ADD COLUMN `version_id` INT UNSIGNED NULL COMMENT ''baris dokumen_versi milik revisi ini''');
CALL _dv_add_col_if_absent('iku_revisi', 'submitted_by',
  'ALTER TABLE `iku_revisi` ADD COLUMN `submitted_by` INT UNSIGNED NULL');
CALL _dv_add_col_if_absent('iku_revisi', 'submitted_at',
  'ALTER TABLE `iku_revisi` ADD COLUMN `submitted_at` DATETIME NULL');
CALL _dv_add_idx_if_absent('iku_revisi', 'idx_iku_revisi_dokver',
  'ALTER TABLE `iku_revisi` ADD KEY `idx_iku_revisi_dokver` (`version_id`)');

-- Lineage per-indikator untuk IKU sync (§15, §11).
--
-- MENGAPA PERLU: IkuModel::importSync() mencocokkan indikator yang sudah ada
-- lewat NORMALISASI TEKS (petaIkuTerpasang), dan `iku_indikator` tidak menyimpan
-- satu pun penunjuk ke indikator Renstra/RPJMD asalnya. Akibatnya:
--
--   * §11 melarang "mengandalkan string name matching sebagai lineage utama";
--   * §64 menuntut sync tercatat berasal dari VERSI mana;
--   * LAKIP yang berpindah sumber (Renstra -> IKU) tidak punya jembatan untuk
--     membawa realisasi yang sudah diinput, karena tidak ada yang menghubungkan
--     indikator IKU dengan indikator Renstra yang melahirkannya.
--
-- Kolomnya NULLABLE: 97 indikator yang sudah ada tetap apa adanya, dan hanya
-- hasil sync berikutnya yang terisi.
CALL _dv_add_col_if_absent('iku_sasaran', 'source_type',
  'ALTER TABLE `iku_sasaran` ADD COLUMN `source_type` VARCHAR(10) NULL COMMENT ''rpjmd | renstra — asal sync (§15)''');
CALL _dv_add_col_if_absent('iku_sasaran', 'source_version_id',
  'ALTER TABLE `iku_sasaran` ADD COLUMN `source_version_id` INT UNSIGNED NULL COMMENT ''dokumen_versi.id sumber sync''');
CALL _dv_add_col_if_absent('iku_sasaran', 'source_sasaran_id',
  'ALTER TABLE `iku_sasaran` ADD COLUMN `source_sasaran_id` INT UNSIGNED NULL COMMENT ''id sasaran live pada dokumen sumber''');

CALL _dv_add_col_if_absent('iku_indikator', 'source_type',
  'ALTER TABLE `iku_indikator` ADD COLUMN `source_type` VARCHAR(10) NULL COMMENT ''rpjmd | renstra — asal sync (§15)''');
CALL _dv_add_col_if_absent('iku_indikator', 'source_version_id',
  'ALTER TABLE `iku_indikator` ADD COLUMN `source_version_id` INT UNSIGNED NULL');
CALL _dv_add_col_if_absent('iku_indikator', 'source_indikator_id',
  'ALTER TABLE `iku_indikator` ADD COLUMN `source_indikator_id` INT UNSIGNED NULL COMMENT ''id indikator live pada dokumen sumber — jembatan realisasi LAKIP''');
CALL _dv_add_idx_if_absent('iku_indikator', 'idx_iku_indikator_source',
  'ALTER TABLE `iku_indikator` ADD KEY `idx_iku_indikator_source` (`source_type`, `source_indikator_id`)');

-- ---------------------------------------------------------------------
-- 5.2  LAKIP — source type + source version  (§24, §25, §26, §32)
--
-- PERUBAHAN PERILAKU (§24): default source LAKIP menjadi IKU.
--   Kabupaten: default IKU Kabupaten, alternatif RPJMD
--   OPD      : default IKU OPD,       alternatif Renstra
-- Sebelumnya source dipaksa oleh `mode` (kabupaten->RPJMD, opd->Renstra).
-- `sumber_iku_revisi_id` yang sudah ada DIPERTAHANKAN demi kompatibilitas (§42).
-- ---------------------------------------------------------------------
CALL _dv_add_col_if_absent('lakip_snapshot', 'version_id',
  'ALTER TABLE `lakip_snapshot` ADD COLUMN `version_id` INT UNSIGNED NULL COMMENT ''baris dokumen_versi milik LAKIP version ini''');
CALL _dv_add_col_if_absent('lakip_snapshot', 'source_type',
  'ALTER TABLE `lakip_snapshot` ADD COLUMN `source_type` VARCHAR(10) NULL COMMENT ''iku | rpjmd | renstra — default iku (§24)''');
CALL _dv_add_col_if_absent('lakip_snapshot', 'source_version_id',
  'ALTER TABLE `lakip_snapshot` ADD COLUMN `source_version_id` INT UNSIGNED NULL COMMENT ''dokumen_versi.id dari source''');
CALL _dv_add_col_if_absent('lakip_snapshot', 'source_reference_date',
  'ALTER TABLE `lakip_snapshot` ADD COLUMN `source_reference_date` DATE NULL COMMENT ''tanggal resolver; default 31 Desember tahun laporan (§26)''');
CALL _dv_add_col_if_absent('lakip_snapshot', 'source_override_reason',
  'ALTER TABLE `lakip_snapshot` ADD COLUMN `source_override_reason` TEXT NULL COMMENT ''WAJIB bila version bukan rekomendasi (§27)''');
CALL _dv_add_col_if_absent('lakip_snapshot', 'created_from_lakip_version_id',
  'ALTER TABLE `lakip_snapshot` ADD COLUMN `created_from_lakip_version_id` INT UNSIGNED NULL COMMENT ''revisi LAKIP: deep copy dari versi ini (§33)''');
CALL _dv_add_col_if_absent('lakip_snapshot', 'captured_at',
  'ALTER TABLE `lakip_snapshot` ADD COLUMN `captured_at` DATETIME NULL COMMENT ''§29''');
CALL _dv_add_col_if_absent('lakip_snapshot', 'captured_by',
  'ALTER TABLE `lakip_snapshot` ADD COLUMN `captured_by` INT UNSIGNED NULL COMMENT ''§29''');
CALL _dv_add_idx_if_absent('lakip_snapshot', 'idx_lakipsnap_dokver',
  'ALTER TABLE `lakip_snapshot` ADD KEY `idx_lakipsnap_dokver` (`version_id`)');
CALL _dv_add_idx_if_absent('lakip_snapshot', 'idx_lakipsnap_source',
  'ALTER TABLE `lakip_snapshot` ADD KEY `idx_lakipsnap_source` (`source_type`, `source_version_id`)');

-- ---------------------------------------------------------------------
-- 5.3  LAKIP SNAPSHOT ITEM  (§29)
--
-- §51 melarang tabel duplikat berfungsi sama. `lakip_snapshot_baris` yang sudah
-- ada SUDAH menjadi "snapshot item" (§29): ia menyimpan sasaran, indikator,
-- satuan terterjemahkan, target, urutan, dan lineage ke iku_indikator.
-- Jadi TIDAK dibuat tabel lakip_snapshot_items baru — cukup digeneralisasi.
--
-- Kolom `sumber` lama hanya mengenal 'renstra|rpjmd'; kini juga 'iku'.
-- ---------------------------------------------------------------------
CALL _dv_add_col_if_absent('lakip_snapshot_baris', 'source_type',
  'ALTER TABLE `lakip_snapshot_baris` ADD COLUMN `source_type` VARCHAR(10) NULL COMMENT ''iku | rpjmd | renstra''');
CALL _dv_add_col_if_absent('lakip_snapshot_baris', 'source_version_id',
  'ALTER TABLE `lakip_snapshot_baris` ADD COLUMN `source_version_id` INT UNSIGNED NULL');
CALL _dv_add_col_if_absent('lakip_snapshot_baris', 'source_sasaran_id',
  'ALTER TABLE `lakip_snapshot_baris` ADD COLUMN `source_sasaran_id` INT UNSIGNED NULL COMMENT ''id baris arsip sasaran pada versi sumber''');
CALL _dv_add_col_if_absent('lakip_snapshot_baris', 'source_indikator_id',
  'ALTER TABLE `lakip_snapshot_baris` ADD COLUMN `source_indikator_id` INT UNSIGNED NULL COMMENT ''id baris arsip indikator pada versi sumber''');
CALL _dv_add_col_if_absent('lakip_snapshot_baris', 'source_target_id',
  'ALTER TABLE `lakip_snapshot_baris` ADD COLUMN `source_target_id` INT UNSIGNED NULL');
CALL _dv_add_col_if_absent('lakip_snapshot_baris', 'copied_from_item_id',
  'ALTER TABLE `lakip_snapshot_baris` ADD COLUMN `copied_from_item_id` INT UNSIGNED NULL COMMENT ''lineage revisi LAKIP V1->V2 (§34/§39)''');
CALL _dv_add_col_if_absent('lakip_snapshot_baris', 'captured_at',
  'ALTER TABLE `lakip_snapshot_baris` ADD COLUMN `captured_at` DATETIME NULL');
CALL _dv_add_col_if_absent('lakip_snapshot_baris', 'captured_by',
  'ALTER TABLE `lakip_snapshot_baris` ADD COLUMN `captured_by` INT UNSIGNED NULL');
CALL _dv_add_idx_if_absent('lakip_snapshot_baris', 'idx_snapbaris_source',
  'ALTER TABLE `lakip_snapshot_baris` ADD KEY `idx_snapbaris_source` (`source_type`, `source_version_id`)');
CALL _dv_add_idx_if_absent('lakip_snapshot_baris', 'idx_snapbaris_lineage',
  'ALTER TABLE `lakip_snapshot_baris` ADD KEY `idx_snapbaris_lineage` (`copied_from_item_id`)');


-- =====================================================================
-- BAGIAN 6 — BENCHMARK PER SNAPSHOT ITEM  (§36, §37, §38, §39)
-- =====================================================================
-- §36: benchmark melekat pada LAKIP Version -> LAKIP Snapshot Item,
--      BUKAN benchmark global source indicator.
--
-- Tabel `lakip_benchmark` yang ada sekarang berkunci
-- (rpjmd_indikator_id|renstra_indikator_id, tahun) — itu benchmark GLOBAL,
-- tepat yang §36 larang. Tabel itu TIDAK DIHAPUS (§42, §81): data lama tetap
-- terbaca. Yang dibuat adalah tabel baru berbutir snapshot item.
--
-- §37 menuntut tahun_data_provinsi / tahun_data_nasional yang tidak dimiliki
-- tabel lama, dan nilai kosong WAJIB NULL, bukan 0.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lakip_benchmark_item` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lakip_snapshot_item_id` INT UNSIGNED NOT NULL COMMENT 'lakip_snapshot_baris.id',
  `snapshot_id`           INT UNSIGNED NOT NULL COMMENT 'denormal utk scope check cepat',
  `opd_id`                INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = tingkat kabupaten',
  `nilai_provinsi`        DECIMAL(20,4) NULL COMMENT 'NULL = belum ada data, BUKAN 0 (§37)',
  `nilai_nasional`        DECIMAL(20,4) NULL COMMENT 'NULL = belum ada data, BUKAN 0 (§37)',
  `sumber_provinsi`       VARCHAR(255) NULL,
  `sumber_nasional`       VARCHAR(255) NULL,
  `tahun_data_provinsi`   YEAR NULL,
  `tahun_data_nasional`   YEAR NULL,
  `catatan`               TEXT NULL,
  `copied_from_id`        INT UNSIGNED NULL COMMENT 'benchmark asal saat revisi LAKIP (§39)',
  `created_by`            INT UNSIGNED NULL,
  `updated_by`            INT UNSIGNED NULL,
  `created_at`            DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_benchitem_item` (`lakip_snapshot_item_id`),
  KEY `idx_benchitem_snapshot` (`snapshot_id`),
  KEY `idx_benchitem_opd`      (`opd_id`),
  KEY `idx_benchitem_copy`     (`copied_from_id`),
  CONSTRAINT `fk_benchitem_item` FOREIGN KEY (`lakip_snapshot_item_id`)
    REFERENCES `lakip_snapshot_baris` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_benchitem_snapshot` FOREIGN KEY (`snapshot_id`)
    REFERENCES `lakip_snapshot` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Benchmark per LAKIP snapshot item (§36/§37). lakip_benchmark lama tetap ada.';


-- =====================================================================
-- BAGIAN 7 — LINGKUP PADA TABEL `lakip`  (§42, temuan T6)
-- =====================================================================
-- Tabel `lakip` tidak punya tahun/opd_id/mode: identitasnya menempel pada
-- renstra_target_id / rpjmd_target_id yang ber-ON DELETE SET NULL. Akibatnya
-- baris yang sumbernya terhapus menjadi yatim dan LENYAP SENYAP dari semua
-- laporan, karena setiap query menyaring lewat rt.tahun / rpj.tahun.
-- Di basis aktif: 111 dari 214 baris sudah yatim.
--
-- Kolom di bawah bersifat DENORMALISASI PENYELAMAT, bukan pengganti FK lama —
-- FK dan kolom lama TIDAK DIHAPUS (§42, §81). Backfill hanya MENGISI kolom
-- baru; tidak ada satu pun nilai lama yang disentuh.
--
-- Baris yatim TIDAK BISA di-backfill (sumbernya sudah tidak ada) dan
-- SENGAJA DIBIARKAN dengan tahun NULL. Menebak tahunnya berarti mengarang
-- sejarah. postdeploy akan melaporkan jumlahnya untuk ditindaklanjuti manual.
-- ---------------------------------------------------------------------
CALL _dv_add_col_if_absent('lakip', 'tahun',
  'ALTER TABLE `lakip` ADD COLUMN `tahun` YEAR NULL COMMENT ''denormal dari target; NULL = baris yatim''');
CALL _dv_add_col_if_absent('lakip', 'opd_id',
  'ALTER TABLE `lakip` ADD COLUMN `opd_id` INT UNSIGNED NULL COMMENT ''0/NULL = tingkat kabupaten''');
CALL _dv_add_col_if_absent('lakip', 'mode',
  'ALTER TABLE `lakip` ADD COLUMN `mode` VARCHAR(12) NULL COMMENT ''kabupaten | opd''');
CALL _dv_add_col_if_absent('lakip', 'source_type',
  'ALTER TABLE `lakip` ADD COLUMN `source_type` VARCHAR(10) NULL COMMENT ''iku | rpjmd | renstra (§41)''');
CALL _dv_add_col_if_absent('lakip', 'source_version_id',
  'ALTER TABLE `lakip` ADD COLUMN `source_version_id` INT UNSIGNED NULL');
CALL _dv_add_col_if_absent('lakip', 'source_entity_id',
  'ALTER TABLE `lakip` ADD COLUMN `source_entity_id` INT UNSIGNED NULL COMMENT ''§41 source-agnostic''');
CALL _dv_add_col_if_absent('lakip', 'lakip_version_id',
  'ALTER TABLE `lakip` ADD COLUMN `lakip_version_id` INT UNSIGNED NULL COMMENT ''dokumen_versi modul=lakip''');
CALL _dv_add_idx_if_absent('lakip', 'idx_lakip_lingkup',
  'ALTER TABLE `lakip` ADD KEY `idx_lakip_lingkup` (`tahun`, `mode`, `opd_id`)');
CALL _dv_add_idx_if_absent('lakip', 'idx_lakip_source',
  'ALTER TABLE `lakip` ADD KEY `idx_lakip_source` (`source_type`, `source_version_id`)');
CALL _dv_add_idx_if_absent('lakip', 'idx_lakip_version',
  'ALTER TABLE `lakip` ADD KEY `idx_lakip_version` (`lakip_version_id`)');

-- Backfill jalur RENSTRA (mode=opd). Hanya mengisi yang masih NULL.
UPDATE `lakip` l
JOIN `renstra_target` rt              ON rt.id  = l.renstra_target_id
JOIN `renstra_indikator_sasaran` ris  ON ris.id = rt.renstra_indikator_id
JOIN `renstra_sasaran` rs             ON rs.id  = ris.renstra_sasaran_id
SET l.tahun            = rt.tahun,
    l.opd_id           = rs.opd_id,
    l.mode             = 'opd',
    l.source_type      = 'renstra',
    l.source_entity_id = rt.id
WHERE l.tahun IS NULL AND l.renstra_target_id IS NOT NULL;

-- Backfill jalur RPJMD (mode=kabupaten).
UPDATE `lakip` l
JOIN `rpjmd_target` rpj ON rpj.id = l.rpjmd_target_id
SET l.tahun            = rpj.tahun,
    l.opd_id           = 0,
    l.mode             = 'kabupaten',
    l.source_type      = 'rpjmd',
    l.source_entity_id = rpj.id
WHERE l.tahun IS NULL AND l.rpjmd_target_id IS NOT NULL;


-- =====================================================================
-- BAGIAN 8 — PERMISSION  (§54)
-- =====================================================================
-- Penamaan mengikuti §54: <modul>.version.<aksi>, version_correction.*,
-- lakip_benchmark.manage_own / manage_all.
-- Idempoten lewat ON DUPLICATE KEY UPDATE pada kolom unik `name`.
-- ---------------------------------------------------------------------

INSERT INTO `permissions` (`name`, `label`, `grup`) VALUES
  -- RPJMD (scope kabupaten)
  ('rpjmd.version.view',           'RPJMD — Lihat Version',            'Kabupaten'),
  ('rpjmd.version.create',         'RPJMD — Buat Version',             'Kabupaten'),
  ('rpjmd.version.update_draft',   'RPJMD — Ubah Draft Version',       'Kabupaten'),
  ('rpjmd.version.submit',         'RPJMD — Ajukan Version',           'Kabupaten'),
  ('rpjmd.version.verify',         'RPJMD — Verifikasi Version',       'Kabupaten'),
  ('rpjmd.version.publish',        'RPJMD — Tetapkan Berlaku',         'Kabupaten'),
  -- RENSTRA (scope opd; verify/publish milik Kabupaten)
  ('renstra.version.view',         'Renstra — Lihat Version',          'OPD'),
  ('renstra.version.create',       'Renstra — Buat Version',           'OPD'),
  ('renstra.version.update_draft', 'Renstra — Ubah Draft Version',     'OPD'),
  ('renstra.version.submit',       'Renstra — Ajukan Version',         'OPD'),
  ('renstra.version.verify',       'Renstra — Verifikasi Version',     'Kabupaten'),
  ('renstra.version.publish',      'Renstra — Tetapkan Berlaku',       'Kabupaten'),
  -- IKU (dipakai dua scope; pembatasan scope di server)
  ('iku.version.view',             'IKU — Lihat Version',              'Umum'),
  ('iku.version.create',           'IKU — Buat Version',               'Umum'),
  ('iku.version.update_draft',     'IKU — Ubah Draft Version',         'Umum'),
  ('iku.version.submit',           'IKU — Ajukan Version',             'Umum'),
  ('iku.version.verify',           'IKU — Verifikasi Version',         'Kabupaten'),
  ('iku.version.publish',          'IKU — Tetapkan Berlaku',           'Kabupaten'),
  ('iku.version.sync',             'IKU — Sinkron dari Dokumen Perencanaan', 'Umum'),
  -- LAKIP
  ('lakip.version.view',           'LAKIP — Lihat Version',            'Umum'),
  ('lakip.version.create',         'LAKIP — Buat Version/Revisi',      'Umum'),
  ('lakip.version.update_draft',   'LAKIP — Ubah Draft Version',       'Umum'),
  ('lakip.version.submit',         'LAKIP — Ajukan Version',           'Umum'),
  ('lakip.version.verify',         'LAKIP — Verifikasi Version',       'Kabupaten'),
  ('lakip.version.publish',        'LAKIP — Tetapkan Berlaku',         'Kabupaten'),
  ('lakip.version.source_select',  'LAKIP — Pilih Source & Version',   'Umum'),
  -- CORRECTION
  ('version_correction.request',   'Koreksi Version — Ajukan',         'Umum'),
  ('version_correction.verify',    'Koreksi Version — Verifikasi',     'Kabupaten'),
  -- BENCHMARK (§38)
  ('lakip_benchmark.manage_own',   'Benchmark — Kelola Lingkup Sendiri', 'Umum'),
  ('lakip_benchmark.manage_all',   'Benchmark — Kelola Seluruh OPD',   'Kabupaten')
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `grup` = VALUES(`grup`);

-- ---------------------------------------------------------------------
-- 8.1  Pemberian ke role (§38 role concept)
--
--   admin              -> lolos semua lewat user_can() (tidak perlu baris)
--   admin_kab          -> kelola Kabupaten + verifikasi/publish seluruh modul
--   admin_opd/kecamatan-> kelola lingkup sendiri, HANYA sampai submit
--   admin_inspektorat  -> read-only
--   bupati             -> read-only, TIDAK diberi satu pun izin tulis
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r JOIN `permissions` p
  ON p.name IN (
    'rpjmd.version.view','rpjmd.version.create','rpjmd.version.update_draft',
    'rpjmd.version.submit','rpjmd.version.verify','rpjmd.version.publish',
    'renstra.version.view','renstra.version.verify','renstra.version.publish',
    'iku.version.view','iku.version.create','iku.version.update_draft',
    'iku.version.submit','iku.version.verify','iku.version.publish','iku.version.sync',
    'lakip.version.view','lakip.version.create','lakip.version.update_draft',
    'lakip.version.submit','lakip.version.verify','lakip.version.publish',
    'lakip.version.source_select',
    'version_correction.request','version_correction.verify',
    'lakip_benchmark.manage_own','lakip_benchmark.manage_all'
  )
WHERE r.name = 'admin_kab';

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r JOIN `permissions` p
  ON p.name IN (
    'renstra.version.view','renstra.version.create','renstra.version.update_draft','renstra.version.submit',
    'iku.version.view','iku.version.create','iku.version.update_draft','iku.version.submit','iku.version.sync',
    'lakip.version.view','lakip.version.create','lakip.version.update_draft','lakip.version.submit',
    'lakip.version.source_select',
    'version_correction.request',
    'lakip_benchmark.manage_own'
  )
WHERE r.name IN ('admin_opd', 'admin_kecamatan');

-- Read-only: hanya .view
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r JOIN `permissions` p
  ON p.name IN ('rpjmd.version.view','renstra.version.view','iku.version.view','lakip.version.view')
WHERE r.name IN ('admin_inspektorat', 'bupati');

-- ---------------------------------------------------------------------
-- 8.2  CABUT hak sahkan mandiri OPD atas revisi IKU
--
-- Keputusan: persetujuan versi IKU/Renstra OPD menjadi wewenang Admin
-- Kabupaten (§17). Membiarkan `iku_opd.revisi_sahkan` di tangan OPD berarti
-- menyisakan jalur pintas lewat menu "Revisi IKU" yang lama.
--
-- Permission-nya TIDAK DIHAPUS dari tabel `permissions` — hanya pemberiannya
-- ke role OPD yang dicabut, sehingga bisa dikembalikan bila perlu.
-- ---------------------------------------------------------------------
DELETE rp FROM `role_permissions` rp
JOIN `roles` r       ON r.id = rp.role_id
JOIN `permissions` p ON p.id = rp.permission_id
WHERE r.name IN ('admin_opd', 'admin_kecamatan')
  AND p.name = 'iku_opd.revisi_sahkan';


-- =====================================================================
-- BAGIAN 9 — BASELINE VERSION 1 PUBLISHED  (§43)
-- =====================================================================
-- "Jadikan data existing sebagai baseline Version 1 Published.
--  Jangan mengubah isi existing.
--  Tentukan effective_from berdasarkan data/periode aktual, jangan hardcode
--  satu tahun jika periode berbeda."  (§43)
--
-- effective_from = 1 Januari tahun awal periode MASING-MASING baris, diambil
-- dari data — bukan konstanta.
--
-- ISI ARSIPNYA SENGAJA TIDAK DIISI DI SINI. Membekukan kondisi live menuntut
-- terjemahan satuan dan penomoran urut yang sama persis dengan yang dipakai
-- aplikasi; melakukannya lewat SQL murni berisiko menghasilkan arsip yang
-- berbeda dari yang dihasilkan aplikasi. Pembekuannya dilakukan perintah spark
-- tersendiri, meniru IkuRevisiModel::pastikanBaseline().
--
-- INSERT ... SELECT ... WHERE NOT EXISTS = idempoten.
-- ---------------------------------------------------------------------

-- 9.1  RPJMD — periode diambil dari rpjmd_misi (tidak ada tabel kepala RPJMD)
INSERT INTO `dokumen_versi`
  (`modul`, `scope`, `opd_id`, `periode_mulai`, `periode_akhir`, `version_no`, `label`,
   `effective_from`, `effective_to`, `status`, `mulai_dari_kosong`, `catatan`, `created_at`, `approved_at`)
SELECT
  'rpjmd', 'kabupaten', NULL, x.tahun_mulai, x.tahun_akhir, 1,
  CONCAT('V1 — Kondisi Awal RPJMD ', x.tahun_mulai, '-', x.tahun_akhir),
  MAKEDATE(x.tahun_mulai, 1), NULL, 'published', 0,
  'Baseline otomatis saat pemasangan versioning (§43); isi arsip dibekukan terpisah.',
  NOW(), NOW()
FROM (
  SELECT DISTINCT CAST(`tahun_mulai` AS UNSIGNED) AS tahun_mulai,
                  CAST(`tahun_akhir` AS UNSIGNED) AS tahun_akhir
  FROM `rpjmd_misi`
  WHERE `tahun_mulai` IS NOT NULL AND `tahun_akhir` IS NOT NULL
) x
WHERE NOT EXISTS (
  SELECT 1 FROM `dokumen_versi` d
  WHERE d.modul = 'rpjmd' AND d.scope_key = 'kabupaten' AND d.opd_key = 0
    AND d.periode_mulai = x.tahun_mulai AND d.periode_akhir = x.tahun_akhir
);

-- 9.2  RENSTRA — satu baseline per (opd, periode)
INSERT INTO `dokumen_versi`
  (`modul`, `scope`, `opd_id`, `periode_mulai`, `periode_akhir`, `version_no`, `label`,
   `effective_from`, `effective_to`, `status`, `mulai_dari_kosong`, `catatan`, `created_at`, `approved_at`)
SELECT
  'renstra', 'opd', x.opd_id, x.tahun_mulai, x.tahun_akhir, 1,
  CONCAT('V1 — Kondisi Awal Renstra ', x.tahun_mulai, '-', x.tahun_akhir),
  MAKEDATE(x.tahun_mulai, 1), NULL, 'published', 0,
  'Baseline otomatis saat pemasangan versioning (§43); isi arsip dibekukan terpisah.',
  NOW(), NOW()
FROM (
  SELECT DISTINCT `opd_id`,
                  CAST(`tahun_mulai` AS UNSIGNED) AS tahun_mulai,
                  CAST(`tahun_akhir` AS UNSIGNED) AS tahun_akhir
  FROM `renstra_sasaran`
  WHERE `opd_id` IS NOT NULL AND `tahun_mulai` IS NOT NULL AND `tahun_akhir` IS NOT NULL
) x
WHERE NOT EXISTS (
  SELECT 1 FROM `dokumen_versi` d
  WHERE d.modul = 'renstra' AND d.scope_key = 'opd' AND d.opd_key = x.opd_id
    AND d.periode_mulai = x.tahun_mulai AND d.periode_akhir = x.tahun_akhir
);

-- 9.3  IKU — hanya bila tabel standalone sudah terisi.
--      Di basis aktif iku_sasaran masih 0 baris (data IKU masih di tabel legacy
--      `iku`), jadi blok ini belum menghasilkan apa pun sampai
--      db/migrasi_iku_standalone.php dijalankan. Itu disengaja: lebih baik
--      tidak ada baseline daripada baseline kosong yang menyesatkan.
INSERT INTO `dokumen_versi`
  (`modul`, `scope`, `opd_id`, `periode_mulai`, `periode_akhir`, `version_no`, `label`,
   `effective_from`, `effective_to`, `status`, `mulai_dari_kosong`, `catatan`, `created_at`, `approved_at`)
SELECT
  'iku',
  IF(x.opd_id IS NULL, 'kabupaten', 'opd'),
  x.opd_id, x.tahun_mulai, x.tahun_akhir, 1,
  CONCAT('V1 — Kondisi Awal IKU ', x.tahun_mulai, '-', x.tahun_akhir),
  MAKEDATE(x.tahun_mulai, 1), NULL, 'published', 0,
  'Baseline otomatis saat pemasangan versioning (§43); isi arsip dibekukan terpisah.',
  NOW(), NOW()
FROM (
  SELECT DISTINCT `opd_id`,
                  CAST(`tahun_mulai` AS UNSIGNED) AS tahun_mulai,
                  CAST(`tahun_akhir` AS UNSIGNED) AS tahun_akhir
  FROM `iku_sasaran`
  WHERE `tahun_mulai` IS NOT NULL AND `tahun_akhir` IS NOT NULL
) x
WHERE NOT EXISTS (
  SELECT 1 FROM `dokumen_versi` d
  WHERE d.modul = 'iku'
    AND d.scope_key = IF(x.opd_id IS NULL, 'kabupaten', 'opd')
    AND d.opd_key = COALESCE(x.opd_id, 0)
    AND d.periode_mulai = x.tahun_mulai AND d.periode_akhir = x.tahun_akhir
);

-- 9.4  Backfill version_id pada tabel live (§43 "Backfill version_id secara aman")
--      Hanya mengisi yang masih NULL; tidak ada nilai lama yang disentuh.
UPDATE `rpjmd_misi` m
JOIN `dokumen_versi` d
  ON d.modul = 'rpjmd' AND d.version_no = 1 AND d.opd_key = 0
 AND d.periode_mulai = CAST(m.tahun_mulai AS UNSIGNED)
 AND d.periode_akhir = CAST(m.tahun_akhir AS UNSIGNED)
SET m.version_id = d.id
WHERE m.version_id IS NULL;

UPDATE `renstra_sasaran` s
JOIN `dokumen_versi` d
  ON d.modul = 'renstra' AND d.version_no = 1 AND d.opd_key = s.opd_id
 AND d.periode_mulai = CAST(s.tahun_mulai AS UNSIGNED)
 AND d.periode_akhir = CAST(s.tahun_akhir AS UNSIGNED)
SET s.version_id = d.id
WHERE s.version_id IS NULL;

UPDATE `renstra_indikator_sasaran` i
JOIN `renstra_sasaran` s ON s.id = i.renstra_sasaran_id
SET i.version_id = s.version_id
WHERE i.version_id IS NULL AND s.version_id IS NOT NULL;

UPDATE `rpjmd_tujuan` t
JOIN `rpjmd_misi` m ON m.id = t.misi_id
SET t.version_id = m.version_id
WHERE t.version_id IS NULL AND m.version_id IS NOT NULL;

UPDATE `rpjmd_indikator_tujuan` it
JOIN `rpjmd_tujuan` t ON t.id = it.tujuan_id
SET it.version_id = t.version_id
WHERE it.version_id IS NULL AND t.version_id IS NOT NULL;

UPDATE `rpjmd_sasaran` s
JOIN `rpjmd_tujuan` t ON t.id = s.tujuan_id
SET s.version_id = t.version_id
WHERE s.version_id IS NULL AND t.version_id IS NOT NULL;

UPDATE `rpjmd_indikator_sasaran` i
JOIN `rpjmd_sasaran` s ON s.id = i.sasaran_id
SET i.version_id = s.version_id
WHERE i.version_id IS NULL AND s.version_id IS NOT NULL;

-- renstra_tujuan & renstra_indikator_tujuan SENGAJA tidak di-backfill di sini:
-- renstra_tujuan tidak punya opd_id maupun periode (temuan T4), dan 55 dari 112
-- barisnya sudah yatim. Menebak pemiliknya berarti mengarang kepemilikan.
-- Pengisiannya dilakukan perintah spark yang menelusuri lewat renstra_sasaran,
-- dan yang benar-benar yatim dilaporkan, bukan ditebak.

-- 9.5  Jejak audit untuk setiap baseline
INSERT INTO `version_submission_history`
  (`version_id`, `aksi`, `dari_status`, `ke_status`, `ringkasan`, `effective_from_saat_itu`, `pada`)
SELECT d.id, 'published', NULL, 'published',
       CONCAT('Baseline otomatis saat pemasangan versioning (', d.modul, ') — §43'),
       d.effective_from, NOW()
FROM `dokumen_versi` d
WHERE d.version_no = 1
  AND NOT EXISTS (
    SELECT 1 FROM `version_submission_history` h
    WHERE h.version_id = d.id AND h.aksi = 'published'
  );


-- =====================================================================
-- BERSIH-BERSIH
-- =====================================================================
DROP PROCEDURE IF EXISTS _dv_add_col_if_absent;
DROP PROCEDURE IF EXISTS _dv_add_idx_if_absent;
DROP PROCEDURE IF EXISTS _dv_add_fk_if_absent;
DROP PROCEDURE IF EXISTS _dv_pensiun_kolom;
DROP PROCEDURE IF EXISTS _dv_cek_prasyarat;

-- Selesai. Jalankan db/postdeploy_2026-08-20_versioning.sql untuk validasi (§59).


-- #####################################################################
-- ## BERKAS: update_2026-08-23_tampilan_utama_versi.sql
-- #####################################################################

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


-- #####################################################################
-- ## BERKAS: update_2026-08-24_izin_sunting.sql
-- #####################################################################

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


-- #####################################################################
-- ## BERKAS: update_2026-08-24_penyesuaian_sumber_iku.sql
-- #####################################################################

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


-- #####################################################################
-- ## BERKAS: update_2026-08-25_izin_sunting_iku_dan_analisis_iku.sql
-- #####################################################################

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


-- #####################################################################
-- ## BERKAS: update_2026-08-25_jejak_sumber_revisi_iku.sql
-- #####################################################################

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


-- #####################################################################
-- ## BERKAS: update_2026-08-26_benchmark_sumber_iku.sql
-- #####################################################################

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
