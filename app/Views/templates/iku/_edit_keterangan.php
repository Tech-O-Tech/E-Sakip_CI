<?php
/**
 * Layar Edit IKU — bagian isi, dipakai OPD maupun Kabupaten.
 *
 * Dipisahkan supaya kedua layar memakai SATU aturan yang sama: hanya
 * keterangan indikator dan redaksi sasaran yang boleh disunting di sini.
 * Sebelumnya sisi Kabupaten memakai form penuh yang menulis ulang sasaran,
 * indikator, satuan, dan SELURUH targetnya — dan dua salinan aturan pasti
 * menyimpang cepat atau lambat.
 *
 * Butuh: $iku, $action_url, $back_url, $renameBoleh, $sasaranSumber, $labelSumber, $labelDampak
 */
$daftarIndikator = $iku['indikator'] ?? [];
$actionUrl       = $action_url ?? '';
$backUrl         = $back_url ?? '';
$labelSumber     = $labelSumber ?? 'Renstra';
$labelDampak     = $labelDampak ?? 'kolom Sasaran ESS II pada Cascading';
?>
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
                    Di sini Anda mengisi <strong>keterangan</strong> tiap indikator
                    <?= ($renameBoleh ?? false) ? 'dan boleh merapikan <strong>redaksi sasaran</strong>' : '' ?>.
                    Nama indikator, satuan, dan target berasal dari <?= esc($labelSumber) ?> &mdash; mengubahnya dilakukan
                    lewat <strong>Versi IKU</strong> supaya tercatat dan disahkan.
                </div>

                <?php if (empty($daftarIndikator)): ?>
                    <div class="alert alert-warning">Sasaran ini belum punya indikator.</div>
                <?php else: ?>
                    <form method="post" action="<?= esc($actionUrl) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="iku_sasaran_id" value="<?= (int) $iku['id'] ?>">

                        <?php /* REDAKSI SASARAN.
                                 Menyunting ini mengubah kolom Sasaran ESS II di Cascading untuk
                                 SELURUH indikator di bawahnya — perlu disebut, karena dari layar
                                 ini akibat itu tidak kelihatan. */ ?>
                        <div class="border rounded p-3 mb-4">
                            <label for="sasaran_baru" class="form-label fw-semibold mb-1">
                                Sasaran IKU
                            </label>

                            <?php if ($renameBoleh ?? false): ?>
                                <textarea class="form-control" id="sasaran_baru" name="sasaran_baru" rows="2"><?= esc($iku['sasaran'] ?? '') ?></textarea>
                                <div class="form-text">
                                    Berlaku untuk <strong>seluruh indikator</strong> di bawah sasaran ini, dan
                                    ikut mengubah <?= esc($labelDampak) ?>.
                                    <?php if (! empty($sasaranSumber)): ?>
                                        <br>Redaksi di <?= esc($labelSumber) ?>:
                                        <em><?= esc($sasaranSumber) ?></em>
                                        <?php if (trim(preg_replace('/\s+/', ' ', (string) $sasaranSumber))
                                               !== trim(preg_replace('/\s+/', ' ', (string) ($iku['sasaran'] ?? '')))): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-1">
                                                sudah berbeda dari <?= esc($labelSumber) ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <input type="text" class="form-control" value="<?= esc($iku['sasaran'] ?? '') ?>" readonly>
                                <div class="form-text">
                                    <i class="fas fa-lock me-1"></i>
                                    IKU periode ini sudah punya <strong>revisi yang berlaku</strong>, jadi
                                    redaksinya diubah lewat draft revisi &mdash; supaya perubahannya tercatat
                                    dan bisa ditelusuri, bukan berubah diam-diam pada dokumen yang sudah disahkan.
                                </div>
                            <?php endif; ?>
                        </div>

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
                            <a href="<?= esc($backUrl) ?>" class="btn btn-outline-secondary">Batal</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
