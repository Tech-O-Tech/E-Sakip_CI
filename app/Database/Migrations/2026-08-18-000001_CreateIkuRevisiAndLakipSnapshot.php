<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * REVISI IKU + SNAPSHOT TAHUNAN LAKIP + PENYESUAIAN KEBIJAKAN.
 *
 * Kembaran SQL langsungnya (yang dipakai di server yang skemanya sudah
 * menyimpang dari migration): db/update_2026-08-18_iku_revisi_lakip_snapshot.sql
 * Berkas itulah yang memuat penjelasan panjang tiap keputusan desain.
 *
 * ---------------------------------------------------------------------
 * MENGAPA SQL MENTAH, BUKAN Forge
 *
 * Dua invariant paling keras dijamin oleh UNIQUE index di atas GENERATED
 * COLUMN, dan CI4 Forge tidak bisa mendeklarasikan generated column:
 *
 *   iku_revisi.berlaku_key    = 1 bila status 'berlaku', selain itu NULL
 *   lakip_snapshot.aktif_key  = 1 bila aktif,            selain itu NULL
 *
 * Karena MySQL mengabaikan NULL pada UNIQUE index, kolom-kolom itu membuat
 * "hanya boleh ada satu yang berlaku/aktif" menjadi hukum basis data, bukan
 * sekadar sopan santun aplikasi. Dua revisi berlaku untuk tahun efektif yang
 * sama, atau dua snapshot aktif untuk tahun yang sama, ditolak engine dengan
 * error 1062 — bahkan bila ada jalur kode yang lupa memeriksa.
 *
 * `opd_key` = COALESCE(opd_id, 0) ada karena IKU tingkat kabupaten memakai
 * opd_id NULL, dan MySQL menganggap dua NULL selalu berbeda; tanpa kolom itu
 * UNIQUE-nya tidak akan pernah mengikat untuk tingkat kabupaten.
 * ---------------------------------------------------------------------
 *
 * Idempoten: setiap langkah memeriksa keberadaan tabel/kolom/indeks dulu,
 * sehingga aman dijalankan di server yang sudah memakai berkas .sql di atas.
 */
class CreateIkuRevisiAndLakipSnapshot extends Migration
{
    public function up()
    {
        foreach ($this->tabelBaru() as $nama => $ddl) {
            if (! $this->db->tableExists($nama)) {
                $this->db->query($ddl);
            }
        }

        foreach ($this->kolomTambahan() as [$tabel, $kolom, $ddl]) {
            $this->tambahKolom($tabel, $kolom, $ddl);
        }

        foreach ($this->indeksTambahan() as [$tabel, $indeks, $ddl]) {
            $this->tambahIndeks($tabel, $indeks, $ddl);
        }

        foreach ($this->fkTambahan() as [$tabel, $nama, $ddl]) {
            $this->tambahFk($tabel, $nama, $ddl);
        }

        $this->semaiPermission();
    }

    /**
     * Sengaja TIDAK menghapus kolom tambahan pada iku_sasaran / iku_indikator.
     *
     * Kolom `dihentikan_pada` & `indikator_sebelumnya_id` memuat sejarah yang
     * tidak bisa dibangun ulang dari mana pun. Menurunkan migration ini
     * membuang tabel revisi & snapshot (isinya memang milik fitur ini), tapi
     * penanda pada tabel live dibiarkan — sejalan dengan invariant 8.
     */
    public function down()
    {
        foreach ([
            'lakip_penyesuaian',
            'lakip_snapshot_program',
            'lakip_snapshot_analisis',
            'lakip_snapshot_baris',
            'lakip_snapshot',
            'iku_revisi_program',
            'iku_revisi_target',
            'iku_revisi_indikator',
            'iku_revisi_sasaran',
        ] as $tabel) {
            $this->forge->dropTable($tabel, true);
        }

        // iku_sasaran/iku_indikator menunjuk iku_revisi, jadi FK-nya dilepas
        // dulu sebelum tabel induknya bisa dibuang.
        $this->lepasFk('iku_sasaran', 'fk_iku_sasaran_revisi');
        $this->lepasFk('iku_indikator', 'fk_iku_indikator_revisi');

        $this->forge->dropTable('iku_revisi', true);
    }

    /* =========================================================
     * DDL
     * =======================================================*/

    /** @return array<string, string> [nama tabel => DDL] */
    private function tabelBaru(): array
    {
        return [
            'iku_revisi' => "
                CREATE TABLE `iku_revisi` (
                  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `opd_id`               INT UNSIGNED NULL COMMENT 'NULL = IKU tingkat kabupaten',
                  `tahun_mulai`          INT NOT NULL,
                  `tahun_akhir`          INT NOT NULL,
                  `nomor`                INT NOT NULL DEFAULT 0 COMMENT '0 = kondisi awal (baseline)',
                  `nama`                 VARCHAR(255) NOT NULL,
                  `dasar_hukum`          VARCHAR(255) NULL,
                  `nomor_dasar`          VARCHAR(150) NULL,
                  `tanggal_dasar`        DATE NULL,
                  `berlaku_mulai_tahun`  INT NOT NULL,
                  `berlaku_sampai_tahun` INT NULL,
                  `status`               VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft | berlaku | superseded | batal',
                  `catatan`              TEXT NULL,
                  `dibuat_oleh`          INT UNSIGNED NULL,
                  `disahkan_oleh`        INT UNSIGNED NULL,
                  `disahkan_pada`        DATETIME NULL,
                  `dibekukan_pada`       DATETIME NULL,
                  `created_at`           DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at`           DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  `opd_key`     INT UNSIGNED AS (COALESCE(`opd_id`, 0)) STORED,
                  `berlaku_key` TINYINT AS (CASE WHEN `status` = 'berlaku' THEN 1 ELSE NULL END) STORED,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_iku_revisi_efektif` (`opd_key`, `tahun_mulai`, `tahun_akhir`, `berlaku_mulai_tahun`, `berlaku_key`),
                  UNIQUE KEY `uq_iku_revisi_nomor`   (`opd_key`, `tahun_mulai`, `tahun_akhir`, `nomor`),
                  KEY `idx_iku_revisi_opd`     (`opd_id`),
                  KEY `idx_iku_revisi_scope`   (`opd_key`, `tahun_mulai`, `tahun_akhir`, `status`),
                  KEY `idx_iku_revisi_efektif` (`status`, `berlaku_mulai_tahun`, `berlaku_sampai_tahun`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ",

            'iku_revisi_sasaran' => "
                CREATE TABLE `iku_revisi_sasaran` (
                  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `revisi_id`         INT UNSIGNED NOT NULL,
                  `sumber_sasaran_id` INT UNSIGNED NULL,
                  `sasaran`           TEXT NOT NULL,
                  `tahun_mulai`       INT NOT NULL,
                  `tahun_akhir`       INT NOT NULL,
                  `urutan`            INT NOT NULL DEFAULT 0,
                  `jenis_perubahan`   VARCHAR(20) NOT NULL DEFAULT 'tetap',
                  `catatan_perubahan` TEXT NULL,
                  `created_at`        DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at`        DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_ikurev_sasaran_revisi` (`revisi_id`, `urutan`),
                  KEY `idx_ikurev_sasaran_sumber` (`sumber_sasaran_id`),
                  CONSTRAINT `fk_ikurev_sasaran_revisi` FOREIGN KEY (`revisi_id`)
                    REFERENCES `iku_revisi` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ",

            'iku_revisi_indikator' => "
                CREATE TABLE `iku_revisi_indikator` (
                  `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `revisi_id`               INT UNSIGNED NOT NULL,
                  `revisi_sasaran_id`       INT UNSIGNED NOT NULL,
                  `sumber_indikator_id`     INT UNSIGNED NULL,
                  `indikator`               TEXT NOT NULL,
                  `definisi`                TEXT NULL,
                  `rumusan_perhitungan`     TEXT NULL,
                  `satuan`                  VARCHAR(50) NULL,
                  `satuan_nama`             VARCHAR(150) NULL COMMENT 'hasil terjemahan satuan saat dibekukan',
                  `sumber_data`             TEXT NULL,
                  `penanggung_jawab`        VARCHAR(255) NULL,
                  `jenis_indikator`         VARCHAR(100) NULL,
                  `baseline`                VARCHAR(50) NULL,
                  `urutan`                  INT NOT NULL DEFAULT 0,
                  `status`                  VARCHAR(20) NOT NULL DEFAULT 'draft',
                  `jenis_perubahan`         VARCHAR(20) NOT NULL DEFAULT 'tetap' COMMENT 'tetap | revisi | pengganti | baru | dihentikan',
                  `indikator_sebelumnya_id` INT UNSIGNED NULL COMMENT 'wajib bila jenis_perubahan = pengganti',
                  `perubahan_substansial`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = tren antar tahun tidak boleh disambung',
                  `catatan_perubahan`       TEXT NULL,
                  `created_at`              DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at`              DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_ikurev_ind_revisi`  (`revisi_id`, `urutan`),
                  KEY `idx_ikurev_ind_sasaran` (`revisi_sasaran_id`, `urutan`),
                  KEY `idx_ikurev_ind_sumber`  (`sumber_indikator_id`),
                  KEY `idx_ikurev_ind_lineage` (`indikator_sebelumnya_id`),
                  CONSTRAINT `fk_ikurev_ind_revisi` FOREIGN KEY (`revisi_id`)
                    REFERENCES `iku_revisi` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                  CONSTRAINT `fk_ikurev_ind_sasaran` FOREIGN KEY (`revisi_sasaran_id`)
                    REFERENCES `iku_revisi_sasaran` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ",

            'iku_revisi_target' => "
                CREATE TABLE `iku_revisi_target` (
                  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `revisi_indikator_id` INT UNSIGNED NOT NULL,
                  `tahun`               INT NOT NULL,
                  `target`              VARCHAR(100) NULL,
                  `target_sebelumnya`   VARCHAR(100) NULL,
                  `created_at`          DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at`          DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_ikurev_target` (`revisi_indikator_id`, `tahun`),
                  CONSTRAINT `fk_ikurev_target_ind` FOREIGN KEY (`revisi_indikator_id`)
                    REFERENCES `iku_revisi_indikator` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ",

            'iku_revisi_program' => "
                CREATE TABLE `iku_revisi_program` (
                  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `revisi_indikator_id` INT UNSIGNED NOT NULL,
                  `program`             TEXT NOT NULL,
                  `urutan`              INT NOT NULL DEFAULT 0,
                  `created_at`          DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_ikurev_program_ind` (`revisi_indikator_id`, `urutan`),
                  CONSTRAINT `fk_ikurev_program_ind` FOREIGN KEY (`revisi_indikator_id`)
                    REFERENCES `iku_revisi_indikator` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ",

            'lakip_snapshot' => "
                CREATE TABLE `lakip_snapshot` (
                  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `tahun`                YEAR NOT NULL,
                  `mode`                 VARCHAR(12) NOT NULL COMMENT 'kabupaten (RPJMD) | opd (RENSTRA)',
                  `opd_id`               INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = tingkat kabupaten',
                  `versi`                INT NOT NULL DEFAULT 1,
                  `label`                VARCHAR(255) NULL,
                  `status`               VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft | final',
                  `aktif`                TINYINT(1) NOT NULL DEFAULT 1,
                  `sumber_iku_revisi_id` INT UNSIGNED NULL,
                  `jumlah_baris`         INT NOT NULL DEFAULT 0,
                  `filter_status`        VARCHAR(50) NULL COMMENT 'filter status LAKIP saat dibekukan',
                  `catatan`              TEXT NULL,
                  `dibuat_oleh`          INT UNSIGNED NULL,
                  `dibuat_pada`          DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                  `disinkronkan_oleh`    INT UNSIGNED NULL,
                  `disinkronkan_pada`    DATETIME NULL,
                  `difinalkan_oleh`      INT UNSIGNED NULL,
                  `difinalkan_pada`      DATETIME NULL,
                  `updated_at`           DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  `aktif_key` TINYINT AS (CASE WHEN `aktif` = 1 THEN 1 ELSE NULL END) STORED,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_lakip_snapshot_aktif` (`tahun`, `mode`, `opd_id`, `aktif_key`),
                  UNIQUE KEY `uq_lakip_snapshot_versi` (`tahun`, `mode`, `opd_id`, `versi`),
                  KEY `idx_lakip_snapshot_lingkup` (`tahun`, `mode`, `opd_id`, `status`),
                  KEY `idx_lakip_snapshot_revisi`  (`sumber_iku_revisi_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ",

            // Sengaja TANPA foreign key ke renstra_target/rpjmd_target/lakip:
            // arsip yang ikut terhapus saat sumbernya dihapus bukan arsip.
            'lakip_snapshot_baris' => "
                CREATE TABLE `lakip_snapshot_baris` (
                  `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `snapshot_id`             INT UNSIGNED NOT NULL,
                  `urutan`                  INT NOT NULL DEFAULT 0,
                  `sumber`                  VARCHAR(12) NOT NULL COMMENT 'renstra | rpjmd',
                  `renstra_target_id`       INT UNSIGNED NULL COMMENT 'penunjuk penelusuran, tanpa FK',
                  `rpjmd_target_id`         INT UNSIGNED NULL,
                  `lakip_id`                INT UNSIGNED NULL,
                  `opd_id`                  INT UNSIGNED NOT NULL DEFAULT 0,
                  `nama_opd`                VARCHAR(255) NULL,
                  `sasaran_id`              INT UNSIGNED NULL,
                  `sasaran`                 TEXT NULL,
                  `indikator_id`            INT UNSIGNED NULL,
                  `indikator`               TEXT NULL,
                  `satuan`                  VARCHAR(150) NULL COMMENT 'sudah diterjemahkan, bukan id',
                  `jenis_indikator`         VARCHAR(100) NULL,
                  `target`                  VARCHAR(255) NULL,
                  `target_hitung`           VARCHAR(255) NULL,
                  `target_lalu`             VARCHAR(255) NULL,
                  `capaian_lalu`            VARCHAR(255) NULL,
                  `realisasi`               VARCHAR(255) NULL,
                  `capaian_hitung`          VARCHAR(255) NULL,
                  `status_lakip`            VARCHAR(50) NULL,
                  `iku_indikator_id`        INT UNSIGNED NULL,
                  `iku_revisi_indikator_id` INT UNSIGNED NULL,
                  `perubahan_substansial`   TINYINT(1) NOT NULL DEFAULT 0,
                  `created_at`              DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_snapbaris_snapshot` (`snapshot_id`, `urutan`),
                  KEY `idx_snapbaris_renstra`  (`renstra_target_id`),
                  KEY `idx_snapbaris_rpjmd`    (`rpjmd_target_id`),
                  KEY `idx_snapbaris_iku`      (`iku_indikator_id`),
                  CONSTRAINT `fk_snapbaris_snapshot` FOREIGN KEY (`snapshot_id`)
                    REFERENCES `lakip_snapshot` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ",

            // Tabel terpisah, BUKAN kolom pada lakip_snapshot_baris: satu indikator
            // boleh punya BANYAK baris analisis, sama seperti lakip_analisis_faktor.
            'lakip_snapshot_analisis' => "
                CREATE TABLE `lakip_snapshot_analisis` (
                  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `snapshot_id`        INT UNSIGNED NOT NULL,
                  `snapshot_baris_id`  INT UNSIGNED NOT NULL,
                  `urutan`             INT NOT NULL DEFAULT 0,
                  `sumber_analisis_id` INT UNSIGNED NULL,
                  `faktor_pendukung`   TEXT NULL,
                  `faktor_penghambat`  TEXT NULL,
                  `upaya_peningkatan`  TEXT NULL,
                  `created_at`         DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_snapanalisis_snapshot` (`snapshot_id`, `urutan`),
                  KEY `idx_snapanalisis_baris`    (`snapshot_baris_id`, `urutan`),
                  CONSTRAINT `fk_snapanalisis_snapshot` FOREIGN KEY (`snapshot_id`)
                    REFERENCES `lakip_snapshot` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                  CONSTRAINT `fk_snapanalisis_baris` FOREIGN KEY (`snapshot_baris_id`)
                    REFERENCES `lakip_snapshot_baris` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ",

            'lakip_snapshot_program' => "
                CREATE TABLE `lakip_snapshot_program` (
                  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `snapshot_id` INT UNSIGNED NOT NULL,
                  `urutan`      INT NOT NULL DEFAULT 0,
                  `program_id`  INT UNSIGNED NULL COMMENT 'program_pk.id, penunjuk saja tanpa FK',
                  `program`     TEXT NULL,
                  `anggaran`    DECIMAL(20,2) NOT NULL DEFAULT 0,
                  `realisasi`   DECIMAL(20,2) NOT NULL DEFAULT 0,
                  `efisiensi`   DECIMAL(20,2) NOT NULL DEFAULT 0,
                  `created_at`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_snapprogram_snapshot` (`snapshot_id`, `urutan`),
                  CONSTRAINT `fk_snapprogram_snapshot` FOREIGN KEY (`snapshot_id`)
                    REFERENCES `lakip_snapshot` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ",

            'lakip_penyesuaian' => "
                CREATE TABLE `lakip_penyesuaian` (
                  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `tahun`             YEAR NOT NULL,
                  `mode`              VARCHAR(12) NOT NULL,
                  `opd_id`            INT UNSIGNED NOT NULL DEFAULT 0,
                  `renstra_target_id` INT UNSIGNED NULL,
                  `rpjmd_target_id`   INT UNSIGNED NULL,
                  `snapshot_id`       INT UNSIGNED NULL,
                  `snapshot_baris_id` INT UNSIGNED NULL,
                  `jenis`             VARCHAR(20) NOT NULL COMMENT 'target | realisasi | satuan | indikator | lainnya',
                  `nilai_asli`        VARCHAR(255) NULL COMMENT 'dibekukan saat penyesuaian dibuat',
                  `nilai_disesuaikan` VARCHAR(255) NULL,
                  `dasar_kebijakan`   VARCHAR(255) NOT NULL,
                  `nomor_dasar`       VARCHAR(150) NULL,
                  `tanggal_dasar`     DATE NULL,
                  `alasan`            TEXT NOT NULL,
                  `setelah_final`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = dibuat setelah snapshot difinalkan',
                  `usul_revisi_iku`   TINYINT(1) NOT NULL DEFAULT 0,
                  `iku_revisi_id`     INT UNSIGNED NULL COMMENT 'draft revisi IKU yang lahir dari usulan ini',
                  `aktif`             TINYINT(1) NOT NULL DEFAULT 1,
                  `dibuat_oleh`       INT UNSIGNED NULL,
                  `created_at`        DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at`        DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  `target_key` INT UNSIGNED AS (COALESCE(`renstra_target_id`, `rpjmd_target_id`, 0)) STORED,
                  `aktif_key`  TINYINT AS (CASE WHEN `aktif` = 1 THEN 1 ELSE NULL END) STORED,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_lakip_penyesuaian_aktif` (`tahun`, `mode`, `opd_id`, `target_key`, `jenis`, `aktif_key`),
                  KEY `idx_penyesuaian_lingkup`  (`tahun`, `mode`, `opd_id`),
                  KEY `idx_penyesuaian_snapshot` (`snapshot_id`),
                  KEY `idx_penyesuaian_revisi`   (`iku_revisi_id`),
                  CONSTRAINT `fk_penyesuaian_revisi` FOREIGN KEY (`iku_revisi_id`)
                    REFERENCES `iku_revisi` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ",
        ];
    }

    /** @return array<int, array{0:string,1:string,2:string}> [tabel, kolom, DDL] */
    private function kolomTambahan(): array
    {
        return [
            ['iku_sasaran', 'revisi_id',
                "ALTER TABLE `iku_sasaran` ADD COLUMN `revisi_id` INT UNSIGNED NULL COMMENT 'revisi terakhir yang mengubah baris ini' AFTER `urutan`"],
            ['iku_sasaran', 'berlaku_sampai',
                "ALTER TABLE `iku_sasaran` ADD COLUMN `berlaku_sampai` INT NULL COMMENT 'tahun terakhir dipakai; NULL = masih berlaku' AFTER `revisi_id`"],
            ['iku_sasaran', 'dihentikan_pada',
                "ALTER TABLE `iku_sasaran` ADD COLUMN `dihentikan_pada` DATETIME NULL COMMENT 'terisi = dipensiunkan, bukan dihapus' AFTER `berlaku_sampai`"],
            ['iku_sasaran', 'alasan_dihentikan',
                "ALTER TABLE `iku_sasaran` ADD COLUMN `alasan_dihentikan` TEXT NULL AFTER `dihentikan_pada`"],

            ['iku_indikator', 'revisi_id',
                "ALTER TABLE `iku_indikator` ADD COLUMN `revisi_id` INT UNSIGNED NULL COMMENT 'revisi terakhir yang mengubah baris ini' AFTER `status`"],
            ['iku_indikator', 'indikator_sebelumnya_id',
                "ALTER TABLE `iku_indikator` ADD COLUMN `indikator_sebelumnya_id` INT UNSIGNED NULL COMMENT 'indikator yang digantikan baris ini (lineage)' AFTER `revisi_id`"],
            ['iku_indikator', 'jenis_perubahan',
                "ALTER TABLE `iku_indikator` ADD COLUMN `jenis_perubahan` VARCHAR(20) NOT NULL DEFAULT 'tetap' COMMENT 'tetap | revisi | pengganti | baru | dihentikan' AFTER `indikator_sebelumnya_id`"],
            ['iku_indikator', 'perubahan_substansial',
                "ALTER TABLE `iku_indikator` ADD COLUMN `perubahan_substansial` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = tren antar tahun tidak boleh disambung' AFTER `jenis_perubahan`"],
            ['iku_indikator', 'berlaku_sampai',
                "ALTER TABLE `iku_indikator` ADD COLUMN `berlaku_sampai` INT NULL COMMENT 'tahun terakhir dipakai; NULL = masih berlaku' AFTER `perubahan_substansial`"],
            ['iku_indikator', 'dihentikan_pada',
                "ALTER TABLE `iku_indikator` ADD COLUMN `dihentikan_pada` DATETIME NULL COMMENT 'terisi = dipensiunkan, bukan dihapus' AFTER `berlaku_sampai`"],
            ['iku_indikator', 'alasan_dihentikan',
                "ALTER TABLE `iku_indikator` ADD COLUMN `alasan_dihentikan` TEXT NULL AFTER `dihentikan_pada`"],
        ];
    }

    /** @return array<int, array{0:string,1:string,2:string}> [tabel, indeks, DDL] */
    private function indeksTambahan(): array
    {
        return [
            ['iku_sasaran', 'idx_iku_sasaran_aktif',
                "ALTER TABLE `iku_sasaran` ADD KEY `idx_iku_sasaran_aktif` (`dihentikan_pada`)"],
            ['iku_indikator', 'idx_iku_indikator_aktif',
                "ALTER TABLE `iku_indikator` ADD KEY `idx_iku_indikator_aktif` (`dihentikan_pada`)"],
            ['iku_indikator', 'idx_iku_indikator_lineage',
                "ALTER TABLE `iku_indikator` ADD KEY `idx_iku_indikator_lineage` (`indikator_sebelumnya_id`)"],
        ];
    }

    /** @return array<int, array{0:string,1:string,2:string}> [tabel, nama FK, DDL] */
    private function fkTambahan(): array
    {
        return [
            // ON DELETE SET NULL, bukan CASCADE: kalau indikator lama toh
            // terhapus, penggantinya harus tetap hidup.
            ['iku_indikator', 'fk_iku_indikator_sebelumnya',
                "ALTER TABLE `iku_indikator` ADD CONSTRAINT `fk_iku_indikator_sebelumnya` FOREIGN KEY (`indikator_sebelumnya_id`) REFERENCES `iku_indikator` (`id`) ON DELETE SET NULL ON UPDATE CASCADE"],
            ['iku_sasaran', 'fk_iku_sasaran_revisi',
                "ALTER TABLE `iku_sasaran` ADD CONSTRAINT `fk_iku_sasaran_revisi` FOREIGN KEY (`revisi_id`) REFERENCES `iku_revisi` (`id`) ON DELETE SET NULL ON UPDATE CASCADE"],
            ['iku_indikator', 'fk_iku_indikator_revisi',
                "ALTER TABLE `iku_indikator` ADD CONSTRAINT `fk_iku_indikator_revisi` FOREIGN KEY (`revisi_id`) REFERENCES `iku_revisi` (`id`) ON DELETE SET NULL ON UPDATE CASCADE"],
        ];
    }

    /* =========================================================
     * PERMISSION
     * =======================================================*/

    /**
     * Role read-only (bupati, admin_inspektorat) SENGAJA tidak diberi satu pun
     * permission di sini.
     */
    private function semaiPermission(): void
    {
        if (! $this->db->tableExists('permissions') || ! $this->db->tableExists('role_permissions')) {
            return;
        }

        $daftar = [
            ['iku_kab.revisi',        'IKU Kabupaten - Buat & Ubah Revisi',              'Kabupaten'],
            ['iku_kab.revisi_sahkan', 'IKU Kabupaten - Sahkan Revisi',                   'Kabupaten'],
            ['iku_opd.revisi',        'IKU OPD - Buat & Ubah Revisi',                    'OPD'],
            ['iku_opd.revisi_sahkan', 'IKU OPD - Sahkan Revisi',                         'OPD'],
            ['lakip_kab.snapshot',    'LAKIP Kabupaten - Siapkan & Sinkronkan Snapshot', 'Kabupaten'],
            ['lakip_kab.finalisasi',  'LAKIP Kabupaten - Finalkan / Kunci Tahun',        'Kabupaten'],
            ['lakip_kab.penyesuaian', 'LAKIP Kabupaten - Penyesuaian Kebijakan',         'Kabupaten'],
            ['lakip_opd.snapshot',    'LAKIP OPD - Siapkan & Sinkronkan Snapshot',       'OPD'],
            ['lakip_opd.finalisasi',  'LAKIP OPD - Finalkan / Kunci Tahun',              'OPD'],
            ['lakip_opd.penyesuaian', 'LAKIP OPD - Penyesuaian Kebijakan',               'OPD'],
        ];

        foreach ($daftar as [$nama, $label, $grup]) {
            if ($this->db->table('permissions')->where('name', $nama)->countAllResults() === 0) {
                $this->db->table('permissions')->insert([
                    'name' => $nama, 'label' => $label, 'grup' => $grup,
                ]);
            }
        }

        $pemetaan = [
            'admin_kab'       => ['iku_kab.revisi', 'iku_kab.revisi_sahkan',
                                  'lakip_kab.snapshot', 'lakip_kab.finalisasi', 'lakip_kab.penyesuaian'],
            'admin_opd'       => ['iku_opd.revisi', 'iku_opd.revisi_sahkan',
                                  'lakip_opd.snapshot', 'lakip_opd.finalisasi', 'lakip_opd.penyesuaian'],
            'admin_kecamatan' => ['iku_opd.revisi', 'iku_opd.revisi_sahkan',
                                  'lakip_opd.snapshot', 'lakip_opd.finalisasi', 'lakip_opd.penyesuaian'],
        ];

        foreach ($pemetaan as $role => $daftarIzin) {
            $roleRow = $this->db->table('roles')->select('id')->where('name', $role)->get()->getRowArray();
            if (! $roleRow) {
                continue;
            }

            foreach ($daftarIzin as $nama) {
                $izin = $this->db->table('permissions')->select('id')->where('name', $nama)->get()->getRowArray();
                if (! $izin) {
                    continue;
                }

                $sudah = $this->db->table('role_permissions')
                    ->where('role_id', (int) $roleRow['id'])
                    ->where('permission_id', (int) $izin['id'])
                    ->countAllResults();

                if ($sudah === 0) {
                    $this->db->table('role_permissions')->insert([
                        'role_id'       => (int) $roleRow['id'],
                        'permission_id' => (int) $izin['id'],
                    ]);
                }
            }
        }
    }

    /* =========================================================
     * PEMERIKSA IDEMPOTENSI
     * =======================================================*/

    private function tambahKolom(string $tabel, string $kolom, string $ddl): void
    {
        if (! $this->db->tableExists($tabel)) {
            return;
        }

        if (! in_array($kolom, $this->db->getFieldNames($tabel), true)) {
            $this->db->query($ddl);
        }
    }

    private function tambahIndeks(string $tabel, string $indeks, string $ddl): void
    {
        if (! $this->db->tableExists($tabel)) {
            return;
        }

        $ada = $this->db->query(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$tabel, $indeks]
        )->getRowArray();

        if (! $ada) {
            $this->db->query($ddl);
        }
    }

    private function tambahFk(string $tabel, string $nama, string $ddl): void
    {
        if (! $this->db->tableExists($tabel)) {
            return;
        }

        $ada = $this->db->query(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1",
            [$tabel, $nama]
        )->getRowArray();

        if (! $ada) {
            $this->db->query($ddl);
        }
    }

    private function lepasFk(string $tabel, string $nama): void
    {
        if (! $this->db->tableExists($tabel)) {
            return;
        }

        $ada = $this->db->query(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1",
            [$tabel, $nama]
        )->getRowArray();

        if ($ada) {
            $this->db->query("ALTER TABLE `{$tabel}` DROP FOREIGN KEY `{$nama}`");
        }
    }
}
