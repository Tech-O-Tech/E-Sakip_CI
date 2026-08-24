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
