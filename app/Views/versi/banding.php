<?php

/**
 * Bandingkan dua versi (§23).
 *
 * §23 sengaja tidak menuntut tampilan sekelas Git — yang diminta empat tanda
 * dan keterbacaan. Baris yang berubah dan yang hilang ditaruh di atas, karena
 * itulah yang perlu dibaca; yang tidak berubah tetap ditampilkan supaya
 * jumlahnya bisa dicocokkan, tapi diredupkan.
 *
 * @var array $hasil  ['a','b','baris','ringkasan']
 */
$title        = $title ?? 'Bandingkan Versi';
$judulHalaman = $judulHalaman ?? ('Bandingkan Versi ' . $namaDokumen);

$a = $hasil['a'];
$b = $hasil['b'];
$r = $hasil['ringkasan'];

$gaya = static function (string $tanda): array {
    return match ($tanda) {
        '+' => ['table-success', 'bg-success',        'Ditambah'],
        '~' => ['table-warning', 'bg-warning text-dark', 'Diubah'],
        '-' => ['table-danger',  'bg-danger',         'Tidak ada lagi'],
        default => ['',          'bg-light text-dark', 'Tidak berubah'],
    };
};
?>
<?= $this->include('templates/shell_atas') ?>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="kotak-jejak h-100">
            <div class="text-secondary sel-kecil">Versi Pembanding (lama)</div>
            <div class="fw-semibold">V<?= (int) $a['version_no'] ?> — <?= esc($a['label']) ?></div>
            <div class="sel-kecil text-secondary">Berlaku sejak <?= esc($a['effective_from']) ?></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="kotak-jejak beku h-100">
            <div class="text-secondary sel-kecil">Versi Ditinjau (baru)</div>
            <div class="fw-semibold">V<?= (int) $b['version_no'] ?> — <?= esc($b['label']) ?></div>
            <div class="sel-kecil text-secondary">Berlaku sejak <?= esc($b['effective_from']) ?></div>
        </div>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-3 small">
        <span><span class="badge bg-success">+</span> Ditambah: <strong><?= (int) $r['tambah'] ?></strong></span>
        <span><span class="badge bg-warning text-dark">~</span> Diubah: <strong><?= (int) $r['ubah'] ?></strong></span>
        <span><span class="badge bg-danger">&minus;</span> Tidak ada lagi: <strong><?= (int) $r['hapus'] ?></strong></span>
        <span><span class="badge bg-light text-dark border">=</span> Tetap: <strong><?= (int) $r['tetap'] ?></strong></span>
    </div>
    <a href="<?= base_url($baseUrl . '/versi/lihat/' . (int) $b['id']) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fa-solid fa-arrow-left me-1"></i>Kembali ke Versi
    </a>
</div>

<div class="kotak-jejak mb-3">
    <div class="small text-secondary">
        Pencocokan baris memakai <strong>silsilah</strong> — dari versi mana sebuah baris disalin, dan
        baris data berjalan mana yang dirujuknya — <em>bukan</em> kemiripan nama. Dua indikator bisa
        bernama sama persis tanpa berhubungan, dan satu indikator bisa berganti nama tanpa berganti makna.
    </div>
</div>

<?php if (empty($hasil['baris'])): ?>
    <div class="alert alert-light border text-center mb-0">Kedua versi tidak berisi indikator apa pun.</div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-bordered align-middle small revisi-tabel" data-no-paginate>
            <thead class="table-success">
                <tr>
                    <th style="width:44px">&nbsp;</th>
                    <th>Sasaran</th>
                    <th>Indikator</th>
                    <th style="width:80px">Satuan</th>
                    <th>Perubahan</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($hasil['baris'] as $row): ?>
                    <?php [$kelasBaris, $kelasBadge, $labelTanda] = $gaya($row['tanda']); ?>
                    <tr class="<?= $kelasBaris ?> <?= $row['tanda'] === '=' ? 'opacity-75' : '' ?>">
                        <td class="text-center">
                            <span class="badge <?= $kelasBadge ?>" title="<?= esc($labelTanda) ?>">
                                <?= $row['tanda'] === '-' ? '&minus;' : esc($row['tanda']) ?>
                            </span>
                        </td>
                        <td class="<?= $row['tanda'] === '-' ? 'garis-putus' : '' ?>"><?= esc($row['sasaran']) ?></td>
                        <td class="<?= $row['tanda'] === '-' ? 'garis-putus' : '' ?>">
                            <?= esc($row['indikator']) ?>
                            <?php if ((int) $row['perubahan_substansial'] === 1): ?>
                                <span class="badge bg-warning text-dark ms-1"
                                      title="Tren antar tahun tidak boleh disambung">substansial</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= esc($row['satuan']) ?></td>
                        <td class="sel-kecil">
                            <?php if ($row['tanda'] === '~' && ! empty($row['beda'])): ?>
                                <?php foreach ($row['beda'] as $kolom => $nilai): ?>
                                    <div>
                                        <span class="text-secondary"><?= esc(ucwords(str_replace('_', ' ', $kolom))) ?>:</span>
                                        <span class="garis-putus"><?= esc($nilai[0] === '' ? '—' : $nilai[0]) ?></span>
                                        <i class="fa-solid fa-arrow-right mx-1 text-secondary"></i>
                                        <strong><?= esc($nilai[1] === '' ? '—' : $nilai[1]) ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            <?php elseif ($row['tanda'] === '+'): ?>
                                <span class="text-secondary">Indikator baru pada versi ini.</span>
                            <?php elseif ($row['tanda'] === '-'): ?>
                                <span class="text-secondary">
                                    Tidak lagi tercantum. Bila versi baru ditetapkan, baris ini
                                    <strong>dipensiunkan</strong> — bukan dihapus.
                                </span>
                            <?php else: ?>
                                <span class="text-secondary">&mdash;</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?= $this->include('templates/shell_bawah') ?>
