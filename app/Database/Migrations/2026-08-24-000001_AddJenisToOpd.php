<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * PENANDA JENIS ENTITAS PADA TABEL `opd`.
 *
 * Kembaran SQL langsungnya (dipakai di server yang skemanya sudah menyimpang
 * dari migration): db/update_2026-08-24_opd_jenis.sql — berkas itu memuat
 * alasan lengkap tiap keputusan.
 *
 * RINGKASNYA: Dashboard Eksekutif (Bupati & admin_kab) harus mengagregasi
 * Perangkat Daerah saja. Sebelum ini jenis entitas ditebak dari `pk.jenis`
 * ('camat') atau dari pola nama, dan keduanya rapuh — Kecamatan Sukoharjo
 * punya PK 'camat' sekaligus PK 'jpt' pada tahun yang sama, dan ejaan nama
 * kecamatan tidak seragam. Kolom eksplisit membuat klasifikasinya menjadi
 * data yang bisa dikoreksi Super Admin lewat Master OPD.
 *
 * Nilai: opd | kecamatan | kelurahan | upt | non_opd
 * (lihat App\Models\OpdModel::JENIS_* dan ::EXCLUDED_EXECUTIVE_JENIS)
 *
 * Idempoten: memeriksa kolom/indeks dulu, dan seed-nya tidak menimpa baris
 * yang jenisnya sudah pernah diubah manual.
 */
class AddJenisToOpd extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('jenis', 'opd')) {
            $this->forge->addColumn('opd', [
                'jenis' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'null'       => false,
                    'default'    => 'opd',
                    'comment'    => 'opd|kecamatan|kelurahan|upt|non_opd',
                    'after'      => 'singkatan',
                ],
            ]);
        }

        $indeks = $this->db->getIndexData('opd');
        if (! isset($indeks['idx_opd_jenis'])) {
            $this->db->query('CREATE INDEX `idx_opd_jenis` ON `opd` (`jenis`)');
        }

        // Klasifikasi awal. Pola nama dipakai HANYA sekali di sini; sesudah
        // ini yang berlaku adalah isi kolom, bukan nama.
        $seed = [
            'kecamatan' => ["nama_opd LIKE 'Kecamatan%'"],
            'kelurahan' => ["nama_opd LIKE 'Kelurahan%'"],
            'upt'       => ["nama_opd LIKE 'UPT %'", "nama_opd LIKE 'UPTD %'"],
            'non_opd'   => ["nama_opd LIKE 'Kabupaten %'", "nama_opd = 'BUPATI'", "nama_opd LIKE 'BAGIAN %'"],
        ];

        foreach ($seed as $jenis => $syarat) {
            $this->db->query(
                'UPDATE `opd` SET `jenis` = ? WHERE `jenis` = ' . "'opd'" . ' AND (' . implode(' OR ', $syarat) . ')',
                [$jenis]
            );
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('jenis', 'opd')) {
            $this->forge->dropColumn('opd', 'jenis');
        }
    }
}
