-- =====================================================================
-- PERIODE IKU NYASAR -> DISATUKAN KE PERIODE RENSTRA
-- Tanggal : 2026-08-29
-- Sifat   : IDEMPOTEN. Tidak ada DELETE terhadap isi IKU; sasaran kembar
--           DIGABUNG (indikatornya dipindahkan), bukan dibuang.
--
-- =====================================================================
-- MASALAHNYA
--
-- IKU boleh lahir pada periode yang TIDAK ada di Renstra selama "Tambah IKU"
-- manual masih terbuka: tahunnya diketik sendiri. Dinas Koperasi (opd 16)
-- karenanya punya dua periode IKU sekaligus —
--
--   2025-2029  4 sasaran, hasil sync Renstra (bersilsilah source_indikator_id)
--   2026-2029  2 sasaran, diketik manual (tanpa silsilah)
--
-- padahal Renstra-nya hanya 2025-2029. Akibatnya berantai:
--   * menu IKU menawarkan dua periode untuk satu Renstra;
--   * revisi lahir di periode nyasar, sehingga tertulis "IKU 2026-2029";
--   * dropdown tahun berlaku ikut meleset (2027-2029, bukan 2026-2029).
--
-- Nama sasaran di periode nyasar KEMBAR dengan yang di periode Renstra,
-- tetapi INDIKATORNYA berbeda — indikator baru yang dimaksudkan berlaku
-- mulai 2026. Itu justru kegunaan revisi, bukan periode tersendiri.
--
-- =====================================================================
-- YANG DILAKUKAN
--
--   1. sasaran periode nyasar yang NAMANYA sudah ada di periode Renstra:
--      indikatornya dipindahkan ke sasaran yang sudah ada, lalu cangkang
--      sasaran kembarnya dibuang (kosong, tanpa indikator);
--   2. sasaran yang namanya BELUM ada: cukup tahunnya yang dibetulkan;
--   3. revisi pada periode nyasar ikut dibetulkan tahunnya, beserta arsipnya.
--
-- Pembekuan ulang arsip revisi TIDAK dilakukan di sini — itu butuh logika
-- aplikasi (bekukanLiveKeRevisi). Jalankan sesudah berkas ini:
--
--     php spark iku:rapikan-periode --fix
--
-- =====================================================================

SET @OPD := 16;
SET @DARI_TM := 2026;
SET @DARI_TA := 2029;
SET @KE_TM   := 2025;
SET @KE_TA   := 2029;

-- ---------------------------------------------------------------------
-- 1. PETA SASARAN KEMBAR: nyasar -> yang sudah ada di periode Renstra
-- ---------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS _kembar;

CREATE TEMPORARY TABLE _kembar (
    sasaran_nyasar INT UNSIGNED NOT NULL,
    sasaran_tujuan INT UNSIGNED NOT NULL,
    PRIMARY KEY (sasaran_nyasar)
) ENGINE=InnoDB;

INSERT INTO _kembar (sasaran_nyasar, sasaran_tujuan)
SELECT n.id, MIN(k.id)
FROM iku_sasaran n
JOIN iku_sasaran k
  ON k.opd_id      = n.opd_id
 AND k.tahun_mulai = @KE_TM AND k.tahun_akhir = @KE_TA
 AND TRIM(REGEXP_REPLACE(k.sasaran, '[[:space:]]+', ' '))
   = TRIM(REGEXP_REPLACE(n.sasaran, '[[:space:]]+', ' '))
WHERE n.opd_id = @OPD
  AND n.tahun_mulai = @DARI_TM AND n.tahun_akhir = @DARI_TA
GROUP BY n.id;

-- ---------------------------------------------------------------------
-- 2. PINDAHKAN INDIKATOR ke sasaran yang sudah ada, lalu buang cangkangnya
--    Urutan diteruskan dari yang sudah ada supaya tidak bertabrakan.
-- ---------------------------------------------------------------------
UPDATE iku_indikator ii
JOIN _kembar km ON km.sasaran_nyasar = ii.iku_sasaran_id
SET ii.iku_sasaran_id = km.sasaran_tujuan,
    ii.urutan = ii.urutan + 100,
    ii.updated_at = NOW();

DELETE s FROM iku_sasaran s
JOIN _kembar km ON km.sasaran_nyasar = s.id
WHERE NOT EXISTS (SELECT 1 FROM iku_indikator x WHERE x.iku_sasaran_id = s.id);

-- ---------------------------------------------------------------------
-- 3. SASARAN YANG TIDAK KEMBAR: cukup betulkan tahunnya
-- ---------------------------------------------------------------------
UPDATE iku_sasaran
SET tahun_mulai = @KE_TM, tahun_akhir = @KE_TA, updated_at = NOW()
WHERE opd_id = @OPD
  AND tahun_mulai = @DARI_TM AND tahun_akhir = @DARI_TA;

-- ---------------------------------------------------------------------
-- 4. REVISI pada periode nyasar: betulkan tahun periodenya + arsipnya
--    `berlaku_mulai_tahun` TIDAK diubah — 2026 memang tahun yang dimaksud.
-- ---------------------------------------------------------------------
UPDATE iku_revisi
SET tahun_mulai = @KE_TM, tahun_akhir = @KE_TA,
    nama = REPLACE(nama, CONCAT(@DARI_TM, '-', @DARI_TA), CONCAT(@KE_TM, '-', @KE_TA)),
    updated_at = NOW()
WHERE opd_key = @OPD
  AND tahun_mulai = @DARI_TM AND tahun_akhir = @DARI_TA;

UPDATE iku_revisi_sasaran rs
JOIN iku_revisi r ON r.id = rs.revisi_id
SET rs.tahun_mulai = @KE_TM, rs.tahun_akhir = @KE_TA
WHERE r.opd_key = @OPD
  AND rs.tahun_mulai = @DARI_TM AND rs.tahun_akhir = @DARI_TA;

DROP TEMPORARY TABLE IF EXISTS _kembar;

-- =====================================================================
-- LAPORAN
-- =====================================================================
SELECT '========== PERIODE IKU SESUDAH DIRAPIKAN ==========' AS `laporan`;

SELECT tahun_mulai, tahun_akhir, COUNT(*) AS jml_sasaran,
       (SELECT COUNT(*) FROM iku_indikator ii
          JOIN iku_sasaran s2 ON s2.id = ii.iku_sasaran_id
         WHERE s2.opd_id = @OPD AND s2.tahun_mulai = s.tahun_mulai) AS jml_indikator
FROM iku_sasaran s
WHERE opd_id = @OPD
GROUP BY tahun_mulai, tahun_akhir;

SELECT '-- periode IKU yang TIDAK punya padanan Renstra (idealnya kosong) --' AS `laporan`;

SELECT s.opd_id, o.nama_opd, s.tahun_mulai, s.tahun_akhir, COUNT(*) AS jml_sasaran
FROM iku_sasaran s
LEFT JOIN opd o ON o.id = s.opd_id
WHERE s.opd_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM renstra_sasaran rs
      WHERE rs.opd_id = s.opd_id
        AND rs.tahun_mulai = s.tahun_mulai
        AND rs.tahun_akhir = s.tahun_akhir
  )
GROUP BY s.opd_id, o.nama_opd, s.tahun_mulai, s.tahun_akhir;
