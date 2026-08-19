<?php

namespace App\Controllers\Concerns;

use App\Models\Opd\IkuRevisiModel;
use Throwable;

/**
 * Aksi Revisi IKU, dipakai bersama AdminKab\IkuController dan
 * AdminOpd\IkuController.
 *
 * ---------------------------------------------------------------------
 * ALUR DI LAYAR
 *
 *   Daftar Revisi  -> semua versi satu periode, beserta masa berlakunya
 *   Buat Revisi    -> membuat DRAFT berisi salinan IKU yang berlaku sekarang
 *   Sunting Draft  -> mengubah target, menandai indikator dihentikan/pengganti,
 *                     menambah indikator baru — SEMUANYA di dalam draft
 *   Sahkan         -> draft diterapkan ke IKU berjalan, versi lama jadi arsip
 *   Batalkan       -> draft dibuang (barisnya tetap, statusnya 'batal')
 *   Lihat          -> isi salah satu versi, apa adanya saat versi itu berlaku
 *
 * Selama masih draft, IKU yang dipakai LAKIP dan API publik TIDAK berubah
 * sedikit pun (invariant 1 & 5).
 *
 * ---------------------------------------------------------------------
 * OTORISASI
 *
 * Controller pemakai menyediakan lingkupnya lewat tiga method:
 *
 *   revisiPermPrefix()  'iku_kab' | 'iku_opd'
 *   revisiOpdId()       NULL untuk kabupaten, id OPD untuk OPD/kecamatan
 *   revisiBaseUrl()     'adminkab/iku' | 'adminopd/iku'
 *
 * revisiOpdId() WAJIB mengambil OPD dari SESSION untuk role tingkat OPD, tidak
 * pernah dari request — pola yang sama dengan LakipAddendumTrait::lakipScope().
 */
trait IkuRevisiTrait
{
    protected ?IkuRevisiModel $revisiModel = null;

    protected function revisi(): IkuRevisiModel
    {
        return $this->revisiModel ??= new IkuRevisiModel();
    }

    abstract protected function revisiPermPrefix(): string;

    /** NULL = lingkup kabupaten. */
    abstract protected function revisiOpdId(): ?int;

    abstract protected function revisiBaseUrl(): string;

    private function bolehRevisi(): bool
    {
        return function_exists('user_can') && user_can($this->revisiPermPrefix() . '.revisi');
    }

    private function bolehSahkanRevisi(): bool
    {
        return function_exists('user_can') && user_can($this->revisiPermPrefix() . '.revisi_sahkan');
    }

    private function urlRevisi(string $tambahan = ''): string
    {
        return base_url($this->revisiBaseUrl() . '/revisi' . $tambahan);
    }

    /**
     * Pastikan sebuah revisi memang milik lingkup pengguna ini.
     * Pencegah IDOR: id revisi dari URL tidak pernah dipercaya.
     */
    private function revisiMilikLingkup(?array $revisi): bool
    {
        if (! $revisi) {
            return false;
        }

        $opd = $this->revisiOpdId();

        return (int) $revisi['opd_key'] === ($opd === null ? 0 : $opd);
    }

    /* =========================================================
     * DAFTAR
     * =======================================================*/

    public function revisiIndex()
    {
        if (! user_can($this->revisiPermPrefix() . '.view')) {
            return redirect()->to(base_url($this->revisiBaseUrl()))
                ->with('error', 'Anda tidak memiliki akses ke Revisi IKU.');
        }

        $model = $this->revisi();

        if (! $model->siap()) {
            return redirect()->to(base_url($this->revisiBaseUrl()))->with(
                'error',
                'Modul Revisi IKU belum aktif di basis data ini. Jalankan '
                . 'db/update_2026-08-18_iku_revisi_lakip_snapshot.sql lebih dulu.'
            );
        }

        $opdId  = $this->revisiOpdId();
        $daftar = $model->daftar($opdId);

        // Peringatan konflik ditampilkan apa adanya, tidak diselesaikan
        // diam-diam (invariant 2 / Case 11).
        $konflik = [];
        foreach ($this->tahunPeriode($daftar) as $tahun) {
            $hasil = $model->resolveEfektif($opdId, $tahun);

            if (! empty($hasil['konflik'])) {
                $konflik[$tahun] = $hasil['pesan'];
            }
        }

        return view('iku/revisi_index', [
            'title'          => 'Revisi IKU',
            'role'           => session()->get('role'),
            'daftar'         => $daftar,
            'periodeOpsi'    => $this->ikuModel->getPeriodeOptions($opdId === null ? 'kabupaten' : 'opd', $opdId),
            'konflik'        => $konflik,
            'bolehRevisi'    => $this->bolehRevisi(),
            'bolehSahkan'    => $this->bolehSahkanRevisi(),
            'baseUrl'        => $this->revisiBaseUrl(),
            'tahunSekarang'  => (int) date('Y'),
        ]);
    }

    /** Tahun-tahun unik yang dicakup daftar revisi, untuk pemeriksaan konflik. */
    private function tahunPeriode(array $daftar): array
    {
        $tahun = [];

        foreach ($daftar as $r) {
            for ($t = (int) $r['tahun_mulai']; $t <= (int) $r['tahun_akhir']; $t++) {
                $tahun[$t] = true;
            }
        }

        return array_keys($tahun);
    }

    /* =========================================================
     * BUAT DRAFT
     * =======================================================*/

    public function revisiBuat()
    {
        if (! $this->bolehRevisi()) {
            return redirect()->to($this->urlRevisi())
                ->with('error', 'Anda tidak berwenang membuat revisi IKU.');
        }

        $opdId = $this->revisiOpdId();

        return view('iku/revisi_form', [
            'title'       => 'Buat Revisi IKU',
            'role'        => session()->get('role'),
            'periodeOpsi' => $this->ikuModel->getPeriodeOptions($opdId === null ? 'kabupaten' : 'opd', $opdId),
            'baseUrl'     => $this->revisiBaseUrl(),
        ]);
    }

    public function revisiSimpan()
    {
        if (! $this->bolehRevisi()) {
            return redirect()->to($this->urlRevisi())
                ->with('error', 'Anda tidak berwenang membuat revisi IKU.');
        }

        $post = $this->request->getPost();

        $error = $this->safeTextError([
            'Nama revisi'    => $post['nama'] ?? null,
            'Dasar hukum'    => $post['dasar_hukum'] ?? null,
            'Nomor dasar'    => $post['nomor_dasar'] ?? null,
            'Catatan'        => $post['catatan'] ?? null,
        ]);

        if ($error) {
            return redirect()->back()->withInput()->with('error', $error);
        }

        // Periode dikirim sebagai "2025-2029" — bentuk yang sama dengan
        // getPeriodeOptions().
        $periode = explode('-', (string) ($post['periode'] ?? ''));

        if (count($periode) !== 2) {
            return redirect()->back()->withInput()->with('error', 'Periode IKU belum dipilih.');
        }

        try {
            $id = $this->revisi()->buatDraft([
                'opd_id'              => $this->revisiOpdId(),
                'tahun_mulai'         => (int) $periode[0],
                'tahun_akhir'         => (int) $periode[1],
                'nama'                => (string) ($post['nama'] ?? ''),
                'dasar_hukum'         => $post['dasar_hukum'] ?? null,
                'nomor_dasar'         => $post['nomor_dasar'] ?? null,
                'tanggal_dasar'       => $post['tanggal_dasar'] ?? null,
                'berlaku_mulai_tahun' => (int) ($post['berlaku_mulai_tahun'] ?? 0),
                'catatan'             => $post['catatan'] ?? null,
                'dibuat_oleh'         => (int) session()->get('user_id') ?: null,
            ]);

            return redirect()->to($this->urlRevisi('/sunting/' . $id))->with(
                'success',
                'Draft revisi dibuat berisi salinan IKU yang berlaku sekarang. '
                . 'IKU berjalan BELUM berubah — silakan sunting lalu sahkan.'
            );
        } catch (Throwable $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    /* =========================================================
     * LIHAT & SUNTING
     * =======================================================*/

    public function revisiLihat($id = null)
    {
        if (! user_can($this->revisiPermPrefix() . '.view')) {
            return redirect()->to(base_url($this->revisiBaseUrl()))->with('error', 'Akses ditolak.');
        }

        $revisi = $this->revisi()->ambil((int) $id);

        if (! $this->revisiMilikLingkup($revisi)) {
            return redirect()->to($this->urlRevisi())->with('error', 'Revisi tidak ditemukan.');
        }

        return view('iku/revisi_lihat', [
            'title'   => $revisi['nama'],
            'role'    => session()->get('role'),
            'revisi'  => $revisi,
            // Nisan 'dihentikan' ikut ditampilkan di sini supaya terbaca APA
            // yang berubah pada revisi ini — berbeda dari isi yang dicetak.
            'isi'     => $this->revisi()->isiRevisi((int) $id, true),
            'years'   => range((int) $revisi['tahun_mulai'], (int) $revisi['tahun_akhir']),
            'baseUrl' => $this->revisiBaseUrl(),
        ]);
    }

    public function revisiSunting($id = null)
    {
        if (! $this->bolehRevisi()) {
            return redirect()->to($this->urlRevisi())->with('error', 'Anda tidak berwenang menyunting revisi.');
        }

        $revisi = $this->revisi()->ambil((int) $id);

        if (! $this->revisiMilikLingkup($revisi)) {
            return redirect()->to($this->urlRevisi())->with('error', 'Revisi tidak ditemukan.');
        }

        if ($revisi['status'] !== IkuRevisiModel::STATUS_DRAFT) {
            return redirect()->to($this->urlRevisi('/lihat/' . (int) $id))->with(
                'error',
                'Hanya draft yang bisa disunting. Revisi yang sudah berlaku adalah arsip dan tidak boleh diubah.'
            );
        }

        return view('iku/revisi_sunting', [
            'title'          => 'Sunting ' . $revisi['nama'],
            'role'           => session()->get('role'),
            'revisi'         => $revisi,
            'isi'            => $this->revisi()->isiRevisi((int) $id, true),
            'years'          => range((int) $revisi['tahun_mulai'], (int) $revisi['tahun_akhir']),
            'satuan_options' => $this->ikuModel->getSatuanOptions(),
            'indikatorLive'  => $this->indikatorLiveUntukLineage($revisi),
            'bolehSahkan'    => $this->bolehSahkanRevisi(),
            'baseUrl'        => $this->revisiBaseUrl(),
        ]);
    }

    /**
     * Daftar indikator IKU berjalan pada periode revisi ini — untuk memilih
     * "indikator mana yang digantikan" (lineage, invariant 4 / Case 14).
     */
    private function indikatorLiveUntukLineage(array $revisi): array
    {
        // db_connect() dipakai langsung: kedua controller IKU tidak punya
        // properti $db, dan menambahkannya hanya demi satu query di sini
        // akan mengubah konstruktor yang sudah mapan.
        $b = db_connect()->table('iku_indikator ind')
            ->select('ind.id, ind.indikator, sas.sasaran')
            ->join('iku_sasaran sas', 'sas.id = ind.iku_sasaran_id', 'left')
            ->where('sas.tahun_mulai', (int) $revisi['tahun_mulai'])
            ->where('sas.tahun_akhir', (int) $revisi['tahun_akhir'])
            ->orderBy('sas.urutan', 'ASC')
            ->orderBy('ind.urutan', 'ASC');

        $opd = $this->revisiOpdId();
        $opd === null ? $b->where('sas.opd_id IS NULL', null, false) : $b->where('sas.opd_id', $opd);

        return $b->get()->getResultArray();
    }

    /**
     * Simpan suntingan draft.
     *
     * Yang boleh diubah pada tiap baris arsip: target per tahun, jenis
     * perubahan, indikator yang digantikan, dan penanda perubahan substansial.
     * Teks indikatornya sendiri juga boleh disunting — itulah gunanya revisi.
     */
    public function revisiSuntingSimpan($id = null)
    {
        if (! $this->bolehRevisi()) {
            return redirect()->to($this->urlRevisi())->with('error', 'Anda tidak berwenang menyunting revisi.');
        }

        $revisiId = (int) $id;
        $revisi   = $this->revisi()->ambil($revisiId);

        if (! $this->revisiMilikLingkup($revisi)) {
            return redirect()->to($this->urlRevisi())->with('error', 'Revisi tidak ditemukan.');
        }

        if ($revisi['status'] !== IkuRevisiModel::STATUS_DRAFT) {
            return redirect()->to($this->urlRevisi('/lihat/' . $revisiId))
                ->with('error', 'Hanya draft yang bisa disunting.');
        }

        $baris = (array) ($this->request->getPost('baris') ?? []);

        try {
            $this->revisi()->simpanSuntinganDraft($revisiId, $baris, $this->request->getPost('baru') ?? []);

            return redirect()->to($this->urlRevisi('/sunting/' . $revisiId))
                ->with('success', 'Draft revisi disimpan. IKU berjalan belum berubah.');
        } catch (Throwable $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    /* =========================================================
     * SAHKAN & BATALKAN
     * =======================================================*/

    public function revisiSahkan($id = null)
    {
        if (! $this->bolehSahkanRevisi()) {
            return redirect()->to($this->urlRevisi())
                ->with('error', 'Anda tidak berwenang mengesahkan revisi IKU.');
        }

        $revisi = $this->revisi()->ambil((int) $id);

        if (! $this->revisiMilikLingkup($revisi)) {
            return redirect()->to($this->urlRevisi())->with('error', 'Revisi tidak ditemukan.');
        }

        try {
            $hasil = $this->revisi()->sahkan((int) $id, (int) session()->get('user_id') ?: null);

            $pesan = 'Revisi disahkan dan berlaku mulai tahun ' . $revisi['berlaku_mulai_tahun'] . '.';

            if ($hasil['indikator_dipensiunkan'] > 0) {
                $pesan .= ' ' . $hasil['indikator_dipensiunkan'] . ' indikator dipensiunkan (tidak dihapus) '
                    . 'sehingga LAKIP tahun-tahun sebelumnya tetap utuh.';
            }

            if (! empty($hasil['digeser'])) {
                $pesan .= ' ' . count($hasil['digeser']) . ' revisi sebelumnya menjadi arsip.';
            }

            return redirect()->to($this->urlRevisi())->with('success', $pesan);
        } catch (Throwable $e) {
            return redirect()->to($this->urlRevisi())->with('error', $e->getMessage());
        }
    }

    public function revisiBatalkan($id = null)
    {
        if (! $this->bolehRevisi()) {
            return redirect()->to($this->urlRevisi())->with('error', 'Anda tidak berwenang membatalkan revisi.');
        }

        $revisi = $this->revisi()->ambil((int) $id);

        if (! $this->revisiMilikLingkup($revisi)) {
            return redirect()->to($this->urlRevisi())->with('error', 'Revisi tidak ditemukan.');
        }

        try {
            $this->revisi()->batalkan((int) $id, (int) session()->get('user_id') ?: null);

            return redirect()->to($this->urlRevisi())
                ->with('success', 'Draft dibatalkan. Jejaknya tetap tersimpan.');
        } catch (Throwable $e) {
            return redirect()->to($this->urlRevisi())->with('error', $e->getMessage());
        }
    }
}
