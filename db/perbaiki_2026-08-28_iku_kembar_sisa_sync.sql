-- =====================================================================
-- BUANG INDIKATOR IKU KEMBAR YANG LAHIR DARI SYNC
-- Tanggal : 2026-08-28
-- Sifat   : MENGHAPUS DATA, tetapi hanya baris yang terbukti TIDAK
--           MEMBAWA APA-APA. Idempoten.
--
-- =====================================================================
-- MASALAHNYA
--
-- Sync IKU mencocokkan lewat silsilah dulu, teks belakangan. Indikator IKU
-- yang diketik manual TIDAK punya silsilah; bila redaksinya juga berbeda
-- sedikit dari Renstra, sync menganggapnya indikator baru lalu menyalin
-- versi Renstra di sebelahnya. Lahirlah dua baris untuk satu hal:
--
--   "Persentase Kepemilikan Akta Kematian bagi yang melaporkan"  (lama, tanpa silsilah)
--   "Persentase Kepemilikan Akta Kematian yang dilaporkan"       (hasil sync, bersilsilah)
--
-- Perbaikan kode sudah mencegah HAL INI TERULANG untuk baris yang sekali
-- tertaut. Yang terlanjur kembar tetap harus dirapikan sekali.
--
-- =====================================================================
-- YANG DIBUANG — dan hanya yang memenuhi SEMUANYA
--
--   1. tanpa silsilah (`source_indikator_id` kosong);
--   2. ADA saudara sekandung di sasaran yang sama yang BERSILSILAH;
--   3. targetnya SAMA PERSIS dengan saudara itu, tahun demi tahun;
--   4. definisi, rumusan, sumber data, dan penanggung jawab semuanya kosong
--      — jadi tidak ada tulisan operator yang ikut hilang;
--   5. tidak dirujuk baris cascading, realisasi LAKIP, arsip revisi,
--      maupun iku_program.
--
-- Satu syarat saja tidak terpenuhi, barisnya DIBIARKAN. Indikator IKU yang
-- memang berdiri sendiri di luar Renstra tidak akan tersentuh: ia gagal di
-- syarat 2 atau 3.
-- =====================================================================


-- =====================================================================
-- LANGKAH 1 - CALON YANG AKAN DIBUANG
-- =====================================================================

DROP TEMPORARY TABLE IF EXISTS _kembar_sync;

CREATE TEMPORARY TABLE _kembar_sync (
    buang    INT UNSIGNED NOT NULL PRIMARY KEY,
    saudara  INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

INSERT INTO _kembar_sync (buang, saudara)
SELECT a.id, MIN(b.id)
FROM iku_indikator a
JOIN iku_sasaran s  ON s.id = a.iku_sasaran_id
JOIN iku_indikator b
  ON b.iku_sasaran_id = a.iku_sasaran_id
 AND b.id <> a.id
 AND b.source_indikator_id IS NOT NULL
 AND b.dihentikan_pada IS NULL
WHERE a.source_indikator_id IS NULL
  AND a.dihentikan_pada IS NULL
  AND s.opd_id IS NOT NULL
  -- (4) tidak ada tulisan operator yang hilang
  AND a.definisi IS NULL AND a.rumusan_perhitungan IS NULL
  AND a.sumber_data IS NULL AND a.penanggung_jawab IS NULL
  -- (5) tidak dirujuk siapa pun
  AND NOT EXISTS (SELECT 1 FROM cascading_sasaran_opd c WHERE c.iku_indikator_id = a.id)
  AND NOT EXISTS (SELECT 1 FROM iku_program p          WHERE p.iku_indikator_id = a.id)
  AND NOT EXISTS (SELECT 1 FROM iku_revisi_indikator r WHERE r.sumber_indikator_id = a.id)
  AND NOT EXISTS (SELECT 1 FROM lakip l WHERE l.source_type = 'iku' AND l.source_entity_id = a.id)
  -- (3) target sama persis, tahun demi tahun, di kedua arah
  AND NOT EXISTS (
      SELECT 1 FROM iku_target ta
      WHERE ta.iku_indikator_id = a.id
        AND NOT EXISTS (SELECT 1 FROM iku_target tb
                         WHERE tb.iku_indikator_id = b.id
                           AND tb.tahun = ta.tahun
                           AND COALESCE(tb.target,'') = COALESCE(ta.target,''))
  )
  AND NOT EXISTS (
      SELECT 1 FROM iku_target tb
      WHERE tb.iku_indikator_id = b.id
        AND NOT EXISTS (SELECT 1 FROM iku_target ta
                         WHERE ta.iku_indikator_id = a.id
                           AND ta.tahun = tb.tahun
                           AND COALESCE(ta.target,'') = COALESCE(tb.target,''))
  )
GROUP BY a.id;

SELECT '========== YANG AKAN DIBUANG ==========' AS `laporan`;

SELECT k.buang AS id_dibuang, o.nama_opd AS opd,
       ab.indikator AS teks_dibuang,
       k.saudara AS id_dipertahankan,
       sb.indikator AS teks_dipertahankan
FROM _kembar_sync k
JOIN iku_indikator ab ON ab.id = k.buang
JOIN iku_indikator sb ON sb.id = k.saudara
JOIN iku_sasaran s ON s.id = ab.iku_sasaran_id
LEFT JOIN opd o ON o.id = s.opd_id;


-- =====================================================================
-- LANGKAH 2 - BUANG
--    Targetnya ikut lewat ON DELETE CASCADE.
-- =====================================================================

DELETE ii FROM iku_indikator ii
JOIN _kembar_sync k ON k.buang = ii.id;

DROP TEMPORARY TABLE IF EXISTS _kembar_sync;


-- =====================================================================
-- LANGKAH 3 - PERIKSA HASIL
-- =====================================================================

SELECT '========== SISA INDIKATOR IKU TANPA SILSILAH ==========' AS `laporan`;

SELECT o.nama_opd AS opd, ii.id, ii.indikator,
       IF(ii.definisi IS NULL,'-','ada')            AS definisi,
       IF(ii.rumusan_perhitungan IS NULL,'-','ada') AS rumusan,
       (SELECT COUNT(*) FROM cascading_sasaran_opd c WHERE c.iku_indikator_id = ii.id) AS baris_cascading
FROM iku_indikator ii
JOIN iku_sasaran s ON s.id = ii.iku_sasaran_id
LEFT JOIN opd o ON o.id = s.opd_id
WHERE ii.source_indikator_id IS NULL
  AND ii.dihentikan_pada IS NULL
  AND s.opd_id IS NOT NULL
ORDER BY o.nama_opd, ii.id;
