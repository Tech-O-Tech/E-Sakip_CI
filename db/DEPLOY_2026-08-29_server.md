# Urutan eksekusi di server — 29 Agustus 2026

Seluruh langkah di bawah **sudah diuji** pada salinan `backup-29 agustus.sql`
yang dimuat ke basis data sementara, bukan diperkirakan dari pembacaan kode.
Angka sebelum/sesudahnya nyata.

Keadaan awal server (terukur):

    sasaran IKU OPD    84, bersilsilah   5
    indikator IKU OPD 109, bersilsilah 102
    indikator IKU KAB  11, bersilsilah   8
    cascading        1413, berjangkar IKU 1335   <-- 78 baris tanpa jangkar

Keadaan sesudah seluruh langkah (terukur):

    sasaran IKU OPD   100, bersilsilah  98
    indikator IKU OPD 130, bersilsilah 125
    indikator IKU KAB  11, bersilsilah  10
    cascading        1413, berjangkar IKU 1413   <-- LENGKAP
    casc:akar-check  113 LULUS / 0 GAGAL
    casc:versi-check   9 LULUS / 0 GAGAL

---

## 0. CADANGKAN DULU

    mysqldump -u USER -p NAMA_DB > backup_sebelum_deploy_29agustus.sql

Tanpa ini, tidak ada jalan mundur. Jangan lewati.

---

## 1. SKRIP SQL — jalankan BERURUTAN

Semuanya idempoten: aman diulang, dan yang sudah pernah dijalankan akan
melapor "dilewati" alih-alih menggandakan pekerjaan.

    1. db/perbaiki_2026-08-28_teks_renstra_pringsewu.sql
    2. db/perbaiki_2026-08-28_iku_kembar_setwan.sql
    3. db/perbaiki_2026-08-28_sasaran_iku_bapperida.sql
    4. db/perbaiki_2026-08-28_iku_pringsewu_samakan_renstra.sql
    5. db/perbaiki_2026-08-28_iku_kembar_sisa_sync.sql
    6. db/perbaiki_2026-08-28_sisa_tunggal_indikator_iku.sql
    7. db/update_2026-08-28_silsilah_sasaran_iku_opd.sql      <-- silsilah 5 -> 82
    8. db/update_2026-08-28_silsilah_iku_kabupaten.sql
    9. db/update_2026-08-29_silsilah_iku_kab_akronim.sql      <-- kabupaten 8 -> 10
   10. db/update_2026-08-29_sasaran_iku_mandiri.sql           <-- KOLOM BARU + FK
   11. db/update_2026-08-29_tandai_indikator_iku_mandiri.sql  <-- lindungi IKP

Nomor 1-6 hampir tidak mengubah apa pun di server Anda — koreksi itu sudah
Anda kerjakan manual di sana. Dijalankan tetap, supaya tidak ada yang
terlewat bila ternyata ada sisa.

Nomor 11 SENGAJA hanya menyentuh lingkup kabupaten. Versi tanpa pembatasan
itu menandai TUJUH indikator pada salinan server Anda, enam di antaranya milik
OPD dan bukan satu pun yang benar-benar mandiri. Lihat bagian 4.

---

## 2. PASANG KODE BARU

Deploy seluruh perubahan aplikasi. Urutannya boleh setelah langkah 1;
kodenya dijaga `fieldExists`, jadi ia tetap berjalan pada basis data yang
belum dimigrasi.

---

## 3. SYNC EMPAT KECAMATAN — WAJIB, JANGAN DILEWATI

Ini langkah yang paling mudah terlupa dan paling terasa akibatnya.

78 baris cascading di server belum punya jangkar IKU, karena empat kecamatan
ini punya indikator Renstra yang belum pernah ditarik ke IKU:

    Kecamatan Adiluwih   5 indikator belum di IKU
    Kecamatan Banyumas   6
    Kecamatan Pagelaran  6
    Kecamatan Pringsewu  4

Kode sekarang membaca cascading dari IKU. Baris tanpa jangkar TIDAK AKAN
MUNCUL di matriks — pekerjaan Eselon III/IV mereka akan tampak hilang.

Lewat CLI (paling cepat, periode dibatasi supaya periode nyasar tidak ikut):

    php spark iku:sync-renstra --opd 35 --periode 2025-2029 --fix
    php spark iku:sync-renstra --opd 36 --periode 2025-2029 --fix
    php spark iku:sync-renstra --opd 37 --periode 2025-2029 --fix
    php spark iku:sync-renstra --opd 31 --periode 2025-2029 --fix

Periksa dulu id OPD-nya di server Anda:

    SELECT id, nama_opd FROM opd
     WHERE nama_opd IN ('Kecamatan Adiluwih','Kecamatan Banyumas',
                        'Kecamatan Pagelaran','Kecamatan Pringsewu');

Atau minta tiap operator kecamatan menekan tombol **Sync dari Renstra**.
Hasilnya sama.

---

## 4. JANGKAR ULANG — SETELAH langkah 3

    db/update_2026-08-27_cascading_sumber_iku.sql

Dijalankan SESUDAH sync, bukan sebelumnya: penjangkaran bergantung pada
silsilah indikator yang baru dibuat sync. Pada uji coba, langkah ini membawa
jangkar dari 1365 menjadi 1413 dari 1413 — lengkap.

---

## 5. PERIKSA HASILNYA

    php spark casc:akar-check     # harus: LULUS 113, GAGAL 0, baris hilang 0
    php spark casc:versi-check    # harus: LULUS 9, GAGAL 0

Dan satu kueri yang paling menentukan — hasilnya HARUS 0:

    SELECT COUNT(*) AS baris_tanpa_jangkar
      FROM cascading_sasaran_opd
     WHERE iku_indikator_id IS NULL;

---

## 6. YANG PERLU KEPUTUSAN ANDA — bukan langkah otomatis

Enam indikator IKU milik OPD tidak punya padanan di Renstra dan TIDAK
ditandai mandiri oleh skrip nomor 11. Skrip itu akan menampilkannya di
bagian "indikator OPD yang SENGAJA DILEWATI", lengkap dengan kolom
`ada_padanan_persis_di_renstra`:

  * Dua di antaranya punya padanan PERSIS di Renstra. Menekan Sync pada OPD
    itu akan menautkannya — itu jalan yang benar.
  * Empat sisanya perlu Anda putuskan: benar-benar berdiri sendiri, atau
    redaksinya menyimpang dari Renstra dan perlu dirapikan.

Selama belum diputuskan, keenamnya tetap muncul di daftar "tidak ada padanan"
pada layar Sync. Mode Ganti akan menawarkan membuangnya — TETAPI selalu lewat
pratinjau yang menyebut namanya satu per satu, jadi tidak ada yang terhapus
diam-diam.

---

## 7. TIDAK PERLU DIJALANKAN

    db/hapus_2026-08-30_realisasi_anggaran_warisan.sql
    db/update_2026-08-29_periode_iku_nyasar.sql

Keduanya menyentuh persoalan lain (warisan monev dan periode Renstra nyasar
Kec. Banyumas 2026-2030 / Kec. Pringsewu 2029-2033) yang belum kita bahas dan
belum Anda putuskan.
