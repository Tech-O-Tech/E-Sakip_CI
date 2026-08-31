-- =====================================================================
-- SILSILAH SASARAN IKU OPD -> RENSTRA
-- Tanggal : 2026-08-28
-- Sifat   : IDEMPOTEN & ADDITIVE. Hanya MENGISI kolom jejak yang masih
--           NULL. Tidak ada DROP, DELETE, perubahan skema, maupun
--           penimpaan nilai yang sudah terisi.
--
-- =====================================================================
-- MASALAHNYA
--
-- Migrasi db/update_2026-08-27_cascading_sumber_iku.sql mengisi silsilah
-- pada tingkat INDIKATOR (`iku_indikator.source_indikator_id`) — dan itu
-- berhasil: 102 dari 109 indikator IKU OPD kini tahu asal Renstra-nya.
--
-- Tetapi tingkat SASARAN tidak ikut diisi. `iku_sasaran.source_sasaran_id`
-- hanya terisi bila sasarannya lahir dari tombol Sync; sasaran yang disusun
-- sebelum itu tetap kosong. Hasilnya timpang:
--
--     indikator IKU bersilsilah : 102 dari 109
--     sasaran   IKU bersilsilah :   5 dari  84      <-- inilah lubangnya
--
-- =====================================================================
-- MENGAPA ITU PENTING
--
-- 1. CASCADING BERAKAR PADA RENSTRA. Matriksnya dibangun `FROM
--    renstra_sasaran`, dengan identitas Eselon II diambil dari
--    `renstra_indikator_sasaran.id`. Selama sasaran IKU tidak tahu asalnya,
--    matriks itu TIDAK BISA diakar-ulang ke IKU tanpa kehilangan kolom
--    Tujuan RPJMD -> Sasaran RPJMD -> Tujuan Renstra untuk 79 sasaran.
--
-- 2. SYNC IKU MEMAKAI SILSILAH UNTUK MENGENALI SASARAN. Tanpa itu ia jatuh
--    ke pencocokan teks — dan begitu redaksi satu sasaran dirapikan, sync
--    menganggapnya sasaran BARU lalu menyalin ulang SELURUH indikator di
--    bawahnya. Itulah asal data kembar.
--
-- =====================================================================
-- PENJAGANYA
--
-- Pencocokan memakai aturan yang SAMA PERSIS dengan migrasi 08-27:
-- opd_id + periode + teks sasaran yang dinormalkan (spasi dirapatkan,
-- di-trim, dibandingkan case-insensitive lewat collation).
--
-- Dipakai HANYA bila padanannya TEPAT SATU DI KEDUA ARAH:
--   * satu sasaran IKU tidak boleh cocok ke >1 sasaran Renstra, DAN
--   * satu sasaran Renstra tidak boleh diklaim >1 sasaran IKU.
--
-- Sasaran Renstra yang sudah diklaim baris IKU lain juga dikecualikan.
-- Yang ambigu sengaja DIBIARKAN kosong: menebak silsilah lebih buruk
-- daripada tidak punya silsilah — layar akan jatuh ke perilaku hari ini
-- (yang benar), sedangkan tebakan yang salah membuat satu sasaran
-- menampilkan turunan milik sasaran lain.
--
-- Lingkupnya SASARAN OPD saja (`opd_id IS NOT NULL`). IKU Kabupaten
-- bersilsilah ke RPJMD lewat jalur tersendiri; lihat
-- db/update_2026-08-28_silsilah_iku_kabupaten.sql.
-- =====================================================================


-- =====================================================================
-- LANGKAH 1 - KEADAAN SEBELUM
-- =====================================================================

SELECT '========== SEBELUM ==========' AS `laporan`;

SELECT COUNT(*)                                  AS sasaran_iku_opd,
       SUM(source_sasaran_id IS NOT NULL)        AS bersilsilah,
       SUM(source_sasaran_id IS NULL)            AS belum
FROM iku_sasaran
WHERE opd_id IS NOT NULL;


-- =====================================================================
-- LANGKAH 2 - PETA PADANAN, TUNGGAL DI KEDUA ARAH
-- =====================================================================

DROP TEMPORARY TABLE IF EXISTS _pasangan;
DROP TEMPORARY TABLE IF EXISTS _peta_sasaran;

-- Seluruh pasangan yang teksnya cocok. Baris IKU yang SUDAH bersilsilah
-- tidak ikut dicari padanannya, tetapi sasaran Renstra yang sudah diklaim
-- olehnya tetap dihitung sebagai "terpakai" pada langkah berikutnya.
CREATE TEMPORARY TABLE _pasangan (
    iku_sasaran_id     INT UNSIGNED NOT NULL,
    renstra_sasaran_id INT UNSIGNED NOT NULL,
    KEY (iku_sasaran_id),
    KEY (renstra_sasaran_id)
) ENGINE=InnoDB;

INSERT INTO _pasangan (iku_sasaran_id, renstra_sasaran_id)
SELECT s.id, rs.id
FROM iku_sasaran s
JOIN renstra_sasaran rs
  ON rs.opd_id      = s.opd_id
 AND rs.tahun_mulai = s.tahun_mulai
 AND rs.tahun_akhir = s.tahun_akhir
 AND TRIM(REGEXP_REPLACE(rs.sasaran, '[[:space:]]+', ' '))
   = TRIM(REGEXP_REPLACE(s.sasaran,  '[[:space:]]+', ' '))
WHERE s.opd_id IS NOT NULL
  AND s.source_sasaran_id IS NULL
  AND s.dihentikan_pada IS NULL;

-- Saring: tunggal dari sisi IKU, tunggal dari sisi Renstra, dan sasaran
-- Renstra-nya belum diklaim baris IKU mana pun.
CREATE TEMPORARY TABLE _peta_sasaran (
    iku_sasaran_id     INT UNSIGNED NOT NULL PRIMARY KEY,
    renstra_sasaran_id INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

-- Kedua hitungan dikerjakan lewat window function, BUKAN subquery berulang:
-- MySQL menolak merujuk satu tabel TEMPORARY lebih dari sekali dalam satu
-- pernyataan ("Can't reopen table"), sehingga _pasangan hanya boleh disebut
-- sekali di sini.
INSERT INTO _peta_sasaran (iku_sasaran_id, renstra_sasaran_id)
SELECT z.iku_sasaran_id, z.renstra_sasaran_id
FROM (
    SELECT p.iku_sasaran_id,
           p.renstra_sasaran_id,
           COUNT(*) OVER (PARTITION BY p.iku_sasaran_id)     AS padanan_dari_iku,
           COUNT(*) OVER (PARTITION BY p.renstra_sasaran_id) AS padanan_dari_renstra
    FROM _pasangan p
) z
WHERE z.padanan_dari_iku = 1
  AND z.padanan_dari_renstra = 1
  -- Sasaran Renstra yang sudah diklaim baris IKU lain dikecualikan.
  --
  -- `source_sasaran_id` itu POLIMORFIK: artinya ditentukan `source_type`.
  -- Pada IKU Kabupaten ia menunjuk `rpjmd_sasaran`, pada IKU OPD menunjuk
  -- `renstra_sasaran` — dan kedua tabel itu punya rentang id yang saling
  -- tumpang tindih. Tanpa menyaring `source_type`, angka yang kebetulan sama
  -- akan disangka bentrok dan tautan yang sah ikut ditolak.
  AND NOT EXISTS (
      SELECT 1 FROM iku_sasaran x
      WHERE x.source_sasaran_id = z.renstra_sasaran_id
        AND x.source_type = 'renstra'
        AND x.opd_id IS NOT NULL
  );

SELECT '-- yang akan ditautkan --' AS `laporan`;
SELECT COUNT(*) AS akan_ditautkan FROM _peta_sasaran;


-- =====================================================================
-- LANGKAH 3 - ISI JEJAKNYA
--
-- `source_type` hanya diisi bila masih kosong: bila baris ini pernah
-- ditandai berasal dari sumber lain, penandanya tidak ditimpa.
-- =====================================================================

UPDATE iku_sasaran s
JOIN _peta_sasaran m ON m.iku_sasaran_id = s.id
SET s.source_sasaran_id = m.renstra_sasaran_id,
    s.source_type       = COALESCE(s.source_type, 'renstra'),
    s.updated_at        = NOW()
WHERE s.source_sasaran_id IS NULL;


-- =====================================================================
-- LANGKAH 4 - LAPORAN
-- =====================================================================

SELECT '========== SESUDAH ==========' AS `laporan`;

SELECT COUNT(*)                           AS sasaran_iku_opd,
       SUM(source_sasaran_id IS NOT NULL) AS bersilsilah,
       SUM(source_sasaran_id IS NULL)     AS belum
FROM iku_sasaran
WHERE opd_id IS NOT NULL;

SELECT '-- yang MASIH kosong beserta sebabnya (idealnya tinggal yang memang tak berpadanan) --' AS `laporan`;

SELECT o.nama_opd AS opd,
       s.tahun_mulai, s.tahun_akhir,
       LEFT(s.sasaran, 60) AS sasaran_iku,
       CASE
           WHEN NOT EXISTS (SELECT 1 FROM renstra_sasaran rs
                             WHERE rs.opd_id = s.opd_id
                               AND rs.tahun_mulai = s.tahun_mulai
                               AND rs.tahun_akhir = s.tahun_akhir)
               THEN 'Renstra periode ini belum ada'
           WHEN NOT EXISTS (SELECT 1 FROM _pasangan p WHERE p.iku_sasaran_id = s.id)
               THEN 'teks sasaran tidak sama dengan Renstra mana pun'
           ELSE 'padanannya ganda / sudah diklaim baris lain -> perlu pemetaan manual'
       END AS sebab
FROM iku_sasaran s
LEFT JOIN opd o ON o.id = s.opd_id
WHERE s.opd_id IS NOT NULL
  AND s.source_sasaran_id IS NULL
ORDER BY o.nama_opd, s.tahun_mulai;

SELECT '-- pemeriksaan: adakah satu sasaran Renstra diklaim >1 sasaran IKU? (harus kosong) --' AS `laporan`;

-- Disaring `source_type = renstra`: lihat catatan polimorfik di LANGKAH 2.
-- Mengelompokkan tanpa itu akan memunculkan "bentrok" palsu antara IKU OPD
-- dan IKU Kabupaten yang id sumbernya kebetulan bernilai sama.
SELECT source_sasaran_id, COUNT(*) AS jml_pengklaim
FROM iku_sasaran
WHERE opd_id IS NOT NULL
  AND source_type = 'renstra'
  AND source_sasaran_id IS NOT NULL
GROUP BY source_sasaran_id
HAVING COUNT(*) > 1;

DROP TEMPORARY TABLE IF EXISTS _pasangan;
DROP TEMPORARY TABLE IF EXISTS _peta_sasaran;
