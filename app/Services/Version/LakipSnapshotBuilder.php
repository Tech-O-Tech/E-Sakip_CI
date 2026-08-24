<?php

namespace App\Services\Version;

use App\Models\DokumenVersiModel;
use CodeIgniter\Database\ConnectionInterface;
use RuntimeException;

/**
 * Merakit baris LAKIP dari ARSIP sebuah versi sumber (§29, §31).
 *
 * =====================================================================
 * BENTUK KELUARAN SENGAJA IDENTIK DENGAN LakipModel::getLakipByMode()
 *
 *   ['rows' => [...], 'lakipMap' => [target_id => baris lakip]]
 *
 * Itu bukan kebetulan: seluruh view layar, view cetak, dan kedua fungsi Excel
 * membaca bentuk itu. Dengan menyamakannya, memilih sumber IKU alih-alih
 * Renstra TIDAK menuntut satu pun view diubah — controller cukup menukar
 * sumbernya. Pola yang sama sudah dipakai LakipSnapshotModel::rehidrasi().
 * =====================================================================
 *
 * =====================================================================
 * MEMBACA ARSIP, BUKAN TABEL LIVE
 *
 * §31: setelah snapshot dibuat, LAKIP tidak boleh membaca sumber hidup.
 * Karena itu perakitan bermula dari tabel `*_versi_*` / `iku_revisi_*`, bukan
 * dari `renstra_target` / `rpjmd_target` / `iku_target`.
 *
 * Id tabel LIVE tetap dicarikan padanannya (`target_id`, `indikator_id`,
 * `sasaran_id`) semata-mata supaya tautan "tambah/ubah realisasi" yang sudah
 * ada di view tetap bekerja. Nilai yang DITAMPILKAN selalu dari arsip.
 * =====================================================================
 */
class LakipSnapshotBuilder
{
    private ConnectionInterface $db;

    private DokumenVersiModel $versi;

    private LakipSourceService $sumber;

    public function __construct(
        ?ConnectionInterface $db = null,
        ?DokumenVersiModel $versi = null,
        ?LakipSourceService $sumber = null
    ) {
        $this->db     = $db ?? db_connect();
        $this->versi  = $versi ?? new DokumenVersiModel($this->db);
        $this->sumber = $sumber ?? new LakipSourceService($this->db, null, $this->versi);
    }

    /**
     * Rakit bahan LAKIP tahun $tahun dari versi sumber tertentu.
     *
     * @return array{
     *     rows:array, lakipMap:array, ringkasan:array,
     *     sumber_type:string, sumber_versi:array
     * }
     */
    public function rakit(
        string $sumberType,
        int $sumberVersiId,
        int $tahun,
        string $mode,
        ?int $opdId
    ): array {
        $sumberType = $this->sumber->sumberSah($sumberType, $mode);
        $versi      = $this->versi->ambil($sumberVersiId);

        if ($versi === null) {
            throw new RuntimeException('Versi sumber tidak ditemukan: ' . $sumberVersiId);
        }

        switch ($sumberType) {
            case LakipSourceService::SUMBER_RPJMD:
                $rows = $this->dariRpjmd($sumberVersiId, $tahun);
                break;

            case LakipSourceService::SUMBER_RENSTRA:
                $rows = $this->dariRenstra($sumberVersiId, $tahun, $opdId);
                break;

            default:
                $rows = $this->dariIku($versi, $tahun, $opdId);
        }

        foreach ($rows as &$r) {
            $r['sumber']            = $sumberType;
            $r['source_version_id'] = $sumberVersiId;
        }
        unset($r);

        $lakipMap = $this->petaLakip($sumberType, $rows, $tahun);

        return [
            'rows'         => $rows,
            'lakipMap'     => $lakipMap,
            'ringkasan'    => $this->ringkas($rows, $lakipMap),
            'sumber_type'  => $sumberType,
            'sumber_versi' => $versi,
        ];
    }

    /* =========================================================
     * PERAKITAN PER SUMBER
     * =======================================================*/

    private function dariRpjmd(int $versiId, int $tahun): array
    {
        $rows = $this->db->query(
            'SELECT
                 tg.id            AS arsip_target_id,
                 tg.tahun         AS tahun,
                 tg.target_tahunan AS target_tahun_ini,
                 i.id             AS arsip_indikator_id,
                 i.source_indikator_id AS indikator_id,
                 i.indikator_sasaran   AS indikator_sasaran,
                 COALESCE(i.satuan_nama, i.satuan) AS satuan,
                 i.jenis_indikator     AS jenis_indikator,
                 i.perubahan_substansial,
                 s.id             AS arsip_sasaran_id,
                 s.source_sasaran_id AS sasaran_id,
                 s.sasaran_rpjmd  AS sasaran,
                 i.urutan         AS urut_ind,
                 s.urutan         AS urut_sas
             FROM rpjmd_versi_target tg
             JOIN rpjmd_versi_indikator_sasaran i ON i.id = tg.versi_indikator_id
             JOIN rpjmd_versi_sasaran s           ON s.id = i.versi_sasaran_id
             WHERE i.version_id = ? AND tg.tahun = ?
             ORDER BY s.urutan ASC, i.urutan ASC, tg.id ASC',
            [$versiId, $tahun]
        )->getResultArray();

        foreach ($rows as &$r) {
            // Padanan id live dicari lewat (indikator live, tahun) — arsip target
            // tidak menyimpan id target live, dan memang tidak perlu.
            $r['target_id'] = $this->targetLive('rpjmd_target', 'indikator_sasaran_id', $r['indikator_id'], $tahun);
            $r['opd_id']    = 0;
            $r['nama_opd']  = null;
        }
        unset($r);

        return $rows;
    }

    private function dariRenstra(int $versiId, int $tahun, ?int $opdId): array
    {
        $rows = $this->db->query(
            'SELECT
                 tg.id      AS arsip_target_id,
                 tg.tahun   AS tahun,
                 tg.target  AS target_tahun_ini,
                 i.id       AS arsip_indikator_id,
                 i.source_indikator_id AS indikator_id,
                 i.indikator_sasaran   AS indikator_sasaran,
                 COALESCE(i.satuan_nama, i.satuan) AS satuan,
                 i.jenis_indikator     AS jenis_indikator,
                 i.perubahan_substansial,
                 s.id       AS arsip_sasaran_id,
                 s.source_sasaran_id AS sasaran_id,
                 s.sasaran  AS sasaran,
                 s.opd_id   AS opd_id,
                 s.nama_opd AS nama_opd,
                 i.urutan   AS urut_ind,
                 s.urutan   AS urut_sas
             FROM renstra_versi_target tg
             JOIN renstra_versi_indikator_sasaran i ON i.id = tg.versi_indikator_id
             JOIN renstra_versi_sasaran s           ON s.id = i.versi_sasaran_id
             WHERE i.version_id = ? AND tg.tahun = ?
             ORDER BY s.urutan ASC, i.urutan ASC, tg.id ASC',
            [$versiId, $tahun]
        )->getResultArray();

        foreach ($rows as &$r) {
            $r['target_id'] = $this->targetLive('renstra_target', 'renstra_indikator_id', $r['indikator_id'], $tahun);
            $r['opd_id']    = (int) ($r['opd_id'] ?: ($opdId ?? 0));
        }
        unset($r);

        return $rows;
    }

    /**
     * Rakit dari arsip revisi IKU.
     *
     * Arsipnya dipegang `iku_revisi_*` yang sudah ada sejak sebelum registri
     * versi dibuat, dan `dokumen_versi.ref_id` yang menautkan keduanya (§42).
     * Kalau tautannya belum ada, versi IKU itu memang belum punya arsip yang
     * bisa dibaca — dan itu dilaporkan apa adanya, bukan diam-diam kosong.
     */
    /**
     * PERINGATAN: jalur ini BELUM tersambung ke layar mana pun.
     *
     * Ia mencari versi IKU di `dokumen_versi` (lewat `ref_id`), padahal versi
     * IKU sesungguhnya hidup di registrinya sendiri, `iku_revisi` — resolusinya
     * per TAHUN, bukan per tanggal. Selama `dokumen_versi` tidak pernah berisi
     * baris modul IKU, pemanggilan di sini selalu berakhir di RuntimeException
     * di bawah.
     *
     * Jalur yang benar-benar dipakai layar & snapshot adalah
     * LakipModel::getIndexIkuTargets() + LakipOpdController::barisHidupUntukSnapshot().
     * Sebelum menyambungkan builder ini ke rute apa pun, samakan dulu
     * sumber versinya — kalau tidak, hasilnya bukan salah, melainkan kosong.
     */
    private function dariIku(array $versi, int $tahun, ?int $opdId): array
    {
        $revisiId = (int) ($versi['ref_id'] ?? 0);

        if ($revisiId <= 0) {
            throw new RuntimeException(
                'Versi IKU ini belum tertaut ke arsip revisinya (dokumen_versi.ref_id kosong), '
                . 'sehingga isinya tidak bisa dibaca.'
            );
        }

        $rows = $this->db->query(
            'SELECT
                 tg.id      AS arsip_target_id,
                 tg.tahun   AS tahun,
                 tg.target  AS target_tahun_ini,
                 i.id       AS arsip_indikator_id,
                 i.sumber_indikator_id AS indikator_id,
                 i.indikator           AS indikator_sasaran,
                 COALESCE(i.satuan_nama, i.satuan) AS satuan,
                 i.jenis_indikator     AS jenis_indikator,
                 i.perubahan_substansial,
                 s.id       AS arsip_sasaran_id,
                 s.sumber_sasaran_id AS sasaran_id,
                 s.sasaran  AS sasaran,
                 i.urutan   AS urut_ind,
                 s.urutan   AS urut_sas
             FROM iku_revisi_target tg
             JOIN iku_revisi_indikator i ON i.id = tg.revisi_indikator_id
             JOIN iku_revisi_sasaran s   ON s.id = i.revisi_sasaran_id
             WHERE i.revisi_id = ? AND tg.tahun = ?
             ORDER BY s.urutan ASC, i.urutan ASC, tg.id ASC',
            [$revisiId, $tahun]
        )->getResultArray();

        $namaOpd = $this->namaOpd($opdId);

        foreach ($rows as &$r) {
            $r['target_id'] = $this->targetLive('iku_target', 'iku_indikator_id', $r['indikator_id'], $tahun);
            $r['opd_id']    = (int) ($opdId ?? 0);
            $r['nama_opd']  = $namaOpd;
        }
        unset($r);

        return $rows;
    }

    /* =========================================================
     * REALISASI YANG SUDAH ADA
     * =======================================================*/

    /**
     * Cari baris `lakip` yang cocok untuk setiap baris hasil rakitan.
     *
     * =================================================================
     * JEMBATAN SAAT SUMBER BERPINDAH
     *
     * `lakip` selama ini hanya mengenal renstra_target_id / rpjmd_target_id.
     * Kalau LAKIP OPD berpindah dari Renstra ke IKU, seluruh realisasi yang
     * sudah diinput akan tampak hilang — padahal indikatornya sering indikator
     * yang sama, hanya lewat dokumen yang berbeda.
     *
     * Karena itu untuk sumber IKU dicoba dua jalur, berurutan:
     *   1. baris lakip yang memang sudah ber-source_type='iku';
     *   2. lewat `iku_indikator.source_indikator_id` — indikator Renstra/RPJMD
     *      yang melahirkan indikator IKU ini saat sync — lalu ke target live
     *      tahun itu, lalu ke baris lakip lamanya.
     *
     * Jalur kedua memakai LINEAGE, bukan pencocokan nama, sesuai §34.
     * Indikator IKU yang tidak punya lineage memang tampil kosong — itu jujur,
     * dan pratinjau melaporkan jumlahnya.
     * =================================================================
     */
    private function petaLakip(string $sumberType, array $rows, int $tahun): array
    {
        if ($rows === []) {
            return [];
        }

        $targetIds = array_values(array_filter(array_map(
            static fn ($r) => (int) ($r['target_id'] ?? 0),
            $rows
        )));

        $map = [];

        if ($targetIds !== []) {
            $kolom = match ($sumberType) {
                LakipSourceService::SUMBER_RPJMD   => 'rpjmd_target_id',
                LakipSourceService::SUMBER_RENSTRA => 'renstra_target_id',
                default                            => null,
            };

            if ($kolom !== null) {
                foreach ($this->db->table('lakip')->whereIn($kolom, $targetIds)->get()->getResultArray() as $l) {
                    $map[(int) $l[$kolom]] = $l;
                }
            } else {
                // Jalur 1 untuk IKU: baris yang sudah source-agnostic.
                foreach ($this->db->table('lakip')
                    ->where('source_type', LakipSourceService::SUMBER_IKU)
                    ->whereIn('source_entity_id', $targetIds)
                    ->get()->getResultArray() as $l) {
                    $map[(int) $l['source_entity_id']] = $l;
                }
            }
        }

        if ($sumberType === LakipSourceService::SUMBER_IKU) {
            $this->jembatanIku($rows, $tahun, $map);
        }

        return $map;
    }

    /** Jalur 2: ikuti lineage IKU -> indikator Renstra/RPJMD -> target live -> lakip. */
    private function jembatanIku(array $rows, int $tahun, array &$map): void
    {
        $perluJembatan = [];

        foreach ($rows as $r) {
            $tid = (int) ($r['target_id'] ?? 0);

            if ($tid > 0 && ! isset($map[$tid]) && ! empty($r['indikator_id'])) {
                $perluJembatan[$tid] = (int) $r['indikator_id'];
            }
        }

        if ($perluJembatan === []) {
            return;
        }

        $lineage = [];

        foreach ($this->db->table('iku_indikator')
            ->select('id, source_type, source_indikator_id')
            ->whereIn('id', array_values($perluJembatan))
            ->where('source_indikator_id IS NOT NULL', null, false)
            ->get()->getResultArray() as $row) {
            $lineage[(int) $row['id']] = $row;
        }

        if ($lineage === []) {
            return;
        }

        foreach ($perluJembatan as $targetId => $ikuIndikatorId) {
            $asal = $lineage[$ikuIndikatorId] ?? null;

            if ($asal === null) {
                continue;
            }

            $tipe = (string) $asal['source_type'];
            [$tabel, $fk, $kolomLakip] = $tipe === LakipSourceService::SUMBER_RPJMD
                ? ['rpjmd_target', 'indikator_sasaran_id', 'rpjmd_target_id']
                : ['renstra_target', 'renstra_indikator_id', 'renstra_target_id'];

            $targetLama = $this->targetLive($tabel, $fk, (int) $asal['source_indikator_id'], $tahun);

            if ($targetLama === null) {
                continue;
            }

            $l = $this->db->table('lakip')->where($kolomLakip, $targetLama)->get()->getRowArray();

            if ($l !== null) {
                $map[$targetId] = $l;
            }
        }
    }

    /* =========================================================
     * RINGKASAN PRATINJAU (§29)
     * =======================================================*/

    private function ringkas(array $rows, array $lakipMap): array
    {
        $sasaran = [];
        $adaRealisasi = 0;

        foreach ($rows as $r) {
            if (! empty($r['arsip_sasaran_id'])) {
                $sasaran[(int) $r['arsip_sasaran_id']] = true;
            }

            $tid = (int) ($r['target_id'] ?? 0);

            if ($tid > 0 && isset($lakipMap[$tid])) {
                $adaRealisasi++;
            }
        }

        return [
            'baris'               => count($rows),
            'sasaran'             => count($sasaran),
            'realisasi_terbawa'   => $adaRealisasi,
            'realisasi_kosong'    => count($rows) - $adaRealisasi,
            'tanpa_padanan_live'  => count(array_filter($rows, static fn ($r) => empty($r['target_id']))),
        ];
    }

    /* =========================================================
     * BANTU
     * =======================================================*/

    /** Id target LIVE untuk (indikator, tahun), atau null bila tidak ada. */
    private function targetLive(string $tabel, string $fk, $indikatorId, int $tahun): ?int
    {
        if (empty($indikatorId)) {
            return null;
        }

        $row = $this->db->table($tabel)->select('id')
            ->where($fk, (int) $indikatorId)
            ->where('tahun', $tahun)
            ->get()->getRowArray();

        return $row === null ? null : (int) $row['id'];
    }

    private function namaOpd(?int $opdId): ?string
    {
        if (empty($opdId)) {
            return null;
        }

        $row = $this->db->table('opd')->select('nama_opd')->where('id', $opdId)->get()->getRowArray();

        return $row['nama_opd'] ?? null;
    }
}
