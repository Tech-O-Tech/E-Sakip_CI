-- =====================================================================
-- TANDAI INDIKATOR YANG LAHIR DI IKU
-- Tanggal : 2026-08-29
-- Sifat   : IDEMPOTEN. Hanya MENGISI `source_type` yang masih NULL pada
--           indikator yang memang tidak punya padanan di dokumen sumber.
--           Tidak ada DROP, DELETE, maupun perubahan teks.
--
-- =====================================================================
-- MENGAPA PERLU
--
-- Mode Ganti pada layar Sync membuang isi IKU yang tidak ada di dokumen
-- sumber. Ia punya empat penjaga — sudah dijangkar cascading, sudah dipakai
-- LAKIP, sudah masuk arsip revisi, atau sudah punya program — dan baris yang
-- kena salah satunya tidak pernah dibuang.
--
-- Ada satu baris yang lolos keempatnya:
--
--     Indeks Ketahanan Pangan (IKP)
--
-- IKP meringkas LIMA indikator RPJMD (Produksi Padi, Jagung, Daging, Telur,
-- Perikanan) menjadi satu indeks. Ia SENGAJA tidak punya padanan
-- satu-lawan-satu, belum dijangkar cascading, belum dipakai LAKIP, dan belum
-- punya arsip revisi. Tanpa penanda, satu centang Ganti di layar Sync
-- Kabupaten akan menghapusnya beserta seluruh targetnya.
--
-- `source_type = 'iku'` berarti: baris ini LAHIR di IKU, bukan salinan yang
-- kehilangan induknya. Penjaga mode Ganti melewatinya.
--
-- =====================================================================
-- YANG SENGAJA TIDAK DISENTUH
--
-- 1. Indikator yang `source_indikator_id`-nya TERISI. Ia memang salinan, dan
--    penandaannya akan berbohong.
--
-- 2. SELURUH INDIKATOR OPD.
--
--    Ini pembatasan yang penting. Saat diuji pada salinan basis data server
--    (29 Agustus), versi tanpa pembatasan ini menandai TUJUH indikator —
--    enam di antaranya milik OPD, dan bukan satu pun yang sengaja dibuat
--    mandiri. Dua dari enam itu bahkan punya padanan PERSIS di Renstra;
--    yang mereka butuhkan adalah ditautkan lewat Sync, bukan dinyatakan
--    berdiri sendiri selamanya.
--
--    Menandai mereka berarti mengebalkan mereka dari mode Ganti untuk
--    seterusnya — keputusan yang seharusnya diambil manusia yang tahu
--    dokumennya, bukan sebuah skrip yang menebak dari kolom kosong.
--
--    Indikator mandiri OPD yang SUNGGUHAN kini ditandai sejak lahir oleh
--    `AdminOpd\IkuController::save()`, jadi tidak ada yang perlu disusulkan
--    di sini.
-- =====================================================================


-- =====================================================================
-- LANGKAH 1 - KEADAAN SEBELUM
-- =====================================================================

SELECT '========== SEBELUM ==========' AS `laporan`;

SELECT IFNULL(source_type, '(NULL)') AS source_type, COUNT(*) AS jml
FROM iku_indikator
GROUP BY source_type
ORDER BY source_type;

SELECT '-- calon yang akan ditandai --' AS `laporan`;

SELECT ii.id,
       ii.indikator,
       CASE WHEN s.opd_id IS NULL THEN 'KABUPATEN' ELSE CONCAT('OPD ', s.opd_id) END AS lingkup,
       s.sasaran
FROM iku_indikator ii
JOIN iku_sasaran s ON s.id = ii.iku_sasaran_id
WHERE ii.source_type        IS NULL
  AND ii.source_indikator_id IS NULL
  AND ii.dihentikan_pada     IS NULL
  AND s.opd_id               IS NULL
ORDER BY ii.id;


-- =====================================================================
-- LANGKAH 2 - TANDAI
-- =====================================================================

UPDATE iku_indikator ii
JOIN iku_sasaran s ON s.id = ii.iku_sasaran_id
SET ii.source_type = 'iku',
    ii.updated_at  = NOW()
WHERE ii.source_type        IS NULL
  AND ii.source_indikator_id IS NULL
  AND ii.dihentikan_pada     IS NULL
  AND s.opd_id               IS NULL;


-- =====================================================================
-- LANGKAH 3 - PERIKSA HASIL
-- =====================================================================

SELECT '========== SESUDAH ==========' AS `laporan`;

SELECT IFNULL(source_type, '(NULL)') AS source_type, COUNT(*) AS jml
FROM iku_indikator
GROUP BY source_type
ORDER BY source_type;

SELECT '-- pemeriksaan: adakah salinan yang ikut tertandai keliru? (harus kosong) --' AS `laporan`;

SELECT id, indikator, source_type, source_indikator_id
FROM iku_indikator
WHERE source_type = 'iku' AND source_indikator_id IS NOT NULL;

SELECT '-- indikator OPD yang SENGAJA DILEWATI (perlu keputusan Anda) --' AS `laporan`;

SELECT ii.id,
       o.nama_opd,
       ii.indikator,
       (SELECT COUNT(*) FROM renstra_sasaran rs
          JOIN renstra_indikator_sasaran ri ON ri.renstra_sasaran_id = rs.id
         WHERE rs.opd_id      = s.opd_id
           AND rs.tahun_mulai = s.tahun_mulai
           AND TRIM(REGEXP_REPLACE(ri.indikator_sasaran, '[[:space:]]+', ' '))
             = TRIM(REGEXP_REPLACE(ii.indikator, '[[:space:]]+', ' '))
       ) AS ada_padanan_persis_di_renstra
FROM iku_indikator ii
JOIN iku_sasaran s ON s.id = ii.iku_sasaran_id
LEFT JOIN opd o    ON o.id = s.opd_id
WHERE ii.source_type        IS NULL
  AND ii.source_indikator_id IS NULL
  AND ii.dihentikan_pada     IS NULL
  AND s.opd_id               IS NOT NULL
ORDER BY o.nama_opd, ii.id;
