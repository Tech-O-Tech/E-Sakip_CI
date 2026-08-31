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
                    <?php
                    // Tahun terpakai dikirim sebagai peta {tahun: keterangan}
                    // supaya layar bisa menyebut SIAPA yang memakainya, bukan
                    // sekadar mematikan pilihannya tanpa alasan.
                    $ket = [];
                    foreach (($p['terpakai'] ?? []) as $th => $r) {
                        $ket[$th] = 'revisi ke-' . $r['nomor'] . ' (' . $r['status'] . ')';
                    }
                    ?>
                    <option value="<?= esc($kunci) ?>"
                            data-mulai="<?= (int) $p['tahun_mulai'] ?>"
                            data-akhir="<?= (int) $p['tahun_akhir'] ?>"
                            data-terpakai="<?= esc(json_encode($ket, JSON_UNESCAPED_UNICODE), 'attr') ?>"
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
                <strong>Boleh tahun mundur:</strong> menyusun di <?= $tahunIni ?> untuk diberlakukan
                pada <?= $tahunIni - 1 ?> itu sah — yang menentukan bukan kapan Anda mengetik,
                melainkan tahun mana yang hendak dinilai dengan dokumen ini.
            </div>
            <div id="ket-berlaku" class="form-text mt-1"></div>
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
        //
        // SELURUH tahun periode ditawarkan — tahun pertama termasuk. Yang
        // dimatikan hanya tahun yang benar-benar sudah dipayungi revisi lain,
        // beserta sebutan siapa pemakainya. Penolakan sungguhannya tetap di
        // server (tolakTahunBentrok); yang dikerjakan di sini hanya
        // memindahkannya ke DEPAN, sebelum pemakai mengisi seluruh form.
        (function () {
            const periode = document.getElementById('periode');
            const berlaku = document.getElementById('berlaku');
            const ket     = document.getElementById('ket-berlaku');
            const tahunIni = <?= $tahunIni ?>;

            function isiTahun() {
                const opt = periode.options[periode.selectedIndex];
                if (!opt) return;

                const mulai = parseInt(opt.dataset.mulai, 10);
                const akhir = parseInt(opt.dataset.akhir, 10);

                let terpakai = {};
                try { terpakai = JSON.parse(opt.dataset.terpakai || '{}') || {}; } catch (e) { terpakai = {}; }

                berlaku.innerHTML = '';

                const bebas = [];
                for (let t = mulai; t <= akhir; t++) {
                    const o = document.createElement('option');
                    o.value = t;

                    if (terpakai[t]) {
                        o.textContent = t + ' — sudah dipakai ' + terpakai[t];
                        o.disabled = true;
                    } else {
                        o.textContent = t;
                        bebas.push(t);
                    }

                    berlaku.appendChild(o);
                }

                // Pra-pilih tahun depan bila masih bebas; kalau tidak, tahun
                // bebas pertama. Tidak pernah memilih opsi yang dimatikan —
                // itu membuat tombol Simpan tampak siap padahal pasti ditolak.
                const disukai = Math.min(Math.max(tahunIni + 1, mulai), akhir);
                const pilih   = bebas.includes(disukai) ? disukai : (bebas[0] ?? null);

                if (pilih !== null) {
                    berlaku.value = String(pilih);
                }

                ket.innerHTML = bebas.length
                    ? 'Tahun yang masih bebas: <strong>' + bebas.join(', ') + '</strong>.'
                    : '<span class="text-danger">Seluruh tahun pada periode ini sudah dipayungi revisi lain. '
                      + 'Geser dulu tahun berlaku salah satu revisi yang ada.</span>';
            }

            periode.addEventListener('change', isiTahun);
            isiTahun();
        })();
    </script>
<?php endif; ?>

<?= $this->include('templates/shell_bawah') ?>
