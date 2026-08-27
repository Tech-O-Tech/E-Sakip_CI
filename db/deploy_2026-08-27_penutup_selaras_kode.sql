-- =====================================================================
-- PENUTUP DEPLOY: SELARASKAN SERVER DENGAN KODE
-- Tanggal : 2026-08-27
-- Sifat   : IDEMPOTEN. Tidak ada DROP, DELETE, atau penimpaan nilai.
--
-- =====================================================================
-- JALANKAN PALING AKHIR
--
-- Berkas ini melengkapi empat naskah yang harus dijalankan LEBIH DULU,
-- berurutan:
--
--   1. db/baseline_2026-08-27_catatan_migrations.sql
--   2. db/update_2026-08-24_opd_jenis.sql
--   3. db/update_2026-08-27_cascading_sumber_iku.sql
--   4. db/update_2026-08-28_silsilah_iku_kabupaten.sql
--   5. berkas ini
--
-- Urutan 1 sebelum 2 itu WAJIB bila sesudahnya `php spark migrate` akan
-- dijalankan: tanpa baseline, CodeIgniter mengira 31 migrasi lama belum
-- pernah jalan dan mencoba MEMBUAT ULANG tabel yang sudah berisi data.
--
-- =====================================================================
-- ISINYA DUA HAL
--
-- BAGIAN 1 - Catatan migrasi `AddJenisToOpd`.
--   Naskah nomor 2 mengubah skemanya lewat SQL langsung, jadi tabel
--   `migrations` tidak ikut tercatat. Tanpa baris ini, `php spark migrate`
--   akan menjalankan ulang migrasinya. Tidak berbahaya (migrasinya
--   memeriksa kolom dulu), tetapi riwayatnya jadi tidak jujur.
--
-- BAGIAN 2 - Kondisi Awal IKU Dinas Koperasi 2025-2029.
--   Dinas Koperasi (opd 16) punya revisi ke-1 berlaku mulai 2026, tetapi
--   TIDAK punya revisi ke-0. Akibatnya tahun 2025 tidak dipayungi dokumen
--   mana pun, dan LAKIP 2025 tidak menemukan arsip IKU yang berlaku.
--
--   Isi di bawah ini BUKAN tulisan tangan: ia hasil `php spark
--   iku:rapikan-periode --fix` yang dijalankan di salinan basis data
--   server ini, lalu diangkut apa adanya. Logika pembekuan arsip memang
--   milik aplikasi (IkuRevisiModel::bekukanLiveKeRevisi) dan sengaja
--   TIDAK ditulis ulang dalam SQL.
--
--   Bila server punya akses CLI, lebih baik lewatkan BAGIAN 2 dan
--   jalankan perintahnya langsung:
--
--       php spark iku:rapikan-periode --fix
--
--   Hasilnya sama persis. BAGIAN 2 hanya untuk server yang cuma bisa
--   diakses lewat phpMyAdmin.
--
--   Penjaganya: seluruh sisipan bersyarat @perlu = 1, yang bernilai 1
--   HANYA bila Dinas Koperasi 2025-2029 benar-benar belum punya revisi
--   ke-0. Dijalankan dua kali, yang kedua tidak melakukan apa-apa.
-- =====================================================================


-- =====================================================================
-- BAGIAN 1 - CATATAN MIGRASI
-- =====================================================================

INSERT INTO `migrations` (`version`,`class`,`group`,`namespace`,`time`,`batch`)
SELECT '2026-08-24-000001','App\Database\Migrations\AddJenisToOpd','default','App',UNIX_TIMESTAMP(),99
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `version`='2026-08-24-000001');


-- =====================================================================
-- BAGIAN 2 - KONDISI AWAL IKU DINAS KOPERASI 2025-2029
-- =====================================================================

-- Penjaga. 1 = perlu dipulihkan, 0 = sudah ada, jangan sentuh.
SET @perlu := (
    SELECT IF(COUNT(*) = 0, 1, 0)
      FROM `iku_revisi`
     WHERE `opd_key` = 16 AND `tahun_mulai` = 2025 AND `tahun_akhir` = 2029
       AND `nomor` = 0
);

SELECT IF(@perlu = 1,
          'PERLU - Kondisi Awal akan dibuat',
          'LEWAT - Kondisi Awal sudah ada, tidak ada yang diubah') AS `penjaga`;

INSERT INTO `iku_revisi` (`id`, `opd_id`, `tahun_mulai`, `tahun_akhir`, `nomor`, `nama`, `dasar_hukum`, `nomor_dasar`, `tanggal_dasar`, `berlaku_mulai_tahun`, `berlaku_sampai_tahun`, `status`, `catatan`, `dibuat_oleh`, `disahkan_oleh`, `disahkan_pada`, `dibekukan_pada`, `created_at`, `updated_at`, `version_id`, `submitted_by`, `submitted_at`) SELECT 2,16,2025,2029,0,'Kondisi Awal IKU 2025-2029',NULL,NULL,NULL,2025,2025,'superseded','Dipulihkan otomatis: revisi bernomor ditemukan tanpa Kondisi Awal. Isi dibekukan dari IKU berjalan saat pemulihan.',NULL,NULL,NOW(),NOW(),NOW(),NOW(),NULL,NULL,NULL FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_sasaran` (`id`, `revisi_id`, `sumber_sasaran_id`, `sasaran`, `tahun_mulai`, `tahun_akhir`, `urutan`, `jenis_perubahan`, `catatan_perubahan`, `created_at`, `updated_at`, `source_type`, `source_version_id`, `source_ref_id`) SELECT 5,2,100,'Meningkatnya Daya Saing Koperasi ',2025,2029,0,'tetap',NULL,NOW(),NOW(),'renstra',NULL,97 FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_sasaran` (`id`, `revisi_id`, `sumber_sasaran_id`, `sasaran`, `tahun_mulai`, `tahun_akhir`, `urutan`, `jenis_perubahan`, `catatan_perubahan`, `created_at`, `updated_at`, `source_type`, `source_version_id`, `source_ref_id`) SELECT 6,2,101,'Meningkatnya Daya Saing UMKM',2025,2029,1,'tetap',NULL,NOW(),NOW(),'renstra',NULL,178 FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_sasaran` (`id`, `revisi_id`, `sumber_sasaran_id`, `sasaran`, `tahun_mulai`, `tahun_akhir`, `urutan`, `jenis_perubahan`, `catatan_perubahan`, `created_at`, `updated_at`, `source_type`, `source_version_id`, `source_ref_id`) SELECT 7,2,102,'Meningkatnya kinerja dan daya saing sektor perdagangan daerah',2025,2029,2,'tetap',NULL,NOW(),NOW(),'renstra',NULL,179 FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_sasaran` (`id`, `revisi_id`, `sumber_sasaran_id`, `sasaran`, `tahun_mulai`, `tahun_akhir`, `urutan`, `jenis_perubahan`, `catatan_perubahan`, `created_at`, `updated_at`, `source_type`, `source_version_id`, `source_ref_id`) SELECT 8,2,103,'Meningkatnya pertumbuhan dan daya saing Industri Kecil dan Menengah (IKM)',2025,2029,3,'tetap',NULL,NOW(),NOW(),'renstra',NULL,181 FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_indikator` (`id`, `revisi_id`, `revisi_sasaran_id`, `sumber_indikator_id`, `indikator`, `definisi`, `rumusan_perhitungan`, `satuan`, `satuan_nama`, `sumber_data`, `penanggung_jawab`, `jenis_indikator`, `baseline`, `urutan`, `status`, `jenis_perubahan`, `indikator_sebelumnya_id`, `perubahan_substansial`, `catatan_perubahan`, `created_at`, `updated_at`, `source_type`, `source_version_id`, `source_ref_id`) SELECT 5,2,5,126,'Presentase koperasi sehat dengan omzet naik',NULL,NULL,'1','Persen',NULL,NULL,'positif','30,30',0,'draft','tetap',NULL,0,NULL,NOW(),NOW(),'renstra',NULL,215 FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_indikator` (`id`, `revisi_id`, `revisi_sasaran_id`, `sumber_indikator_id`, `indikator`, `definisi`, `rumusan_perhitungan`, `satuan`, `satuan_nama`, `sumber_data`, `penanggung_jawab`, `jenis_indikator`, `baseline`, `urutan`, `status`, `jenis_perubahan`, `indikator_sebelumnya_id`, `perubahan_substansial`, `catatan_perubahan`, `created_at`, `updated_at`, `source_type`, `source_version_id`, `source_ref_id`) SELECT 6,2,6,127,'Persentase UMKM yang mengalami peningkatan omzet setelah mendapat pembinaan',NULL,NULL,'1','Persen',NULL,NULL,'positif','9,03',0,'draft','tetap',NULL,0,NULL,NOW(),NOW(),'renstra',NULL,469 FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_indikator` (`id`, `revisi_id`, `revisi_sasaran_id`, `sumber_indikator_id`, `indikator`, `definisi`, `rumusan_perhitungan`, `satuan`, `satuan_nama`, `sumber_data`, `penanggung_jawab`, `jenis_indikator`, `baseline`, `urutan`, `status`, `jenis_perubahan`, `indikator_sebelumnya_id`, `perubahan_substansial`, `catatan_perubahan`, `created_at`, `updated_at`, `source_type`, `source_version_id`, `source_ref_id`) SELECT 7,2,7,128,'Kontribusi sektor perdagangan terhadap PDRB',NULL,NULL,'1','Persen',NULL,NULL,'positif','17,00',0,'draft','tetap',NULL,0,NULL,NOW(),NOW(),'renstra',NULL,470 FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_indikator` (`id`, `revisi_id`, `revisi_sasaran_id`, `sumber_indikator_id`, `indikator`, `definisi`, `rumusan_perhitungan`, `satuan`, `satuan_nama`, `sumber_data`, `penanggung_jawab`, `jenis_indikator`, `baseline`, `urutan`, `status`, `jenis_perubahan`, `indikator_sebelumnya_id`, `perubahan_substansial`, `catatan_perubahan`, `created_at`, `updated_at`, `source_type`, `source_version_id`, `source_ref_id`) SELECT 8,2,8,129,'Kontribusi PDRB Industri Pengolahan',NULL,NULL,'1','Persen',NULL,NULL,'positif','14,26',0,'draft','tetap',NULL,0,NULL,NOW(),NOW(),'renstra',NULL,472 FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 21,5,2025,'30,30',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 22,5,2026,'36,36',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 23,5,2027,'41,67',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 24,5,2028,'45,00',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 25,5,2029,'50,00',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 26,6,2025,'9,03',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 27,6,2026,'9,85',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 28,6,2027,'10,65',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 29,6,2028,'11,43',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 30,6,2029,'12,31',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 31,7,2025,'17,00',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 32,7,2026,'17,02',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 33,7,2027,'17,05',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 34,7,2028,'17,08',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 35,7,2029,'17,10',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 36,8,2025,'14,26',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 37,8,2026,'14,28',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 38,8,2027,'14,30',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 39,8,2028,'14,31',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;
INSERT INTO `iku_revisi_target` (`id`, `revisi_indikator_id`, `tahun`, `target`, `target_sebelumnya`, `created_at`, `updated_at`) SELECT 40,8,2029,'14,33',NULL,NOW(),NOW() FROM DUAL WHERE @perlu = 1;


-- =====================================================================
-- BAGIAN 3 - VERIFIKASI
-- Baca hasilnya. Kolom `hasil` harus berbunyi OK semua.
-- =====================================================================

SELECT '========== VERIFIKASI SELARAS KODE ==========' AS `laporan`;

SELECT 'riwayat migrasi tercatat' AS periksa,
       COUNT(*) AS nilai, '71' AS diharapkan,
       IF(COUNT(*) = 71, 'OK', 'PERIKSA') AS hasil
  FROM `migrations`
UNION ALL
SELECT 'kolom opd.jenis',
       COUNT(*), '1', IF(COUNT(*) = 1, 'OK', 'PERIKSA')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'opd' AND COLUMN_NAME = 'jenis'
UNION ALL
SELECT 'kolom jangkar IKU di cascading',
       COUNT(*), '2', IF(COUNT(*) = 2, 'OK', 'PERIKSA')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cascading_sasaran_opd'
   AND COLUMN_NAME IN ('iku_indikator_id', 'source_type')
UNION ALL
SELECT 'jangkar Renstra boleh kosong',
       IF(IS_NULLABLE = 'YES', 1, 0), '1', IF(IS_NULLABLE = 'YES', 'OK', 'PERIKSA')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cascading_sasaran_opd'
   AND COLUMN_NAME = 'renstra_indikator_sasaran_id'
UNION ALL
SELECT 'FK cascading -> Renstra memakai SET NULL',
       COUNT(*), '1', IF(COUNT(*) = 1, 'OK', 'PERIKSA')
  FROM information_schema.REFERENTIAL_CONSTRAINTS
 WHERE CONSTRAINT_SCHEMA = DATABASE()
   AND CONSTRAINT_NAME = 'fk_cascading_renstra_indikator' AND DELETE_RULE = 'SET NULL'
UNION ALL
SELECT 'Kondisi Awal tanpa pasangan (idealnya 0)',
       COUNT(*), '0', IF(COUNT(*) = 0, 'OK', 'PERIKSA')
  FROM (SELECT `opd_key`, `tahun_mulai`, `tahun_akhir`
          FROM `iku_revisi`
         GROUP BY `opd_key`, `tahun_mulai`, `tahun_akhir`
        HAVING SUM(`nomor` > 0) > 0 AND SUM(`nomor` = 0) = 0) AS bolong;

SELECT '-- sebaran jenis entitas OPD --' AS `laporan`;
SELECT `jenis`, COUNT(*) AS jml FROM `opd` GROUP BY `jenis` ORDER BY jml DESC;

SELECT '-- sumber jangkar baris cascading --' AS `laporan`;
SELECT `source_type` AS sumber, COUNT(*) AS baris
  FROM `cascading_sasaran_opd` GROUP BY `source_type` ORDER BY baris DESC;

SELECT '-- revisi IKU per lingkup --' AS `laporan`;
SELECT `opd_key`, `tahun_mulai`, `tahun_akhir`,
       SUM(`nomor` = 0) AS kondisi_awal, SUM(`nomor` > 0) AS bernomor
  FROM `iku_revisi`
 GROUP BY `opd_key`, `tahun_mulai`, `tahun_akhir`;
