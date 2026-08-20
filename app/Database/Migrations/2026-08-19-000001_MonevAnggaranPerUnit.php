<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * REALISASI ANGGARAN MONEV: DARI PER-INDIKATOR MENJADI PER-UNIT.
 *
 * Kembaran SQL langsungnya (dipakai di server yang skemanya sudah menyimpang
 * dari migration): db/update_2026-08-19_monev_anggaran_per_unit.sql —
 * berkas itulah yang memuat penjelasan panjang tiap keputusan desain.
 *
 * ---------------------------------------------------------------------
 * RINGKASAN PERUBAHAN
 *
 *   ref_level ENUM('program','kegiatan','subkegiatan') NULL  -- NULL = warisan
 *   ref_id    INT UNSIGNED NULL                              -- id tabel MASTER
 *   ref_key   VARCHAR(40) GENERATED ... STORED               -- "{level}:{id}"
 *   UNIQUE (target_rencana_id, ref_key)  menggantikan  UNIQUE (target_rencana_id)
 *
 * ---------------------------------------------------------------------
 * MENGAPA SQL MENTAH, BUKAN Forge
 *
 * `ref_key` adalah GENERATED COLUMN dan CI4 Forge tidak bisa mendeklarasikannya
 * sama sekali. Kolom itu bukan hiasan: MySQL tidak pernah mengikat NULL pada
 * UNIQUE index, sehingga UNIQUE (target_rencana_id, ref_level, ref_id) akan
 * meloloskan baris warisan (ref_level & ref_id NULL) berkali-kali. Dengan
 * menerjemahkan NULL menjadi teks ':0', `ref_key` membuat "satu baris warisan
 * per indikator" kembali menjadi hukum basis data, bukan sekadar sopan santun
 * aplikasi.
 *
 * ---------------------------------------------------------------------
 * SIFAT
 *
 * up()   : idempoten & additive — setiap langkah memeriksa keberadaan kolom /
 *          index dulu, jadi aman dijalankan di server yang sudah memakai
 *          berkas .sql di atas. Tidak ada DELETE, tidak ada TRUNCATE; baris
 *          produksi lama bertahan utuh sebagai ref_level NULL.
 * down() : mengembalikan UNIQUE tunggal HANYA bila tidak ada duplikat
 *          target_rencana_id. Kalau sudah ada rincian per unit, rollback
 *          DIHENTIKAN dengan pesan yang jelas — menurunkan skema saat itu
 *          berarti membuang rincian anggaran yang tidak bisa dibangun ulang.
 */
class MonevAnggaranPerUnit extends Migration
{
    private const TABEL     = 'monev_anggaran';
    private const UQ_UNIT   = 'uq_monev_anggaran_unit';
    private const UQ_TARGET = 'uq_monev_anggaran_target';
    private const IDX_REF   = 'idx_monev_anggaran_ref';

    public function up()
    {
        if (! $this->db->tableExists(self::TABEL)) {
            return;
        }

        foreach ($this->kolomTambahan() as [$kolom, $ddl]) {
            $this->tambahKolom($kolom, $ddl);
        }

        // Urutan di bawah ini WAJIB: UNIQUE baru dipasang LEBIH DULU, baru
        // UNIQUE lama dibuang. `fk_monev_anggaran_target` butuh index yang
        // diawali kolom target_rencana_id; membuang yang lama duluan membuat
        // MySQL menolak dengan error 1553.
        $this->tolakBilaGanda();

        $this->tambahIndeks(
            self::UQ_UNIT,
            'ALTER TABLE `' . self::TABEL . '` ADD UNIQUE KEY `' . self::UQ_UNIT . '` (`target_rencana_id`, `ref_key`)'
        );

        $this->lepasUqTargetTunggal();

        $this->tambahIndeks(
            self::IDX_REF,
            'ALTER TABLE `' . self::TABEL . '` ADD KEY `' . self::IDX_REF . '` (`ref_level`, `ref_id`)'
        );
    }

    public function down()
    {
        if (! $this->db->tableExists(self::TABEL)) {
            return;
        }

        // Diperiksa SEBELUM satu pun DDL dijalankan, supaya kalau ditolak,
        // basis datanya tetap dalam keadaan yang sama seperti sebelum rollback.
        $ganda = $this->db->query(
            'SELECT `target_rencana_id` FROM `' . self::TABEL . '`
             GROUP BY `target_rencana_id` HAVING COUNT(*) > 1 LIMIT 1'
        )->getRowArray();

        if ($ganda !== null) {
            throw new RuntimeException(
                'Rollback dibatalkan: monev_anggaran sudah memuat lebih dari satu baris '
                . 'realisasi untuk target_rencana_id ' . $ganda['target_rencana_id'] . '. '
                . 'UNIQUE tunggal tidak bisa dikembalikan tanpa membuang rincian anggaran '
                . 'per unit, dan rincian itu tidak bisa dibangun ulang. Gabungkan dulu '
                . 'baris-baris itu secara manual bila skema lama memang dikehendaki.'
            );
        }

        // Sama seperti pada up(), hanya terbalik: UNIQUE tunggal dipasang dulu
        // supaya foreign key target_rencana_id tidak pernah kehilangan index.
        $this->tambahIndeks(
            self::UQ_TARGET,
            'ALTER TABLE `' . self::TABEL . '` ADD UNIQUE KEY `' . self::UQ_TARGET . '` (`target_rencana_id`)'
        );

        $this->lepasIndeks(self::UQ_UNIT);
        $this->lepasIndeks(self::IDX_REF);

        // ref_key dibuang paling awal: ia generated column yang bersandar pada
        // dua kolom di bawahnya.
        foreach (['ref_key', 'ref_id', 'ref_level'] as $kolom) {
            $this->lepasKolom($kolom);
        }
    }

    /* =========================================================
     * DDL
     * =======================================================*/

    /** @return array<int, array{0:string,1:string}> [kolom, DDL] */
    private function kolomTambahan(): array
    {
        return [
            // Tanpa foreign key, dan itu disengaja: (1) `ref_id` adalah dasar
            // sebuah STORED generated column, dan MySQL menolak FK ber-aksi
            // CASCADE/SET NULL pada kolom semacam itu (error 1215); (2) satu
            // kolom tidak bisa menunjuk tiga tabel master sekaligus — yang
            // menentukan tujuannya justru `ref_level`.
            ['ref_level',
                'ALTER TABLE `' . self::TABEL . "` ADD COLUMN `ref_level` ENUM('program','kegiatan','subkegiatan') NULL "
                . "COMMENT 'tingkat unit anggaran; NULL = baris warisan sebelum dirinci per unit' AFTER `opd_id`"],

            ['ref_id',
                'ALTER TABLE `' . self::TABEL . '` ADD COLUMN `ref_id` INT UNSIGNED NULL '
                . "COMMENT 'id tabel MASTER: program_pk.id | kegiatan_pk.id | sub_kegiatan_pk.id (bukan id tabel jembatan)' AFTER `ref_level`"],

            // "{level}:{id}", mis. 'program:12'. Baris warisan menjadi ':0'.
            ['ref_key',
                'ALTER TABLE `' . self::TABEL . '` ADD COLUMN `ref_key` VARCHAR(40) '
                . "GENERATED ALWAYS AS (CONCAT(COALESCE(`ref_level`, ''), ':', COALESCE(`ref_id`, 0))) STORED "
                . "COMMENT 'kunci unit; nilai '':0'' berarti baris warisan' AFTER `ref_id`"],
        ];
    }

    /**
     * Menolak melanjutkan bila datanya belum memenuhi UNIQUE yang akan dipasang.
     * Lebih berguna daripada error 1062 mentah di tengah ALTER, dan tidak ada
     * satu baris pun yang dihapus untuk "membereskannya".
     */
    private function tolakBilaGanda(): void
    {
        if (! in_array('ref_key', $this->db->getFieldNames(self::TABEL), true)) {
            return;
        }

        $ganda = $this->db->query(
            'SELECT `target_rencana_id`, `ref_key` FROM `' . self::TABEL . '`
             GROUP BY `target_rencana_id`, `ref_key` HAVING COUNT(*) > 1 LIMIT 1'
        )->getRowArray();

        if ($ganda !== null) {
            throw new RuntimeException(
                'monev_anggaran memuat baris ganda pada (target_rencana_id, ref_key) = ('
                . $ganda['target_rencana_id'] . ', ' . $ganda['ref_key'] . '). '
                . 'Rapikan dulu sebelum index unik baru dipasang.'
            );
        }
    }

    /**
     * Membuang UNIQUE tunggal lama pada (target_rencana_id), apa pun namanya.
     *
     * Namanya tidak bisa dipastikan satu: berkas .sql pendahulunya memberi nama
     * `uq_monev_anggaran_target`, sedangkan basis data yang dibangun lewat
     * migration Forge (2026-07-27-000003_CreateMonevAnggaranTable) memakai nama
     * bawaan Forge. Karena itu index dicari berdasarkan BENTUKNYA — unik, satu
     * kolom, kolomnya target_rencana_id.
     */
    private function lepasUqTargetTunggal(): void
    {
        $daftar = $this->db->query(
            "SELECT s.INDEX_NAME
               FROM information_schema.STATISTICS s
              WHERE s.TABLE_SCHEMA = DATABASE()
                AND s.TABLE_NAME   = ?
                AND s.NON_UNIQUE   = 0
                AND s.INDEX_NAME  <> 'PRIMARY'
              GROUP BY s.INDEX_NAME
             HAVING COUNT(*) = 1 AND MAX(s.COLUMN_NAME) = 'target_rencana_id'",
            [self::TABEL]
        )->getResultArray();

        foreach ($daftar as $baris) {
            $this->db->query('ALTER TABLE `' . self::TABEL . '` DROP INDEX `' . $baris['INDEX_NAME'] . '`');
        }
    }

    /* =========================================================
     * PEMERIKSA IDEMPOTENSI
     * =======================================================*/

    private function tambahKolom(string $kolom, string $ddl): void
    {
        if (! in_array($kolom, $this->db->getFieldNames(self::TABEL), true)) {
            $this->db->query($ddl);
            $this->db->resetDataCache();
        }
    }

    private function lepasKolom(string $kolom): void
    {
        if (in_array($kolom, $this->db->getFieldNames(self::TABEL), true)) {
            $this->db->query('ALTER TABLE `' . self::TABEL . '` DROP COLUMN `' . $kolom . '`');
            $this->db->resetDataCache();
        }
    }

    private function tambahIndeks(string $indeks, string $ddl): void
    {
        if (! $this->adaIndeks($indeks)) {
            $this->db->query($ddl);
        }
    }

    private function lepasIndeks(string $indeks): void
    {
        if ($this->adaIndeks($indeks)) {
            $this->db->query('ALTER TABLE `' . self::TABEL . '` DROP INDEX `' . $indeks . '`');
        }
    }

    private function adaIndeks(string $indeks): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [self::TABEL, $indeks]
        )->getRowArray() !== null;
    }
}
