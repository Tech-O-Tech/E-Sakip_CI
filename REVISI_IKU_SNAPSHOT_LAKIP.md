# Revisi IKU + Snapshot Tahunan LAKIP + Penyesuaian Kebijakan

**Tanggal:** 2026-08-18
**Basis:** CodeIgniter 4.6.4 + MySQL 8.4.3 (`test_sakip`)
**Sifat perubahan:** aditif & backward-compatible — tidak ada tabel, kolom, rute, atau tampilan lama yang dihapus atau berubah maknanya.

---

## 1. Masalah yang diselesaikan

Dokumen perencanaan punya umur berbeda:

| Dokumen | Umur |
|---|---|
| RPJMD | ±5 tahun |
| Renstra | ±5 tahun |
| IKU | ±5 tahun |
| **LAKIP** | **1 tahun** |

Sebelum perubahan ini, seluruhnya dibaca dari **satu-satunya salinan data yang hidup**. Akibatnya:

1. **Menyunting IKU/Renstra/RPJMD hari ini ikut mengubah bunyi LAKIP tahun-tahun lampau.** `LakipController::cetak()` memakai query yang sama persis dengan `index()` — tidak ada pembekuan.
2. **`IkuModel::updateComplete()` MENGHAPUS indikator** yang hilang dari form, dan target/programnya ikut musnah lewat FK `ON DELETE CASCADE`. Mengosongkan satu textarea sudah cukup untuk menghapus permanen satu indikator beserta seluruh target tahunannya.
3. **Tidak ada cara membedakan** "indikator ini direvisi" dari "indikator ini diganti indikator lain" dari "indikator baru" — sehingga tren antar tahun bisa menyambungkan dua hal yang sebenarnya berbeda.
4. **Tidak ada jalur resmi untuk koreksi kebijakan** pada LAKIP tahun berjalan yang meninggalkan jejak.

> Bukti bahwa ini bukan kekhawatiran teoretis: FK `lakip` ke kedua tabel target memakai `ON DELETE SET NULL`, dan di basis data produksi **111 dari 214 baris `lakip` sudah yatim** (kedua kolom target `NULL`). Baris-baris itu sudah lenyap diam-diam dari semua laporan, karena setiap query menyaring lewat `rt.tahun` / `rpj.tahun`.

**Prinsip yang dipegang:** *jangan mengubah sejarah hanya karena dokumen sumber berubah kemudian.*

---

## 2. Tiga lapis penyelesaian

```
┌─ REVISI IKU ─────────────────────────────────────────────────┐
│  Versi dokumen IKU yang bernomor, bertanggal berlaku, beku.   │
│  LAKIP tahun Y membaca versi yang berlaku PADA tahun Y.       │
└──────────────────────────────────────────────────────────────┘
┌─ SNAPSHOT LAKIP ─────────────────────────────────────────────┐
│  Pembekuan seluruh baris LAKIP satu tahun. Setelah final,     │
│  perubahan RPJMD/Renstra/IKU tidak lagi menyentuhnya.         │
└──────────────────────────────────────────────────────────────┘
┌─ PENYESUAIAN KEBIJAKAN ──────────────────────────────────────┐
│  Pengecualian yang tercatat: koreksi khusus tahun LAKIP itu,  │
│  wajib berdasar kebijakan. Bisa "diusulkan" jadi revisi IKU.  │
└──────────────────────────────────────────────────────────────┘
```

---

## 3. Keputusan desain inti

### 3.1 Tabel live `iku_*` tetap berisi "versi yang sedang berlaku"

Ada dua pilihan menaruh versi berlaku:

| | Pendekatan | Konsekuensi |
|---|---|---|
| (a) | Hanya di tabel revisi, tabel live dipensiunkan | API publik, halaman publik, dan model dashboard **semua** harus dirombak |
| (b) | **Tabel live selalu = versi berlaku**, tabel revisi = arsip beku + draft | Semua pembaca lama **tidak berubah sebaris pun** |

**Dipilih (b).** `iku_sasaran` / `iku_indikator` / `iku_target` dibaca oleh `Api\PerangkatDaerahController` (API publik), `UserPublicModel` (halaman publik), dan tiga model dashboard. Membiarkannya apa adanya adalah yang membuat perubahan ini backward-compatible.

Alurnya menjadi:

```
buatDraft()  →  salin kondisi live saat ini jadi titik awal usulan (status draft)
                TIDAK menyentuh tabel live sama sekali
sahkan()     →  isi draft DITERAPKAN ke tabel live
                revisi sebelumnya ditandai superseded, arsipnya tetap utuh selamanya
LAKIP thn Y  →  membaca ARSIP revisi yang efektif pada tahun Y
```

### 3.2 Snapshot membekukan MASUKAN, bukan hasil hitung

`hitungCapaianLakip()` ternyata punya **tiga definisi berbeda** di proyek ini, semuanya dijaga `function_exists()`:

| Berkas | Perilaku | Dipakai oleh |
|---|---|---|
| `app/Helpers/lakip_helper.php` | **tanpa** clamp | cetak PDF & Excel |
| `views/adminOpd/lakip/lakip.php` | clamp 0..200% | layar OPD |
| `views/adminKabupaten/lakip/lakip.php` | clamp 0..200% | layar Kabupaten |

Artinya **layar dan PDF memang sudah menampilkan persentase berbeda** untuk capaian ekstrem. Kalau snapshot menyimpan satu angka persen jadi, salah satu dari keduanya pasti berubah — pelanggaran backward-compatibility yang nyaris mustahil disadari.

**Maka yang dibekukan adalah bahan mentahnya** (target, realisasi, satuan, jenis indikator, nilai `*_hitung`), dan setiap view tetap menghitung persentasenya sendiri seperti sekarang. **Tidak ada satu angka pun yang berubah.**

### 3.3 Snapshot menyalin TEKS, bukan sekadar id

Karena 111 baris `lakip` sudah yatim di produksi, arsip yang isinya cuma penunjuk id akan ikut kosong begitu sumbernya hilang. Tabel snapshot **sengaja tanpa foreign key** ke `renstra_target` / `rpjmd_target` / `lakip` — arsip yang ikut terhapus saat sumbernya dihapus bukan arsip.

### 3.4 Rehidrasi menghasilkan bentuk array yang identik

`LakipSnapshotModel::rehidrasi()` mengembalikan `['rows', 'lakipMap']` **persis seperti** `LakipModel::getLakipByMode()`. Karena itu seluruh view, view cetak, dan kedua fungsi Excel bekerja tanpa diubah — controller cukup menukar sumbernya.

---

## 4. Skema basis data

Berkas: `db/update_2026-08-18_iku_revisi_lakip_snapshot.sql` (idempoten, sudah diterapkan)
Kembarannya: `app/Database/Migrations/2026-08-18-000001_CreateIkuRevisiAndLakipSnapshot.php`

### 4.1 Tabel baru

```
iku_revisi                       kepala versi dokumen IKU
 ├─ iku_revisi_sasaran           arsip sasaran
 │   └─ iku_revisi_indikator     arsip indikator + LINEAGE
 │       ├─ iku_revisi_target    arsip target tahunan
 │       └─ iku_revisi_program   arsip program pendukung

lakip_snapshot                   kepala snapshot tahunan
 ├─ lakip_snapshot_baris         salinan beku baris LAKIP
 │   └─ lakip_snapshot_analisis  analisis faktor beku (one-to-many)
 └─ lakip_snapshot_program       program & anggaran beku

lakip_penyesuaian                penyesuaian kebijakan
```

### 4.2 Kolom tambahan pada tabel lama

Semua **NULLABLE / ber-default**, sehingga 75 sasaran + 97 indikator yang sudah ada tidak berubah perilakunya sedikit pun.

| Tabel | Kolom | Guna |
|---|---|---|
| `iku_sasaran` | `revisi_id`, `berlaku_sampai`, `dihentikan_pada`, `alasan_dihentikan` | pensiun, bukan hapus |
| `iku_indikator` | `revisi_id`, `indikator_sebelumnya_id`, `jenis_perubahan`, `perubahan_substansial`, `berlaku_sampai`, `dihentikan_pada`, `alasan_dihentikan` | lineage + pensiun |

### 4.3 Penegakan invariant di level engine

Dua aturan terkeras **dijamin basis data**, bukan sekadar dijaga PHP — memakai *generated column* + UNIQUE index (MySQL mengabaikan `NULL` pada UNIQUE):

```sql
-- iku_revisi
berlaku_key TINYINT AS (CASE WHEN status='berlaku' THEN 1 ELSE NULL END) STORED
opd_key     INT UNSIGNED AS (COALESCE(opd_id,0)) STORED
UNIQUE (opd_key, tahun_mulai, tahun_akhir, berlaku_mulai_tahun, berlaku_key)

-- lakip_snapshot
aktif_key TINYINT AS (CASE WHEN aktif=1 THEN 1 ELSE NULL END) STORED
UNIQUE (tahun, mode, opd_id, aktif_key)
```

`opd_key` diperlukan karena IKU kabupaten memakai `opd_id NULL`, dan MySQL menganggap dua `NULL` selalu berbeda — tanpa kolom itu UNIQUE-nya tidak akan pernah mengikat untuk tingkat kabupaten.

> **Terbukti:** percobaan menyisipkan revisi `berlaku` kedua ditolak `ERROR 1062`, begitu juga snapshot aktif kedua.

**Catatan teknis:** `iku_revisi.opd_id` sengaja **tanpa** foreign key. MySQL melarang FK ber-aksi `CASCADE`/`SET NULL` pada kolom yang menjadi dasar *stored generated column* (error 1215) — dan secara semantik memang benar: arsip milik OPD yang dibubarkan tetap harus terbaca oleh LAKIP tahun-tahun sebelumnya.

---

## 5. Alur kerja

### 5.1 Revisi IKU

```
                      ┌──────────────────┐
      Buat Revisi ───►│  draft           │  isi = salinan IKU yang berlaku sekarang
                      │                  │  IKU berjalan BELUM berubah
                      └────────┬─────────┘
                Sunting Draft  │  ubah target, tandai indikator
                               │  dihentikan / pengganti / baru
                               │
                    ┌──────────┴──────────┐
             Sahkan │                     │ Batalkan
                    ▼                     ▼
             ┌─────────────┐        ┌───────────┐
             │  berlaku    │        │  batal    │  barisnya tetap ada
             └──────┬──────┘        └───────────┘
    revisi berikutnya disahkan
                    ▼
             ┌─────────────┐
             │ superseded  │  TETAP jadi sumber LAKIP untuk tahun-tahun
             └─────────────┘  yang dulu dipayunginya — tidak pernah dihapus
```

Yang terjadi saat **Sahkan** (satu transaksi penuh):

1. Revisi lama pada lingkup yang sama ditutup: `status = superseded`, `berlaku_sampai_tahun = berlaku_mulai_tahun_baru − 1`.
2. Isi arsip diterapkan ke tabel live (`iku_sasaran` / `iku_indikator` / `iku_target` / `iku_program`).
3. Indikator live yang **tidak lagi disebut** arsip **dipensiunkan** (`dihentikan_pada` terisi, `berlaku_sampai` = tahun sebelum revisi) — **tidak dihapus**. Target lamanya tetap utuh.
4. Draft ditandai `berlaku`.

> **Penting:** penyapuan pensiun **dibatasi periode revisi**. Satu pemilik lazim punya beberapa periode IKU sekaligus (2020–2024 di samping 2025–2029); tanpa batas ini, mengesahkan revisi satu periode akan memensiunkan seluruh IKU periode lain. Ada uji regresi khusus untuk ini.

**Revisi ke-0 (Kondisi Awal)** dibuat otomatis saat revisi pertama dibuat, berisi salinan IKU yang berlaku saat itu — supaya tahun-tahun sebelum revisi pertama juga punya dokumen rujukan.

**Resolusi versi untuk tahun Y:**

```sql
WHERE status IN ('berlaku','superseded')          -- draft & batal TIDAK PERNAH
  AND berlaku_mulai_tahun <= Y
  AND (berlaku_sampai_tahun IS NULL OR berlaku_sampai_tahun >= Y)
  AND tahun_mulai <= Y AND tahun_akhir >= Y
ORDER BY berlaku_mulai_tahun DESC, nomor DESC
```

Bila hasilnya **lebih dari satu**, sistem **tidak memilih salah satu** — ia mengembalikan daftar konflik dan halaman menampilkan galat administratif.

### 5.2 Snapshot Tahunan LAKIP

```
   Belum ada snapshot           Sudah ada snapshot
   ─────────────────            ──────────────────
   [Siapkan LAKIP]      ──►     [Lihat Snapshot]
                                [Bandingkan dengan Data Terbaru]
                                [Sinkronkan Snapshot]   ← versi baru, versi lama
                                [Finalkan / Kunci Tahun]   dinonaktifkan (bukan dihapus)
                                        │
                                        ▼
                                 status = final
                                 ✗ tidak boleh disinkronkan ulang
                                 ✗ tidak boleh regenerate
                                 ✗ tombol tambah/ubah/hapus pada tabel utama padam
                                 ✓ koreksi hanya lewat Penyesuaian Kebijakan
```

**Kapan halaman membaca arsip, kapan membaca data hidup:**

| Keadaan | Sumber |
|---|---|
| `?snapshot=<id>` | versi itu (untuk melihat versi lama) |
| Tahun **terkunci** (final) | snapshot final |
| Selain itu | **data hidup**, persis seperti sebelum fitur ini ada |

Snapshot berstatus *draft* sengaja **tidak** membajak tampilan — kalau ia dipakai begitu dibuat, operator akan bingung mengapa angka berhenti mengikuti perubahan yang baru saja disimpan. Yang mengunci tampilan adalah **finalisasi**, dan itu tindakan sadar.

**Tidak ada tombol "buka kunci".** Menyediakannya sama saja dengan membatalkan seluruh jaminan invariant 6.

### 5.3 Penyesuaian Kebijakan

```
Angka LAKIP perlu berubah karena kebijakan
                │
      ┌─────────┴──────────┐
      │                    │
 hanya untuk          semestinya berlaku
 tahun ini            juga tahun berikutnya
      │                    │
      ▼                    ▼
 Penyesuaian         [Usulkan sebagai Perubahan IKU]
 LAKIP                      │
 (dasar kebijakan           ▼
  + alasan WAJIB)     membuat DRAFT revisi IKU
      │                     │
      │              IKU aktif TIDAK berubah
      │              sampai draft disahkan
      ▼
 bila dibuat setelah finalisasi
 → setelah_final = 1 otomatis,
   tidak bisa dimatikan dari form
```

`nilai_asli` **dibekukan** saat penyesuaian dibuat, bukan dibaca ulang saat ditampilkan — kalau tidak, kolom "sebelum" ikut bergerak setiap kali sumbernya berubah dan perbandingan sebelum/sesudah jadi tidak berarti.

---

## 6. Pemetaan invariant & test case ke implementasi

| # | Invariant | Ditegakkan di | Cara |
|---|---|---|---|
| 1 | Revision lifecycle; draft bukan sumber LAKIP | `IkuRevisiModel::resolveEfektif()` | `whereIn(status, ['berlaku','superseded'])` — draft & batal tak pernah lolos |
| 2 | Single effective revision | **UNIQUE index DB** + `resolveEfektif()` | generated column `berlaku_key`; >1 kandidat → galat administratif, bukan pilih diam-diam |
| 3 | Single active LAKIP snapshot | **UNIQUE index DB** + `siapkan()` | generated column `aktif_key`; `siapkan()` melempar bila sudah ada |
| 4 | Semantic indicator change | `jenis_perubahan` + `indikator_sebelumnya_id` + `perubahan_substansial` | `pengganti` tanpa lineage **ditolak**; `perubahan_substansial` memutus tren |
| 5 | LAKIP adjustment governance | `LakipPenyesuaianModel::usulkanRevisiIku()` | hanya membuat **draft**; tak ada jalur yang mengubah IKU aktif |
| 6 | Final LAKIP lock | `sinkronkan()` + `lakipCanWrite` | sinkron ditolak saat final; tombol pengubah padam; `setelah_final` dicatat |
| 7 | Transaction safety | `App\Models\Concerns\TransaksiAman` | lihat catatan di bawah |
| 8 | Referential safety | `IkuModel::deleteComplete()` / `hapusAtauPensiunkanIndikator()` | pensiun bila dirujuk sejarah, hapus hanya bila belum pernah dirujuk |

### Catatan invariant 7 — dua jebakan CodeIgniter yang ditangani

Keduanya diverifikasi langsung di `vendor/`:

1. **Transaksi bersarang itu palsu.** `BaseConnection::transBegin()` baris 833–837: bila `transDepth > 0` ia **hanya menaikkan penghitung**, tanpa SAVEPOINT. Pasangannya juga tidak melakukan ROLLBACK sungguhan. → `TransaksiAman` **menolak** berjalan di dalam transaksi lain, alih-alih berpura-pura aman.
2. **`$transStatus` itu lengket.** `transBegin()` hanya me-reset `$transFailure`, bukan `$transStatus`. Satu query gagal di awal request membuat **semua** transaksi berikutnya dilaporkan gagal. → status di-reset tepat setelah transaksi terluar dibuka.

### Test case

| Case | Hasil |
|---|---|
| 11 — Multiple revision conflict | ✅ ditolak PHP (pesan jelas) **dan** ditolak engine (`ERROR 1062`) |
| 12 — Duplicate snapshot | ✅ tidak membuat snapshot kedua; ditawarkan Lihat / Bandingkan / Sinkronkan |
| 13 — Failed transaction | ✅ rollback penuh; penonaktifan versi lama ikut dibatalkan; operasi berikutnya tetap jalan |
| 14 — Replacement indicator | ✅ A dipensiunkan (target lamanya utuh), B lahir dengan lineage A→B, LAKIP 2091 lihat A / 2092 lihat B |
| 15 — Adjustment promotion | ✅ hanya draft yang dibuat; IKU berlaku tidak berubah; usulan kedua ditolak |

---

## 7. Cara memasang & menguji

```bash
# 1. Terapkan skema (idempoten — aman dijalankan ulang)
mysql -u root test_sakip < db/update_2026-08-18_iku_revisi_lakip_snapshot.sql

# 2. Jalankan uji penerimaan Case 11-15
php spark revisi:verify
```

Keluaran yang diharapkan: **LULUS: 52   GAGAL: 0**

Perintah `revisi:verify` memakai data uji pada periode 2090–2094 tingkat kabupaten, lalu membersihkannya sendiri. Termasuk **penjaga regresi** yang memastikan IKU periode lain tidak ikut terpensiun.

> Berbeda dengan `spark dash:verify`, perintah ini **tidak** membungkus seluruh uji dalam satu transaksi lalu rollback — karena yang sedang diuji justru transaksinya sendiri.

---

## 8. Daftar berkas

### Baru

| Berkas | Isi |
|---|---|
| `db/update_2026-08-18_iku_revisi_lakip_snapshot.sql` | skema + seed permission (idempoten) |
| `app/Database/Migrations/2026-08-18-000001_CreateIkuRevisiAndLakipSnapshot.php` | kembaran migration untuk server bersih |
| `app/Models/Concerns/TransaksiAman.php` | pembungkus transaksi yang benar-benar bisa di-rollback |
| `app/Models/Opd/IkuRevisiModel.php` | lifecycle, resolver, pembekuan, penerapan, pensiun |
| `app/Models/LakipSnapshotModel.php` | siapkan / sinkronkan / finalkan / bandingkan / rehidrasi |
| `app/Models/LakipPenyesuaianModel.php` | penyesuaian + usulan revisi IKU |
| `app/Controllers/Concerns/IkuRevisiTrait.php` | aksi revisi, dipakai dua controller IKU |
| `app/Controllers/Concerns/LakipSnapshotTrait.php` | aksi snapshot & penyesuaian, dipakai dua controller LAKIP |
| `app/Commands/RevisiSnapshotVerify.php` | `php spark revisi:verify` |
| `app/Views/templates/shell_atas.php`, `shell_bawah.php` | cangkang halaman bersama lintas role |
| `app/Views/iku/revisi_index.php`, `revisi_form.php`, `revisi_lihat.php`, `revisi_sunting.php` | UI revisi IKU |
| `app/Views/lakip/snapshot_panel.php`, `snapshot_banding.php` | UI snapshot & penyesuaian |

### Diubah

| Berkas | Perubahan |
|---|---|
| `app/Models/Opd/IkuModel.php` | `getMatrix()` & `petaIkuTerpasang()` menyembunyikan baris pensiun; `updateComplete()` / `deleteComplete()` / `deleteIndikator()` → pensiun-bila-dirujuk |
| `app/Controllers/AdminKab/LakipController.php` | `use LakipSnapshotTrait`; `index`/`cetak`/`cetakExcel` sadar snapshot; `lakipCanWrite` padam saat terkunci |
| `app/Controllers/AdminOpd/LakipOpdController.php` | idem, dengan `sumberLakipOpd()` yang mengulang pengelompokan & rekey memakai fungsi yang sama |
| `app/Controllers/AdminKab/IkuController.php` | `use IkuRevisiTrait` + lingkup kabupaten |
| `app/Controllers/AdminOpd/IkuController.php` | `use IkuRevisiTrait` + lingkup OPD dari **session** |
| `app/Config/Routes.php` | 30 rute baru (adminkab & adminopd) |
| `app/Views/adminKabupaten/lakip/lakip.php`, `app/Views/adminOpd/lakip/lakip.php` | menyisipkan `lakip/snapshot_panel` |
| `app/Views/templates/admin_menu.php` | butir menu **Revisi IKU** |

### Permission baru

`iku_kab.revisi`, `iku_kab.revisi_sahkan`, `iku_opd.revisi`, `iku_opd.revisi_sahkan`,
`lakip_kab.snapshot`, `lakip_kab.finalisasi`, `lakip_kab.penyesuaian`,
`lakip_opd.snapshot`, `lakip_opd.finalisasi`, `lakip_opd.penyesuaian`

Diberikan ke `admin_kab` (tingkat kabupaten) dan `admin_opd` + `admin_kecamatan` (tingkat OPD).
Role read-only **`bupati` dan `admin_inspektorat` sengaja tidak diberi satu pun** — tombolnya tidak pernah muncul dan aksinya tetap ditolak walau URL-nya ditebak. Grup rute `/bupati` juga tidak menerima satu pun rute POST baru (dijaga `ReadOnlyRoleFilter`).

---

## 9. Jaminan backward-compatibility

Diverifikasi setelah seluruh perubahan diterapkan:

| Pemeriksaan | Hasil |
|---|---|
| Jumlah baris `iku_sasaran` / `iku_indikator` / `iku_target` / `iku_program` / `lakip` | **75 / 97 / 499 / 251 / 214** — identik dengan sebelum migrasi |
| Baris dengan penanda baru terisi | **0** |
| Indikator terpensiun di data nyata | **0** |
| SQL dijalankan dua kali berturut-turut | tidak ada galat (idempoten) |
| Lint PHP seluruh berkas berubah/baru | bersih |
| Render ketujuh view baru | bersih |
| `php spark routes` | 30 rute baru terdaftar dengan filter yang benar |

Filter `dihentikan_pada IS NULL` tidak menyaring apa pun pada data yang sudah ada, karena kolom itu `NULL` untuk seluruh 97 indikator — dan hanya bisa terisi lewat fitur baru.

---

## 10. Temuan sampingan (di luar lingkup, tidak diubah)

Ditemukan saat verifikasi, dilaporkan apa adanya karena berdampak pada integritas data:

1. **`app/Config/Database.php:42` menyetel `strictOn => false`.** CodeIgniter lalu **membuang `STRICT_TRANS_TABLES` dari sesi koneksinya**, sehingga data yang melebihi panjang kolom **dipotong diam-diam** oleh aplikasi walaupun klien `mysql` menolaknya. Kode baru di sini tidak bergantung pada mode ketat, tetapi ini berlaku untuk seluruh modul.

2. **111 dari 214 baris `lakip` yatim** (kedua kolom target `NULL`) dan sudah tidak pernah muncul di laporan mana pun.

3. **`hitungCapaianLakip()` punya tiga definisi berbeda** — layar dan PDF/Excel sudah menampilkan persentase berbeda untuk capaian >200% atau negatif. Sengaja **tidak** diseragamkan, karena menyeragamkannya akan mengubah keluaran yang sudah berjalan.

4. **`DashboardModel`, `DashboardOpdModel`, `DashboardKabupatenModel` adalah dead code** (tidak direferensikan di mana pun kecuali autoload classmap); `DashboardModel` bahkan mengquery tabel `lakip_opd` & `lakip_kabupaten` yang tidak ada di basis data. Dashboard yang benar-benar dipakai adalah `KabupatenDashboardService` & `OpdDashboardService`, dan keduanya **tidak menyentuh** `iku_*` sama sekali serta hanya membaca kolom `status` dari `lakip` — karena itu dashboard tidak perlu diubah.

---

## 11. Yang belum dikerjakan

- **RPJMD & Renstra belum punya mekanisme revisi.** Sesuai keterangan bahwa frekuensinya jauh lebih kecil, hanya IKU yang digarap. Pola arsip di sini bisa ditiru bila kelak diperlukan.
- **Penanda penyesuaian belum ditampilkan pada baris tabel utama LAKIP.** Penyesuaian sudah tersimpan, tampil di panel, dan ikut jadi bagian riwayat; menempelkan penanda per baris menuntut penyuntingan kedua view cetak yang saat ini sengaja dibiarkan utuh.
- **Grafik/dashboard belum memutus garis tren** pada `perubahan_substansial`. Datanya sudah tersedia (`iku_indikator.perubahan_substansial` dan `lakip_snapshot_baris.perubahan_substansial`); yang belum ada adalah pemakainya, sebab dashboard aktif memang tidak membangun time-series antar tahun per indikator IKU.
