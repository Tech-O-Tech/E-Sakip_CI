<?php
$title        = $title ?? 'Bandingkan Snapshot LAKIP';
$judulHalaman = 'Bandingkan Snapshot LAKIP ' . ($snapshot['tahun'] ?? '');

/** @var array $beda ['berubah','baru','hilang','sama'] */
$jumlahBerubah = count($beda['berubah'] ?? []);
$jumlahBaru    = count($beda['baru'] ?? []);
$jumlahHilang  = count($beda['hilang'] ?? []);
$terkunci      = ($snapshot['status'] ?? '') === 'final';
?>
<?= $this->include('templates/shell_atas') ?>

<div class="kotak-jejak <?= $terkunci ? 'kunci' : 'beku' ?> mb-4">
    <div class="row g-3 small">
        <div class="col-md-3">
            <div class="text-secondary">Versi snapshot</div>
            <div class="fw-semibold"><?= (int) $snapshot['versi'] ?></div>
        </div>
        <div class="col-md-3">
            <div class="text-secondary">Status</div>
            <div class="fw-semibold">
                <?= $terkunci ? 'Final (tahun terkunci)' : 'Draft' ?>
            </div>
        </div>
        <div class="col-md-3">
            <div class="text-secondary">Dibekukan</div>
            <div class="fw-semibold"><?= esc($snapshot['dibuat_pada'] ?? '—') ?></div>
        </div>
        <div class="col-md-3">
            <div class="text-secondary">Baris beku</div>
            <div class="fw-semibold"><?= (int) $snapshot['jumlah_baris'] ?></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="border rounded p-3 text-center">
            <div class="h4 mb-0 text-warning"><?= $jumlahBerubah ?></div>
            <div class="small text-secondary">Baris berubah</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="border rounded p-3 text-center">
            <div class="h4 mb-0 text-success"><?= $jumlahBaru ?></div>
            <div class="small text-secondary">Baru di data hidup</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="border rounded p-3 text-center">
            <div class="h4 mb-0 text-danger"><?= $jumlahHilang ?></div>
            <div class="small text-secondary">Hilang dari data hidup</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="border rounded p-3 text-center">
            <div class="h4 mb-0 text-secondary"><?= (int) ($beda['sama'] ?? 0) ?></div>
            <div class="small text-secondary">Tidak berubah</div>
        </div>
    </div>
</div>

<a href="<?= esc($kembaliUrl) ?>" class="btn btn-outline-secondary btn-sm mb-3">
    <i class="fa-solid fa-arrow-left me-1"></i>Kembali ke LAKIP
</a>

<?php if ($terkunci): ?>
    <div class="alert alert-success small">
        <i class="fa-solid fa-lock me-1"></i>
        Tahun ini sudah terkunci. Perbedaan di bawah hanya <strong>informasi</strong> — snapshot final
        sengaja tidak bisa disinkronkan ulang. Bila memang perlu dikoreksi, gunakan
        <strong>Penyesuaian Kebijakan</strong> agar perubahannya tercatat lengkap dengan dasarnya.
    </div>
<?php elseif ($jumlahBerubah + $jumlahBaru + $jumlahHilang > 0): ?>
    <div class="alert alert-warning small">
        Data hidup sudah bergerak dari snapshot ini. Bila perbedaannya memang dikehendaki,
        jalankan <strong>Sinkronkan Snapshot</strong> di halaman LAKIP untuk membuat versi baru
        (versi sekarang tetap tersimpan sebagai riwayat).
    </div>
<?php else: ?>
    <div class="alert alert-light border small">Snapshot masih identik dengan data hidup.</div>
<?php endif; ?>

<?php if ($jumlahBerubah > 0): ?>
    <h6 class="fw-semibold mt-4">Baris yang berubah</h6>
    <div class="table-responsive">
        <table class="table table-bordered table-sm small align-middle" data-no-paginate>
            <thead class="table-warning">
                <tr>
                    <th style="width:34%">Indikator</th>
                    <th style="width:16%">Bagian</th>
                    <th style="width:25%">Di snapshot</th>
                    <th style="width:25%">Di data hidup</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($beda['berubah'] as $b): ?>
                    <?php $n = count($b['selisih']); $baris = 0; ?>
                    <?php foreach ($b['selisih'] as $bagian => $nilai): ?>
                        <tr>
                            <?php if ($baris === 0): ?>
                                <td rowspan="<?= $n ?>">
                                    <div class="fw-semibold"><?= esc($b['indikator']) ?></div>
                                    <div class="text-secondary sel-kecil"><?= esc($b['sasaran']) ?></div>
                                </td>
                            <?php endif; ?>
                            <td><?= esc(str_replace('_', ' ', $bagian)) ?></td>
                            <td class="garis-putus"><?= esc($nilai['snapshot'] ?? '—') ?></td>
                            <td class="fw-semibold"><?= esc($nilai['sekarang'] ?? '—') ?></td>
                        </tr>
                        <?php $baris++; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($jumlahBaru > 0): ?>
    <h6 class="fw-semibold mt-4">Ada di data hidup, belum ada di snapshot</h6>
    <ul class="small">
        <?php foreach ($beda['baru'] as $b): ?>
            <li><?= esc($b['indikator']) ?> <span class="text-secondary">— <?= esc($b['sasaran']) ?></span></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($jumlahHilang > 0): ?>
    <h6 class="fw-semibold mt-4">Ada di snapshot, sudah hilang dari data hidup</h6>
    <div class="alert alert-danger py-2 small">
        Inilah alasan snapshot menyalin teks, bukan sekadar menyimpan penunjuk id. Baris-baris di
        bawah tetap terbaca lengkap di dokumen tahun ini walaupun sumbernya sudah tidak ada lagi.
    </div>
    <ul class="small">
        <?php foreach ($beda['hilang'] as $b): ?>
            <li><?= esc($b['indikator']) ?> <span class="text-secondary">— <?= esc($b['sasaran']) ?></span></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?= $this->include('templates/shell_bawah') ?>
