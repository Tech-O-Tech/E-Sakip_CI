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
