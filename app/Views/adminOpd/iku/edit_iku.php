<?php

/**
 * Sunting KETERANGAN indikator IKU.
 *
 * =====================================================================
 * MENGAPA HANYA EMPAT MEDAN
 *
 * Sasaran, indikator, satuan, dan target datang dari Renstra lewat sync, dan
 * perubahannya berjalan lewat REVISI supaya tercatat dan disahkan. Membiarkan
 * keempatnya bisa disunting di sini berarti menyediakan jalan pintas yang
 * melewati seluruh alur itu — tanpa jejak, tanpa persetujuan.
 *
 * Yang tersisa di halaman ini adalah keterangan yang memang harus diisi
 * sendiri OPD dan tidak pernah ada di Renstra: definisi operasional, formula,
 * sumber data, dan penanggung jawab.
 *
 * Pembatasannya BUKAN hanya di layar. `IkuController::update()` memanggil
 * `perbaruiKeterangan()` yang menyentuh empat kolom itu saja, sehingga POST
 * yang dikarang pun tidak punya jalan mengubah target atau satuan.
 */
$title = $title ?? 'Sunting Keterangan IKU';
$daftarIndikator = $iku['indikator'] ?? [];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= esc($title ?? 'Keterangan IKU') ?></title>
    <?= $this->include('adminOpd/templates/style.php'); ?>
</head>

<body class="bg-light min-vh-100 d-flex flex-column position-relative">
    <div id="main-content" class="content-wrapper d-flex flex-column" style="transition: margin-left .3s ease;">

        <?= $this->include('adminOpd/templates/header.php'); ?>
        <?= $this->include('adminOpd/templates/sidebar.php'); ?>

        <main class="flex-fill p-4 mt-2">
            <div class="bg-white rounded shadow-sm p-4">
                <h2 class="h4 fw-bold text-success mb-1">Keterangan Indikator Kinerja Utama</h2>
                <p class="text-muted small mb-4">
                    Sasaran: <strong><?= esc($iku['sasaran'] ?? '-') ?></strong>
                    &middot; Periode <?= (int) ($iku['tahun_mulai'] ?? 0) ?>&ndash;<?= (int) ($iku['tahun_akhir'] ?? 0) ?>
                </p>

                <?php if (session()->getFlashdata('error')): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?= esc(session()->getFlashdata('error')) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="alert alert-light border small">
                    <i class="fas fa-circle-info me-1"></i>
                    Di sini Anda hanya mengisi <strong>keterangan</strong> tiap indikator. Rumusan sasaran,
                    nama indikator, satuan, dan target berasal dari Renstra &mdash; mengubahnya dilakukan
                    lewat <strong>Versi IKU</strong> supaya tercatat dan disahkan.
                </div>

                <?php if (empty($daftarIndikator)): ?>
                    <div class="alert alert-warning">Sasaran ini belum punya indikator.</div>
                <?php else: ?>
                    <form method="post" action="<?= base_url('adminopd/iku/update') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="iku_sasaran_id" value="<?= (int) $iku['id'] ?>">

                        <?php foreach ($daftarIndikator as $n => $ind): ?>
                            <?php $id = (int) $ind['id']; ?>
                            <div class="border rounded p-3 mb-3 bg-light">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                                    <div>
                                        <div class="text-muted small">Indikator <?= $n + 1 ?></div>
                                        <div class="fw-semibold"><?= esc($ind['indikator']) ?></div>
                                    </div>
                                    <div class="text-muted small text-end">
                                        Satuan: <strong><?= esc($ind['satuan_nama'] ?? $ind['satuan'] ?? '-') ?></strong>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Definisi Operasional</label>
                                        <textarea name="keterangan[<?= $id ?>][definisi]" class="form-control" rows="3"
                                                  placeholder="Penjelasan makna indikator"><?= esc($ind['definisi'] ?? '') ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Formula / Rumusan Perhitungan</label>
                                        <textarea name="keterangan[<?= $id ?>][rumusan_perhitungan]" class="form-control" rows="3"
                                                  placeholder="Cara menghitung capaian indikator"><?= esc($ind['rumusan_perhitungan'] ?? '') ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Sumber Data</label>
                                        <textarea name="keterangan[<?= $id ?>][sumber_data]" class="form-control" rows="2"
                                                  placeholder="Contoh: Laporan rutin bidang"><?= esc($ind['sumber_data'] ?? '') ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Penanggung Jawab</label>
                                        <input type="text" name="keterangan[<?= $id ?>][penanggung_jawab]"
                                               class="form-control" maxlength="255"
                                               value="<?= esc($ind['penanggung_jawab'] ?? '') ?>"
                                               placeholder="Contoh: Bidang Kesmas">
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div class="d-flex gap-2">
                            <button class="btn btn-success">
                                <i class="fas fa-floppy-disk me-1"></i> Simpan Keterangan
                            </button>
                            <a href="<?= base_url('adminopd/iku') ?>" class="btn btn-outline-secondary">Batal</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </main>

        <?= $this->include('adminOpd/templates/footer.php'); ?>
    </div>
</body>

</html>
