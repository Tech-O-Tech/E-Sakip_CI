<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Analisis Faktor Pencapaian Kinerja (tabel `lakip_analisis_faktor`).
 *
 * Satu indikator LAKIP boleh punya BANYAK baris analisis. Penambatnya adalah
 * TARGET (renstra_target / rpjmd_target) — pola yang sama dengan tabel `lakip`
 * — karena baris target sudah memuat indikator + tahun sekaligus, dan analisis
 * harus tetap bisa diisi walau baris LAKIP-nya belum dibuat.
 */
class LakipAnalisisModel extends Model
{
    protected $table = 'lakip_analisis_faktor';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $protectFields = true;

    protected $allowedFields = [
        'renstra_target_id',
        'rpjmd_target_id',
        'opd_id',
        'tahun',
        'faktor_pendukung',
        'faktor_penghambat',
        'upaya_peningkatan',
        'created_by',
        'updated_by',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /** Tabelnya opsional: instalasi lama boleh belum menjalankan migrasinya. */
    public function siap(): bool
    {
        return $this->db->tableExists($this->table);
    }

    /**
     * Analisis satu tahun, dikelompokkan per target — siap dipakai view.
     *
     * Satu query untuk seluruh halaman (hindari N+1 per indikator).
     *
     * @param string   $mode  'opd' (renstra) | 'kabupaten' (rpjmd)
     * @param int|null $opdId null/0 = tingkat kabupaten atau lintas OPD
     *
     * @return array<int, array<int, array<string, mixed>>> [target_id => daftar analisis]
     */
    /**
     * Kolom kunci baris untuk satu sumber dokumen.
     *
     * Satu tempat, dipakai baca maupun tulis. Sejak LAKIP OPD boleh bersumber
     * IKU, kunci baris tidak lagi bisa disimpulkan dari mode: mode 'opd'
     * berlaku untuk Renstra DAN IKU, sedangkan id keduanya hidup di ruang
     * angka yang sama.
     */
    public function kolomKunci(string $sumber): string
    {
        switch ($sumber) {
            case 'iku':
                return 'iku_indikator_id';

            case 'rpjmd':
                return 'rpjmd_target_id';

            default:
                return 'renstra_target_id';
        }
    }

    /** Sumber baris yang sah untuk satu mode. */
    public function sumberSah(string $mode, $diminta): string
    {
        if ($mode === 'kabupaten') {
            return 'rpjmd';
        }

        $sumber = trim((string) ($diminta ?? ''));

        return in_array($sumber, ['iku', 'renstra'], true) ? $sumber : 'renstra';
    }

    /** Sumber sebuah baris analisis; baris lama tanpa `source_type` dibaca dari kolomnya. */
    public function sumberBaris(array $baris): string
    {
        $sumber = (string) ($baris['source_type'] ?? '');

        if (in_array($sumber, ['iku', 'renstra', 'rpjmd'], true)) {
            return $sumber;
        }

        return ! empty($baris['rpjmd_target_id']) ? 'rpjmd' : 'renstra';
    }

    /** Apakah kolom sumber sudah terpasang (migrasi 2026-08-25). */
    public function punyaKolomSumber(): bool
    {
        return $this->siap() && $this->db->fieldExists('source_type', $this->table);
    }

    /**
     * @param string|null $sumber batasi pada satu sumber dokumen. null = ikut
     *                            bawaan mode (perilaku lama).
     */
    public function getByTahunGrouped(
        string $tahun,
        string $mode,
        ?int $opdId = null,
        ?string $sumber = null
    ): array {
        if (!$this->siap()) {
            return [];
        }

        $sumberAktif = $this->sumberSah($mode, $sumber);
        $kolom       = $this->kolomKunci($sumberAktif);

        $b = $this->db->table($this->table)
            ->where('tahun', $tahun)
            ->where($kolom . ' IS NOT NULL', null, false)
            ->orderBy('id', 'ASC');

        // Penyaring sumber hanya dipasang bila kolomnya ada. Instalasi yang
        // belum menjalankan migrasi tetap berjalan seperti sebelumnya, bukan
        // gagal dengan "unknown column".
        if ($this->punyaKolomSumber()) {
            if ($sumberAktif === 'renstra') {
                // Baris lama tidak punya source_type; semuanya Renstra/RPJMD.
                $b->groupStart()
                    ->where('source_type', 'renstra')
                    ->orWhere('source_type IS NULL', null, false)
                    ->groupEnd();
            } else {
                $b->where('source_type', $sumberAktif);
            }
        }

        // Mode kabupaten selalu opd_id = 0; mode OPD dibatasi kalau OPD-nya jelas
        // (admin_kab yang melihat "semua OPD" tidak memfilter).
        if ($mode === 'kabupaten') {
            $b->where('opd_id', 0);
        } elseif (!empty($opdId)) {
            $b->where('opd_id', (int) $opdId);
        }

        $map = [];
        foreach ($b->get()->getResultArray() as $row) {
            $map[(int) $row[$kolom]][] = $row;
        }

        return $map;
    }

    /** Satu baris analisis (null bila tabelnya belum ada / id tidak ketemu). */
    public function ambil(int $id): ?array
    {
        return $this->siap() ? $this->find($id) : null;
    }
}
