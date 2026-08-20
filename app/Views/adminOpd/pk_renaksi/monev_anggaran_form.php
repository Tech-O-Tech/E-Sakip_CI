<?php

/**
 * Input REALISASI ANGGARAN per triwulan (MONEV), SATU BARIS PER UNIT ANGGARAN.
 *
 * "Unit" = satuan anggaran yang tingkatnya mengikuti jenis PK
 * (Program / Kegiatan / Sub Kegiatan — lihat app/Helpers/pk_unit_helper.php).
 * Pagunya read-only karena ikut Perjanjian Kinerja; yang diinput hanya realisasinya.
 *
 * Bentuk POST yang dibaca controller (monevAnggaranSave):
 *   realisasi[<ref_key>][1..4]
 *   unit[<ref_key>][level] & unit[<ref_key>][ref_id]  (hanya dicocokkan)
 *
 * @var array      $detail          baris target_rencana + konteks PK (memuat pk_jenis)
 * @var array      $units           daftar unit anggaran indikator ini
 * @var array      $anggaranUnit    [ref_key => baris monev_anggaran]
 * @var array|null $anggaranWarisan baris lama (ref_key ':0') yang belum dirinci per unit
 * @var string     $labelUnitHeader label kolom unit dari controller
 */
$isBupati  = ($jenis === 'bupati');
$judul     = 'Input Realisasi Anggaran';
$monevPath = ($jenis === 'bupati') ? 'adminkab/monev'
           : (($base === 'adminopd') ? 'adminopd/monev' : ($base . '/monev_pk/' . $jenis));
$baseUrl   = base_url($monevPath);

// Kunci baru; kunci lama ($programPk/$anggaran) tetap dipakai sebagai cadangan
// supaya view ini tidak pecah kalau dipanggil dari jalur yang belum diperbarui.
$units           = $units ?? ($programPk ?? []);
$anggaranUnit    = $anggaranUnit ?? [];
$anggaranWarisan = $anggaranWarisan ?? ($anggaran ?? null);

// Eselon di halaman ini sudah pasti satu, jadi labelnya tunggal (tanpa badge).
$labelUnit = $labelUnitHeader
    ?? (function_exists('pk_unit_label') ? pk_unit_label($detail['pk_jenis'] ?? null) : 'Program');

// format_helper tidak ikut autoload — pakai pembungkus bercadangan.
$rupiah = function ($nilai) {
    if (function_exists('formatRupiah')) {
        return formatRupiah($nilai);
    }
    return 'Rp ' . number_format((float) $nilai, 0, ',', '.');
};

$totalPagu = 0.0;
foreach ($units as $u) {
    $totalPagu += (float) ($u['anggaran'] ?? 0);
}

// old() dikembalikan mentah; peng-escape-an dilakukan sendiri saat dicetak.
$oldRealisasi = old('realisasi', [], false);
$oldRealisasi = is_array($oldRealisasi) ? $oldRealisasi : [];

/** Nilai prefill satu sel: old() dulu (kalau validasi gagal), lalu data tersimpan. */
$val = function (string $refKey, int $q) use ($anggaranUnit, $oldRealisasi) {
    if (isset($oldRealisasi[$refKey][$q])) {
        return (string) $oldRealisasi[$refKey][$q];
    }
    // DECIMAL dari DB keluar sebagai "1500000" — tampilkan apa adanya agar mudah disunting.
    $simpan = $anggaranUnit[$refKey]['realisasi_triwulan_' . $q] ?? '';
    return $simpan === null ? '' : (string) $simpan;
};

// Total realisasi warisan hanya untuk ditampilkan (tidak ikut ter-submit).
$totalWarisan = 0.0;
if ($anggaranWarisan) {
    foreach ([1, 2, 3, 4] as $q) {
        $totalWarisan += (float) ($anggaranWarisan['realisasi_triwulan_' . $q] ?? 0);
    }
}

$triwulan = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV'];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= esc($judul) ?> - <?= esc(setting('app_name', 'e-SAKIP')) ?></title>
    <?= $this->include('adminOpd/templates/style.php'); ?>
</head>

<body class="bg-light min-vh-100 d-flex flex-column position-relative">
    <div id="main-content" class="content-wrapper d-flex flex-column" style="transition: margin-left .3s ease;">
        <?= $this->include($isBupati ? 'adminKabupaten/templates/header.php' : 'adminOpd/templates/header.php'); ?>
        <?= $this->include($isBupati ? 'adminKabupaten/templates/sidebar.php' : 'adminOpd/templates/sidebar.php'); ?>

        <main class="flex-fill d-flex justify-content-center p-4 mt-4">
            <div class="bg-white rounded shadow-sm p-4" style="width:100%; max-width:1100px;">
                <h2 class="h3 fw-bold text-center mb-4" style="color:#00743e;"><?= esc($judul) ?></h2>

                <?php if (session()->getFlashdata('error')): ?>
                    <div class="alert alert-danger mb-3"><?= session()->getFlashdata('error') ?></div>
                <?php endif; ?>

                <div class="row mb-3">
                    <div class="col-md-12">
                        <label class="form-label">Indikator PK</label>
                        <input type="text" class="form-control bg-light" value="<?= esc($detail['indikator_sasaran'] ?? '-') ?>" readonly>
                    </div>
                </div>

                <form action="<?= $baseUrl . '/anggaran/save' ?>" method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="target_rencana_id" value="<?= (int) ($detail['id'] ?? 0) ?>">

                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            Realisasi Anggaran per <?= esc($labelUnit) ?> dan Triwulan (Rp)
                            <span class="text-muted fw-normal">— pagu dari Perjanjian Kinerja</span>
                        </label>

                        <?php if (empty($units)): ?>
                            <div class="alert alert-light border mb-0 py-2 px-3 text-muted small">
                                Indikator PK ini belum ditautkan ke <?= esc(strtolower($labelUnit)) ?> mana pun,
                                jadi realisasi anggaran belum bisa diinput.
                                Atur dulu lewat menu Program Perjanjian Kinerja.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered align-middle mb-1" id="tabel-anggaran">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:90px;">Kode</th>
                                            <th><?= esc($labelUnit) ?></th>
                                            <th class="text-end" style="width:180px;">Pagu</th>
                                            <?php foreach ($triwulan as $q => $label): ?>
                                                <th class="text-end" style="width:140px;">TW <?= $label ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($anggaranWarisan): ?>
                                            <?php // Baris WARISAN: realisasi lama yang belum dirinci per unit.
                                                  // Sengaja terkunci & tidak ikut ter-submit (input disabled). ?>
                                            <tr class="table-light text-muted">
                                                <td class="text-nowrap"><em>&mdash;</em></td>
                                                <td class="text-start">
                                                    <em>Realisasi lama, belum dirinci per <?= esc(strtolower($labelUnit)) ?></em>
                                                    <div class="small">
                                                        <i class="fas fa-lock me-1"></i>
                                                        Terkunci &mdash; angka historis tidak diubah dari sini.
                                                    </div>
                                                </td>
                                                <td class="text-end text-nowrap"><em>&mdash;</em></td>
                                                <?php foreach ($triwulan as $q => $label): ?>
                                                    <td class="text-end">
                                                        <input type="text" class="form-control form-control-sm text-end bg-light"
                                                               value="<?= esc((string) ($anggaranWarisan['realisasi_triwulan_' . $q] ?? '')) ?>"
                                                               disabled readonly>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endif; ?>

                                        <?php foreach ($units as $unit): ?>
                                            <?php
                                            $refKey = (string) ($unit['ref_key'] ?? '');
                                            $pagu   = (float) ($unit['anggaran'] ?? 0);
                                            ?>
                                            <tr class="unit-row" data-pagu="<?= esc((string) $pagu) ?>">
                                                <td class="text-nowrap">
                                                    <?= esc($unit['kode'] ?? '-') ?>
                                                    <input type="hidden" name="unit[<?= esc($refKey) ?>][level]"
                                                           value="<?= esc((string) ($unit['level'] ?? '')) ?>">
                                                    <input type="hidden" name="unit[<?= esc($refKey) ?>][ref_id]"
                                                           value="<?= (int) ($unit['ref_id'] ?? 0) ?>">
                                                </td>
                                                <td class="text-start">
                                                    <?= esc($unit['nama'] ?? ($unit['program'] ?? '-')) ?>
                                                    <?php if (!empty($unit['fallback'])): ?>
                                                        <?php // Tingkat aslinya kosong sehingga turun tingkat — dijujurkan
                                                              // supaya penginput tahu angkanya menempel di tingkat mana. ?>
                                                        <div class="small text-muted">
                                                            <i class="fas fa-level-down-alt me-1"></i>
                                                            tingkat <?= esc(strtolower((string) ($unit['level_label'] ?? ''))) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end text-nowrap">
                                                    <?= esc($rupiah($pagu)) ?>
                                                    <div class="small text-muted serapan-unit">&mdash;</div>
                                                </td>
                                                <?php foreach ($triwulan as $q => $label): ?>
                                                    <td class="text-end">
                                                        <input type="text"
                                                               name="realisasi[<?= esc($refKey) ?>][<?= $q ?>]"
                                                               class="form-control form-control-sm text-end realisasi-input"
                                                               data-q="<?= $q ?>" inputmode="numeric" placeholder="0"
                                                               title="Realisasi Triwulan <?= $label ?>"
                                                               value="<?= esc($val($refKey, $q)) ?>">
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="fw-semibold">
                                            <td colspan="2" class="text-end">Total</td>
                                            <td class="text-end text-nowrap"><?= esc($rupiah($totalPagu)) ?></td>
                                            <?php foreach ($triwulan as $q => $label): ?>
                                                <td class="text-end text-nowrap" id="total-tw-<?= $q ?>">Rp 0</td>
                                            <?php endforeach; ?>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <small class="text-muted">
                                Boleh diketik dengan pemisah ribuan (mis. <code>1.500.000</code>) — akan dinormalkan otomatis.
                                Dikosongkan berarti belum diisi.
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="alert alert-light border">
                        <div class="d-flex justify-content-between">
                            <span class="fw-semibold">Total Realisasi</span>
                            <span class="fw-bold" id="total-realisasi">Rp 0</span>
                        </div>
                        <div class="d-flex justify-content-between text-muted small mt-1">
                            <span>Sisa terhadap total pagu</span>
                            <span id="sisa-pagu">&mdash;</span>
                        </div>
                        <?php if ($anggaranWarisan): ?>
                            <div class="d-flex justify-content-between text-muted small mt-1">
                                <span>Realisasi lama (belum dirinci, terkunci)</span>
                                <span><?= esc($rupiah($totalWarisan)) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="<?= $baseUrl ?>" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
                        <?php if (!empty($units)): ?>
                            <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i> Simpan</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </main>

        <?= $this->include('adminOpd/templates/footer.php'); ?>
    </div>

    <script>
        (function () {
            var totalPagu = <?= json_encode((float) $totalPagu) ?>;
            var rows = document.querySelectorAll('#tabel-anggaran .unit-row');
            var totalEl = document.getElementById('total-realisasi');
            var sisaEl = document.getElementById('sisa-pagu');
            if (!rows.length) return;

            function keAngka(teks) {
                teks = String(teks || '').replace(/[Rr]p|\s|\./g, '').replace(',', '.');
                var n = parseFloat(teks);
                return isNaN(n) ? 0 : n;
            }

            function format(n) {
                var negatif = n < 0;
                var teks = Math.round(Math.abs(n)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                return (negatif ? '-Rp ' : 'Rp ') + teks;
            }

            function hitung() {
                var total = 0;
                var totalTw = { 1: 0, 2: 0, 3: 0, 4: 0 };

                Array.prototype.forEach.call(rows, function (row) {
                    // Serapan dihitung PER UNIT: pagu unit ini lawan realisasinya sendiri.
                    var pagu = parseFloat(row.getAttribute('data-pagu')) || 0;
                    var subtotal = 0;

                    Array.prototype.forEach.call(row.querySelectorAll('.realisasi-input'), function (inp) {
                        var nilai = keAngka(inp.value);
                        subtotal += nilai;
                        var q = inp.getAttribute('data-q');
                        if (totalTw[q] !== undefined) totalTw[q] += nilai;
                    });

                    total += subtotal;

                    var info = row.querySelector('.serapan-unit');
                    if (info) {
                        if (subtotal === 0) {
                            info.textContent = '—';
                            info.className = 'small text-muted serapan-unit';
                        } else if (pagu > 0) {
                            var persen = (subtotal / pagu * 100).toFixed(1);
                            info.textContent = format(subtotal) + ' (' + persen + '%)';
                            info.className = subtotal > pagu
                                ? 'small text-danger fw-semibold serapan-unit'
                                : 'small text-muted serapan-unit';
                        } else {
                            // Pagu 0/kosong: persentase tidak bermakna, tampilkan nilainya saja.
                            info.textContent = format(subtotal);
                            info.className = 'small text-muted serapan-unit';
                        }
                    }
                });

                [1, 2, 3, 4].forEach(function (q) {
                    var el = document.getElementById('total-tw-' + q);
                    if (el) el.textContent = format(totalTw[q]);
                });

                if (totalEl) totalEl.textContent = format(total);

                if (sisaEl) {
                    if (totalPagu > 0) {
                        var sisa = totalPagu - total;
                        sisaEl.textContent = format(sisa) + ' (' + (total / totalPagu * 100).toFixed(1) + '% terserap)';
                        sisaEl.className = sisa < 0 ? 'text-danger fw-semibold' : '';
                    } else {
                        sisaEl.textContent = '—';
                    }
                }
            }

            Array.prototype.forEach.call(document.querySelectorAll('#tabel-anggaran .realisasi-input'), function (i) {
                i.addEventListener('input', hitung);
            });
            hitung();
        })();
    </script>
</body>

</html>
