-- =====================================================================
-- PREFLIGHT — VERSIONING DOKUMEN  (§58)
-- Tanggal : 2026-08-20
-- Sifat   : HANYA MEMBACA. Tidak ada satu pun statement yang mengubah data.
--
-- Jalankan SEBELUM update_2026-08-20_versioning_dokumen.sql:
--   mysql -u root -p "e-sakip_6" -t < db/preflight_2026-08-20_versioning.sql
--
-- Baca hasilnya dan hentikan pemasangan bila ada baris bertanda GAGAL.
-- =====================================================================

SELECT '========== 1. DATABASE & VERSI ENGINE ==========' AS ``;

SELECT DATABASE() AS db_aktif, VERSION() AS versi_engine,
       CASE
         WHEN VERSION() LIKE '%MariaDB%' THEN 'MariaDB'
         WHEN CAST(SUBSTRING_INDEX(VERSION(), '.', 1) AS UNSIGNED) >= 8 THEN 'MySQL 8+ OK'
         ELSE 'GAGAL: butuh MySQL 8+ atau MariaDB (generated column STORED + JSON)'
       END AS penilaian;

-- Generated column STORED dipakai sebagai penegak invariant. Wajib didukung.
SELECT 'Dukungan STORED generated column' AS periksa,
       CASE
         WHEN VERSION() LIKE '%MariaDB%'
           AND CAST(SUBSTRING_INDEX(VERSION(), '.', 1) AS UNSIGNED) >= 10 THEN 'OK'
         WHEN VERSION() NOT LIKE '%MariaDB%'
           AND CAST(SUBSTRING_INDEX(VERSION(), '.', 1) AS UNSIGNED) >= 8 THEN 'OK'
         ELSE 'GAGAL'
       END AS hasil;


SELECT '========== 2. PRASYARAT: TABEL 2026-08-18 ==========' AS ``;

-- update_2026-08-20 menunjuk ke iku_revisi & lakip_snapshot.
SELECT t.nama AS tabel_wajib,
       IF(i.TABLE_NAME IS NULL, 'GAGAL: belum ada', 'OK') AS hasil
FROM (
  SELECT 'iku_revisi' AS nama UNION ALL SELECT 'iku_revisi_sasaran'
  UNION ALL SELECT 'iku_revisi_indikator' UNION ALL SELECT 'iku_revisi_target'
  UNION ALL SELECT 'iku_revisi_program'   UNION ALL SELECT 'lakip_snapshot'
  UNION ALL SELECT 'lakip_snapshot_baris' UNION ALL SELECT 'lakip_snapshot_analisis'
  UNION ALL SELECT 'lakip_snapshot_program' UNION ALL SELECT 'lakip_penyesuaian'
) t
LEFT JOIN information_schema.TABLES i
  ON i.TABLE_SCHEMA = DATABASE() AND i.TABLE_NAME = t.nama
ORDER BY hasil DESC, t.nama;


SELECT '========== 3. TABEL DEPENDENSI INTI ==========' AS ``;

SELECT t.nama AS tabel, IF(i.TABLE_NAME IS NULL, 'GAGAL: tidak ada', 'OK') AS hasil
FROM (
  SELECT 'rpjmd_visi' AS nama UNION ALL SELECT 'rpjmd_misi' UNION ALL SELECT 'rpjmd_tujuan'
  UNION ALL SELECT 'rpjmd_indikator_tujuan' UNION ALL SELECT 'rpjmd_target_tujuan'
  UNION ALL SELECT 'rpjmd_sasaran' UNION ALL SELECT 'rpjmd_indikator_sasaran'
  UNION ALL SELECT 'rpjmd_target'
  UNION ALL SELECT 'renstra_tujuan' UNION ALL SELECT 'renstra_indikator_tujuan'
  UNION ALL SELECT 'renstra_target_tujuan' UNION ALL SELECT 'renstra_sasaran'
  UNION ALL SELECT 'renstra_indikator_sasaran' UNION ALL SELECT 'renstra_target'
  UNION ALL SELECT 'iku_sasaran' UNION ALL SELECT 'iku_indikator'
  UNION ALL SELECT 'iku_target' UNION ALL SELECT 'iku_program'
  UNION ALL SELECT 'lakip' UNION ALL SELECT 'lakip_benchmark'
  UNION ALL SELECT 'lakip_analisis_faktor' UNION ALL SELECT 'lakip_efisiensi_program'
  UNION ALL SELECT 'permissions' UNION ALL SELECT 'role_permissions' UNION ALL SELECT 'roles'
  UNION ALL SELECT 'opd' UNION ALL SELECT 'satuan' UNION ALL SELECT 'target_rencana'
  UNION ALL SELECT 'cascading_sasaran_opd'
) t
LEFT JOIN information_schema.TABLES i
  ON i.TABLE_SCHEMA = DATABASE() AND i.TABLE_NAME = t.nama
ORDER BY hasil DESC, t.nama;


SELECT '========== 4. TIPE KOLOM FK (jangan menebak tipe, §57) ==========' AS ``;

-- Kolom-kolom yang akan dirujuk / ditambahkan harus cocok tipenya.
SELECT TABLE_NAME AS tabel, COLUMN_NAME AS kolom, COLUMN_TYPE AS tipe, IS_NULLABLE AS nullable
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
    (TABLE_NAME = 'rpjmd_misi'                AND COLUMN_NAME IN ('id','tahun_mulai','tahun_akhir'))
 OR (TABLE_NAME = 'rpjmd_sasaran'             AND COLUMN_NAME = 'id')
 OR (TABLE_NAME = 'rpjmd_indikator_sasaran'   AND COLUMN_NAME = 'id')
 OR (TABLE_NAME = 'renstra_sasaran'           AND COLUMN_NAME IN ('id','opd_id','tahun_mulai','tahun_akhir'))
 OR (TABLE_NAME = 'renstra_indikator_sasaran' AND COLUMN_NAME = 'id')
 OR (TABLE_NAME = 'renstra_target'            AND COLUMN_NAME IN ('id','tahun'))
 OR (TABLE_NAME = 'rpjmd_target'              AND COLUMN_NAME IN ('id','tahun'))
 OR (TABLE_NAME = 'iku_sasaran'               AND COLUMN_NAME IN ('id','opd_id','tahun_mulai','tahun_akhir'))
 OR (TABLE_NAME = 'lakip'                     AND COLUMN_NAME IN ('id','renstra_target_id','rpjmd_target_id'))
 OR (TABLE_NAME = 'lakip_snapshot'            AND COLUMN_NAME IN ('id','tahun','mode','opd_id'))
 OR (TABLE_NAME = 'lakip_snapshot_baris'      AND COLUMN_NAME IN ('id','snapshot_id','sumber'))
 OR (TABLE_NAME = 'opd'                       AND COLUMN_NAME = 'id')
 OR (TABLE_NAME = 'users'                     AND COLUMN_NAME = 'id')
  )
ORDER BY TABLE_NAME, COLUMN_NAME;


SELECT '========== 5. KOLOM BARU — SUDAH ADA SEBELUMNYA? ==========' AS ``;

-- Bila sudah ada, berkas update akan melewatinya (idempoten). Bukan kegagalan,
-- tapi perlu diketahui agar tidak dikira gagal jalan.
SELECT t.tabel, t.kolom,
       IF(c.COLUMN_NAME IS NULL, 'belum ada (akan ditambah)', 'SUDAH ADA (akan dilewati)') AS kondisi
FROM (
  SELECT 'rpjmd_misi' AS tabel, 'version_id' AS kolom
  UNION ALL SELECT 'rpjmd_sasaran','dihentikan_pada'
  UNION ALL SELECT 'renstra_sasaran','version_id'
  UNION ALL SELECT 'renstra_indikator_sasaran','jenis_perubahan'
  UNION ALL SELECT 'lakip','tahun'
  UNION ALL SELECT 'lakip','mode'
  UNION ALL SELECT 'lakip','opd_id'
  UNION ALL SELECT 'lakip_snapshot','source_type'
  UNION ALL SELECT 'lakip_snapshot_baris','source_version_id'
  UNION ALL SELECT 'iku_revisi','version_id'
) t
LEFT JOIN information_schema.COLUMNS c
  ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = t.tabel AND c.COLUMN_NAME = t.kolom
ORDER BY kondisi DESC, t.tabel, t.kolom;

-- Nama tabel baru tidak boleh bentrok dengan tabel yang sudah ada dan berbeda isi.
SELECT TABLE_NAME AS tabel_baru_sudah_ada, TABLE_ROWS AS perkiraan_baris
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'dokumen_versi','version_submission_history','version_correction_requests',
    'lakip_benchmark_item',
    'rpjmd_versi_misi','rpjmd_versi_tujuan','rpjmd_versi_indikator_tujuan',
    'rpjmd_versi_target_tujuan','rpjmd_versi_sasaran','rpjmd_versi_indikator_sasaran',
    'rpjmd_versi_target',
    'renstra_versi_tujuan','renstra_versi_indikator_tujuan','renstra_versi_target_tujuan',
    'renstra_versi_sasaran','renstra_versi_indikator_sasaran','renstra_versi_target'
  );


SELECT '========== 6. JUMLAH RECORD EXISTING (catat untuk dibandingkan) ==========' AS ``;

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
UNION ALL SELECT 'cascading_sasaran_opd',     COUNT(*) FROM cascading_sasaran_opd
UNION ALL SELECT 'permissions',               COUNT(*) FROM permissions
UNION ALL SELECT 'role_permissions',          COUNT(*) FROM role_permissions;


SELECT '========== 7. PERIODE YANG AKAN JADI BASELINE ==========' AS ``;

SELECT 'rpjmd' AS modul, NULL AS opd_id, tahun_mulai, tahun_akhir, COUNT(*) AS jml_misi
FROM rpjmd_misi
WHERE tahun_mulai IS NOT NULL AND tahun_akhir IS NOT NULL
GROUP BY tahun_mulai, tahun_akhir;

SELECT 'renstra' AS modul, COUNT(DISTINCT CONCAT(opd_id,'-',tahun_mulai,'-',tahun_akhir)) AS jml_baseline_akan_dibuat
FROM renstra_sasaran
WHERE opd_id IS NOT NULL AND tahun_mulai IS NOT NULL AND tahun_akhir IS NOT NULL;

SELECT 'iku' AS modul, COUNT(DISTINCT CONCAT(COALESCE(opd_id,0),'-',tahun_mulai,'-',tahun_akhir)) AS jml_baseline_akan_dibuat
FROM iku_sasaran
WHERE tahun_mulai IS NOT NULL AND tahun_akhir IS NOT NULL;


SELECT '========== 8. DATA BERMASALAH (§58 duplicate & orphan) ==========' AS ``;

-- 8.1  Baris lakip yatim: kedua FK sumber NULL. TIDAK akan bisa di-backfill.
SELECT 'lakip yatim (kedua target NULL)' AS temuan, COUNT(*) AS jml,
       'DIBIARKAN — tahun tidak bisa ditebak; ditindaklanjuti manual' AS tindakan
FROM lakip
WHERE renstra_target_id IS NULL AND rpjmd_target_id IS NULL;

-- 8.2  Baris lakip yang FK-nya terisi tapi menunjuk target yang sudah tidak ada.
SELECT 'lakip menunjuk renstra_target hilang' AS temuan, COUNT(*) AS jml, '' AS tindakan
FROM lakip l
LEFT JOIN renstra_target rt ON rt.id = l.renstra_target_id
WHERE l.renstra_target_id IS NOT NULL AND rt.id IS NULL
UNION ALL
SELECT 'lakip menunjuk rpjmd_target hilang', COUNT(*), ''
FROM lakip l
LEFT JOIN rpjmd_target rpj ON rpj.id = l.rpjmd_target_id
WHERE l.rpjmd_target_id IS NOT NULL AND rpj.id IS NULL;

-- 8.3  renstra_tujuan tanpa pemilik (temuan T4) — tidak akan di-backfill otomatis.
SELECT 'renstra_tujuan yatim (tak ada sasaran menunjuk)' AS temuan, COUNT(*) AS jml,
       'DIBIARKAN — kepemilikan tidak boleh ditebak' AS tindakan
FROM renstra_tujuan rt
LEFT JOIN renstra_sasaran rs ON rs.renstra_tujuan_id = rt.id
WHERE rs.id IS NULL;

-- 8.4  renstra_tujuan dipakai LEBIH DARI SATU OPD -> backfill version_id ambigu.
SELECT 'renstra_tujuan dipakai >1 OPD' AS temuan, COUNT(*) AS jml,
       IF(COUNT(*) = 0, 'OK', 'PERIKSA: backfill version_id akan ambigu') AS tindakan
FROM (
  SELECT rt.id FROM renstra_tujuan rt
  JOIN renstra_sasaran rs ON rs.renstra_tujuan_id = rt.id
  GROUP BY rt.id HAVING COUNT(DISTINCT rs.opd_id) > 1
) x;

-- 8.5  renstra_sasaran dengan periode berbeda di bawah satu tujuan yang sama.
SELECT 'renstra_tujuan dgn >1 periode' AS temuan, COUNT(*) AS jml,
       IF(COUNT(*) = 0, 'OK', 'PERIKSA: satu tujuan menaungi >1 periode') AS tindakan
FROM (
  SELECT rt.id FROM renstra_tujuan rt
  JOIN renstra_sasaran rs ON rs.renstra_tujuan_id = rt.id
  GROUP BY rt.id HAVING COUNT(DISTINCT CONCAT(rs.tahun_mulai,'-',rs.tahun_akhir)) > 1
) x;

-- 8.6  Periode tidak masuk akal.
SELECT 'periode terbalik / nol' AS temuan, COUNT(*) AS jml,
       IF(COUNT(*) = 0, 'OK', 'GAGAL: perbaiki dulu, baseline akan salah') AS tindakan
FROM (
  SELECT id FROM rpjmd_misi
   WHERE tahun_mulai IS NULL OR tahun_akhir IS NULL
      OR CAST(tahun_mulai AS UNSIGNED) = 0
      OR CAST(tahun_akhir AS UNSIGNED) < CAST(tahun_mulai AS UNSIGNED)
  UNION ALL
  SELECT id FROM renstra_sasaran
   WHERE tahun_mulai IS NULL OR tahun_akhir IS NULL
      OR tahun_mulai = 0 OR tahun_akhir < tahun_mulai
) x;


SELECT '========== 9. PERMISSION EXISTING ==========' AS ``;

SELECT p.name AS permission_sudah_ada, p.grup
FROM permissions p
WHERE p.name LIKE '%.version.%'
   OR p.name LIKE 'version_correction.%'
   OR p.name IN ('lakip_benchmark.manage','lakip_benchmark.manage_own','lakip_benchmark.manage_all')
ORDER BY p.name;

-- Hak sahkan mandiri OPD yang AKAN DICABUT pemberiannya (bukan permissionnya).
SELECT 'iku_opd.revisi_sahkan akan dicabut dari role ini' AS catatan,
       GROUP_CONCAT(r.name ORDER BY r.name) AS role_terdampak
FROM role_permissions rp
JOIN roles r       ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE p.name = 'iku_opd.revisi_sahkan'
  AND r.name IN ('admin_opd','admin_kecamatan');

SELECT r.name AS role, COUNT(*) AS jml_permission
FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
GROUP BY r.name ORDER BY r.name;


SELECT '========== 10. UNIQUE INDEX YANG BISA MENGGANJAL DEEP COPY (§44) ==========' AS ``;

-- Deep copy membuat baris baru dengan id baru. UNIQUE yang TIDAK memuat
-- version_id / indikator_id bisa menolak salinan tersebut.
SELECT s.TABLE_NAME AS tabel, s.INDEX_NAME AS nama_index,
       GROUP_CONCAT(s.COLUMN_NAME ORDER BY s.SEQ_IN_INDEX) AS kolom
FROM information_schema.STATISTICS s
WHERE s.TABLE_SCHEMA = DATABASE()
  AND s.NON_UNIQUE = 0
  AND s.INDEX_NAME <> 'PRIMARY'
  AND s.TABLE_NAME IN (
    'rpjmd_misi','rpjmd_tujuan','rpjmd_indikator_tujuan','rpjmd_target_tujuan',
    'rpjmd_sasaran','rpjmd_indikator_sasaran','rpjmd_target',
    'renstra_tujuan','renstra_indikator_tujuan','renstra_target_tujuan',
    'renstra_sasaran','renstra_indikator_sasaran','renstra_target',
    'iku_sasaran','iku_indikator','iku_target','iku_program',
    'lakip','lakip_benchmark','lakip_analisis_faktor'
  )
GROUP BY s.TABLE_NAME, s.INDEX_NAME
ORDER BY s.TABLE_NAME, s.INDEX_NAME;


SELECT '========== 11. FK BER-CASCADE YANG BERBAHAYA (tidak diubah, hanya dicatat) ==========' AS ``;

SELECT CONCAT(rc.TABLE_NAME,'.',kcu.COLUMN_NAME) AS dari,
       CONCAT(kcu.REFERENCED_TABLE_NAME,'.',kcu.REFERENCED_COLUMN_NAME) AS ke,
       rc.DELETE_RULE AS aturan_hapus
FROM information_schema.REFERENTIAL_CONSTRAINTS rc
JOIN information_schema.KEY_COLUMN_USAGE kcu
  ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
 AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
  AND kcu.REFERENCED_TABLE_NAME IN (
    'rpjmd_sasaran','rpjmd_indikator_sasaran','rpjmd_target',
    'renstra_tujuan','renstra_sasaran','renstra_indikator_sasaran','renstra_target'
  )
ORDER BY kcu.REFERENCED_TABLE_NAME, rc.TABLE_NAME;

-- Berapa OPD yang Renstra-nya menggantung pada rpjmd_sasaran (temuan T5).
SELECT 'OPD terdampak bila rpjmd_sasaran dihapus' AS catatan,
       COUNT(DISTINCT rs.opd_id) AS jml_opd
FROM rpjmd_sasaran ps
JOIN renstra_tujuan rt  ON rt.rpjmd_sasaran_id = ps.id
JOIN renstra_sasaran rs ON rs.renstra_tujuan_id = rt.id;


SELECT '========== PREFLIGHT SELESAI ==========' AS ``;
SELECT 'Periksa setiap baris bertanda GAGAL sebelum melanjutkan.' AS instruksi;
