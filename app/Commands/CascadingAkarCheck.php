<?php

namespace App\Commands;

use App\Models\CascadingModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Bandingkan matriks Cascading berakar RENSTRA dengan yang berakar IKU.
 *
 *   php spark casc:akar-check            seluruh OPD
 *   php spark casc:akar-check --opd 7    satu OPD
 *   php spark casc:akar-check --rinci    tampilkan tiap selisih
 *
 * =====================================================================
 * UNTUK APA
 *
 * Matriks Cascading dibangun `FROM renstra_sasaran`: daftar baris Eselon II
 * ditentukan Renstra. Membalik akarnya ke IKU mengubah daftar itu — dan
 * pertanyaannya bukan "apakah berubah" (pasti berubah), melainkan
 * "apakah ada isian operator yang hilang".
 *
 * =====================================================================
 * YANG DIJAMIN DI SINI
 *
 * 1. TIDAK ADA BARIS CASCADING YANG HILANG. Setiap sasaran/indikator
 *    Eselon III, IV, dan Pelaksana yang tampil pada akar Renstra HARUS
 *    tampil juga pada akar IKU. Inilah jaminan pokoknya: isian operator
 *    tidak boleh lenyap dari layar hanya karena akarnya dibalik.
 *
 * 2. TEKS ESELON II TIDAK BERUBAH untuk baris yang sama. Bila berubah,
 *    berarti salah satu akar membaca sumber yang keliru.
 *
 * 3. TULANG PUNGGUNG RPJMD TIDAK MEMBURUK. Jumlah baris yang kehilangan
 *    rangkaian Tujuan/Sasaran RPJMD tidak boleh bertambah.
 *
 * Selisih DAFTAR indikator Eselon II dilaporkan sebagai keterangan, bukan
 * kegagalan: indikator yang hanya ada di Renstra memang seharusnya tidak
 * lagi ditawarkan, dan yang hanya ada di IKU memang seharusnya mulai
 * muncul. Yang penting keduanya diketahui, bukan mengejutkan.
 */
class CascadingAkarCheck extends BaseCommand
{
    protected $group       = 'SAKIP';
    protected $name        = 'casc:akar-check';
    protected $description = 'Bandingkan matriks Cascading berakar Renstra vs berakar IKU.';
    protected $usage       = 'casc:akar-check [--opd <id>] [--rinci]';
    protected $options     = [
        '--opd'   => 'Batasi pada satu OPD.',
        '--rinci' => 'Tampilkan tiap selisih, bukan hanya ringkasannya.',
    ];

    private int $lulus = 0;
    private int $gagal = 0;

    /** Kunci sebuah baris cascading — inilah isian operator yang tidak boleh hilang. */
    private function kunciCascading(array $r): array
    {
        $kunci = [];

        foreach ([
            'es3' => ['es3_id', 'es3_indikator_id'],
            'es4' => ['es4_id', 'es4_indikator_id'],
            'pel' => ['pelaksana_id', 'pelaksana_indikator_id'],
        ] as $tingkat => $kolom) {
            foreach ($kolom as $k) {
                if (! empty($r[$k])) {
                    $kunci[] = $tingkat . ':' . $k . ':' . $r[$k];
                }
            }
        }

        return $kunci;
    }

    /**
     * Kunci Eselon II: TEKS indikator yang dinormalkan, bukan `indikator_id`.
     *
     * Id-nya justru yang sedang dibalik maknanya — pada akar Renstra ia id
     * indikator Renstra, pada akar IKU id indikator IKU. Membandingkan lewat
     * id membuat indikator yang sama tercatat "hilang" sekaligus "muncul",
     * dan perbandingannya jadi tidak berarti apa-apa. Teks berlaku di kedua
     * akar.
     */
    private function kunciEs2(array $r): ?string
    {
        $teks = trim(preg_replace('/\s+/', ' ', (string) ($r['indikator_sasaran'] ?? '')));

        return $teks === '' ? null : mb_strtolower($teks);
    }

    /** @return array{cascading: array<string,bool>, es2: array<string,string>, per_es2: array<string,array<string,bool>>, tanpa_rpjmd: int} */
    private function ringkas(array $rows): array
    {
        $cascading  = [];
        $es2        = [];
        $perEs2     = [];
        $tanpaRpjmd = 0;

        foreach ($rows as $r) {
            $kunciEs2 = $this->kunciEs2($r);

            foreach ($this->kunciCascading($r) as $k) {
                $cascading[$k] = true;

                if ($kunciEs2 !== null) {
                    $perEs2[$kunciEs2][$k] = true;
                }
            }

            if ($kunciEs2 !== null) {
                $es2[$kunciEs2] = trim((string) ($r['indikator_sasaran'] ?? ''));
            }

            if (empty($r['tujuan_rpjmd']) && empty($r['sasaran_rpjmd'])) {
                $tanpaRpjmd++;
            }
        }

        return ['cascading' => $cascading, 'es2' => $es2, 'per_es2' => $perEs2, 'tanpa_rpjmd' => $tanpaRpjmd];
    }

    private function cek(string $nama, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            $this->lulus++;
            CLI::write('    ' . CLI::color('LULUS', 'green') . '  ' . $nama);

            return;
        }

        $this->gagal++;
        CLI::write('    ' . CLI::color('GAGAL', 'red') . '  ' . $nama . ($detail !== '' ? '  -> ' . $detail : ''));
    }

    public function run(array $params)
    {
        $db    = Database::connect();
        $model = new CascadingModel();
        $rinci = array_key_exists('rinci', $params) || CLI::getOption('rinci') !== null;
        $opdOpt = CLI::getOption('opd');

        CLI::write('Basis data: ' . $db->getDatabase(), 'yellow');

        if (! $model->jangkarIkuTersedia()) {
            CLI::error('Kolom jangkar IKU belum ada — jalankan db/update_2026-08-27_cascading_sumber_iku.sql.');

            return 1;
        }

        // Lingkup uji: tiap OPD + periode yang benar-benar punya baris cascading.
        $b = $db->table('cascading_sasaran_opd c')
            ->select('c.opd_id, rs.tahun_mulai, rs.tahun_akhir, COUNT(*) AS baris', false)
            ->join('renstra_indikator_sasaran ri', 'ri.id = c.renstra_indikator_sasaran_id')
            ->join('renstra_sasaran rs', 'rs.id = ri.renstra_sasaran_id')
            ->groupBy('c.opd_id, rs.tahun_mulai, rs.tahun_akhir');

        if ($opdOpt !== null && $opdOpt !== true) {
            $b->where('c.opd_id', (int) $opdOpt);
        }

        $lingkup = $b->orderBy('c.opd_id', 'ASC')->get()->getResultArray();

        if ($lingkup === []) {
            CLI::error('Tidak ada lingkup cascading untuk diuji.');

            return 1;
        }

        $totalHilang = 0;
        $totalBeda   = 0;
        $ringkasan   = [];

        foreach ($lingkup as $lk) {
            $opdId = (int) $lk['opd_id'];
            $tm    = (int) $lk['tahun_mulai'];
            $ta    = (int) $lk['tahun_akhir'];

            $nama = $db->table('opd')->select('nama_opd')->where('id', $opdId)
                ->get()->getRowArray()['nama_opd'] ?? ('OPD ' . $opdId);

            CLI::newLine();
            CLI::write('  ' . $nama . ' — ' . $tm . '-' . $ta, 'cyan');

            try {
                $lama = $this->ringkas($model->getCascadingMatrixByOpd($opdId, $tm, $ta));
                $baru = $this->ringkas($model->getCascadingMatrixByOpd($opdId, $tm, $ta, null, 'iku'));
            } catch (Throwable $e) {
                $this->cek('query berjalan', false, $e->getMessage());

                continue;
            }

            // --- 1. Tidak ada baris cascading yang hilang ------------------
            $hilang = array_diff_key($lama['cascading'], $baru['cascading']);
            $this->cek(
                'isian operator utuh (' . count($lama['cascading']) . ' baris cascading)',
                $hilang === [],
                count($hilang) . ' hilang'
            );
            $totalHilang += count($hilang);

            if ($rinci && $hilang !== []) {
                foreach (array_slice(array_keys($hilang), 0, 10) as $h) {
                    CLI::write('        hilang: ' . $h);
                }
            }

            // --- 2. Turunan tiap Eselon II tetap sama ---------------------
            // Lebih tajam daripada membandingkan himpunan global: memastikan
            // baris cascading tidak berpindah induk saat akarnya dibalik.
            $bersama = array_intersect_key($lama['es2'], $baru['es2']);
            $beda    = [];

            foreach ($bersama as $kunci => $teks) {
                $a = $lama['per_es2'][$kunci] ?? [];
                $c = $baru['per_es2'][$kunci] ?? [];

                if (array_diff_key($a, $c) !== [] || array_diff_key($c, $a) !== []) {
                    $beda[$kunci] = $teks;
                }
            }

            $this->cek('turunan tiap Eselon II tetap sama (' . count($bersama) . ' indikator beririsan)', $beda === []);
            $totalBeda += count($beda);

            if ($rinci && $beda !== []) {
                foreach (array_slice($beda, 0, 5, true) as $teks) {
                    CLI::write('        berpindah induk: ' . $teks);
                }
            }

            // --- 3. Tulang punggung RPJMD tidak memburuk -------------------
            $this->cek(
                'rangkaian RPJMD tidak memburuk (' . $lama['tanpa_rpjmd'] . ' -> ' . $baru['tanpa_rpjmd'] . ')',
                $baru['tanpa_rpjmd'] <= $lama['tanpa_rpjmd']
            );

            // --- Keterangan: selisih daftar indikator Eselon II ------------
            $hanyaRenstra = array_diff_key($lama['es2'], $baru['es2']);
            $hanyaIku     = array_diff_key($baru['es2'], $lama['es2']);

            if ($hanyaRenstra !== [] || $hanyaIku !== []) {
                CLI::write('    catatan  daftar Eselon II berubah: -'
                    . count($hanyaRenstra) . ' hanya-Renstra, +' . count($hanyaIku) . ' hanya-IKU');

                foreach ($hanyaRenstra as $teks) {
                    $ringkasan[] = ['HILANG', $nama, $tm . '-' . $ta, mb_substr($teks, 0, 58)];
                }
                foreach ($hanyaIku as $teks) {
                    $ringkasan[] = ['MUNCUL', $nama, $tm . '-' . $ta, mb_substr($teks, 0, 58)];
                }
            }
        }

        // --- 4. Sisi tulis: penerjemahan jangkar harus konsisten dua arah --
        //
        // Baris cascading menyimpan KEDUA jangkar. Apa pun akar yang aktif,
        // jangkarDariEs2() harus menghasilkan pasangan yang sama dengan yang
        // sudah tersimpan — kalau tidak, baris yang dibuat sesudah akarnya
        // dibalik akan berjangkar ke induk yang berbeda dari saudaranya.
        CLI::newLine();
        CLI::write('  Sisi tulis — penerjemahan jangkar', 'cyan');

        $baris = $db->table('cascading_sasaran_opd')
            ->select('id, renstra_indikator_sasaran_id, iku_indikator_id')
            ->where('iku_indikator_id IS NOT NULL', null, false)
            ->where('renstra_indikator_sasaran_id IS NOT NULL', null, false);

        if ($opdOpt !== null && $opdOpt !== true) {
            $baris->where('opd_id', (int) $opdOpt);
        }

        $melesetIku = $melesetRenstra = 0;
        $diuji      = 0;

        foreach ($baris->get()->getResultArray() as $r) {
            $diuji++;
            $renstraId = (int) $r['renstra_indikator_sasaran_id'];
            $ikuId     = (int) $r['iku_indikator_id'];

            $dariRenstra = $model->jangkarDariEs2($renstraId, 'renstra');
            $dariIku     = $model->jangkarDariEs2($ikuId, 'iku');

            if ((int) ($dariRenstra['iku_indikator_id'] ?? 0) !== $ikuId) {
                $melesetIku++;
            }
            if ((int) ($dariIku['renstra_indikator_sasaran_id'] ?? 0) !== $renstraId) {
                $melesetRenstra++;
            }
        }

        $this->cek('Renstra -> IKU menemukan jangkar yang sama (' . $diuji . ' baris)', $melesetIku === 0,
            $melesetIku . ' meleset');
        $this->cek('IKU -> Renstra menemukan jangkar yang sama', $melesetRenstra === 0,
            $melesetRenstra . ' meleset');

        CLI::newLine();

        if ($ringkasan !== []) {
            CLI::write('Perubahan daftar indikator Eselon II:', 'yellow');
            CLI::table($ringkasan, ['Nasib', 'OPD', 'Periode', 'Indikator']);
            CLI::newLine();
        }

        CLI::write('LULUS: ' . $this->lulus . '   GAGAL: ' . $this->gagal,
            $this->gagal === 0 ? 'green' : 'red');
        CLI::write('Baris cascading hilang: ' . $totalHilang . '   Teks Eselon II berubah: ' . $totalBeda,
            ($totalHilang === 0 && $totalBeda === 0) ? 'green' : 'red');

        return $this->gagal === 0 ? 0 : 1;
    }
}
