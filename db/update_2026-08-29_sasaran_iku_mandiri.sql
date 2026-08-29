-- =====================================================================
-- SASARAN IKU MANDIRI: BERI PENUNJUK TUJUAN RENSTRA-NYA SENDIRI
-- Tanggal : 2026-08-29
-- Sifat   : ADDITIVE & IDEMPOTEN. Menambah SATU kolom nullable.
--           Tidak ada DROP, DELETE, maupun perubahan nilai yang sudah ada.
--
-- =====================================================================
-- MASALAHNYA
--
-- Mengubah Renstra menuntut proses birokrasi yang ketat; IKU tidak. Maka
-- wajar bila sebuah sasaran perlu lahir di IKU lebih dulu, sebelum (atau
-- tanpa) ada padanannya di Renstra.
--
-- Tetapi tulang punggung matriks Cascading adalah:
--
--     Tujuan Renstra -> Sasaran Eselon II
--
-- dan `iku_sasaran` TIDAK punya kolom tujuan. Tujuannya selama ini dicapai
-- dua lompatan:
--
--     iku_sasaran.source_sasaran_id -> renstra_sasaran.renstra_tujuan_id
--                                   -> renstra_tujuan
--
-- Sasaran yang lahir di IKU tidak punya `source_sasaran_id`, sehingga
-- lompatan itu putus. Akibatnya, pada matriks OPD baris tersebut tampil
-- dengan EMPAT kolom kosong — Tujuan RPJMD, Sasaran RPJMD, Tujuan RENSTRA,
-- Indikator Tujuan — dan, karena `$groupKey` jatuh ke 'row_...' alih-alih
-- 'rt_...', ia berdiri sebagai PULAU sendiri yang tidak bisa berbagi blok
-- Tujuan dengan sasaran lain.
--
-- =====================================================================
-- OBATNYA
--
-- Satu kolom: sasaran yang lahir di IKU menunjuk sendiri tujuan Renstra
-- tempatnya bernaung. Sasaran hasil sync tidak menyentuh kolom ini sama
-- sekali — tujuannya tetap diturunkan lewat `source_sasaran_id` seperti
-- semula.
--
-- Ongkosnya satu kolom, hasilnya keempat kolom itu terisi sekaligus:
-- `renstra_tujuan` SUDAH tahu induk RPJMD-nya (`rpjmd_sasaran_id`, terisi
-- pada 114 dari 114 baris) dan sudah punya indikatornya sendiri lewat
-- `renstra_indikator_tujuan.tujuan_id`. Jadi menunjuk SATU tujuan
-- merangkai ulang seluruh separuh atas dokumen.
--
-- =====================================================================
-- LINGKUPNYA
--
-- OPD saja. IKU Kabupaten bernaung di bawah `rpjmd_tujuan`, dan matriks
-- kabupaten berpangkal di RPJMD — sasaran yang hanya ada di IKU tidak akan
-- muncul di sana sama sekali, jadi kolom ini tidak akan menolongnya.
-- Itu persoalan tersendiri, dan tidak dipaksakan masuk ke sini.
-- =====================================================================


-- =====================================================================
-- LANGKAH 1 - KEADAAN SEBELUM
-- =====================================================================

SELECT '========== SEBELUM ==========' AS `laporan`;

SELECT COUNT(*) AS kolom_renstra_tujuan_id_sudah_ada
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'iku_sasaran'
  AND COLUMN_NAME  = 'renstra_tujuan_id';


-- =====================================================================
-- LANGKAH 2 - TAMBAH KOLOM (idempoten)
-- =====================================================================

SET @ada := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'iku_sasaran'
      AND COLUMN_NAME  = 'renstra_tujuan_id'
);

SET @sql := IF(@ada = 0,
    'ALTER TABLE `iku_sasaran`
       ADD COLUMN `renstra_tujuan_id` INT UNSIGNED NULL
           COMMENT ''tujuan Renstra bagi sasaran yang LAHIR di IKU; NULL = ikut source_sasaran_id''
           AFTER `source_sasaran_id`',
    'SELECT ''kolom sudah ada, dilewati'' AS `laporan`');

PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;


-- =====================================================================
-- LANGKAH 3 - INDEKS & FOREIGN KEY (idempoten)
--
-- ON DELETE SET NULL, bukan CASCADE: tujuan Renstra yang dihapus tidak
-- boleh ikut menghapus sasaran IKU beserta seluruh cascading di bawahnya.
-- Barisnya kembali tak bertujuan — kelihatan, bisa diperbaiki, tidak hilang.
-- =====================================================================

SET @ada := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'iku_sasaran'
      AND INDEX_NAME   = 'idx_iku_sasaran_renstra_tujuan'
);

SET @sql := IF(@ada = 0,
    'ALTER TABLE `iku_sasaran`
       ADD KEY `idx_iku_sasaran_renstra_tujuan` (`renstra_tujuan_id`)',
    'SELECT ''indeks sudah ada, dilewati'' AS `laporan`');

PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

SET @ada := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA    = DATABASE()
      AND TABLE_NAME      = 'iku_sasaran'
      AND CONSTRAINT_NAME = 'fk_iku_sasaran_renstra_tujuan'
);

SET @sql := IF(@ada = 0,
    'ALTER TABLE `iku_sasaran`
       ADD CONSTRAINT `fk_iku_sasaran_renstra_tujuan`
       FOREIGN KEY (`renstra_tujuan_id`) REFERENCES `renstra_tujuan` (`id`)
       ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT ''foreign key sudah ada, dilewati'' AS `laporan`');

PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;


-- =====================================================================
-- LANGKAH 4 - PERIKSA HASIL
-- =====================================================================

SELECT '========== SESUDAH ==========' AS `laporan`;

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'iku_sasaran'
  AND COLUMN_NAME  = 'renstra_tujuan_id';

SELECT CONSTRAINT_NAME, DELETE_RULE
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND CONSTRAINT_NAME   = 'fk_iku_sasaran_renstra_tujuan';

SELECT '-- semua sasaran lama harus tetap NULL (0 = benar) --' AS `laporan`;

SELECT COUNT(*) AS sasaran_dengan_tujuan_mandiri
FROM iku_sasaran WHERE renstra_tujuan_id IS NOT NULL;
