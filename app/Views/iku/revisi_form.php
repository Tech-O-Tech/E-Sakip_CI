<?php
$title        = $title ?? 'Buat Revisi IKU';
$judulHalaman = 'Buat Revisi IKU';

/** @var array $periodeOpsi */
$tahunIni = (int) date('Y');
?>
<?= $this->include('templates/shell_atas') ?>

<div class="kotak-jejak mb-4">
    <div class="fw-semibold mb-1">Yang terjadi setelah tombol Simpan ditekan</div>
    <ul class="small text-secondary mb-0">
        <li>Sebuah <strong>draft</strong> dibuat berisi salinan IKU yang berlaku saat ini.</li>
        <li>IKU berjalan, LAKIP, dashboard, dan API publik <strong>belum berubah sama sekali</strong>.</li>
        <li>Anda menyunting draft itu dulu, lalu mengesahkannya bila sudah benar.</li>
        <li>Bila ini revisi pertama, versi <em>Kondisi Awal</em> ikut dibekukan otomatis
            supaya tahun-tahun sebelum revisi tetap punya dokumen rujukan.</li>
    </ul>
</div>

<?php if (empty($periodeOpsi)): ?>
    <div class="alert alert-warning">
        Belum ada data IKU pada lingkup ini, jadi belum ada yang bisa direvisi.
        Isi IKU terlebih dahulu.
    </div>
    <a href="<?= base_url($baseUrl . '/revisi') ?>" class="btn btn-outline-secondary btn-sm">Kembali</a>
<?php else: ?>
    <form method="post" action="<?= base_url($baseUrl . '/revisi/simpan') ?>" class="row g-3">
        <?= csrf_field() ?>

        <div class="col-md-4">
            <label class="form-label fw-semibold">Periode IKU <span class="text-danger">*</span></label>
            <select name="periode" id="periode" class="form-select" required>
                <?php foreach ($periodeOpsi as $kunci => $p): ?>
                    <option value="<?= esc($kunci) ?>"
                            data-mulai="<?= (int) $p['tahun_mulai'] ?>"
                            data-akhir="<?= (int) $p['tahun_akhir'] ?>"
                            <?= in_array($tahunIni, $p['years'], true) ? 'selected' : '' ?>>
                        <?= esc($p['period']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4">
            <label class="form-label fw-semibold">Berlaku Mulai Tahun <span class="text-danger">*</span></label>
            <select name="berlaku_mulai_tahun" id="berlaku" class="form-select" required></select>
            <div class="form-text">
                Tahun pertama revisi ini dipakai. Tahun-tahun sebelumnya tetap memakai versi lama.
            </div>
        </div>

        <div class="col-md-4">
            <label class="form-label fw-semibold">Nama Revisi</label>
            <input type="text" name="nama" class="form-control" maxlength="255"
                   value="<?= esc(old('nama') ?? '') ?>" placeholder="Otomatis bila dikosongkan">
        </div>

        <div class="col-md-5">
            <label class="form-label fw-semibold">Dasar Hukum</label>
            <input type="text" name="dasar_hukum" class="form-control" maxlength="255"
                   value="<?= esc(old('dasar_hukum') ?? '') ?>"
                   placeholder="mis. Peraturan Bupati tentang Perubahan IKU">
        </div>

        <div class="col-md-4">
            <label class="form-label fw-semibold">Nomor Dasar</label>
            <input type="text" name="nomor_dasar" class="form-control" maxlength="150"
                   value="<?= esc(old('nomor_dasar') ?? '') ?>">
        </div>

        <div class="col-md-3">
            <label class="form-label fw-semibold">Tanggal Dasar</label>
            <input type="date" name="tanggal_dasar" class="form-control"
                   value="<?= esc(old('tanggal_dasar') ?? '') ?>">
        </div>

        <div class="col-12">
            <label class="form-label fw-semibold">Catatan</label>
            <textarea name="catatan" class="form-control" rows="2"
                      placeholder="Alasan revisi, ringkasan perubahan, dsb."><?= esc(old('catatan') ?? '') ?></textarea>
        </div>

        <div class="col-12 d-flex gap-2">
            <button class="btn btn-success">
                <i class="fa-solid fa-floppy-disk me-1"></i>Simpan sebagai Draft
            </button>
            <a href="<?= base_url($baseUrl . '/revisi') ?>" class="btn btn-outline-secondary">Batal</a>
        </div>
    </form>

    <script>
        // Pilihan "berlaku mulai" selalu mengikuti periode yang dipilih, supaya
        // tahun di luar periode tidak pernah bisa terkirim ke server.
        (function () {
            const periode = document.getElementById('periode');
            const berlaku = document.getElementById('berlaku');
            const tahunIni = <?= $tahunIni ?>;

            function isiTahun() {
                const opt = periode.options[periode.selectedIndex];
                if (!opt) return;

                const mulai = parseInt(opt.dataset.mulai, 10);
                const akhir = parseInt(opt.dataset.akhir, 10);

                berlaku.innerHTML = '';
                for (let t = mulai; t <= akhir; t++) {
                    const o = document.createElement('option');
                    o.value = t;
                    o.textContent = t;
                    // Revisi umumnya diberlakukan mulai tahun depan.
                    if (t === Math.min(Math.max(tahunIni + 1, mulai), akhir)) o.selected = true;
                    berlaku.appendChild(o);
                }
            }

            periode.addEventListener('change', isiTahun);
            isiTahun();
        })();
    </script>
<?php endif; ?>

<?= $this->include('templates/shell_bawah') ?>
