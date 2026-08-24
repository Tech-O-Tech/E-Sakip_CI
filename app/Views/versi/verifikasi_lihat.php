<?php

/**
 * Detail satu pengajuan + tombol keputusan (§17).
 *
 * §17 menuntut verifikator melihat OPD, modul, versi, tanggal berlaku,
 * asal salinan, alasan, dasar perubahan, DAN ringkasan perubahan — bukan
 * sekadar isi versinya utuh. Tanpa ringkasan itu, memutuskan berarti membaca
 * ratusan baris untuk menemukan yang berubah.
 *
 * @var array      $versi
 * @var array|null $diff        hasil VersionCompareService, bila ada pembanding
 * @var array|null $pembanding  versi yang sedang berlaku
 * @var array|null $asal        versi yang disalin, bila ada
 */
$title        = $title ?? ('Verifikasi — ' . $versi['label']);
$judulHalaman = $judulHalaman ?? 'Verifikasi Pengajuan';

$labelModul = static fn (string $m): string => match ($m) {
    'rpjmd'   => 'RPJMD',
    'renstra' => 'Renstra',
    'iku'     => 'IKU',
    'lakip'   => 'LAKIP',
    default   => strtoupper($m),
};

$gaya = static fn (string $t): array => match ($t) {
    '+' => ['table-success', 'bg-success',           'Ditambah'],
    '~' => ['table-warning', 'bg-warning text-dark', 'Diubah'],
    '-' => ['table-danger',  'bg-danger',            'Tidak ada lagi'],
    default => ['',          'bg-light text-dark',   'Tidak berubah'],
};

/** Ratakan pohon arsip — lihat catatan yang sama di versi/lihat.php. */
$tujuanSemua = [];

foreach ($isi as $akar) {
    if (is_array($akar['tujuan'] ?? null)) {
        foreach ($akar['tujuan'] as $t) {
            $tujuanSemua[] = $t;
        }
    } else {
        $tujuanSemua[] = $akar;
    }
}
?>
<?= $this->include('templates/shell_atas') ?>

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <span class="badge bg-primary badge-lifecycle me-2">MENUNGGU VERIFIKASI</span>
        <span class="text-secondary sel-kecil">
            <?= esc($labelModul($versi['modul'])) ?>
            &middot; <?= esc($namaOpd) ?>
            &middot; periode <?= (int) $versi['periode_mulai'] ?>&ndash;<?= (int) $versi['periode_akhir'] ?>
        </span>
    </div>
    <a href="<?= base_url('adminkab/verifikasi') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fa-solid fa-arrow-left me-1"></i>Antrean
    </a>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Yang Diajukan</div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-secondary fw-normal">Versi</dt>
                    <dd class="col-sm-8 fw-semibold">V<?= (int) $versi['version_no'] ?> — <?= esc($versi['label']) ?></dd>

                    <dt class="col-sm-4 text-secondary fw-normal">Mulai berlaku</dt>
                    <dd class="col-sm-8"><?= esc($rentang) ?></dd>

                    <dt class="col-sm-4 text-secondary fw-normal">Isi diambil dari</dt>
                    <dd class="col-sm-8">
                        <?php if ($asal !== null): ?>
                            Salinan V<?= (int) $asal['version_no'] ?> — <?= esc($asal['label']) ?>
                        <?php elseif ((int) $versi['mulai_dari_kosong'] === 1): ?>
                            <span class="text-danger fw-semibold">Mulai dari kosong</span>
                        <?php else: ?>
                            Salinan kondisi berjalan
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-4 text-secondary fw-normal">Dasar perubahan</dt>
                    <dd class="col-sm-8">
                        <?= ! empty($versi['dasar_perubahan']) ? esc($versi['dasar_perubahan']) : '<span class="text-secondary">—</span>' ?>
                        <?= ! empty($versi['nomor_dasar']) ? ' No. ' . esc($versi['nomor_dasar']) : '' ?>
                        <?= ! empty($versi['tanggal_dasar']) ? ' (' . esc($versi['tanggal_dasar']) . ')' : '' ?>
                    </dd>

                    <dt class="col-sm-4 text-secondary fw-normal">Alasan</dt>
                    <dd class="col-sm-8">
                        <?= ! empty($versi['alasan_perubahan']) ? nl2br(esc($versi['alasan_perubahan'])) : '<span class="text-secondary">—</span>' ?>
                    </dd>

                    <dt class="col-sm-4 text-secondary fw-normal">Diajukan</dt>
                    <dd class="col-sm-8">
                        <?= ! empty($versi['submitted_at']) ? esc(date('d M Y H:i', strtotime($versi['submitted_at']))) : '—' ?>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Ringkasan Perubahan</div>
            <div class="card-body small">
                <?php if ($diff === null): ?>
                    <div class="text-secondary">
                        <?php if ($pembanding === null): ?>
                            Belum ada versi berlaku pada periode ini, jadi tidak ada pembanding.
                            Menyetujui berarti menjadikan versi ini yang pertama berlaku.
                        <?php else: ?>
                            Ringkasan tidak tersedia — timeline periode ini sedang berkonflik.
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="text-secondary mb-2">
                        Dibandingkan dengan yang berlaku sekarang:
                        <strong>V<?= (int) $pembanding['version_no'] ?></strong>
                    </div>
                    <div class="d-flex flex-wrap gap-3">
                        <span><span class="badge bg-success">+</span> <?= (int) $diff['ringkasan']['tambah'] ?> ditambah</span>
                        <span><span class="badge bg-warning text-dark">~</span> <?= (int) $diff['ringkasan']['ubah'] ?> diubah</span>
                        <span><span class="badge bg-danger">&minus;</span> <?= (int) $diff['ringkasan']['hapus'] ?> tidak ada lagi</span>
                        <span><span class="badge bg-light text-dark border">=</span> <?= (int) $diff['ringkasan']['tetap'] ?> tetap</span>
                    </div>
                    <?php if ((int) $diff['ringkasan']['hapus'] > 0): ?>
                        <div class="kotak-jejak awas mt-3 mb-0">
                            <div class="sel-kecil">
                                <strong><?= (int) $diff['ringkasan']['hapus'] ?> indikator</strong> tidak lagi tercantum.
                                Bila disetujui, baris-baris itu <strong>dipensiunkan</strong> — datanya tetap ada
                                dan laporan tahun lampau tidak berubah, tetapi tidak lagi muncul sebagai indikator berjalan.
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">Keputusan</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-5">
                <form method="post" action="<?= base_url('adminkab/verifikasi/setujui/' . (int) $versi['id']) ?>"
                      onsubmit="return confirm('Setujui dan tetapkan versi ini berlaku? Isinya akan langsung diterapkan ke data berjalan.')">
                    <?= csrf_field() ?>
                    <button class="btn btn-success w-100">
                        <i class="fa-solid fa-check me-1"></i>Setujui &amp; Tetapkan Berlaku
                    </button>
                    <div class="form-text">
                        Versi menjadi resmi dan tidak bisa disunting lagi.
                    </div>
                </form>
            </div>

            <div class="col-md-7">
                <form method="post" action="<?= base_url('adminkab/verifikasi/kembalikan/' . (int) $versi['id']) ?>">
                    <?= csrf_field() ?>
                    <div class="input-group">
                        <input type="text" name="catatan" class="form-control form-control-sm" required
                               maxlength="1000"
                               placeholder="Catatan pengembalian (wajib) — apa yang harus diperbaiki">
                        <button class="btn btn-outline-danger btn-sm">
                            <i class="fa-solid fa-rotate-left me-1"></i>Kembalikan
                        </button>
                    </div>
                    <div class="form-text">
                        Versi kembali menjadi draft dan bisa disunting penyusunnya. Catatan ikut tersimpan pada jejak audit.
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($diff !== null && ! empty($diff['baris'])): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">Rincian Perubahan</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered align-middle small revisi-tabel" data-no-paginate>
                    <thead class="table-success">
                        <tr>
                            <th style="width:44px">&nbsp;</th>
                            <th>Sasaran</th>
                            <th>Indikator</th>
                            <th>Perubahan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($diff['baris'] as $row): ?>
                            <?php if ($row['tanda'] === '=') { continue; } ?>
                            <?php [$kelasBaris, $kelasBadge, $labelTanda] = $gaya($row['tanda']); ?>
                            <tr class="<?= $kelasBaris ?>">
                                <td class="text-center">
                                    <span class="badge <?= $kelasBadge ?>" title="<?= esc($labelTanda) ?>">
                                        <?= $row['tanda'] === '-' ? '&minus;' : esc($row['tanda']) ?>
                                    </span>
                                </td>
                                <td class="<?= $row['tanda'] === '-' ? 'garis-putus' : '' ?>"><?= esc($row['sasaran']) ?></td>
                                <td class="<?= $row['tanda'] === '-' ? 'garis-putus' : '' ?>"><?= esc($row['indikator']) ?></td>
                                <td class="sel-kecil">
                                    <?php if (! empty($row['beda'])): ?>
                                        <?php foreach ($row['beda'] as $kolom => $nilai): ?>
                                            <div>
                                                <span class="text-secondary"><?= esc(ucwords(str_replace('_', ' ', $kolom))) ?>:</span>
                                                <span class="garis-putus"><?= esc($nilai[0] === '' ? '—' : $nilai[0]) ?></span>
                                                <i class="fa-solid fa-arrow-right mx-1 text-secondary"></i>
                                                <strong><?= esc($nilai[1] === '' ? '—' : $nilai[1]) ?></strong>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php elseif ($row['tanda'] === '+'): ?>
                                        <span class="text-secondary">Indikator baru.</span>
                                    <?php else: ?>
                                        <span class="text-secondary">Tidak lagi tercantum; akan dipensiunkan.</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">
        Isi Lengkap Versi yang Diajukan
        <?php if (! empty($ringkas)): ?>
            <span class="text-secondary sel-kecil fw-normal ms-2">
                <?php foreach ($ringkas as $nama => $jml): ?>
                    <?= esc(ucwords(str_replace('_', ' ', $nama))) ?>: <?= (int) $jml ?>&nbsp;&nbsp;
                <?php endforeach; ?>
            </span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($tujuanSemua)): ?>
            <div class="alert alert-warning border mb-0 small">
                Versi ini <strong>tidak berisi apa pun</strong>. Menyetujuinya berarti seluruh isi
                dokumen periode ini akan dipensiunkan.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered align-middle small revisi-tabel" data-no-paginate>
                    <thead class="table-light">
                        <tr>
                            <th>Tujuan</th>
                            <th>Sasaran</th>
                            <th>Indikator</th>
                            <th style="width:80px">Satuan</th>
                            <th style="width:200px">Target</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tujuanSemua as $t): ?>
                            <?php
                            $namaTujuan  = $t['tujuan_rpjmd'] ?? $t['tujuan'] ?? '';
                            $sasaranList = $t['sasaran'] ?? [];

                            /* Tinggi rowspan tujuan = jumlah baris seluruh anaknya.
                               Sasaran tanpa indikator tetap dihitung SATU baris:
                               versi yang diajukan bisa saja memang belum lengkap,
                               dan verifikator justru perlu melihat kekurangan itu —
                               bukan mendapati barisnya raib dari tabel. */
                            $tinggiTujuan = 0;

                            foreach ($sasaranList as $s) {
                                $tinggiTujuan += max(1, count($s['indikator'] ?? []));
                            }

                            $tinggiTujuan  = max(1, $tinggiTujuan);
                            $tujuanDicetak = false;
                            ?>

                            <?php if (empty($sasaranList)): ?>
                                <tr>
                                    <td><?= esc($namaTujuan) ?></td>
                                    <td colspan="4" class="text-secondary fst-italic">Belum ada sasaran</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sasaranList as $s): ?>
                                    <?php
                                    $namaSasaran   = $s['sasaran_rpjmd'] ?? $s['sasaran'] ?? '';
                                    $indList       = $s['indikator'] ?? [];
                                    $tinggiSasaran = max(1, count($indList));
                                    ?>

                                    <?php if (empty($indList)): ?>
                                        <tr>
                                            <?php if (! $tujuanDicetak): ?>
                                                <td rowspan="<?= $tinggiTujuan ?>"><?= esc($namaTujuan) ?></td>
                                                <?php $tujuanDicetak = true; ?>
                                            <?php endif; ?>
                                            <td><?= esc($namaSasaran) ?></td>
                                            <td colspan="3" class="text-secondary fst-italic">Belum ada indikator</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($indList as $k => $i): ?>
                                            <tr>
                                                <?php if (! $tujuanDicetak): ?>
                                                    <td rowspan="<?= $tinggiTujuan ?>"><?= esc($namaTujuan) ?></td>
                                                    <?php $tujuanDicetak = true; ?>
                                                <?php endif; ?>

                                                <?php if ($k === 0): ?>
                                                    <td rowspan="<?= $tinggiSasaran ?>"><?= esc($namaSasaran) ?></td>
                                                <?php endif; ?>

                                                <td>
                                                    <?= esc($i['indikator_sasaran'] ?? '') ?>
                                                    <?php if (($i['jenis_perubahan'] ?? 'tetap') !== 'tetap'): ?>
                                                        <span class="badge bg-light text-dark border ms-1"><?= esc($i['jenis_perubahan']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center"><?= esc($i['satuan_nama'] ?? $i['satuan'] ?? '') ?></td>
                                                <td class="sel-kecil">
                                                    <?php foreach ($i['target'] ?? [] as $tg): ?>
                                                        <span class="d-inline-block me-2">
                                                            <?= esc($tg['tahun']) ?>: <strong><?= esc($tg['target_tahunan'] ?? $tg['target'] ?? '') ?></strong>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Jejak Audit</div>
    <div class="card-body">
        <?php if (empty($riwayat)): ?>
            <div class="text-secondary small">Belum ada jejak.</div>
        <?php else: ?>
            <table class="table table-sm align-middle small mb-0" data-no-paginate>
                <tbody>
                    <?php foreach ($riwayat as $h): ?>
                        <tr>
                            <td style="width:150px" class="sel-kecil"><?= esc(date('d M Y H:i', strtotime($h['pada']))) ?></td>
                            <td style="width:150px"><span class="badge bg-light text-dark border"><?= esc($h['aksi']) ?></span></td>
                            <td style="width:160px" class="sel-kecil"><?= esc($h['oleh_nama'] ?? '—') ?></td>
                            <td class="sel-kecil">
                                <?= esc($h['ringkasan'] ?? '') ?>
                                <?php if (! empty($h['catatan'])): ?>
                                    <div class="text-danger">Catatan: <?= esc($h['catatan']) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?= $this->include('templates/shell_bawah') ?>
