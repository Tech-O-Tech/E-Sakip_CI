# Master Plan — Versioning RPJMD, Renstra, IKU & LAKIP

**Tanggal:** 2026-08-20
**Basis pembacaan:** branch `main` @ `0f80cf3` (working tree bersih) + skema hidup `e-sakip_6`
**Acuan:** `MASTER_PROMPT_VERSIONING_ESAKIP.md` (82 bagian) — rujukan `§n` di bawah menunjuk ke sana.
**Status dokumen:** skema, service, dan **UI versi RPJMD/Renstra + halaman Verifikasi**
sudah terpasang dan teruji. Yang belum: IKU sync ber-versi, correction request,
benchmark per item, dan UI LAKIP. Rinciannya di bagian 6.

Dokumen ini hasil tahap **baca & petakan** yang diminta sebelum implementasi.
Urutan sumber kebenaran yang dipakai: Routes aktif → Controller/Service/Model aktif →
skema DB hidup → RBAC → View → dokumentasi.

---

## 0. Ringkasan eksekutif

Sepertiga dari pekerjaan ini **sudah ada dan sudah ditulis**: IKU sudah punya versi dokumen
(`iku_revisi`) dan LAKIP sudah punya snapshot tahunan (`lakip_snapshot`), lengkap dengan
penyesuaian kebijakan, uji penerimaan (`php spark revisi:verify`), dan dokumen desain
`REVISI_IKU_SNAPSHOT_LAKIP.md`. Rancangan di bawah **memperluas** pola itu, bukan menggantinya.

Yang belum ada dan menjadi inti pekerjaan baru:

| Kebutuhan master task | Status hari ini |
|---|---|
| RPJMD version-aware | **belum ada sama sekali** |
| Renstra version-aware | **belum ada sama sekali** |
| IKU multi-version dalam satu periode | ada, **tapi resolusinya per-TAHUN** — dua perubahan dalam satu tahun tidak terwakili |
| LAKIP multi-revision dalam satu tahun | ada (`lakip_snapshot.versi`), sudah memadai |
| `effective_from` / `effective_to` sebagai DATE | **belum** — `iku_revisi` hanya punya `berlaku_mulai_tahun` INT |
| `version_no` terpisah dari waktu berlaku | sebagian (`iku_revisi.nomor`) |
| Version baru dari copy version existing | ada untuk IKU (`buatDraft` menyalin live), **belum bisa menyalin dari versi lama tertentu** |
| Version baru dari kosong | **belum** |
| Approval Admin Kabupaten | **belum** — pemilik dokumen mengesahkan revisinya sendiri |
| IKU sync one-time dari RPJMD/Renstra version tertentu | sync ada (`IkuModel::importSync`), **tapi menarik dari tabel live, bukan dari versi** |
| LAKIP memilih source + version | **belum** — source dipaksa oleh `mode` (kabupaten→RPJMD, opd→Renstra) |
| Koreksi administratif pada version published | ada untuk LAKIP (`lakip_penyesuaian`), **belum** untuk RPJMD/Renstra/IKU |
| Audit trail lengkap | sebagian (`activity_logs` generik) — **belum** ada jejak per-versi |

---

## 1. Dependency map

### 1.1 Rute aktif per modul

| Modul | Prefix rute | Controller | Filter |
|---|---|---|---|
| RPJMD | `adminkab/rpjmd/*` | `RpjmdController` | `auth:admin_kab,admin,admin_inspektorat` + `modperm→rpjmd` |
| Renstra | `adminopd/renstra/*` | `AdminOpd\RenstraController` | `auth:admin_opd,admin,admin_kecamatan` + `modperm→renstra` |
| IKU Kab | `adminkab/iku/*` (termasuk `iku/revisi/*`, `iku/sync`) | `AdminKab\IkuController` + `IkuRevisiTrait` + `IkuFormTrait` | `modperm→iku_kab` |
| IKU OPD | `adminopd/iku/*` (idem) | `AdminOpd\IkuController` + trait yang sama | `modperm→iku_opd` |
| LAKIP Kab | `adminkab/lakip/*` (termasuk `snapshot/*`, `penyesuaian/*`, `benchmark/*`) | `AdminKab\LakipController` + `LakipSnapshotTrait` + `LakipAddendumTrait` + `LakipBenchmarkTrait` | `modperm→lakip_kab` |
| LAKIP OPD | `adminopd/lakip/*` (idem) | `AdminOpd\LakipOpdController` + trait yang sama | `modperm→lakip_opd` |
| LAKIP Bupati | `bupati/lakip*` | `AdminKab\LakipController` (read-only) | `auth:bupati,admin` + `ReadOnlyRoleFilter` |
| Publik | `/rpjmd`, `/renstra`, `/iku_opd`, `/lakip_opd`, `/lakip_kabupaten`, `/rkt` | `UserController` → `UserPublicModel` | **tanpa auth** |
| API | `api/perangkat-daerah/(:num)/iku`, `api/iku`, `api/cascading`, `api/pohon-kinerja` | `Api\PerangkatDaerahController` | `api-token` |

> `ModulePermissionFilter::actionsFor()` memetakan aksi dari **substring path**. Path
> `iku/revisi/sahkan/5` tidak mengandung `save|update|edit|delete|tambah|status`, sehingga
> filter itu hanya menuntut `iku_kab.view`. Penjagaan sebenarnya ada di
> `IkuRevisiTrait::bolehSahkan()` (`*.revisi_sahkan`). Pola yang sama **wajib** diikuti
> rute versi yang baru: jangan mengandalkan `modperm` sebagai satu-satunya penjaga.

### 1.2 Graf ketergantungan basis data (dibaca dari `information_schema`, bukan dokumentasi)

```
rpjmd_visi
  └─ rpjmd_misi (tahun_mulai, tahun_akhir)   ← PERIODE RPJMD DISIMPAN DI SINI
       └─ rpjmd_tujuan
            ├─ rpjmd_indikator_tujuan → rpjmd_target_tujuan
            └─ rpjmd_sasaran
                 ├─ rpjmd_indikator_sasaran ──┬─ rpjmd_target ──┬─ lakip.rpjmd_target_id           [SET NULL]
                 │                            │                 ├─ lakip_analisis_faktor           [CASCADE]
                 │                            │                 └─ target_rencana.rpjmd_target_id  [SET NULL]
                 │                            ├─ rpjmd_cascading                                   [CASCADE]
                 │                            └─ lakip_benchmark.rpjmd_indikator_id                [CASCADE]
                 └─ renstra_tujuan.rpjmd_sasaran_id                                                [CASCADE]  <-- BAHAYA
                      ├─ renstra_indikator_tujuan → renstra_target_tujuan
                      └─ renstra_sasaran (opd_id, tahun_mulai, tahun_akhir)  ← PERIODE RENSTRA
                           └─ renstra_indikator_sasaran ──┬─ renstra_target ──┬─ lakip.renstra_target_id           [SET NULL]
                                                          │                   ├─ lakip_analisis_faktor             [CASCADE]
                                                          │                   └─ target_rencana.renstra_target_id  [CASCADE] <-- BAHAYA
                                                          ├─ cascading_sasaran_opd                                 [CASCADE]
                                                          └─ lakip_benchmark.renstra_indikator_id                  [CASCADE]

iku_sasaran (opd_id NULL=kab, tahun_mulai, tahun_akhir)
  └─ iku_indikator ─┬─ iku_target
                    └─ iku_program
(legacy, masih 268 baris di e-sakip_6): iku → iku_program_pendukung
```

### 1.3 Konsumen hilir yang harus tetap hidup

| Konsumen | Membaca | Konsekuensi bila versi salah tangan |
|---|---|---|
| Cascading OPD | `cascading_sasaran_opd.renstra_indikator_sasaran_id` | pohon kinerja putus |
| Renaksi / Target Rencana | `target_rencana.renstra_target_id` (CASCADE) | rencana aksi & MONEV triwulanan **terhapus** |
| MONEV | `monev` → `target_rencana` | realisasi triwulan hilang |
| RKT / Renja | `rkt.indikator_id` → indikator Renstra | RKT yatim |
| PK | `pk_sasaran`, `pk_indikator` (tanpa FK ke Renstra) | tidak terdampak langsung |
| LAKIP | `lakip.renstra_target_id` / `rpjmd_target_id` (SET NULL) | baris jadi yatim, hilang senyap dari laporan |
| Benchmark | `lakip_benchmark.*_indikator_id` (CASCADE) | pembanding provinsi/nasional terhapus |
| Analisis faktor | `lakip_analisis_faktor.*_target_id` (CASCADE) | narasi LAKIP terhapus |
| Dashboard | `KabupatenDashboardService`, `OpdDashboardService` | membaca `renstra_*`/`rpjmd_*`/`lakip.status`; **tidak menyentuh `iku_*`** |
| API publik | `Api\PerangkatDaerahController` → `iku_sasaran/indikator/target/program` | endpoint eksternal |
| Halaman publik | `UserPublicModel` | portal warga |
| PDF/Excel | `lakip_excel_helper.php`, `lakip_helper.php`, `cascading_excel_helper.php`, view `*_cetak.php` | keluaran cetak |

---

## 2. Temuan kritis (semua diverifikasi langsung ke source & DB, bukan dari dokumentasi)

### T1 — Migrasi versi IKU/LAKIP BELUM terpasang di database aktif

`.env` menunjuk `e-sakip_6`. Di sana **tidak ada** `iku_revisi*`, `lakip_snapshot*`,
`lakip_penyesuaian`, dan **tidak ada** kolom `revisi_id`/`dihentikan_pada`/`jenis_perubahan`
pada `iku_indikator`. Tabel `migrations` berhenti di `2026-07-28-000001`, padahal tabel dari
`2026-07-27-*` dan `2026-08-12-*` sudah ada — artinya skema selama ini dipasang **manual lewat
`db/*.sql`**, bukan `spark migrate`. Fitur Revisi IKU & Snapshot LAKIP yang sudah ditulis
**belum aktif** di lingkungan ini (semua modelnya menjaga diri lewat `siap()`).

**Konsekuensi:** `db/update_2026-08-18_iku_revisi_lakip_snapshot.sql` **wajib dijalankan lebih
dulu** sebelum SQL versi yang baru — DDL baru menunjuk ke `iku_revisi` dan `lakip_snapshot`.

### T2 — Data IKU standalone kosong di database aktif

`iku_sasaran` / `iku_indikator` / `iku_target` / `iku_program` = **0 baris**, sedangkan tabel
legacy `iku` masih berisi **268 baris** dan `iku_program_pendukung` 408 baris. Migrasi data
(`db/migrasi_iku_standalone.php`) belum dijalankan di `e-sakip_6`. Versioning IKU di lingkungan
ini akan membekukan kondisi kosong sampai migrasi data itu dijalankan.

### T3 — RPJMD tidak punya "dokumen"; periodenya menempel di `rpjmd_misi`

Tidak ada tabel kepala RPJMD. Sebuah "RPJMD periode 2025–2029" hanyalah *kumpulan baris
`rpjmd_misi` yang kebetulan punya `tahun_mulai`/`tahun_akhir` sama* — `RpjmdController::index`
mengelompokkannya di PHP. `rpjmd_visi` bahkan tidak punya periode sama sekali.
→ Identitas versi RPJMD harus dibuat dari luar.

### T4 — Renstra juga tidak punya "dokumen", dan `renstra_tujuan` tidak punya pemilik

Periode & OPD ada di `renstra_sasaran`. Tetapi `renstra_tujuan` **tidak punya `opd_id` maupun
periode** — ia menggantung pada `rpjmd_sasaran`. Kepemilikan tujuan hanya bisa disimpulkan
lewat `renstra_sasaran` yang menunjuknya. Fakta di `e-sakip_6`: 57 tujuan dimiliki tepat 1 OPD,
dan **55 tujuan sudah yatim** (tidak ada sasaran yang menunjuknya).
→ Arsip versi Renstra **wajib menyimpan tujuan di dalam lingkup versi**, karena tabel live tidak
mampu menyatakan kepemilikan.

### T5 — Menghapus satu `rpjmd_sasaran` menghapus Renstra sampai 38 OPD

`renstra_tujuan.rpjmd_sasaran_id` ber-`ON DELETE CASCADE`. Rantainya:
`rpjmd_sasaran → renstra_tujuan → renstra_sasaran → renstra_indikator_sasaran → renstra_target
→ target_rencana (Renaksi) + lakip_analisis_faktor`, dan `lakip` ikut di-`SET NULL`.
Hitungan nyata di `e-sakip_6`: **38 OPD** terhubung ke RPJMD lewat rantai ini.

→ **Invariant paling keras dalam pekerjaan ini:** menerbitkan versi RPJMD baru **tidak boleh
pernah** menghapus baris live. Penerapan versi = *upsert + pensiun*, persis pola
`IkuRevisiModel::pensiunkanYangHilang()`.

### T6 — `lakip` tidak punya `tahun`, `opd_id`, maupun `mode`

Identitas baris LAKIP sepenuhnya melekat pada `renstra_target_id` / `rpjmd_target_id`. Karena
FK-nya `SET NULL`, baris yang sumbernya terhapus menjadi yatim dan **lenyap senyap** dari semua
laporan (setiap query menyaring lewat `rt.tahun`/`rpj.tahun`). Fakta di `e-sakip_6`:
**111 dari 214 baris `lakip` sudah yatim** — persis pola yang dilaporkan
`REVISI_IKU_SNAPSHOT_LAKIP.md` untuk basis lain.
→ "LAKIP memilih source + version" tidak bisa diwujudkan penuh tanpa menambah kolom lingkup pada `lakip`.

### T7 — Resolusi versi IKU hanya berbutir TAHUN

`iku_revisi.berlaku_mulai_tahun` / `berlaku_sampai_tahun` bertipe INT, dan
`IkuRevisiModel::resolveEfektif()` mencari per tahun. UNIQUE-nya
`(opd_key, tahun_mulai, tahun_akhir, berlaku_mulai_tahun, berlaku_key)`.
→ Kebutuhan "**lebih dari satu perubahan dalam satu tahun**" **tidak terpenuhi**: revisi kedua
di tahun yang sama ditolak `ERROR 1062`, atau kalau lolos akan dilaporkan sebagai konflik
administratif. Ini alasan utama `effective_from`/`effective_to` harus DATE.

### T8 — Belum ada approval; pemilik mengesahkan dokumennya sendiri

`IkuRevisiTrait::revisiSahkan()` hanya menuntut `<prefix>.revisi_sahkan`, dan permission itu
diberikan ke `admin_opd` + `admin_kecamatan` untuk lingkup OPD. Tidak ada status "diajukan",
tidak ada penolakan, tidak ada jejak siapa yang menyetujui dari Kabupaten.

### T9 — `iku/sync` menarik dari tabel LIVE, bukan dari versi

`IkuModel::getKandidatSync()` / `importSync()` membaca `rpjmd_sasaran`/`renstra_sasaran` hidup.
Sinkron hari ini menghasilkan isi berbeda dari sinkron kemarin bila sumbernya berubah, dan
tidak ada catatan "disalin dari versi mana".

### T10 — `Config\Database::$strictOn = false`

CodeIgniter membuang `STRICT_TRANS_TABLES` dari sesi koneksinya, sehingga data yang melebihi
panjang kolom **dipotong senyap oleh aplikasi** walau klien `mysql` menolaknya. Sudah dilaporkan
di dokumen sebelumnya; relevan karena arsip versi menyimpan teks panjang.

---

## 3. Rancangan

### 3.1 Keputusan pokok: satu registri versi, arsip tetap per-dokumen

Membangun empat mekanisme sejenis (`rpjmd_revisi`, `renstra_revisi`, …) berarti empat kali
kode dan empat kali penyimpangan. Sebaliknya, membongkar `iku_revisi` yang sudah jalan
melanggar prinsip backward-compatible.

Jalan tengahnya:

* **`dokumen_versi`** — satu tabel kepala untuk keempat jenis dokumen. Di sinilah `version_no`,
  `effective_from`/`effective_to`, status, approval, asal salinan, dan sumber sinkron hidup.
* **Arsip isi tetap per-dokumen**, karena bentuknya memang berbeda jauh:
  * RPJMD → `rpjmd_versi_*` (baru)
  * Renstra → `renstra_versi_*` (baru)
  * IKU → **`iku_revisi_*` yang sudah ada, tidak diubah**
  * LAKIP → **`lakip_snapshot_*` yang sudah ada, tidak diubah**
* `dokumen_versi.ref_id` menunjuk kepala arsip lama (`iku_revisi.id` / `lakip_snapshot.id`),
  sehingga `IkuRevisiModel` dan `LakipSnapshotModel` yang sudah ditulis **tetap bekerja apa adanya**.

### 3.2 Tabel live tetap berarti "versi yang sedang berlaku"

Diteruskan dari keputusan 3.1 dokumen sebelumnya, dan sekarang berlaku juga untuk RPJMD & Renstra.
Alasannya sama dan sekarang jauh lebih kuat: `renstra_*` dan `rpjmd_*` dibaca oleh Cascading,
RKT, Renaksi, MONEV, Benchmark, Dashboard, API, halaman publik, PDF, dan Excel. Membiarkannya
apa adanya adalah satu-satunya cara perubahan ini tidak menyentuh 50+ berkas.

```
buat versi   → salin kondisi live / versi lain / kosong  →  ARSIP (tabel *_versi_*)
               tabel live TIDAK tersentuh
ajukan       → status 'pending_approval', terkunci dari sunting
setujui      → arsip DITERAPKAN ke tabel live (upsert + pensiun, TIDAK PERNAH delete)
               versi terbuka sebelumnya ditutup: effective_to := effective_from versi baru
LAKIP thn Y  → membaca ARSIP versi yang efektif pada tanggal rujukan tahun Y
```

### 3.3 `version_no` vs `effective_from` — dipisah tegas

| Kolom | Arti | Catatan |
|---|---|---|
| `created_at` | kapan barisnya dibuat | tidak pernah dipakai untuk resolusi |
| `version_no` | nomor urut administratif dalam satu (jenis, pemilik, periode) | `0` = Kondisi Awal; **tidak menentukan waktu berlaku** |
| `effective_from` | DATE, tanggal mulai berlaku | **satu-satunya penentu timeline** |
| `effective_to` | DATE, eksklusif; NULL = masih terbuka | diisi otomatis saat versi berikutnya berlaku |

Interval **setengah terbuka `[effective_from, effective_to)`** supaya tidak ada tanggal yang
dimiliki dua versi sekaligus.

Karena `version_no` lepas dari waktu, keempat kasus yang diminta terlayani:

* **versi historis/mundur** → `effective_from` di masa lalu, `version_no` boleh berapa saja
* **versi berlaku sekarang** → interval memuat hari ini
* **versi masa depan** → `effective_from` > hari ini; sudah `disetujui`, belum efektif
* **>1 perubahan dalam satu tahun** → V1 `2026-01-01 → 2026-07-01`, V2 `2026-07-01 → NULL`

### 3.4 Jaminan level engine (tanpa trigger)

Tiga UNIQUE index memakai *generated column* — pola yang sudah terbukti di `iku_revisi`:

```
opd_key       = COALESCE(opd_id, 0)
scope_key     = COALESCE(scope, 'kabupaten')
published_key = CASE WHEN status='published' THEN 1 ELSE NULL END
terbuka_key   = CASE WHEN status='published' AND effective_to IS NULL THEN 1 ELSE NULL END

UNIQUE (modul, scope_key, opd_key, periode_mulai, periode_akhir, version_no)
UNIQUE (modul, scope_key, opd_key, periode_mulai, periode_akhir, effective_from, published_key)  -- §6, §62
UNIQUE (modul, scope_key, opd_key, periode_mulai, periode_akhir, terbuka_key)                    -- satu versi terbuka
```

UNIQUE ketiga adalah kuncinya: MySQL mengabaikan `NULL` pada UNIQUE, jadi index ini mengikat
tepat satu baris `published` yang `effective_to`-nya masih NULL per lingkup. Digabung dengan
aturan penutupan (`effective_to` versi lama := `effective_from` versi baru), **tumpang tindih
interval menjadi mustahil secara konstruksi** — tanpa perlu exclusion constraint yang memang
tidak dimiliki MySQL.

**Aturan urutan yang wajib dipatuhi: lepas dulu, baru klaim.** MySQL memeriksa UNIQUE
*per-statement*, bukan ditunda sampai COMMIT. Maka:

```
BENAR : tutup versi terbuka lama  →  buka versi baru
SALAH : buka versi baru           →  tutup versi lama   (ERROR 1062)
```

Penyisipan retrospektif (§4, §60) tidak menyentuh slot terbuka sama sekali: versi yang
disisipkan di tengah histori lahir sudah ber-`effective_to`, dan versi terakhir tetap yang
terbuka. Itulah sebabnya §60 bisa dilayani tanpa mengendurkan satu pun invariant.
Aturan ini diterapkan `VersionTimelineService::hitungUlang()` dan `terbitkan()`.

### 3.5 Resolusi versi untuk tanggal D

```sql
SELECT * FROM dokumen_versi
WHERE modul = ? AND scope_key = ? AND opd_key = ?
  AND periode_mulai <= :tahun AND periode_akhir >= :tahun
  AND status = 'published'
  AND effective_from <= :D
  AND (effective_to IS NULL OR effective_to > :D)
```

Status `draft`, `pending_approval`, `cancelled` **tidak pernah** lolos (§2.11).
Bila hasilnya >1 baris, resolver melempar `VersionConflictException` berisi daftar kandidat —
**tidak memilih diam-diam** (§26, §81).

**Tanggal rujukan LAKIP tahun Y = `Y-12-31`** secara default, boleh ditimpa operator.

> **Penyimpangan sadar dari tulisan §26.** §26 menulis `effective_to >= referenceDate`.
> Dipakai `>` (interval setengah terbuka). Alasannya bukan selera: pada contoh §61 sendiri,
> tanggal 2026-03-15 adalah akhir V1 sekaligus awal V2. Dengan `>=` tanggal itu cocok dengan
> **dua** versi dan resolver melaporkan konflik padahal datanya benar. Dengan `>` hasilnya
> tepat V2 — persis yang §61 harapkan. Sudah diuji (`batas 2091-03-15 -> V2`).

### 3.6 Status & approval Admin Kabupaten (§7, §17)

```
                    ┌─────────┐
        buat ──────►│  draft  │◄──── kembalikan (catatan WAJIB)
                    └────┬────┘
                 ajukan  │  (pemilik dokumen)
                         ▼
                   ┌──────────────────┐
                   │ pending_approval │  pemilik TIDAK boleh menyunting
                   └────────┬─────────┘
        ┌───────────────────┴───────────────────┐
 setujui│ (Admin Kabupaten)                     │ kembalikan
        ▼                                       ▼
  ┌────────────┐                          kembali ke draft
  │ published  │  immutable (§16)
  └────────────┘

  draft / pending  ──batalkan──►  cancelled   (barisnya TETAP ada, §2.4)
```

Empat status saja, sesuai §7: `draft | pending_approval | published | cancelled`.

**`historical` / `current` / `upcoming` TIDAK disimpan** — ketiganya dihitung dari timeline
oleh `VersionResolver::badge()`. Kalau disimpan, ia harus diperbarui setiap hari oleh sesuatu,
dan sehari terlewat berarti seluruh halaman berbohong. Konsekuensinya juga menyenangkan: tidak
ada cron yang perlu membalik status ketika tanggal versi masa depan tiba.

**Keputusan wewenang:** persetujuan versi Renstra & IKU OPD menjadi wewenang **Admin Kabupaten
saja**. Pemberian `iku_opd.revisi_sahkan` ke role OPD **dicabut** oleh SQL bagian 8.2 agar tidak
tersisa jalur pintas lewat menu "Revisi IKU" yang lama. Permission-nya sendiri tidak dihapus,
sehingga keputusan ini bisa dikembalikan bila perlu.

Dokumen tingkat Kabupaten (§18) boleh `draft → published` langsung tanpa antre verifikasi,
lewat `VersionApprovalService::setujui($id, $user, langsungDariDraft: true)`.

### 3.7 LAKIP memilih source + version (§24–§27)

**Perubahan perilaku:** default sumber LAKIP menjadi **IKU**.

| Lingkup | Default | Alternatif |
|---|---|---|
| Kabupaten | IKU Kabupaten | RPJMD |
| OPD | IKU OPD | Renstra |

Sebelumnya sumber dipaksa oleh `mode` (kabupaten→RPJMD, opd→Renstra) tanpa pilihan.

Kolom baru pada `lakip_snapshot`:

| Kolom | Guna |
|---|---|
| `version_id` | baris `dokumen_versi` milik LAKIP version ini |
| `source_type` | `iku` \| `rpjmd` \| `renstra` |
| `source_version_id` | `dokumen_versi.id` dari source |
| `source_reference_date` | tanggal resolver (default `Y-12-31`) |
| `source_override_reason` | **WAJIB** bila versi yang dipilih bukan rekomendasi (§27) |
| `created_from_lakip_version_id` | revisi LAKIP: deep copy dari versi ini (§33) |

`sumber_iku_revisi_id` yang sudah ada **dipertahankan** demi kompatibilitas (§42).
Satu LAKIP version = **satu** source type + **satu** source version; sumber campuran
per indikator dilarang (§2.12, §25).

### 3.8 IKU sync one-time dari versi tertentu (§15)

`source_type`, `source_version_id`, `source_captured_at`. Begitu `source_captured_at` terisi,
tautannya **beku**: sinkron ulang tidak menimpa, melainkan menghasilkan versi IKU baru.
Itulah arti "one-time". Perubahan RPJMD/Renstra sesudahnya tidak menyentuh IKU version itu.

### 3.9 Copy / kosong / historis (§9, §10)

| `copied_from_version_id` | `mulai_dari_kosong` | Arti |
|---|---|---|
| terisi | 0 | **deep copy** dari versi itu — seluruh hierarki dengan ID baru (§10) |
| NULL | 1 | mulai dari kosong |
| NULL | 0 | salin dari kondisi live saat ini |

Deep copy wajib memberi **ID baru** di seluruh hierarki dan menyimpan lineage
(`copied_from_id` pada setiap tabel arsip), supaya menyunting V2 tidak pernah mengubah V1,
dan compare (§23) tidak perlu mencocokkan nama sebagai lineage.

### 3.10 Correction request untuk typo/non-substantif (§20, §21)

Versi `published` immutable terhadap sunting normal. Koreksi lewat
`version_correction_requests` dengan alur **permintaan → review → baru diterapkan**:

```
Published → Ajukan Koreksi → pending → Admin Kabupaten { kembalikan | setujui }
                                                          setujui → apply transaksional + audit
```

`old_value` **dibekukan** saat permintaan dibuat — kalau dibaca ulang saat ditampilkan, kolom
"sebelum" ikut bergerak dan perbandingannya jadi tidak berarti.

Whitelist field ditegakkan di **backend**, bukan skema: daftar field yang boleh dikoreksi
bergantung `entity_type` dan lebih aman dipelihara di PHP. Yang dijamin skema: `reason` NOT NULL.
Perubahan substantif (target, sasaran, indikator, formula, satuan, `effective_from`, hierarki)
**wajib versi baru** — bukan koreksi (§20, §2.14, §66).

### 3.11 Audit trail (§17, §22)

`version_submission_history` — append-only, satu baris per peristiwa:
`created | edited_draft | submitted | returned | resubmitted | published | cancelled |
correction_requested | correction_approved | correction_returned | synced | applied | retired`.

Menyimpan `dari_status`, `ke_status`, `sebelum`/`sesudah` (JSON), `catatan` (wajib pada
`returned`), `alasan`, `dasar`, `effective_from_saat_itu`, `source_version_id`, pelaku + role + IP.

FK-nya `ON DELETE RESTRICT` sehingga versi yang punya jejak **tidak bisa dihapus** — §2.4
ditegakkan engine, bukan disiplin. Sudah diuji.

**Nama pelaku dibekukan** (`oleh_nama`, `oleh_role`), tidak di-join saat ditampilkan: pegawai
berpindah OPD dan akun dinonaktifkan. Jejak audit yang ikut berubah ketika master datanya
berubah bukan jejak audit.

Ini melengkapi `activity_logs` yang generik per-request, bukan menggantikannya.

### 3.12 Pensiun, bukan hapus (menutup T5)

Tabel live RPJMD & Renstra mendapat kolom yang sama seperti yang sudah dipunyai `iku_*`:
`version_id`, `berlaku_sampai`, `dihentikan_pada`, `alasan_dihentikan` — pada `rpjmd_misi`,
`rpjmd_tujuan`, `rpjmd_indikator_tujuan`, `rpjmd_sasaran`, `rpjmd_indikator_sasaran`,
`renstra_tujuan`, `renstra_indikator_tujuan`, `renstra_sasaran`, `renstra_indikator_sasaran`.

Silsilah pada kedua tabel indikator sasaran: `indikator_sebelumnya_id`, `jenis_perubahan`,
`perubahan_substansial`.

Semuanya **NULLABLE / ber-default**, jadi seluruh baris yang ada sekarang tidak berubah perilaku.

FK ber-`CASCADE` yang berbahaya **sengaja tidak diubah** (§80.3, §81) — mengubahnya akan
mengubah perilaku `RpjmdModel::deleteMisi()`, `deleteSasaran()`, `RenstraModel::deleteCompleteRenstra()`.
Yang dilakukan adalah **memblokir hard-delete di lapisan model** ketika baris dirujuk sejarah,
memakai pola `indikatorDirujukSejarah()` yang sudah ada.

### 3.13 Benchmark per snapshot item (§36–§39)

`lakip_benchmark` yang ada sekarang berkunci `(rpjmd_indikator_id|renstra_indikator_id, tahun)` —
itu benchmark **global**, tepat yang §36 larang. Tabel itu **tidak dihapus** (§42, §81).

Yang dibuat: `lakip_benchmark_item` berbutir `lakip_snapshot_item_id`, lengkap dengan
`tahun_data_provinsi` / `tahun_data_nasional` yang dituntut §37 dan tidak dimiliki tabel lama.
Nilai kosong wajib `NULL`, bukan `0`.

Peran: `lakip_benchmark.manage_own` (OPD, lingkup sendiri) vs `manage_all` (Kabupaten) — §38.

---

## 4. Permission baru (§54)

Penamaan mengikuti §54: `<modul>.version.<aksi>`.

| Permission | admin_kab | admin_opd / kecamatan | inspektorat / bupati |
|---|:---:|:---:|:---:|
| `rpjmd.version.view` | ✓ | – | ✓ |
| `rpjmd.version.create` / `update_draft` / `submit` | ✓ | – | – |
| `rpjmd.version.verify` / `publish` | ✓ | – | – |
| `renstra.version.view` | ✓ | ✓ | ✓ |
| `renstra.version.create` / `update_draft` / `submit` | – | ✓ | – |
| `renstra.version.verify` / `publish` | ✓ | – | – |
| `iku.version.view` | ✓ | ✓ | ✓ |
| `iku.version.create` / `update_draft` / `submit` / `sync` | ✓ | ✓ | – |
| `iku.version.verify` / `publish` | ✓ | – | – |
| `lakip.version.view` | ✓ | ✓ | ✓ |
| `lakip.version.create` / `update_draft` / `submit` / `source_select` | ✓ | ✓ | – |
| `lakip.version.verify` / `publish` | ✓ | – | – |
| `version_correction.request` | ✓ | ✓ | – |
| `version_correction.verify` | ✓ | – | – |
| `lakip_benchmark.manage_own` | ✓ | ✓ | – |
| `lakip_benchmark.manage_all` | ✓ | – | – |

Role read-only **hanya** mendapat `.view`. Grup rute `/bupati` tidak menerima rute POST baru
(dijaga `ReadOnlyRoleFilter`).

> **Catatan penegakan.** `ModulePermissionFilter::actionsFor()` menyimpulkan aksi dari
> *substring path*, sehingga `iku/version/publish/5` hanya akan menuntut `iku_kab.view`.
> Karena itu setiap aksi versi **wajib** memeriksa permission-nya sendiri di controller/trait,
> persis seperti `IkuRevisiTrait::bolehSahkan()`. Jangan mengandalkan `modperm` sebagai
> satu-satunya penjaga.

---

## 5. Urutan pemasangan

```bash
# 1. PREFLIGHT — hanya membaca; hentikan bila ada baris bertanda GAGAL
mysql -u root -p "e-sakip_6" -t < db/preflight_2026-08-20_versioning.sql

# 2. Prasyarat (temuan T1: belum terpasang di e-sakip_6)
mysql -u root -p "e-sakip_6" < db/update_2026-08-18_iku_revisi_lakip_snapshot.sql

# 3. Registri versi + arsip RPJMD/Renstra + audit + koreksi + benchmark item
mysql -u root -p "e-sakip_6" < db/update_2026-08-20_versioning_dokumen.sql

# 4. POST-DEPLOY — 82 pemeriksaan; semua kolom `hasil` harus OK
mysql -u root -p "e-sakip_6" -t < db/postdeploy_2026-08-20_versioning.sql

# 5. Acceptance test §60-§65
php spark versi:verify

# 6. (temuan T2) migrasi data IKU legacy -> tabel standalone, bila diperlukan
php db/migrasi_iku_standalone.php

# 7. Regresi fitur yang sudah ada
php spark revisi:verify
```

Ketiga berkas SQL **idempoten** — aman dijalankan ulang.
`versi:verify` menerima `--db <nama>` untuk menguji di basis salinan lebih dulu.

---

## 6. Status implementasi

### Selesai & teruji

| Bagian | Berkas | Bukti |
|---|---|---|
| Skema + baseline + backfill | `db/update_2026-08-20_versioning_dokumen.sql` | idempoten; data lama identik |
| Preflight (§58) | `db/preflight_2026-08-20_versioning.sql` | – |
| Post-deploy (§59) | `db/postdeploy_2026-08-20_versioning.sql` | **82 pemeriksaan OK, 0 GAGAL** |
| Registri versi | `app/Models/DokumenVersiModel.php` | – |
| Lingkup versi | `app/Services/Version/VersionScope.php` | – |
| Resolver (§26) | `app/Services/Version/VersionResolver.php` | §61 lulus |
| Timeline (§3, §60) | `app/Services/Version/VersionTimelineService.php` | §60 lulus |
| Audit (§22) | `app/Services/Version/VersionAuditService.php` | §65 lulus |
| Approval (§17) | `app/Services/Version/VersionApprovalService.php` | §65 lulus |
| Arsip — dasar bersama | `app/Models/Versi/ArsipVersiModel.php` | – |
| Arsip RPJMD (beku/salin/terap) | `app/Models/Versi/RpjmdVersiModel.php` | §63 + uji pensiun lulus |
| Arsip Renstra (beku/salin/terap) | `app/Models/Versi/RenstraVersiModel.php` | uji lingkup OPD lulus |
| Pemeta modul → arsip | `app/Services/Version/ArsipRegistry.php` | – |
| Deep copy (§9, §10, §11) | `app/Services/Version/VersionDeepCopyService.php` | §63 lulus |
| Sumber LAKIP (§24–§28) | `app/Services/Version/LakipSourceService.php` | §68, §69 lulus |
| Perakit snapshot (§29, §31) | `app/Services/Version/LakipSnapshotBuilder.php` | §70 lulus |
| Versi & revisi LAKIP (§32–§34) | `app/Services/Version/LakipRevisionService.php` | §72 lulus |
| Bandingkan versi (§23) | `app/Services/Version/VersionCompareService.php` | render OK |
| Aksi versi (controller) | `app/Controllers/Concerns/DokumenVersiTrait.php` | – |
| UI versi RPJMD & Renstra | `app/Views/versi/{index,form,lihat,banding}.php` | render OK |
| Sunting isi draft (§9, §11) | `ArsipVersiModel::simpanSuntingan()` + `app/Views/versi/sunting.php` | §9/§11 lulus |
| Verifikasi Admin Kabupaten (§17) | `app/Controllers/AdminKab/VerifikasiController.php` | §17 lulus |
| UI verifikasi | `app/Views/versi/{verifikasi_index,verifikasi_lihat}.php` | render OK |
| Badge antrean di sidebar | `app/Helpers/versi_helper.php` | – |
| Siklus Renstra berjalan (= V1) | `app/Controllers/Concerns/RenstraSiklusTrait.php` | uji siklus lulus |
| Ajukan koreksi (§20, §21) | `app/Services/Version/VersionCorrectionService.php` + `app/Views/versi/koreksi.php` | uji koreksi lulus |
| Ubah tanggal berlaku | `VersionTimelineService::ubahTanggalBerlaku()` + `app/Views/versi/keterangan.php` | uji tanggal lulus |
| **Isi versi Renstra lewat form yang sama dengan Tambah Renstra** | `app/Controllers/Concerns/RenstraVersiIsiTrait.php` + `RenstraVersiModel::simpanTujuanDariForm()` / `tujuanUntukForm()` | uji pulang-pergi lulus |
| **Memilih versi untuk dilihat & dicetak di menu Renstra** | `RenstraVersiModel::bacaSepertiLive()` + `RenstraVersiIsiTrait::renstraVersiPilihan()` | uji bentuk data lulus |
| **Tunjukan "tampilan utama"** | `db/update_2026-08-23_tampilan_utama_versi.sql` + `DokumenVersiModel::tetapkanTampilanUtama()` | UNIQUE engine terbukti menolak tunjukan kedua |
| **Izin sunting dokumen yang sudah ditetapkan** | `db/update_2026-08-24_izin_sunting.sql` + `app/Services/Version/IzinSuntingService.php` | arsip terbukti tidak tersentuh (uji sidik jari) |
| **Pratinjau garis waktu saat mengubah tanggal** | `app/Views/versi/keterangan.php` | render OK |
| **Geser tanggal versi yang sudah ditetapkan** | `DokumenVersiTrait::versiBolehGeserTanggal()` | garis waktu terbukti tetap bersambung |
| **Penguncian Renstra berbasis versi RESMI** | `RenstraSiklusTrait::renstraKeadaan()` | draft terbukti tidak membuka kunci |
| **Sync IKU dari versi Renstra pilihan + jejak asal** | `IkuModel::getKandidatSync()/importSync()` | jejak terbukti tercatat & tidak terhapus saat disunting |
| **Verifikasi revisi IKU OPD oleh Admin Kabupaten** | `IkuRevisiModel::ajukan()/kembalikan()` + `VerifikasiController` | jalur buntu tertutup, ada ujinya |
| **Sync bermuara ke draft revisi + jejak selamat melewati beku/terap** | `db/update_2026-08-25_jejak_sumber_revisi_iku.sql` + `IkuRevisiModel::imporKandidat()` | 13 uji |
| **Selisih IKU terhadap versi Renstra pilihan** | `IkuModel::bandingkanIndikator()` + `ikuTanpaPadananSumber()` | 13 uji |
| **Halaman verifikasi revisi IKU + pratinjau dampaknya** | `IkuRevisiModel::praTinjauPengesahan()` + `app/Views/iku/verifikasi_revisi.php` | 13 uji |
| **Ajukan pengesahan IKU dari isi berjalan** | `IkuRevisiModel::bekukanDanAjukan()` | 11 uji |
| **Sunting keterangan IKU terbatas 4 kolom** | `IkuModel::perbaruiKeterangan()` + `adminOpd/iku/edit_iku.php` | 15 uji |
| **LAKIP bersumber IKU (pilih versi, jejak beku, edit, cetak)** | `LakipModel::getIndexIkuTargets()` + `LakipOpdController` | 12 uji + 4 render |
| Acceptance test | `app/Commands/VersioningVerify.php` | **400 LULUS, 0 GAGAL** |
| Uji render view | `app/Commands/VersiRenderCheck.php` | 39/39 Renstra, 22/22 RPJMD (termasuk layar IKU & LAKIP) |
| Pembersih lapisan versi | `app/Commands/VersiReset.php` | isi dokumen tidak disentuh |

#### Berkas lama yang diubah — seluruhnya aditif

| Berkas | Perubahan |
|---|---|
| `app/Config/Routes.php` | +45 baris: 20 rute versi + 4 rute verifikasi + 4 rute isi tujuan versi, didaftarkan **sebelum** pola yang lebih umum |
| `app/Controllers/RpjmdController.php` | +31 baris: `use DokumenVersiTrait` + 4 method lingkup |
| `app/Controllers/AdminOpd/RenstraController.php` | +41 baris: idem, `opd_id` dari **sesi** |
| `app/Views/templates/admin_menu.php` | +15 baris: butir "Versi RPJMD", "Versi Renstra", dan menu "Verifikasi" ber-badge |
| `app/Views/adminOpd/renstra/tambah_renstra.php` | diparameterkan (`$formAction`, `$judulForm`, `$hiddenExtra`, `$periodeKunci`, `$isiAwal`, `$kembaliUrl`, `$catatanForm`); tanpa parameter, perilakunya persis seperti semula |
| `app/Views/versi/form.php` | pertanyaan sumber isi dibingkai ulang jadi "seberapa besar perubahannya"; medan POST tidak berubah |
| `app/Views/versi/lihat.php` | tombol Tambah/Sunting/Hapus Tujuan untuk draft Renstra |
| `app/Views/adminOpd/renstra/renstra.php` | pemilih versi, spanduk penanda arsip, kolom Status/Aksi & tombol tulis padam saat membaca versi |
| `app/Views/adminOpd/renstra/renstra_cetak.php` | subjudul menyebut versi yang dicetak |
| `app/Controllers/AdminOpd/RenstraController.php` | `index()` & `cetak()` membaca arsip bila `?versi=` diisi, atau bila ada tunjukan tampilan utama; `?versi=berjalan` memaksa kondisi berjalan |
| `app/Views/versi/index.php` | lencana "Tampilan Utama" pada versi yang ditunjuk |
| `app/Views/versi/lihat.php` | tombol "Ajukan Koreksi" DIHAPUS, digantikan Izin Sunting; tabel isi memakai rowspan |
| `app/Views/versi/verifikasi_lihat.php` | tabel isi memakai rowspan; tujuan tanpa sasaran & sasaran tanpa indikator tidak lagi raib |
| `app/Views/versi/verifikasi_index.php` | antrean permohonan izin sunting |
| `app/Controllers/AdminKab/VerifikasiController.php` | keputusan izin sunting (setujui/tolak/cabut) |

Tidak ada satu pun method atau baris lama yang diubah atau dihapus — trait hanya
**menambah** method berawalan `versi*`.

#### Penjaga yang paling penting: temuan T5

Uji `Pensiun bukan hapus` membuktikan bahwa menerbitkan versi RPJMD yang membuang
sebuah sasaran **memensiunkannya, bukan menghapusnya** — dan `renstra_tujuan` yang
menggantung padanya lewat FK `ON DELETE CASCADE` **selamat**. Di data nyata, itu
adalah Renstra 38 OPD beserta Renaksi dan analisis LAKIP-nya.

Uji arsip Renstra membuktikan pasangannya untuk T4: sebuah `renstra_tujuan` yang masih
dipakai OPD lain **tidak** ikut dipensiunkan ketika satu OPD mengosongkan Renstra-nya,
dan baru pensiun setelah kehilangan seluruh sasarannya lintas OPD.

### Belum dikerjakan

| Bagian | Spec |
|---|---|
| Alur ajukan/verifikasi khusus LAKIP OPD di controller | §35 |
| Benchmark per snapshot item — layanan & UI (tabel + penyalinan revisi sudah ada) | §36–§39, §73 |
| UI pemilih sumber & revisi LAKIP (layanannya sudah ada) | §25–§35 |
| Migration CI4 kembaran SQL | §56 |
| Regression test lintas modul | §74 |
| Pembaruan `AI_AGENT_GUIDE_AKSARA.md` | §78 |

### Catatan rancangan: dua jawaban atas "versi mana yang dipakai"

Sejak `update_2026-08-23`, ada **dua** sumber jawaban dan keduanya sah:

| Sumber | Menjawab | Disimpan di |
|---|---|---|
| `effective_from`/`effective_to` | versi mana yang **berlaku** pada suatu tanggal | kolom tanggal, dihitung `VersionResolver` |
| `tampilan_utama` | versi mana yang **ditampilkan** di menu dokumennya | kolom tunjukan, dipasang manual |

Dipisah dengan sengaja: tanggal berlaku adalah fakta hukum dokumen, dan mengarang
tanggal demi mengubah tampilan akan merusak riwayat yang justru menjadi alasan
seluruh fitur versi ini ada.

Empat pengaman menjaga agar dua jawaban ini tidak berubah menjadi kerancuan:

1. Paling banyak **satu** tunjukan per dokumen — dijamin `uq_dokver_tampilan`,
   bukan oleh kode yang bisa lupa. Berpindah tunjukan **wajib** melepas dulu baru
   memasang, aturan urutan yang sama dengan `hitungUlang()`.
2. Hanya versi **published** yang boleh ditunjuk, dan hanya yang **berisi**.
3. Bila tunjukan berbeda dari versi yang berlaku menurut tanggal, selisihnya
   **ditampilkan** di halaman versi dan di menu Renstra — tidak didiamkan.
4. Izin tersendiri `<modul>.version.pin`; peran read-only tidak menerimanya.

Yang **belum** mengikuti tunjukan: PK, Cascading, RKT, dan LAKIP masih membaca
tabel berjalan. Tunjukan sejauh ini hanya mengatur TAMPILAN menu Renstra.

### Catatan rancangan: izin sunting menggantikan permintaan koreksi

Sejak `update_2026-08-24`, perbaikan atas Renstra yang sudah ditetapkan berjalan
lewat **izin sunting**, bukan koreksi per-medan:

```
published  ──►  OPD "Ajukan Izin Sunting" (wajib beralasan)
                       │
                       ▼
           Admin Kabupaten memberi izin / menolak (penolakan wajib bercatatan)
                       │
                       ▼
           kunci menu Renstra TERBUKA — form & tombol seperti biasa
                       │
                       ▼
           "Ajukan Validasi" ──► LAHIR VERSI BARU ──► izin ditutup sendiri
```

Yang dibuka adalah kunci **tabel berjalan**; arsip versi yang sudah ditetapkan
tidak pernah tersentuh. Karena itu jejak audit, snapshot LAKIP, dan fitur
Bandingkan tetap sah. Uji `caseIzinSunting` membuktikannya dengan membandingkan
sidik jari (md5) isi arsip sebelum dan sesudah izin diberikan.

Jebakan terbesar ada di `renstraAjukanValidasi()`: tanpa cabang khusus, pengajuan
ulang akan mengembalikan versi yang **sudah published** ke status draft dan
melenyapkan keresmiannya. Cabang `STATUS_PUBLISHED` di sana yang mencegahnya —
pengajuan ulang selalu melahirkan versi baru.

Layanan `VersionCorrectionService` beserta tabelnya **dipertahankan** walau tombol
masuknya dihapus, supaya permintaan koreksi lama tetap terbaca di jejak audit.

### Catatan rancangan: siapa boleh menggeser tanggal berlaku

`effective_from` bukan ISI dokumen melainkan keterangan KAPAN isi itu mulai
dipakai, sehingga menggesernya tidak menyentuh arsip sama sekali. Karena itu ia
tidak tunduk pada aturan "published bersifat tetap" yang berlaku untuk isi.

| Keadaan versi | Siapa boleh menggeser |
|---|---|
| draft | penyusunnya (`.version.update_draft`) |
| published, baseline otomatis | penyusunnya (`.version.create`) — tanggalnya memang tebakan pemasangan |
| published, versi resmi | `.version.publish`, **atau** penyusunnya selama ada izin sunting berlaku |
| pending_approval | tidak ada — tarik permohonannya dulu |

Untuk Renstra, jalur `.version.publish` praktis tidak terpakai: rute
`adminopd/renstra/*` tidak menerima role `admin_kab`. Jadi jalannya adalah izin
sunting — mekanisme yang sama dengan memperbaiki isi, bukan mekanisme kedua.

### Catatan rancangan: apa yang mengunci Renstra berjalan

Yang mengunci adalah **versi resmi** — `published` DAN arsipnya berisi — bukan
versi ber-`version_no` tertinggi. Perbedaannya bukan kosmetik:

| Peran versi | Akibat pada Renstra berjalan |
|---|---|
| `pending_approval` | terkunci; tawarkan tarik permohonan |
| `published` + berisi | terkunci; buka lewat izin sunting |
| `published` + arsip kosong (baseline pemasangan) | **tidak mengunci**; dipakai ulang jadi V1 |
| `draft` / `cancelled` | **tidak pernah menentukan apa pun** |

Versi draft hidup di arsipnya sendiri dan tidak menyentuh tabel berjalan, jadi
keberadaannya tidak punya alasan mengubah keadaan. Sebelum diperbaiki, membuat
draft baru di menu "Versi Renstra" membuat status periode terbaca `draft` dan
kunci terbuka sendiri — pintu belakang yang membatalkan seluruh alur izin sunting.

Draft yang **sudah berisi** juga menghalangi tombol "Ajukan Validasi" di menu
Renstra, sebab pengajuan dari sana membekukan ulang dari kondisi berjalan dan
akan menimpa isi draft yang disusun tangan.

### Catatan rancangan: `renstra_tujuan` tidak punya pemilik (T4)

Kepemilikannya hanya tersirat lewat `renstra_sasaran` yang menggantung padanya,
sehingga **tidak ada query yang aman dengan sendirinya**. Tiap jalur tulis wajib
memanggil `renstraTujuanMilikSaya()` lebih dulu.

Yang membuat celah ini sulit terlihat: `renstraKeadaanDariTujuan()` mencari
tujuan dalam lingkup sendiri; ketika tidak ketemu ia memanggil
`renstraKeadaan(0, 0)`, dan selama cabang itu mengembalikan "tidak terkunci",
penjaga justru **meloloskan** tujuan asing. Periode tidak sah kini berarti
TOLAK.

### Catatan rancangan: IKU berasal dari Renstra, lalu hidup sendiri

IKU bukan salinan Renstra melainkan **pilihan** indikator utama yang diambil
darinya sekali, lalu tidak lagi mengikutinya. Karena itu penyusun memilih
Renstra **versi mana** yang jadi titik tolak, dan pilihan itu dicatat:

| Kolom | Isi |
|---|---|
| `source_type` | `renstra` / `rpjmd` |
| `source_version_id` | id `dokumen_versi` sumber; NULL bila dari kondisi berjalan |
| `source_sasaran_id` / `source_indikator_id` | id baris **berjalan** — jembatan realisasi LAKIP |

Dua id yang gampang tertukar saat membaca arsip: `sumber_id` adalah id baris
ARSIP (dipakai kotak centang di form), sedangkan `sumber_live_id` adalah id
baris BERJALAN yang dibekukan arsip itu — dan yang kedua inilah yang disimpan
sebagai jejak.

Kolom `source_*` **hanya ditulis saat baris lahir**, tidak lewat
`siapkanIndikator()` yang juga melayani pembaruan. Kalau ikut ditulis di sana,
menyunting kalimat indikator akan menimpanya dengan NULL — jejak asal terhapus
justru ketika orang sedang merapikan redaksinya.

### Catatan rancangan: siklus revisi IKU

```
draft ──ajukan──► menunggu ──sahkan (Admin Kab)──► berlaku
  ▲                   │                              │
  └──kembalikan───────┘                     revisi lama ──► superseded
     (catatan WAJIB)
```

Status `menunggu` disisipkan tanpa perubahan skema: `status` sudah `VARCHAR(20)`
dan `berlaku_key` hanya bereaksi pada nilai `berlaku`, sehingga jaminan "satu
revisi berlaku per lingkup" tetap utuh apa adanya.

Dua lingkup, dua perlakuan — dan yang menentukan adalah **lingkupnya**, bukan
izinnya:

| Lingkup | Pengesahan |
|---|---|
| `iku_kab` | disahkan penyusunnya sendiri; dokumennya memang milik mereka |
| `iku_opd` | wajib lewat antrean Admin Kabupaten (`iku.version.verify`) |

`revisiSahkan()` menolak lingkup OPD lebih dulu, sebelum memeriksa izin.
Menyandarkan penguncian ini semata pada pemberian izin berarti satu baris di
`role_permissions` bisa membatalkan seluruh alur verifikasi.

Tidak ada izin baru: `iku.version.verify` yang sudah dipegang admin_kab artinya
persis itu, dan izin kembar hanya membuat daftar izin makin sulit dibaca.

### Catatan rancangan: ke mana hasil sync bermuara

| Keadaan IKU | Sync menulis ke | Alasan |
|---|---|---|
| belum ada revisi berlaku | tabel berjalan | IKU memang masih disusun; sama wajarnya dengan mengetik manual |
| sudah ada revisi berlaku | **draft revisi** | menambah ke dokumen resmi harus ikut antre disahkan |

Titik baliknya `IkuRevisiModel::revisiBerlaku()`. Sesudah titik itu, menulis
langsung ke tabel berjalan berarti mengubah dokumen resmi tanpa sepengetahuan
siapa pun — dan tambahannya tidak akan muncul di arsip revisi mana pun.

Draft berstatus `menunggu` **tidak** ditawarkan sebagai tujuan: isinya sedang
diperiksa, dan menambahinya diam-diam berarti verifikator memutuskan sesuatu
yang bukan lagi yang ia baca.

### Catatan rancangan: dua "sumber" yang mudah tertukar di arsip revisi IKU

| Kolom | Menunjuk |
|---|---|
| `sumber_sasaran_id` / `sumber_indikator_id` | baris **IKU berjalan** yang dibekukan baris arsip ini |
| `source_ref_id` | baris **Renstra berjalan** yang menjadi asal-usulnya |

Karena itu kolom Renstra sengaja TIDAK dinamai `source_indikator_id` seperti di
tabel live — di sini nama itu akan berdiri persis di sebelah `sumber_indikator_id`
dengan arti berbeda.

`bekukanLiveKeRevisi()` membawa jejak masuk, `terapkanKeLive()` menuliskannya
kembali. Sebelum diperbaiki, keduanya tidak menyentuhnya sama sekali: sekali
sebuah revisi disahkan, keterangan "IKU ini dari Renstra V berapa" lenyap tanpa
gejala apa pun.

### Catatan rancangan: layar sync sekaligus layar selisih

Satu layar melayani sync pertama maupun sync ke-sekian. Saat IKU masih kosong,
semuanya wajar muncul sebagai "Baru"; sesudah terisi, layar yang sama menjadi
perbandingan.

| Keadaan | Arti | Kotak centang |
|---|---|---|
| **Baru** | tidak ada padanannya di IKU | `pilih[]` — menambah baris, tercentang otomatis |
| **Berubah** | ada padanannya, tetapi nilainya bergeser | `perbarui[]` — MENIMPA, tidak tercentang otomatis |
| **Sama** | identik | tidak bisa dicentang |

Nama medannya sengaja dibedakan. Menambah baris baru dan menimpa nilai yang
sudah dipakai adalah dua keputusan berbeda; satu nama untuk dua akibat cepat
atau lambat membuat orang menimpa tanpa bermaksud.

**Pencocokan lewat silsilah dulu, baru teks.** Bila indikator IKU menyimpan
`source_indikator_id`, itulah padanan yang pasti. Pencocokan teks hanya cadangan
untuk indikator yang dulu diketik manual. Bedanya nyata: begitu redaksi
dirapikan di salah satu sisi, pencocokan teks gagal dan indikator yang sama akan
tampak "baru" lalu tersalin dua kali.

**Indikator IKU yang tidak ada di sumber ditampilkan, bukan dihapus.** IKU
memang boleh berisi indikator yang tidak ada di Renstra — itulah gunanya ia
dokumen tersendiri. Daftar itu hanya keterangan, terutama setelah berganti versi
sumber.

### Catatan rancangan: verifikasi revisi IKU

Halaman keputusan menaruh **DAMPAK di atas, isi lengkap di bawah**. Urutannya
disengaja: isi dokumen bisa dibaca kapan saja, sedangkan yang hanya bisa dilihat
sebelum tombol ditekan adalah akibatnya — dan yang terpenting di antaranya
justru **tidak tertulis di dokumen itu**, sebab yang hilang memang tidak
tercantum:

| Bagian | Menjawab |
|---|---|
| dipensiunkan | indikator mana yang berhenti karena tidak lagi tercantum |
| berubah | nilai apa yang bergeser, lama -> baru |
| baru | indikator apa yang bertambah |
| digeser | revisi mana yang menjadi arsip |

Perbandingannya **arsip lawan arsip**, bukan arsip lawan tabel berjalan: yang
perlu dijawab adalah "apa bedanya dengan dokumen yang berlaku sekarang", bukan
"apa bedanya dengan keadaan sesaat ini".

Pencocokannya lewat `sumber_indikator_id` dulu, baru teks — sama seperti layar
selisih IKU. Tanpa itu, indikator yang cuma diperbaiki ejaannya akan terbaca
sebagai "yang lama dipensiunkan, yang baru muncul", dan verifikator melihat dua
perubahan besar padahal tidak ada yang berubah.

Tombol utama di antrean adalah **Periksa Isinya**, bukan Sahkan: menyetujui
dokumen yang belum dibaca bukan verifikasi.

### Catatan rancangan: sync IKU mengambil seluruh isi versi sumber

Pemilihan per indikator untuk yang **baru** dihapus — seluruh isi versi Renstra
yang dipilih ikut terambil, sesuai keputusan pengguna bahwa IKU adalah salinan
Renstra yang kemudian hidup sendiri.

Yang **tetap** memerlukan centang adalah indikator berstatus *berubah*, dan itu
bukan inkonsistensi: mengambilnya berarti **menimpa** nilai yang mungkin sengaja
diubah di IKU — persis kemandirian yang jadi alasan modul ini ada. Medannya pun
dibedakan (`pilih[]` vs `perbarui[]`) supaya satu nama tidak melahirkan dua
akibat.

### Catatan rancangan: pembagian tugas antar-layar di modul IKU

| Layar | Boleh mengubah | Alasan |
|---|---|---|
| Sync dari Renstra | menambah sasaran/indikator/target | substansinya berasal dari Renstra |
| Sunting Keterangan (menu Edit) | definisi, formula, sumber data, penanggung jawab | keempatnya tidak pernah ada di Renstra |
| Sunting Draft Revisi | segalanya, termasuk keempat kolom di atas | tercatat & disahkan Admin Kabupaten |
| Tambah IKU manual | *(disembunyikan)* | mudah melahirkan sasaran kembar |

Pembatasan layar Keterangan **ada di server, bukan hanya di layar**:
`IkuController::update()` membaca `keterangan[<id>][...]` saja dan memanggil
`perbaruiKeterangan()` yang menyentuh empat kolom itu. Sebelumnya ia memanggil
`updateComplete()`, yang menulis ulang sasaran, indikator, satuan, dan SELURUH
target dari apa pun yang dikirim — menyembunyikan medannya di layar tidak
menutup jalan itu.

Form draft revisi kini bertata letak kartu, sama dengan form Tambah IKU.
Bentuk tabel yang lama memaksa keempat medan panjang itu dihilangkan karena
tidak muat, sehingga tidak pernah bisa diisi lewat revisi meski kolomnya sudah
ada di basis data dan model sudah siap menerimanya.

---

## 7. Yang sengaja TIDAK dikerjakan

* Mengubah FK `CASCADE` menjadi `RESTRICT` (lihat 3.12) — diusulkan terpisah, karena
  mengubahnya mengubah perilaku alur hapus yang sekarang mengandalkannya (§80.3).
* **111 baris `lakip` yatim tidak ditebak tahunnya.** Kolom lingkup sudah ditambahkan dan
  di-backfill (88 mode=opd, 15 mode=kabupaten), tetapi 103 baris yang kedua FK sumbernya
  `NULL` dibiarkan `tahun IS NULL`. Menebak tahunnya berarti mengarang sejarah; post-deploy
  melaporkan jumlahnya untuk ditindaklanjuti manual.
* Menyeragamkan tiga definisi `hitungCapaianLakip()` — menyeragamkannya akan mengubah angka
  yang sudah tampil hari ini.
* Menghapus `DashboardModel` / `DashboardOpdModel` / `DashboardKabupatenModel` yang sudah
  dead code.
