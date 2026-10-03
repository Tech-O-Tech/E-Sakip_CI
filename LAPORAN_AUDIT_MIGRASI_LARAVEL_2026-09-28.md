# Laporan Audit & Katalog Migrasi E-SAKIP — CodeIgniter 4 → Laravel 12

**Tanggal audit**: 2026-09-28
**Repo sumber (CI4)**: `C:\diskominfo\E-Sakip_CI`
**Repo target (Laravel 12)**: `C:\diskominfo\e-sakip-laravel`
**Metodologi**: analisis `git log` CI4 sejak checkpoint migrasi terakhir (11 Juni 2026, 200 commit berikutnya), dibaca per klaster modul (4 riset paralel), disilangkan dengan struktur model/controller/migrasi Laravel saat ini, dan dipetakan ke kerangka SAKIP KemenPAN-RB resmi (acuan `db/RELASI_SAKIP.md`).

---

## 0. Ringkasan Eksekutif

Proyek Laravel dibangun dari snapshot CI4 per **11 Juni 2026** dan sempat dicatat "migrasi selesai". Sejak saat itu CI4 terus dikembangkan aktif (200 commit, 80+ file controller/model tersentuh, 11 file skema SQL baru untuk LAKIP saja) dan menambahkan **13 sub-sistem** yang sekarang sebagian besar **tidak ada** di Laravel:

| # | Sub-sistem | Status di Laravel | Kompleksitas | Prioritas migrasi |
|---|---|---|---|---|
| 1 | **IKU OPD** — skema standalone (`iku_sasaran/indikator/target/program`) | ❌ Masih skema lama (`ikus` + FK langsung ke rpjmd/renstra) | Tinggi | **Tinggi** (fondasi) |
| 2 | **Cascading** — level Pelaksana, 3-mode (Kabupaten/OPD/Keseluruhan), keterkaitan Program PK | ⚠️ Sebagian (CRUD dasar ada, fitur lanjutan tidak) | Tinggi | **Tinggi** (fondasi) |
| 3 | **PK Rencana Aksi (Renaksi)** & realisasi anggaran per-unit | ❌ Tidak ada sama sekali | Sangat tinggi (2225 baris controller) | **Tinggi** (operasional harian) |
| 4 | **Dashboard eksekutif** — mode Bupati, mode Fokus-OPD, threshold capaian | ⚠️ Sebagian (dashboard dasar ada, jauh lebih dangkal) | Sedang | **Tinggi** |
| 5 | Sistem **versioning dokumen** generik (`dokumen_versi` + arsip per modul) | ❌ Tidak ada | Sangat tinggi (±2700 baris + 14 service) | Sedang* |
| 6 | **Revisi IKU** (`iku_revisi`) | ❌ Tidak ada (bergantung pada #1) | Tinggi | Sedang* |
| 7 | Siklus lanjutan **LAKIP** (pengesahan/penyesuaian/analisis/efisiensi/benchmark) | ❌ Tidak ada | Tinggi | Sedang* |
| 8 | **Verifikasi**/approval workflow versi dokumen | ❌ Tidak ada | Tinggi | Sedang* |
| 9 | **Two-Factor Authentication** | ⚠️ Config & migrasi Fortify sudah siap, tinggal 1 trait | Sangat rendah | **Cepat** (quick win) |
| 10 | **Activity Log** | ❌ Tidak ada | Rendah (paket siap pakai) | Rendah |
| 11 | **AI Analysis** (Gemini, narasi Cascading) | ❌ Tidak ada | Rendah–sedang | Rendah |
| 12 | **App Settings** (Pengaturan Aplikasi) | ❌ Tidak ada | Rendah | Rendah |
| 13 | **SIMPEG/SIKASN sync** | ⚠️ Ada, tapi lebih rapuh (hanya sync pegawai, skip diam-diam) | Sedang | Sedang |

\* #5–#8 saling bergantung erat — lihat §4.1–§4.3. Sebaiknya dirancang ulang sebagai satu paket koheren, bukan diporting satu-satu.

**Catatan penting soal role**: Laravel saat ini hanya punya 3 role Spatie (`super_admin`, `admin_kab`, `admin_opd`). CI4 sudah menambah role `bupati` (read-only, dashboard eksekutif sendiri), `admin_kecamatan`, dan `admin_inspektorat` (read-only lintas-OPD) — ini prasyarat untuk migrasi item #4 dan beberapa bagian §4.3/§4.4.

---

## 1. Kerangka Acuan SAKIP KemenPAN-RB

Rantai akuntabilitas kinerja resmi: **Perencanaan → Penetapan → Pengukuran → Pelaporan → Evaluasi**.

| Tahap | Dokumen SAKIP | Modul Aplikasi | Regulasi Acuan |
|---|---|---|---|
| Perencanaan strategis daerah | RPJMD / RPD | **RPJMD** | Permendagri 90/2019 jo. Kepmendagri 050-3708/2020 |
| Perencanaan strategis PD | Renstra Perangkat Daerah | **Renstra** | PermenPAN-RB 89/2021 |
| Penjabaran kinerja | Pohon Kinerja / Cascading (Es. II→III→IV/JF→Pelaksana) | **Cascading** | Panduan Pohon Kinerja KemenPAN-RB |
| Indikator utama | Indikator Kinerja Utama (IKU) | **IKU** | PermenPAN 09/2007; PermenPAN-RB 88/2021 |
| Penetapan kinerja | Perjanjian Kinerja (PK) + Rencana Aksi | **PK + PK Renaksi** | PermenPAN-RB 89/2021 |
| Pengukuran | Pengukuran kinerja / MONEV | **Target & MONEV** | PermenPAN-RB 88/2021 |
| Pelaporan | Laporan Kinerja (LKj/LAKIP) | **LAKIP** (+ siklus lanjutan) | PermenPAN-RB 53/2014 jo. 88/2021 |

Prinsip kunci yang **wajib dijaga** saat migrasi: **keselarasan vertikal** (RPJMD → Renstra → Pohon Kinerja → PK) dan **keterukuran** (tiap indikator punya satuan, baseline, target tahunan yang diukur di LAKIP).

> ⚠️ **Peringatan staleness**: `db/RELASI_SAKIP.md` (sumber tabel di atas) bertanggal 2026-06-28 dan masih menyebut tabel `iku` tunggal. Sejak **2026-07-27**, IKU sudah menjadi modul standalone (`iku_sasaran/indikator/target/program`, lihat §4.1) — dokumen itu perlu pembaruan, jangan dipakai sebagai acuan skema IKU terkini.

### Istilah internal proyek vs istilah resmi SAKIP

| Istilah di aplikasi | Padanan resmi / konteks | Catatan kepatuhan |
|---|---|---|
| Snapshot LAKIP | *(tidak ada di Permenpan)* | Solusi teknis integritas data historis — **sudah nonaktif**, digantikan Pengesahan. Pertahankan kode hanya untuk baca arsip lama. |
| Pengesahan LAKIP | Finalisasi/pengesahan laporan kinerja | Mekanisme kunci-tahun **aktif saat ini**. Nama permission masih `lakip_kab.finalisasi`/`lakip_opd.finalisasi` — tidak sinkron dengan istilah UI "Pengesahan", **rename saat migrasi**. |
| Penyesuaian Kebijakan LAKIP | Koreksi/revisi capaian akibat kebijakan baru | Wajar, sejenis adendum laporan resmi. |
| Analisis Faktor (`lakip_analisis_faktor`) | "Analisis Capaian Kinerja" (faktor pendukung/penghambat + strategi) | ✅ **Sesuai** Permenpan RB 53/2014. |
| Efisiensi Program (`lakip_efisiensi_program`) | "Analisis efisiensi penggunaan sumber daya" | ✅ Sesuai secara konsep. Nilai efisiensi **diinput manual**, bukan dihitung otomatis dari (anggaran−realisasi) — keputusan desain, bukan turunan aturan; **putuskan ulang eksplisit saat migrasi**. |
| Benchmark Provinsi/Nasional | *(tidak ada di Permenpan)* | Praktik baik tambahan, bukan penyimpangan. |
| Revisi IKU | Penetapan/reviu IKU berkala | Selaras semangat PermenPAN-RB 88/2021 tentang reviu IKU periodik. |
| PK Rencana Aksi (Renaksi) | Rencana Aksi atas Perjanjian Kinerja | ✅ Istilah umum breakdown PK jadi langkah terukur per triwulan. |

---

## 2. Struktur Database — Tabel Baru/Berubah Sejak Juni 2026

### 2.1 Tabel benar-benar baru (tidak ada padanan sama sekali di Laravel)

| Domain | Tabel |
|---|---|
| IKU standalone | `iku_sasaran`, `iku_indikator`, `iku_target`, `iku_program` |
| Revisi IKU | `iku_revisi`, `iku_revisi_sasaran`, `iku_revisi_indikator`, `iku_revisi_target`, `iku_revisi_program` |
| Versioning generik | `dokumen_versi`, `version_submission_history`, `version_correction_requests` |
| Arsip isi versi | `rpjmd_versi_misi/tujuan/indikator_tujuan/target_tujuan/sasaran/indikator_sasaran`, `renstra_versi_tujuan/indikator_tujuan/target_tujuan/sasaran/indikator_sasaran` |
| PK Renaksi | `target_sub_rencana`, `monev_anggaran`, `pk_sasaran_opd` |
| LAKIP lanjutan | `lakip_snapshot` (+`_baris/_program/_analisis`), `lakip_pengesahan`, `lakip_buka_permintaan`, `lakip_penyesuaian`, `lakip_analisis_faktor`, `lakip_efisiensi_program`, `lakip_benchmark`, `lakip_dokumen` |
| Keamanan/admin | `activity_logs`, `dashboard_status_thresholds`, `app_settings` |

### 2.2 Tabel yang skemanya berubah (perlu penyesuaian migrasi Laravel, bukan sekadar tambah tabel baru)

| Tabel | Perubahan | Dampak |
|---|---|---|
| `cascading_sasaran_opd.level` | ENUM ditambah `'pelaksana'` (Laravel masih `es2,es3,es4` saja) | Jenjang ke-7 Cascading tidak bisa disimpan di Laravel |
| `target` | + kolom `pk_indikator_id` (jangkar baru dari PK, terpisah dari `renstra_target_id`/`rpjmd_target_id`) | Prasyarat PK Renaksi |
| `monev` | + kolom `target_sub_rencana_id` (0=legacy, >0=per sub-rencana), UNIQUE `(target_rencana_id, target_sub_rencana_id)` | Prasyarat realisasi per sub-rencana |
| `iku_sasaran`/`iku_indikator` | + `dihentikan_pada`, `revisi_id`, `indikator_sebelumnya_id` (self-FK) | Prasyarat Revisi IKU |
| `users` | + `two_factor_secret`, `two_factor_enabled` | **Sudah ada di migrasi Laravel** — tinggal diaktifkan (§5.1) |

### 2.3 Tabel yang ditinggalkan tapi dipertahankan di CI4 (jangan diporting sebagai tabel aktif)

`iku` + `iku_program_pendukung` (skema IKU lama, digantikan §2.1) — di Laravel justru **inilah yang masih dipakai** (`app/Models/Iku.php`). Migrasi `align_iku_with_ci` di Laravel bahkan mendokumentasikan pola lama ini sebagai "mengikuti CI4" — sudah usang, CI4 sendiri sudah pindah sejak 27 Juli 2026.

---

## 3. Baseline Laravel Saat Ini

- **39 model**, **25 controller** (flat, tanpa folder `AdminKab/AdminOpd/Bupati` seperti CI4), **15 service**, **26 file migrasi** (terakhir 11 Juni 2026).
- Pola arsitektur yang **sudah disepakati dan harus dipertahankan** untuk modul baru: thin **Controller → Form Request → Service → Eloquent Model**. Role via **Spatie laravel-permission**. Auth via **Laravel Fortify**.
- Repo Laravel punya banyak file `modified` belum di-commit dari sesi sebelumnya — verifikasi status commit sebelum melanjutkan pekerjaan baru di atasnya.

---

## 4. Katalog Terperinci per Modul

### 4.1 Sistem Versioning Dokumen (`dokumen_versi` + `iku_revisi`)

Dua rezim versi yang **terpisah tapi saling menaut**: (A) `iku_revisi_*` — khusus IKU, lebih tua; (B) `dokumen_versi` — registri generik lintas modul (RPJMD/Renstra/IKU/LAKIP), lebih baru; `iku_revisi` ditautkan ke `dokumen_versi` via `ref_id`, **bukan digantikan**.

**A. `iku_revisi`** (Model `App\Models\Opd\IkuRevisiModel`, 3862 baris)
- Kolom kunci: `opd_id` (NULL=kabupaten), `tahun_mulai/akhir`, `nomor` (0=baseline), `status` (draft|menunggu|berlaku|superseded|batal).
- **Invariant dijamin level DB**: generated column `opd_key`=`COALESCE(opd_id,0)` STORED + `berlaku_key` (1 jika status='berlaku') STORED → `UNIQUE uq_iku_revisi_efektif` menjamin maksimal 1 revisi 'berlaku' per tahun-mulai per lingkup. `UNIQUE uq_iku_revisi_nomor` menjamin nomor revisi unik per lingkup.
- Anak tabel: `iku_revisi_sasaran`, `iku_revisi_indikator` (dengan lineage `indikator_sebelumnya_id`, `jenis_perubahan`: tetap|revisi|pengganti|baru|dihentikan), `iku_revisi_target`, `iku_revisi_program`.
- Method model kunci: `resolveEfektif($opdId,$tahun)` (mengembalikan konflik eksplisit, tidak memilih diam-diam), `buatDraft()`/`buatDraftInti()` (transaksional), `pastikanBaseline()`/`pulihkanBaseline()`, `simpanSuntinganDraft()`, `penghalangHapus()`, `hapusRevisi()`.
- Trait `IkuRevisiTrait` (1606 baris, dipakai bersama AdminKab & AdminOpd `IkuController`): alur *Daftar → Buat Draft → Sunting → Sahkan → Batalkan*. Kebijakan 23-Sep-2026: pemegang `revisi_sahkan` kini boleh sunting langsung versi berlaku/superseded (bukan hanya draft) — **catat sebagai keputusan produk yang perlu dikonfirmasi ulang**, bukan diasumsikan final saat migrasi.

**B. `dokumen_versi`** (Model `App\Models\DokumenVersiModel`, 842 baris) — registri generik
- Kolom kunci: `modul` (rpjmd|renstra|iku|lakip), `scope`, `opd_id`, `effective_from`/`effective_to` (interval setengah-terbuka), `status` (draft|pending_approval|published|cancelled), `source_type`+`source_version_id` (**polimorfik — wajib difilter `source_type` saat menghitung rujukan**, bug historis yang sudah diperbaiki).
- Generated columns `opd_key`/`scope_key`/`published_key`/`terbuka_key` → 3 UNIQUE index menjamin tepat satu versi "terbuka" per dokumen.
- Method kunci: `kandidatEfektif()` (interval setengah-terbuka), `publishedUrutMaju(..., $kunci=true)` (pakai `FOR UPDATE` cegah race saat publish), `tetapkanTampilanUtama()` (keputusan tampilan terpisah dari fakta hukum dokumen), `penghalangHapus()` (6 kategori rujukan).
- Tabel pendamping: `version_submission_history` (audit append-only, FK RESTRICT), `version_correction_requests` (koreksi non-substantif pasca-publish).
- Trait `DokumenVersiTrait` (1740 baris, dipakai RPJMD & Renstra Controller): alur *Buat Versi (deep-copy) → Sunting → Ajukan → Tetapkan → Koreksi*. Permission eksplisit per-aksi (`<modul>.version.{view|create|update_draft|submit|publish|pin}`) — sengaja tidak mengandalkan filter berbasis substring URL.

**C. Arsip isi per modul**: `ArsipVersiModel` abstrak → `RpjmdVersiModel` (936 baris), `RenstraVersiModel`. FK **CASCADE ke `dokumen_versi`** (beda filosofi dari `iku_revisi_*` yang sengaja tanpa FK ke sumber live).

**Status Laravel**: ❌ **Tidak ada sama sekali** — nol hasil grep untuk `dokumen_versi|iku_revisi|rpjmd_versi|renstra_versi` di seluruh repo Laravel.

**Gotcha migrasi**:
1. `source_type`+`source_version_id` polimorfik → gunakan Eloquent `morphTo` eksplisit per tipe, jangan replikasi string-matching implisit (sumber bug historis di CI4).
2. Invariant di-*enforce* via generated column + composite UNIQUE, bukan validasi aplikasi — migrasi Laravel **wajib** mereplikasi kolom STORED generated ini persis (Laravel migration mendukung `->storedAs()` sejak Laravel 8).
3. Race pada publish butuh `lockForUpdate()` Eloquent + transaksi yang sama membungkus perhitungan ulang timeline, bukan hanya UNIQUE check.
4. Volume: ±2700+ baris model/trait inti + ~14 service class `App\Services\Version\*` yang **belum diaudit detail** — audit lanjutan disarankan sebelum estimasi effort final.

---

### 4.2 Siklus Lanjutan LAKIP

Dibangun bertahap lewat 11 file migrasi SQL (Jul–Sep 2026), plus skrip remediasi 111/214 baris `lakip` yatim akibat FK `ON DELETE SET NULL` (bukti drift historis nyata, bukan teoretis).

| Sub-modul | Model | Fungsi |
|---|---|---|
| `lakip_snapshot(+baris/program/analisis)` | `LakipSnapshotModel` | Membekukan bahan mentah LAKIP per tahun (bukan angka jadi), agar perubahan rumus tak menggeser arsip lama. **Sudah nonaktif** (endpoint tulis ditutup, digantikan Pengesahan) — pertahankan kode hanya untuk baca 2 snapshot draft 2025 yang tersisa. |
| `lakip_pengesahan` + `lakip_buka_permintaan` | `LakipPengesahanModel` | **Mekanisme kunci tahun aktif sekarang**: OPD `sahkan()` → terkunci → `ajukanPembukaan()` → admin_kab `setujui()`/`tolak()` → jika disetujui, OPD perbaiki → `sahkan()` ulang. Tanpa versi/salinan, hanya state + riwayat. |
| `lakip_penyesuaian` | `LakipPenyesuaianModel` | Koreksi per-tahun dengan `dasar_kebijakan`/`alasan` wajib. UNIQUE via generated column — 1 penyesuaian aktif per lingkup+target+jenis. Fitur "Usulkan sebagai Perubahan IKU" hanya buat draft revisi IKU terpisah. |
| `lakip_analisis_faktor` | `LakipAnalisisModel` | Faktor pendukung/penghambat + upaya peningkatan, per target (hanya satu FK terisi: renstra/rpjmd/iku_indikator). ✅ Sesuai Permenpan RB 53/2014. |
| `lakip_efisiensi_program` | `LakipEfisiensiModel` | Efisiensi **anggaran** (bukan waktu): `anggaran`/`realisasi`/`efisiensi` — nilai efisiensi **input manual**, bukan dihitung otomatis. OPD hampir selalu punya `program_pk.opd_id` NULL (207/209 baris) → kepemilikan dijangkau lewat rantai PK. |
| `lakip_benchmark` | `LakipBenchmarkModel`/`LakipBenchmarkTrait` | Nilai pembanding Provinsi Lampung & Nasional per indikator+tahun, izin terpisah (`lakip_benchmark.manage*`) karena OPD tak boleh ubah angka pembanding sendiri. |
| `lakip_dokumen` | `LakipDokumenModel`/`LakipSumberTrait` | Binding dokumen sumber (IKU vs RPJMD/Renstra + versi) untuk LAKIP Kabupaten; larangan ganti sumber setelah ada realisasi. |

**Verifikasi ulang catatan lama**: `hitungCapaianLakip` memang punya 3 definisi (helper + 2 view), **sudah disamakan sejak 23-Sep-2026** (pemotongan 200% dicabut dari view, kini hanya dipasang di rata-rata `LakipKabupatenCapaianService::ringkasan()`). `strictOn=false` terkonfirmasi di `app/Config/Database.php` — ini **setting global koneksi**, bukan spesifik LAKIP.

**Status Laravel**: ❌ Hanya ada `app/Models/Lakip.php` (tabel `lakips`, setara versi CI4 **sebelum** Juni 2026) + `LakipController.php` dasar. Nol match untuk `snapshot|pengesahan|penyesuaian|benchmark|efisiensi|analisis_faktor|lakip_dokumen`.

**Catatan kecil**: nama permission tetap `*.finalisasi` walau mekanisme aktual sekarang "Pengesahan" — perbaiki penamaan saat migrasi (lihat §1).

---

### 4.3 PK Rencana Aksi (Renaksi), Cascading, IKU OPD

Klaster paling berat — perubahan terbanyak di CI4 sejak Juni 2026.

**A. PK Renaksi** (Controller `PkRenaksiController`, 2225 baris, paling sering berubah — 24×)
- Skema: `target.pk_indikator_id` (jangkar baru, terpisah dari jalur Renstra/RPJMD lama) → `target_sub_rencana` (tiap butir rencana aksi 1 baris, `target_triwulan_1..4` sendiri) → `monev.target_sub_rencana_id` (0=legacy/level-indikator, >0=per sub; UNIQUE `(target_rencana_id, target_sub_rencana_id)`) → `monev_anggaran` (realisasi anggaran **per-unit** via `ref_level` program/kegiatan/subkegiatan + `ref_id` ke tabel MASTER, generated `ref_key`="{level}:{id}" sebagai UNIQUE).
- `pk_sasaran_opd`: override manual PD pendukung per Sasaran PK Bupati (menggantikan hasil pencocokan otomatis Cascading).
- `pk.jenis` ∈ {bupati, jpt, camat, administrator, pengawas} menentukan level unit anggaran: bupati/jpt/camat→Program, administrator→Kegiatan, pengawas→Sub Kegiatan.
- Model: `TargetModel` (962 baris) + `MonevModel` (1112 baris) — jalur PK-khusus paralel dengan jalur lama: `getTargetListByPkBupati/Opd`, `simpanDenganSub/perbaruiDenganSub` (induk+sub 1 transaksi), `upsertAnggaranBatch` (validasi lebih-pagu per unit).
- Controller: alur *index → tambah/save (anti-duplikat) → edit/update (sub berdata dilindungi exception `SubBermonev`) → monev (rollup realisasi) → cetak PDF*. RBAC granular per `$jenis`: admin_kab tulis PK Bupati, admin_opd/admin_kecamatan tulis PK OPD, admin_kab & admin_inspektorat baca lintas-OPD, role `bupati` baca-saja semua.
- **Status Laravel**: ❌ **Tidak ada sama sekali**. `Target.php`/`Monev.php` Laravel tak punya kolom terkait; `PkController.php` Laravel hanya CRUD dokumen PK, tak menyentuh rencana aksi/realisasi.

**B. Cascading (Pohon Kinerja)** (Model `CascadingModel`, 2547 baris)
- Skema: `cascading_sasaran_opd(level ENUM('es2','es3','es4','pelaksana'), es3_indikator_id ="indikator induk")` — jenjang Pelaksana (27 Jul 2026) ditambah **tanpa tabel baru**, cukup nilai enum + pola query berulang.
- Fungsi kunci: `getMatrix`/`getPdfMatrix` (backbone IKU Kabupaten), `getCascadingMatrixByOpd` (mode OPD), `getKeseluruhanMatrix` (mode Keseluruhan: RPJMD→seluruh OPD), `getPohonKinerja`, dan **`programPkByEs3`** — mengaitkan Program/Kegiatan PK ke node Cascading ES III **lewat pencocokan teks ternormalisasi**, **tanpa FK sama sekali**. Ini gotcha migrasi paling jelas: **ganti dengan FK asli** (mis. `pk_indikator_id` langsung di `cascading_indikator_opd`) di Laravel, jangan warisi kerapuhannya.
- 3 mode di AdminKab (`?mode=kabupaten|opd|keseluruhan`), tiap mode pakai model+meta-rowspan berbeda. `CascadingOpdMetaTrait` (316 baris) = **satu-satunya sumber** rowspan/pohon untuk 3 pemakai (AdminOpd, AdminKab mode-opd, publik) — sebelumnya tiap controller punya salinan sendiri (bug historis sudah diperbaiki).
- **Status Laravel**: ⚠️ Tabel dasar ada, tapi `level` ENUM hanya `es2,es3,es4` (**tanpa 'pelaksana'**), controller CRUD sederhana per-OPD saja — **tidak ada mode Kabupaten/Keseluruhan, tidak ada `programPkByEs3`, tidak ada meta-trait bersama**. Setara versi CI4 jauh lebih lama.

**C. IKU OPD** (skema standalone sejak 27 Jul 2026, Model `IkuModel`, 2405 baris)
- Skema: `iku_sasaran(opd_id NULL=kabupaten)` → `iku_indikator(status draft/selesai)` → `iku_target(tahun,target)` + `iku_program`. **Satu-satunya FK keluar: `opd_id`→`opd`** — sengaja tidak ber-FK ke renstra/rpjmd lagi (inti perubahannya). Tabel lama `iku`+`iku_program_pendukung` dibiarkan sebagai cadangan, tidak dipakai UI aktif.
- Fitur "sync" (`getKandidatSync`/`importSync`): menarik indikator dari Renstra/RPJMD sebagai **draft awal**, bukan FK permanen. Plus alur revisi & pengesahan (lihat §4.1).
- **Status Laravel**: ❌ **Masih pakai skema lama** — `app/Models/Iku.php` (tabel `ikus`, `rpjmd_id`/`renstra_id` **langsung ber-FK**). Migrasi `align_iku_with_ci` mendokumentasikan pola ini sebagai "mengikuti CI4" — sudah usang.

**Ringkasan prioritas klaster ini**: IKU (fondasi, banyak modul bergantung) → Cascading (dekat kesetaraan struktural, tinggal tambah fitur) → PK Renaksi (nihil total, effort terbesar, tapi paling sering dipakai harian).

---

### 4.4 Keamanan, Dashboard Eksekutif, Admin Lain-lain

**Keamanan**

| Fitur | CI4 | Laravel |
|---|---|---|
| **2FA** | `TwoFactorController` — TOTP custom (`App\Libraries\Totp`), throttle 5/menit, regenerasi sesi | ⚠️ **Fortify sudah aktif & dikonfigurasi** (`Features::twoFactorAuthentication`, rate limiter `login`+`two-factor` sudah ditulis di `FortifyServiceProvider`, migrasi kolom sudah ada) — **tapi `User.php` belum pakai trait `TwoFactorAuthenticatable`**, jadi belum fungsional. **Quick win**: 1 trait + view challenge. **Jangan port TOTP custom CI4.** |
| **Activity Log** | `AdminKab/ActivityLogController` + tabel `activity_logs`, filter+cetak PDF+auto-cleanup | ❌ Tidak ada. Rekomendasi: pakai `spatie/laravel-activitylog` (satu ekosistem dengan `spatie/laravel-permission` yang sudah dipakai), bukan tulis logger manual. |
| Hardening login lain | Session regenerate, throttle brute-force, cek `is_active` per-request, mapping role→dashboard tunggal (audit 17-Sep-2026) | Fortify sudah sediakan rate limiter setara; regenerasi sesi & cek `is_active` per-middleware **perlu diverifikasi eksplisit** ada di Laravel (belum dicek detail — tindak lanjut). |

**Dashboard Eksekutif**
- CI4: `KabupatenDashboardService` dipakai bersama `AdminKabupatenController::dashboard()` **dan** `Bupati\DashboardController::index()` (query identik agar angka tak pernah beda antar role) + `OpdDashboardService`. Mode Kabupaten/Fokus-OPD, endpoint JSON drill-down (IDOR-safe, opd_id selalu divalidasi), `DashboardThresholdModel` (`dashboard_status_thresholds`) sebagai **satu-satunya sumber** rentang warna status capaian (dikonfigurasi Super Admin, bukan hardcode). `Bupati\DashboardController` murni read-only via `ReadOnlyRoleFilter` global.
- Laravel: `DashboardController`+`DashboardService` jauh lebih sederhana (`summaryCards`/`rpjmdStatus`/`rktStatus`/`topOpdByRkt`), **tanpa** mode Fokus-OPD, drill-down JSON, role bupati, atau threshold konfigurasi. **Gap fitur terbesar di klaster ini** — perlu redesain, bukan port langsung.

**Modul admin lain-lain**

| Modul CI4 | Fungsi | Status Laravel |
|---|---|---|
| `VerifikasiController` + `VersionApprovalService` dkk | Antrean verifikasi versi dokumen (draft→pending→approved), izin-sunting, koreksi pasca-sah, diff/banding versi | ❌ Tidak ada — bergantung penuh pada §4.1. |
| `AiAnalysisController` (Gemini) | Analisis naratif AI atas Cascading/Pohon Kinerja | ❌ Tidak ada integrasi LLM apa pun. |
| `PegawaiController`+`PegawaiSyncService`/`SimpegClient` | Sync **4 entitas** (OPD→Pangkat→Jabatan→Pegawai) dari SIMPEG, idempoten, preview, resolusi alias, SSL custom | ⚠️ Ada tapi jauh lebih tipis: hanya sync pegawai, **skip diam-diam** kalau OPD/Jabatan/Pangkat belum ada (bukan upsert 4 entitas seperti CI4) — risiko produksi jika SIMPEG ubah id duluan. |
| `ProgramPkController`/`ProgramPkModel` | Import Excel SIPD Lampiran 8, cakupan per-OPD **atau** "Seluruh OPD" via staging+pemetaan manual | Laravel punya `ProgramController`+`ProgramImportService` (Excel import ada) — cakupan "Seluruh OPD"+staging **belum diverifikasi**, kemungkinan hanya per-OPD sederhana. |
| `SettingController` (Pengaturan Aplikasi) | `app_settings` key-value: nama/instansi/logo/favicon/SEO/API key Gemini | ❌ Tidak ada tabel/controller sama sekali. |
| `ProfileController` | Profil (nama OPD, role label, status 2FA) | Kemungkinan digantikan profil bawaan Fortify — tampilan khas E-SAKIP belum tentu ada. |
| `ChangePasswordController` | Ganti password manual | ✅ Padanan native tersedia (Fortify `UpdateUserPassword` sudah wired) — tidak perlu controller custom. |
| `UserController` (portal publik) + `UserPublicModel` | Data publik non-login | `PublicController.php` ada — cakupan lengkap (RKPD/LAKIP/Cascading/Pohon Kinerja publik) belum diverifikasi mendalam. |
| `Api/PerangkatDaerahController` | REST API publik: OPD/IKU/cascading/pohon kinerja | ✅ Ada padanan method-setara di Laravel — perlu bandingkan shape response bila ada konsumen eksternal. |

---

## 5. Rekomendasi Arsitektur untuk Laravel

1. **Pertahankan pola yang sudah disepakati**: thin Controller → Form Request → Service → Eloquent Model. Semua modul baru di atas mengikuti pola ini, termasuk yang kompleks (versioning, PK Renaksi) — pecah jadi Service kecil per tanggung jawab (mis. `VersionResolverService`, `VersionApprovalService`, `PkRenaksiService`, `MonevAnggaranService`), meniru pemisahan yang sudah ada di `App\Services\Version\*` CI4, bukan satu Service raksasa.
2. **Invariant di level database, bukan hanya validasi aplikasi**: replikasi generated/stored column (`->storedAs()`, didukung sejak Laravel 8) untuk kolom seperti `opd_key`/`aktif_key`/`terbuka_key`, dipasangkan dengan composite UNIQUE index di migration. Jangan pindahkan ke validasi PHP saja — CI4 sengaja menaruhnya di engine untuk mencegah race condition.
3. **Locking eksplisit untuk operasi publish/sahkan**: gunakan `lockForUpdate()` Eloquent di dalam `DB::transaction()`, setara `FOR UPDATE` manual CI4 pada `publishedUrutMaju()`.
4. **Ganti pencocokan teks dengan FK asli**: `programPkByEs3` (Cascading↔Program PK) di Laravel harus pakai kolom FK langsung (mis. `pk_indikator_id` di `cascading_indikator_opd`), bukan `LOWER(TRIM(REGEXP_REPLACE(...)))`.
5. **Polymorphic reference eksplisit**: `dokumen_versi.source_type`+`source_version_id` → Eloquent `morphTo` dengan map tipe eksplisit, bukan string matching implisit (sumber bug historis di CI4).
6. **Pakai paket ekosistem Laravel, jangan port logic custom 1:1**:
   - 2FA → aktifkan `Laravel\Fortify\TwoFactorAuthenticatable` trait (config sudah siap), jangan port `TwoFactorController` TOTP custom.
   - Activity Log → `spatie/laravel-activitylog` (satu ekosistem dengan `spatie/laravel-permission` yang sudah dipakai), jangan tulis logger manual.
7. **Rapikan penamaan permission yang sudah usang**: `lakip_kab.finalisasi`/`lakip_opd.finalisasi` → ganti jadi nama yang mencerminkan mekanisme aktual ("Pengesahan"), jangan warisi sisa nama dari era snapshot.
8. **Putuskan ulang, jangan asumsikan, hal-hal yang di CI4 adalah pilihan desain sengaja**: nilai `lakip_efisiensi_program.efisiensi` manual vs dihitung otomatis; kebijakan 23-Sep-2026 yang melonggarkan sunting versi published langsung oleh pemegang wewenang sahkan — verifikasi ke pemilik produk sebelum diporting sebagai default.
9. **Role & RBAC**: tambahkan role `bupati`, `admin_kecamatan`, `admin_inspektorat` di seeder Spatie sebelum memulai migrasi Dashboard Eksekutif dan PK Renaksi — keduanya bergantung pada role ini untuk filter akses baca/tulis.

---

## 6. Roadmap Migrasi yang Disarankan

**Fase 1 — Fondasi data (blocking modul lain)**
1. IKU OPD skema standalone (§4.3.C) — prasyarat Revisi IKU & Cascading mode Kabupaten.
2. Cascading: tambah level Pelaksana + 3-mode + FK Program PK asli (§4.3.B).
3. Tambah role `bupati`, `admin_kecamatan`, `admin_inspektorat` (Spatie seeder).

**Fase 2 — Operasional harian OPD**
4. PK Renaksi + realisasi per-unit anggaran (§4.3.A) — modul paling sering dipakai.
5. Dashboard eksekutif: role Bupati + mode Fokus-OPD + threshold konfigurasi (§4.4).

**Fase 3 — Governance & kualitas laporan** *(rancang sebagai satu paket, evaluasi ulang kebutuhan sebelum port 1:1)*
6. Sistem versioning dokumen generik (`dokumen_versi`, §4.1.B–C).
7. Revisi IKU (`iku_revisi`, §4.1.A).
8. Siklus lanjutan LAKIP: Pengesahan → Penyesuaian → Analisis Faktor → Efisiensi → Benchmark (§4.2).
9. Verifikasi/approval workflow (§4.4).

**Fase 4 — Quick win & pelengkap**
10. 2FA (aktifkan trait Fortify) — sangat cepat.
11. Activity Log (`spatie/laravel-activitylog`) — cepat.
12. App Settings — cepat.
13. AI Analysis (Gemini) — sedang.
14. Perkuat SIMPEG sync jadi 4-entitas (OPD/Pangkat/Jabatan/Pegawai) sebelum dipakai produksi.

---

## 7. Lampiran — Berkas Kunci per Klaster (referensi cepat)

**Versioning dokumen**: `app/Models/Opd/IkuRevisiModel.php`, `app/Controllers/Concerns/IkuRevisiTrait.php`, `app/Models/DokumenVersiModel.php`, `app/Controllers/Concerns/DokumenVersiTrait.php`, `app/Models/Versi/{RpjmdVersiModel,ArsipVersiModel}.php`, `app/Controllers/Concerns/{RenstraVersiIsiTrait,RenstraSiklusTrait}.php`; skema `db/update_2026-08-{18,20}_*.sql`.

**LAKIP lanjutan**: `app/Models/Lakip{Snapshot,Pengesahan,Penyesuaian,Analisis,Benchmark,Efisiensi,Dokumen}Model.php`, `app/Controllers/Concerns/Lakip{Snapshot,Benchmark,Sumber,Addendum}Trait.php`, `app/Controllers/AdminKab/LakipController.php`, `app/Controllers/AdminOpd/LakipOpdController.php`; skema `db/update_2026-{07-27,08-12,08-18,08-30}_lakip_*.sql`.

**PK Renaksi/Cascading/IKU**: `app/Controllers/AdminOpd/PkRenaksiController.php`, `app/Models/Opd/{TargetModel,MonevModel,IkuModel}.php`, `app/Models/CascadingModel.php`, `app/Controllers/Concerns/{CascadingOpdMetaTrait,IkuFormTrait}.php`, `app/Controllers/Admin{Kab,Opd}/CascadingController.php`, `app/Controllers/Admin{Kab,Opd}/IkuController.php`; skema `db/update_2026-07-27_{iku_standalone,sub_rencana_aksi,sub_rencana_triwulan,monev_per_sub,monev_anggaran,cascading_pelaksana}.sql`, `db/update_2026-08-19_monev_anggaran_per_unit.sql`, `db/update_2026-07-02_pk_sasaran_opd.sql`.

**Keamanan/dashboard/admin**: `app/Controllers/TwoFactorController.php`, `app/Controllers/AdminKab/ActivityLogController.php`, `app/Controllers/AdminKab/VerifikasiController.php`, `app/Controllers/AiAnalysisController.php`, `app/Controllers/Bupati/DashboardController.php`, `app/Models/DashboardThresholdModel.php`, `app/Controllers/{PegawaiController,ProgramPkController,SettingController}.php`; skema `db/update_2026-06-13.sql` (§4 activity_logs, §5 2FA).

**Sisi Laravel dicek**: `app/Models/*.php`, `app/Http/Controllers/**/*.php`, `database/migrations/*.php`, `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`, `composer.json`.

---

*Dokumen ini adalah katalog/audit — belum ada perubahan kode. Gunakan §6 sebagai urutan kerja saat mulai implementasi.*
