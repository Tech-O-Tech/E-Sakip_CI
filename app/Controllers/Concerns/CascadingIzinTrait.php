<?php

namespace App\Controllers\Concerns;

/**
 * Penjaga izin untuk layar Cascading, OPD maupun Kabupaten.
 *
 * =====================================================================
 * MENGAPA DI SATU TEMPAT
 *
 * Sebelum ini kedua controller Cascading TIDAK memeriksa izin sama sekali —
 * bandingkan dengan controller IKU yang punya 9 dan 7 pemeriksaan. Padahal
 * izin `cascading_opd.*` dan `cascading_kab.*` sudah lama ada di basis data.
 * Siapa pun yang bisa masuk ke grup rutenya bisa menulis apa saja.
 *
 * Ditegakkan lewat `_remap()`, bukan ditaburkan di ~25 metode: satu tempat
 * yang bisa dibaca sekali duduk, dan metode baru tidak bisa lupa dijaga
 * karena petanya wajib disebut.
 *
 * =====================================================================
 * MENGAPA INI TIDAK MENYULITKAN SIAPA PUN
 *
 * Sudah diperiksa terhadap tabel `role_permissions` yang berlaku:
 *
 *   * adminopd  -> admin_opd, admin, admin_kecamatan
 *                  ketiganya memegang cascading_opd.{view,create,update,delete}
 *   * adminkab  -> admin_kab, admin, admin_inspektorat
 *                  dua yang pertama memegang cascading_kab.* penuh
 *
 * Jadi tidak ada operator yang kehilangan apa pun yang bisa ia kerjakan hari
 * ini. Satu-satunya perubahan: `admin_inspektorat` — yang hanya memegang
 * `cascading_kab.view` — tidak lagi bisa MENULIS cascading kabupaten. Itu
 * memang maksud perannya sebagai pemeriksa, dan tabel izinnya sudah
 * mengatakannya sejak awal; aplikasinya saja yang belum menghormatinya.
 */
trait CascadingIzinTrait
{
    /**
     * Peta metode -> izin yang dibutuhkan. Diisi masing-masing controller.
     *
     * Metode yang TIDAK disebut di sini diperlakukan sebagai bacaan biasa,
     * sama seperti perilaku sebelum penjaga ini ada — supaya pemasangannya
     * tidak diam-diam mematikan sesuatu yang belum sempat dipetakan.
     *
     * @return array<string, string>
     */
    abstract protected function petaIzinCascading(): array;

    /** Izin bacaan bagi metode yang belum dipetakan. */
    abstract protected function izinBacaCascading(): string;

    /**
     * Bolehkah metode ini dijalankan? Kembalikan null bila boleh, atau
     * tanggapan penolakan bila tidak.
     */
    protected function tolakBilaTakBerizin(string $method)
    {
        $peta = $this->petaIzinCascading();
        $izin = $peta[$method] ?? $this->izinBacaCascading();

        if (user_can($izin)) {
            return null;
        }

        // Tindakan tulis dan tindakan baca ditolak dengan kalimat berbeda:
        // yang pertama biasanya salah peran, yang kedua salah alamat.
        $pesan = str_ends_with($izin, '.view')
            ? 'Anda tidak memiliki akses untuk melihat Cascading.'
            : 'Anda hanya dapat melihat Cascading, tidak mengubahnya.';

        log_message('info', '[CASCADING IZIN] ditolak: ' . $method . ' butuh ' . $izin);

        if ($this->request->isAJAX()) {
            return $this->response->setStatusCode(403)
                ->setJSON(['success' => false, 'status' => 'error', 'message' => $pesan]);
        }

        return redirect()->back()->with('error', $pesan);
    }

    /**
     * Hanya metode publik yang boleh dicapai lewat URL.
     *
     * Begitu `_remap()` ada, CI4 menyerahkan SETIAP nama metode ke sini —
     * termasuk yang privat dan yang tidak ada. Tanpa saringan ini, metode
     * pembantu seperti `insertCascadingRow` mendadak bisa dipanggil lewat URL.
     */
    protected function metodePublikCascading(string $method): bool
    {
        if (str_starts_with($method, '_') || ! method_exists($this, $method)) {
            return false;
        }

        return (new \ReflectionMethod($this, $method))->isPublic();
    }
}
