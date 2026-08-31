-- =====================================================================
-- SAMAKAN IKU KEC. PRINGSEWU DENGAN RENSTRA (penutup)
-- Tanggal : 2026-08-28
-- Sifat   : MENGHAPUS DATA. Membuang sasaran & indikator IKU yang tidak
--           punya padanan di Renstra. Sekali jalan, isinya hilang.
--
-- =====================================================================
-- URUTAN YANG BENAR — naskah ini yang KETIGA
--
--   1. db/perbaiki_2026-08-28_teks_renstra_pringsewu.sql
--   2. php spark iku:sync-renstra --opd 31 --periode 2025-2029 --fix
--   3. berkas ini
--   4. db/update_2026-08-27_cascading_sumber_iku.sql
--
-- Menjalankannya sebelum nomor 1 dan 2 akan membuang indikator yang
-- padanannya belum sempat lahir. Penjaga di LANGKAH 1 menahannya.
--
-- =====================================================================
-- YANG DILAKUKAN
--
-- Sesudah sync, IKU memuat DUA lapis: hasil sync yang bersilsilah ke
-- Renstra, dan sisa tulisan tangan lama yang tidak ada di Renstra.
-- Supaya IKU benar-benar sama dengan Renstra, lapis kedua dibuang.
--
-- Tetapi lapis lama itu menyimpan DEFINISI dan RUMUSAN yang diketik
-- operator, sedangkan hasil sync lahir kosong — Renstra memang tidak
-- menyimpan kedua hal itu. Maka sebelum dibuang, isinya DIPINDAHKAN ke
-- baris hasil sync yang teksnya sama.
--
-- Yang tidak punya padanan sama sekali memang hilang. Itu konsekuensi
-- yang disadari: indikatornya tidak ada di Renstra.
--
-- =====================================================================
-- YANG DIJAGA
--
--   * Lingkupnya HANYA opd 31.
--   * Pemindahan hanya mengisi kolom yang MASIH KOSONG di tujuan.
--   * Jangkar cascading yang menunjuk baris yang akan dibuang dialihkan
--     lebih dulu ke penggantinya — tanpa itu baris cascading akan diam-
--     diam jatuh kembali ke Renstra (FK-nya ON DELETE SET NULL).
--   * Sasaran hanya dibuang bila SELURUH indikatornya tanpa silsilah.
--     Satu saja yang bersilsilah, sasarannya dipertahankan.
-- =====================================================================


-- =====================================================================
-- LANGKAH 1 - PENJAGA: pastikan sync sudah dijalankan
-- =====================================================================

SET @sudah_sync := (
    SELECT COUNT(*) FROM iku_sasaran s
    WHERE s.opd_id = 31 AND s.source_sasaran_id IS NOT NULL
);

SELECT IF(@sudah_sync >= 3,
          CONCAT('SIAP - ', @sudah_sync, ' sasaran IKU sudah bersilsilah ke Renstra'),
          'BATAL - jalankan dulu langkah 1 & 2; tidak ada yang akan dihapus') AS `penjaga`;


-- =====================================================================
-- LANGKAH 2 - PETA PINDAHAN: baris lama -> penggantinya
--    Dicocokkan lewat TEKS indikator yang dinormalkan, dan hanya bila
--    padanannya tunggal di kedua arah.
-- =====================================================================

DROP TEMPORARY TABLE IF EXISTS _pindah;

CREATE TEMPORARY TABLE _pindah (
    lama INT UNSIGNED NOT NULL PRIMARY KEY,
    baru INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

INSERT INTO _pindah (lama, baru)
SELECT z.lama, z.baru FROM (
    SELECT lama.id AS lama, baru.id AS baru,
           COUNT(*) OVER (PARTITION BY lama.id) AS n_lama,
           COUNT(*) OVER (PARTITION BY baru.id) AS n_baru
      FROM iku_indikator lama
      JOIN iku_sasaran sl ON sl.id = lama.iku_sasaran_id AND sl.opd_id = 31
      JOIN iku_indikator baru
        ON TRIM(REGEXP_REPLACE(baru.indikator, '[[:space:]]+', ' '))
         = TRIM(REGEXP_REPLACE(lama.indikator, '[[:space:]]+', ' '))
       AND baru.source_indikator_id IS NOT NULL
      JOIN iku_sasaran sb ON sb.id = baru.iku_sasaran_id AND sb.opd_id = 31
     WHERE lama.source_indikator_id IS NULL
       AND @sudah_sync >= 3
) z
WHERE z.n_lama = 1 AND z.n_baru = 1;

SELECT '-- isi yang akan dipindahkan --' AS `laporan`;
SELECT p.lama, p.baru, l.indikator
  FROM _pindah p JOIN iku_indikator l ON l.id = p.lama;


-- =====================================================================
-- LANGKAH 3 - PINDAHKAN DEFINISI, RUMUSAN, SUMBER DATA, PENANGGUNG JAWAB
--    Hanya mengisi yang kosong di tujuan; isi hasil sync tidak ditimpa.
-- =====================================================================

UPDATE iku_indikator baru
JOIN _pindah p ON p.baru = baru.id
JOIN iku_indikator lama ON lama.id = p.lama
SET baru.definisi            = COALESCE(baru.definisi,            lama.definisi),
    baru.rumusan_perhitungan = COALESCE(baru.rumusan_perhitungan, lama.rumusan_perhitungan),
    baru.sumber_data         = COALESCE(baru.sumber_data,         lama.sumber_data),
    baru.penanggung_jawab    = COALESCE(baru.penanggung_jawab,    lama.penanggung_jawab),
    baru.updated_at          = NOW();


-- =====================================================================
-- LANGKAH 4 - ALIHKAN JANGKAR CASCADING sebelum barisnya hilang
-- =====================================================================

UPDATE cascading_sasaran_opd c
JOIN _pindah p ON p.lama = c.iku_indikator_id
SET c.iku_indikator_id = p.baru,
    c.source_type      = 'iku';


-- =====================================================================
-- LANGKAH 5 - BUANG SASARAN YANG TIDAK ADA DI RENSTRA
--
-- Indikator, target, dan programnya ikut terbuang lewat ON DELETE
-- CASCADE. Sasaran yang punya SATU SAJA indikator bersilsilah tidak
-- disentuh.
-- =====================================================================

DELETE s FROM iku_sasaran s
WHERE s.opd_id = 31
  AND @sudah_sync >= 3
  AND s.source_sasaran_id IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM iku_indikator ii
      WHERE ii.iku_sasaran_id = s.id
        AND ii.source_indikator_id IS NOT NULL
  );

DROP TEMPORARY TABLE IF EXISTS _pindah;


-- =====================================================================
-- LANGKAH 6 - PERIKSA HASIL
-- =====================================================================

SELECT '========== IKU KEC. PRINGSEWU SESUDAH ==========' AS `laporan`;

SELECT s.id AS sasaran_id, s.tahun_mulai, s.tahun_akhir,
       TRIM(REGEXP_REPLACE(s.sasaran, '[[:space:]]+', ' ')) AS sasaran,
       s.source_sasaran_id AS silsilah_sasaran,
       ii.id AS indikator_id, ii.indikator,
       ii.source_indikator_id AS silsilah_indikator,
       IF(ii.definisi IS NULL, '-', 'ada')            AS definisi,
       IF(ii.rumusan_perhitungan IS NULL, '-', 'ada') AS rumusan
FROM iku_sasaran s
LEFT JOIN iku_indikator ii ON ii.iku_sasaran_id = s.id
WHERE s.opd_id = 31
ORDER BY s.id, ii.id;

SELECT '-- sisa yang tidak bersilsilah (idealnya kosong) --' AS `laporan`;
SELECT s.id AS sasaran_id, ii.id AS indikator_id, ii.indikator
FROM iku_sasaran s
LEFT JOIN iku_indikator ii ON ii.iku_sasaran_id = s.id
WHERE s.opd_id = 31
  AND (s.source_sasaran_id IS NULL OR ii.source_indikator_id IS NULL);

SELECT 'Jalankan db/update_2026-08-27_cascading_sumber_iku.sql sesudah ini.' AS `langkah_berikutnya`;
