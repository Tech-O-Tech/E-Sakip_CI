-- =====================================================================
-- SAMAKAN INDIKATOR IKU YANG TERSISA TUNGGAL DENGAN RENSTRA
-- Tanggal : 2026-08-28
-- Sifat   : IDEMPOTEN. Menyunting TEKS indikator dan memasang silsilahnya.
--           Tidak ada INSERT, DELETE, maupun perubahan definisi, rumusan,
--           satuan, baseline, atau target.
--
-- =====================================================================
-- ATURANNYA — "SISA TUNGGAL"
--
-- Di dalam satu sasaran yang SUDAH berpasangan (IKU tahu asal Renstra-nya),
-- sebagian besar indikator biasanya sudah bersilsilah. Bila yang tersisa
-- TEPAT SATU di sisi IKU dan TEPAT SATU di sisi Renstra, keduanya pastilah
-- pasangan — tidak ada kemungkinan lain di dalam sasaran itu.
--
-- Contohnya Kec. Sukoharjo: sasaran yang sama, 5 lawan 5, empat sudah
-- berpasangan, dan yang tersisa:
--
--   RENSTRA : "Pembinaan Kepada Aparatur Pemerintahan Kecamatan"
--   IKU     : "Prosentase Pembinaan Kepada Aparatur Pemerintah
--              Desa/Kelurahan di wilayah Kecamatan"
--
-- Redaksinya berbeda jauh, sehingga pencocokan teks tidak mungkin
-- menemukannya. Tetapi hitungannya tidak menyisakan ruang untuk keliru.
--
-- =====================================================================
-- ARAH PENYESUAIAN
--
-- Renstra adalah dokumen formalnya; IKU diturunkan darinya. Maka TEKS IKU
-- yang mengikuti Renstra, bukan sebaliknya — sama seperti perlakuan pada
-- Sekretariat DPRD dan Bapperida.
--
-- =====================================================================
-- YANG DIJAGA
--
--   * Hanya sasaran yang SUDAH bersilsilah yang diperiksa. Sasaran yang
--     belum berpasangan tidak punya dasar untuk menghitung "sisa".
--   * Tepat satu di KEDUA sisi. Dua lawan dua pun dilewati — di sana masih
--     ada dua kemungkinan pasangan, dan menebak lebih buruk daripada diam.
--   * Indikator yang sudah dipensiunkan tidak ikut dihitung.
-- =====================================================================


-- =====================================================================
-- LANGKAH 1 - CARI SISA TUNGGAL
-- =====================================================================

DROP TEMPORARY TABLE IF EXISTS _sisa_iku;
DROP TEMPORARY TABLE IF EXISTS _sisa_renstra;
DROP TEMPORARY TABLE IF EXISTS _pasangan_sisa;

CREATE TEMPORARY TABLE _sisa_iku (
    iku_ind        INT UNSIGNED NOT NULL PRIMARY KEY,
    iku_sasaran    INT UNSIGNED NOT NULL,
    renstra_sasaran INT UNSIGNED NOT NULL,
    KEY (iku_sasaran)
) ENGINE=InnoDB;

INSERT INTO _sisa_iku (iku_ind, iku_sasaran, renstra_sasaran)
SELECT ii.id, s.id, s.source_sasaran_id
FROM iku_indikator ii
JOIN iku_sasaran s ON s.id = ii.iku_sasaran_id
WHERE s.opd_id IS NOT NULL
  AND s.source_sasaran_id IS NOT NULL
  AND s.dihentikan_pada IS NULL
  AND ii.source_indikator_id IS NULL
  AND ii.dihentikan_pada IS NULL;

CREATE TEMPORARY TABLE _sisa_renstra (
    renstra_ind     INT UNSIGNED NOT NULL PRIMARY KEY,
    renstra_sasaran INT UNSIGNED NOT NULL,
    KEY (renstra_sasaran)
) ENGINE=InnoDB;

INSERT INTO _sisa_renstra (renstra_ind, renstra_sasaran)
SELECT ri.id, ri.renstra_sasaran_id
FROM renstra_indikator_sasaran ri
WHERE NOT EXISTS (
    SELECT 1 FROM iku_indikator x
    WHERE x.source_indikator_id = ri.id
      AND x.dihentikan_pada IS NULL
);

-- Pasangkan HANYA bila tepat satu di kedua sisi, pada sasaran yang sama.
CREATE TEMPORARY TABLE _pasangan_sisa (
    iku_ind     INT UNSIGNED NOT NULL PRIMARY KEY,
    renstra_ind INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

INSERT INTO _pasangan_sisa (iku_ind, renstra_ind)
SELECT z.iku_ind, z.renstra_ind
FROM (
    SELECT si.iku_ind,
           sr.renstra_ind,
           COUNT(*) OVER (PARTITION BY si.iku_sasaran)     AS n_iku,
           COUNT(*) OVER (PARTITION BY sr.renstra_sasaran) AS n_renstra
    FROM _sisa_iku si
    JOIN _sisa_renstra sr ON sr.renstra_sasaran = si.renstra_sasaran
) z
WHERE z.n_iku = 1 AND z.n_renstra = 1;

SELECT '========== YANG AKAN DISAMAKAN ==========' AS `laporan`;

SELECT o.nama_opd AS opd,
       p.iku_ind, ii.indikator AS teks_iku_sekarang,
       p.renstra_ind, ri.indikator_sasaran AS teks_renstra_acuan
FROM _pasangan_sisa p
JOIN iku_indikator ii ON ii.id = p.iku_ind
JOIN iku_sasaran s ON s.id = ii.iku_sasaran_id
JOIN renstra_indikator_sasaran ri ON ri.id = p.renstra_ind
LEFT JOIN opd o ON o.id = s.opd_id;


-- =====================================================================
-- LANGKAH 2 - SAMAKAN TEKSNYA & PASANG SILSILAHNYA
--
-- `definisi`, `rumusan_perhitungan`, `sumber_data`, `penanggung_jawab`,
-- `satuan`, `baseline`, dan target SENGAJA tidak disebut — itulah isi yang
-- harus selamat.
-- =====================================================================

UPDATE iku_indikator ii
JOIN _pasangan_sisa p ON p.iku_ind = ii.id
JOIN renstra_indikator_sasaran ri ON ri.id = p.renstra_ind
SET ii.indikator           = ri.indikator_sasaran,
    ii.source_indikator_id = p.renstra_ind,
    ii.source_type         = COALESCE(ii.source_type, 'renstra'),
    ii.updated_at          = NOW()
WHERE ii.source_indikator_id IS NULL;

DROP TEMPORARY TABLE IF EXISTS _sisa_iku;
DROP TEMPORARY TABLE IF EXISTS _sisa_renstra;
DROP TEMPORARY TABLE IF EXISTS _pasangan_sisa;


-- =====================================================================
-- LANGKAH 3 - PERIKSA HASIL
-- =====================================================================

SELECT '========== SISA INDIKATOR IKU TANPA SILSILAH ==========' AS `laporan`;

SELECT o.nama_opd AS opd, ii.id, ii.indikator,
       IF(s.source_sasaran_id IS NULL, 'sasarannya pun belum bersilsilah', 'sasaran sudah, indikatornya belum') AS keadaan
FROM iku_indikator ii
JOIN iku_sasaran s ON s.id = ii.iku_sasaran_id
LEFT JOIN opd o ON o.id = s.opd_id
WHERE s.opd_id IS NOT NULL
  AND ii.source_indikator_id IS NULL
  AND ii.dihentikan_pada IS NULL
ORDER BY o.nama_opd, ii.id;

SELECT '-- pemeriksaan: satu indikator Renstra diklaim >1 indikator IKU? (harus kosong) --' AS `laporan`;

SELECT source_indikator_id, COUNT(*) AS jml_pengklaim
FROM iku_indikator
WHERE source_indikator_id IS NOT NULL AND source_type = 'renstra'
GROUP BY source_indikator_id
HAVING COUNT(*) > 1;
