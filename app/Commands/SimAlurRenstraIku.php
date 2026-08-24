<?php

namespace App\Commands;

use App\Models\DokumenVersiModel;
use App\Models\Opd\IkuModel;
use App\Models\Opd\IkuRevisiModel;
use App\Models\Versi\RenstraVersiModel;
use App\Services\Version\IzinSuntingService;
use App\Services\Version\VersionApprovalService;
use App\Services\Version\VersionAuditService;
use App\Services\Version\VersionScope;
use App\Services\Version\VersionTimelineService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Simulasi alur penuh: Renstra disusun sampai IKU disahkan.
 *
 *   php spark sim:alur --db klon_uji          # jalankan penuh, lalu bersihkan
 *   php spark sim:alur --opd 11 --simpan      # hanya tanam data dummy, alurnya
 *                                             # dijalankan sendiri lewat layar
 *
 * =====================================================================
 * APA GUNANYA
 *
 * Uji satuan membuktikan tiap bagian bekerja sendiri-sendiri. Yang TIDAK
 * dibuktikannya adalah bahwa keenam belas langkah itu masih nyambung ketika
 * dijalankan berurutan oleh satu OPD sungguhan — dan justru sambungannya yang
 * berulang kali menyimpan cacat: tombol yang muncul di keadaan yang salah,
 * versi yang lahir dua kali, jejak yang hilang di tengah jalan.
 *
 * Perintah ini menjalankan seluruh rangkaian memakai layanan yang sama persis
 * dengan yang dipakai controller, lalu memeriksa hasil tiap langkah.
 *
 * =====================================================================
 * DENGAN --simpan
 *
 * Yang ditanam hanya ISI Renstra 2030-2034 (beserta RPJMD penopangnya) tanpa
 * satu pun versi. Itu disengaja: pengujinya sendiri yang akan menekan "Ajukan
 * Validasi" dari layar, sehingga yang teruji adalah alur sebagaimana dipakai,
 * bukan alur yang sudah dijalankan perintah ini.
 */
class SimAlurRenstraIku extends BaseCommand
{
    protected $group       = 'SAKIP';
    protected $name        = 'sim:alur';
    protected $description = 'Simulasi alur penuh Renstra -> versi -> IKU -> revisi IKU.';
    protected $usage       = 'sim:alur [--db <basis>] [--opd <id>] [--simpan] [--bersih]';
    protected $options     = [
        '--db'     => 'basis data lain (mis. salinan uji)',
        '--opd'    => 'id OPD yang dipakai (bawaan: 11)',
        '--simpan' => 'hanya tanam data dummy Renstra 2030-2034, jangan jalankan alurnya',
        '--bersih' => 'buang seluruh data dummy 2030-2034 beserta versinya',
    ];

    private const MULAI = 2030;
    private const AKHIR = 2034;
    private const TANDA = '[SIM2030]';

    private $db;
    private int $lulus = 0;
    private int $gagal = 0;

    public function run(array $params)
    {
        $namaDb = $params['db'] ?? CLI::getOption('db');
        $opdId  = (int) ($params['opd'] ?? CLI::getOption('opd') ?: 11);
        $simpan = in_array('--simpan', $_SERVER['argv'] ?? [], true);
        $bersih = in_array('--bersih', $_SERVER['argv'] ?? [], true);

        $cfg = config('Database')->default;

        if ($namaDb !== null && $namaDb !== true && trim((string) $namaDb) !== '') {
            $cfg['database'] = trim((string) $namaDb);
        }

        $this->db = db_connect($cfg, false);

        CLI::write('Basis data : ' . CLI::color($this->db->getDatabase(), 'yellow'));
        CLI::write('OPD        : ' . CLI::color((string) $opdId, 'yellow'));
        CLI::write('Periode    : ' . CLI::color(self::MULAI . '-' . self::AKHIR, 'yellow'));
        CLI::newLine();

        if ($bersih) {
            $this->bersihkan($opdId);
            CLI::write(CLI::color('Data dummy 2030-2034 dibuang.', 'green'));

            return 0;
        }

        try {
            $this->bersihkan($opdId);
            [$rpjmdSasaranId] = $this->tanamRpjmd();
            $this->tanamRenstra($opdId, $rpjmdSasaranId);

            CLI::write(CLI::color('Data dummy Renstra ' . self::MULAI . '-' . self::AKHIR
                . ' ditanam untuk OPD ' . $opdId . '.', 'green'));

            if ($simpan) {
                CLI::newLine();
                CLI::write('Alurnya TIDAK dijalankan (--simpan). Silakan uji sendiri lewat layar:');
                CLI::write('  1. Menu Renstra, pilih periode ' . self::MULAI . '-' . self::AKHIR);
                CLI::write('  2. Ajukan Validasi  ->  Admin Kabupaten menyetujui');
                CLI::write('  3. Menu IKU, Sync dari Renstra (pilih versinya)');
                CLI::write('  4. Ajukan Pengesahan  ->  Admin Kabupaten mengesahkan');

                return 0;
            }

            $this->jalankanAlur($opdId);
        } catch (Throwable $e) {
            $this->gagal++;
            CLI::error('GALAT TAK TERDUGA: ' . $e->getMessage());
            CLI::write($e->getFile() . ':' . $e->getLine());
        } finally {
            if (! $simpan) {
                $this->bersihkan($opdId);
                CLI::newLine();
                CLI::write(CLI::color('Data simulasi dibersihkan.', 'blue'));
            }
        }

        CLI::newLine();
        CLI::write('LULUS: ' . CLI::color((string) $this->lulus, 'green')
            . '   GAGAL: ' . CLI::color((string) $this->gagal, $this->gagal > 0 ? 'red' : 'green'));

        return $this->gagal > 0 ? 1 : 0;
    }

    /* =========================================================
     * ALUR
     * =======================================================*/

    private function jalankanAlur(int $opdId): void
    {
        $scope    = VersionScope::renstra($opdId, self::MULAI, self::AKHIR);
        $versi    = new DokumenVersiModel($this->db);
        $arsip    = new RenstraVersiModel($this->db);
        $audit    = new VersionAuditService($this->db);
        $timeline = new VersionTimelineService($this->db, $versi, $audit);
        $approval = new VersionApprovalService($this->db, $versi, $timeline, $audit);

        // ---------- 1. Renstra diajukan menjadi V1 ----------
        $this->bab('1. Renstra disusun lalu diajukan');

        $this->cek('belum ada versi apa pun', $versi->daftar($scope) === []);

        $v1 = $versi->sisipkan(array_merge($scope->kolomBaru(), [
            'version_no' => 1, 'label' => self::TANDA . ' V1 Renstra',
            'effective_from' => self::MULAI . '-01-01',
            'status' => DokumenVersiModel::STATUS_DRAFT, 'created_by' => 11,
        ]));

        $ringkas = $this->transaksi(fn () => $arsip->bekukanDariLive($v1, $scope));
        $this->cek('isi Renstra terbekukan ke arsip V1',
            ($ringkas['sasaran'] ?? 0) >= 2 && ($ringkas['indikator_sasaran'] ?? 0) >= 2);

        $approval->ajukan($v1, 11);
        $this->cek('V1 menunggu verifikasi', $this->status($v1) === DokumenVersiModel::STATUS_PENDING);
        $this->cek('muncul di antrean Admin Kabupaten', $this->diAntrean($versi, $v1));

        // ---------- 2. Admin Kabupaten menetapkan ----------
        $this->bab('2. Admin Kabupaten menetapkan V1');

        $sasaranSebelum = $this->hitungSasaranHidup($opdId);
        $approval->setujui($v1, 22);

        $this->cek('V1 berlaku', $this->status($v1) === DokumenVersiModel::STATUS_PUBLISHED);
        $this->cek('penetapan tidak memensiunkan Renstra sendiri',
            $this->hitungSasaranHidup($opdId) === $sasaranSebelum);
        $this->cek('tepat satu versi terbuka', $this->jumlahTerbuka($versi, $scope) === 1);

        // ---------- 3. Terkunci, lalu izin sunting ----------
        $this->bab('3. Renstra terkunci; izin sunting diminta');

        $izin = new IzinSuntingService($this->db);
        $this->cek('belum boleh disunting', ! $izin->bolehSunting($scope));

        $izinId = $izin->ajukan($scope, self::TANDA . ' perbaikan target', 11, $v1);
        $this->cek('permohonan menunggu keputusan', $izin->berjalan($scope)['status'] === 'pending');
        $this->cek('menunggu keputusan belum membuka kunci', ! $izin->bolehSunting($scope));

        $izin->setujui($izinId, 22);
        $this->cek('setelah disetujui, kunci terbuka', $izin->bolehSunting($scope));

        $sidikArsip = md5(json_encode($arsip->isi($v1)));

        // ---------- 4. Disunting, diajukan ulang -> V2 ----------
        $this->bab('4. Renstra disunting lalu diajukan ulang');

        $this->db->table('renstra_target')
            ->where('renstra_indikator_id', $this->indikatorPertama($opdId))
            ->where('tahun', self::MULAI)
            ->update(['target' => '88']);

        $v2 = $versi->sisipkan(array_merge($scope->kolomBaru(), [
            'version_no' => $versi->nomorBerikutnya($scope),
            'label' => self::TANDA . ' V2 Renstra (hasil penyuntingan)',
            'effective_from' => (self::MULAI + 1) . '-01-01',
            'status' => DokumenVersiModel::STATUS_DRAFT, 'created_by' => 11,
        ]));

        $this->transaksi(fn () => $arsip->bekukanDariLive($v2, $scope));
        $approval->ajukan($v2, 11);
        $izin->selesaikan($scope);

        $this->cek('izin ditutup setelah dipakai', $izin->berjalan($scope) === null);

        $approval->setujui($v2, 22);

        $this->cek('V2 berlaku', $this->status($v2) === DokumenVersiModel::STATUS_PUBLISHED);
        $this->cek('V1 TETAP UTUH — arsipnya tidak tersentuh',
            md5(json_encode($arsip->isi($v1))) === $sidikArsip);
        $this->cek('V1 kini punya batas akhir',
            $versi->ambil($v1)['effective_to'] === (self::MULAI + 1) . '-01-01');
        $this->cek('masih tepat satu versi terbuka', $this->jumlahTerbuka($versi, $scope) === 1);

        // ---------- 5. IKU disalin dari versi Renstra pilihan ----------
        $this->bab('5. IKU disalin dari versi Renstra pilihan');

        $iku       = new IkuModel($this->db);
        $tersedia  = $iku->versiRenstraTersedia($opdId, self::MULAI, self::AKHIR);

        $this->cek('kedua versi Renstra ditawarkan sebagai sumber', count($tersedia) === 2);

        $kandidat = $iku->getKandidatSync('renstra', $opdId, self::MULAI, self::AKHIR, $v2);
        $pilihan  = [];

        foreach ($kandidat as $s) {
            $pilihan[(int) $s['sumber_id']] = array_column($s['indikator'], 'sumber_id');
        }

        $stat = $iku->importSync('renstra', $opdId, $pilihan, self::MULAI, self::AKHIR, $v2);

        $this->cek('seluruh indikator versi tersalin ke IKU',
            ($stat['indikator_baru'] ?? 0) >= 2);

        $indIku = $this->db->table('iku_indikator i')
            ->select('i.*')->join('iku_sasaran s', 's.id = i.iku_sasaran_id')
            ->where('s.opd_id', $opdId)->where('s.tahun_mulai', self::MULAI)
            ->get()->getRowArray();

        $this->cek('IKU mencatat versi Renstra asalnya',
            (int) ($indIku['source_version_id'] ?? 0) === $v2);
        $this->cek('IKU mencatat baris Renstra berjalan asalnya',
            ! empty($indIku['source_indikator_id']));

        $this->cek('target yang disunting ikut terbawa',
            $this->targetIku((int) $indIku['id'], self::MULAI) === '88'
            || $this->adaTargetIku($opdId, '88'));

        // ---------- 6. IKU diajukan & disahkan ----------
        $this->bab('6. IKU diajukan lalu disahkan');

        $rev = new IkuRevisiModel($this->db);

        $revisiId = $rev->bekukanDanAjukan($opdId, self::MULAI, self::AKHIR, 11);
        $this->cek('revisi IKU menunggu keputusan', $rev->ambil($revisiId)['status'] === 'menunggu');
        $this->cek('belum berlaku sebelum disahkan',
            $rev->revisiBerlaku($opdId, self::MULAI, self::AKHIR) === null);

        $this->cekMelempar('mengajukan lagi saat menggantung ditolak',
            static fn () => $rev->bekukanDanAjukan($opdId, self::MULAI, self::AKHIR, 11));

        $rev->sahkan($revisiId, 22);
        $this->cek('revisi IKU berlaku',
            (int) $rev->revisiBerlaku($opdId, self::MULAI, self::AKHIR)['id'] === $revisiId);

        $this->cekMelempar('mengajukan lagi sesudah berlaku ditolak',
            static fn () => $rev->bekukanDanAjukan($opdId, self::MULAI, self::AKHIR, 11));

        // ---------- 7. Revisi IKU berikutnya ----------
        $this->bab('7. Revisi IKU berikutnya');

        $this->cekMelempar('revisi dengan tahun berlaku yang sama ditolak sejak awal',
            static fn () => $rev->buatDraft([
                'opd_id' => $opdId, 'tahun_mulai' => self::MULAI, 'tahun_akhir' => self::AKHIR,
                'nomor' => 'X', 'nama' => 'bentrok',
                'berlaku_mulai_tahun' => (int) $rev->revisiBerlaku($opdId, self::MULAI, self::AKHIR)['berlaku_mulai_tahun'],
            ]));

        $draftId = $rev->buatDraft([
            'opd_id' => $opdId, 'tahun_mulai' => self::MULAI, 'tahun_akhir' => self::AKHIR,
            'nomor' => self::TANDA . '/002', 'nama' => self::TANDA . ' Revisi kedua',
            'berlaku_mulai_tahun' => self::MULAI + 3, 'dibuat_oleh' => 11,
        ]);

        $this->cek('draft revisi lahir', $rev->ambil($draftId)['status'] === 'draft');

        $praTinjau = $rev->praTinjauPengesahan($draftId);
        $this->cek('pratinjau mengenali pembanding', $praTinjau['pembanding'] !== null);
        $this->cek('salinan apa adanya: belum ada yang berubah',
            $praTinjau['baru'] === [] && $praTinjau['berubah'] === [] && $praTinjau['dihentikan'] === []);

        $rev->ajukan($draftId, 11);
        $rev->sahkan($draftId, 22);

        $this->cek('revisi kedua berlaku',
            (int) $rev->revisiBerlaku($opdId, self::MULAI, self::AKHIR)['id'] === $draftId);
        $this->cek('revisi pertama menjadi arsip',
            $rev->ambil($revisiId)['status'] === 'superseded');

        // ---------- 8. Resolusi per tahun ----------
        $this->bab('8. Revisi mana yang berlaku pada tiap tahun');

        for ($t = self::MULAI; $t <= self::AKHIR; $t++) {
            $hasil = $rev->resolveEfektif($opdId, $t);
            $nama  = $hasil['revisi']['nomor'] ?? '(tidak ada)';

            $this->cek('tahun ' . $t . ' -> tepat satu revisi (' . $nama . ')',
                $hasil['konflik'] === []);
        }
    }

    /* =========================================================
     * DATA DUMMY
     * =======================================================*/

    /** @return array{0:int} id sasaran RPJMD yang bisa dirujuk Renstra */
    private function tanamRpjmd(): array
    {
        $misiId = $this->sisip('rpjmd_misi', [
            'misi' => self::TANDA . ' Mewujudkan pelayanan dasar yang merata',
            'tahun_mulai' => self::MULAI, 'tahun_akhir' => self::AKHIR,
        ]);

        $tujuanId = $this->sisip('rpjmd_tujuan', [
            'misi_id' => $misiId,
            'tujuan_rpjmd' => self::TANDA . ' Meningkatnya kualitas pelayanan dasar',
        ]);

        $sasaranId = $this->sisip('rpjmd_sasaran', [
            'tujuan_id' => $tujuanId,
            'sasaran_rpjmd' => self::TANDA . ' Meningkatnya derajat kesehatan masyarakat',
        ]);

        return [$sasaranId];
    }

    private function tanamRenstra(int $opdId, int $rpjmdSasaranId): void
    {
        $tujuanId = $this->sisip('renstra_tujuan', [
            'rpjmd_sasaran_id' => $rpjmdSasaranId,
            'tujuan' => self::TANDA . ' Meningkatnya derajat kesehatan masyarakat',
        ]);

        $indTujuan = $this->sisip('renstra_indikator_tujuan', [
            'tujuan_id' => $tujuanId,
            'indikator_tujuan' => self::TANDA . ' Indeks Kesehatan Masyarakat',
        ]);

        foreach ($this->tahun() as $i => $th) {
            $this->sisip('renstra_target_tujuan', [
                'indikator_tujuan_id' => $indTujuan, 'tahun' => $th,
                'target_tahunan' => (string) (70 + $i),
            ]);
        }

        $daftar = [
            ['Menurunnya angka kesakitan penyakit menular', [
                ['Angka populasi bebas penyakit menular', '1', '96,5', 'positif'],
                ['Angka populasi bebas PTM', '1', '83,4', 'positif'],
            ]],
            ['Meningkatnya akses dan mutu pelayanan kesehatan', [
                ['Cakupan pemeriksaan kesehatan gratis', '1', '50', 'positif'],
                ['Proporsi fasyankes terakreditasi utama', '1', '93,3', 'positif'],
            ]],
        ];

        foreach ($daftar as $urut => [$namaSasaran, $indikator]) {
            $sasaranId = $this->sisip('renstra_sasaran', [
                'opd_id' => $opdId, 'renstra_tujuan_id' => $tujuanId,
                'sasaran' => self::TANDA . ' ' . $namaSasaran, 'status' => 'selesai',
                'tahun_mulai' => self::MULAI, 'tahun_akhir' => self::AKHIR,
            ]);

            foreach ($indikator as [$teks, $satuan, $baseline, $jenis]) {
                $indId = $this->sisip('renstra_indikator_sasaran', [
                    'renstra_sasaran_id' => $sasaranId,
                    'indikator_sasaran' => self::TANDA . ' ' . $teks,
                    'satuan' => $satuan, 'baseline' => $baseline, 'jenis_indikator' => $jenis,
                ]);

                foreach ($this->tahun() as $i => $th) {
                    $this->sisip('renstra_target', [
                        'renstra_indikator_id' => $indId, 'tahun' => $th,
                        'target' => (string) (60 + $urut * 10 + $i),
                    ]);
                }
            }
        }
    }

    /* =========================================================
     * PEMBERSIHAN
     * =======================================================*/

    private function bersihkan(int $opdId): void
    {
        $db = $this->db;

        // --- IKU ---
        $idRevisi = array_column($db->table('iku_revisi')
            ->select('id')->where('opd_id', $opdId)
            ->where('tahun_mulai', self::MULAI)->where('tahun_akhir', self::AKHIR)
            ->get()->getResultArray(), 'id');

        if ($idRevisi !== []) {
            $idInd = array_column($db->table('iku_revisi_indikator')
                ->select('id')->whereIn('revisi_id', $idRevisi)->get()->getResultArray(), 'id');

            if ($idInd !== []) {
                $db->table('iku_revisi_target')->whereIn('revisi_indikator_id', $idInd)->delete();
                $db->table('iku_revisi_program')->whereIn('revisi_indikator_id', $idInd)->delete();
            }

            $db->table('iku_revisi_indikator')->whereIn('revisi_id', $idRevisi)->delete();
            $db->table('iku_revisi_sasaran')->whereIn('revisi_id', $idRevisi)->delete();
            $db->table('iku_revisi')->whereIn('id', $idRevisi)->delete();
        }

        $idSasaranIku = array_column($db->table('iku_sasaran')
            ->select('id')->where('opd_id', $opdId)
            ->where('tahun_mulai', self::MULAI)->where('tahun_akhir', self::AKHIR)
            ->get()->getResultArray(), 'id');

        if ($idSasaranIku !== []) {
            $idInd = array_column($db->table('iku_indikator')
                ->select('id')->whereIn('iku_sasaran_id', $idSasaranIku)->get()->getResultArray(), 'id');

            if ($idInd !== []) {
                $db->table('iku_target')->whereIn('iku_indikator_id', $idInd)->delete();
                $db->table('iku_program')->whereIn('iku_indikator_id', $idInd)->delete();
                $db->table('iku_indikator')->whereIn('id', $idInd)->delete();
            }

            $db->table('iku_sasaran')->whereIn('id', $idSasaranIku)->delete();
        }

        // --- versi & izin ---
        $idVersi = array_column($db->table('dokumen_versi')
            ->select('id')->where('periode_mulai', self::MULAI)->where('periode_akhir', self::AKHIR)
            ->get()->getResultArray(), 'id');

        if ($idVersi !== []) {
            $db->table('version_correction_requests')->whereIn('version_id', $idVersi)->delete();
            $db->table('version_submission_history')->whereIn('version_id', $idVersi)->delete();
            $db->table('dokumen_versi')->whereIn('id', $idVersi)->delete();
        }

        if ($db->tableExists('dokumen_izin_sunting')) {
            $db->table('dokumen_izin_sunting')
                ->where('periode_mulai', self::MULAI)->where('periode_akhir', self::AKHIR)->delete();
        }

        // --- isi Renstra & RPJMD dummy ---
        $idSasaran = array_column($db->table('renstra_sasaran')
            ->select('id')->like('sasaran', self::TANDA, 'after')->get()->getResultArray(), 'id');

        if ($idSasaran !== []) {
            $idInd = array_column($db->table('renstra_indikator_sasaran')
                ->select('id')->whereIn('renstra_sasaran_id', $idSasaran)->get()->getResultArray(), 'id');

            if ($idInd !== []) {
                $db->table('renstra_target')->whereIn('renstra_indikator_id', $idInd)->delete();
                $db->table('renstra_indikator_sasaran')->whereIn('id', $idInd)->delete();
            }

            $db->table('renstra_sasaran')->whereIn('id', $idSasaran)->delete();
        }

        $idTujuan = array_column($db->table('renstra_tujuan')
            ->select('id')->like('tujuan', self::TANDA, 'after')->get()->getResultArray(), 'id');

        if ($idTujuan !== []) {
            $idIt = array_column($db->table('renstra_indikator_tujuan')
                ->select('id')->whereIn('tujuan_id', $idTujuan)->get()->getResultArray(), 'id');

            if ($idIt !== []) {
                $db->table('renstra_target_tujuan')->whereIn('indikator_tujuan_id', $idIt)->delete();
                $db->table('renstra_indikator_tujuan')->whereIn('id', $idIt)->delete();
            }

            $db->table('renstra_tujuan')->whereIn('id', $idTujuan)->delete();
        }

        $db->table('rpjmd_sasaran')->like('sasaran_rpjmd', self::TANDA, 'after')->delete();
        $db->table('rpjmd_tujuan')->like('tujuan_rpjmd', self::TANDA, 'after')->delete();
        $db->table('rpjmd_misi')->like('misi', self::TANDA, 'after')->delete();
    }

    /* =========================================================
     * BANTU
     * =======================================================*/

    /** @return int[] */
    private function tahun(): array
    {
        return range(self::MULAI, self::AKHIR);
    }

    private function sisip(string $tabel, array $data): int
    {
        $now = date('Y-m-d H:i:s');

        foreach (['created_at', 'updated_at'] as $k) {
            if ($this->db->fieldExists($k, $tabel)) {
                $data[$k] = $now;
            }
        }

        $this->db->table($tabel)->insert($data);

        return (int) $this->db->insertID();
    }

    private function transaksi(callable $kerja)
    {
        $this->db->transBegin();

        try {
            $hasil = $kerja();

            if ($this->db->transStatus() === false) {
                $this->db->transRollback();

                throw new \RuntimeException('Transaksi ditolak basis data.');
            }

            $this->db->transCommit();

            return $hasil;
        } catch (Throwable $e) {
            if ($this->db->transDepth > 0) {
                $this->db->transRollback();
            }

            throw $e;
        }
    }

    private function status(int $versiId): string
    {
        return (string) $this->db->table('dokumen_versi')->select('status')
            ->where('id', $versiId)->get()->getRowArray()['status'];
    }

    private function diAntrean(DokumenVersiModel $versi, int $versiId): bool
    {
        foreach ($versi->menungguVerifikasi() as $v) {
            if ((int) $v['id'] === $versiId) {
                return true;
            }
        }

        return false;
    }

    private function jumlahTerbuka(DokumenVersiModel $versi, VersionScope $scope): int
    {
        return (int) $this->db->table('dokumen_versi')
            ->where($scope->kondisi())->where('terbuka_key', 1)->countAllResults();
    }

    private function hitungSasaranHidup(int $opdId): int
    {
        return (int) $this->db->table('renstra_sasaran')
            ->where('opd_id', $opdId)->where('tahun_mulai', self::MULAI)
            ->where('dihentikan_pada IS NULL', null, false)->countAllResults();
    }

    private function indikatorPertama(int $opdId): int
    {
        return (int) $this->db->table('renstra_indikator_sasaran i')
            ->select('i.id')->join('renstra_sasaran s', 's.id = i.renstra_sasaran_id')
            ->where('s.opd_id', $opdId)->where('s.tahun_mulai', self::MULAI)
            ->orderBy('i.id', 'ASC')->get()->getRowArray()['id'];
    }

    private function targetIku(int $indikatorId, int $tahun): ?string
    {
        $r = $this->db->table('iku_target')->select('target')
            ->where('iku_indikator_id', $indikatorId)->where('tahun', $tahun)
            ->get()->getRowArray();

        return $r['target'] ?? null;
    }

    private function adaTargetIku(int $opdId, string $nilai): bool
    {
        return (int) $this->db->table('iku_target t')
            ->join('iku_indikator i', 'i.id = t.iku_indikator_id')
            ->join('iku_sasaran s', 's.id = i.iku_sasaran_id')
            ->where('s.opd_id', $opdId)->where('s.tahun_mulai', self::MULAI)
            ->where('t.target', $nilai)->countAllResults() > 0;
    }

    private function bab(string $judul): void
    {
        CLI::newLine();
        CLI::write(CLI::color($judul, 'cyan'));
    }

    private function cek(string $judul, bool $lulus): void
    {
        if ($lulus) {
            $this->lulus++;
            CLI::write('  ' . CLI::color('LULUS', 'green') . '  ' . $judul);

            return;
        }

        $this->gagal++;
        CLI::write('  ' . CLI::color('GAGAL', 'red') . '  ' . $judul);
    }

    private function cekMelempar(string $judul, callable $kerja): void
    {
        try {
            $kerja();
            $this->cek($judul, false);
        } catch (Throwable $e) {
            $this->cek($judul, true);
        }
    }
}
