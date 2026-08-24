<?php

namespace App\Services\Version;

use App\Models\DokumenVersiModel;
use CodeIgniter\Database\ConnectionInterface;
use RuntimeException;

/**
 * Bandingkan isi dua versi dokumen (§23).
 *
 * §23 sengaja tidak menuntut tampilan sekelas Git — yang diminta empat tanda
 * dan keterbacaan:
 *
 *   +  ditambah
 *   ~  diubah
 *   -  tidak ada lagi
 *   =  tidak berubah
 *
 * =====================================================================
 * PENCOCOKAN LEWAT LINEAGE, BUKAN NAMA
 *
 * §11 dan §34 sama-sama melarang mengandalkan pencocokan nama. Dua indikator
 * berbeda bisa bernama sama persis, dan satu indikator bisa berganti nama tanpa
 * berganti makna — kalau dicocokkan lewat teks, keduanya salah baca.
 *
 * Kunci yang dipakai, berurutan dari yang paling kuat:
 *   1. `copied_from_id`   — B memang lahir sebagai salinan baris A
 *   2. `source_*_id`      — keduanya menunjuk baris live yang sama
 *
 * Baris yang tidak cocok lewat keduanya dilaporkan sebagai ditambah/dihapus,
 * bukan ditebak-tebak kemiripannya.
 * =====================================================================
 */
class VersionCompareService
{
    public const TAMBAH = '+';
    public const UBAH   = '~';
    public const HAPUS  = '-';
    public const TETAP  = '=';

    private ConnectionInterface $db;

    private DokumenVersiModel $versi;

    private ArsipRegistry $registry;

    public function __construct(
        ?ConnectionInterface $db = null,
        ?DokumenVersiModel $versi = null,
        ?ArsipRegistry $registry = null
    ) {
        $this->db       = $db ?? db_connect();
        $this->versi    = $versi ?? new DokumenVersiModel($this->db);
        $this->registry = $registry ?? new ArsipRegistry($this->db);
    }

    /**
     * Bandingkan versi A (lama) dengan versi B (baru).
     *
     * @return array{
     *     a:array, b:array,
     *     baris:array<int,array>,
     *     ringkasan:array{tambah:int,ubah:int,hapus:int,tetap:int}
     * }
     */
    public function banding(int $versiAId, int $versiBId): array
    {
        $a = $this->versi->ambil($versiAId);
        $b = $this->versi->ambil($versiBId);

        if ($a === null || $b === null) {
            throw new RuntimeException('Salah satu versi yang dibandingkan tidak ditemukan.');
        }

        if ($a['modul'] !== $b['modul']) {
            throw new RuntimeException('Hanya versi dari dokumen yang sama yang bisa dibandingkan.');
        }

        // Anti-IDOR: menebak id versi milik OPD lain tidak boleh membocorkan isinya.
        if (! VersionScope::dariBaris($a)->sama(VersionScope::dariBaris($b))) {
            throw new RuntimeException('Kedua versi harus berada pada lingkup dan periode yang sama.');
        }

        $arsip = $this->registry->untuk($a['modul']);

        if ($arsip === null || ! $arsip->siap()) {
            throw new RuntimeException('Arsip modul ' . $a['modul'] . ' tidak tersedia untuk dibandingkan.');
        }

        $itemA = $this->ratakan($arsip->isi($versiAId), $a['modul']);
        $itemB = $this->ratakan($arsip->isi($versiBId), $a['modul']);

        return $this->cocokkan($itemA, $itemB, $a, $b);
    }

    /* =========================================================
     * PERATAAN POHON -> DAFTAR INDIKATOR
     * =======================================================*/

    /**
     * Ratakan pohon arsip menjadi daftar indikator beserta konteks & targetnya.
     *
     * Indikator dipilih sebagai satuan pembanding karena di situlah perubahan
     * yang berarti terjadi: target berubah, satuan berubah, indikator diganti.
     * Perubahan pada sasaran/tujuan ikut terbaca lewat kolom konteksnya.
     */
    private function ratakan(array $pohon, string $modul): array
    {
        $out = [];

        // RPJMD berpuncak di misi, Renstra langsung di tujuan.
        $tujuanSemua = $modul === VersionScope::MODUL_RPJMD
            ? array_merge(...array_map(static fn ($m) => $m['tujuan'] ?? [], $pohon) ?: [[]])
            : $pohon;

        foreach ($tujuanSemua as $t) {
            $namaTujuan = $t['tujuan_rpjmd'] ?? $t['tujuan'] ?? '';

            foreach ($t['sasaran'] ?? [] as $s) {
                $namaSasaran = $s['sasaran_rpjmd'] ?? $s['sasaran'] ?? '';

                foreach ($s['indikator'] ?? [] as $i) {
                    $target = [];

                    foreach ($i['target'] ?? [] as $tg) {
                        $target[(string) $tg['tahun']] = (string) ($tg['target_tahunan'] ?? $tg['target'] ?? '');
                    }

                    ksort($target);

                    $out[] = [
                        'arsip_id'            => (int) $i['id'],
                        'copied_from_id'      => ! empty($i['copied_from_id']) ? (int) $i['copied_from_id'] : null,
                        'source_indikator_id' => ! empty($i['source_indikator_id']) ? (int) $i['source_indikator_id'] : null,
                        'tujuan'              => $namaTujuan,
                        'sasaran'             => $namaSasaran,
                        'indikator'           => (string) ($i['indikator_sasaran'] ?? ''),
                        'satuan'              => (string) ($i['satuan_nama'] ?? $i['satuan'] ?? ''),
                        'jenis_indikator'     => (string) ($i['jenis_indikator'] ?? ''),
                        'baseline'            => (string) ($i['baseline'] ?? ''),
                        'jenis_perubahan'     => (string) ($i['jenis_perubahan'] ?? 'tetap'),
                        'perubahan_substansial' => (int) ($i['perubahan_substansial'] ?? 0),
                        'target'              => $target,
                    ];
                }
            }
        }

        return $out;
    }

    /* =========================================================
     * PENCOCOKAN
     * =======================================================*/

    private function cocokkan(array $itemA, array $itemB, array $a, array $b): array
    {
        // Peta A menurut kedua kunci lineage.
        $petaA = [];

        foreach ($itemA as $idx => $x) {
            $petaA['id:' . $x['arsip_id']] = $idx;

            if ($x['source_indikator_id'] !== null) {
                $petaA['src:' . $x['source_indikator_id']] ??= $idx;
            }
        }

        $terpakai = [];
        $baris    = [];
        $ringkas  = [self::TAMBAH => 0, self::UBAH => 0, self::HAPUS => 0, self::TETAP => 0];

        foreach ($itemB as $y) {
            $idx = null;

            // Kunci terkuat lebih dulu: B memang salinan baris A tertentu.
            if ($y['copied_from_id'] !== null && isset($petaA['id:' . $y['copied_from_id']])) {
                $idx = $petaA['id:' . $y['copied_from_id']];
            } elseif ($y['source_indikator_id'] !== null && isset($petaA['src:' . $y['source_indikator_id']])) {
                $idx = $petaA['src:' . $y['source_indikator_id']];
            }

            if ($idx === null || isset($terpakai[$idx])) {
                $baris[] = $this->barisBanding(self::TAMBAH, null, $y);
                $ringkas[self::TAMBAH]++;

                continue;
            }

            $terpakai[$idx] = true;
            $x = $itemA[$idx];

            $beda = $this->beda($x, $y);
            $tanda = $beda === [] ? self::TETAP : self::UBAH;

            $baris[] = $this->barisBanding($tanda, $x, $y, $beda);
            $ringkas[$tanda]++;
        }

        foreach ($itemA as $idx => $x) {
            if (! isset($terpakai[$idx])) {
                $baris[] = $this->barisBanding(self::HAPUS, $x, null);
                $ringkas[self::HAPUS]++;
            }
        }

        // Yang berubah dan yang hilang naik ke atas: itu yang perlu dibaca.
        usort($baris, static function ($p, $q) {
            $urut = [self::UBAH => 0, self::HAPUS => 1, self::TAMBAH => 2, self::TETAP => 3];

            return ($urut[$p['tanda']] <=> $urut[$q['tanda']])
                ?: strcmp($p['sasaran'] . $p['indikator'], $q['sasaran'] . $q['indikator']);
        });

        return [
            'a'         => $a,
            'b'         => $b,
            'baris'     => $baris,
            'ringkasan' => [
                'tambah' => $ringkas[self::TAMBAH],
                'ubah'   => $ringkas[self::UBAH],
                'hapus'  => $ringkas[self::HAPUS],
                'tetap'  => $ringkas[self::TETAP],
            ],
        ];
    }

    /** @return array<string,array{0:string,1:string}> kolom => [lama, baru] */
    private function beda(array $x, array $y): array
    {
        $beda = [];

        foreach (['tujuan', 'sasaran', 'indikator', 'satuan', 'jenis_indikator', 'baseline'] as $k) {
            if ((string) $x[$k] !== (string) $y[$k]) {
                $beda[$k] = [(string) $x[$k], (string) $y[$k]];
            }
        }

        foreach (array_unique(array_merge(array_keys($x['target']), array_keys($y['target']))) as $th) {
            $lama = $x['target'][$th] ?? '';
            $baru = $y['target'][$th] ?? '';

            if ($lama !== $baru) {
                $beda['target ' . $th] = [$lama, $baru];
            }
        }

        return $beda;
    }

    private function barisBanding(string $tanda, ?array $x, ?array $y, array $beda = []): array
    {
        $sumber = $y ?? $x;

        return [
            'tanda'                 => $tanda,
            'tujuan'                => $sumber['tujuan'],
            'sasaran'               => $sumber['sasaran'],
            'indikator'             => $sumber['indikator'],
            'satuan'                => $sumber['satuan'],
            'target'                => $sumber['target'],
            'jenis_perubahan'       => $sumber['jenis_perubahan'] ?? 'tetap',
            'perubahan_substansial' => $sumber['perubahan_substansial'] ?? 0,
            'beda'                  => $beda,
        ];
    }
}
