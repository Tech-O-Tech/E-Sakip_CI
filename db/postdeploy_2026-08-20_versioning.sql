-- =====================================================================
-- POST-DEPLOY CHECK — VERSIONING DOKUMEN  (§59)
-- Tanggal : 2026-08-20
-- Sifat   : HANYA MEMBACA. Tidak ada satu pun statement yang mengubah data.
--
-- Jalankan SESUDAH update_2026-08-20_versioning_dokumen.sql:
--   mysql -u root -p "e-sakip_6" -t < db/postdeploy_2026-08-20_versioning.sql
--
-- Setiap pemeriksaan mengembalikan kolom `hasil` bernilai OK atau GAGAL.
-- Satu GAGAL saja = jangan lanjutkan ke tahap aplikasi.
-- =====================================================================

SELECT '========== 1. TABEL BARU TERBENTUK ==========' AS ``;

SELECT t.nama AS tabel, IF(i.TABLE_NAME IS NULL, 'GAGAL', 'OK') AS hasil
FROM (
  SELECT 'dokumen_versi' AS nama
  UNION ALL SELECT 'version_submission_history'
  UNION ALL SELECT 'version_correction_requests'
  UNION ALL SELECT 'lakip_benchmark_item'
  UNION ALL SELECT 'rpjmd_versi_misi'              UNION ALL SELECT 'rpjmd_versi_tujuan'
  UNION ALL SELECT 'rpjmd_versi_indikator_tujuan'  UNION ALL SELECT 'rpjmd_versi_target_tujuan'
  UNION ALL SELECT 'rpjmd_versi_sasaran'           UNION ALL SELECT 'rpjmd_versi_indikator_sasaran'
  UNION ALL SELECT 'rpjmd_versi_target'
  UNION ALL SELECT 'renstra_versi_tujuan'          UNION ALL SELECT 'renstra_versi_indikator_tujuan'
  UNION ALL SELECT 'renstra_versi_target_tujuan'   UNION ALL SELECT 'renstra_versi_sasaran'
  UNION ALL SELECT 'renstra_versi_indikator_sasaran' UNION ALL SELECT 'renstra_versi_target'
) t
LEFT JOIN information_schema.TABLES i
  ON i.TABLE_SCHEMA = DATABASE() AND i.TABLE_NAME = t.nama
ORDER BY hasil ASC, t.nama;


SELECT '========== 2. PENEGAK INVARIANT TERPASANG ==========' AS ``;

-- Tiga UNIQUE index inilah yang menjamin §6 & §62 di level engine.
SELECT t.idx AS unique_index, IF(s.INDEX_NAME IS NULL, 'GAGAL', 'OK') AS hasil
FROM (
  SELECT 'uq_dokver_nomor' AS idx
  UNION ALL SELECT 'uq_dokver_mulai'
  UNION ALL SELECT 'uq_dokver_terbuka'
) t
LEFT JOIN (
  SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dokumen_versi' AND NON_UNIQUE = 0
) s ON s.INDEX_NAME = t.idx
ORDER BY hasil ASC;

-- Generated column penopang index di atas.
SELECT COLUMN_NAME AS generated_column, EXTRA AS jenis,
       IF(EXTRA LIKE '%STORED GENERATED%', 'OK', 'GAGAL') AS hasil
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dokumen_versi'
  AND COLUMN_NAME IN ('opd_key','scope_key','published_key','terbuka_key')
ORDER BY COLUMN_NAME;


SELECT '========== 3. TIMELINE VALID ==========' AS ``;

-- 3.1  Tidak boleh ada dua Published dengan effective_from sama (§6, §62).
SELECT 'duplicate effective_from pada Published' AS periksa,
       COUNT(*) AS pelanggaran,
       IF(COUNT(*) = 0, 'OK', 'GAGAL') AS hasil
FROM (
  SELECT 1 FROM dokumen_versi
  WHERE status = 'published'
  GROUP BY modul, scope_key, opd_key, periode_mulai, periode_akhir, effective_from
  HAVING COUNT(*) > 1
) x;

-- 3.2  Tepat satu versi terbuka per dokumen.
SELECT 'lebih dari satu versi terbuka' AS periksa,
       COUNT(*) AS pelanggaran,
       IF(COUNT(*) = 0, 'OK', 'GAGAL') AS hasil
FROM (
  SELECT 1 FROM dokumen_versi
  WHERE status = 'published' AND effective_to IS NULL
  GROUP BY modul, scope_key, opd_key, periode_mulai, periode_akhir
  HAVING COUNT(*) > 1
) x;

-- 3.3  Interval Published tidak boleh tumpang tindih (§5, §61).
SELECT 'interval Published tumpang tindih' AS periksa,
       COUNT(*) AS pelanggaran,
       IF(COUNT(*) = 0, 'OK', 'GAGAL') AS hasil
FROM dokumen_versi a
JOIN dokumen_versi b
  ON  a.modul = b.modul AND a.scope_key = b.scope_key AND a.opd_key = b.opd_key
  AND a.periode_mulai = b.periode_mulai AND a.periode_akhir = b.periode_akhir
  AND a.id < b.id
  AND a.status = 'published' AND b.status = 'published'
  AND a.effective_from < COALESCE(b.effective_to, '9999-12-31')
  AND b.effective_from < COALESCE(a.effective_to, '9999-12-31');

-- 3.4  effective_to tidak boleh mendahului effective_from.
SELECT 'effective_to < effective_from' AS periksa,
       COUNT(*) AS pelanggaran,
       IF(COUNT(*) = 0, 'OK', 'GAGAL') AS hasil
FROM dokumen_versi
WHERE effective_to IS NOT NULL AND effective_to <= effective_from;

-- 3.5  Status hanya boleh empat nilai (§7).
SELECT 'status di luar daftar (§7)' AS periksa,
       COUNT(*) AS pelanggaran,
       IF(COUNT(*) = 0, 'OK', 'GAGAL') AS hasil
FROM dokumen_versi
WHERE status NOT IN ('draft','pending_approval','published','cancelled');

-- 3.6  Bila ada versi Published, harus tepat satu yang terbuka per dokumen.
SELECT 'dokumen Published tanpa versi terbuka' AS periksa,
       COUNT(*) AS pelanggaran,
       IF(COUNT(*) = 0, 'OK', 'GAGAL') AS hasil
FROM (
  SELECT modul, scope_key, opd_key, periode_mulai, periode_akhir
  FROM dokumen_versi WHERE status = 'published'
  GROUP BY 1,2,3,4,5
  HAVING SUM(effective_to IS NULL) <> 1
) x;


SELECT '========== 4. BASELINE BERHASIL (§43) ==========' AS ``;

SELECT modul, scope, COUNT(*) AS jml_versi,
       SUM(status = 'published') AS published,
       SUM(version_no = 1)       AS baseline_v1
FROM dokumen_versi
GROUP BY modul, scope
ORDER BY modul, scope;

-- Setiap periode RPJMD harus punya baseline.
SELECT 'periode RPJMD tanpa baseline' AS periksa, COUNT(*) AS pelanggaran,
       IF(COUNT(*) = 0, 'OK', 'GAGAL') AS hasil
FROM (
  SELECT DISTINCT CAST(m.tahun_mulai AS UNSIGNED) AS tm, CAST(m.tahun_akhir AS UNSIGNED) AS ta
  FROM rpjmd_misi m
  WHERE m.tahun_mulai IS NOT NULL AND m.tahun_akhir IS NOT NULL
) p
LEFT JOIN dokumen_versi d
  ON d.modul = 'rpjmd' AND d.opd_key = 0
 AND d.periode_mulai = p.tm AND d.periode_akhir = p.ta
WHERE d.id IS NULL;

-- Setiap (OPD, periode) Renstra harus punya baseline.
SELECT 'lingkup Renstra tanpa baseline' AS periksa, COUNT(*) AS pelanggaran,
       IF(COUNT(*) = 0, 'OK', 'GAGAL') AS hasil
FROM (
  SELECT DISTINCT opd_id,
         CAST(tahun_mulai AS UNSIGNED) AS tm, CAST(tahun_akhir AS UNSIGNED) AS ta
  FROM renstra_sasaran
  WHERE opd_id IS NOT NULL AND tahun_mulai IS NOT NULL AND tahun_akhir IS NOT NULL
) p
LEFT JOIN dokumen_versi d
  ON d.modul = 'renstra' AND d.opd_key = p.opd_id
 AND d.periode_mulai = p.tm AND d.periode_akhir = p.ta
WHERE d.id IS NULL;

-- Setiap baseline wajib punya jejak audit (§22).
SELECT 'baseline tanpa jejak audit' AS periksa, COUNT(*) AS pelanggaran,
       IF(COUNT(*) = 0, 'OK', 'GAGAL') AS hasil
FROM dokumen_versi d
LEFT JOIN version_submission_history h ON h.version_id = d.id
WHERE d.version_no = 1 AND h.id IS NULL;


SELECT '========== 5. BACKFILL version_id (§43) ==========' AS ``;

SELECT 'rpjmd_misi' AS tabel, COUNT(*) AS total,
       SUM(version_id IS NOT NULL) AS terisi,
       SUM(version_id IS NULL)     AS kosong,
       IF(SUM(version_id IS NULL) = 0, 'OK', 'PERIKSA') AS hasil
FROM rpjmd_misi
UNION ALL SELECT 'rpjmd_tujuan', COUNT(*), SUM(version_id IS NOT NULL), SUM(version_id IS NULL),
       IF(SUM(version_id IS NULL) = 0,'OK','PERIKSA') FROM rpjmd_tujuan
UNION ALL SELECT 'rpjmd_sasaran', COUNT(*), SUM(version_id IS NOT NULL), SUM(version_id IS NULL),
       IF(SUM(version_id IS NULL) = 0,'OK','PERIKSA') FROM rpjmd_sasaran
UNION ALL SELECT 'rpjmd_indikator_sasaran', COUNT(*), SUM(version_id IS NOT NULL), SUM(version_id IS NULL),
       IF(SUM(version_id IS NULL) = 0,'OK','PERIKSA') FROM rpjmd_indikator_sasaran
UNION ALL SELECT 'renstra_sasaran', COUNT(*), SUM(version_id IS NOT NULL), SUM(version_id IS NULL),
       IF(SUM(version_id IS NULL) = 0,'OK','PERIKSA') FROM renstra_sasaran
UNION ALL SELECT 'renstra_indikator_sasaran', COUNT(*), SUM(version_id IS NOT NULL), SUM(version_id IS NULL),
       IF(SUM(version_id IS NULL) = 0,'OK','PERIKSA') FROM renstra_indikator_sasaran
UNION ALL SELECT 'renstra_tujuan (sengaja dilewati)', COUNT(*), SUM(version_id IS NOT NULL), SUM(version_id IS NULL),
       'DIHARAPKAN KOSONG' FROM renstra_tujuan;

-- version_id yang menunjuk versi tidak ada = FK logis rusak.
SELECT 'version_id menunjuk versi hilang' AS periksa, SUM(x.jml) AS pelanggaran,
       IF(SUM(x.jml) = 0, 'OK', 'GAGAL') AS hasil
FROM (
  SELECT COUNT(*) AS jml FROM rpjmd_misi t
   LEFT JOIN dokumen_versi d ON d.id = t.version_id
   WHERE t.version_id IS NOT NULL AND d.id IS NULL
  UNION ALL
  SELECT COUNT(*) FROM rpjmd_sasaran t
   LEFT JOIN dokumen_versi d ON d.id = t.version_id
   WHERE t.version_id IS NOT NULL AND d.id IS NULL
  UNION ALL
  SELECT COUNT(*) FROM renstra_sasaran t
   LEFT JOIN dokumen_versi d ON d.id = t.version_id
   WHERE t.version_id IS NOT NULL AND d.id IS NULL
  UNION ALL
  SELECT COUNT(*) FROM renstra_indikator_sasaran t
   LEFT JOIN dokumen_versi d ON d.id = t.version_id
   WHERE t.version_id IS NOT NULL AND d.id IS NULL
) x;


SELECT '========== 6. DATA LAMA TIDAK HILANG (§42, §80.1) ==========' AS ``;
-- Bandingkan angka di bawah dengan keluaran preflight bagian 6.
-- Harus IDENTIK. Satu selisih pun = berhenti dan pulihkan dari backup.

SELECT 'rpjmd_misi' AS tabel, COUNT(*) AS jml FROM rpjmd_misi
UNION ALL SELECT 'rpjmd_sasaran',             COUNT(*) FROM rpjmd_sasaran
UNION ALL SELECT 'rpjmd_indikator_sasaran',   COUNT(*) FROM rpjmd_indikator_sasaran
UNION ALL SELECT 'rpjmd_target',              COUNT(*) FROM rpjmd_target
UNION ALL SELECT 'renstra_tujuan',            COUNT(*) FROM renstra_tujuan
UNION ALL SELECT 'renstra_sasaran',           COUNT(*) FROM renstra_sasaran
UNION ALL SELECT 'renstra_indikator_sasaran', COUNT(*) FROM renstra_indikator_sasaran
UNION ALL SELECT 'renstra_target',            COUNT(*) FROM renstra_target
UNION ALL SELECT 'iku_sasaran',               COUNT(*) FROM iku_sasaran
UNION ALL SELECT 'iku_indikator',             COUNT(*) FROM iku_indikator
UNION ALL SELECT 'lakip',                     COUNT(*) FROM lakip
UNION ALL SELECT 'lakip_benchmark',           COUNT(*) FROM lakip_benchmark
UNION ALL SELECT 'lakip_analisis_faktor',     COUNT(*) FROM lakip_analisis_faktor
UNION ALL SELECT 'target_rencana',            COUNT(*) FROM target_rencana
UNION ALL SELECT 'cascading_sasaran_opd',     COUNT(*) FROM cascading_sasaran_opd;

-- Tidak boleh ada baris yang terpensiun oleh pemasangan ini.
SELECT 'baris terpensiun oleh migrasi' AS periksa, SUM(x.jml) AS pelanggaran,
       IF(SUM(x.jml) = 0, 'OK', 'GAGAL') AS hasil
FROM (
  SELECT COUNT(*) AS jml FROM rpjmd_misi              WHERE dihentikan_pada IS NOT NULL
  UNION ALL SELECT COUNT(*) FROM rpjmd_sasaran             WHERE dihentikan_pada IS NOT NULL
  UNION ALL SELECT COUNT(*) FROM rpjmd_indikator_sasaran   WHERE dihentikan_pada IS NOT NULL
  UNION ALL SELECT COUNT(*) FROM renstra_sasaran           WHERE dihentikan_pada IS NOT NULL
  UNION ALL SELECT COUNT(*) FROM renstra_indikator_sasaran WHERE dihentikan_pada IS NOT NULL
) x;

-- Lineage belum boleh terisi apa pun (fitur belum dipakai).
SELECT 'lineage terisi sebelum fitur dipakai' AS periksa, SUM(x.jml) AS pelanggaran,
       IF(SUM(x.jml) = 0, 'OK', 'GAGAL') AS hasil
FROM (
  SELECT COUNT(*) AS jml FROM rpjmd_indikator_sasaran   WHERE jenis_perubahan <> 'tetap'
  UNION ALL SELECT COUNT(*) FROM renstra_indikator_sasaran WHERE jenis_perubahan <> 'tetap'
) x;


SELECT '========== 7. LINGKUP TABEL lakip (temuan T6) ==========' AS ``;

SELECT 'total lakip'                       AS metrik, COUNT(*) AS jml FROM lakip
UNION ALL SELECT 'ter-backfill (tahun terisi)', COUNT(*) FROM lakip WHERE tahun IS NOT NULL
UNION ALL SELECT 'mode = opd',                  COUNT(*) FROM lakip WHERE mode = 'opd'
UNION ALL SELECT 'mode = kabupaten',            COUNT(*) FROM lakip WHERE mode = 'kabupaten'
UNION ALL SELECT 'YATIM (tahun NULL)',          COUNT(*) FROM lakip WHERE tahun IS NULL;

-- Setiap baris yang PUNYA target harus berhasil di-backfill.
SELECT 'baris bertarget tapi gagal backfill' AS periksa, COUNT(*) AS pelanggaran,
       IF(COUNT(*) = 0, 'OK', 'GAGAL') AS hasil
FROM lakip
WHERE tahun IS NULL
  AND (renstra_target_id IS NOT NULL OR rpjmd_target_id IS NOT NULL);

-- Baris yatim: DIBIARKAN dengan sengaja. Angkanya harus sama dengan preflight 8.1.
SELECT 'baris yatim dibiarkan (bukan kegagalan)' AS catatan, COUNT(*) AS jml,
       'tindak lanjut manual — tahun tidak boleh ditebak' AS tindakan
FROM lakip
WHERE renstra_target_id IS NULL AND rpjmd_target_id IS NULL;

-- Backfill tidak boleh salah kamar.
SELECT 'mode tidak cocok dengan sumbernya' AS periksa, COUNT(*) AS pelanggaran,
       IF(COUNT(*) = 0, 'OK', 'GAGAL') AS hasil
FROM lakip
WHERE (mode = 'opd'       AND renstra_target_id IS NULL)
   OR (mode = 'kabupaten' AND rpjmd_target_id   IS NULL);

-- Tahun hasil backfill harus sama dengan tahun target sumbernya.
SELECT 'tahun backfill tidak cocok target' AS periksa, COUNT(*) AS pelanggaran,
       IF(COUNT(*) = 0, 'OK', 'GAGAL') AS hasil
FROM lakip l
LEFT JOIN renstra_target rt ON rt.id  = l.renstra_target_id
LEFT JOIN rpjmd_target  rpj ON rpj.id = l.rpjmd_target_id
WHERE l.tahun IS NOT NULL
  AND l.tahun <> COALESCE(rt.tahun, rpj.tahun);


SELECT '========== 8. PERMISSION (§54) ==========' AS ``;

SELECT t.nama AS permission, IF(p.id IS NULL, 'GAGAL', 'OK') AS hasil
FROM (
  SELECT 'rpjmd.version.view' AS nama UNION ALL SELECT 'rpjmd.version.create'
  UNION ALL SELECT 'rpjmd.version.update_draft' UNION ALL SELECT 'rpjmd.version.submit'
  UNION ALL SELECT 'rpjmd.version.verify'       UNION ALL SELECT 'rpjmd.version.publish'
  UNION ALL SELECT 'renstra.version.view'       UNION ALL SELECT 'renstra.version.create'
  UNION ALL SELECT 'renstra.version.update_draft' UNION ALL SELECT 'renstra.version.submit'
  UNION ALL SELECT 'renstra.version.verify'     UNION ALL SELECT 'renstra.version.publish'
  UNION ALL SELECT 'iku.version.view'           UNION ALL SELECT 'iku.version.create'
  UNION ALL SELECT 'iku.version.update_draft'   UNION ALL SELECT 'iku.version.submit'
  UNION ALL SELECT 'iku.version.verify'         UNION ALL SELECT 'iku.version.publish'
  UNION ALL SELECT 'iku.version.sync'
  UNION ALL SELECT 'lakip.version.view'         UNION ALL SELECT 'lakip.version.create'
  UNION ALL SELECT 'lakip.version.update_draft' UNION ALL SELECT 'lakip.version.submit'
  UNION ALL SELECT 'lakip.version.verify'       UNION ALL SELECT 'lakip.version.publish'
  UNION ALL SELECT 'lakip.version.source_select'
  UNION ALL SELECT 'version_correction.request' UNION ALL SELECT 'version_correction.verify'
  UNION ALL SELECT 'lakip_benchmark.manage_own' UNION ALL SELECT 'lakip_benchmark.manage_all'
) t
LEFT JOIN permissions p ON p.name = t.nama
ORDER BY hasil ASC, t.nama;

-- Role read-only TIDAK boleh punya izin tulis apa pun (§38, §55).
SELECT 'role read-only punya izin tulis' AS periksa, COUNT(*) AS pelanggaran,
       IF(COUNT(*) = 0, 'OK', 'GAGAL') AS hasil
FROM role_permissions rp
JOIN roles r       ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE r.name IN ('bupati','admin_inspektorat')
  AND (p.name LIKE '%.version.create' OR p.name LIKE '%.version.update_draft'
    OR p.name LIKE '%.version.submit' OR p.name LIKE '%.version.verify'
    OR p.name LIKE '%.version.publish' OR p.name LIKE '%.version.sync'
    OR p.name LIKE 'version_correction.%' OR p.name LIKE 'lakip_benchmark.manage%');

-- OPD tidak boleh bisa verify/publish (keputusan §17: wewenang Kabupaten).
SELECT 'OPD masih bisa verify/publish' AS periksa, COUNT(*) AS pelanggaran,
       IF(COUNT(*) = 0, 'OK', 'GAGAL') AS hasil
FROM role_permissions rp
JOIN roles r       ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE r.name IN ('admin_opd','admin_kecamatan')
  AND (p.name LIKE '%.version.verify' OR p.name LIKE '%.version.publish');

-- Hak sahkan mandiri revisi IKU sudah tercabut dari role OPD.
SELECT 'iku_opd.revisi_sahkan masih di role OPD' AS periksa, COUNT(*) AS pelanggaran,
       IF(COUNT(*) = 0, 'OK', 'GAGAL') AS hasil
FROM role_permissions rp
JOIN roles r       ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE r.name IN ('admin_opd','admin_kecamatan')
  AND p.name = 'iku_opd.revisi_sahkan';

SELECT r.name AS role, COUNT(*) AS jml_permission
FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
GROUP BY r.name ORDER BY r.name;


SELECT '========== 9. BENCHMARK AMAN (§39, §42) ==========' AS ``;

SELECT 'lakip_benchmark (lama, tetap ada)' AS tabel, COUNT(*) AS jml FROM lakip_benchmark
UNION ALL SELECT 'lakip_benchmark_item (baru)', COUNT(*) FROM lakip_benchmark_item;

SELECT 'benchmark item menunjuk snapshot hilang' AS periksa, COUNT(*) AS pelanggaran,
       IF(COUNT(*) = 0, 'OK', 'GAGAL') AS hasil
FROM lakip_benchmark_item b
LEFT JOIN lakip_snapshot_baris i ON i.id = b.lakip_snapshot_item_id
WHERE i.id IS NULL;

-- §37: nilai kosong wajib NULL, bukan 0.
SELECT 'benchmark bernilai 0 (harusnya NULL)' AS periksa, COUNT(*) AS pelanggaran,
       IF(COUNT(*) = 0, 'OK', 'PERIKSA') AS hasil
FROM lakip_benchmark_item
WHERE nilai_provinsi = 0 OR nilai_nasional = 0;


SELECT '========== 10. FK & INDEX TERPASANG (§75) ==========' AS ``;

SELECT rc.TABLE_NAME AS tabel, rc.CONSTRAINT_NAME AS fk, rc.DELETE_RULE AS aturan_hapus
FROM information_schema.REFERENTIAL_CONSTRAINTS rc
WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
  AND rc.TABLE_NAME IN (
    'dokumen_versi','version_submission_history','version_correction_requests',
    'lakip_benchmark_item',
    'rpjmd_versi_misi','rpjmd_versi_tujuan','rpjmd_versi_sasaran','rpjmd_versi_indikator_sasaran',
    'renstra_versi_tujuan','renstra_versi_sasaran','renstra_versi_indikator_sasaran'
  )
ORDER BY rc.TABLE_NAME, rc.CONSTRAINT_NAME;

-- Audit & correction WAJIB RESTRICT: versi berjejak tidak boleh terhapus (§2.4).
SELECT 'audit/correction bukan RESTRICT' AS periksa, COUNT(*) AS pelanggaran,
       IF(COUNT(*) = 0, 'OK', 'GAGAL') AS hasil
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND CONSTRAINT_NAME IN ('fk_vsubhist_versi','fk_vcorr_versi')
  AND DELETE_RULE <> 'RESTRICT';

SELECT TABLE_NAME AS tabel, INDEX_NAME AS idx,
       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS kolom
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('dokumen_versi','lakip','lakip_snapshot','lakip_snapshot_baris')
  AND INDEX_NAME LIKE 'idx_%'
GROUP BY TABLE_NAME, INDEX_NAME
ORDER BY TABLE_NAME, INDEX_NAME;


SELECT '========== 11. UJI RESOLVER (§26, §61) ==========' AS ``;
-- Untuk setiap dokumen ber-baseline, resolusi pada 31 Desember tahun berjalan
-- periode harus mengembalikan TEPAT SATU versi.

SELECT 'tanggal rujukan mengembalikan >1 versi' AS periksa, COUNT(*) AS pelanggaran,
       IF(COUNT(*) = 0, 'OK', 'GAGAL') AS hasil
FROM (
  SELECT d.modul, d.scope_key, d.opd_key, d.periode_mulai, d.periode_akhir,
         COUNT(*) AS jml
  FROM dokumen_versi d
  WHERE d.status = 'published'
    AND d.effective_from <= MAKEDATE(d.periode_akhir, 365)
    AND (d.effective_to IS NULL OR d.effective_to > MAKEDATE(d.periode_akhir, 365))
  GROUP BY 1,2,3,4,5
  HAVING COUNT(*) > 1
) x;

-- Contoh timeline untuk dibaca mata (§48 badge CURRENT/HISTORICAL/UPCOMING).
SELECT modul, scope, opd_key AS opd,
       CONCAT(periode_mulai,'-',periode_akhir) AS periode,
       version_no AS v, label, effective_from, effective_to, status,
       CASE
         WHEN status <> 'published' THEN UPPER(status)
         WHEN effective_from > CURDATE() THEN 'UPCOMING'
         WHEN effective_to IS NOT NULL AND effective_to <= CURDATE() THEN 'HISTORICAL'
         ELSE 'CURRENT'
       END AS badge
FROM dokumen_versi
ORDER BY modul, scope, opd_key, periode_mulai, effective_from
LIMIT 30;


SELECT '========== POST-DEPLOY SELESAI ==========' AS ``;
SELECT 'Semua kolom hasil harus OK. Satu GAGAL = jangan lanjut ke tahap aplikasi.' AS instruksi;
