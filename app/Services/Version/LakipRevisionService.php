<?php

namespace App\Services\Version;

use App\Models\Concerns\TransaksiAman;
use App\Models\DokumenVersiModel;
use App\Models\LakipSnapshotModel;
use CodeIgniter\Database\ConnectionInterface;
use RuntimeException;

/**
 * Versioning & revisi LAKIP (§29, §32, §33, §34).
 *
 * =====================================================================
 * DUA MEKANISME YANG DISATUKAN, BUKAN DIDUPLIKASI
 *
 * `lakip_snapshot` SUDAH punya versioning yang bekerja: kolom `versi`, `aktif`,
 * `status`, dan UNIQUE pada generated column `aktif_key` yang menjamin satu
 * snapshot aktif per (tahun, mode, opd). Itu tidak dibuang.
 *
 * Yang ditambahkan registri `dokumen_versi` adalah hal-hal yang belum dipunyai
 * snapshot: alur approval (§35), jejak audit per peristiwa (§22), dan
 * pemilihan sumber ber-versi (§25). `lakip_snapshot.version_id` dan
 * `dokumen_versi.ref_id` saling menunjuk, sehingga LakipSnapshotModel yang
 * sudah ada tetap bekerja tanpa diubah sebaris pun (§42, §51).
 * =====================================================================
 *
 * =====================================================================
 * EFFECTIVE_FROM PADA LAKIP
 *
 * Untuk RPJMD/Renstra/IKU, `effective_from` adalah tanggal dokumen mulai
 * mengikat. LAKIP tidak "mengikat" apa pun — ia laporan. Yang ditandai di sini
 * adalah TANGGAL SEBUAH REVISI MENJADI LAPORAN YANG BERLAKU.
 *
 * V1 bawaannya 1 Januari tahun laporan; revisi berikutnya bawaannya hari ini.
 * Tidak ada perlakuan khusus: aturan §6 (dua published tidak boleh punya
 * effective_from sama) berlaku sama, dan dua revisi di hari yang sama memang
 * harus dibedakan tanggalnya oleh operator.
 * =====================================================================
 */
class LakipRevisionService
{
    use TransaksiAman;

    private ConnectionInterface $db;

    private DokumenVersiModel $versi;

    private LakipSnapshotModel $snapshot;

    private LakipSourceService $sumber;

    private LakipSnapshotBuilder $builder;

    private VersionAuditService $audit;

    public function __construct(
        ?ConnectionInterface $db = null,
        ?DokumenVersiModel $versi = null,
        ?LakipSnapshotModel $snapshot = null,
        ?LakipSourceService $sumber = null,
        ?LakipSnapshotBuilder $builder = null,
        ?VersionAuditService $audit = null
    ) {
        $this->db       = $db ?? db_connect();
        $this->versi    = $versi ?? new DokumenVersiModel($this->db);
        $this->snapshot = $snapshot ?? new LakipSnapshotModel($this->db);
        $this->sumber   = $sumber ?? new LakipSourceService($this->db, null, $this->versi);
        $this->builder  = $builder ?? new LakipSnapshotBuilder($this->db, $this->versi, $this->sumber);
        $this->audit    = $audit ?? new VersionAuditService($this->db);
    }

    /* =========================================================
     * PRATINJAU (§29)
     * =======================================================*/

    /**
     * Tampilkan apa yang AKAN dibekukan, sebelum operator menekan Siapkan.
     *
     * §29 mewajibkan pratinjau sasaran/indikator/satuan/target. Ringkasannya
     * juga menyebut berapa baris yang realisasinya terbawa dan berapa yang
     * kosong — supaya berpindah sumber tidak berujung kejutan.
     */
    public function praTinjau(
        string $sumberType,
        int $sumberVersiId,
        int $tahun,
        string $mode,
        ?int $opdId,
        ?string $alasanOverride = null
    ): array {
        $periksa = $this->sumber->validasiPilihan(
            $sumberType,
            $sumberVersiId,
            $mode,
            $opdId,
            $tahun,
            $alasanOverride
        );

        if ($periksa['galat'] !== []) {
            return ['galat' => $periksa['galat'], 'pilihan' => $periksa];
        }

        $bahan = $this->builder->rakit($sumberType, $sumberVersiId, $tahun, $mode, $opdId);

        return [
            'galat'     => [],
            'pilihan'   => $periksa,
            'rows'      => $bahan['rows'],
            'lakipMap'  => $bahan['lakipMap'],
            'ringkasan' => $bahan['ringkasan'],
        ];
    }

    /* =========================================================
     * PEMBUATAN VERSI LAKIP
     * =======================================================*/

    /**
     * Siapkan LAKIP: buat versi + snapshot dari versi sumber terpilih.
     *
     * @param array{
     *     alasan_override?:?string, label?:?string, effective_from?:?string,
     *     catatan?:?string, filter_status?:?string, created_by?:?int
     * } $opsi
     *
     * @return array{version_id:int, snapshot_id:int, ringkasan:array}
     */
    public function siapkan(
        string $sumberType,
        int $sumberVersiId,
        int $tahun,
        string $mode,
        ?int $opdId,
        array $opsi = []
    ): array {
        $mode    = $this->sumber->modeSah($mode);
        $periksa = $this->sumber->validasiPilihan(
            $sumberType,
            $sumberVersiId,
            $mode,
            $opdId,
            $tahun,
            $opsi['alasan_override'] ?? null
        );

        if ($periksa['galat'] !== []) {
            throw new RuntimeException(implode(' ', $periksa['galat']));
        }

        $scope = VersionScope::lakip(
            $mode === LakipSourceService::MODE_KABUPATEN
                ? VersionScope::SCOPE_KABUPATEN
                : VersionScope::SCOPE_OPD,
            $mode === LakipSourceService::MODE_KABUPATEN ? null : $opdId,
            $tahun
        );

        $bahan = $this->builder->rakit($sumberType, $sumberVersiId, $tahun, $mode, $opdId);

        return $this->dalamTransaksi(function () use (
            $scope, $bahan, $periksa, $sumberType, $sumberVersiId, $tahun, $mode, $opdId, $opsi
        ) {
            $nomor  = $this->versi->nomorBerikutnya($scope);
            $mulai  = $this->tanggalBerlaku($opsi['effective_from'] ?? null, $tahun, $nomor);

            $versiId = $this->versi->sisipkan(array_merge($scope->kolomBaru(), [
                'version_no'             => $nomor,
                'label'                  => $opsi['label'] ?? ('LAKIP ' . $tahun . ' — Revisi ' . $nomor),
                'effective_from'         => $mulai,
                'status'                 => DokumenVersiModel::STATUS_DRAFT,
                'source_type'            => $sumberType,
                'source_version_id'      => $sumberVersiId,
                'source_captured_at'     => date('Y-m-d H:i:s'),
                'source_override_reason' => $periksa['adalah_rekomendasi'] ? null : ($opsi['alasan_override'] ?? null),
                'catatan'                => $opsi['catatan'] ?? null,
                'created_by'             => $opsi['created_by'] ?? null,
            ]));

            $snapshotId = $this->tulisSnapshot(
                $versiId,
                $nomor,
                $tahun,
                $mode,
                $opdId,
                $sumberType,
                $sumberVersiId,
                $periksa,
                $bahan,
                $opsi
            );

            $this->versi->perbarui($versiId, ['ref_id' => $snapshotId]);

            $this->audit->catat($versiId, VersionAuditService::AKSI_CREATED, [
                'ke_status'         => DokumenVersiModel::STATUS_DRAFT,
                'ringkasan'         => 'LAKIP ' . $tahun . ' disiapkan dari ' . strtoupper($sumberType)
                    . ' V' . ($periksa['sumber_versi']['version_no'] ?? '?')
                    . ' (' . $bahan['ringkasan']['baris'] . ' baris)',
                'sesudah'           => $bahan['ringkasan'],
                'alasan'            => $periksa['adalah_rekomendasi'] ? null : ($opsi['alasan_override'] ?? null),
                'effective_from'    => $mulai,
                'source_version_id' => $sumberVersiId,
                'oleh'              => $opsi['created_by'] ?? null,
            ]);

            return [
                'version_id'  => $versiId,
                'snapshot_id' => $snapshotId,
                'ringkasan'   => $bahan['ringkasan'],
            ];
        }, 'penyiapan LAKIP');
    }

    /**
     * Buat revisi LAKIP dari versi yang sudah ada (§33, §34).
     *
     * Sumbernya BOLEH diganti — §33 menyebutnya eksplisit. Yang tidak boleh
     * adalah kehilangan pekerjaan: realisasi, analisis faktor, efisiensi
     * program, dan benchmark dibawa untuk baris yang punya LINEAGE SAMA.
     *
     * Indikator baru -> kosong. Indikator yang hilang -> tetap hanya di versi
     * lama. Keduanya konsekuensi langsung §34, bukan pilihan implementasi.
     *
     * @param array{
     *     sumber_type?:?string, sumber_versi_id?:?int, alasan_override?:?string,
     *     salin_realisasi?:bool, salin_analisis?:bool, salin_efisiensi?:bool,
     *     salin_benchmark?:bool, label?:?string, effective_from?:?string,
     *     catatan?:?string, created_by?:?int
     * } $opsi
     */
    public function buatRevisi(int $dariVersiLakipId, array $opsi = []): array
    {
        $lama = $this->versi->ambil($dariVersiLakipId);

        if ($lama === null || $lama['modul'] !== VersionScope::MODUL_LAKIP) {
            throw new RuntimeException('Versi LAKIP asal tidak ditemukan.');
        }

        if ($lama['status'] !== DokumenVersiModel::STATUS_PUBLISHED) {
            throw new RuntimeException(
                'Revisi hanya dibuat dari LAKIP yang sudah ditetapkan. Status versi asal: '
                . $lama['status'] . '.'
            );
        }

        $tahun = (int) $lama['periode_mulai'];
        $mode  = $lama['scope_key'] === VersionScope::SCOPE_KABUPATEN
            ? LakipSourceService::MODE_KABUPATEN
            : LakipSourceService::MODE_OPD;
        $opdId = $lama['opd_id'] !== null ? (int) $lama['opd_id'] : null;

        // Sumber baku revisi = sumber versi lama, kecuali operator menggantinya.
        $sumberType   = $opsi['sumber_type'] ?? (string) $lama['source_type'];
        $sumberVersiId = (int) ($opsi['sumber_versi_id'] ?? $lama['source_version_id']);

        $hasil = $this->siapkan($sumberType, $sumberVersiId, $tahun, $mode, $opdId, [
            'alasan_override' => $opsi['alasan_override'] ?? null,
            'label'           => $opsi['label'] ?? null,
            'effective_from'  => $opsi['effective_from'] ?? null,
            'catatan'         => $opsi['catatan'] ?? null,
            'created_by'      => $opsi['created_by'] ?? null,
        ]);

        $bawa = $this->dalamTransaksi(function () use ($lama, $hasil, $opsi) {
            $this->versi->perbarui($hasil['version_id'], [
                'copied_from_version_id' => (int) $lama['id'],
            ]);

            $this->db->table('lakip_snapshot')->where('id', $hasil['snapshot_id'])->update([
                'created_from_lakip_version_id' => (int) $lama['id'],
            ]);

            return $this->salinDataAntarVersi(
                (int) $lama['ref_id'],
                $hasil['snapshot_id'],
                $opsi
            );
        }, 'penyalinan data revisi LAKIP');

        $this->audit->catat($hasil['version_id'], VersionAuditService::AKSI_EDITED_DRAFT, [
            'ringkasan' => 'Revisi dibuat dari V' . $lama['version_no'] . '; data terbawa: '
                . json_encode($bawa, JSON_UNESCAPED_UNICODE),
            'sesudah'   => $bawa,
            'oleh'      => $opsi['created_by'] ?? null,
        ]);

        return array_merge($hasil, ['terbawa' => $bawa, 'dari_versi_id' => (int) $lama['id']]);
    }

    /* =========================================================
     * PENYALINAN DATA ANTAR VERSI (§34)
     * =======================================================*/

    /**
     * Bawa realisasi/analisis/efisiensi/benchmark dari snapshot lama ke baru.
     *
     * PENCOCOKANNYA LEWAT LINEAGE, BUKAN NAMA (§34 melarang pencocokan nama).
     * Kunci yang dipakai, berurutan:
     *   1. (sumber, indikator_id live) — sama walau sasarannya dipindah;
     *   2. (sumber, source_indikator_id arsip) — bila id live belum ada.
     *
     * Baris baru yang tidak punya padanan dibiarkan kosong; baris lama yang
     * hilang tetap tinggal di versi lama. Keduanya disebut §34 dan §39.
     */
    private function salinDataAntarVersi(int $snapshotLamaId, int $snapshotBaruId, array $opsi): array
    {
        $bawaRealisasi = $opsi['salin_realisasi'] ?? true;
        $bawaAnalisis  = $opsi['salin_analisis'] ?? true;
        $bawaEfisiensi = $opsi['salin_efisiensi'] ?? true;
        $bawaBenchmark = $opsi['salin_benchmark'] ?? true;

        $n = ['realisasi' => 0, 'analisis' => 0, 'efisiensi' => 0, 'benchmark' => 0, 'tanpa_padanan' => 0];

        $lama = $this->db->table('lakip_snapshot_baris')->where('snapshot_id', $snapshotLamaId)
            ->get()->getResultArray();
        $baru = $this->db->table('lakip_snapshot_baris')->where('snapshot_id', $snapshotBaruId)
            ->get()->getResultArray();

        if ($baru === []) {
            return $n;
        }

        $petaLama = [];

        foreach ($lama as $b) {
            foreach ($this->kunciLineage($b) as $k) {
                $petaLama[$k] ??= $b;
            }
        }

        $now = date('Y-m-d H:i:s');

        foreach ($baru as $b) {
            $cocok = null;

            foreach ($this->kunciLineage($b) as $k) {
                if (isset($petaLama[$k])) {
                    $cocok = $petaLama[$k];
                    break;
                }
            }

            if ($cocok === null) {
                $n['tanpa_padanan']++;

                continue;
            }

            $this->db->table('lakip_snapshot_baris')->where('id', (int) $b['id'])->update([
                'copied_from_item_id' => (int) $cocok['id'],
            ]);

            if ($bawaRealisasi) {
                $this->db->table('lakip_snapshot_baris')->where('id', (int) $b['id'])->update([
                    'target_hitung'  => $cocok['target_hitung'],
                    'target_lalu'    => $cocok['target_lalu'],
                    'capaian_lalu'   => $cocok['capaian_lalu'],
                    'realisasi'      => $cocok['realisasi'],
                    'capaian_hitung' => $cocok['capaian_hitung'],
                    'status_lakip'   => $cocok['status_lakip'],
                ]);
                $n['realisasi']++;
            }

            if ($bawaAnalisis) {
                foreach ($this->db->table('lakip_snapshot_analisis')
                    ->where('snapshot_baris_id', (int) $cocok['id'])
                    ->orderBy('urutan', 'ASC')->get()->getResultArray() as $a) {
                    $this->db->table('lakip_snapshot_analisis')->insert([
                        'snapshot_id'        => $snapshotBaruId,
                        'snapshot_baris_id'  => (int) $b['id'],
                        'urutan'             => $a['urutan'],
                        'sumber_analisis_id' => $a['sumber_analisis_id'],
                        'faktor_pendukung'   => $a['faktor_pendukung'],
                        'faktor_penghambat'  => $a['faktor_penghambat'],
                        'upaya_peningkatan'  => $a['upaya_peningkatan'],
                        'created_at'         => $now,
                    ]);
                    $n['analisis']++;
                }
            }

            // §39 — benchmark hanya ikut bila lineage-nya sama. Itu sudah dijamin
            // karena kita berada di dalam blok "ada padanan".
            if ($bawaBenchmark && $this->db->tableExists('lakip_benchmark_item')) {
                $bm = $this->db->table('lakip_benchmark_item')
                    ->where('lakip_snapshot_item_id', (int) $cocok['id'])
                    ->get()->getRowArray();

                if ($bm !== null) {
                    $this->db->table('lakip_benchmark_item')->insert([
                        'lakip_snapshot_item_id' => (int) $b['id'],
                        'snapshot_id'            => $snapshotBaruId,
                        'opd_id'                 => $bm['opd_id'],
                        'nilai_provinsi'         => $bm['nilai_provinsi'],
                        'nilai_nasional'         => $bm['nilai_nasional'],
                        'sumber_provinsi'        => $bm['sumber_provinsi'],
                        'sumber_nasional'        => $bm['sumber_nasional'],
                        'tahun_data_provinsi'    => $bm['tahun_data_provinsi'],
                        'tahun_data_nasional'    => $bm['tahun_data_nasional'],
                        'catatan'                => $bm['catatan'],
                        'copied_from_id'         => (int) $bm['id'],
                        'created_at'             => $now,
                        'updated_at'             => $now,
                    ]);
                    $n['benchmark']++;
                }
            }
        }

        // Efisiensi program melekat pada snapshot, bukan pada baris indikator,
        // jadi disalin utuh tanpa pencocokan lineage.
        if ($bawaEfisiensi) {
            foreach ($this->db->table('lakip_snapshot_program')
                ->where('snapshot_id', $snapshotLamaId)
                ->orderBy('urutan', 'ASC')->get()->getResultArray() as $p) {
                $this->db->table('lakip_snapshot_program')->insert([
                    'snapshot_id' => $snapshotBaruId,
                    'urutan'      => $p['urutan'],
                    'program_id'  => $p['program_id'],
                    'program'     => $p['program'],
                    'anggaran'    => $p['anggaran'],
                    'realisasi'   => $p['realisasi'],
                    'efisiensi'   => $p['efisiensi'],
                    'created_at'  => $now,
                ]);
                $n['efisiensi']++;
            }
        }

        return $n;
    }

    /**
     * Kunci lineage sebuah baris snapshot, dari yang paling kuat.
     *
     * Sengaja TIDAK memuat teks indikator: §34 melarang pencocokan berdasarkan
     * nama, karena dua indikator berbeda bisa bernama sama dan satu indikator
     * bisa berganti nama tanpa berganti makna.
     *
     * @return string[]
     */
    private function kunciLineage(array $baris): array
    {
        $kunci  = [];
        $sumber = (string) ($baris['source_type'] ?? $baris['sumber'] ?? '');

        if (! empty($baris['indikator_id'])) {
            $kunci[] = 'live:' . $sumber . ':' . (int) $baris['indikator_id'];
        }

        if (! empty($baris['source_indikator_id'])) {
            $kunci[] = 'arsip:' . $sumber . ':' . (int) $baris['source_indikator_id'];
        }

        // Jalur lama: baris snapshot sebelum kolom source_* ada.
        if (! empty($baris['iku_indikator_id'])) {
            $kunci[] = 'iku:' . (int) $baris['iku_indikator_id'];
        }

        return $kunci;
    }

    /* =========================================================
     * INTERNAL
     * =======================================================*/

    /**
     * Tulis kepala + baris snapshot.
     *
     * Tidak memakai LakipSnapshotModel::siapkan(): method itu menolak bila
     * sudah ada snapshot aktif (invariant 3 yang memang benar untuk alurnya
     * sendiri), sedangkan REVISI justru selalu berangkat dari keadaan itu.
     * Yang dilakukan di sini menghormati invariant yang sama dengan cara lain:
     * versi lama dinonaktifkan LEBIH DULU, sehingga UNIQUE `aktif_key` tetap
     * terpenuhi setiap saat.
     */
    private function tulisSnapshot(
        int $versiId,
        int $nomor,
        int $tahun,
        string $mode,
        ?int $opdId,
        string $sumberType,
        int $sumberVersiId,
        array $periksa,
        array $bahan,
        array $opsi
    ): int {
        $now   = date('Y-m-d H:i:s');
        $opdKey = $mode === LakipSourceService::MODE_KABUPATEN ? 0 : (int) $opdId;

        // Lepas dulu slot aktif — UNIQUE uq_lakip_snapshot_aktif diperiksa
        // per-statement, sama seperti slot "terbuka" pada dokumen_versi.
        $this->db->table('lakip_snapshot')
            ->where('tahun', $tahun)->where('mode', $mode)->where('opd_id', $opdKey)
            ->where('aktif', 1)
            ->update(['aktif' => 0, 'updated_at' => $now]);

        $this->db->table('lakip_snapshot')->insert([
            'tahun'                  => $tahun,
            'mode'                   => $mode,
            'opd_id'                 => $opdKey,
            'versi'                  => $nomor,
            'label'                  => $opsi['label'] ?? ('LAKIP ' . $tahun . ' versi ' . $nomor),
            'status'                 => LakipSnapshotModel::STATUS_DRAFT,
            'aktif'                  => 1,
            'version_id'             => $versiId,
            'source_type'            => $sumberType,
            'source_version_id'      => $sumberVersiId,
            'source_reference_date'  => $periksa['tanggal_rujukan'],
            'source_override_reason' => $periksa['adalah_rekomendasi'] ? null : ($opsi['alasan_override'] ?? null),
            'sumber_iku_revisi_id'   => $sumberType === LakipSourceService::SUMBER_IKU
                ? ($periksa['sumber_versi']['ref_id'] ?? null)
                : null,
            'filter_status'          => ($opsi['filter_status'] ?? '') !== '' ? $opsi['filter_status'] : null,
            'catatan'                => $opsi['catatan'] ?? null,
            'jumlah_baris'           => count($bahan['rows']),
            'dibuat_oleh'            => $opsi['created_by'] ?? null,
            'dibuat_pada'            => $now,
            'captured_at'            => $now,
            'captured_by'            => $opsi['created_by'] ?? null,
            'updated_at'             => $now,
        ]);

        $snapshotId = (int) $this->db->insertID();
        $lakipMap   = $bahan['lakipMap'] ?? [];

        foreach ($bahan['rows'] as $urutan => $r) {
            $targetId = (int) ($r['target_id'] ?? 0);
            $lk       = $lakipMap[$targetId] ?? null;

            $this->db->table('lakip_snapshot_baris')->insert([
                'snapshot_id'        => $snapshotId,
                'urutan'             => $urutan,
                'sumber'             => $sumberType,
                'source_type'        => $sumberType,
                'source_version_id'  => $sumberVersiId,
                'source_sasaran_id'  => $r['arsip_sasaran_id'] ?? null,
                'source_indikator_id' => $r['arsip_indikator_id'] ?? null,
                'source_target_id'   => $r['arsip_target_id'] ?? null,
                'renstra_target_id'  => $sumberType === LakipSourceService::SUMBER_RENSTRA ? ($targetId ?: null) : null,
                'rpjmd_target_id'    => $sumberType === LakipSourceService::SUMBER_RPJMD ? ($targetId ?: null) : null,
                'iku_indikator_id'   => $sumberType === LakipSourceService::SUMBER_IKU
                    ? (! empty($r['indikator_id']) ? (int) $r['indikator_id'] : null)
                    : null,
                'lakip_id'           => $lk !== null ? (int) $lk['id'] : null,
                'opd_id'             => (int) ($r['opd_id'] ?? 0),
                'nama_opd'           => $r['nama_opd'] ?? null,
                'sasaran_id'         => ! empty($r['sasaran_id']) ? (int) $r['sasaran_id'] : null,
                'sasaran'            => $r['sasaran'] ?? null,
                'indikator_id'       => ! empty($r['indikator_id']) ? (int) $r['indikator_id'] : null,
                'indikator'          => $r['indikator_sasaran'] ?? null,
                'satuan'             => $r['satuan'] ?? null,
                'jenis_indikator'    => $r['jenis_indikator'] ?? null,
                'target'             => $r['target_tahun_ini'] ?? null,
                'target_hitung'      => $lk['target_hitung'] ?? null,
                'target_lalu'        => $lk['target_lalu'] ?? null,
                'capaian_lalu'       => $lk['capaian_lalu'] ?? null,
                'realisasi'          => $lk['capaian_tahun_ini'] ?? null,
                'capaian_hitung'     => $lk['capaian_hitung'] ?? null,
                'status_lakip'       => $lk['status'] ?? null,
                'perubahan_substansial' => (int) ($r['perubahan_substansial'] ?? 0),
                'captured_at'        => $now,
                'captured_by'        => $opsi['created_by'] ?? null,
                'created_at'         => $now,
            ]);
        }

        return $snapshotId;
    }

    /**
     * V1 bawaannya 1 Januari tahun laporan; revisi berikutnya bawaannya hari
     * ini. Lihat catatan kelas soal makna effective_from pada LAKIP.
     */
    private function tanggalBerlaku(?string $diminta, int $tahun, int $nomor): string
    {
        if ($diminta !== null && trim($diminta) !== '') {
            $ts = strtotime($diminta);

            if ($ts === false) {
                throw new RuntimeException('Tanggal berlaku tidak sah: ' . $diminta);
            }

            return date('Y-m-d', $ts);
        }

        if ($nomor <= 1) {
            return VersionResolver::awalTahun($tahun);
        }

        $hariIni = date('Y-m-d');

        // Tetap harus berada di dalam periode dokumen (periode LAKIP = satu
        // tahun), kalau tidak resolver tidak akan pernah memilihnya.
        if ((int) date('Y', strtotime($hariIni)) !== $tahun) {
            return VersionResolver::akhirTahun($tahun);
        }

        return $hariIni;
    }
}
