<?php

namespace App\Models\Versi;

use App\Services\Version\VersionScope;
use RuntimeException;

/**
 * Arsip isi versi Renstra OPD.
 *
 * Hierarki yang dibekukan:
 *
 *   tujuan (+ teks rpjmd_sasaran induknya)
 *     ├─ indikator_tujuan ─ target_tujuan
 *     └─ sasaran (opd_id, periode)
 *          └─ indikator_sasaran ─ target
 *
 * =====================================================================
 * PEMBEKUAN BERMULA DARI SASARAN, LALU MENDAKI KE TUJUAN
 *
 * Lingkup versi Renstra adalah (opd_id, periode) — dan keduanya hanya ada di
 * `renstra_sasaran`. `renstra_tujuan` TIDAK punya `opd_id` maupun periode
 * (temuan T4): ia menggantung pada `rpjmd_sasaran`, dan kepemilikannya hanya
 * bisa disimpulkan lewat sasaran yang menunjuknya. Di basis aktif bahkan 55
 * dari 112 tujuan sudah yatim.
 *
 * Karena itu urutannya terbalik dibanding RPJMD: kumpulkan dulu sasaran dalam
 * lingkup, baru dari situ ditemukan tujuan mana yang ikut dibekukan. Teks
 * `rpjmd_sasaran` induknya ikut dibekukan supaya arsip tetap terbaca walau
 * RPJMD-nya kelak berubah.
 * =====================================================================
 */
class RenstraVersiModel extends ArsipVersiModel
{
    public function modul(): string
    {
        return VersionScope::MODUL_RENSTRA;
    }

    public function tabelArsip(): array
    {
        return [
            'renstra_versi_tujuan',
            'renstra_versi_indikator_tujuan',
            'renstra_versi_target_tujuan',
            'renstra_versi_sasaran',
            'renstra_versi_indikator_sasaran',
            'renstra_versi_target',
        ];
    }

    /**
     * Peta tingkat arsip Renstra. Akarnya TUJUAN — tidak ada misi, dan
     * `renstra_tujuan` memang tidak punya pemilik di tabel live (temuan T4);
     * kepemilikannya dibawa `version_id` arsip ini.
     */
    protected function petaKolom(): array
    {
        return [
            'tujuan' => [
                'tabel' => 'renstra_versi_tujuan', 'teks' => 'tujuan',
                'fk' => null, 'induk' => null, 'extra' => [],
            ],
            'sasaran' => [
                'tabel' => 'renstra_versi_sasaran', 'teks' => 'sasaran',
                'fk' => 'versi_tujuan_id', 'induk' => 'tujuan', 'extra' => ['csf'],
            ],
            'indikator' => [
                'tabel' => 'renstra_versi_indikator_sasaran', 'teks' => 'indikator_sasaran',
                'fk' => 'versi_sasaran_id', 'induk' => 'sasaran',
                'extra' => ['satuan', 'jenis_indikator', 'baseline'],
            ],
            'target' => [
                'tabel' => 'renstra_versi_target',
                'fk' => 'versi_indikator_id', 'nilai' => 'target',
            ],
        ];
    }

    /**
     * Sasaran baru butuh opd_id & periode yang tidak ada di form — keduanya
     * diambil dari lingkup versi, bukan dari masukan pemakai (§2.9).
     */
    protected function lengkapiBarisBaru(string $tingkat, array $row, int $versiId): array
    {
        if ($tingkat !== 'sasaran') {
            return $row;
        }

        $kepala = $this->db->table('dokumen_versi')
            ->select('opd_id, periode_mulai, periode_akhir')
            ->where('id', $versiId)->get()->getRowArray();

        if ($kepala !== null) {
            $row['opd_id']      = $kepala['opd_id'] !== null ? (int) $kepala['opd_id'] : null;
            $row['tahun_mulai'] = (int) $kepala['periode_mulai'];
            $row['tahun_akhir'] = (int) $kepala['periode_akhir'];
            $row['nama_opd']    = $this->namaOpd($row['opd_id']);
        }

        return $row;
    }

    /* =========================================================
     * LIVE -> ARSIP
     * =======================================================*/

    public function bekukanDariLive(int $versiId, VersionScope $scope): array
    {
        $this->pastikanDalamTransaksi('pembekuan arsip Renstra');

        $now = $this->sekarang();
        $n   = ['tujuan' => 0, 'indikator_tujuan' => 0, 'target_tujuan' => 0,
            'sasaran' => 0, 'indikator_sasaran' => 0, 'target' => 0];

        $sasaranLingkup = $this->sasaranDalamLingkup($scope);

        if ($sasaranLingkup === []) {
            return $n;
        }

        // Tujuan yang ikut dibekukan = tujuan yang dirujuk sasaran dalam lingkup.
        // Urutannya mengikuti id supaya hasil pembekuan deterministik.
        $tujuanIds = [];

        foreach ($sasaranLingkup as $s) {
            $tid = (int) $s['renstra_tujuan_id'];

            if ($tid > 0 && ! in_array($tid, $tujuanIds, true)) {
                $tujuanIds[] = $tid;
            }
        }

        sort($tujuanIds);

        $namaOpd = $this->namaOpd($scope->opdId());
        $urutT   = 0;

        foreach ($tujuanIds as $tujuanId) {
            $t = $this->db->table('renstra_tujuan rt')
                ->select('rt.*, ps.sasaran_rpjmd AS teks_rpjmd_sasaran, ps.version_id AS rpjmd_version_id')
                ->join('rpjmd_sasaran ps', 'ps.id = rt.rpjmd_sasaran_id', 'left')
                ->where('rt.id', $tujuanId)
                ->get()->getRowArray();

            if ($t === null) {
                continue;
            }

            $arsipTujuanId = $this->sisip('renstra_versi_tujuan', [
                'version_id'         => $versiId,
                'source_tujuan_id'   => $tujuanId,
                'rpjmd_sasaran_id'   => $t['rpjmd_sasaran_id'] !== null ? (int) $t['rpjmd_sasaran_id'] : null,
                'rpjmd_sasaran_teks' => $this->kosongJadiNull($t['teks_rpjmd_sasaran'] ?? null),
                'rpjmd_version_id'   => ! empty($t['rpjmd_version_id']) ? (int) $t['rpjmd_version_id'] : null,
                'tujuan'             => (string) $t['tujuan'],
                'urutan'             => $urutT++,
                'jenis_perubahan'    => self::UBAH_TETAP,
                'created_at'         => $now,
            ]);
            $n['tujuan']++;

            $this->bekukanIndikatorTujuan($versiId, $tujuanId, $arsipTujuanId, $now, $n);

            // HANYA sasaran dalam lingkup. Satu tujuan bisa (walau jarang)
            // dirujuk sasaran milik OPD lain; membekukannya berarti versi OPD
            // ini diam-diam memuat Renstra OPD tetangga.
            $urutS = 0;

            foreach ($sasaranLingkup as $s) {
                if ((int) $s['renstra_tujuan_id'] !== $tujuanId) {
                    continue;
                }

                $arsipSasaranId = $this->sisip('renstra_versi_sasaran', [
                    'version_id'        => $versiId,
                    'versi_tujuan_id'   => $arsipTujuanId,
                    'source_sasaran_id' => (int) $s['id'],
                    'opd_id'            => (int) $s['opd_id'],
                    'nama_opd'          => $namaOpd,
                    'sasaran'           => (string) $s['sasaran'],
                    'csf'               => $this->kosongJadiNull($s['csf'] ?? null),
                    'tahun_mulai'       => (int) $s['tahun_mulai'],
                    'tahun_akhir'       => (int) $s['tahun_akhir'],
                    'urutan'            => $urutS++,
                    'jenis_perubahan'   => self::UBAH_TETAP,
                    'created_at'        => $now,
                ]);
                $n['sasaran']++;

                $this->bekukanIndikatorSasaran($versiId, (int) $s['id'], $arsipSasaranId, $now, $n);
            }
        }

        return $n;
    }

    private function bekukanIndikatorTujuan(int $versiId, int $tujuanId, int $arsipTujuanId, string $now, array &$n): void
    {
        $rows = $this->db->table('renstra_indikator_tujuan')
            ->where('tujuan_id', $tujuanId)
            ->where('dihentikan_pada IS NULL', null, false)
            ->orderBy('id', 'ASC')->get()->getResultArray();

        $urut = 0;

        foreach ($rows as $i) {
            $arsipIndId = $this->sisip('renstra_versi_indikator_tujuan', [
                'version_id'          => $versiId,
                'versi_tujuan_id'     => $arsipTujuanId,
                'source_indikator_id' => (int) $i['id'],
                'indikator_tujuan'    => (string) $i['indikator_tujuan'],
                'urutan'              => $urut++,
                'jenis_perubahan'     => self::UBAH_TETAP,
                'created_at'          => $now,
            ]);
            $n['indikator_tujuan']++;

            foreach ($this->db->table('renstra_target_tujuan')
                ->where('indikator_tujuan_id', (int) $i['id'])
                ->orderBy('tahun', 'ASC')->get()->getResultArray() as $tg) {
                $this->sisip('renstra_versi_target_tujuan', [
                    'versi_indikator_tujuan_id' => $arsipIndId,
                    'tahun'                     => (int) $tg['tahun'],
                    'target_tahunan'            => $this->kosongJadiNull($tg['target_tahunan']),
                    'created_at'                => $now,
                ]);
                $n['target_tujuan']++;
            }
        }
    }

    private function bekukanIndikatorSasaran(int $versiId, int $sasaranId, int $arsipSasaranId, string $now, array &$n): void
    {
        $rows = $this->db->table('renstra_indikator_sasaran')
            ->where('renstra_sasaran_id', $sasaranId)
            ->where('dihentikan_pada IS NULL', null, false)
            ->orderBy('id', 'ASC')->get()->getResultArray();

        $urut = 0;

        foreach ($rows as $i) {
            $arsipIndId = $this->sisip('renstra_versi_indikator_sasaran', [
                'version_id'            => $versiId,
                'versi_sasaran_id'      => $arsipSasaranId,
                'source_indikator_id'   => (int) $i['id'],
                'indikator_sasaran'     => (string) $i['indikator_sasaran'],
                'satuan'                => $this->kosongJadiNull($i['satuan'] ?? null),
                'satuan_nama'           => $this->namaSatuan($i['satuan'] ?? null),
                'baseline'              => $this->kosongJadiNull($i['baseline'] ?? null),
                'jenis_indikator'       => $this->kosongJadiNull($i['jenis_indikator'] ?? null),
                'urutan'                => $urut++,
                'jenis_perubahan'       => $this->jenisPerubahanSah($i['jenis_perubahan'] ?? null),
                'perubahan_substansial' => (int) ($i['perubahan_substansial'] ?? 0),
                'created_at'            => $now,
            ]);
            $n['indikator_sasaran']++;

            foreach ($this->db->table('renstra_target')
                ->where('renstra_indikator_id', (int) $i['id'])
                ->orderBy('tahun', 'ASC')->get()->getResultArray() as $tg) {
                $this->sisip('renstra_versi_target', [
                    'versi_indikator_id' => $arsipIndId,
                    'tahun'              => (int) $tg['tahun'],
                    'target'             => $this->kosongJadiNull($tg['target']),
                    'created_at'         => $now,
                ]);
                $n['target']++;
            }
        }
    }

    /* =========================================================
     * FORM "TAMBAH RENSTRA" -> ARSIP VERSI
     * =======================================================*/

    /**
     * Simpan satu TUJUAN beserta seluruh isinya dari bentuk POST form
     * "Tambah Renstra" ke dalam arsip sebuah versi.
     *
     * Bentuk masukannya sengaja dibuat identik dengan yang diterima
     * RenstraController::save(), karena form-nya memang berkas yang sama.
     * Yang berbeda hanya tujuannya: ini menulis ke ARSIP, bukan ke tabel live.
     *
     * Saat menyunting, subpohon tujuan lama DIBUANG lalu ditulis ulang. Aman
     * karena yang disentuh hanya arsip DRAFT — usulan yang belum pernah resmi.
     * Menulis ulang jauh lebih sederhana, dan lebih sulit salah, daripada
     * mencocokkan baris satu per satu hanya untuk menghemat beberapa INSERT.
     *
     * @param int|null $arsipTujuanId id tujuan arsip yang disunting; null = baru
     *
     * @return int id tujuan arsip hasil simpan
     */
    public function simpanTujuanDariForm(
        int $versiId,
        VersionScope $scope,
        array $post,
        ?int $arsipTujuanId = null
    ): int {
        $this->pastikanDalamTransaksi('penyimpanan tujuan versi Renstra');

        $now     = $this->sekarang();
        $namaOpd = $this->namaOpd($scope->opdId());

        $tujuanTeks = trim((string) ($post['tujuan_renstra'] ?? ''));

        if ($tujuanTeks === '') {
            throw new RuntimeException('Tujuan Renstra wajib diisi.');
        }

        $rpjmdSasaranId = (int) ($post['rpjmd_sasaran_id'] ?? 0);
        $rpjmdTeks      = null;
        $rpjmdVersiId   = null;

        if ($rpjmdSasaranId > 0) {
            $ps = $this->db->table('rpjmd_sasaran')->select('sasaran_rpjmd, version_id')
                ->where('id', $rpjmdSasaranId)->get()->getRowArray();

            if ($ps === null) {
                $rpjmdSasaranId = 0;
            } else {
                $rpjmdTeks    = $this->kosongJadiNull($ps['sasaran_rpjmd']);
                $rpjmdVersiId = ! empty($ps['version_id']) ? (int) $ps['version_id'] : null;
            }
        }

        // --- sunting: buang subpohon lama, pertahankan urutan & lineage-nya ---
        $urutan       = 0;
        $sumberTujuan = null;

        if ($arsipTujuanId !== null) {
            $lama = $this->db->table('renstra_versi_tujuan')
                ->where('id', $arsipTujuanId)->where('version_id', $versiId)
                ->get()->getRowArray();

            if ($lama === null) {
                throw new RuntimeException('Tujuan tidak ditemukan pada versi ini.');
            }

            $urutan       = (int) $lama['urutan'];
            $sumberTujuan = $lama['source_tujuan_id'];

            // CASCADE membuang indikator, sasaran, dan targetnya sekaligus.
            $this->db->table('renstra_versi_tujuan')->where('id', $arsipTujuanId)->delete();
        } else {
            $row = $this->db->table('renstra_versi_tujuan')->selectMax('urutan', 'maks')
                ->where('version_id', $versiId)->get()->getRowArray();
            $urutan = (int) ($row['maks'] ?? -1) + 1;
        }

        $tujuanId = $this->sisip('renstra_versi_tujuan', [
            'version_id'         => $versiId,
            'source_tujuan_id'   => $sumberTujuan,
            'rpjmd_sasaran_id'   => $rpjmdSasaranId > 0 ? $rpjmdSasaranId : null,
            'rpjmd_sasaran_teks' => $rpjmdTeks,
            'rpjmd_version_id'   => $rpjmdVersiId,
            'tujuan'             => $tujuanTeks,
            'urutan'             => $urutan,
            'jenis_perubahan'    => self::UBAH_TETAP,
            'created_at'         => $now,
        ]);

        // --- indikator tujuan ---
        $urutIT = 0;

        foreach ((array) ($post['indikator_tujuan'] ?? []) as $it) {
            $teks = trim((string) ($it['indikator_tujuan'] ?? ''));

            if ($teks === '') {
                continue;
            }

            $itId = $this->sisip('renstra_versi_indikator_tujuan', [
                'version_id'       => $versiId,
                'versi_tujuan_id'  => $tujuanId,
                'indikator_tujuan' => $teks,
                'urutan'           => $urutIT++,
                'jenis_perubahan'  => self::UBAH_TETAP,
                'created_at'       => $now,
            ]);

            $this->simpanTargetForm('renstra_versi_target_tujuan', 'versi_indikator_tujuan_id',
                'target_tahunan', $itId, $it['target_tahunan'] ?? [], $scope, $now);
        }

        // --- sasaran + indikatornya ---
        $urutS = 0;

        foreach ((array) ($post['sasaran_renstra'] ?? []) as $s) {
            $teksSasaran = trim((string) ($s['sasaran'] ?? ''));

            if ($teksSasaran === '') {
                continue;
            }

            $sasaranId = $this->sisip('renstra_versi_sasaran', [
                'version_id'      => $versiId,
                'versi_tujuan_id' => $tujuanId,
                // opd_id & periode diambil dari LINGKUP versi, bukan dari form —
                // form tidak boleh memindahkan kepemilikan sasaran (§2.9).
                'opd_id'          => $scope->opdId(),
                'nama_opd'        => $namaOpd,
                'sasaran'         => $teksSasaran,
                'csf'             => $this->kosongJadiNull($s['csf'] ?? null),
                'tahun_mulai'     => $scope->periodeMulai(),
                'tahun_akhir'     => $scope->periodeAkhir(),
                'urutan'          => $urutS++,
                'jenis_perubahan' => self::UBAH_TETAP,
                'created_at'      => $now,
            ]);

            $urutI = 0;

            foreach ((array) ($s['indikator_sasaran'] ?? []) as $i) {
                $teksInd = trim((string) ($i['indikator_sasaran'] ?? ''));

                if ($teksInd === '') {
                    continue;
                }

                $satuan = $this->kosongJadiNull($i['satuan'] ?? null);

                $indId = $this->sisip('renstra_versi_indikator_sasaran', [
                    'version_id'            => $versiId,
                    'versi_sasaran_id'      => $sasaranId,
                    'indikator_sasaran'     => $teksInd,
                    'satuan'                => $satuan,
                    'satuan_nama'           => $this->namaSatuan($satuan),
                    'baseline'              => $this->kosongJadiNull($i['baseline'] ?? null),
                    'jenis_indikator'       => $this->kosongJadiNull($i['jenis_indikator'] ?? null),
                    'urutan'                => $urutI++,
                    'jenis_perubahan'       => self::UBAH_TETAP,
                    'perubahan_substansial' => 0,
                    'created_at'            => $now,
                ]);

                $this->simpanTargetForm('renstra_versi_target', 'versi_indikator_id',
                    'target', $indId, $i['target_tahunan'] ?? [], $scope, $now);
            }
        }

        return $tujuanId;
    }

    /**
     * Simpan target per tahun dari bentuk `target_tahunan[i][tahun|target]`.
     *
     * Tahun di luar periode versi DIABAIKAN. Form mengirim lima baris tetap,
     * dan periode yang lebih pendek akan menyisakan baris kosong bertahun nol —
     * menyimpannya hanya akan melahirkan target hantu.
     */
    private function simpanTargetForm(
        string $tabel,
        string $fk,
        string $kolomNilai,
        int $indukId,
        $target,
        VersionScope $scope,
        string $now
    ): void {
        foreach ((array) $target as $t) {
            $tahun = (int) ($t['tahun'] ?? 0);
            $nilai = $this->kosongJadiNull($t['target'] ?? null);

            if ($tahun <= 0 || ! $scope->memuatTahun($tahun) || $nilai === null) {
                continue;
            }

            $this->sisip($tabel, [
                $fk           => $indukId,
                'tahun'       => $tahun,
                $kolomNilai   => $nilai,
                'created_at'  => $now,
            ]);
        }
    }

    /**
     * Isi satu tujuan arsip dalam bentuk yang bisa dipakai mengisi ulang form.
     *
     * Bentuk kembaliannya meniru POST-nya sendiri, sehingga view yang sama bisa
     * dipakai untuk menambah maupun menyunting tanpa cabang tersendiri.
     */
    public function tujuanUntukForm(int $versiId, int $arsipTujuanId): ?array
    {
        $t = $this->db->table('renstra_versi_tujuan')
            ->where('id', $arsipTujuanId)->where('version_id', $versiId)
            ->get()->getRowArray();

        if ($t === null) {
            return null;
        }

        $out = [
            'id'               => (int) $t['id'],
            'rpjmd_sasaran_id' => $t['rpjmd_sasaran_id'],
            'tujuan_renstra'   => $t['tujuan'],
            'indikator_tujuan' => [],
            'sasaran_renstra'  => [],
        ];

        foreach ($this->anak('renstra_versi_indikator_tujuan', 'versi_tujuan_id', $arsipTujuanId) as $it) {
            $out['indikator_tujuan'][] = [
                'indikator_tujuan' => $it['indikator_tujuan'],
                'target_tahunan'   => $this->targetUntukForm(
                    'renstra_versi_target_tujuan', 'versi_indikator_tujuan_id', (int) $it['id'], 'target_tahunan'
                ),
            ];
        }

        foreach ($this->anak('renstra_versi_sasaran', 'versi_tujuan_id', $arsipTujuanId) as $s) {
            $baris = ['sasaran' => $s['sasaran'], 'csf' => $s['csf'], 'indikator_sasaran' => []];

            foreach ($this->anak('renstra_versi_indikator_sasaran', 'versi_sasaran_id', (int) $s['id']) as $i) {
                $baris['indikator_sasaran'][] = [
                    'indikator_sasaran' => $i['indikator_sasaran'],
                    'satuan'            => $i['satuan'],
                    'jenis_indikator'   => $i['jenis_indikator'],
                    'baseline'          => $i['baseline'],
                    'target_tahunan'    => $this->targetUntukForm(
                        'renstra_versi_target', 'versi_indikator_id', (int) $i['id'], 'target'
                    ),
                ];
            }

            $out['sasaran_renstra'][] = $baris;
        }

        return $out;
    }

    /** @return array<int,array{tahun:int,target:string}> */
    private function targetUntukForm(string $tabel, string $fk, int $indukId, string $kolomNilai): array
    {
        $out = [];

        foreach ($this->db->table($tabel)->where($fk, $indukId)
            ->orderBy('tahun', 'ASC')->get()->getResultArray() as $t) {
            $out[(int) $t['tahun']] = [
                'tahun'  => (int) $t['tahun'],
                'target' => (string) ($t[$kolomNilai] ?? ''),
            ];
        }

        // Dikunci per tahun supaya tidak kembar, lalu dikembalikan sebagai
        // daftar berurut — JSON-nya harus berupa larik, bukan objek.
        return array_values($out);
    }

    /* =========================================================
     * ARSIP -> ARSIP (DEEP COPY §10)
     * =======================================================*/

    public function salinDariVersi(int $dariVersiId, int $keVersiId): array
    {
        $this->pastikanDalamTransaksi('penyalinan arsip Renstra');

        if ($dariVersiId === $keVersiId) {
            throw new RuntimeException('Versi asal dan tujuan penyalinan tidak boleh sama.');
        }

        $now = $this->sekarang();
        $n   = ['tujuan' => 0, 'indikator_tujuan' => 0, 'target_tujuan' => 0,
            'sasaran' => 0, 'indikator_sasaran' => 0, 'target' => 0];

        foreach ($this->barisArsip('renstra_versi_tujuan', $dariVersiId) as $t) {
            $tujuanBaru = $this->sisip('renstra_versi_tujuan', [
                'version_id'         => $keVersiId,
                'source_tujuan_id'   => $t['source_tujuan_id'],
                'copied_from_id'     => (int) $t['id'],
                'rpjmd_sasaran_id'   => $t['rpjmd_sasaran_id'],
                'rpjmd_sasaran_teks' => $t['rpjmd_sasaran_teks'],
                'rpjmd_version_id'   => $t['rpjmd_version_id'],
                'tujuan'             => $t['tujuan'],
                'urutan'             => $t['urutan'],
                'jenis_perubahan'    => self::UBAH_TETAP,
                'catatan_perubahan'  => $t['catatan_perubahan'],
                'created_at'         => $now,
            ]);
            $n['tujuan']++;

            foreach ($this->anak('renstra_versi_indikator_tujuan', 'versi_tujuan_id', (int) $t['id']) as $it) {
                $itBaru = $this->sisip('renstra_versi_indikator_tujuan', [
                    'version_id'          => $keVersiId,
                    'versi_tujuan_id'     => $tujuanBaru,
                    'source_indikator_id' => $it['source_indikator_id'],
                    'copied_from_id'      => (int) $it['id'],
                    'indikator_tujuan'    => $it['indikator_tujuan'],
                    'urutan'              => $it['urutan'],
                    'jenis_perubahan'     => self::UBAH_TETAP,
                    'created_at'          => $now,
                ]);
                $n['indikator_tujuan']++;

                foreach ($this->anak('renstra_versi_target_tujuan', 'versi_indikator_tujuan_id', (int) $it['id']) as $tg) {
                    $this->sisip('renstra_versi_target_tujuan', [
                        'versi_indikator_tujuan_id' => $itBaru,
                        'tahun'                     => $tg['tahun'],
                        'target_tahunan'            => $tg['target_tahunan'],
                        'target_sebelumnya'         => $tg['target_tahunan'],
                        'created_at'                => $now,
                    ]);
                    $n['target_tujuan']++;
                }
            }

            foreach ($this->anak('renstra_versi_sasaran', 'versi_tujuan_id', (int) $t['id']) as $s) {
                $sasaranBaru = $this->sisip('renstra_versi_sasaran', [
                    'version_id'        => $keVersiId,
                    'versi_tujuan_id'   => $tujuanBaru,
                    'source_sasaran_id' => $s['source_sasaran_id'],
                    'copied_from_id'    => (int) $s['id'],
                    'opd_id'            => $s['opd_id'],
                    'nama_opd'          => $s['nama_opd'],
                    'sasaran'           => $s['sasaran'],
                    'csf'               => $s['csf'],
                    'tahun_mulai'       => $s['tahun_mulai'],
                    'tahun_akhir'       => $s['tahun_akhir'],
                    'urutan'            => $s['urutan'],
                    'jenis_perubahan'   => self::UBAH_TETAP,
                    'catatan_perubahan' => $s['catatan_perubahan'],
                    'created_at'        => $now,
                ]);
                $n['sasaran']++;

                foreach ($this->anak('renstra_versi_indikator_sasaran', 'versi_sasaran_id', (int) $s['id']) as $i) {
                    $indBaru = $this->sisip('renstra_versi_indikator_sasaran', [
                        'version_id'            => $keVersiId,
                        'versi_sasaran_id'      => $sasaranBaru,
                        'source_indikator_id'   => $i['source_indikator_id'],
                        'copied_from_id'        => (int) $i['id'],
                        'indikator_sasaran'     => $i['indikator_sasaran'],
                        'satuan'                => $i['satuan'],
                        'satuan_nama'           => $i['satuan_nama'],
                        'baseline'              => $i['baseline'],
                        'jenis_indikator'       => $i['jenis_indikator'],
                        'urutan'                => $i['urutan'],
                        // Salinan lahir 'tetap': operator yang menyatakan apa yang
                        // berubah, bukan sistem yang menebaknya (§11).
                        'jenis_perubahan'       => self::UBAH_TETAP,
                        'perubahan_substansial' => 0,
                        'created_at'            => $now,
                    ]);
                    $n['indikator_sasaran']++;

                    foreach ($this->anak('renstra_versi_target', 'versi_indikator_id', (int) $i['id']) as $tg) {
                        $this->sisip('renstra_versi_target', [
                            'versi_indikator_id' => $indBaru,
                            'tahun'              => $tg['tahun'],
                            'target'             => $tg['target'],
                            'target_sebelumnya'  => $tg['target'],
                            'created_at'         => $now,
                        ]);
                        $n['target']++;
                    }
                }
            }
        }

        return $n;
    }

    /* =========================================================
     * BACA ISI
     * =======================================================*/

    public function isi(int $versiId): array
    {
        if (! $this->siap()) {
            return [];
        }

        $tujuan = $this->barisArsip('renstra_versi_tujuan', $versiId);

        if ($tujuan === []) {
            return [];
        }

        $indTujuan  = $this->petaAnak('renstra_versi_indikator_tujuan', 'versi_tujuan_id', $versiId);
        $sasaran    = $this->petaAnak('renstra_versi_sasaran', 'versi_tujuan_id', $versiId);
        $indSasaran = $this->petaAnak('renstra_versi_indikator_sasaran', 'versi_sasaran_id', $versiId);

        $targetTujuan = $this->petaTarget(
            'renstra_versi_target_tujuan',
            'versi_indikator_tujuan_id',
            $this->kolomId(array_merge(...array_values($indTujuan) ?: [[]]))
        );
        $target = $this->petaTarget(
            'renstra_versi_target',
            'versi_indikator_id',
            $this->kolomId(array_merge(...array_values($indSasaran) ?: [[]]))
        );

        foreach ($tujuan as &$t) {
            $t['indikator_tujuan'] = $indTujuan[(int) $t['id']] ?? [];

            foreach ($t['indikator_tujuan'] as &$it) {
                $it['target'] = $targetTujuan[(int) $it['id']] ?? [];
            }
            unset($it);

            $t['sasaran'] = $sasaran[(int) $t['id']] ?? [];

            foreach ($t['sasaran'] as &$s) {
                $s['indikator'] = $indSasaran[(int) $s['id']] ?? [];

                foreach ($s['indikator'] as &$i) {
                    $i['target'] = $target[(int) $i['id']] ?? [];
                }
                unset($i);
            }
            unset($s);
        }
        unset($t);

        return $tujuan;
    }

    public function ringkas(int $versiId): array
    {
        if (! $this->siap()) {
            return [];
        }

        $out = [];

        foreach ([
            'tujuan'            => 'renstra_versi_tujuan',
            'indikator_tujuan'  => 'renstra_versi_indikator_tujuan',
            'sasaran'           => 'renstra_versi_sasaran',
            'indikator_sasaran' => 'renstra_versi_indikator_sasaran',
        ] as $nama => $tabel) {
            $out[$nama] = (int) $this->db->table($tabel)->where('version_id', $versiId)->countAllResults();
        }

        return $out;
    }

    /* =========================================================
     * ARSIP DIBACA SEPERTI DATA BERJALAN
     * =======================================================*/

    /**
     * Isi sebuah versi dalam bentuk yang PERSIS SAMA dengan yang dikembalikan
     * `RenstraModel::getFilteredRenstra()`.
     *
     * =====================================================================
     * MENGAPA BENTUKNYA HARUS SAMA PERSIS
     *
     * Tabel Renstra di layar dan di PDF sudah ada, sudah benar, dan sudah
     * dikenal pemakainya. Melihat versi lama seharusnya tidak berarti melihat
     * tabel yang lain. Dengan menyamakan bentuk datanya, satu-satunya yang
     * berubah ketika pemakai memilih versi adalah SUMBER barisnya — bukan
     * susunan kolom, bukan urutan, bukan cara membacanya.
     *
     * =====================================================================
     * YANG SENGAJA TIDAK DIISI
     *
     * `status` per sasaran tidak ada di arsip, dan memang tidak seharusnya ada:
     * draft/selesai adalah keadaan PENGERJAAN, sedangkan versi yang sudah
     * ditetapkan tidak lagi dikerjakan. Nilainya dikosongkan, dan tampilan
     * menyembunyikan kolomnya saat membaca versi.
     *
     * `sasaran_id` di sini adalah id ARSIP, bukan id baris berjalan. Karena itu
     * pemanggilnya WAJIB mematikan tombol sunting/hapus saat menampilkan versi —
     * kalau tidak, tombol itu akan menunjuk baris yang salah. Penanda
     * `dari_arsip` disertakan agar kekeliruan itu tidak bisa terjadi diam-diam.
     *
     * @param string|null $cariRpjmd saring berdasarkan teks sasaran RPJMD
     * @param string|null $cariTujuan saring berdasarkan teks tujuan Renstra
     */
    public function bacaSepertiLive(
        int $versiId,
        ?string $cariRpjmd = null,
        ?string $cariTujuan = null
    ): array {
        if (! $this->siap()) {
            return [];
        }

        $cocok = static function (?string $kata, ?string $teks): bool {
            $kata = trim((string) $kata);

            return $kata === '' || mb_stripos((string) $teks, $kata) !== false;
        };

        $out = [];

        foreach ($this->isi($versiId) as $t) {
            if (! $cocok($cariRpjmd, $t['rpjmd_sasaran_teks'] ?? '')
                || ! $cocok($cariTujuan, $t['tujuan'] ?? '')) {
                continue;
            }

            $indikatorTujuan = [];

            foreach ($t['indikator_tujuan'] ?? [] as $it) {
                $indikatorTujuan[] = [
                    'id'               => (int) $it['id'],
                    'tujuan_id'        => (int) $t['id'],
                    'indikator_tujuan' => $it['indikator_tujuan'],
                    'targets'          => $this->petaTahun($it['target'] ?? [], 'target_tahunan'),
                ];
            }

            $sasaran = [];

            foreach ($t['sasaran'] ?? [] as $s) {
                $indikator = [];

                foreach ($s['indikator'] ?? [] as $i) {
                    $indikator[(int) $i['id']] = [
                        'indikator'       => $i['indikator_sasaran'],
                        // Nama satuan dibekukan saat pembekuan; memakai master
                        // satuan hari ini berisiko menampilkan nama yang sudah
                        // berubah pada dokumen yang seharusnya tidak berubah.
                        'satuan'          => $i['satuan_nama'] ?? $i['satuan'] ?? '',
                        'baseline'        => $i['baseline'] ?? '',
                        'jenis_indikator' => $i['jenis_indikator'] ?? '',
                        'targets'         => $this->petaTahun($i['target'] ?? [], 'target'),
                    ];
                }

                $sasaran[(int) $s['id']] = [
                    'sasaran_id' => (int) $s['id'],
                    'sasaran'    => $s['sasaran'],
                    'status'     => '',
                    'indikator'  => $indikator,
                ];
            }

            $out[] = [
                'tujuan_renstra_id' => (int) $t['id'],
                'sasaran_rpjmd'     => $t['rpjmd_sasaran_teks'] ?? '',
                'tujuan'            => $t['tujuan'],
                'indikator_tujuan'  => $indikatorTujuan,
                'sasaran'           => $sasaran,
                'dari_arsip'        => true,
            ];
        }

        return $out;
    }

    /** Ubah daftar target arsip menjadi peta tahun => nilai. */
    private function petaTahun(array $target, string $kolom): array
    {
        $out = [];

        foreach ($target as $t) {
            $out[(int) $t['tahun']] = $t[$kolom] ?? '';
        }

        return $out;
    }

    public function hitungLiveAktif(VersionScope $scope): array
    {
        $sasaran = $this->kolomId($this->sasaranDalamLingkup($scope));

        $indikator = $sasaran === [] ? 0 : (int) $this->db->table('renstra_indikator_sasaran')
            ->whereIn('renstra_sasaran_id', $sasaran)
            ->where('dihentikan_pada IS NULL', null, false)->countAllResults();

        return ['sasaran' => count($sasaran), 'indikator' => $indikator];
    }

    /* =========================================================
     * ARSIP -> LIVE (upsert + PENSIUN)
     * =======================================================*/

    public function terapkanKeLive(int $versiId, VersionScope $scope, int $berlakuMulaiTahun): array
    {
        $this->pastikanDalamTransaksi('penerapan arsip Renstra ke tabel live');

        $now = $this->sekarang();
        $n   = ['dibuat' => 0, 'diperbarui' => 0, 'dipensiunkan' => 0, 'target_live_tak_tercantum' => 0];

        $dipakai = ['tujuan' => [], 'ind_tujuan' => [], 'sasaran' => [], 'ind_sasaran' => []];

        foreach ($this->barisArsip('renstra_versi_tujuan', $versiId) as $t) {
            $tujuanId = $this->upsert('renstra_tujuan', $t['source_tujuan_id'], [
                'rpjmd_sasaran_id' => $this->rpjmdSasaranSah($t),
                'tujuan'           => (string) $t['tujuan'],
                'version_id'       => $versiId,
            ], ['created_at' => $now], $now, $n);

            $this->tautkanArsip('renstra_versi_tujuan', (int) $t['id'], 'source_tujuan_id', $tujuanId);
            $dipakai['tujuan'][] = $tujuanId;

            foreach ($this->anak('renstra_versi_indikator_tujuan', 'versi_tujuan_id', (int) $t['id']) as $it) {
                $itId = $this->upsert('renstra_indikator_tujuan', $it['source_indikator_id'], [
                    'tujuan_id'        => $tujuanId,
                    'indikator_tujuan' => (string) $it['indikator_tujuan'],
                    'version_id'       => $versiId,
                ], ['created_at' => $now], $now, $n);

                $this->tautkanArsip('renstra_versi_indikator_tujuan', (int) $it['id'], 'source_indikator_id', $itId);
                $dipakai['ind_tujuan'][] = $itId;

                $this->terapkanTarget(
                    'renstra_target_tujuan',
                    'indikator_tujuan_id',
                    'target_tahunan',
                    $itId,
                    $this->anak('renstra_versi_target_tujuan', 'versi_indikator_tujuan_id', (int) $it['id']),
                    'target_tahunan',
                    $now,
                    $n
                );
            }

            foreach ($this->anak('renstra_versi_sasaran', 'versi_tujuan_id', (int) $t['id']) as $s) {
                // opd_id & periode diambil dari LINGKUP, bukan dari arsip:
                // arsip yang disalin dari versi OPD lain tidak boleh diam-diam
                // memindahkan kepemilikan sasaran (§2.9).
                $sasaranId = $this->upsert('renstra_sasaran', $s['source_sasaran_id'], [
                    'opd_id'            => $scope->opdId(),
                    'renstra_tujuan_id' => $tujuanId,
                    'sasaran'           => (string) $s['sasaran'],
                    'csf'               => $s['csf'],
                    'tahun_mulai'       => $scope->periodeMulai(),
                    'tahun_akhir'       => $scope->periodeAkhir(),
                    'version_id'        => $versiId,
                ], ['status' => 'selesai', 'created_at' => $now], $now, $n);

                $this->tautkanArsip('renstra_versi_sasaran', (int) $s['id'], 'source_sasaran_id', $sasaranId);
                $dipakai['sasaran'][] = $sasaranId;

                foreach ($this->anak('renstra_versi_indikator_sasaran', 'versi_sasaran_id', (int) $s['id']) as $i) {
                    // Lihat catatan yang sama di RpjmdVersiModel: indikator
                    // bertanda 'dihentikan' dibiarkan dipensiunkan penyapuan.
                    if ($this->jenisPerubahanSah($i['jenis_perubahan']) === self::UBAH_DIHENTIKAN) {
                        continue;
                    }

                    $indId = $this->upsert('renstra_indikator_sasaran', $i['source_indikator_id'], [
                        'renstra_sasaran_id'    => $sasaranId,
                        'indikator_sasaran'     => (string) $i['indikator_sasaran'],
                        // Kolom live NOT NULL — lihat nullJadiKosong().
                        'satuan'                => $this->nullJadiKosong($i['satuan']),
                        'baseline'              => $this->nullJadiKosong($i['baseline']),
                        'jenis_indikator'       => $i['jenis_indikator'],
                        'jenis_perubahan'       => $this->jenisPerubahanSah($i['jenis_perubahan']),
                        'perubahan_substansial' => (int) $i['perubahan_substansial'],
                        'version_id'            => $versiId,
                    ], ['created_at' => $now], $now, $n);

                    $this->tautkanLineage('renstra_indikator_sasaran', 'renstra_versi_indikator_sasaran', $indId, $i);
                    $this->tautkanArsip('renstra_versi_indikator_sasaran', (int) $i['id'], 'source_indikator_id', $indId);
                    $dipakai['ind_sasaran'][] = $indId;

                    $this->terapkanTarget(
                        'renstra_target',
                        'renstra_indikator_id',
                        'target',
                        $indId,
                        $this->anak('renstra_versi_target', 'versi_indikator_id', (int) $i['id']),
                        'target',
                        $now,
                        $n
                    );
                }
            }
        }

        $n['dipensiunkan'] = $this->pensiunkanYangHilang($versiId, $scope, $dipakai, $berlakuMulaiTahun, $now);

        return $n;
    }

    /**
     * Pensiunkan baris live yang tidak lagi tercantum di arsip.
     *
     * Penyapuan dibatasi (opd_id, periode) — satu OPD lazim punya beberapa
     * periode Renstra sekaligus, dan tanpa batas ini menetapkan versi 2025-2029
     * akan memensiunkan seluruh Renstra 2020-2024 milik OPD yang sama.
     *
     * TUJUAN DIPERLAKUKAN KHUSUS. `renstra_tujuan` tidak punya pemilik
     * (temuan T4) dan secara teori bisa dirujuk sasaran OPD lain. Karena itu
     * sebuah tujuan hanya dipensiunkan bila SETELAH penyapuan ini ia tidak lagi
     * punya satu pun sasaran hidup — lintas OPD, bukan hanya dalam lingkup.
     * Tanpa syarat itu, OPD A bisa mematikan tujuan yang masih dipakai OPD B.
     */
    private function pensiunkanYangHilang(
        int $versiId,
        VersionScope $scope,
        array $dipakai,
        int $berlakuMulaiTahun,
        string $now
    ): int {
        $alasan = $this->alasanPensiun($versiId);
        $sampai = $berlakuMulaiTahun - 1;
        $total  = 0;

        // --- sasaran dalam lingkup ---
        $sasaranHidup = $this->kolomId($this->db->table('renstra_sasaran')->select('id')
            ->where('opd_id', $scope->opdId())
            ->where('tahun_mulai', $scope->periodeMulai())
            ->where('tahun_akhir', $scope->periodeAkhir())
            ->where('dihentikan_pada IS NULL', null, false)
            ->get()->getResultArray());

        $sasaranHilang = array_values(array_diff($sasaranHidup, $dipakai['sasaran']));
        $total += $this->pensiunkan('renstra_sasaran', $sasaranHilang, $sampai, $alasan, $now);

        // --- indikator sasaran ---
        $indHilang = $this->hilangDiBawah(
            'renstra_indikator_sasaran',
            'renstra_sasaran_id',
            $sasaranHidup,
            $sasaranHilang,
            $dipakai['ind_sasaran']
        );
        $total += $this->pensiunkan('renstra_indikator_sasaran', $indHilang, $sampai, $alasan, $now, true);

        // --- indikator tujuan, hanya di bawah tujuan yang tersentuh versi ini ---
        if ($dipakai['tujuan'] !== []) {
            $itHilang = $this->hilangDiBawah(
                'renstra_indikator_tujuan',
                'tujuan_id',
                $dipakai['tujuan'],
                [],
                $dipakai['ind_tujuan']
            );
            $total += $this->pensiunkan('renstra_indikator_tujuan', $itHilang, $sampai, $alasan, $now);
        }

        // --- tujuan yatim: hanya bila tak ada sasaran hidup mana pun ---
        $total += $this->pensiunkanTujuanYatim($sasaranHilang, $alasan, $sampai, $now);

        return $total;
    }

    /**
     * Pensiunkan tujuan yang kehilangan seluruh sasarannya.
     *
     * Diperiksa LINTAS OPD dengan sengaja: tujuan tanpa opd_id adalah milik
     * bersama, dan mematikannya sepihak akan memutus Renstra OPD lain yang
     * masih memakainya.
     */
    private function pensiunkanTujuanYatim(array $sasaranHilang, string $alasan, int $sampai, string $now): int
    {
        if ($sasaranHilang === []) {
            return 0;
        }

        $tujuanKandidat = $this->kolomId(
            $this->db->table('renstra_sasaran')->select('renstra_tujuan_id')
                ->whereIn('id', $sasaranHilang)
                ->groupBy('renstra_tujuan_id')
                ->get()->getResultArray(),
            'renstra_tujuan_id'
        );

        $yatim = [];

        foreach ($tujuanKandidat as $tid) {
            if ($tid <= 0) {
                continue;
            }

            $masihDipakai = (int) $this->db->table('renstra_sasaran')
                ->where('renstra_tujuan_id', $tid)
                ->where('dihentikan_pada IS NULL', null, false)
                ->countAllResults();

            if ($masihDipakai === 0) {
                $yatim[] = $tid;
            }
        }

        return $this->pensiunkan('renstra_tujuan', $yatim, $sampai, $alasan, $now);
    }

    /* =========================================================
     * BANTU
     * =======================================================*/

    private function sasaranDalamLingkup(VersionScope $scope): array
    {
        return $this->db->table('renstra_sasaran')
            ->where('opd_id', $scope->opdId())
            ->where('tahun_mulai', $scope->periodeMulai())
            ->where('tahun_akhir', $scope->periodeAkhir())
            ->where('dihentikan_pada IS NULL', null, false)
            ->orderBy('renstra_tujuan_id', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * `renstra_tujuan.rpjmd_sasaran_id` ber-FK NOT NULL-able ke rpjmd_sasaran.
     * Bila baris RPJMD yang dirujuk sudah tidak ada, menulis id-nya akan
     * ditolak FK. Dikembalikan NULL supaya penerapan tetap jalan dan tautannya
     * bisa diperbaiki kemudian — teks induknya sudah dibekukan di arsip.
     */
    private function rpjmdSasaranSah(array $arsipTujuan): ?int
    {
        $id = $arsipTujuan['rpjmd_sasaran_id'] ?? null;

        if (empty($id)) {
            return null;
        }

        $ada = $this->db->table('rpjmd_sasaran')->where('id', (int) $id)->countAllResults() > 0;

        return $ada ? (int) $id : null;
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
