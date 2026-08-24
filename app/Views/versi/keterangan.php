<?php

/**
 * Ubah keterangan versi: label, tanggal berlaku, dasar, alasan.
 *
 * Dua keadaan yang dilayani berkas yang sama:
 *   $bolehPenuh   -> versi masih draft; seluruh keterangan boleh diubah
 *   $bolehTanggal -> baseline otomatis yang arsipnya masih kosong; HANYA
 *                    tanggal berlakunya yang boleh diperbaiki, dan wajib beralasan
 *
 * @var array $versi
 * @var array $timeline versi published pada lingkup ini, urut maju
 */
$title        = $title ?? 'Ubah Keterangan Versi';
$judulHalaman = $judulHalaman ?? 'Ubah Keterangan Versi';

$old = static fn (string $k, $d = '') => old($k, $d);
?>
<?= $this->include('templates/shell_atas') ?>

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <span class="fw-semibold">V<?= (int) $versi['version_no'] ?> — <?= esc($versi['label']) ?></span>
        <div class="text-secondary sel-kecil">
            <?= esc($namaDokumen) ?> <?= (int) $versi['periode_mulai'] ?>&ndash;<?= (int) $versi['periode_akhir'] ?>
            &middot; status <?= esc($versi['status']) ?>
        </div>
    </div>
    <a href="<?= base_url($baseUrl . '/versi/lihat/' . (int) $versi['id']) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fa-solid fa-arrow-left me-1"></i>Kembali
    </a>
</div>

<?php
/* Dua keadaan berbeda sama-sama sampai ke sini dengan $bolehTanggal, dan
   keduanya perlu penjelasan yang berbeda: memperbaiki tebakan pemasangan
   bukan hal yang sama dengan menggeser tanggal dokumen yang sudah resmi. */
$baselineOtomatis = ($versi['status'] ?? '') === 'published'
    && (int) ($versi['version_no'] ?? 0) === 1
    && ($versi['created_by'] ?? null) === null;
?>

<?php if (! $bolehPenuh && $bolehTanggal && $baselineOtomatis): ?>
    <div class="kotak-jejak awas mb-4">
        <div class="fw-semibold mb-1">Anda sedang memperbaiki tanggal baseline otomatis</div>
        <div class="small text-secondary">
            Baris ini dibuat otomatis saat pemasangan, dan tanggalnya (1 Januari awal periode)
            adalah <strong>tebakan sistem</strong> — bukan keputusan yang pernah ditetapkan.
            Arsipnya masih kosong, jadi tidak ada isi resmi yang terpengaruh.
        </div>
    </div>
<?php elseif (! $bolehPenuh && $bolehTanggal): ?>
    <div class="kotak-jejak awas mb-4">
        <div class="fw-semibold mb-1">Anda menggeser tanggal berlaku versi yang sudah ditetapkan</div>
        <div class="small text-secondary">
            Yang berubah <strong>hanya kapan</strong> versi ini mulai dipakai. Isinya —
            tujuan, sasaran, indikator, target — tidak tersentuh, sehingga arsipnya tetap
            beku dan snapshot LAKIP yang merujuknya tetap cocok.
            <br><br>
            Namun ini menggeser batas versi tetangganya juga, dan bisa mengubah versi mana
            yang berlaku pada suatu tahun. Periksa pratinjau garis waktu di bawah sebelum
            menyimpan, dan sebutkan alasannya — keduanya tercatat pada jejak audit.
        </div>
    </div>
<?php else: ?>
    <div class="kotak-jejak beku mb-4">
        <div class="fw-semibold mb-1">Versi ini masih draft</div>
        <div class="small text-secondary">
            Seluruh keterangan boleh diubah dan belum mengikat apa pun. Tanggal berlaku
            menentukan versi mana yang dipakai pada suatu tanggal — nomor versi tidak.
        </div>
    </div>
<?php endif; ?>

<?php if (! empty($timeline)): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">Tanggal yang Sudah Terpakai</div>
        <div class="card-body">
            <div class="small text-secondary mb-2">
                Dua versi yang sudah ditetapkan tidak boleh mulai pada tanggal yang sama.
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($timeline as $t): ?>
                    <span class="badge <?= (int) $t['id'] === (int) $versi['id'] ? 'bg-primary' : 'bg-light text-dark border' ?>">
                        V<?= (int) $t['version_no'] ?>: <?= esc($t['effective_from']) ?>
                        <?= (int) $t['id'] === (int) $versi['id'] ? ' (ini)' : '' ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (! empty($timeline)): ?>
    <?php
    /* ================= PRATINJAU GARIS WAKTU =================
     *
     * Mengubah tanggal mulai sebuah versi TIDAK hanya menggeser versi itu:
     * ia menggeser batas versi tetangganya juga, sebab `effective_to` dihitung
     * dari tanggal mulai versi berikutnya. Akibat itu tidak terlihat dari
     * kotak tanggal, dan selama ini baru ketahuan setelah disimpan.
     *
     * Perhitungan di bawah SENGAJA diulang di JavaScript supaya akibatnya
     * terbaca sambil mengetik. Yang menentukan tetap server: `praTinjau()` dan
     * `ubahTanggalBerlaku()` yang berhak menolak. Ini pratinjau, bukan putusan.
     */
    $garis = [];

    foreach ($timeline as $t) {
        $garis[] = [
            'id'    => (int) $t['id'],
            'no'    => (int) $t['version_no'],
            'label' => (string) $t['label'],
            'mulai' => (string) $t['effective_from'],
        ];
    }

    // Versi yang sedang disunting mungkin masih draft, sehingga belum ada di
    // garis published. Ia tetap ditampilkan supaya pengisi tahu ia akan mendarat
    // di mana bila kelak ditetapkan.
    $iniAda = false;

    foreach ($garis as $g) {
        if ($g['id'] === (int) $versi['id']) {
            $iniAda = true;
        }
    }

    if (! $iniAda) {
        $garis[] = [
            'id'    => (int) $versi['id'],
            'no'    => (int) $versi['version_no'],
            'label' => (string) $versi['label'] . ' (draft)',
            'mulai' => (string) $versi['effective_from'],
        ];
    }
    ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            Pratinjau Garis Waktu
            <div class="text-secondary sel-kecil fw-normal mt-1">
                Berubah mengikuti tanggal yang Anda isi. Tanggal berakhir tidak diketik &mdash;
                ia dihitung dari tanggal mulai versi sesudahnya, sehingga tidak pernah ada
                celah maupun tumpang tindih.
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="small fw-semibold text-secondary mb-2">Sekarang</div>
                    <div id="garisSebelum" class="d-flex flex-column gap-1"></div>
                </div>
                <div class="col-md-6">
                    <div class="small fw-semibold text-secondary mb-2">Setelah disimpan</div>
                    <div id="garisSesudah" class="d-flex flex-column gap-1"></div>
                </div>
            </div>

            <div id="peringatanGaris" class="mt-3"></div>
        </div>
    </div>

    <script>
        (function () {
            const asal    = <?= json_encode($garis, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const iniId   = <?= (int) $versi['id'] ?>;
            const awalPer = '<?= (int) $versi['periode_mulai'] ?>-01-01';
            const akhirPer = '<?= (int) $versi['periode_akhir'] ?>-12-31';

            const kotakSebelum = document.getElementById('garisSebelum');
            const kotakSesudah = document.getElementById('garisSesudah');
            const kotakPeringatan = document.getElementById('peringatanGaris');
            const medanTanggal = document.querySelector('input[name="effective_from"]');

            if (!medanTanggal) return;

            // Hitung rentang tiap versi: berakhir tepat sebelum versi berikutnya
            // mulai. Yang terakhir tidak punya penerus, jadi "seterusnya".
            function susun(daftar) {
                const urut = daftar.slice().sort(function (a, b) {
                    return a.mulai < b.mulai ? -1 : (a.mulai > b.mulai ? 1 : a.no - b.no);
                });

                return urut.map(function (v, i) {
                    const berikut = urut[i + 1];
                    return Object.assign({}, v, {
                        sampai: berikut ? berikut.mulai : null,
                        // Dua versi yang mulai di tanggal sama: yang di bawah
                        // tidak pernah punya rentang sama sekali.
                        nol: berikut && berikut.mulai === v.mulai,
                    });
                });
            }

            function gambar(kotak, daftar) {
                kotak.innerHTML = '';

                daftar.forEach(function (v) {
                    const ini = v.id === iniId;
                    const el = document.createElement('div');
                    el.className = 'border rounded px-2 py-1 small '
                        + (ini ? 'border-primary bg-primary-subtle' : 'bg-light');
                    el.innerHTML =
                        '<span class="fw-semibold">V' + v.no + '</span> '
                        + '<span class="text-secondary">' + v.mulai + ' &rarr; '
                        + (v.sampai ? 'sebelum ' + v.sampai : 'seterusnya') + '</span>'
                        + (v.nol ? ' <span class="badge bg-danger">tidak pernah berlaku</span>' : '')
                        + (ini ? ' <span class="badge bg-primary">ini</span>' : '');
                    kotak.appendChild(el);
                });
            }

            function perbarui() {
                const baru = medanTanggal.value;

                gambar(kotakSebelum, susun(asal));

                if (!baru) {
                    kotakSesudah.innerHTML = '<div class="text-secondary small">Isi tanggalnya dulu.</div>';
                    kotakPeringatan.innerHTML = '';
                    return;
                }

                const simulasi = asal.map(function (v) {
                    return v.id === iniId ? Object.assign({}, v, { mulai: baru }) : v;
                });

                const hasil = susun(simulasi);
                gambar(kotakSesudah, hasil);

                const pesan = [];

                if (baru < awalPer || baru > akhirPer) {
                    pesan.push(['danger', 'Tanggal di luar periode dokumen (' + awalPer + ' s.d. ' + akhirPer + '). Penyimpanan akan ditolak.']);
                }

                asal.forEach(function (v) {
                    if (v.id !== iniId && v.mulai === baru) {
                        pesan.push(['danger', 'Bentrok dengan V' + v.no + ' yang juga mulai ' + baru
                            + '. Dua versi resmi tidak boleh mulai di tanggal yang sama — penyimpanan akan ditolak.']);
                    }
                });

                hasil.forEach(function (v) {
                    if (v.nol && v.id !== iniId) {
                        pesan.push(['warning', 'V' + v.no + ' jadi tidak pernah berlaku sama sekali.']);
                    }
                });

                const posisiLama = susun(asal).findIndex(function (v) { return v.id === iniId; });
                const posisiBaru = hasil.findIndex(function (v) { return v.id === iniId; });

                if (posisiLama !== posisiBaru && pesan.length === 0) {
                    pesan.push(['info', 'Urutan berlakunya berpindah: versi ini bergeser dari posisi ke-'
                        + (posisiLama + 1) + ' menjadi ke-' + (posisiBaru + 1) + '.']);
                }

                kotakPeringatan.innerHTML = pesan.map(function (p) {
                    return '<div class="alert alert-' + p[0] + ' py-2 px-3 small mb-2">' + p[1] + '</div>';
                }).join('');
            }

            medanTanggal.addEventListener('input', perbarui);
            perbarui();
        })();
    </script>
<?php endif; ?>

<form method="post" action="<?= base_url($baseUrl . '/versi/keterangan/' . (int) $versi['id']) ?>">
    <?= csrf_field() ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <?php if ($bolehPenuh): ?>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Label Versi</label>
                    <input type="text" name="label" class="form-control form-control-sm" maxlength="255"
                           value="<?= esc($old('label', $versi['label'])) ?>">
                </div>
            <?php endif; ?>

            <div class="mb-3">
                <label class="form-label small fw-semibold">
                    Tanggal Mulai Berlaku <span class="text-danger">*</span>
                </label>
                <input type="date" name="effective_from" class="form-control form-control-sm" required
                       min="<?= (int) $versi['periode_mulai'] ?>-01-01"
                       max="<?= (int) $versi['periode_akhir'] ?>-12-31"
                       value="<?= esc($old('effective_from', $versi['effective_from'])) ?>">
                <div class="form-text">
                    Harus berada dalam periode
                    <?= (int) $versi['periode_mulai'] ?>&ndash;<?= (int) $versi['periode_akhir'] ?>.
                    Boleh tanggal masa lalu (versi historis) maupun masa depan.
                </div>
            </div>

            <?php if ($bolehPenuh): ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Dasar Perubahan</label>
                        <input type="text" name="dasar_perubahan" class="form-control form-control-sm" maxlength="255"
                               value="<?= esc($old('dasar_perubahan', $versi['dasar_perubahan'] ?? '')) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Nomor</label>
                        <input type="text" name="nomor_dasar" class="form-control form-control-sm" maxlength="150"
                               value="<?= esc($old('nomor_dasar', $versi['nomor_dasar'] ?? '')) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Tanggal Dasar</label>
                        <input type="date" name="tanggal_dasar" class="form-control form-control-sm"
                               value="<?= esc($old('tanggal_dasar', $versi['tanggal_dasar'] ?? '')) ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Alasan Perubahan</label>
                    <textarea name="alasan_perubahan" rows="3"
                              class="form-control form-control-sm"><?= esc($old('alasan_perubahan', $versi['alasan_perubahan'] ?? '')) ?></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Catatan</label>
                    <textarea name="catatan" rows="2"
                              class="form-control form-control-sm"><?= esc($old('catatan', $versi['catatan'] ?? '')) ?></textarea>
                </div>
            <?php endif; ?>

            <div class="mb-0">
                <label class="form-label small fw-semibold">
                    Alasan mengubah tanggal berlaku
                    <?php if (! $bolehPenuh): ?><span class="text-danger">*</span><?php endif; ?>
                </label>
                <textarea name="alasan_ubah" rows="2" class="form-control form-control-sm"
                          <?= $bolehPenuh ? '' : 'required' ?>
                          placeholder="mis. Baseline otomatis digeser agar versi historis bisa disisipkan"><?= esc($old('alasan_ubah')) ?></textarea>
                <div class="form-text">Tersimpan pada jejak audit versi ini.</div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center">
        <a href="<?= base_url($baseUrl . '/versi/lihat/' . (int) $versi['id']) ?>" class="btn btn-outline-secondary btn-sm">
            Batal
        </a>
        <button type="submit" class="btn btn-success btn-sm">
            <i class="fa-solid fa-floppy-disk me-1"></i>Simpan
        </button>
    </div>
</form>

<?= $this->include('templates/shell_bawah') ?>
