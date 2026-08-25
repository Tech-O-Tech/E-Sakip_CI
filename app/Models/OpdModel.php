<?php

namespace App\Models;

use CodeIgniter\Model;

class OpdModel extends Model
{
    /**
     * ID OPD yang dikecualikan dari daftar/dropdown OPD (mis. Pemda induk / entitas non-OPD).
     * Sumber tunggal — jangan hardcode ulang angka ini di controller/query lain.
     */
    public const EXCLUDED_OPD_IDS = [1, 46, 209];

    /**
     * Nilai kolom `opd.jenis` (lihat migration AddJenisToOpd).
     * Klasifikasi ini DATA, bukan aturan tersembunyi di kode: Super Admin
     * mengubahnya lewat Master OPD tanpa menyentuh source.
     */
    public const JENIS_OPD       = 'opd';
    public const JENIS_KECAMATAN = 'kecamatan';
    public const JENIS_KELURAHAN = 'kelurahan';
    public const JENIS_UPT       = 'upt';
    public const JENIS_NON_OPD   = 'non_opd';

    /** Pilihan jenis untuk dropdown Master OPD. */
    public const JENIS_LABEL = [
        self::JENIS_OPD       => 'Perangkat Daerah',
        self::JENIS_KECAMATAN => 'Kecamatan',
        self::JENIS_KELURAHAN => 'Kelurahan',
        self::JENIS_UPT       => 'UPT / UPTD',
        self::JENIS_NON_OPD   => 'Bukan Perangkat Daerah',
    ];

    /**
     * Jenis yang TIDAK ikut agregat Dashboard Eksekutif (Bupati & admin_kab).
     *
     * Kecamatan/kelurahan/UPT punya jalur pembinaan dan dokumen PK sendiri
     * (PK Camat, jenjang Pelaksana) sehingga bila dicampur ke agregat lintas
     * Perangkat Daerah, angka "Capaian Perangkat Daerah", "OPD Belum Update",
     * dan Prioritas Pimpinan jadi tidak sebanding.
     *
     * `non_opd` SENGAJA tidak masuk daftar ini — entitas seperti
     * "Kabupaten Pringsewu" (id 212) tetap tampil sesuai keputusan pengguna.
     * Menambahkannya cukup satu baris di sini bila kelak dikehendaki.
     */
    public const EXCLUDED_EXECUTIVE_JENIS = [
        self::JENIS_KECAMATAN,
        self::JENIS_KELURAHAN,
        self::JENIS_UPT,
    ];

    protected $table = 'opd';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = ['nama_opd', 'singkatan', 'jenis', 'alamat_opd'];

    // Automatically handle timestamps
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    // Validation rules
    protected $validationRules = [
        'nama_opd' => 'required|string|max_length[255]',
        'singkatan' => 'permit_empty|string|max_length[50]',
        'alamat_opd' => 'permit_empty|string|max_length[50]',
        'jenis' => 'permit_empty|in_list[opd,kecamatan,kelurahan,upt,non_opd]',
    ];

    protected $validationMessages = [
        'nama_opd' => [
            'required' => 'Nama OPD harus diisi',
            'max_length' => 'Nama OPD maksimal 255 karakter',
        ],
    ];

    protected $skipValidation = false;
    protected $cleanValidationRules = true;

    // Callbacks
    protected $allowCallbacks = true;
    protected $beforeInsert = [];
    protected $afterInsert = [];
    protected $beforeUpdate = [];
    protected $afterUpdate = [];
    protected $beforeFind = [];
    protected $afterFind = [];
    protected $beforeDelete = [];
    protected $afterDelete = [];

    public function getAllOpd()
    {
        return $this->whereNotIn('id', self::EXCLUDED_OPD_IDS)->orderBy('nama_opd', 'ASC')->findAll();
    }
    public function getOpdById(int $opdId)
    {
        $db = \Config\Database::connect();
        return $db->table('opd')->where('id', $opdId)->get()->getRowArray();
    }
}
