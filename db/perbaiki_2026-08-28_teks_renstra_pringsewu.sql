-- =====================================================================
-- BETULKAN TEKS INDIKATOR RENSTRA KEC. PRINGSEWU
-- Tanggal : 2026-08-28
-- Sifat   : IDEMPOTEN. Hanya MENYUNTING TEKS. Tidak ada INSERT, DELETE,
--           perubahan id, target, maupun struktur.
--
-- =====================================================================
-- MASALAHNYA
--
-- Indikator Renstra Kec. Pringsewu tersimpan dengan kata-kata terpotong
-- dan ganti baris di tengah kalimat — pola khas salin-tempel dari PDF,
-- di mana kata yang terpenggal pindah baris ikut terbawa apa adanya:
--
--   "Prosentase Administrasi Keuangan Perangkat<CR><LF>Daerah"
--   "Prosentase Pemberdaya an Masyarakat Desa dan Kelurahan"
--   "Jumlah Kegiatan Pemberdaya an di<CR><LF>Kelurahan"
--   "Prosentase pencapaian program penyelenggar aan pemerintaha n
--    dan pelayanan public"
--
-- =====================================================================
-- MENGAPA HARUS DIBETULKAN DULU
--
-- IKU akan disamakan dengan Renstra. Tanpa perbaikan ini, teks rusak itu
-- IKUT TERSALIN ke IKU — padahal IKU-nya justru sudah bersih
-- ("Prosentase Pemberdayaan Masyarakat Desa dan Kelurahan"). Hasilnya
-- bukan hanya IKU jadi kotor, tetapi juga lahir kembar dekat:
-- "Pemberdaya an" berdampingan dengan "Pemberdayaan" yang sudah ada.
--
-- =====================================================================
-- YANG DIJAGA
--
--   * Lingkupnya HANYA opd 31. Data OPD lain tidak tersentuh.
--   * Hanya kolom `indikator_sasaran`. Id, sasaran induk, satuan, dan
--     seluruh target tahunan tetap — sehingga tidak ada tautan yang putus:
--     cascading, PK, dan LAKIP menunjuk id, bukan teks.
--   * Perbaikannya berupa penggantian potongan kata yang jelas keliru,
--     bukan penulisan ulang kalimat. Maknanya tidak diubah.
-- =====================================================================


-- =====================================================================
-- LANGKAH 1 - LIHAT DULU
-- =====================================================================

SELECT '========== SEBELUM ==========' AS `laporan`;

SELECT ri.id,
       REPLACE(REPLACE(ri.indikator_sasaran, CHAR(13), '<CR>'), CHAR(10), '<LF>') AS teks_apa_adanya
FROM renstra_indikator_sasaran ri
JOIN renstra_sasaran rs ON rs.id = ri.renstra_sasaran_id
WHERE rs.opd_id = 31
ORDER BY ri.id;


-- =====================================================================
-- LANGKAH 2 - RAPATKAN SPASI & GANTI BARIS
--
-- Ganti baris di tengah kalimat menjadi satu spasi biasa. Ini juga
-- menormalkan spasi ganda. Baris yang sudah rapi tidak berubah.
-- =====================================================================

UPDATE renstra_indikator_sasaran ri
JOIN renstra_sasaran rs ON rs.id = ri.renstra_sasaran_id
SET ri.indikator_sasaran = TRIM(REGEXP_REPLACE(ri.indikator_sasaran, '[[:space:]]+', ' '))
WHERE rs.opd_id = 31
  AND ri.indikator_sasaran <> TRIM(REGEXP_REPLACE(ri.indikator_sasaran, '[[:space:]]+', ' '));


-- =====================================================================
-- LANGKAH 3 - SAMBUNG KATA YANG TERPENGGAL
--
-- Tiap penggantian disebut satu per satu supaya jelas apa yang diubah,
-- dan supaya tidak ada pola menyapu yang bisa mengenai kata lain.
-- =====================================================================

UPDATE renstra_indikator_sasaran ri
JOIN renstra_sasaran rs ON rs.id = ri.renstra_sasaran_id
SET ri.indikator_sasaran = REPLACE(ri.indikator_sasaran, 'Pemberdaya an', 'Pemberdayaan')
WHERE rs.opd_id = 31 AND ri.indikator_sasaran LIKE '%Pemberdaya an%';

UPDATE renstra_indikator_sasaran ri
JOIN renstra_sasaran rs ON rs.id = ri.renstra_sasaran_id
SET ri.indikator_sasaran = REPLACE(ri.indikator_sasaran, 'penyelenggar aan', 'penyelenggaraan')
WHERE rs.opd_id = 31 AND ri.indikator_sasaran LIKE '%penyelenggar aan%';

UPDATE renstra_indikator_sasaran ri
JOIN renstra_sasaran rs ON rs.id = ri.renstra_sasaran_id
SET ri.indikator_sasaran = REPLACE(ri.indikator_sasaran, 'pemerintaha n', 'pemerintahan')
WHERE rs.opd_id = 31 AND ri.indikator_sasaran LIKE '%pemerintaha n%';

-- "public" -> "publik": ejaan Indonesia, bukan potongan kata. Dibatasi
-- pada frasa "pelayanan public" supaya tidak mengenai istilah lain.
UPDATE renstra_indikator_sasaran ri
JOIN renstra_sasaran rs ON rs.id = ri.renstra_sasaran_id
SET ri.indikator_sasaran = REPLACE(ri.indikator_sasaran, 'pelayanan public', 'pelayanan publik')
WHERE rs.opd_id = 31 AND ri.indikator_sasaran LIKE '%pelayanan public%';


-- =====================================================================
-- LANGKAH 4 - PERIKSA HASIL
-- =====================================================================

SELECT '========== SESUDAH ==========' AS `laporan`;

SELECT ri.id,
       REPLACE(REPLACE(ri.indikator_sasaran, CHAR(13), '<CR>'), CHAR(10), '<LF>') AS teks_sesudah,
       IF(ri.indikator_sasaran REGEXP '[[:space:]]{2,}|[[:cntrl:]]|Pemberdaya an|penyelenggar aan|pemerintaha n|pelayanan public',
          'MASIH ADA YANG JANGGAL', 'bersih') AS periksa
FROM renstra_indikator_sasaran ri
JOIN renstra_sasaran rs ON rs.id = ri.renstra_sasaran_id
WHERE rs.opd_id = 31
ORDER BY ri.id;
