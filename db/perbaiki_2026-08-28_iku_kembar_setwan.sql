-- =====================================================================
-- SATUKAN IKU KEMBAR SEKRETARIAT DPRD & PASANG SILSILAHNYA
-- Tanggal : 2026-08-28
-- Sifat   : IDEMPOTEN. Menghapus PALING BANYAK satu baris — yaitu baris
--           kembar hasil sync yang isinya kosong. Baris yang berisi
--           definisi & rumusan operator TIDAK pernah disentuh isinya.
--
-- =====================================================================
-- MASALAHNYA
--
-- Indikator IKU Sekretariat DPRD salah ketik satu huruf sejak awal:
--
--     Renstra : "... Terhadap Pelayanan Sekretariat DPRD"
--     IKU     : "... Terhadap Pelayaan  Sekretariat DPRD"     <- huruf n hilang
--
-- Sync IKU mencocokkan lewat silsilah dulu, teks belakangan. Baris IKU ini
-- diketik manual sehingga tidak punya silsilah, dan teksnya beda sehingga
-- pencocokan teks juga gagal. Akibatnya sync menganggapnya indikator BARU
-- lalu menyalinnya lagi — lahirlah dua indikator untuk satu hal yang sama,
-- dan yang baru itu KOSONG definisi maupun rumusannya.
--
-- Akibat lanjutannya: 14 baris cascading Sekretariat DPRD tidak bisa
-- berjangkar ke IKU dan tetap membaca Renstra.
--
-- =====================================================================
-- YANG DILAKUKAN
--
--   1. Baris IKU yang BERISI (punya definisi/rumusan) dipertahankan;
--      teksnya dibetulkan mengikuti Renstra, dan silsilahnya dipasang.
--   2. Baris kembar hasil sync — yang kosong — dibuang beserta targetnya.
--      Targetnya sudah dipastikan identik, jadi tidak ada angka yang hilang.
--   3. Jangkar cascading dibiarkan; jalankan ulang naskah 08-27 sesudah ini
--      supaya 14 baris itu pindah membaca IKU:
--
--          mysql -u USER -p NAMA_DB < db/update_2026-08-27_cascading_sumber_iku.sql
--
-- =====================================================================
-- BERLAKU DI DUA KEADAAN
--
-- Di server (per 27 Agustus) kembarannya BELUM ada — di sana naskah ini
-- hanya membetulkan salah ketik dan memasang silsilah, tanpa menghapus
-- apa pun. Di basis data yang sudah terlanjur ter-sync, kembarannya ikut
-- dibersihkan. Keduanya memakai naskah yang sama.
-- =====================================================================


-- =====================================================================
-- LANGKAH 1 - LIHAT DULU
-- =====================================================================

SELECT '========== KEADAAN SEKARANG ==========' AS `laporan`;

SELECT ii.id, ii.indikator, ii.source_indikator_id AS silsilah,
       (ii.definisi IS NOT NULL)            AS ada_definisi,
       (ii.rumusan_perhitungan IS NOT NULL) AS ada_rumusan,
       (SELECT COUNT(*) FROM iku_target t WHERE t.iku_indikator_id = ii.id) AS jml_target,
       ii.created_at
FROM iku_indikator ii
JOIN iku_sasaran s ON s.id = ii.iku_sasaran_id
WHERE s.opd_id = 3
ORDER BY ii.id;


-- =====================================================================
-- LANGKAH 2 - TENTUKAN BARIS MANA YANG DIPERTAHANKAN
--
-- Yang dipertahankan = yang PUNYA definisi atau rumusan. Bila keduanya
-- punya (atau keduanya tidak), yang id-nya terkecil yang menang — yang
-- lebih dulu ada, yang lebih mungkin dipakai di tempat lain.
-- =====================================================================

SET @renstra_ind := (
    SELECT ri.id FROM renstra_indikator_sasaran ri
    JOIN renstra_sasaran rs ON rs.id = ri.renstra_sasaran_id
    WHERE rs.opd_id = 3
      AND TRIM(REGEXP_REPLACE(ri.indikator_sasaran, '[[:space:]]+', ' '))
        = 'Tingkat Kepuasan Anggota DPRD Terhadap Pelayanan Sekretariat DPRD'
    LIMIT 1
);

SET @teks_benar := (
    SELECT ri.indikator_sasaran FROM renstra_indikator_sasaran ri WHERE ri.id = @renstra_ind
);

-- Seluruh baris IKU Sekretariat DPRD yang teksnya "mirip" indikator itu:
-- yang benar maupun yang salah ketik.
DROP TEMPORARY TABLE IF EXISTS _kembar_setwan;

CREATE TEMPORARY TABLE _kembar_setwan (
    id            INT UNSIGNED NOT NULL PRIMARY KEY,
    berisi        TINYINT NOT NULL,
    dipertahankan TINYINT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

INSERT INTO _kembar_setwan (id, berisi)
SELECT ii.id,
       (ii.definisi IS NOT NULL OR ii.rumusan_perhitungan IS NOT NULL) AS berisi
FROM iku_indikator ii
JOIN iku_sasaran s ON s.id = ii.iku_sasaran_id
WHERE s.opd_id = 3
  AND ii.indikator LIKE 'Tingkat Kepuasan Anggota DPRD Terhadap Pelaya%Sekretariat DPRD';

-- Pemenangnya: yang berisi lebih diutamakan, lalu id terkecil.
SET @pemenang := (
    SELECT id FROM _kembar_setwan ORDER BY berisi DESC, id ASC LIMIT 1
);

UPDATE _kembar_setwan SET dipertahankan = 1 WHERE id = @pemenang;

SELECT '-- yang dipertahankan & yang dibuang --' AS `laporan`;
SELECT k.id, IF(k.dipertahankan, 'DIPERTAHANKAN', 'DIBUANG') AS nasib,
       k.berisi AS punya_definisi_atau_rumusan, ii.indikator
FROM _kembar_setwan k JOIN iku_indikator ii ON ii.id = k.id
ORDER BY k.dipertahankan DESC, k.id;


-- =====================================================================
-- LANGKAH 3 - BETULKAN TEKS & PASANG SILSILAH PADA YANG DIPERTAHANKAN
--
-- `definisi`, `rumusan_perhitungan`, `sumber_data`, dan `penanggung_jawab`
-- SENGAJA tidak disebut di sini — itulah isi yang harus selamat.
-- =====================================================================

UPDATE iku_indikator
SET indikator           = @teks_benar,
    source_indikator_id = COALESCE(source_indikator_id, @renstra_ind),
    source_type         = COALESCE(source_type, 'renstra'),
    updated_at          = NOW()
WHERE id = @pemenang
  AND @renstra_ind IS NOT NULL;


-- =====================================================================
-- LANGKAH 4 - BUANG KEMBARANNYA
--
-- Bila tidak ada kembaran (keadaan server per 27 Agustus), kedua perintah
-- di bawah tidak menyentuh baris mana pun.
-- =====================================================================

DELETE t FROM iku_target t
JOIN _kembar_setwan k ON k.id = t.iku_indikator_id
WHERE k.dipertahankan = 0;

-- Jangkar cascading yang sempat menunjuk baris yang dibuang dialihkan ke
-- yang dipertahankan, supaya tidak ada baris cascading yang kehilangan
-- jangkarnya lalu diam-diam jatuh kembali ke Renstra.
UPDATE cascading_sasaran_opd c
JOIN _kembar_setwan k ON k.id = c.iku_indikator_id
SET c.iku_indikator_id = @pemenang,
    c.source_type      = 'iku'
WHERE k.dipertahankan = 0;

DELETE ii FROM iku_indikator ii
JOIN _kembar_setwan k ON k.id = ii.id
WHERE k.dipertahankan = 0;

DROP TEMPORARY TABLE IF EXISTS _kembar_setwan;


-- =====================================================================
-- LANGKAH 5 - PERIKSA HASIL
-- =====================================================================

SELECT '========== SESUDAH DIRAPIKAN ==========' AS `laporan`;

SELECT ii.id, ii.indikator, ii.source_indikator_id AS silsilah,
       (ii.definisi IS NOT NULL)            AS ada_definisi,
       (ii.rumusan_perhitungan IS NOT NULL) AS ada_rumusan,
       (SELECT COUNT(*) FROM iku_target t WHERE t.iku_indikator_id = ii.id) AS jml_target
FROM iku_indikator ii
JOIN iku_sasaran s ON s.id = ii.iku_sasaran_id
WHERE s.opd_id = 3
ORDER BY ii.id;

SELECT '-- teks IKU vs Renstra: harus SAMA --' AS `laporan`;
SELECT ii.indikator AS teks_iku, ri.indikator_sasaran AS teks_renstra,
       IF(TRIM(REGEXP_REPLACE(ii.indikator, '[[:space:]]+', ' '))
        = TRIM(REGEXP_REPLACE(ri.indikator_sasaran, '[[:space:]]+', ' ')), 'SAMA', 'MASIH BEDA') AS hasil
FROM iku_indikator ii
JOIN iku_sasaran s ON s.id = ii.iku_sasaran_id
JOIN renstra_indikator_sasaran ri ON ri.id = ii.source_indikator_id
WHERE s.opd_id = 3;

SELECT '-- sumber baris cascading Sekretariat DPRD --' AS `laporan`;
SELECT source_type, COUNT(*) AS baris
FROM cascading_sasaran_opd WHERE opd_id = 3 GROUP BY source_type;

SELECT 'Jalankan db/update_2026-08-27_cascading_sumber_iku.sql sesudah ini supaya jangkarnya terpasang.' AS `langkah_berikutnya`;
