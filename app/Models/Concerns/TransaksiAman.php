<?php

namespace App\Models\Concerns;

use RuntimeException;
use Throwable;

/**
 * Pembungkus transaksi yang benar-benar bisa di-rollback.
 *
 * Dipakai modul revisi IKU, snapshot LAKIP, dan penyesuaian kebijakan —
 * ketiganya menulis ke banyak tabel sekaligus dan invariant 7 mensyaratkan
 * "gagal satu langkah = rollback seluruh operasi, tidak boleh ada revisi atau
 * snapshot setengah jadi".
 *
 * ---------------------------------------------------------------------
 * DUA JEBAKAN CODEIGNITER 4 YANG DITANGANI DI SINI
 *
 * 1. TRANSAKSI BERSARANG ITU PALSU.
 *    BaseConnection::transBegin() baris 833-837: bila transDepth sudah > 0 ia
 *    HANYA menaikkan penghitung dan langsung return true — tidak ada SAVEPOINT.
 *    Pasangannya, transRollback() pada kedalaman > 1, juga hanya menurunkan
 *    penghitung tanpa ROLLBACK sungguhan.
 *
 *    Akibatnya: memanggil operasi bertransaksi dari DALAM transaksi lain
 *    menghasilkan data separuh tertulis TANPA exception apa pun. Karena itu
 *    method ini MENOLAK dijalankan bila sudah ada transaksi berjalan, alih-alih
 *    berpura-pura aman.
 *
 * 2. $transStatus ITU LENGKET.
 *    transBegin() hanya me-reset $transFailure, bukan $transStatus (lihat baris
 *    846 vs 322). Satu query gagal di awal request — misalnya pemeriksaan tabel
 *    opsional yang wajar gagal — membuat SEMUA transaksi berikutnya dalam
 *    request yang sama dilaporkan gagal, padahal query-nya sendiri sukses.
 *
 *    Karena itu status di-reset tepat setelah transaksi terluar dibuka.
 * ---------------------------------------------------------------------
 */
trait TransaksiAman
{
    /**
     * Jalankan $kerja di dalam satu transaksi sungguhan.
     *
     * @param callable():mixed $kerja
     * @param string           $namaOperasi dipakai pada pesan galat
     *
     * @return mixed nilai kembalian $kerja
     *
     * @throws RuntimeException bila sudah ada transaksi berjalan atau transaksi gagal
     * @throws Throwable        apa pun yang dilempar $kerja, setelah rollback
     */
    protected function dalamTransaksi(callable $kerja, string $namaOperasi = 'operasi')
    {
        $db = $this->db;

        if ($db->transDepth > 0) {
            // Sengaja menolak, bukan ikut bersarang. Lihat jebakan 1 di atas:
            // bersarang di CI4 tidak memberi jaminan rollback apa pun, dan
            // diam-diam menulis separuh data jauh lebih berbahaya daripada
            // menolak terang-terangan.
            throw new RuntimeException(
                'Tidak bisa menjalankan ' . $namaOperasi . ' di dalam transaksi lain: '
                . 'CodeIgniter tidak mendukung transaksi bersarang yang benar-benar bisa dibatalkan.'
            );
        }

        $db->transBegin();
        $db->resetTransStatus(); // lihat jebakan 2

        try {
            $hasil = $kerja();

            if ($db->transStatus() === false) {
                throw new RuntimeException('Transaksi ' . $namaOperasi . ' gagal pada salah satu query.');
            }

            $db->transCommit();

            return $hasil;
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        }
    }
}
