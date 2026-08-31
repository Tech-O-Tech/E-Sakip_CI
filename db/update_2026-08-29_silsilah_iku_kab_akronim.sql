-- =====================================================================
-- SILSILAH IKU KABUPATEN: PADANAN YANG HANYA BEDA AKRONIM
-- Tanggal : 2026-08-29
-- Sifat   : IDEMPOTEN & ADDITIVE. Hanya MENGISI `source_indikator_id`
--           yang masih NULL. Tidak ada DROP, DELETE, perubahan teks,
--           maupun penimpaan nilai yang sudah terisi.
--
-- =====================================================================
-- MASALAHNYA
--
-- db/update_2026-08-28_silsilah_iku_kabupaten.sql mencocokkan teks secara
-- utuh, sehingga padanan yang hanya berbeda akronim di belakang tidak
-- ketemu:
--
--   RPJMD : "Indeks Kualitas Lingkungan Hidup"
--   IKU   : "Indeks Kualitas Lingkungan Hidup (IKLH)"
--
--   RPJMD : "Indeks Risiko Bencana"
--   IKU   : "Indeks Risiko Bencana (IRB)"
--
-- Keduanya jelas satu hal yang sama; yang satu sekadar menyebut
-- singkatannya. Tanpa silsilah, Cascading Kabupaten menampilkan teks
-- RPJMD untuk baris itu, padahal IKU-nya sudah ada.
--
-- =====================================================================
-- ATURANNYA
--
-- Kurung penutup di UJUNG teks IKU dibuang, lalu dibandingkan dengan teks
-- RPJMD yang dinormalkan. Hanya kurung di ujung — "(IKLH)", "(IRB)" —
-- bukan kurung di tengah kalimat, yang biasanya bagian dari maknanya.
--
-- Dipakai HANYA bila padanannya TEPAT SATU DI KEDUA ARAH, dan indikator
-- RPJMD-nya belum diklaim baris IKU lain.
--
-- =====================================================================
-- YANG SENGAJA TIDAK DISENTUH
--
-- "Indeks Ketahanan Pangan (IKP)" TIDAK akan tertaut ke mana pun, dan itu
-- benar. Di RPJMD, ketahanan pangan diukur lewat LIMA indikator terpisah
-- (Produksi Padi, Jagung, Daging, Telur, Perikanan); IKU meringkasnya
-- menjadi satu indeks. Itu bukan padanan satu-lawan-satu, melainkan
-- peringkasan — dan menautkannya ke salah satu dari lima akan berbohong
-- tentang empat sisanya.
-- =====================================================================


-- =====================================================================
-- LANGKAH 1 - KEADAAN SEBELUM
-- =====================================================================

SELECT '========== SEBELUM ==========' AS `laporan`;

SELECT COUNT(*)                                AS indikator_iku_kabupaten,
       SUM(ii.source_indikator_id IS NOT NULL) AS bersilsilah,
       SUM(ii.source_indikator_id IS NULL)     AS belum
FROM iku_indikator ii
JOIN iku_sasaran s ON s.id = ii.iku_sasaran_id
WHERE s.opd_id IS NULL AND ii.dihentikan_pada IS NULL;


-- =====================================================================
-- LANGKAH 2 - PETA PADANAN, TUNGGAL DI KEDUA ARAH
-- =====================================================================

DROP TEMPORARY TABLE IF EXISTS _pasangan_kab;
DROP TEMPORARY TABLE IF EXISTS _peta_kab;

CREATE TEMPORARY TABLE _pasangan_kab (
    iku_ind    INT UNSIGNED NOT NULL,
    rpjmd_ind  INT UNSIGNED NOT NULL,
    KEY (iku_ind), KEY (rpjmd_ind)
) ENGINE=InnoDB;

INSERT INTO _pasangan_kab (iku_ind, rpjmd_ind)
SELECT ii.id, ri.id
FROM iku_indikator ii
JOIN iku_sasaran s ON s.id = ii.iku_sasaran_id
JOIN rpjmd_indikator_sasaran ri
  ON TRIM(REGEXP_REPLACE(
         REGEXP_REPLACE(ii.indikator, '[[:space:]]*\\([^()]*\\)[[:space:]]*$', ''),
         '[[:space:]]+', ' '))
   = TRIM(REGEXP_REPLACE(ri.indikator_sasaran, '[[:space:]]+', ' '))
WHERE s.opd_id IS NULL
  AND ii.dihentikan_pada IS NULL
  AND ii.source_indikator_id IS NULL
  -- indikator RPJMD yang sudah diklaim baris IKU lain dikecualikan
  AND NOT EXISTS (
      SELECT 1 FROM iku_indikator x
      WHERE x.source_indikator_id = ri.id
        AND x.source_type = 'rpjmd'
        AND x.dihentikan_pada IS NULL
  );

-- Window function, bukan subquery berulang: MySQL menolak merujuk satu
-- tabel TEMPORARY lebih dari sekali dalam satu pernyataan.
CREATE TEMPORARY TABLE _peta_kab (
    iku_ind   INT UNSIGNED NOT NULL PRIMARY KEY,
    rpjmd_ind INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

INSERT INTO _peta_kab (iku_ind, rpjmd_ind)
SELECT z.iku_ind, z.rpjmd_ind
FROM (
    SELECT p.iku_ind, p.rpjmd_ind,
           COUNT(*) OVER (PARTITION BY p.iku_ind)   AS n_iku,
           COUNT(*) OVER (PARTITION BY p.rpjmd_ind) AS n_rpjmd
    FROM _pasangan_kab p
) z
WHERE z.n_iku = 1 AND z.n_rpjmd = 1;

SELECT '-- yang akan ditautkan --' AS `laporan`;

SELECT p.iku_ind, ii.indikator AS teks_iku,
       p.rpjmd_ind, ri.indikator_sasaran AS teks_rpjmd
FROM _peta_kab p
JOIN iku_indikator ii ON ii.id = p.iku_ind
JOIN rpjmd_indikator_sasaran ri ON ri.id = p.rpjmd_ind;


-- =====================================================================
-- LANGKAH 3 - PASANG SILSILAHNYA
--
-- TEKS IKU TIDAK DIUBAH. Akronimnya memang milik IKU, dan sejak Cascading
-- membaca IKU, akronim itulah yang pantas tampil.
-- =====================================================================

UPDATE iku_indikator ii
JOIN _peta_kab p ON p.iku_ind = ii.id
SET ii.source_indikator_id = p.rpjmd_ind,
    ii.source_type         = COALESCE(ii.source_type, 'rpjmd'),
    ii.updated_at          = NOW()
WHERE ii.source_indikator_id IS NULL;

DROP TEMPORARY TABLE IF EXISTS _pasangan_kab;
DROP TEMPORARY TABLE IF EXISTS _peta_kab;


-- =====================================================================
-- LANGKAH 4 - PERIKSA HASIL
-- =====================================================================

SELECT '========== SESUDAH ==========' AS `laporan`;

SELECT COUNT(*)                                AS indikator_iku_kabupaten,
       SUM(ii.source_indikator_id IS NOT NULL) AS bersilsilah,
       SUM(ii.source_indikator_id IS NULL)     AS belum
FROM iku_indikator ii
JOIN iku_sasaran s ON s.id = ii.iku_sasaran_id
WHERE s.opd_id IS NULL AND ii.dihentikan_pada IS NULL;

SELECT '-- indikator RPJMD yang masih dibaca dari RPJMD --' AS `laporan`;

SELECT i.id, LEFT(s.sasaran_rpjmd, 34) AS sasaran_rpjmd, i.indikator_sasaran
FROM rpjmd_indikator_sasaran i
JOIN rpjmd_sasaran s ON s.id = i.sasaran_id
WHERE NOT EXISTS (
    SELECT 1 FROM iku_indikator ii
    JOIN iku_sasaran x ON x.id = ii.iku_sasaran_id
    WHERE ii.source_indikator_id = i.id
      AND ii.source_type = 'rpjmd'
      AND ii.dihentikan_pada IS NULL
      AND x.opd_id IS NULL
)
ORDER BY i.id;

SELECT '-- pemeriksaan: satu indikator RPJMD diklaim >1 IKU? (harus kosong) --' AS `laporan`;

SELECT source_indikator_id, COUNT(*) AS jml
FROM iku_indikator
WHERE source_indikator_id IS NOT NULL AND source_type = 'rpjmd'
GROUP BY source_indikator_id
HAVING COUNT(*) > 1;
