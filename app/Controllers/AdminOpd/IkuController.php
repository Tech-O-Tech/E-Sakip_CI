<?php

namespace App\Controllers\AdminOpd;

use App\Controllers\BaseController;
use App\Controllers\Concerns\IkuFormTrait;
use App\Controllers\Concerns\IkuRevisiTrait;
use App\Models\Opd\IkuModel;
use App\Models\Opd\IkuRevisiModel;
use App\Models\OpdModel;

/**
 * IKU tingkat OPD / Kecamatan — STANDALONE.
 *
 * Sejak 2026-07-27 IKU tidak lagi menempel ke RENSTRA: sasaran, indikator,
 * satuan, dan target per tahun diinput langsung di modul ini dan disimpan di
 * `iku_sasaran` / `iku_indikator` / `iku_target` dengan `iku_sasaran.opd_id`
 * sebagai penanda pemilik.
 *
 * Dipakai role admin_opd dan admin_kecamatan (keduanya punya `opd_id` di sesi).
 * Super admin (role `admin`, tanpa opd_id) melihat data seluruh OPD.
 */
class IkuController extends BaseController
{
    use IkuFormTrait;

    /** Revisi IKU: versi dokumen yang bernomor & bertanggal berlaku. */
    use IkuRevisiTrait;

    /* =========================================================
     * LINGKUP REVISI (dipakai IkuRevisiTrait)
     * =======================================================*/

    protected function revisiPermPrefix(): string
    {
        return 'iku_opd';
    }

    /**
     * OPD diambil dari SESSION, tidak pernah dari request — pola yang sama
     * dengan LakipAddendumTrait::lakipScope(). Ini yang mencegah satu OPD
     * merevisi IKU OPD lain lewat parameter yang ditebak.
     */
    protected function revisiOpdId(): ?int
    {
        $opdId = (int) session()->get('opd_id');

        return $opdId > 0 ? $opdId : null;
    }

    protected function revisiBaseUrl(): string
    {
        return 'adminopd/iku';
    }

    protected IkuModel $ikuModel;
    protected OpdModel $opdModel;

    public function __construct()
    {
        $this->ikuModel = new IkuModel();
        $this->opdModel = new OpdModel();
    }

    /* =========================================================
     * LIST
     * =======================================================*/
    public function index()
    {
        $opdId = $this->opdIdSesi();
        if ($opdId === false) {
            return redirect()->to('/login')->with('error', 'Silakan login terlebih dahulu');
        }

        [$groupedData, $periode] = $this->resolvePeriode($opdId);

        $ikuData = $this->ikuModel->getMatrix([
            'level'       => 'opd',
            'opd_id'      => $opdId,
            'tahun_mulai' => $periode['tahun_mulai'] ?? null,
            'tahun_akhir' => $periode['tahun_akhir'] ?? null,
        ]);

        $rev = new IkuRevisiModel();

        return view('adminOpd/iku/iku', [
            // Keadaan pengesahan: yang menunggu keputusan, dan yang sedang berlaku.
            'revisiMenunggu' => $rev->siap() && ! empty($periode['tahun_mulai'])
                ? $rev->berjalanMenunggu($opdId, (int) $periode['tahun_mulai'], (int) $periode['tahun_akhir'])
                : null,
            'revisiBerlaku'  => $rev->siap() && ! empty($periode['tahun_mulai'])
                ? $rev->revisiBerlaku($opdId, (int) $periode['tahun_mulai'], (int) $periode['tahun_akhir'])
                : null,
            'title'            => 'Indikator Kinerja Utama',
            'iku_data'         => $ikuData,
            'grouped_data'     => $groupedData,
            'selected_periode' => $periode['key'] ?? null,
            'years'            => $periode['years'] ?? [],
            'role'             => session()->get('role'),
            'is_lintas_opd'    => $opdId === null,
        ]);
    }

    /* =========================================================
     * FORM TAMBAH
     * =======================================================*/
    public function tambah()
    {
        // Pintu tambah manual DITUTUP (bukan sekadar tombolnya disembunyikan):
        // selama endpoint-nya hidup, tautan lama atau URL yang diketik langsung
        // tetap bisa melahirkan sasaran kembar di sebelah hasil sync.
        //
        // Rutenya sengaja dibiarkan terdaftar supaya tautan lama tidak berujung
        // 404 tanpa penjelasan — yang datang ke sini diberi tahu jalan yang benar.
        return redirect()->to(base_url('adminopd/iku'))
            ->with('error', 'IKU tidak lagi ditambah manual. Sasaran & indikatornya diambil dari Renstra lewat tombol Sync — supaya tidak lahir sasaran kembar dan setiap baris punya jejak asal.');
    }

    /* =========================================================
     * SIMPAN BARU
     * =======================================================*/
    public function save()
    {
        // Pintu tambah manual DITUTUP (bukan sekadar tombolnya disembunyikan):
        // selama endpoint-nya hidup, tautan lama atau URL yang diketik langsung
        // tetap bisa melahirkan sasaran kembar di sebelah hasil sync.
        //
        // Rutenya sengaja dibiarkan terdaftar supaya tautan lama tidak berujung
        // 404 tanpa penjelasan — yang datang ke sini diberi tahu jalan yang benar.
        return redirect()->to(base_url('adminopd/iku'))
            ->with('error', 'IKU tidak lagi ditambah manual. Sasaran & indikatornya diambil dari Renstra lewat tombol Sync — supaya tidak lahir sasaran kembar dan setiap baris punya jejak asal.');
    }

    /* =========================================================
     * SYNC DARI RENSTRA — PRATINJAU
     * GET adminopd/iku/sync?periode=2025-2029
     * =======================================================*/

    /* =========================================================
     * AJUKAN PENGESAHAN IKU (dari menu IKU, bukan menu Revisi)
     *
     * Kembaran "Ajukan Validasi" pada menu Renstra. Sebelum ini, satu-satunya
     * jalan mengesahkan IKU adalah lewat menu Revisi — dan menu itu kosong
     * sampai seseorang membuat revisi, sehingga IKU hasil sync tidak punya
     * pintu apa pun menuju pengesahan.
     * =======================================================*/

    public function ajukanPengesahan()
    {
        $opdId = $this->opdIdSesi();

        if ($opdId === false) {
            return redirect()->to('/login')->with('error', 'Silakan login terlebih dahulu');
        }

        if (! user_can('iku_opd.revisi')) {
            return redirect()->to(base_url('adminopd/iku'))
                ->with('error', 'Anda tidak berwenang mengajukan pengesahan IKU.');
        }

        $tm = (int) $this->request->getPost('tahun_mulai');
        $ta = (int) $this->request->getPost('tahun_akhir');

        if ($tm <= 0 || $ta < $tm) {
            return redirect()->back()->with('error', 'Periode IKU tidak sah.');
        }

        $kembali = base_url('adminopd/iku?periode=' . $tm . '-' . $ta);

        try {
            (new IkuRevisiModel())->bekukanDanAjukan($opdId, $tm, $ta, $this->penggunaIku(), [
                'catatan' => $this->request->getPost('catatan') ?: null,
            ]);
        } catch (\Throwable $e) {
            return redirect()->to($kembali)->with('error', $e->getMessage());
        }

        return redirect()->to($kembali)->with('success',
            'IKU periode ' . $tm . '-' . $ta . ' diajukan untuk disahkan Admin Kabupaten. '
            . 'Isinya dibekukan apa adanya saat ini — perubahan sesudah ini tidak ikut terkirim.');
    }

    private function penggunaIku(): ?int
    {
        $id = session()->get('user_id') ?? session()->get('id');

        return $id === null ? null : (int) $id;
    }

    public function sync()
    {
        $opdId = $this->opdIdSesi();
        if ($opdId === false) {
            return redirect()->to('/login')->with('error', 'Silakan login terlebih dahulu');
        }

        if (!user_can('iku_opd.create')) {
            return redirect()->to(base_url('adminopd/iku'))
                ->with('error', 'Anda tidak memiliki akses untuk menyalin data ke IKU.');
        }

        // Sync selalu terikat satu OPD — super admin lintas OPD harus memilih dulu.
        $opdId = $this->opdSyncTerpilih($opdId);
        if ($opdId === null) {
            return redirect()->to(base_url('adminopd/iku'))
                ->with('error', 'Akun ini tidak terikat ke satu OPD, jadi sync Renstra tidak bisa dijalankan dari sini.');
        }

        [$daftarPeriode, $periode] = $this->resolvePeriodeSumber('renstra', $opdId);

        if (empty($periode)) {
            return redirect()->to(base_url('adminopd/iku'))
                ->with('error', 'Belum ada periode Renstra pada OPD ini yang bisa disalin. Isi Renstra terlebih dahulu.');
        }

        // Versi Renstra yang jadi titik tolak. Tanpa parameter ini, sumbernya
        // kondisi berjalan — perilaku lama, apa adanya.
        $versiTersedia = $this->ikuModel->versiRenstraTersedia(
            $opdId,
            (int) $periode['tahun_mulai'],
            (int) $periode['tahun_akhir']
        );

        $versiDipilih = $this->versiRenstraDipilih(
            $this->request->getGet('renstra_versi'),
            $versiTersedia
        );

        $kandidat = $this->ikuModel->getKandidatSync(
            'renstra',
            $opdId,
            $periode['tahun_mulai'],
            $periode['tahun_akhir'],
            $versiDipilih !== null ? (int) $versiDipilih['id'] : null
        );

        $opd = $this->opdModel->find($opdId);

        return view('adminOpd/iku/sync_iku', [
            'title'          => 'Sync IKU dari Renstra',
            'kandidat'       => $kandidat,
            'daftar_periode' => $daftarPeriode,
            'periode'        => $periode,
            'years'          => $periode['years'],
            'nama_opd'       => $opd['nama_opd'] ?? '',
            'role'           => session()->get('role'),
            'versi_tersedia' => $versiTersedia,
            'versi_dipilih'  => $versiDipilih,
            'tanpa_padanan'  => $this->ikuModel->ikuTanpaPadananSumber(),
        ] + $this->muaraSync($opdId, $periode));
    }

    /* =========================================================
     * SYNC DARI RENSTRA — SIMPAN
     * POST adminopd/iku/sync/simpan
     * =======================================================*/
    public function syncSimpan()
    {
        $opdId = $this->opdIdSesi();
        if ($opdId === false) {
            return redirect()->to('/login')->with('error', 'Silakan login terlebih dahulu');
        }

        if (!user_can('iku_opd.create')) {
            return redirect()->to(base_url('adminopd/iku'))
                ->with('error', 'Anda tidak memiliki akses untuk menyalin data ke IKU.');
        }

        $opdId = $this->opdSyncTerpilih($opdId);
        if ($opdId === null) {
            return redirect()->to(base_url('adminopd/iku'))
                ->with('error', 'Akun ini tidak terikat ke satu OPD, jadi sync Renstra tidak bisa dijalankan dari sini.');
        }

        $post    = $this->request->getPost() ?? [];
        $periode = (string) ($post['periode'] ?? '');

        $daftarPeriode = $this->ikuModel->getPeriodeSumber('renstra', $opdId);
        if (!isset($daftarPeriode[$periode])) {
            return redirect()->to(base_url('adminopd/iku/sync'))
                ->with('error', 'Periode Renstra tidak valid.');
        }

        // Versi diperiksa ulang terhadap daftar yang sah, bukan dipercaya dari
        // form: id karangan tidak boleh membuka arsip OPD lain.
        $versiTersedia = $this->ikuModel->versiRenstraTersedia(
            $opdId,
            (int) $daftarPeriode[$periode]['tahun_mulai'],
            (int) $daftarPeriode[$periode]['tahun_akhir']
        );

        $versiDipilih = $this->versiRenstraDipilih($post['renstra_versi'] ?? null, $versiTersedia);

        // Seluruh isi sumber terpilih disalin — pemakai memilih SUMBER, bukan
        // baris per baris. Keranjangnya dibangun dari kandidat yang sama
        // dengan yang dipratinjau, di server.
        $kandidat = $this->ikuModel->getKandidatSync(
            'renstra',
            $opdId,
            (int) $daftarPeriode[$periode]['tahun_mulai'],
            (int) $daftarPeriode[$periode]['tahun_akhir'],
            $versiDipilih !== null ? (int) $versiDipilih['id'] : null
        );

        [$pilihan, $perbarui] = $this->keranjangSyncPenuh($kandidat);

        if (empty($pilihan) && empty($perbarui)) {
            return redirect()->to(base_url('adminopd/iku/sync?periode=' . $periode))
                ->with('info', 'IKU sudah sama dengan sumber ini — tidak ada yang perlu disalin.');
        }

        $muara = $this->muaraSync($opdId, $daftarPeriode[$periode]);

        try {
            if ($muara['ke_revisi']) {
                $stat = $this->syncKeDraft($muara, $post, $opdId, $daftarPeriode[$periode],
                    $versiDipilih, $pilihan, $perbarui, 'renstra',
                    base_url('adminopd/iku/sync?periode=' . $periode));

                if (! is_array($stat)) {
                    return $stat;
                }
            } else {
                $stat = $this->ikuModel->importSync(
                    'renstra',
                    $opdId,
                    $pilihan,
                    $daftarPeriode[$periode]['tahun_mulai'],
                    $daftarPeriode[$periode]['tahun_akhir'],
                    $versiDipilih !== null ? (int) $versiDipilih['id'] : null,
                    $perbarui
                );
            }
        } catch (\Throwable $e) {
            log_message('error', '[IKU SYNC OPD] ' . $e->getMessage());

            return redirect()->to(base_url('adminopd/iku/sync?periode=' . $periode))
                ->with('error', 'Gagal menyalin data Renstra: ' . $e->getMessage());
        }

        if ($muara['ke_revisi']) {
            return redirect()->to(base_url('adminopd/iku/revisi'))
                ->with('success', $this->pesanHasilSync($stat)
                    . ' Semuanya masuk ke DRAFT revisi — IKU berjalan belum berubah, '
                    . 'dan baru berubah setelah revisi itu diajukan dan disahkan.');
        }

        return redirect()->to(base_url('adminopd/iku?periode=' . $periode))
            ->with('success', $this->pesanHasilSync($stat));
    }

    

    /**
     * Ke mana hasil sync bermuara: tabel berjalan atau draft revisi.
     *
     * Selama IKU belum punya revisi yang berlaku, ia memang masih disusun dan
     * menyalin langsung ke tabel berjalan sama wajarnya dengan mengetik
     * manual. Sesudah ada revisi yang disahkan, menambah indikator langsung ke
     * tabel berjalan berarti mengubah dokumen resmi tanpa sepengetahuan
     * siapa pun — dan tambahan itu tidak akan muncul di arsip revisi mana pun.
     *
     * @return array{ke_revisi:bool, revisi_berlaku:?array, draft_tersedia:array}
     */


    /**
     * Versi Renstra yang dipilih sebagai sumber, atau null bila memakai
     * kondisi berjalan.
     *
     * Nilainya TIDAK dipercaya begitu saja: harus ada pada daftar versi yang
     * sudah dibatasi OPD dan periodenya. Dengan begitu tidak ada jalan membaca
     * arsip OPD lain lewat id yang dikarang.
     *
     * @param array<int,array<string,mixed>> $tersedia
     */
    private function versiRenstraDipilih($nilai, array $tersedia): ?array
    {
        $id = (int) $nilai;

        if ($id <= 0) {
            return null;
        }

        foreach ($tersedia as $v) {
            if ((int) $v['id'] === $id) {
                return $v;
            }
        }

        return null;
    }

    /**
     * OPD yang jadi sasaran sync.
     *
     * admin_opd/admin_kecamatan terkunci ke OPD-nya sendiri. Super admin lintas
     * OPD boleh menyebut ?opd_id= karena sync wajib terikat tepat satu OPD.
     */
    private function opdSyncTerpilih(?int $opdSesi): ?int
    {
        if ($opdSesi !== null) {
            return $opdSesi;
        }

        $dari = $this->request->getGet('opd_id') ?? $this->request->getPost('opd_id');
        $dari = (int) $dari;

        return $dari > 0 ? $dari : null;
    }

    /* =========================================================
     * FORM EDIT
     * =======================================================*/
    public function edit($sasaranId = null)
    {
        $opdId = $this->opdIdSesi();
        if ($opdId === false) {
            return redirect()->to('/login')->with('error', 'Silakan login terlebih dahulu');
        }

        if (!user_can('iku_opd.update')) {
            return redirect()->to(base_url('adminopd/iku'))
                ->with('error', 'Anda tidak memiliki akses untuk mengubah IKU.');
        }

        $sasaran = $this->ikuModel->getSasaranDetail((int) $sasaranId);
        if (!$sasaran) {
            return redirect()->to(base_url('adminopd/iku'))->with('error', 'Data IKU tidak ditemukan.');
        }

        if (!$this->bolehAksesSasaran((int) $sasaranId)) {
            return redirect()->to(base_url('adminopd/iku'))
                ->with('error', 'Anda tidak memiliki akses ke IKU OPD lain.');
        }

        return view('adminOpd/iku/edit_iku', [
            'title'          => 'Edit IKU',
            'iku'            => $sasaran,
            'satuan_options' => $this->ikuModel->getSatuanOptions(),
            'opd_list'       => $opdId === null ? $this->opdModel->orderBy('nama_opd', 'ASC')->findAll() : [],
            'is_lintas_opd'  => $opdId === null,
            'role'           => session()->get('role'),
        ]);
    }

    /* =========================================================
     * UPDATE
     * =======================================================*/
    /**
     * Simpan KETERANGAN indikator saja.
     *
     * =====================================================================
     * PEMBATASANNYA DI SERVER, BUKAN DI LAYAR
     *
     * Sebelumnya method ini membaca seluruh form lewat `bacaFormIku()` dan
     * memanggil `updateComplete()` — yang menulis ulang sasaran, indikator,
     * satuan, dan SELURUH targetnya dari apa pun yang dikirim. Menyembunyikan
     * medannya di layar tidak menutup jalan itu: POST yang dikarang tetap bisa
     * menghapus target dan indikator, tanpa satu pun galat.
     *
     * Karena itu yang dibaca sekarang hanya `keterangan[<id indikator>][...]`,
     * dan yang ditulis hanya empat kolom. Perubahan substantif berjalan lewat
     * revisi IKU, tempat ia tercatat dan disahkan.
     */
    public function update()
    {
        $opdId = $this->opdIdSesi();

        if ($opdId === false) {
            return redirect()->to('/login')->with('error', 'Silakan login terlebih dahulu');
        }

        if (! user_can('iku_opd.update')) {
            return redirect()->to(base_url('adminopd/iku'))
                ->with('error', 'Anda tidak memiliki akses untuk mengubah IKU.');
        }

        $sasaranId = (int) ($this->request->getPost('iku_sasaran_id') ?? 0);

        if ($sasaranId <= 0) {
            return redirect()->to(base_url('adminopd/iku'))->with('error', 'ID IKU tidak ditemukan.');
        }

        if (! $this->bolehAksesSasaran($sasaranId)) {
            return redirect()->to(base_url('adminopd/iku'))
                ->with('error', 'Anda tidak memiliki akses ke IKU OPD lain.');
        }

        $keterangan = (array) ($this->request->getPost('keterangan') ?? []);

        if ($keterangan === []) {
            return redirect()->back()->with('error', 'Tidak ada keterangan yang dikirim.');
        }

        try {
            $jumlah = $this->ikuModel->perbaruiKeterangan($sasaranId, $keterangan);
        } catch (\Throwable $e) {
            log_message('error', '[IKU KETERANGAN OPD] ' . $e->getMessage());

            return redirect()->back()->withInput()
                ->with('error', 'Gagal menyimpan keterangan: ' . $e->getMessage());
        }

        return redirect()->to(base_url('adminopd/iku'))
            ->with('success', 'Keterangan ' . $jumlah . ' indikator disimpan. '
                . 'Sasaran, indikator, satuan, dan target tidak tersentuh.');
    }

    public function delete($sasaranId = null)
    {
        if (!user_can('iku_opd.delete')) {
            return redirect()->to(base_url('adminopd/iku'))
                ->with('error', 'Anda tidak memiliki akses untuk menghapus IKU.');
        }

        $sasaranId = (int) $sasaranId;

        if (!$this->bolehAksesSasaran($sasaranId)) {
            return redirect()->to(base_url('adminopd/iku'))
                ->with('error', 'Anda tidak memiliki akses untuk menghapus IKU OPD lain.');
        }

        try {
            $this->ikuModel->deleteComplete($sasaranId);
            session()->setFlashdata('success', 'Data IKU berhasil dihapus.');
        } catch (\Throwable $e) {
            session()->setFlashdata('error', 'Gagal menghapus IKU: ' . $e->getMessage());
        }

        return redirect()->to(base_url('adminopd/iku'));
    }

    /* =========================================================
     * UBAH STATUS SATU INDIKATOR (draft <-> selesai)
     * =======================================================*/
    public function change_status($indikatorId = null)
    {
        if (!user_can('iku_opd.update')) {
            return redirect()->back()->with('error', 'Anda tidak memiliki akses untuk mengubah status IKU.');
        }

        $owner = $this->ikuModel->getIndikatorOwner((int) $indikatorId);

        if (!$owner['found']) {
            return redirect()->back()->with('error', 'Indikator IKU tidak ditemukan.');
        }

        if (!$this->canAccessOpd($owner['opd_id'])) {
            return redirect()->back()->with('error', 'Anda tidak memiliki akses ke IKU OPD lain.');
        }

        $statusBaru = $this->ikuModel->toggleStatusIndikator((int) $indikatorId);

        return redirect()->back()
            ->with('success', 'Status IKU berhasil diubah menjadi ' . ucfirst((string) $statusBaru) . '.');
    }

    /* =========================================================
     * CETAK PDF
     * =======================================================*/
    public function cetak()
    {
        $opdId = $this->opdIdSesi();
        if ($opdId === false) {
            return redirect()->to('/login')->with('error', 'Silakan login terlebih dahulu');
        }

        [, $periode] = $this->resolvePeriode($opdId);

        if (empty($periode)) {
            return redirect()->to(base_url('adminopd/iku'))
                ->with('error', 'Belum ada data IKU yang bisa dicetak.');
        }

        $ikuData = $this->ikuModel->getMatrix([
            'level'       => 'opd',
            'opd_id'      => $opdId,
            'tahun_mulai' => $periode['tahun_mulai'],
            'tahun_akhir' => $periode['tahun_akhir'],
        ]);

        $opd = $opdId ? $this->opdModel->find($opdId) : null;

        if (ob_get_level() > 0) {
            @ob_clean();
        }

        $html = view('adminOpd/iku/iku_cetak', [
            'iku_data'    => $ikuData,
            'years'       => $periode['years'],
            'periode_txt' => $periode['label'],
            'nama_opd'    => $opd['nama_opd'] ?? '',
            'lintas_opd'  => $opdId === null,
        ]);

        $mpdf = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4-L',
            'margin_left'   => 10,
            'margin_right'  => 10,
            'margin_top'    => 12,
            'margin_bottom' => 10,
            'margin_header' => 0,
            'margin_footer' => 0,
            'tempDir'       => sys_get_temp_dir(),
        ]);

        helper('setting');
        $mpdf->SetHTMLFooter(pdf_footer_aksara());
        pdf_watermark_aksara($mpdf);
        $mpdf->SetDisplayMode('fullpage');
        $mpdf->WriteHTML($html);

        $this->response->setHeader('Content-Type', 'application/pdf');
        $namaOpd  = trim((string) ($opd['nama_opd'] ?? ''));
        $namaFile = $namaOpd !== '' ? preg_replace('/[^A-Za-z0-9]+/', '-', $namaOpd) . '-' : '';
        $mpdf->Output('IKU-OPD-' . $namaFile . $periode['key'] . '.pdf', 'I');
        exit;
    }

    /* =========================================================
     * HELPER PRIVAT
     * =======================================================*/

    /**
     * opd_id dari sesi.
     *
     * @return int|null|false int  = admin_opd / admin_kecamatan,
     *                        null = super admin (lintas OPD),
     *                        false = belum login
     */
    private function opdIdSesi()
    {
        $session = session();
        $opdId   = $session->get('opd_id');
        $role    = $session->get('role');

        if (!empty($opdId)) {
            return (int) $opdId;
        }

        // Tanpa opd_id hanya boleh role tingkat kabupaten (super admin).
        return $role ? null : false;
    }

    /** Cek otorisasi lintas-OPD untuk satu sasaran IKU (IDOR). */
    private function bolehAksesSasaran(int $sasaranId): bool
    {
        $owner = $this->ikuModel->getSasaranOwner($sasaranId);

        return $owner['found'] && $this->canAccessOpd($owner['opd_id']);
    }

    /**
     * Tentukan periode aktif: dari query string kalau ada, kalau tidak pilih
     * periode yang memuat tahun berjalan, kalau tetap tidak ada pakai yang pertama.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function resolvePeriode(?int $opdId): array
    {
        $groupedData = $this->ikuModel->getPeriodeOptions('opd', $opdId);

        if (empty($groupedData)) {
            return [[], []];
        }

        $dipilih = $this->request->getGet('periode');
        if (empty($dipilih) || !isset($groupedData[$dipilih])) {
            $dipilih     = null;
            $tahunSekarang = (int) date('Y');

            foreach ($groupedData as $key => $p) {
                if (in_array($tahunSekarang, $p['years'], true)) {
                    $dipilih = $key;
                    break;
                }
            }

            $dipilih ??= array_key_first($groupedData);
        }

        $p = $groupedData[$dipilih];

        return [$groupedData, [
            'key'         => $dipilih,
            'label'       => $p['period'],
            'years'       => $p['years'],
            'tahun_mulai' => $p['tahun_mulai'],
            'tahun_akhir' => $p['tahun_akhir'],
        ]];
    }
}
