-- =====================================================================
-- SAMAKAN REDAKSI SASARAN IKU BAPPERIDA DENGAN RENSTRA
-- Tanggal : 2026-08-28
-- Sifat   : IDEMPOTEN. Hanya MENYUNTING SATU teks sasaran dan memasang
--           silsilahnya. Tidak ada INSERT, DELETE, maupun perubahan
--           indikator, definisi, rumusan, satuan, baseline, atau target.
--
-- =====================================================================
-- MASALAHNYA
--
--   RENSTRA : "Meningkatnya Kualitas Perencanaan Kinerja Pemerintah Daerah"
--   IKU     : "Meningkatnya Kualitas Perencanaan kinerja Pembangunan Daerah"
--                                                   ^^^^^^^^^^^
--
-- Beda huruf besar/kecil tidak menjadi soal — pencocokan memakai collation
-- yang mengabaikannya. Yang menghalangi hanya satu kata.
--
-- =====================================================================
-- MENGAPA RENSTRA YANG DIPAKAI SEBAGAI ACUAN
--
-- 1. INDIKATOR DI BAWAH KEDUA SASARAN ITU IDENTIK, karakter demi karakter:
--    "Nilai Komponen Perencanaan SAKIP Pemda Pringsewu". Kalau sasarannya
--    memang dua hal yang berbeda, indikatornya tidak akan sama persis.
--
-- 2. SAKIP = Sistem Akuntabilitas Kinerja Instansi PEMERINTAH. Indikatornya
--    mengukur komponen perencanaan pada penilaian SAKIP, sehingga
--    "Kinerja Pemerintah Daerah" yang runtut, bukan "kinerja Pembangunan".
--
-- 3. Dua sasaran Bapperida yang lain sudah sama persis dengan Renstra dan
--    sudah bersilsilah. Yang ini satu-satunya yang menyimpang.
--
-- Renstra adalah dokumen formalnya; IKU diturunkan darinya. Maka IKU yang
-- disesuaikan, bukan sebaliknya.
--
-- =====================================================================
-- DAMPAK YANG DIHARAPKAN
--
-- 27 baris cascading Bapperida yang selama ini membaca Renstra akan bisa
-- berjangkar ke IKU. Jangkarnya TIDAK dipasang oleh naskah ini — jalankan
-- sesudahnya:
--
--     mysql -u USER -p NAMA_DB < db/update_2026-08-27_cascading_sumber_iku.sql
-- =====================================================================


-- =====================================================================
-- LANGKAH 1 - LIHAT DULU
-- =====================================================================

SELECT '========== SEBELUM ==========' AS `laporan`;

SELECT s.id AS iku_sasaran_id,
       TRIM(REGEXP_REPLACE(s.sasaran, '[[:space:]]+', ' ')) AS teks_iku,
       s.source_sasaran_id AS silsilah,
       (SELECT COUNT(*) FROM cascading_sasaran_opd c
         WHERE c.opd_id = 7 AND c.iku_indikator_id IS NULL) AS casc_belum_berjangkar
FROM iku_sasaran s
WHERE s.opd_id = 7
ORDER BY s.id;


-- =====================================================================
-- LANGKAH 2 - CARI PASANGANNYA
--
-- Sasaran Renstra dikenali dari INDIKATORNYA, bukan dari teks sasarannya
-- (teks itulah yang justru sedang berbeda). Indikator yang identik di
-- kedua sisi adalah jembatan yang paling tidak meragukan.
-- =====================================================================

SET @renstra_sasaran := (
    SELECT rs.id
    FROM renstra_sasaran rs
    JOIN renstra_indikator_sasaran ri ON ri.renstra_sasaran_id = rs.id
    WHERE rs.opd_id = 7
      AND TRIM(REGEXP_REPLACE(ri.indikator_sasaran, '[[:space:]]+', ' '))
        = 'Nilai Komponen Perencanaan SAKIP Pemda Pringsewu'
    LIMIT 1
);

SET @iku_sasaran := (
    SELECT s.id
    FROM iku_sasaran s
    JOIN iku_indikator ii ON ii.iku_sasaran_id = s.id
    WHERE s.opd_id = 7
      AND s.dihentikan_pada IS NULL
      AND TRIM(REGEXP_REPLACE(ii.indikator, '[[:space:]]+', ' '))
        = 'Nilai Komponen Perencanaan SAKIP Pemda Pringsewu'
    LIMIT 1
);

SET @teks_benar := (SELECT sasaran FROM renstra_sasaran WHERE id = @renstra_sasaran);

SELECT IF(@renstra_sasaran IS NULL OR @iku_sasaran IS NULL,
          'BATAL - pasangannya tidak ketemu, tidak ada yang diubah',
          CONCAT('Akan disamakan: IKU #', @iku_sasaran, ' mengikuti Renstra #', @renstra_sasaran)) AS `penjaga`;


-- =====================================================================
-- LANGKAH 3 - SAMAKAN TEKSNYA & PASANG SILSILAHNYA
--
-- Hanya kolom `sasaran` dan jejak asalnya yang disentuh. Indikator,
-- definisi, rumusan, satuan, baseline, target, dan urutan tidak ikut.
-- =====================================================================

UPDATE iku_sasaran
SET sasaran           = @teks_benar,
    source_sasaran_id = COALESCE(source_sasaran_id, @renstra_sasaran),
    source_type       = COALESCE(source_type, 'renstra'),
    updated_at        = NOW()
WHERE id = @iku_sasaran
  AND @renstra_sasaran IS NOT NULL
  AND @iku_sasaran IS NOT NULL;


-- =====================================================================
-- LANGKAH 4 - PERIKSA HASIL
-- =====================================================================

SELECT '========== SESUDAH ==========' AS `laporan`;

SELECT s.id AS iku_sasaran_id,
       TRIM(REGEXP_REPLACE(s.sasaran, '[[:space:]]+', ' ')) AS teks_iku,
       s.source_sasaran_id AS silsilah
FROM iku_sasaran s
WHERE s.opd_id = 7
ORDER BY s.id;

SELECT '-- teks IKU vs Renstra: harus SAMA --' AS `laporan`;

SELECT TRIM(REGEXP_REPLACE(s.sasaran,  '[[:space:]]+', ' ')) AS teks_iku,
       TRIM(REGEXP_REPLACE(rs.sasaran, '[[:space:]]+', ' ')) AS teks_renstra,
       IF(TRIM(REGEXP_REPLACE(s.sasaran,  '[[:space:]]+', ' '))
        = TRIM(REGEXP_REPLACE(rs.sasaran, '[[:space:]]+', ' ')), 'SAMA', 'MASIH BEDA') AS hasil
FROM iku_sasaran s
JOIN renstra_sasaran rs ON rs.id = s.source_sasaran_id
WHERE s.opd_id = 7 AND s.source_type = 'renstra';

SELECT 'Jalankan db/update_2026-08-27_cascading_sumber_iku.sql sesudah ini supaya 27 baris cascading Bapperida ikut berjangkar.' AS `langkah_berikutnya`;
