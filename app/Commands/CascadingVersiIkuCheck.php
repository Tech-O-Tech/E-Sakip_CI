<?php

namespace App\Commands;

use App\Models\CascadingModel;
use App\Models\Opd\IkuRevisiModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Uji cascading membaca VERSI IKU tertentu.
 *
 *   php spark casc:versi-check
 *
 * Yang dijamin di sini:
 *   1. tanpa parameter versi, perilakunya persis seperti sebelumnya
 *      (IKU berjalan) — penanda versi kosong;
 *   2. dengan versi, teks Eselon II diambil dari ARSIP revisi itu, bukan
 *      dari tabel berjalan;
 *   3. tiap baris membawa penanda apa yang terjadi pada indikator induknya
 *      di versi itu — termasuk 'tidak_ada' untuk jangkar yang tidak dibawa;
 *   4. jumlah baris TIDAK berubah: membaca versi lama tidak boleh
 *      menghilangkan baris cascading yang sudah diinput.
 */
class CascadingVersiIkuCheck extends BaseCommand
{
    protected $group       = 'SAKIP';
    protected $name        = 'casc:versi-check';
    protected $description = 'Uji cascading membaca versi IKU tertentu beserta penanda perubahannya.';

    private int $lulus = 0;
    private int $gagal = 0;

    private function cek(string $nama, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            $this->lulus++;
            CLI::write('  ' . CLI::color('LULUS', 'green') . '  ' . $nama);

            return;
        }

        $this->gagal++;
        CLI::write('  ' . CLI::color('GAGAL', 'red') . '  ' . $nama
            . ($detail !== '' ? '  -> ' . $detail : ''));
    }

    public function run(array $params)
    {
        $db    = Database::connect();
        $model = new CascadingModel();
        $rev   = new IkuRevisiModel();

        CLI::write('Basis data: ' . $db->getDatabase(), 'yellow');
        CLI::newLine();

        if (! $rev->siap() || ! $db->fieldExists('iku_indikator_id', 'cascading_sasaran_opd')) {
            CLI::error('Prasyarat belum ada (tabel revisi / kolom jangkar IKU).');

            return 1;
        }

        // Lingkup uji: OPD yang punya cascading berjangkar IKU DAN punya revisi.
        $kandidat = $db->table('cascading_sasaran_opd c')
            ->select('c.opd_id, COUNT(*) AS n', false)
            ->where('c.iku_indikator_id IS NOT NULL', null, false)
            ->groupBy('c.opd_id')->orderBy('n', 'DESC')
            ->get()->getResultArray();

        $opdId = null;
        $revisi = null;

        foreach ($kandidat as $k) {
            $periode = $db->table('renstra_sasaran')
                ->select('tahun_mulai, tahun_akhir, COUNT(*) AS n', false)
                ->where('opd_id', (int) $k['opd_id'])
                ->groupBy(['tahun_mulai', 'tahun_akhir'])
                ->orderBy('n', 'DESC')->limit(1)->get()->getRowArray();

            if ($periode === null) {
                continue;
            }

            $daftar = array_values(array_filter(
                $rev->daftar((int) $k['opd_id'], (int) $periode['tahun_mulai'], (int) $periode['tahun_akhir']),
                static fn ($r) => in_array($r['status'], ['berlaku', 'superseded'], true)
            ));

            if ($daftar !== []) {
                $opdId  = (int) $k['opd_id'];
                $tm     = (int) $periode['tahun_mulai'];
                $ta     = (int) $periode['tahun_akhir'];
                $revisi = $daftar[0];
                break;
            }
        }

        if ($opdId === null) {
            CLI::write('Belum ada OPD dengan cascading berjangkar IKU + revisi resmi.', 'yellow');
            CLI::write('(uji dilewati, bukan gagal)');

            return 0;
        }

        CLI::write("Lingkup uji: OPD {$opdId}, periode {$tm}-{$ta}, revisi #{$revisi['id']}", 'dark_gray');
        CLI::newLine();

        // ---------------------------------------------------------------
        CLI::write('== 1. Tanpa versi = perilaku lama ==', 'cyan');
        // ---------------------------------------------------------------
        $live = $model->getCascadingMatrixByOpd($opdId, $tm, $ta);

        $this->cek('matriks terbaca', $live !== [], 'baris: ' . count($live));

        if ($live === []) {
            return $this->tutup();
        }

        $this->cek('kolom penanda versi ada', array_key_exists('es2_versi_status', $live[0]));

        $semuaKosong = true;
        foreach ($live as $r) {
            if (($r['es2_versi_status'] ?? '') !== '') {
                $semuaKosong = false;
                break;
            }
        }

        $this->cek('penanda kosong saat membaca IKU berjalan', $semuaKosong);

        // ---------------------------------------------------------------
        CLI::newLine();
        CLI::write('== 2. Membaca versi tertentu ==', 'cyan');
        // ---------------------------------------------------------------
        $versi = $model->getCascadingMatrixByOpd($opdId, $tm, $ta, (int) $revisi['id']);

        $this->cek('jumlah baris TIDAK berubah', count($versi) === count($live),
            count($live) . ' vs ' . count($versi));

        $berpenanda = array_values(array_filter(
            $versi,
            static fn ($r) => ($r['es2_versi_status'] ?? '') !== ''
        ));

        $this->cek('ada baris berpenanda versi', $berpenanda !== [], count($berpenanda) . ' baris');

        $sah = ['tetap', 'revisi', 'baru', 'pengganti', 'dihentikan', 'tidak_ada'];
        $semuaSah = true;
        foreach ($berpenanda as $r) {
            if (! in_array($r['es2_versi_status'], $sah, true)) {
                $semuaSah = false;
                break;
            }
        }

        $this->cek('nilai penandanya sah semua', $semuaSah);

        // ---------------------------------------------------------------
        CLI::newLine();
        CLI::write('== 3. Teks diambil dari arsip, bukan tabel berjalan ==', 'cyan');
        // ---------------------------------------------------------------
        $contoh = null;
        foreach ($versi as $r) {
            if (! empty($r['es2_iku_indikator_id']) && ($r['es2_versi_status'] ?? '') !== 'tidak_ada') {
                $contoh = $r;
                break;
            }
        }

        if ($contoh === null) {
            CLI::write('  (dilewati — tidak ada baris berjangkar yang dibawa versi ini)', 'dark_gray');

            return $this->tutup();
        }

        $ikuId = (int) $contoh['es2_iku_indikator_id'];
        $asli  = $db->table('iku_indikator')->select('indikator')->where('id', $ikuId)
            ->get()->getRowArray();

        $teksUji = 'UJI VERSI CASCADING ' . $ikuId;
        $db->table('iku_indikator')->where('id', $ikuId)->update(['indikator' => $teksUji]);

        try {
            $sesudahLive  = $model->getCascadingMatrixByOpd($opdId, $tm, $ta);
            $sesudahVersi = $model->getCascadingMatrixByOpd($opdId, $tm, $ta, (int) $revisi['id']);

            $liveIkut = false;
            foreach ($sesudahLive as $r) {
                if ((int) ($r['es2_iku_indikator_id'] ?? 0) === $ikuId && $r['indikator_sasaran'] === $teksUji) {
                    $liveIkut = true;
                    break;
                }
            }

            $versiIkut = false;
            foreach ($sesudahVersi as $r) {
                if ((int) ($r['es2_iku_indikator_id'] ?? 0) === $ikuId && $r['indikator_sasaran'] === $teksUji) {
                    $versiIkut = true;
                    break;
                }
            }

            $this->cek('IKU berjalan IKUT berubah', $liveIkut);
            $this->cek('tampilan versi TIDAK ikut berubah (arsip beku)', ! $versiIkut);
        } finally {
            $db->table('iku_indikator')->where('id', $ikuId)->update(['indikator' => $asli['indikator']]);
        }

        $pulih = $db->table('iku_indikator')->select('indikator')->where('id', $ikuId)
            ->get()->getRowArray();

        $this->cek('teks IKU dikembalikan seperti semula', $pulih['indikator'] === $asli['indikator']);

        return $this->tutup();
    }

    private function tutup(): int
    {
        CLI::newLine();
        CLI::write('LULUS: ' . CLI::color((string) $this->lulus, 'green')
            . '   GAGAL: ' . CLI::color((string) $this->gagal, $this->gagal > 0 ? 'red' : 'green'));

        return $this->gagal > 0 ? 1 : 0;
    }
}
