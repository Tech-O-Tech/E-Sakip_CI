<?php

namespace App\Commands;

use App\Models\Opd\IkuModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * Uji sync IKU "ambil seluruhnya" — tanpa centang per indikator.
 *
 *   php spark iku:sync-penuh-check
 *
 * Sejak layar sync tidak lagi memakai kotak centang, dua hal WAJIB benar dan
 * keduanya pernah salah:
 *
 *   1. indikator yang BERUBAH ikut terambil. Dulu keranjang `perbarui` dibuang
 *      diam-diam pada jalur tabel hidup — layar melapor sukses, nilainya tidak
 *      berubah sedikit pun.
 *   2. keterangan yang diketik di IKU (definisi operasional, rumusan, sumber
 *      data, penanggung jawab) TIDAK ikut tertimpa. Keempatnya tidak punya
 *      padanan di Renstra/RPJMD, jadi menimpanya = menghapus pekerjaan orang.
 *
 * Fixture dibuat pada periode 2085-2089 yang mustahil dipakai data nyata, dan
 * dibersihkan lagi di akhir — sukses maupun gagal.
 */
class IkuSyncPenuhCheck extends BaseCommand
{
    protected $group       = 'SAKIP';
    protected $name        = 'iku:sync-penuh-check';
    protected $description = 'Uji sync IKU mengambil seluruh isi sumber, termasuk yang berubah.';

    private const TM    = 2085;
    private const TA    = 2089;
    private const TANDA = 'UJI-SYNC-PENUH';

    private int $lulus = 0;
    private int $gagal = 0;

    private function cek(string $nama, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            $this->lulus++;
            CLI::write('  ' . CLI::color('LULUS', 'green') . '  ' . $nama);
        } else {
            $this->gagal++;
            CLI::write('  ' . CLI::color('GAGAL', 'red') . '  ' . $nama
                . ($detail !== '' ? ' -> ' . $detail : ''));
        }
    }

    public function run(array $params)
    {
        $db  = Database::connect();
        $iku = new IkuModel();

        CLI::write('Basis data: ' . $db->getDatabase(), 'yellow');
        CLI::newLine();

        $opdId = (int) ($db->table('opd')->select('id')->orderBy('id', 'ASC')
            ->get()->getRowArray()['id'] ?? 0);

        if ($opdId <= 0) {
            CLI::error('Tidak ada OPD untuk dijadikan fixture.');

            return 1;
        }

        $this->bersihkan($db, $opdId);

        try {
            // ---- Renstra sumber: 1 sasaran, 2 indikator ------------------
            // renstra_tujuan tidak punya opd_id/tahun — kepemilikan &
            // periodenya hidup di renstra_sasaran.
            // rpjmd_sasaran_id wajib: FK-nya NOT NULL-able? bukan, tetapi
            // constraint-nya menolak nilai yang tidak menunjuk baris nyata.
            $rpjmdSasaran = $db->table('rpjmd_sasaran')->select('id')
                ->orderBy('id', 'ASC')->get()->getRowArray();

            $db->table('renstra_tujuan')->insert([
                'tujuan'           => self::TANDA . ' Tujuan',
                'rpjmd_sasaran_id' => $rpjmdSasaran['id'] ?? null,
            ]);
            $tujuanId = (int) $db->insertID();

            $db->table('renstra_sasaran')->insert([
                'renstra_tujuan_id' => $tujuanId, 'opd_id' => $opdId,
                'sasaran' => self::TANDA . ' Sasaran',
                'tahun_mulai' => self::TM, 'tahun_akhir' => self::TA,
            ]);
            $sasaranId = (int) $db->insertID();

            $indIds = [];
            foreach (['A', 'B'] as $n) {
                $db->table('renstra_indikator_sasaran')->insert([
                    'renstra_sasaran_id' => $sasaranId,
                    'indikator_sasaran'  => self::TANDA . ' Indikator ' . $n,
                    'satuan'             => '%',
                ]);
                $indIds[$n] = (int) $db->insertID();

                $db->table('renstra_target')->insert([
                    'renstra_indikator_id' => $indIds[$n], 'tahun' => self::TM, 'target' => '10',
                ]);
            }

            // ---- Sync pertama: keduanya BARU ----------------------------
            $kandidat = $iku->getKandidatSync('renstra', $opdId, self::TM, self::TA);
            [$baru, $berubah] = $this->keranjang($kandidat);

            $this->cek('sync-1: dua indikator terbaca BARU',
                count($baru[$sasaranId] ?? []) === 2, 'baru: ' . count($baru[$sasaranId] ?? []));
            $this->cek('sync-1: belum ada yang berubah', $berubah === []);

            $stat = $iku->importSync('renstra', $opdId, $baru, self::TM, self::TA, null, $berubah);
            $this->cek('sync-1: dua indikator tersalin', (int) $stat['indikator_baru'] === 2);

            // ---- Operator mengetik keterangan di IKU --------------------
            $ikuInd = $db->table('iku_indikator ii')
                ->select('ii.id, ii.indikator')
                ->join('iku_sasaran isa', 'isa.id = ii.iku_sasaran_id')
                ->where('isa.opd_id', $opdId)->where('isa.tahun_mulai', self::TM)
                ->orderBy('ii.id', 'ASC')->get()->getResultArray();

            $this->cek('IKU berisi dua indikator', count($ikuInd) === 2);

            $idA = (int) $ikuInd[0]['id'];
            $db->table('iku_indikator')->where('id', $idA)->update([
                'definisi'            => 'DEFINISI KETIKAN OPERATOR',
                'rumusan_perhitungan' => 'RUMUS KETIKAN OPERATOR',
                'sumber_data'         => 'SUMBER KETIKAN OPERATOR',
                'penanggung_jawab'    => 'PJ KETIKAN OPERATOR',
            ]);

            // ---- Renstra berubah: target & teks indikator A -------------
            $db->table('renstra_target')
                ->where('renstra_indikator_id', $indIds['A'])->where('tahun', self::TM)
                ->update(['target' => '95']);

            $kandidat = $iku->getKandidatSync('renstra', $opdId, self::TM, self::TA);
            [$baru2, $berubah2] = $this->keranjang($kandidat);

            $this->cek('sync-2: perubahan target terdeteksi "berubah"',
                count($berubah2[$sasaranId] ?? []) === 1, 'berubah: ' . count($berubah2[$sasaranId] ?? []));
            $this->cek('sync-2: tidak ada yang dianggap baru lagi', $baru2 === []);

            // ---- Sync kedua: TANPA centang, keranjang dari kandidat -----
            $stat2 = $iku->importSync('renstra', $opdId, $baru2, self::TM, self::TA, null, $berubah2);

            $this->cek('sync-2: satu indikator DIPERBARUI (dulu dibuang diam-diam)',
                (int) ($stat2['diperbarui'] ?? 0) === 1, 'diperbarui: ' . ($stat2['diperbarui'] ?? 0));

            $targetBaru = $db->table('iku_target')
                ->where('iku_indikator_id', $idA)->where('tahun', self::TM)
                ->get()->getRowArray();

            $this->cek('sync-2: target IKU ikut jadi 95',
                (string) ($targetBaru['target'] ?? '') === '95', 'aktual: ' . ($targetBaru['target'] ?? '-'));

            // ---- Keterangan operator HARUS selamat ----------------------
            $sesudah = $db->table('iku_indikator')->where('id', $idA)->get()->getRowArray();

            $this->cek('definisi operasional TIDAK tertimpa',
                $sesudah['definisi'] === 'DEFINISI KETIKAN OPERATOR');
            $this->cek('rumusan perhitungan TIDAK tertimpa',
                $sesudah['rumusan_perhitungan'] === 'RUMUS KETIKAN OPERATOR');
            $this->cek('sumber data TIDAK tertimpa',
                $sesudah['sumber_data'] === 'SUMBER KETIKAN OPERATOR');
            $this->cek('penanggung jawab TIDAK tertimpa',
                $sesudah['penanggung_jawab'] === 'PJ KETIKAN OPERATOR');

            // ---- Sync ketiga tanpa perubahan: tidak ada yang ditulis ----
            $kandidat = $iku->getKandidatSync('renstra', $opdId, self::TM, self::TA);
            [$baru3, $berubah3] = $this->keranjang($kandidat);

            $this->cek('sync-3: sudah sama, kedua keranjang kosong',
                $baru3 === [] && $berubah3 === []);
        } finally {
            $this->bersihkan($db, $opdId);
        }

        CLI::newLine();
        CLI::write('LULUS: ' . CLI::color((string) $this->lulus, 'green')
            . '   GAGAL: ' . CLI::color((string) $this->gagal, $this->gagal > 0 ? 'red' : 'green'));

        return $this->gagal > 0 ? 1 : 0;
    }

    /** Cermin IkuFormTrait::keranjangSyncPenuh() — sengaja disalin agar uji tidak ikut hanyut bila trait berubah. */
    private function keranjang(array $kandidat): array
    {
        $baru = [];
        $ubah = [];

        foreach ($kandidat as $s) {
            $sid = (int) ($s['sumber_id'] ?? 0);

            foreach ($s['indikator'] ?? [] as $i) {
                $iid = (int) ($i['sumber_id'] ?? 0);

                if (($i['banding'] ?? '') === 'baru') {
                    $baru[$sid][] = $iid;
                } elseif (($i['banding'] ?? '') === 'berubah') {
                    $ubah[$sid][] = $iid;
                }
            }
        }

        return [$baru, $ubah];
    }

    private function bersihkan($db, int $opdId): void
    {
        $sasIku = $db->table('iku_sasaran')->select('id')
            ->where('opd_id', $opdId)->where('tahun_mulai', self::TM)->get()->getResultArray();

        foreach ($sasIku as $s) {
            $ind = $db->table('iku_indikator')->select('id')
                ->where('iku_sasaran_id', $s['id'])->get()->getResultArray();

            foreach ($ind as $i) {
                $db->table('iku_target')->where('iku_indikator_id', $i['id'])->delete();
                $db->table('iku_program')->where('iku_indikator_id', $i['id'])->delete();
            }

            $db->table('iku_indikator')->where('iku_sasaran_id', $s['id'])->delete();
        }

        $db->table('iku_sasaran')->where('opd_id', $opdId)->where('tahun_mulai', self::TM)->delete();

        $sas = $db->table('renstra_sasaran')->select('id')
            ->where('opd_id', $opdId)->where('tahun_mulai', self::TM)->get()->getResultArray();

        foreach ($sas as $s) {
            $ind = $db->table('renstra_indikator_sasaran')->select('id')
                ->where('renstra_sasaran_id', $s['id'])->get()->getResultArray();

            foreach ($ind as $i) {
                $db->table('renstra_target')->where('renstra_indikator_id', $i['id'])->delete();
            }

            $db->table('renstra_indikator_sasaran')->where('renstra_sasaran_id', $s['id'])->delete();
        }

        $db->table('renstra_sasaran')->where('opd_id', $opdId)->where('tahun_mulai', self::TM)->delete();
        $db->table('renstra_tujuan')->like('tujuan', self::TANDA, 'after')->delete();
    }
}
