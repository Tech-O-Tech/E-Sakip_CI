-- =====================================================================
-- PERBAIKAN TEKS MOJIBAKE (double-encoded UTF-8)
-- Tanggal : 2026-08-27
-- Sifat   : IDEMPOTEN. Hanya menyentuh baris yang TERBUKTI rusak dan
--           TERBUKTI bisa dipulihkan utuh. Aman dijalankan berulang.
--
-- =====================================================================
-- MASALAHNYA
--
-- Sebagian teks tersimpan sebagai UTF-8 yang pernah dibaca sebagai cp1252
-- lalu disimpan ulang sebagai UTF-8 — "double encoding". Contoh nyata dari
-- iku_indikator #36:
--
--   byte tersimpan : C3A2 E282AC C593   -> tampil "â€œ"
--   byte seharusnya: E2 80 9C           -> tampil "“"
--
-- Jadi tanda kutip “utama” menjadi â€œutamaâ€, Σ menjadi Î£, × menjadi Ã—,
-- dan − menjadi âˆ’. Terlihat di kolom INDIKATOR KINERJA UTAMA, DEFINISI
-- OPERASIONAL, dan FORMULA/RUMUSAN PERHITUNGAN.
--
-- =====================================================================
-- CARA MEMULIHKAN
--
--   CONVERT(BINARY(CONVERT(kolom USING latin1)) USING utf8mb4)
--
-- `latin1` milik MySQL sebenarnya cp1252 — 0x80 memang €, 0x9C memang œ —
-- jadi ia memetakan mundur persis kerusakan yang terjadi.
--
-- =====================================================================
-- PENJAGA (bagian terpenting berkas ini)
--
-- Konversi di atas BERBAHAYA bila dipukul rata: teks yang memuat karakter
-- di luar cp1252 (mis. aksara non-Latin) akan berubah menjadi '?' dan
-- HILANG PERMANEN.
--
-- Karena itu setiap baris disaring dua kali:
--
--   1. hanya baris yang benar-benar mengandung pola mojibake;
--   2. hanya baris yang LOLOS UJI PULANG-PERGI —
--        CONVERT(BINARY(hasil_perbaikan) USING latin1) = teks_sekarang
--      artinya perbaikannya benar-benar kebalikan dari kerusakannya, bukan
--      tebakan. Baris yang tidak lolos DIBIARKAN APA ADANYA, bukan dipaksa.
--
-- Baris yang sudah benar tidak cocok dengan pola, jadi tidak tersentuh —
-- itu yang membuat berkas ini aman diulang.
-- =====================================================================

SET @pola := '(â€|Ã.|Â.|Î.|âˆ)';

DROP PROCEDURE IF EXISTS _perbaiki_mojibake;

DELIMITER //

CREATE PROCEDURE _perbaiki_mojibake(IN p_tabel VARCHAR(64), IN p_kolom VARCHAR(64))
BEGIN
  -- Lewati bila tabel/kolomnya tidak ada di basis data ini.
  IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabel AND COLUMN_NAME = p_kolom) THEN

    SET @sql := CONCAT(
      'UPDATE `', p_tabel, '` ',
      'SET `', p_kolom, '` = CONVERT(BINARY(CONVERT(`', p_kolom, '` USING latin1)) USING utf8mb4) ',
      'WHERE `', p_kolom, '` REGEXP ''(â€|Ã.|Â.|Î.|âˆ)'' ',
      -- uji pulang-pergi: perbaikan harus benar-benar membalik kerusakan
      '  AND CONVERT(BINARY(CONVERT(BINARY(CONVERT(`', p_kolom, '` USING latin1)) USING utf8mb4)) USING latin1) ',
      '      = `', p_kolom, '`'
    );

    PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END //

DELIMITER ;

-- ---------------------------------------------------------------------
-- Kolom terdampak, hasil penyisiran SELURUH kolom teks basis data.
-- ---------------------------------------------------------------------
CALL _perbaiki_mojibake('iku_indikator',             'indikator');
CALL _perbaiki_mojibake('iku_indikator',             'definisi');
CALL _perbaiki_mojibake('iku_indikator',             'rumusan_perhitungan');
CALL _perbaiki_mojibake('iku_indikator',             'sumber_data');
CALL _perbaiki_mojibake('iku_indikator',             'penanggung_jawab');
CALL _perbaiki_mojibake('iku_sasaran',               'sasaran');

CALL _perbaiki_mojibake('iku',                       'definisi');
CALL _perbaiki_mojibake('iku',                       'rumusan_perhitungan');
CALL _perbaiki_mojibake('iku',                       'sumber_data');

CALL _perbaiki_mojibake('iku_revisi_indikator',      'indikator');
CALL _perbaiki_mojibake('iku_revisi_indikator',      'definisi');
CALL _perbaiki_mojibake('iku_revisi_indikator',      'rumusan_perhitungan');
CALL _perbaiki_mojibake('iku_revisi_sasaran',        'sasaran');

CALL _perbaiki_mojibake('renstra_indikator_sasaran', 'indikator_sasaran');
CALL _perbaiki_mojibake('renstra_sasaran',           'sasaran');
CALL _perbaiki_mojibake('renstra_sasaran',           'csf');
CALL _perbaiki_mojibake('renstra_tujuan',            'tujuan');

CALL _perbaiki_mojibake('rpjmd_indikator_sasaran',   'definisi_op');
CALL _perbaiki_mojibake('rpjmd_indikator_sasaran',   'indikator_sasaran');
CALL _perbaiki_mojibake('rpjmd_sasaran',             'sasaran_rpjmd');
CALL _perbaiki_mojibake('rpjmd_sasaran',             'csf');
CALL _perbaiki_mojibake('rpjmd_tujuan',              'tujuan_rpjmd');
CALL _perbaiki_mojibake('rpjmd_misi',                'misi');

CALL _perbaiki_mojibake('cascading_indikator_opd',   'indikator');
CALL _perbaiki_mojibake('cascading_sasaran_opd',     'nama_sasaran');
CALL _perbaiki_mojibake('cascading_sasaran_opd',     'csf');

CALL _perbaiki_mojibake('pk_indikator',              'indikator');
CALL _perbaiki_mojibake('pk_sasaran',                'sasaran');

CALL _perbaiki_mojibake('lakip_analisis_faktor',     'faktor_penghambat');
CALL _perbaiki_mojibake('lakip_analisis_faktor',     'faktor_pendukung');

CALL _perbaiki_mojibake('target_rencana',            'rencana_aksi');
CALL _perbaiki_mojibake('target_sub_rencana',        'sub_rencana_aksi');

DROP PROCEDURE IF EXISTS _perbaiki_mojibake;

-- =====================================================================
-- BAGIAN 2 — TANDA PISAH PANJANG "—" MENJADI "ÔÇö"
--
-- Kerusakan BERBEDA dari bagian 1, jadi obatnya juga berbeda.
--
-- Sumbernya bukan data pengguna melainkan cara migrasi dijalankan:
-- db/update_2026-08-20_versioning_dokumen.sql menulis label baseline
-- "V1 — Kondisi Awal ...". Bila berkas itu dijalankan TANPA
-- --default-character-set=utf8mb4, klien mysql memakai codepage konsol
-- Windows (CP850/CP437) dan "—" tersimpan sebagai "ÔÇö".
--
--   byte tersimpan : C394 C387 C3B6   -> "ÔÇö"
--   byte seharusnya: E2 80 94         -> "—"
--
-- PENTING — PERBANDINGAN HARUS BINER.
--
-- Collation utf8mb4_general_ci MELIPAT AKSEN, sehingga
--   WHERE label LIKE '%ÔÇö%'
-- juga menjaring teks biasa yang memuat "oco"/"OCO". Pada basis data ini
-- saringan _ci itu keliru menjaring 15 baris `pegawai`.`password` dan 7
-- baris `pegawai`.`nama_pegawai` — REPLACE yang dijalankan atas dasar itu
-- akan MERUSAK kata sandi pengguna.
--
-- Karena itu semua pencocokan di bawah memakai BINARY: byte lawan byte,
-- tanpa pelipatan aksen, tanpa pelipatan huruf besar-kecil.
-- =====================================================================

SET @salah := UNHEX('C394C387C3B6');   -- "ÔÇö"
SET @benar := UNHEX('E28094');         -- "—"

UPDATE `dokumen_versi`
SET `label` = CONVERT(REPLACE(BINARY `label`, @salah, @benar) USING utf8mb4)
WHERE INSTR(BINARY `label`, @salah) > 0;

UPDATE `permissions`
SET `label` = CONVERT(REPLACE(BINARY `label`, @salah, @benar) USING utf8mb4)
WHERE INSTR(BINARY `label`, @salah) > 0;

UPDATE `version_submission_history`
SET `ringkasan` = CONVERT(REPLACE(BINARY `ringkasan`, @salah, @benar) USING utf8mb4)
WHERE INSTR(BINARY COALESCE(`ringkasan`, ''), @salah) > 0;

-- =====================================================================
-- LAPORAN: sisa baris yang masih mengandung pola mojibake.
-- Idealnya kosong. Baris yang tersisa berarti TIDAK lolos uji pulang-pergi
-- dan sengaja dibiarkan — perlu diperiksa manual, jangan dipaksa konversi.
-- =====================================================================
SELECT '========== SISA MOJIBAKE (idealnya kosong) ==========' AS `laporan`;

SELECT 'iku_indikator.indikator' AS lokasi, COUNT(*) AS sisa FROM iku_indikator WHERE indikator REGEXP '(â€|Ã.|Â.|Î.|âˆ)'
UNION ALL SELECT 'iku_indikator.definisi', COUNT(*) FROM iku_indikator WHERE definisi REGEXP '(â€|Ã.|Â.|Î.|âˆ)'
UNION ALL SELECT 'iku_indikator.rumusan_perhitungan', COUNT(*) FROM iku_indikator WHERE rumusan_perhitungan REGEXP '(â€|Ã.|Â.|Î.|âˆ)'
UNION ALL SELECT 'iku.definisi', COUNT(*) FROM iku WHERE definisi REGEXP '(â€|Ã.|Â.|Î.|âˆ)'
UNION ALL SELECT 'iku.rumusan_perhitungan', COUNT(*) FROM iku WHERE rumusan_perhitungan REGEXP '(â€|Ã.|Â.|Î.|âˆ)'
UNION ALL SELECT 'renstra_indikator_sasaran.indikator_sasaran', COUNT(*) FROM renstra_indikator_sasaran WHERE indikator_sasaran REGEXP '(â€|Ã.|Â.|Î.|âˆ)'
UNION ALL SELECT 'rpjmd_indikator_sasaran.definisi_op', COUNT(*) FROM rpjmd_indikator_sasaran WHERE definisi_op REGEXP '(â€|Ã.|Â.|Î.|âˆ)'
UNION ALL SELECT 'cascading_indikator_opd.indikator', COUNT(*) FROM cascading_indikator_opd WHERE indikator REGEXP '(â€|Ã.|Â.|Î.|âˆ)'
UNION ALL SELECT 'pk_indikator.indikator', COUNT(*) FROM pk_indikator WHERE indikator REGEXP '(â€|Ã.|Â.|Î.|âˆ)'
UNION ALL SELECT 'lakip_analisis_faktor.faktor_penghambat', COUNT(*) FROM lakip_analisis_faktor WHERE faktor_penghambat REGEXP '(â€|Ã.|Â.|Î.|âˆ)'
UNION ALL SELECT 'target_rencana.rencana_aksi', COUNT(*) FROM target_rencana WHERE rencana_aksi REGEXP '(â€|Ã.|Â.|Î.|âˆ)'
UNION ALL SELECT 'target_sub_rencana.sub_rencana_aksi', COUNT(*) FROM target_sub_rencana WHERE sub_rencana_aksi REGEXP '(â€|Ã.|Â.|Î.|âˆ)'
UNION ALL SELECT 'dokumen_versi.label (ÔÇö)', COUNT(*) FROM dokumen_versi WHERE INSTR(BINARY label, UNHEX('C394C387C3B6')) > 0
UNION ALL SELECT 'permissions.label (ÔÇö)', COUNT(*) FROM permissions WHERE INSTR(BINARY label, UNHEX('C394C387C3B6')) > 0
UNION ALL SELECT 'version_submission_history.ringkasan (ÔÇö)', COUNT(*) FROM version_submission_history WHERE INSTR(BINARY COALESCE(ringkasan,''), UNHEX('C394C387C3B6')) > 0
ORDER BY 2 DESC, 1;
