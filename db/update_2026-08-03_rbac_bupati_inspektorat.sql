-- =====================================================================
-- RBAC + AKUN: role `bupati` dan role `admin_inspektorat`
-- Tanggal : 2026-08-03
-- Sifat   : IDEMPOTEN (NOT EXISTS) + ADDITIVE — aman dijalankan berulang.
--
-- Melengkapi db/update_2026-07-30_role_bupati.sql (role+permission Bupati)
-- dan db/update_2026-07-02_rbac_kec_inspektorat.sql (role Inspektorat)
-- dengan menambahkan bagian yang belum pernah ada di skrip SQL mana pun:
-- AKUN PENGGUNA untuk kedua role tersebut.
--
-- Struktur yang dipakai (hasil pemeriksaan DB berjalan, bukan asumsi):
--   users            (username, password, email, role, opd_id, is_active, …)
--                    users.role = VARCHAR(50) -> tidak ada enum yang perlu diubah
--   roles            (name, label, is_system)
--   permissions      (name, label, grup)
--   role_permissions (role_id, permission_id) UNIQUE(role_id, permission_id)
--
-- PENTING soal `opd_id`: untuk role `bupati` dan `admin_inspektorat` nilainya
-- WAJIB NULL. Keduanya peran lintas Perangkat Daerah; bila diisi salah satu OPD,
-- pemeriksaan BaseController::canAccessOpd akan mengunci akun ke OPD itu saja.
--
-- Jalankan:
--   mysql -u root test_sakip < db/update_2026-08-03_rbac_bupati_inspektorat.sql
-- =====================================================================

-- =====================================================================
-- BAGIAN A — ROLE BUPATI (read-only, dashboard eksekutif)
-- =====================================================================

-- ---------- A1. Master role ----------
INSERT INTO `roles` (`name`, `label`, `is_system`, `created_at`, `updated_at`)
SELECT 'bupati', 'Bupati', 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `name` = 'bupati');

UPDATE `roles`
SET `label`     = COALESCE(NULLIF(`label`, ''), 'Bupati'),
    `is_system` = 1
WHERE `name` = 'bupati';

-- ---------- A2. Permission khusus Bupati (grup "Bupati", semuanya view) ----------
INSERT INTO `permissions` (`name`, `label`, `grup`, `created_at`, `updated_at`)
SELECT 'dashboard_bupati.view', 'Dashboard Eksekutif Bupati - Lihat', 'Bupati', NOW(), NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'dashboard_bupati.view');

INSERT INTO `permissions` (`name`, `label`, `grup`, `created_at`, `updated_at`)
SELECT 'pk_bupati_monitoring.view', 'Monitoring Perjanjian Kinerja - Lihat', 'Bupati', NOW(), NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'pk_bupati_monitoring.view');

INSERT INTO `permissions` (`name`, `label`, `grup`, `created_at`, `updated_at`)
SELECT 'target_bupati_monitoring.view', 'Monitoring Target & Rencana Aksi - Lihat', 'Bupati', NOW(), NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'target_bupati_monitoring.view');

INSERT INTO `permissions` (`name`, `label`, `grup`, `created_at`, `updated_at`)
SELECT 'monev_bupati_monitoring.view', 'Monitoring MONEV - Lihat', 'Bupati', NOW(), NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'monev_bupati_monitoring.view');

INSERT INTO `permissions` (`name`, `label`, `grup`, `created_at`, `updated_at`)
SELECT 'lakip_bupati_monitoring.view', 'Monitoring LAKIP - Lihat', 'Bupati', NOW(), NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'lakip_bupati_monitoring.view');

-- ---------- A3. Pemetaan role bupati -> permission ----------
-- `dashboard.view` diikutkan agar entri Dashboard pada sidebar terpadu muncul
-- (BUKAN untuk membuka /adminkab/dashboard — grup rute itu dijaga AuthFilter).
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT rb.id, p.id
FROM `roles` rb
JOIN `permissions` p ON p.name IN (
  'dashboard.view',
  'dashboard_bupati.view',
  'pk_bupati_monitoring.view',
  'target_bupati_monitoring.view',
  'monev_bupati_monitoring.view',
  'lakip_bupati_monitoring.view'
)
WHERE rb.name = 'bupati'
  AND NOT EXISTS (
    SELECT 1 FROM `role_permissions` x
    WHERE x.role_id = rb.id AND x.permission_id = p.id
  );

-- =====================================================================
-- BAGIAN B — ROLE ADMIN INSPEKTORAT (read-only lintas OPD, rute /adminkab)
-- =====================================================================

-- ---------- B1. Master role ----------
INSERT INTO `roles` (`name`, `label`, `is_system`, `created_at`, `updated_at`)
SELECT 'admin_inspektorat', 'Admin Inspektorat', 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `name` = 'admin_inspektorat');

UPDATE `roles`
SET `label`     = COALESCE(NULLIF(`label`, ''), 'Admin Inspektorat'),
    `is_system` = 1
WHERE `name` = 'admin_inspektorat';

-- ---------- B2. Pemetaan role admin_inspektorat -> permission ----------
-- Semua permission di bawah ini sudah ada dari seeder RBAC inti; yang dibuat di
-- sini hanya pemetaannya. Hanya izin *.view -> tidak ada create/update/delete.
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT ri.id, p.id
FROM `roles` ri
JOIN `permissions` p ON p.name IN (
  'dashboard.view',
  'tentang_kami.view',
  'rpjmd.view',
  'cascading_kab.view',
  'pk_bupati.view',
  'lakip_kab.view'
)
WHERE ri.name = 'admin_inspektorat'
  AND NOT EXISTS (
    SELECT 1 FROM `role_permissions` x
    WHERE x.role_id = ri.id AND x.permission_id = p.id
  );

-- =====================================================================
-- BAGIAN C — AKUN PENGGUNA
--
-- Kata sandi disimpan sebagai hash bcrypt (password_hash/PASSWORD_DEFAULT),
-- sesuai LoginController yang memverifikasi dengan password_verify().
--
--   username: bupati                 kata sandi awal: Bupati#2026
--   username: inspektorat_evaluasi   kata sandi awal: Inspektorat#2026
--
-- Catatan nama akun: pada DB berjalan SUDAH ADA user `admin_inspektorat`
-- (role `admin_opd`, opd_id = 4) — itu admin OPD Inspektorat yang mengelola
-- Renstra/PK Inspektorat sendiri, BUKAN evaluator lintas OPD. Karena itu akun
-- evaluator di bawah memakai username `inspektorat_evaluasi` supaya keduanya
-- bisa hidup berdampingan dan akun lama tidak berubah perannya.
--
-- !! KATA SANDI AWAL INI TERTULIS DI BERKAS SKRIP (dan ikut masuk Git) !!
-- Ganti lewat menu Profil Saya SEGERA setelah login pertama, atau pakai
-- perintah reset di BAGIAN D dengan hash yang Anda buat sendiri.
--
-- Baris hanya dibuat bila belum ada username/email yang sama, sehingga akun
-- yang sudah terlanjur dibuat TIDAK tertimpa dan kata sandinya TIDAK berubah.
-- =====================================================================

-- ---------- C1. Akun Bupati ----------
INSERT INTO `users` (`username`, `password`, `email`, `role`, `opd_id`, `is_active`, `created_at`, `updated_at`)
SELECT 'bupati',
       '$2y$12$48HdHt9wSvX/DfWvLZKPouggBR.b9u2Ln9CP9fN27Wp/Ff7ucLuhO',  -- Bupati#2026
       'bupati@pringsewukab.go.id',
       'bupati',
       NULL,          -- WAJIB NULL: peran lintas Perangkat Daerah
       1,
       NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM (SELECT `username`, `email` FROM `users`) u
  WHERE u.`username` = 'bupati' OR u.`email` = 'bupati@pringsewukab.go.id'
);

-- ---------- C2. Akun evaluator Inspektorat (lintas OPD, read-only) ----------
INSERT INTO `users` (`username`, `password`, `email`, `role`, `opd_id`, `is_active`, `created_at`, `updated_at`)
SELECT 'inspektorat_evaluasi',
       '$2y$12$BOhOs276RGdHXQd/8qNkPuwO3W2LZBwxm6/ZgdoHl.AUCe1Wl4KLS',  -- Inspektorat#2026
       'inspektorat.evaluasi@pringsewukab.go.id',
       'admin_inspektorat',
       NULL,          -- WAJIB NULL: read-only lintas Perangkat Daerah
       1,
       NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM (SELECT `username`, `email` FROM `users`) u
  WHERE u.`username` = 'inspektorat_evaluasi'
     OR u.`email`    = 'inspektorat.evaluasi@pringsewukab.go.id'
);

-- ---------- C3. Pengaman: pastikan kedua akun tidak terikat OPD ----------
UPDATE `users`
SET `opd_id` = NULL, `updated_at` = NOW()
WHERE `role` IN ('bupati', 'admin_inspektorat')
  AND `opd_id` IS NOT NULL;

-- =====================================================================
-- BAGIAN D — OPSIONAL (dinonaktifkan; hapus komentar bila perlu)
-- =====================================================================

-- D1. Ganti kata sandi akun yang SUDAH ada. Buat hash-nya lebih dulu:
--     php -r "echo password_hash('KataSandiBaru', PASSWORD_DEFAULT), PHP_EOL;"
-- UPDATE `users` SET `password` = '<TEMPEL_HASH_DI_SINI>', `updated_at` = NOW()
--  WHERE `username` = 'bupati';
-- UPDATE `users` SET `password` = '<TEMPEL_HASH_DI_SINI>', `updated_at` = NOW()
--  WHERE `username` = 'inspektorat_evaluasi';

-- D2. Nonaktifkan akun sementara (tanpa menghapus datanya):
-- UPDATE `users` SET `is_active` = 0, `updated_at` = NOW() WHERE `username` = 'inspektorat_evaluasi';

-- D3. ALTERNATIF: memakai ulang akun `admin_inspektorat` yang sudah ada
--     (role `admin_opd`, opd_id = 4) sebagai evaluator lintas OPD, alih-alih
--     membuat akun baru di C2. JANGAN dijalankan bila akun itu masih dipakai
--     mengelola Renstra/PK milik OPD Inspektorat — setelah diubah, akun tsb
--     menjadi READ-ONLY dan kehilangan hak tulis atas OPD-nya.
-- UPDATE `users` SET `role` = 'admin_inspektorat', `opd_id` = NULL, `updated_at` = NOW()
--  WHERE `username` = 'admin_inspektorat';

-- =====================================================================
-- BAGIAN E — VERIFIKASI (jalankan manual setelah skrip selesai)
-- =====================================================================
-- SELECT r.name AS role, p.name AS permission
--   FROM role_permissions rp
--   JOIN roles r       ON r.id = rp.role_id
--   JOIN permissions p ON p.id = rp.permission_id
--  WHERE r.name IN ('bupati', 'admin_inspektorat')
--  ORDER BY r.name, p.name;
--
-- SELECT user_id, username, email, role, opd_id, is_active
--   FROM users WHERE role IN ('bupati', 'admin_inspektorat');
