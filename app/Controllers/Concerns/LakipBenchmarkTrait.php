<?php

namespace App\Controllers\Concerns;

use App\Models\LakipBenchmarkModel;

/**
 * Benchmark Provinsi Lampung & Nasional pada halaman LAKIP.
 *
 * Dipakai bersama AdminKab\LakipController dan AdminOpd\LakipOpdController,
 * dan MENUMPANG lingkup (mode/tahun/opdScope) milik LakipAddendumTrait —
 * jadi chart selalu mengikuti filter LAKIP yang sedang aktif.
 *
 * ---------------------------------------------------------------------
 * HAK AKSES — sengaja TIDAK memakai izin lakip_kab / lakip_opd
 *
 * Role OPD boleh menulis LAKIP unitnya sendiri, tetapi TIDAK boleh mengubah
 * angka pembanding Provinsi/Nasional. Karena itu benchmark memakai modul izin
 * tersendiri:
 *
 *   lakip_benchmark.view   -> semua role pembaca LAKIP
 *   lakip_benchmark.manage -> admin_kab (role 'admin' otomatis lolos user_can)
 *
 * Pemeriksaan dilakukan di SERVER pada setiap aksi tulis, bukan sekadar
 * menyembunyikan tombol di view.
 * ---------------------------------------------------------------------
 */
trait LakipBenchmarkTrait
{
    protected ?LakipBenchmarkModel $benchmarkModel = null;

    protected function benchmark(): LakipBenchmarkModel
    {
        return $this->benchmarkModel ??= new LakipBenchmarkModel();
    }

    /**
     * Boleh input/ubah benchmark pada SATU LINGKUP.
     *
     * Tiga izin, tiga jangkauan yang berbeda — dan lingkupnya wajib ikut
     * diperiksa, bukan hanya nama izinnya:
     *
     *   lakip_benchmark.manage       kelola tanpa batas lingkup (izin lama)
     *   lakip_benchmark.manage_all   kelola seluruh OPD (Kabupaten)
     *   lakip_benchmark.manage_own   kelola HANYA lingkup sendiri
     *
     * Tanpa pemeriksaan lingkup, `manage_own` berubah arti menjadi
     * `manage_all`: satu OPD bisa mengisi angka pembanding milik OPD lain
     * hanya dengan menukar `opd_id` di form.
     *
     * @param array|null $scope hasil lakipScope(); null = pertanyaan umum
     *                          "boleh mengelola sama sekali?", dipakai view
     *                          untuk memutuskan apakah tombolnya dirender.
     */
    protected function benchmarkCanManage(?array $scope = null): bool
    {
        helper('rbac');

        if (user_can('lakip_benchmark.manage') || user_can('lakip_benchmark.manage_all')) {
            return true;
        }

        if (! user_can('lakip_benchmark.manage_own')) {
            return false;
        }

        if ($scope === null) {
            return true;
        }

        // Lingkup kabupaten bukan milik siapa pun kecuali pemegang manage_all.
        if ((string) ($scope['mode'] ?? 'opd') === 'kabupaten') {
            return false;
        }

        $opdScope = (int) ($scope['opdScope'] ?? 0);
        $opdSesi  = (int) (session()->get('opd_id') ?? 0);

        return $opdScope > 0 && $opdSesi > 0 && $opdScope === $opdSesi;
    }

    /** Boleh melihat chart benchmark? */
    protected function benchmarkCanView(): bool
    {
        helper('rbac');

        // Sudah lolos gate halaman LAKIP; izin mengelola otomatis mencakup lihat.
        return user_can('lakip_benchmark.view')
            || user_can('lakip_benchmark.manage')
            || user_can('lakip_benchmark.manage_all')
            || user_can('lakip_benchmark.manage_own');
    }

    /**
     * Bahan chart perbandingan untuk view.
     *
     * Nilai Kabupaten/OPD diambil dari LAKIP existing ($lakipMap, kunci
     * target_id) — sumber datanya tidak diubah sama sekali. Benchmark hanya
     * menempelkan nilai Provinsi & Nasional per indikator.
     *
     * @param array $scope    hasil LakipAddendumTrait::lakipScope()
     * @param array $rows     baris target/indikator tahun aktif
     * @param array $lakipMap peta baris LAKIP; boleh dikunci target_id
     *                        (AdminKab) maupun indikator_id (AdminOpd)
     *
     * @return array<string, mixed>
     */
    protected function lakipBenchmarkData(array $scope, array $rows, array $lakipMap): array
    {
        // Kedua controller LAKIP mengunci petanya dengan cara berbeda; nilainya
        // sama-sama memuat indikator_id, jadi dikunci ulang di sini.
        $lakipByIndikator = [];
        foreach ($lakipMap as $baris) {
            if (is_array($baris) && !empty($baris['indikator_id'])) {
                $lakipByIndikator[(int) $baris['indikator_id']] = $baris;
            }
        }

        $mode  = (string) ($scope['mode'] ?? 'opd');
        $tahun = (string) ($scope['tahun'] ?? '');
        $opd   = $scope['opdScope'];

        // Mode OPD tanpa memilih OPD: indikator antar-OPD berbeda-beda sehingga
        // tidak boleh diagregasi. View menampilkan pesan "pilih satu OPD".
        $perluPilihOpd = ($mode === 'opd') && empty($opd);

        // Sumber ikut disaring: angka pembanding milik Renstra tidak boleh
        // muncul pada indikator IKU yang kebetulan ber-id sama.
        $sumberDok = $this->sumberDokumenLakip($mode);

        $benchmarkMap = $perluPilihOpd
            ? []
            : $this->benchmark()->getByTahunKeyedByIndikator(
                $tahun, $mode, $opd ? (int) $opd : null, $sumberDok
            );

        $daftar = [];
        if (!$perluPilihOpd) {
            foreach ($rows as $r) {
                $indikatorId = (int) ($r['indikator_id'] ?? 0);
                $targetId    = (int) ($r['target_id'] ?? 0);
                if ($indikatorId <= 0) {
                    continue;
                }
                // Satu indikator cukup sekali walau punya beberapa baris target.
                if (isset($daftar[$indikatorId])) {
                    continue;
                }

                $lakipBaris = $lakipByIndikator[$indikatorId] ?? null;
                $bm         = $benchmarkMap[$indikatorId] ?? null;

                $daftar[$indikatorId] = [
                    'indikator_id' => $indikatorId,
                    'target_id'    => $targetId,
                    'nama'         => (string) ($r['indikator_sasaran'] ?? '-'),
                    'satuan'       => (string) ($r['satuan'] ?? ''),
                    'sasaran'      => (string) ($r['sasaran'] ?? ''),
                    'nama_opd'     => (string) ($r['nama_opd'] ?? ''),
                    // Nilai indikator (bukan persentase capaian).
                    'nilai_daerah' => $this->benchmarkAngka($lakipBaris['capaian_tahun_ini'] ?? null),
                    'nilai_provinsi'  => isset($bm['nilai_provinsi']) && $bm['nilai_provinsi'] !== null ? (float) $bm['nilai_provinsi'] : null,
                    'nilai_nasional'  => isset($bm['nilai_nasional']) && $bm['nilai_nasional'] !== null ? (float) $bm['nilai_nasional'] : null,
                    'sumber_provinsi' => (string) ($bm['sumber_provinsi'] ?? ''),
                    'sumber_nasional' => (string) ($bm['sumber_nasional'] ?? ''),
                    'catatan'         => (string) ($bm['catatan'] ?? ''),
                    'updated_at'      => (string) ($bm['updated_at'] ?? ''),
                    'benchmark_id'    => (int) ($bm['id'] ?? 0),
                    'ada_benchmark'   => $bm !== null,
                ];
            }
        }

        return [
            'benchmarkList'          => array_values($daftar),
            'benchmarkCanManage'     => $this->benchmarkCanManage($scope),
            'benchmarkCanView'       => $this->benchmarkCanView(),
            'benchmarkPerluPilihOpd' => $perluPilihOpd,
            'benchmarkSiap'          => $this->benchmark()->siap(),
        ];
    }

    /**
     * "3,52" / "3.52" / "85 %" -> float. Kolom capaian LAKIP bertipe VARCHAR
     * sehingga isinya bisa memakai koma desimal atau membawa satuan.
     */
    protected function benchmarkAngka($nilai): ?float
    {
        if ($nilai === null) {
            return null;
        }
        $teks = trim((string) $nilai);
        if ($teks === '') {
            return null;
        }

        // Buang semua kecuali digit, koma, titik, dan minus.
        $bersih = preg_replace('/[^0-9,.\-]/', '', $teks);
        if ($bersih === '' || $bersih === '-') {
            return null;
        }

        $titik = strrpos($bersih, '.');
        $koma  = strrpos($bersih, ',');

        if ($koma !== false && ($titik === false || $koma > $titik)) {
            // Format Indonesia: titik = ribuan, koma = desimal.
            $bersih = str_replace('.', '', $bersih);
            $bersih = str_replace(',', '.', $bersih);
        } else {
            $bersih = str_replace(',', '', $bersih);
        }

        return is_numeric($bersih) ? (float) $bersih : null;
    }

    /**
     * "3,52" -> float untuk input form. Mengembalikan null bila kosong,
     * false bila bukan angka.
     *
     * @return float|null|false
     */
    protected function benchmarkNilaiInput($nilai)
    {
        if ($nilai === null || trim((string) $nilai) === '') {
            return null;
        }

        $angka = $this->benchmarkAngka($nilai);

        return $angka === null ? false : $angka;
    }

    /* =========================================================
     * SIMPAN & HAPUS
     * =======================================================*/

    /** POST tambah/ubah benchmark. Upsert per indikator + tahun. */
    public function benchmarkSave()
    {
        $scope = $this->lakipScopeFromPost();
        $back  = $this->kembaliLakip($scope);

        if (!$this->benchmark()->siap()) {
            return redirect()->to($back)->with('error', 'Tabel lakip_benchmark belum tersedia. Jalankan db/update_2026-08-12_lakip_benchmark.sql.');
        }

        // Otorisasi di server — bukan sekadar tombol yang disembunyikan.
        if (!$this->benchmarkCanManage($scope)) {
            return redirect()->to($back)->with('error', 'Anda tidak berhak mengubah data benchmark Provinsi/Nasional.');
        }

        // Lingkup tetap dihormati: mode OPD wajib menunjuk satu OPD.
        if ($scope['mode'] === 'opd' && empty($scope['opdScope'])) {
            return redirect()->to($back)->with('error', 'Pilih satu OPD dulu sebelum mengisi benchmark.');
        }

        $sumberDok   = $this->sumberDokumenLakip((string) $scope['mode']);
        $indikatorId = (int) ($this->request->getPost('indikator_id') ?? 0);
        $indikator   = $this->benchmark()->indikatorSah(
            $indikatorId, $scope['mode'], $scope['tahun'], $scope['opdScope'], $sumberDok
        );
        if (!$indikator) {
            return redirect()->to($back)->with('error', 'Indikator tidak ditemukan pada tahun & unit yang dipilih.');
        }

        $provinsi = $this->benchmarkNilaiInput($this->request->getPost('nilai_provinsi'));
        $nasional = $this->benchmarkNilaiInput($this->request->getPost('nilai_nasional'));
        if ($provinsi === false) {
            return redirect()->to($back)->withInput()->with('error', 'Nilai Provinsi harus berupa angka.');
        }
        if ($nasional === false) {
            return redirect()->to($back)->withInput()->with('error', 'Nilai Nasional harus berupa angka.');
        }
        if ($provinsi === null && $nasional === null) {
            return redirect()->to($back)->withInput()
                ->with('error', 'Isi minimal salah satu dari Nilai Provinsi atau Nilai Nasional.');
        }

        $teks = [
            'sumber_provinsi' => trim((string) ($this->request->getPost('sumber_provinsi') ?? '')),
            'sumber_nasional' => trim((string) ($this->request->getPost('sumber_nasional') ?? '')),
            'catatan'         => trim((string) ($this->request->getPost('catatan') ?? '')),
        ];
        foreach ($teks as $kunci => $nilai) {
            $batas = $kunci === 'catatan' ? 5000 : 255;
            if (mb_strlen($nilai) > $batas) {
                return redirect()->to($back)->withInput()
                    ->with('error', 'Isian ' . str_replace('_', ' ', $kunci) . ' terlalu panjang (maksimal ' . $batas . ' karakter).');
            }
            if (!$this->teksAmanLakip($nilai)) {
                return redirect()->to($back)->withInput()
                    ->with('error', 'Isian benchmark mengandung script / input berbahaya.');
            }
            $teks[$kunci] = ($nilai === '') ? null : $nilai;
        }

        $userId = (int) session()->get('user_id') ?: null;
        $sumber = LakipBenchmarkModel::sumberSah((string) $scope['mode'], $sumberDok);
        $kolom  = LakipBenchmarkModel::kolomIndikator($scope['mode'], $sumber);

        if ($kolom === 'iku_indikator_id' && ! $this->benchmark()->punyaKolomSumber()) {
            return redirect()->to($back)->with('error',
                'Benchmark untuk LAKIP bersumber IKU membutuhkan migrasi '
                . 'db/update_2026-08-26_benchmark_sumber_iku.sql. Jalankan dulu di server ini.');
        }
        $opdId  = ($scope['mode'] === 'kabupaten') ? 0 : (int) $scope['opdScope'];

        $isi = $teks + [
            'nilai_provinsi' => $provinsi,
            'nilai_nasional' => $nasional,
            'updated_by'     => $userId,
        ];

        if ($this->benchmark()->punyaKolomSumber()) {
            $isi['source_type'] = $sumber;
        }

        $lama = $this->benchmark()->cariByIndikator($indikatorId, $scope['tahun'], $scope['mode'], $sumberDok);

        $this->db->transStart();

        if ($lama) {
            $this->benchmark()->update((int) $lama['id'], $isi);
            $pesan = 'Benchmark Provinsi/Nasional berhasil diperbarui.';
        } else {
            $this->benchmark()->insert($isi + [
                $kolom       => $indikatorId,
                'opd_id'     => $opdId,
                'tahun'      => $scope['tahun'],
                'created_by' => $userId,
            ]);
            $pesan = 'Benchmark Provinsi/Nasional berhasil disimpan.';
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return redirect()->to($back)->withInput()->with('error', 'Gagal menyimpan data benchmark.');
        }

        return redirect()->to($back)->with('success', $pesan);
    }

    /** Hapus satu baris benchmark. */
    public function benchmarkDelete($id = null)
    {
        $scope = $this->lakipScopeFromPost();
        $back  = $this->kembaliLakip($scope);

        if (!$this->benchmarkCanManage($scope)) {
            return redirect()->to($back)->with('error', 'Anda tidak berhak menghapus data benchmark Provinsi/Nasional.');
        }

        $baris = $this->benchmark()->ambil((int) $id);
        if (!$baris
            || (string) $baris['tahun'] !== (string) $scope['tahun']
            || (int) $baris['opd_id'] !== (int) ($scope['opdScope'] ?? 0)) {
            return redirect()->to($back)->with('error', 'Data benchmark tidak ditemukan pada unit Anda.');
        }

        $this->benchmark()->delete((int) $id);

        return redirect()->to($back)->with('success', 'Benchmark Provinsi/Nasional berhasil dihapus.');
    }
}
