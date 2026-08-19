<?php
$title        = $title ?? 'Isi Revisi IKU';
$judulHalaman = $revisi['nama'];

/** @var array $isi */
/** @var array $years */
$tanda = static function (string $jenis): array {
    return match ($jenis) {
        'revisi'     => ['bg-info text-dark',    'Direvisi'],
        'pengganti'  => ['bg-primary',           'Pengganti'],
        'baru'       => ['bg-success',           'Baru'],
        'dihentikan' => ['bg-dark',              'Dihentikan'],
        default      => ['bg-light text-dark',   'Tetap'],
    };
};
?>
<?= $this->include('templates/shell_atas') ?>

<div class="kotak-jejak beku mb-4">
    <div class="row g-3 small">
        <div class="col-md-3">
            <div class="text-secondary">Status</div>
            <div class="fw-semibold"><?= esc(ucfirst($revisi['status'])) ?></div>
        </div>
        <div class="col-md-3">
            <div class="text-secondary">Masa berlaku</div>
            <div class="fw-semibold">
                <?php if (in_array($revisi['status'], ['draft', 'batal'], true)): ?>
                    Diusulkan mulai <?= (int) $revisi['berlaku_mulai_tahun'] ?>
                <?php else: ?>
                    <?= (int) $revisi['berlaku_mulai_tahun'] ?> &ndash;
                    <?= $revisi['berlaku_sampai_tahun'] !== null ? (int) $revisi['berlaku_sampai_tahun'] : 'sekarang' ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-3">
            <div class="text-secondary">Dasar</div>
            <div class="fw-semibold">
                <?= esc($revisi['dasar_hukum'] ?: '—') ?>
                <?= ! empty($revisi['nomor_dasar']) ? ' No. ' . esc($revisi['nomor_dasar']) : '' ?>
            </div>
        </div>
        <div class="col-md-3">
            <div class="text-secondary">Dibekukan</div>
            <div class="fw-semibold"><?= esc($revisi['dibekukan_pada'] ?: '—') ?></div>
        </div>
        <?php if (! empty($revisi['catatan'])): ?>
            <div class="col-12">
                <div class="text-secondary">Catatan</div>
                <div><?= esc($revisi['catatan']) ?></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (in_array($revisi['status'], ['berlaku', 'superseded'], true)): ?>
    <div class="alert alert-light border small">
        Ini <strong>arsip beku</strong>. Isinya adalah IKU sebagaimana berlaku pada masanya dan
        sengaja tidak bisa diubah — LAKIP tahun-tahun tersebut membacanya dari sini.
    </div>
<?php endif; ?>

<a href="<?= base_url($baseUrl . '/revisi') ?>" class="btn btn-outline-secondary btn-sm mb-3">
    <i class="fa-solid fa-arrow-left me-1"></i>Kembali ke Daftar Revisi
</a>

<?php if (empty($isi)): ?>
    <div class="alert alert-light border text-center mb-0">Revisi ini belum berisi sasaran/indikator.</div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle small revisi-tabel" data-no-paginate>
            <thead class="table-success">
                <tr>
                    <th rowspan="2" style="width:40px">No</th>
                    <th rowspan="2">Sasaran</th>
                    <th rowspan="2">Indikator Kinerja Utama</th>
                    <th rowspan="2" style="width:90px">Satuan</th>
                    <th colspan="<?= max(1, count($years)) ?>">Target per Tahun</th>
                    <th rowspan="2" style="width:120px">Perubahan</th>
                </tr>
                <tr>
                    <?php foreach ($years as $th): ?>
                        <th style="width:70px"><?= $th ?></th>
                    <?php endforeach; ?>
                    <?php if (empty($years)): ?><th>-</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php $no = 0; ?>
                <?php foreach ($isi as $sas): ?>
                    <?php $baris = ! empty($sas['indikator']) ? $sas['indikator'] : [null]; ?>
                    <?php foreach ($baris as $k => $ind): ?>
                        <tr class="<?= ($ind && $ind['jenis_perubahan'] === 'dihentikan') ? 'table-secondary' : '' ?>">
                            <?php if ($k === 0): ?>
                                <td class="text-center" rowspan="<?= count($baris) ?>"><?= ++$no ?></td>
                                <td rowspan="<?= count($baris) ?>"><?= esc($sas['sasaran']) ?></td>
                            <?php endif; ?>

                            <?php if (! $ind): ?>
                                <td colspan="<?= 3 + max(1, count($years)) ?>" class="text-center text-secondary">
                                    Belum ada indikator pada sasaran ini.
                                </td>
                            <?php else: ?>
                                <td class="<?= $ind['jenis_perubahan'] === 'dihentikan' ? 'garis-putus' : '' ?>">
                                    <?= esc($ind['indikator']) ?>
                                    <?php if (! empty($ind['perubahan_substansial'])): ?>
                                        <span class="badge bg-danger ms-1" title="Definisi/metodologi berubah — angka tahun ini tidak sebanding dengan tahun lalu">
                                            tren terputus
                                        </span>
                                    <?php endif; ?>
                                    <?php if (! empty($ind['catatan_perubahan'])): ?>
                                        <div class="sel-kecil text-secondary"><?= esc($ind['catatan_perubahan']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= esc($ind['satuan_nama'] ?: ($ind['satuan'] ?: '-')) ?></td>
                                <?php foreach ($years as $th): ?>
                                    <td class="text-center"><?= esc($ind['target'][$th]['target'] ?? '-') ?></td>
                                <?php endforeach; ?>
                                <?php if (empty($years)): ?><td class="text-center">-</td><?php endif; ?>
                                <td class="text-center">
                                    <?php [$kelas, $label] = $tanda((string) $ind['jenis_perubahan']); ?>
                                    <span class="badge <?= $kelas ?>"><?= $label ?></span>
                                    <?php if (! empty($ind['indikator_sebelumnya_id'])): ?>
                                        <div class="sel-kecil text-secondary mt-1">
                                            menggantikan #<?= (int) $ind['indikator_sebelumnya_id'] ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="text-secondary sel-kecil mt-2">
        Baris berlatar abu-abu bertanda <strong>Dihentikan</strong> adalah catatan bahwa indikator itu
        berhenti dipakai mulai revisi ini. Baris tersebut <em>tidak</em> ikut tercetak di LAKIP, tetapi
        sengaja disimpan agar terbaca apa yang berubah.
    </div>
<?php endif; ?>

<?= $this->include('templates/shell_bawah') ?>
