# MASTER PROMPT IMPLEMENTASI VERSIONING E-SAKIP / AKSARA
## RPJMD, Renstra, IKU, LAKIP, Approval, Historical Version, Snapshot, Benchmark, Audit, SQL Production

Anda bekerja pada project **e-SAKIP / AKSARA Kabupaten Pringsewu** berbasis **CodeIgniter 4 + MySQL/MariaDB**.

Task ini adalah **perubahan arsitektur besar**. Jangan langsung melakukan coding sebelum memahami struktur project terbaru.

---

# 0. WAJIB ANALISIS PROJECT TERLEBIH DAHULU

Sebelum menulis kode, audit project terbaru secara menyeluruh.

Pelajari minimal:

- `app/Config/Routes.php`
- seluruh Controller terkait RPJMD, Renstra, IKU, LAKIP, RKT/Renja, PK, Cascading, Renaksi, MONEV, Dashboard
- seluruh Model terkait
- Service/Trait/Helper terkait
- Views terkait
- sidebar/menu
- RBAC, Filter, Permission, helper `user_can()` atau mekanisme ekuivalen
- schema database terbaru
- SQL terbaru di folder `db/`
- `AI_AGENT_GUIDE_AKSARA.md` jika tersedia
- PDF/Excel/API/Public View yang menggunakan data RPJMD/Renstra/IKU/LAKIP

Cari seluruh dependency dan foreign key sebelum membuat perubahan.

Jika dokumentasi berbeda dengan source aktif, gunakan prioritas:

1. Routes aktif
2. Controller / Service / Model aktif
3. Database/schema aktual
4. RBAC/permission
5. View aktif
6. Dokumentasi

Jangan mengaktifkan file/controller/route legacy hanya karena filenya masih ada.

Buat internal implementation plan terlebih dahulu:

1. dependency map;
2. schema impact;
3. backward compatibility strategy;
4. migration/backfill strategy;
5. route/controller/service/model changes;
6. UI/sidebar changes;
7. regression test plan;
8. SQL production plan.

Setelah itu baru coding.

---

# 1. TUJUAN UTAMA

Ubah arsitektur dokumen utama menjadi **version-aware**:

- RPJMD
- Renstra
- IKU
- LAKIP

Kebutuhan bisnis:

1. RPJMD/Renstra/IKU dapat memiliki banyak versi dalam satu periode.
2. Versi dapat berlaku di masa lalu/retrospektif, saat ini, atau di masa depan.
3. Dalam satu tahun dapat terjadi lebih dari satu perubahan.
4. User dapat membuat versi baru dengan deep copy dari versi existing atau input dari kosong.
5. Khusus IKU, versi baru juga dapat dibuat dengan one-time sync/copy dari RPJMD version tertentu untuk Kabupaten atau Renstra version tertentu untuk OPD.
6. Versi resmi tidak boleh ditimpa.
7. LAKIP default mengambil data dari IKU version yang relevan, tetapi Kabupaten dapat memilih RPJMD dan OPD dapat memilih Renstra sebagai alternatif.
8. LAKIP mempunyai version/revision sendiri.
9. Sasaran/Indikator/Satuan/Tahun/Target di LAKIP berasal dari snapshot source dan bersifat read-only.
10. Benchmark melekat per LAKIP/snapshot item.
11. OPD boleh menginput benchmark untuk LAKIP OPD-nya sendiri.
12. Version OPD harus diverifikasi Admin Kabupaten sebelum menjadi resmi.
13. Version resmi dapat menerima correction request untuk typo/non-substantif, dengan approval Admin Kabupaten.
14. Semua perubahan database harus disertai **SQL manual production** yang dapat dieksekusi langsung di server.

---

# 2. BUSINESS INVARIANTS

Pegang aturan berikut:

1. Jangan mengubah sejarah.
2. Jangan overwrite version resmi.
3. Published version immutable terhadap edit normal.
4. Jangan hard-delete entity historis yang pernah berlaku/direferensikan.
5. Setiap perubahan harus dapat ditelusuri siapa, kapan, alasan, dasar, source, dan effective date-nya.
6. `version_no` bukan penentu waktu berlaku.
7. `effective_from` adalah penentu timeline.
8. Data historis existing tetap dapat dibaca/dicetak/export.
9. Version baru tidak boleh diam-diam memindahkan dependency lama.
10. LAKIP lama tidak boleh berubah otomatis hanya karena source document mempunyai version baru.
11. Draft version tidak boleh menjadi source resmi LAKIP.
12. Jangan membuat LAKIP multi-source per indikator.
13. LAKIP tidak boleh mengedit Sasaran/Indikator/Satuan/Target sumber secara langsung.
14. Correction administratif tidak boleh digunakan untuk perubahan substantif.
15. Semua operasi sensitif harus transaction-safe.

---

# 3. MODEL WAKTU VERSION

Jangan hanya menggunakan `effective_year`.

Gunakan:

```text
version_no
effective_from DATE
effective_to DATE NULL
created_at
```

Contoh:

```text
V1
created_at     = 2025-01-01
effective_from = 2025-01-01

V2
created_at     = 2028-01-01
effective_from = 2028-01-01

V3
created_at     = 2029-02-01
effective_from = 2026-07-01
```

Urutan pembuatan:

```text
V1 → V2 → V3
```

Timeline resmi:

```text
2025-01-01 ─ 2026-06-30 → V1
2026-07-01 ─ 2027-12-31 → V3
2028-01-01 ─ ...        → V2
```

Ini valid.

Jangan renumber version lama hanya karena version historis disisipkan.

`effective_to` sebaiknya dihitung/diperbarui sistem berdasarkan published version berikutnya, bukan bebas diedit user.

---

# 4. VERSION HISTORIS / VERSI MUNDUR

User boleh memasukkan version yang baru dibuat sekarang tetapi sebenarnya berlaku pada masa lalu.

Contoh:

```text
RPJMD Baru
effective_from = 2026-01-01
```

Kemudian diketahui ada RPJMD lama:

```text
RPJMD Lama
effective_from = 2021-01-01
```

Flow:

```text
Create Historical Version
→ isi/copy data
→ effective_from historis
→ Draft
→ Submit
→ Verify
→ Published
```

Setelah Published, timeline disusun ulang.

Version historis tersebut kemudian dapat menjadi source LAKIP tahun lama.

Jangan mengizinkan LAKIP menggunakan version historis yang masih Draft.

---

# 5. MULTIPLE VERSION DALAM SATU TAHUN

Dukung:

```text
V1 effective_from = 2026-01-01
V2 effective_from = 2026-03-15
V3 effective_from = 2026-09-01
```

Timeline:

```text
01 Jan – 14 Mar → V1
15 Mar – 31 Agu → V2
01 Sep – seterusnya → V3
```

Resolver harus deterministic berdasarkan tanggal referensi.

---

# 6. TIMELINE CONFLICT

Untuk satu dokumen + satu scope + satu periode, tidak boleh ada dua **Published** version dengan `effective_from` yang sama.

Publish kedua harus ditolak.

Jangan memilih salah satu diam-diam.

Gunakan transaction dan locking/check yang cukup agar dua admin tidak dapat publish bersamaan dan menciptakan conflict.

---

# 7. STATUS VERSION

Gunakan minimal:

```text
draft
pending_approval
published
cancelled
```

Jangan menyimpan `historical/current/upcoming` sebagai status manual.

Hitung dari timeline:

```text
Published + tanggal belum mulai      → UPCOMING
Published + tanggal berada di range  → CURRENT
Published + tanggal sudah lewat      → HISTORICAL
```

UI dapat menampilkan:

```text
V1 | Published | Historical
V2 | Published | Current
V3 | Published | Upcoming
V4 | Draft
```

---

# 8. VERSION LABEL

Selain `version_no`, dukung `label`.

Contoh:

```text
V3 — Penyesuaian RPJMD Tahun 2026
V2 — Perubahan Pertama
```

Minimal metadata version:

```text
id
periode_id / periode
scope
opd_id jika relevan
version_no
label
effective_from
effective_to
status
copied_from_version_id
source_type
source_version_id
alasan_perubahan
dasar_perubahan
created_by
submitted_by
approved_by
created_at
submitted_at
approved_at
```

Sesuaikan naming dengan convention schema existing.

---

# 9. MEMBUAT VERSION BARU

Pada RPJMD, Renstra, dan IKU sediakan:

```text
[Buat Version Baru]
```

Pilihan:

```text
○ Salin dari Version Existing
○ Mulai dari Kosong
```

Khusus IKU:

```text
○ Sinkron dari Dokumen Perencanaan
```

User harus dapat memilih version existing mana yang menjadi sumber copy.

---

# 10. DEEP COPY WAJIB

Copy version adalah **DEEP COPY**, bukan sharing mutable record.

Contoh:

```text
V1:
Sasaran ID 10
Indikator ID 20

COPY →

V2:
Sasaran ID 100
Indikator ID 200
```

Edit V2 tidak boleh mengubah V1.

Clone seluruh hierarchy internal dengan ID baru sesuai schema aktual.

Gunakan transaction. Jika satu child gagal dicopy, rollback seluruh version baru.

---

# 11. LINEAGE

Simpan lineage source copy jika berguna:

```text
copied_from_version_id
copied_from_entity_id
```

atau tabel mapping lineage yang lebih cocok.

Tujuan:

- compare antar version;
- mengetahui entity mana kelanjutan entity lama;
- copy LAKIP revision;
- benchmark copy;
- audit.

Jangan mengandalkan string name matching sebagai lineage utama.

---

# 12. RPJMD VERSIONING

RPJMD menjadi version-aware.

Satu periode:

```text
RPJMD 2025–2029

V1
V2
V3
...
```

Semua query data RPJMD harus mempunyai version context.

Audit semua model/controller/view/PDF/API yang membaca RPJMD.

---

# 13. RENSTRA VERSIONING

Renstra menjadi version-aware.

Scope minimal:

```text
opd_id
periode
version_id
```

Admin OPD hanya dapat membuat/mengubah Draft milik OPD sendiri.

Anti-IDOR wajib di server.

---

# 14. IKU VERSIONING

IKU menjadi version-aware.

Scope:

```text
Kabupaten:
scope = kabupaten

OPD:
scope = opd
opd_id = ...
```

Setiap version dapat dibuat dari:

1. Copy IKU version existing.
2. Manual dari kosong.
3. Sync dokumen perencanaan.

---

# 15. IKU SYNC = ONE-TIME COPY

Sync bukan live synchronization.

Kabupaten:

```text
IKU Kabupaten
← RPJMD Version tertentu
```

OPD:

```text
IKU OPD
← Renstra OPD Version tertentu
```

Simpan lineage:

```text
source_type
source_version_id
```

Setelah sync, IKU version berdiri independen.

Jika RPJMD/Renstra berubah kemudian, IKU tidak ikut berubah.

Jika perlu penyesuaian, buat IKU version baru.

---

# 16. PUBLISHED VERSION READ-ONLY

Version berstatus `published` tidak boleh Edit normal.

UI hanya:

```text
[Lihat]
[Bandingkan]
[Buat Version dari Ini]
[Ajukan Koreksi]
```

Jika perubahan substantif, buat version baru.

---

# 17. WORKFLOW OPD → ADMIN KABUPATEN

Untuk Renstra/IKU/LAKIP milik OPD:

```text
DRAFT
  ↓
[Ajukan untuk Ditetapkan]
  ↓
PENDING_APPROVAL
```

Saat Pending Approval:

- OPD tidak boleh edit;
- OPD dapat melihat status/history.

Admin Kabupaten:

```text
PENDING_APPROVAL
   ├── Kembalikan → DRAFT
   └── Setujui & Tetapkan Berlaku → PUBLISHED
```

Kembalikan wajib catatan.

Simpan submission history.

Admin Kabupaten harus melihat OPD, module, version, effective_from, source/copy, alasan, dasar perubahan, summary diff, dan compare version.

---

# 18. DOKUMEN KABUPATEN

Untuk scope Kabupaten, admin_kab dengan permission sesuai dapat:

```text
DRAFT
→ validation
→ preview
→ confirmation
→ PUBLISHED
```

Super Admin mengikuti RBAC existing.

---

# 19. PUBLISH / TETAPKAN BERLAKU

Jangan ubah status lewat dropdown biasa.

Gunakan aksi eksplisit:

```text
[Tetapkan Berlaku]
```

atau:

```text
[Ajukan untuk Ditetapkan]
```

Sebelum publish/submit validasi:

- hierarchy;
- periode;
- effective_from;
- conflict timeline;
- target mandatory;
- source;
- scope OPD;
- broken FK;
- empty version bila tidak diizinkan.

Tampilkan preview impact timeline.

Untuk retrospektif tampilkan warning bahwa version disisipkan ke histori dan LAKIP existing tidak akan diubah otomatis.

---

# 20. CORRECTION REQUEST UNTUK TYPO/NON-SUBSTANTIF

Published version tidak boleh diedit normal.

Sediakan:

```text
[Ajukan Koreksi]
```

untuk typo/non-substantif.

Boleh:

- typo/ejaan;
- nomor dokumen;
- catatan administratif;
- metadata minor;
- formatting text minor.

Tidak boleh:

- target berubah;
- Sasaran ditambah/dihapus;
- Indikator ditambah/dihapus;
- formula berubah;
- satuan substantif berubah;
- effective_from berubah;
- hierarchy berubah.

Jika substantif, wajib Version baru.

---

# 21. WORKFLOW CORRECTION

```text
Published Version
      ↓
Ajukan Koreksi
      ↓
Pending Correction Approval
      ↓
Admin Kabupaten
   ├── Kembalikan/Tolak
   └── Setujui
          ↓
Apply correction transactionally
          ↓
Audit old/new
```

Correction request minimal menyimpan:

```text
version_id
entity_type
entity_id
field
old_value
requested_value
reason
requested_by
requested_at
reviewed_by
reviewed_at
review_note
status
```

Whitelist field correction di backend.

---

# 22. AUDIT TRAIL

Reuse Activity Log existing bila tersedia.

Minimal lifecycle:

```text
created
edited draft
submitted
returned
resubmitted
published
correction requested
correction approved
correction rejected/returned
```

Catat user, timestamp, module, entity, before, after, effective date, reason, legal basis, source version.

---

# 23. COMPARE VERSION

Tambahkan compare antar version.

Minimal:

```text
+ Ditambah
~ Diubah
- Tidak ada lagi
= Tidak berubah
```

Tidak perlu desain seperti Git; prioritaskan readability.

---

# 24. PERUBAHAN SOURCE LAKIP

Verifikasi existing implementation.

Kemungkinan saat ini:

```text
LAKIP Kabupaten → RPJMD / rpjmd_target
LAKIP OPD       → Renstra / renstra_target
```

Target:

```text
LAKIP Kabupaten:
default    → IKU Kabupaten Version
alternatif → RPJMD Version

LAKIP OPD:
default    → IKU OPD Version
alternatif → Renstra Version
```

IKU adalah default.

---

# 25. SOURCE LAKIP

Kabupaten:

```text
Sumber:
(•) IKU Kabupaten
( ) RPJMD
```

OPD:

```text
Sumber:
(•) IKU OPD
( ) Renstra
```

Jangan izinkan source campuran per indikator.

Satu LAKIP version memiliki satu primary source type + satu source version.

---

# 26. AUTO SELECT SOURCE VERSION LAKIP

Saat memilih:

```text
LAKIP Tahun 2026
```

sistem merekomendasikan version Published yang efektif pada:

```text
31 Desember 2026
```

Gunakan reusable resolver:

```text
getEffectiveVersion(module, scope, opd, period, referenceDate)
```

Resolver:

```text
status = published
effective_from <= referenceDate
effective_to >= referenceDate OR NULL
```

Jika >1 hasil: ERROR, jangan silent select.

---

# 27. DROPDOWN VERSION LAKIP

Walaupun auto-selected, dropdown tetap tersedia.

Jika user memilih version selain recommendation, wajib:

```text
Alasan penggunaan version
Dasar/keterangan
```

Draft version tidak boleh tampil sebagai source resmi.

---

# 28. KASUS RPJMD LAMA / V-1

WAJIB support.

Contoh:

```text
RPJMD Baru
Published
effective_from = 2026
```

Tetapi ada RPJMD lama yang berlaku sebelumnya dan belum pernah dimasukkan.

Flow:

```text
Tambah RPJMD Version Historis
→ effective_from sesuai dokumen lama
→ Draft
→ Submit/Publish
→ timeline recalculated
```

Setelah Published:

```text
LAKIP 2025 source=RPJMD
→ rekomendasi RPJMD historis

LAKIP 2026
→ rekomendasi RPJMD baru
```

Jangan izinkan Draft version lama langsung dipilih LAKIP.

---

# 29. LAKIP SNAPSHOT

Setelah memilih tahun, source type, source version:

tampilkan preview:

```text
Sasaran
Indikator
Satuan
Target tahun LAKIP
```

Kemudian:

```text
[Siapkan LAKIP]
```

Snapshot menyimpan copy nilai penting:

```text
sasaran_snapshot
indikator_snapshot
satuan_snapshot
tahun
target_snapshot
urutan
```

dan lineage:

```text
source_type
source_version_id
source_sasaran_id
source_indikator_id
source_target_id jika relevan
captured_at
captured_by
```

---

# 30. DATA INTI LAKIP READ-ONLY

Setelah snapshot dibuat:

```text
Sasaran
Indikator
Satuan
Tahun
Target
```

tidak boleh diedit langsung.

Jika salah, perbaiki source melalui version/correction.

LAKIP Draft dapat Bandingkan Source, Ganti Source Version, Refresh/Regenerate Snapshot dengan preview + confirmation + audit.

---

# 31. SOURCE LAKIP TIDAK LIVE

Setelah snapshot, LAKIP tidak membaca source live.

Jika source version baru muncul, tampilkan warning dan compare.

Tidak auto-update.

---

# 32. LAKIP VERSIONING

LAKIP satu tahun dapat memiliki:

```text
LAKIP 2026
V1
V2
V3
```

Minimal metadata:

```text
version_no
label
status
tahun
scope
opd_id
source_type
source_version_id
source_override_reason
created_from_lakip_version_id
created_by
submitted_by
approved_by
timestamps
```

Published LAKIP immutable.

---

# 33. REVISI LAKIP

Jika LAKIP V1 Published perlu perubahan:

```text
[Buat Revisi LAKIP]
```

Buat V2 dengan deep copy dari V1.

Source LAKIP V2 boleh dipilih ulang.

---

# 34. COPY DATA LAKIP V1 → V2

User dapat menyalin:

- realisasi/capaian;
- analisis faktor;
- efisiensi program;
- benchmark;
- narasi/metadata LAKIP lain yang relevan.

Default:

```text
✓ realisasi/capaian
✓ analisis
✓ efisiensi
✓ benchmark hanya untuk indikator dengan lineage sama
```

Jangan match hanya berdasarkan nama indikator.

Indikator baru → blank.
Indikator hilang → tetap hanya di version lama.

Tampilkan summary sebelum commit.

---

# 35. WORKFLOW LAKIP OPD

Gunakan:

```text
Draft
→ Ajukan
→ Admin Kabupaten Verifikasi
→ Published
```

LAKIP Kabupaten dapat dipublish admin_kab sesuai permission.

---

# 36. BENCHMARK PER LAKIP

Benchmark melekat pada:

```text
LAKIP Version
→ LAKIP Snapshot Item
```

Bukan benchmark global source indicator.

---

# 37. DATA BENCHMARK

Minimal:

```text
lakip_snapshot_item_id
nilai_provinsi
nilai_nasional
sumber_provinsi
sumber_nasional
tahun_data_provinsi
tahun_data_nasional
catatan
created_by
updated_by
created_at
updated_at
```

Nilai kosong = `NULL`, bukan `0`.

---

# 38. BENCHMARK OPD

Admin OPD boleh create/update benchmark LAKIP OPD sendiri.

Backend wajib memastikan scope OPD.

Role concept:

```text
admin → semua
admin_kab → manage Kabupaten + view seluruh OPD
admin_opd → manage OPD sendiri
admin_kecamatan → scope sendiri sesuai existing
admin_inspektorat → read-only
bupati → read-only
```

Jika admin_kab perlu override OPD, buat permission `manage_all`, terpisah dari `manage_own`.

---

# 39. BENCHMARK DAN LAKIP REVISION

Saat LAKIP V2 dibuat dari V1, benchmark boleh dicopy hanya bila snapshot item mempunyai lineage yang sama.

Indikator baru → benchmark kosong.
Indikator hilang → benchmark tetap di V1.

---

# 40. FINAL/PUBLISHED LAKIP

Published LAKIP:

- source tidak boleh diganti normal;
- snapshot tidak boleh regenerate normal;
- realisasi/analisis/efisiensi/benchmark tidak boleh diedit normal.

Jika substantif → LAKIP version baru.
Jika administratif → correction request bila field termasuk whitelist.

---

# 41. SOURCE-AGNOSTIC LAKIP

Untuk layer baru, hindari terus menambah FK source-specific bila tidak perlu.

Prefer:

```text
source_type
source_version_id
source_entity_id
```

Tetapi jangan menghapus legacy FK existing tanpa kebutuhan.

---

# 42. BACKWARD COMPATIBILITY LAKIP EXISTING

Existing LAKIP kemungkinan masih menggunakan:

```text
rpjmd_target_id
renstra_target_id
```

Jangan DROP.

Jangan rewrite historical agresif.

Existing LAKIP harus tetap dapat dibuka, dicetak, export PDF/Excel, Analisis Faktor, Efisiensi, benchmark existing aman, nilai historis tidak berubah.

---

# 43. MIGRASI DATA EXISTING RPJMD/RENSTRA/IKU

Jadikan data existing sebagai baseline Version 1 Published.

Jangan mengubah isi existing.

Backfill `version_id` secara aman.

Tentukan `effective_from` berdasarkan data/periode aktual, jangan hardcode satu tahun jika periode berbeda.

---

# 44. UNIQUE INDEX EXISTING

Audit UNIQUE constraint agar deep copy tidak gagal.

Jika perlu ubah menjadi version-aware, dokumentasikan dan buat SQL aman.

---

# 45. QUERY HARUS VERSION-SCOPED

Semua query aktif harus mempertimbangkan `version_id` atau resolver.

Audit Controller, Model, Service, API, PDF, Excel, Dashboard, public page.

Pastikan V1/V2 tidak tercampur.

---

# 46. DEPENDENCY MODUL LAIN

Cari dependency ke:

- RKT/Renja
- PK
- Cascading
- Renaksi
- MONEV
- Dashboard

Jangan memindahkan record historical ke version terbaru hanya karena published sekarang.

Untuk data baru, gunakan context tahun/version sesuai business rule existing.

---

# 47. SIDEBAR

Sesuaikan secara rapi:

```text
PERENCANAAN
├── RPJMD
├── Renstra
└── IKU

PELAPORAN
└── LAKIP

VERIFIKASI
├── Pengajuan Version
└── Pengajuan Koreksi
```

Menu Verifikasi hanya untuk permission relevan.

Jangan menampilkan setiap version sebagai sidebar item.

---

# 48. LANDING PAGE VERSION

Tampilkan:

```text
Dokumen
Periode
Current Version
Tanggal Berlaku
Status
```

Action:

```text
[Lihat Current]
[Riwayat Version]
[Buat Version Baru]
```

Riwayat:

```text
Version | Label | Berlaku | Status | Dibuat | Source | Aksi
```

Badge:

```text
CURRENT
HISTORICAL
UPCOMING
DRAFT
MENUNGGU VERIFIKASI
```

---

# 49. VERSION TIMELINE UI

Jika sesuai style existing, tampilkan timeline sederhana agar version retrospektif mudah dipahami.

Jangan berlebihan.

---

# 50. STYLE UI

Style diserahkan kepada agent, tetapi harus:

- konsisten dengan project existing;
- clean;
- enterprise/government;
- modern;
- natural;
- responsive;
- tidak terasa AI-generated;
- mudah digunakan.

Reuse komponen/theme existing.

---

# 51. DATABASE DESIGN

Audit schema aktual sebelum final schema.

Kemungkinan dibutuhkan:

```text
rpjmd_versions
renstra_versions
iku_versions
lakip_versions
version_submission_history
version_correction_requests
lakip_snapshots
lakip_snapshot_items
```

Benchmark existing harus diperiksa dan diadaptasi bila memungkinkan.

Jangan membuat tabel duplikat dengan fungsi sama.

---

# 52. TRANSACTION

Wajib transaction untuk:

- deep copy;
- sync IKU;
- submit/publish;
- timeline retrospektif;
- correction approval;
- LAKIP snapshot;
- LAKIP revision;
- benchmark copy.

Jika gagal, rollback seluruh operasi.

---

# 53. CONCURRENCY

Cegah race condition publish.

Revalidate conflict tepat sebelum commit.

---

# 54. PERMISSION

Audit permission existing.

Gunakan permission granular mengikuti convention project.

Konsep:

```text
rpjmd.version.view/create/update_draft/submit/verify/publish
renstra.version.*
iku.version.*
lakip.version.*
version_correction.request
version_correction.verify
lakip_benchmark.view
lakip_benchmark.manage_own
lakip_benchmark.manage_all
```

Jangan hardcode role jika permission framework existing tersedia.

---

# 55. SECURITY

WAJIB:

- authorization server-side;
- CSRF existing;
- OPD scope;
- anti-IDOR;
- validate version ownership;
- validate source version status/scope;
- correction whitelist;
- source/source-version pairing.

---

# 56. DATABASE / SQL PRODUCTION WAJIB

Jika ada perubahan schema/data, buat migration CI4 bila sesuai.

Selain migration, **WAJIB buat SQL manual production** yang bisa dijalankan langsung di server.

SQL mencakup:

- CREATE TABLE;
- ALTER TABLE;
- ADD COLUMN;
- INDEX/UNIQUE;
- FK;
- permission;
- seed;
- baseline version;
- backfill version_id;
- benchmark migration;
- data migration.

Jangan hanya mengatakan `php spark migrate`.

---

# 57. SQL SAFETY

SQL harus:

- MySQL/MariaDB compatible;
- tidak menebak tipe FK;
- tidak DROP data existing;
- tidak TRUNCATE;
- tidak DELETE historical;
- backup-friendly;
- idempotent semaksimal mungkin;
- diberi komentar per section.

---

# 58. PREFLIGHT SQL

Berikan query preflight untuk:

- DB aktif;
- versi MySQL/MariaDB;
- dependency tables;
- FK types;
- duplicate data;
- existing version tables;
- existing benchmark;
- existing permissions;
- unique conflicts;
- orphan FK;
- jumlah record existing.

---

# 59. POST-DEPLOY SQL CHECK

Berikan query validasi:

- baseline version berhasil;
- semua record wajib punya version;
- FK valid;
- duplicate effective_from Published tidak ada;
- timeline valid;
- permission benar;
- LAKIP existing tidak hilang;
- benchmark aman;
- total record inti masuk akal.

---

# 60. ACCEPTANCE TEST — TIMELINE RETROSPEKTIF

Test V1 2025-01-01, V2 2028-01-01, lalu V3 dibuat belakangan tetapi effective 2026-07-01.

Expected:

```text
V1: 2025-01-01 – 2026-06-30
V3: 2026-07-01 – 2027-12-31
V2: 2028-01-01 – NULL
```

---

# 61. ACCEPTANCE TEST — MULTIPLE VERSION SATU TAHUN

```text
V1 2026-01-01
V2 2026-03-15
V3 2026-09-01
```

Resolver:

```text
2026-02-01 → V1
2026-05-01 → V2
2026-12-31 → V3
```

---

# 62. ACCEPTANCE TEST — SAME DATE CONFLICT

Dua Published dengan effective_from sama.

Publish kedua harus gagal.

---

# 63. ACCEPTANCE TEST — DEEP COPY

Pastikan entity ID baru, hierarchy benar, source version tidak berubah, lineage tersimpan.

---

# 64. ACCEPTANCE TEST — IKU SYNC

Renstra V2 → one-time sync → IKU V3.

Setelah sync, perubahan Renstra tidak mengubah IKU V3.

Test juga Kabupaten dari RPJMD.

---

# 65. ACCEPTANCE TEST — APPROVAL

OPD Draft → Submit → AdminKab Return → OPD edit → Submit ulang → AdminKab Approve → Published read-only.

---

# 66. ACCEPTANCE TEST — CORRECTION

Typo boleh melalui correction + approval + audit.

Target 80 → 85 melalui correction harus ditolak dan diarahkan ke Version baru.

---

# 67. ACCEPTANCE TEST — RPJMD HISTORIS

RPJMD Baru Published mulai 2026.

Tambah RPJMD Lama mulai 2021.

Setelah historical version Published:

```text
LAKIP 2025 source=RPJMD → rekomendasi RPJMD Lama
LAKIP 2026 source=RPJMD → rekomendasi RPJMD Baru
```

---

# 68. ACCEPTANCE TEST — LAKIP DEFAULT IKU

Kabupaten → default IKU Kabupaten.
OPD → default IKU OPD.

Recommendation version berdasarkan 31 Desember tahun laporan.

---

# 69. ACCEPTANCE TEST — ALTERNATIVE SOURCE LAKIP

Kabupaten dapat memilih RPJMD.
OPD dapat memilih Renstra.

Jika version bukan recommendation, alasan wajib.

---

# 70. ACCEPTANCE TEST — SNAPSHOT LAKIP

IKU V2 target 82 → LAKIP snapshot 82.

IKU V3 kemudian target 85.

LAKIP tetap 82 sampai user melakukan action resmi.

---

# 71. ACCEPTANCE TEST — READ ONLY CORE LAKIP

Tidak ada edit langsung Sasaran/Indikator/Satuan/Tahun/Target.

---

# 72. ACCEPTANCE TEST — LAKIP REVISION

LAKIP V1 Published → buat V2 → deep copy valid data → source version dapat berubah.

Lineage sama dapat copy data; indikator baru blank; indikator hilang tetap di V1.

---

# 73. ACCEPTANCE TEST — BENCHMARK SCOPE

Admin OPD A boleh edit benchmark OPD A, tidak boleh OPD B.

Benchmark melekat ke LAKIP version + snapshot item.

---

# 74. REGRESSION TEST

WAJIB test:

- Login
- RBAC
- RPJMD
- Renstra
- IKU
- RKT/Renja
- PK
- Cascading
- Renaksi
- MONEV
- LAKIP
- Benchmark
- Analisis Faktor
- Efisiensi
- Dashboard
- PDF
- Excel
- API
- Public pages

---

# 75. PERFORMANCE

Tambahkan index sesuai query nyata:

```text
version_id
opd_id
periode
effective_from
effective_to
status
tahun
source_version_id
snapshot_id
```

Hindari N+1.

---

# 76. SERVICE ARCHITECTURE

Business logic jangan menumpuk di Controller.

Gunakan Service reusable, misalnya:

```text
VersionResolver
VersionTimelineService
VersionDeepCopyService
VersionApprovalService
VersionCorrectionService
IkuSyncService
LakipSnapshotService
LakipRevisionService
```

Nama mengikuti convention project.

---

# 77. SIDEBAR & UX FINAL

Sesuaikan sidebar dan halaman setelah fitur selesai.

Gunakan badge pending count jika cocok.

Jangan redesign keseluruhan theme.

---

# 78. DOKUMENTASI AI AGENT

Setelah implementasi, update `AI_AGENT_GUIDE_AKSARA.md`.

Dokumentasikan Version Model, Timeline, retrospective/future version, status, deep copy, lineage, approval, correction, resolver, IKU sync, LAKIP source, snapshot, revision, benchmark, MVC/routes/permissions/database/SQL deployment/backward compatibility.

---

# 79. OUTPUT AKHIR AGENT

Setelah selesai jangan hanya menulis `done`.

Berikan:

1. Existing Architecture
2. Final Architecture
3. File Baru
4. File Diubah
5. Database Changes
6. Routes
7. Permissions
8. RPJMD Workflow
9. Renstra Workflow
10. IKU Workflow
11. LAKIP Workflow
12. Approval Workflow
13. Correction Workflow
14. Benchmark Workflow
15. Backward Compatibility
16. Regression Test PASS/FAIL
17. SQL Production lengkap + urutan eksekusi + preflight + post-deploy
18. Risks / TODO

---

# 80. PRIORITAS KEPUTUSAN

1. Jangan kehilangan data.
2. Jangan mengubah historical meaning.
3. Jangan merusak FK existing.
4. Backward compatibility.
5. Published version immutable.
6. Timeline deterministic.
7. Approval aman.
8. OPD scope aman.
9. LAKIP snapshot tidak berubah otomatis karena source berubah.
10. Hindari double entry.
11. Minimal complexity.
12. Konsistensi UI.

---

# 81. DILARANG

Jangan:

- truncate data existing;
- delete historical;
- overwrite published version;
- share mutable child rows antar version;
- sync IKU live;
- auto-update LAKIP lama;
- memakai Draft version sebagai source resmi LAKIP;
- membuat LAKIP multi-source per indikator;
- edit target/sasaran/indikator/satuan LAKIP langsung;
- memakai correction untuk perubahan substantif;
- hardcode authorization hanya di frontend;
- silent resolve version conflict;
- membuat sidebar per version;
- merombak theme tanpa kebutuhan;
- menjalankan destructive migration tanpa validasi;
- menghapus legacy FK hanya supaya schema terlihat bersih.

---

# 82. QUALITY BAR

Implementasi harus **production-ready**, bukan prototype.

Pastikan:

- transaction;
- validation;
- authorization;
- audit;
- proper FK;
- proper index;
- no partial writes;
- predictable error handling;
- maintainable Service/Model;
- responsive UI;
- backward compatibility;
- regression test memadai.

Jika menemukan conflict nyata pada schema/source yang membuat requirement tidak mungkin diterapkan, jangan menebak.

Dokumentasikan conflict, pilih fallback paling aman, dan jelaskan keputusan implementasinya.

Untuk pilihan teknis kecil, pilih pendekatan paling aman, maintainable, minimal, dan konsisten dengan project existing.
