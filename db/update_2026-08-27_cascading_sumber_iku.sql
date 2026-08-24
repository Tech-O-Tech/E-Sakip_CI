-- =====================================================================
-- CASCADING: JANGKAR GANDA (Renstra + IKU) & PENUTUPAN LUBANG CASCADE
-- Tanggal : 2026-08-27
-- Sifat   : IDEMPOTEN & ADDITIVE. Tidak ada DROP TABLE / DELETE / TRUNCATE
--           terhadap data cascading. Aman dijalankan berulang.
--
-- Prasyarat: db/deploy_2026-08-24_server_pringsewu.sql sudah dijalankan
--            (butuh kolom iku_indikator.dihentikan_pada & source_indikator_id).
--
-- =====================================================================
-- MASALAH 1 - CASCADING TIDAK BISA BERPINDAH SUMBER
--
-- `cascading_sasaran_opd.renstra_indikator_sasaran_id` adalah INT NOT NULL
-- dengan FK ke `renstra_indikator_sasaran`. Selama kolom itu wajib, cascading
-- MUSTAHIL berjangkar ke IKU: tidak ada tempat menyimpan id indikator IKU.
--
-- MASALAH 2 - PENGHAPUS SENYAP (lebih gawat)
--
-- FK-nya ON DELETE CASCADE. Sementara itu app/Models/Opd/RenstraModel.php
-- baris 674-690 menghapus indikator sasaran yang tidak ikut terkirim saat
-- sasaran Renstra disunting - dan bila `keepIndikatorSasaranIds` KOSONG,
-- klausa whereNotIn dilewati sehingga SELURUH indikator sasaran itu terhapus.
--
-- Akibatnya, satu penyuntingan Renstra yang wajar bisa memusnahkan seluruh
-- pohon cascading (es3 -> es4 -> pelaksana) milik sasaran tersebut, tanpa
-- peringatan dan tanpa jejak. `cascading_indikator_opd` ikut lenyap lewat
-- `fk_indikator_cascading` yang juga CASCADE.
--
-- =====================================================================
-- YANG DIUBAH
--
--   + kolom `iku_indikator_id`  - jangkar ke IKU (NULL = belum dipetakan)
--   + kolom `source_type`       - 'renstra' | 'iku', sumber yang dipakai baris
--   ~ kolom `renstra_indikator_sasaran_id` -> NULL-able
--   ~ FK   `fk_cascading_renstra_indikator` : CASCADE -> SET NULL
--   + FK   `fk_cascading_iku_indikator`     : SET NULL
--   ~ backfill `iku_indikator_id` untuk padanan yang TUNGGAL & TIDAK AMBIGU
--   ~ backfill `iku_indikator.source_indikator_id` (jembatan realisasi LAKIP)
--
-- SET NULL, bukan RESTRICT: RESTRICT akan melempar exception yang tidak
-- ditangani RenstraModel dan membuat penyuntingan Renstra gagal total.
-- Dengan SET NULL, baris cascading BERTAHAN HIDUP; dan bila `iku_indikator_id`
-- sudah terisi, baris itu tidak terpengaruh sama sekali oleh penghapusan
-- indikator Renstra.
--
-- Pemetaan hanya diisi bila padanannya TEPAT SATU. Padanan ganda sengaja
-- dibiarkan NULL supaya jatuh ke fallback Renstra, bukan ditebak.
-- =====================================================================

SET @db := DATABASE();

DROP PROCEDURE IF EXISTS _casc_add_col;
DROP PROCEDURE IF EXISTS _casc_add_idx;

DELIMITER //

CREATE PROCEDURE _casc_add_col(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = p_table AND COLUMN_NAME = p_col) THEN
    SET @s := p_ddl; PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END //

CREATE PROCEDURE _casc_add_idx(IN p_table VARCHAR(64), IN p_idx VARCHAR(64), IN p_ddl TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = p_table AND INDEX_NAME = p_idx) THEN
    SET @s := p_ddl; PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END //

DELIMITER ;

-- ---------------------------------------------------------------------
-- 1. KOLOM JANGKAR BARU
-- ---------------------------------------------------------------------
CALL _casc_add_col('cascading_sasaran_opd', 'iku_indikator_id',
  'ALTER TABLE `cascading_sasaran_opd` ADD COLUMN `iku_indikator_id` INT UNSIGNED NULL COMMENT ''jangkar IKU; NULL = belum dipetakan, baris jatuh ke Renstra'' AFTER `renstra_indikator_sasaran_id`');

-- DEFAULT 'renstra' disengaja: CascadingController belum menulis kolom ini,
-- jadi tanpa default setiap baris cascading BARU akan lahir ber-source_type
-- NULL dan kode terpaksa menebak artinya. Dengan default, baris baru otomatis
-- benar (masih berjangkar Renstra) sampai kodenya diperbarui.
CALL _casc_add_col('cascading_sasaran_opd', 'source_type',
  'ALTER TABLE `cascading_sasaran_opd` ADD COLUMN `source_type` VARCHAR(10) NOT NULL DEFAULT ''renstra'' COMMENT ''renstra | iku - sumber yang dipakai baris ini'' AFTER `iku_indikator_id`');

-- Bila kolomnya terlanjur ada tanpa default (dari percobaan sebelumnya),
-- NULL yang tersisa harus diisi LEBIH DULU: konversi NULL -> NOT NULL akan
-- ditolak MySQL dalam strict mode.
UPDATE cascading_sasaran_opd SET source_type = 'renstra' WHERE source_type IS NULL;

SET @tanpaDefault := (SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cascading_sasaran_opd'
                        AND COLUMN_NAME = 'source_type'
                        AND (COLUMN_DEFAULT IS NULL OR IS_NULLABLE = 'YES'));

SET @s := IF(@tanpaDefault > 0,
  'ALTER TABLE `cascading_sasaran_opd` MODIFY COLUMN `source_type` VARCHAR(10) NOT NULL DEFAULT ''renstra'' COMMENT ''renstra | iku - sumber yang dipakai baris ini''',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

CALL _casc_add_idx('cascading_sasaran_opd', 'idx_cascading_iku_indikator',
  'ALTER TABLE `cascading_sasaran_opd` ADD KEY `idx_cascading_iku_indikator` (`iku_indikator_id`)');

CALL _casc_add_idx('cascading_sasaran_opd', 'idx_cascading_source',
  'ALTER TABLE `cascading_sasaran_opd` ADD KEY `idx_cascading_source` (`source_type`)');

-- ---------------------------------------------------------------------
-- 2. LONGGARKAN JANGKAR RENSTRA MENJADI NULL-ABLE
--    Wajib: tanpa ini, baris tidak bisa kehilangan induk Renstra-nya
--    tanpa ikut terhapus.
-- ---------------------------------------------------------------------
SET @perlu := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cascading_sasaran_opd'
                 AND COLUMN_NAME = 'renstra_indikator_sasaran_id' AND IS_NULLABLE = 'NO');

SET @s := IF(@perlu > 0,
  'ALTER TABLE `cascading_sasaran_opd` MODIFY COLUMN `renstra_indikator_sasaran_id` INT UNSIGNED NULL COMMENT ''jangkar Renstra; NULL bila induknya sudah dihapus''',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 3. GANTI FK RENSTRA: CASCADE -> SET NULL   (penutup lubang utama)
-- ---------------------------------------------------------------------
SET @adaCascade := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE()
                      AND CONSTRAINT_NAME = 'fk_cascading_renstra_indikator'
                      AND DELETE_RULE = 'CASCADE');

SET @s := IF(@adaCascade > 0,
  'ALTER TABLE `cascading_sasaran_opd` DROP FOREIGN KEY `fk_cascading_renstra_indikator`',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @adaFk := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_cascading_renstra_indikator');

SET @s := IF(@adaFk = 0,
  'ALTER TABLE `cascading_sasaran_opd` ADD CONSTRAINT `fk_cascading_renstra_indikator` FOREIGN KEY (`renstra_indikator_sasaran_id`) REFERENCES `renstra_indikator_sasaran` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 4. FK JANGKAR IKU
-- ---------------------------------------------------------------------
SET @adaFkIku := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                  WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_cascading_iku_indikator');

SET @s := IF(@adaFkIku = 0,
  'ALTER TABLE `cascading_sasaran_opd` ADD CONSTRAINT `fk_cascading_iku_indikator` FOREIGN KEY (`iku_indikator_id`) REFERENCES `iku_indikator` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 5. PETA PADANAN Renstra -> IKU
--    Kunci cocok: opd_id + periode + teks sasaran + teks indikator,
--    dinormalkan persis seperti IkuModel::normalkanTeks()
--    (huruf kecil lewat collation _general_ci, spasi dirapatkan, di-trim).
--    Baris IKU yang sudah dipensiunkan TIDAK ikut dicocokkan.
-- ---------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS _peta_iku;

CREATE TEMPORARY TABLE _peta_iku (
  renstra_ind_id INT UNSIGNED NOT NULL,
  iku_ind_id     INT UNSIGNED NOT NULL,
  jml_padanan    INT NOT NULL,
  PRIMARY KEY (renstra_ind_id)
) ENGINE=InnoDB;

SET @punyaPensiun := (SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'iku_indikator'
                        AND COLUMN_NAME = 'dihentikan_pada');

SET @s := CONCAT(
'INSERT INTO _peta_iku (renstra_ind_id, iku_ind_id, jml_padanan)
 SELECT ri.id, MIN(ii.id), COUNT(DISTINCT ii.id)
 FROM renstra_indikator_sasaran ri
 JOIN renstra_sasaran rs ON rs.id = ri.renstra_sasaran_id
 JOIN iku_sasaran isx
   ON isx.opd_id      = rs.opd_id
  AND isx.tahun_mulai = rs.tahun_mulai
  AND isx.tahun_akhir = rs.tahun_akhir
  AND TRIM(REGEXP_REPLACE(isx.sasaran, ''[[:space:]]+'', '' ''))
    = TRIM(REGEXP_REPLACE(rs.sasaran,  ''[[:space:]]+'', '' ''))
 JOIN iku_indikator ii
   ON ii.iku_sasaran_id = isx.id
  AND TRIM(REGEXP_REPLACE(ii.indikator,         ''[[:space:]]+'', '' ''))
    = TRIM(REGEXP_REPLACE(ri.indikator_sasaran, ''[[:space:]]+'', '' ''))
 WHERE 1 = 1 ',
  IF(@punyaPensiun > 0, ' AND ii.dihentikan_pada IS NULL AND isx.dihentikan_pada IS NULL ', ''),
' GROUP BY ri.id');

PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Padanan ganda dibuang: menebak lebih buruk daripada jatuh ke fallback.
DELETE FROM _peta_iku WHERE jml_padanan <> 1;

-- ---------------------------------------------------------------------
-- 6. BACKFILL JANGKAR CASCADING
--    Hanya mengisi yang masih NULL - pemetaan manual tidak akan tertimpa.
-- ---------------------------------------------------------------------
UPDATE cascading_sasaran_opd c
JOIN _peta_iku p ON p.renstra_ind_id = c.renstra_indikator_sasaran_id
SET c.iku_indikator_id = p.iku_ind_id,
    c.source_type      = 'iku'
WHERE c.iku_indikator_id IS NULL;

-- Sisanya ditandai tegas sebagai pembaca Renstra, bukan dibiarkan NULL,
-- supaya kode tidak perlu menebak arti NULL.
UPDATE cascading_sasaran_opd
SET source_type = 'renstra'
WHERE source_type IS NULL;

-- ---------------------------------------------------------------------
-- 7. BACKFILL JEMBATAN REALISASI LAKIP
--    `iku_indikator.source_indikator_id` adalah satu-satunya penghubung
--    realisasi LAKIP lama (berkunci Renstra) ke baris bersumber IKU.
--    Diisi hanya bila masih kosong - hasil sync tidak akan tertimpa.
-- ---------------------------------------------------------------------
SET @punyaSumber := (SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'iku_indikator'
                       AND COLUMN_NAME = 'source_indikator_id');

SET @s := IF(@punyaSumber > 0,
'UPDATE iku_indikator ii
 JOIN _peta_iku p ON p.iku_ind_id = ii.id
 SET ii.source_indikator_id = p.renstra_ind_id,
     ii.source_type = COALESCE(ii.source_type, ''renstra'')
 WHERE ii.source_indikator_id IS NULL',
'DO 0');

PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

DROP TEMPORARY TABLE IF EXISTS _peta_iku;
DROP PROCEDURE IF EXISTS _casc_add_col;
DROP PROCEDURE IF EXISTS _casc_add_idx;

-- =====================================================================
-- LAPORAN
-- =====================================================================
SELECT '========== HASIL PEMETAAN CASCADING ==========' AS `laporan`;

SELECT source_type AS sumber, COUNT(*) AS baris
FROM cascading_sasaran_opd
GROUP BY source_type
ORDER BY 2 DESC;

SELECT '========== SISA YANG BELUM TERPETAKAN (per OPD) ==========' AS `laporan`;

SELECT o.id AS opd_id, o.nama_opd,
       COUNT(*) AS baris_belum_terpetakan,
       IF((SELECT COUNT(*) FROM iku_sasaran s WHERE s.opd_id = o.id) = 0,
          'IKU BELUM DIINPUT -> jalankan Sync IKU dari Renstra',
          'IKU ada tapi teksnya beda -> perlu pemetaan manual') AS tindak_lanjut
FROM cascading_sasaran_opd c
JOIN opd o ON o.id = c.opd_id
WHERE c.iku_indikator_id IS NULL
GROUP BY o.id, o.nama_opd
ORDER BY 3 DESC;

SELECT '========== FK SESUDAH PERUBAHAN ==========' AS `laporan`;

-- Hanya FK ke DOKUMEN LUAR (Renstra / IKU) yang wajib SET NULL.
-- `fk_cascading_parent` dan `fk_cascading_sasaran_opd` memang HARUS CASCADE:
-- menghapus node induk sudah seharusnya menghapus anak es4/pelaksana-nya,
-- dan menghapus OPD sudah seharusnya menghapus cascading miliknya.
SELECT rc.CONSTRAINT_NAME AS fk, rc.DELETE_RULE AS aturan_hapus,
       CASE
         WHEN rc.CONSTRAINT_NAME IN ('fk_cascading_parent', 'fk_cascading_sasaran_opd')
           THEN 'OK - cascade memang disengaja'
         WHEN rc.DELETE_RULE = 'CASCADE'
           THEN 'GAGAL - jangkar dokumen tidak boleh CASCADE'
         ELSE 'OK'
       END AS hasil
FROM information_schema.REFERENTIAL_CONSTRAINTS rc
WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
  AND rc.TABLE_NAME = 'cascading_sasaran_opd'
ORDER BY 1;
