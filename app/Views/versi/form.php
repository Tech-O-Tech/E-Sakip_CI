<?php

/**
 * Form buat versi baru (§9).
 *
 * Tiga cara mengisi versi, sesuai §9:
 *   - salin dari versi existing (DEEP COPY §10)
 *   - salin kondisi berjalan
 *   - mulai dari kosong
 *
 * @var \App\Services\Version\VersionScope $scope
 * @var array $sumberSalin  versi PUBLISHED yang boleh jadi asal salinan
 */
$title        = $title ?? ('Buat Versi ' . $namaDokumen);
$judulHalaman = $judulHalaman ?? ('Buat Versi Baru — ' . $namaDokumen);

$awalPeriode = $scope->periodeMulai() . '-01-01';
$old         = static fn (string $k, $d = '') => old($k, $d);
?>
<?= $this->include('templates/shell_atas') ?>

<div class="kotak-jejak beku mb-4">
    <div class="fw-semibold mb-1">Versi baru selalu lahir sebagai draft</div>
    <div class="small text-secondary">
        Membuat versi <strong>tidak mengubah apa pun</strong> pada data yang sedang berjalan.
        Isinya baru diterapkan setelah versi ditetapkan berlaku, dan pada saat itu pun
        baris yang tidak lagi tercantum akan <strong>dipensiunkan, bukan dihapus</strong> —
        sehingga Cascading, RKT, Renaksi, MONEV, dan LAKIP tahun-tahun lampau tetap utuh.
    </div>
</div>

<form method="post" action="<?= base_url($baseUrl . '/versi/simpan') ?>" class="mb-0">
    <?= csrf_field() ?>
    <input type="hidden" name="periode" value="<?= esc($periode) ?>">

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Identitas Versi</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Dokumen</label>
                        <input type="text" class="form-control form-control-sm" disabled
                               value="<?= esc($namaDokumen . ' ' . $periode) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">
                            Label Versi <span class="text-secondary fw-normal">(kosongkan untuk otomatis)</span>
                        </label>
                        <input type="text" name="label" class="form-control form-control-sm"
                               maxlength="255"
                               placeholder="V<?= (int) $nomorBerikut ?> — Perubahan Pertama"
                               value="<?= esc($old('label')) ?>">
                        <div class="form-text">
                            Nomor versi berikutnya: <strong>V<?= (int) $nomorBerikut ?></strong>.
                            Nomor ini <em>tidak</em> menentukan waktu berlaku.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Tanggal Mulai Berlaku <span class="text-danger">*</span></label>
                        <input type="date" name="effective_from" class="form-control form-control-sm" required
                               min="<?= $scope->periodeMulai() ?>-01-01"
                               max="<?= $scope->periodeAkhir() ?>-12-31"
                               value="<?= esc($old('effective_from', $awalPeriode)) ?>">
                        <div class="form-text">
                            Inilah yang menentukan versi mana yang berlaku pada suatu tanggal.
                            Boleh diisi tanggal <strong>masa lalu</strong> (versi historis) maupun
                            <strong>masa depan</strong>. Harus berada dalam periode
                            <?= $scope->periodeMulai() ?>&ndash;<?= $scope->periodeAkhir() ?>.
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Dasar Perubahan</label>
                            <input type="text" name="dasar_perubahan" class="form-control form-control-sm"
                                   maxlength="255" placeholder="Perbup / Perda / SK"
                                   value="<?= esc($old('dasar_perubahan')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Nomor</label>
                            <input type="text" name="nomor_dasar" class="form-control form-control-sm"
                                   maxlength="150" value="<?= esc($old('nomor_dasar')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Tanggal</label>
                            <input type="date" name="tanggal_dasar" class="form-control form-control-sm"
                                   value="<?= esc($old('tanggal_dasar')) ?>">
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label small fw-semibold">Alasan Perubahan</label>
                        <textarea name="alasan_perubahan" rows="3" class="form-control form-control-sm"
                                  placeholder="Mengapa versi ini dibuat"><?= esc($old('alasan_perubahan')) ?></textarea>
                        <div class="form-text">Ikut tersimpan pada jejak audit versi.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Seberapa Besar Perubahannya?</div>
                <div class="card-body">
                    <?php /*
                        Pertanyaannya sengaja tentang BESAR PERUBAHAN, bukan tentang
                        "sumber salinan". Penyusun tahu betul seberapa banyak yang akan
                        berubah; ia tidak seharusnya diminta menerjemahkan sendiri hal itu
                        menjadi pilihan teknis. Medan yang terkirim tetap satu — `sumber` —
                        sehingga pengelompokan ini murni soal cara bertanya.
                    */ ?>
                    <div class="small text-secondary mb-3">
                        Jawabannya menentukan versi baru ini mulai dari mana. Apa pun pilihannya,
                        versi lama tetap utuh dan bisa dilihat kapan saja.
                    </div>

                    <div class="border rounded p-3 mb-3">
                        <div class="fw-semibold mb-1">Hanya sedikit yang berubah</div>
                        <div class="text-secondary sel-kecil mb-3">
                            Sebagian besar isinya tetap. Versi baru dimulai dari salinan lengkap,
                            lalu Anda tinggal menyunting yang perlu saja.
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="radio" name="sumber" value="live"
                                   id="sumberLive" <?= $old('sumber', 'live') === 'live' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="sumberLive">
                                <span class="fw-semibold">Salin dari kondisi berjalan</span>
                                <div class="text-secondary sel-kecil">
                                    Menyalin <?= esc($namaDokumen) ?> sebagaimana adanya sekarang.
                                    Ini pilihan yang lazim.
                                </div>
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="sumber" value="copy"
                                   id="sumberCopy" <?= $old('sumber') === 'copy' ? 'checked' : '' ?>
                                   <?= empty($sumberSalin) ? 'disabled' : '' ?>>
                            <label class="form-check-label" for="sumberCopy">
                                <span class="fw-semibold">Salin dari versi tertentu</span>
                                <div class="text-secondary sel-kecil">
                                    Isinya disalin dengan identitas baru, sehingga menyunting versi ini
                                    tidak pernah mengubah versi asalnya.
                                </div>
                            </label>
                        </div>

                        <div class="ms-4 mt-2">
                            <select name="copied_from_version_id" class="form-select form-select-sm"
                                    <?= empty($sumberSalin) ? 'disabled' : '' ?>>
                                <?php if (empty($sumberSalin)): ?>
                                    <option value="">Belum ada versi yang ditetapkan</option>
                                <?php else: ?>
                                    <?php foreach ($sumberSalin as $s): ?>
                                        <option value="<?= (int) $s['id'] ?>"
                                            <?= (int) $old('copied_from_version_id') === (int) $s['id'] ? 'selected' : '' ?>>
                                            V<?= (int) $s['version_no'] ?> — <?= esc($s['label']) ?>
                                            (berlaku <?= esc($s['effective_from']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <?php if (empty($sumberSalin)): ?>
                                <div class="form-text">
                                    Hanya versi yang <strong>sudah ditetapkan</strong> yang boleh disalin —
                                    draft belum resmi, jadi tidak boleh menjadi titik awal dokumen berikutnya.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="border rounded p-3">
                        <div class="fw-semibold mb-1">Perubahannya cukup besar</div>
                        <div class="text-secondary sel-kecil mb-3">
                            Susunannya dirombak, sehingga menyalin isi lama justru menyulitkan.
                            Versi baru dimulai kosong dan disusun dari awal.
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="sumber" value="kosong"
                                   id="sumberKosong" <?= $old('sumber') === 'kosong' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="sumberKosong">
                                <span class="fw-semibold">Mulai dari kosong</span>
                                <div class="text-secondary sel-kecil">
                                    Perlu diketahui: bila versi ini kelak ditetapkan sementara isinya masih
                                    kosong, <strong>seluruh</strong> isi <?= esc($namaDokumen) ?> periode ini
                                    akan dipensiunkan. Jadi isi dulu, baru ajukan.
                                </div>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-4">
        <a href="<?= base_url($baseUrl . '/versi') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-arrow-left me-1"></i>Batal
        </a>
        <button type="submit" class="btn btn-success btn-sm">
            <i class="fa-solid fa-floppy-disk me-1"></i>Buat Versi (Draft)
        </button>
    </div>
</form>

<?= $this->include('templates/shell_bawah') ?>
