<?php

/**
 * Panel Snapshot Tahunan LAKIP + Penyesuaian Kebijakan.
 *
 * Partial bersama, disisipkan di halaman index LAKIP AdminKab & AdminOpd —
 * pola yang sama dengan app/Views/lakip/addendum_layar.php yang sudah ada.
 *
 * Variabel dari LakipSnapshotTrait::dataSnapshot():
 *   $snapshotSiap $snapshotAktif $snapshotDaftar $snapshotDipakai
 *   $snapshotTerkunci $snapshotLintasOpd $snapshotPesan $snapshotBase
 *   $penyesuaianRiwayat $bolehSnapshot $bolehFinalisasi $bolehPenyesuaian
 *   $addendumScope (dari LakipAddendumTrait)
 */
$snapshotSiap       = $snapshotSiap       ?? false;
$snapshotAktif      = $snapshotAktif      ?? null;
$snapshotDaftar     = $snapshotDaftar     ?? [];
$snapshotDipakai    = $snapshotDipakai    ?? false;
$snapshotTerkunci   = $snapshotTerkunci   ?? false;
$snapshotLintasOpd  = $snapshotLintasOpd  ?? false;
$snapshotPesan      = $snapshotPesan      ?? null;
$snapshotBase       = $snapshotBase       ?? 'adminkab/lakip';
$penyesuaianRiwayat = $penyesuaianRiwayat ?? [];
$bolehSnapshot      = $bolehSnapshot      ?? false;
$bolehFinalisasi    = $bolehFinalisasi    ?? false;
$bolehPenyesuaian   = $bolehPenyesuaian   ?? false;
// Hanya layar LAKIP OPD yang punya pemilih sumber; AdminKab tidak
// mengirim variabel ini sama sekali.
$sumberLakip        = $sumberLakip        ?? [];
$scope              = $addendumScope      ?? ['tahun' => '', 'mode' => 'opd', 'opdScope' => null, 'canWrite' => false];

if (! $snapshotSiap) {
    return; // SQL 2026-08-18 belum dijalankan di server ini — panel disembunyikan
}

$tahun = (string) ($scope['tahun'] ?? '');
$mode  = (string) ($scope['mode'] ?? 'opd');
$opd   = (int) ($scope['opdScope'] ?? 0);

/* Sumber dokumen yang sedang tampil (IKU/Renstra + versinya). Ikut dikirim
   pada SETIAP aksi snapshot: yang dibekukan harus dokumen yang dilihat
   operator, bukan sumber bawaan. Layar AdminKab tidak punya pemilih ini,
   sehingga di sana keduanya kosong dan tidak ada yang berubah. */
$sumberTampil     = $sumberLakip['sumber'] ?? '';
$sumberVersiTampil = (int) ($sumberLakip['versi']['id'] ?? 0);

/** Bidang tersembunyi lingkup, diverifikasi ulang di server. */
$bidangLingkup = '<input type="hidden" name="tahun" value="' . esc($tahun, 'attr') . '">'
    . '<input type="hidden" name="mode" value="' . esc($mode, 'attr') . '">'
    . ($mode === 'opd' && $opd > 0 ? '<input type="hidden" name="opd_id" value="' . $opd . '">' : '')
    . ($sumberTampil !== '' ? '<input type="hidden" name="sumber" value="' . esc($sumberTampil, 'attr') . '">' : '')
    . ($sumberVersiTampil > 0 ? '<input type="hidden" name="sumber_versi" value="' . $sumberVersiTampil . '">' : '');

/** Potongan query string sumber, untuk tautan (GET) di panel ini. */
$qsSumber = ($sumberTampil !== '' ? '&sumber=' . rawurlencode($sumberTampil) : '')
    . ($sumberVersiTampil > 0 ? '&sumber_versi=' . $sumberVersiTampil : '');

/** Label sumber yang DIBEKUKAN snapshot aktif — belum tentu sama dengan yang tampil. */
$labelSumberBeku = null;

if ($snapshotAktif) {
    $petaSumber = ['iku' => 'IKU', 'renstra' => 'Renstra', 'rpjmd' => 'RPJMD'];
    $tipeBeku   = (string) ($snapshotAktif['source_type'] ?? '');

    if (isset($petaSumber[$tipeBeku])) {
        $labelSumberBeku = $petaSumber[$tipeBeku];

        if (! empty($snapshotAktif['source_version_id'])) {
            $labelSumberBeku .= ' (versi #' . (int) $snapshotAktif['source_version_id'] . ')';
        }
    }
}
?>

<div class="card mt-4 mb-3 border-primary-subtle">
    <div class="card-header bg-primary-subtle d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold">
            <i class="fa-solid fa-box-archive me-1"></i>Snapshot Tahunan LAKIP <?= esc($tahun) ?>
        </span>

        <?php if ($snapshotTerkunci): ?>
            <span class="badge bg-success"><i class="fa-solid fa-lock me-1"></i>Tahun Terkunci</span>
        <?php elseif ($snapshotAktif): ?>
            <span class="badge bg-warning text-dark">Draft &middot; versi <?= (int) $snapshotAktif['versi'] ?></span>
        <?php else: ?>
            <span class="badge bg-secondary">Belum ada snapshot</span>
        <?php endif; ?>
    </div>

    <div class="card-body">
        <?php if ($snapshotPesan): ?>
            <div class="alert alert-warning py-2 small"><?= esc($snapshotPesan) ?></div>
        <?php endif; ?>

        <p class="small text-secondary">
            Snapshot membekukan seluruh baris LAKIP tahun ini — sasaran, indikator, satuan, target,
            realisasi, analisis faktor, dan program/anggaran. Setelah <strong>difinalkan</strong>,
            perubahan pada RPJMD, Renstra, atau IKU tidak lagi mengubah isi laporan tahun ini.
        </p>

        <?php if ($snapshotLintasOpd): ?>
            <div class="alert alert-light border small mb-0">
                Pilih satu OPD terlebih dahulu. Dokumen LAKIP selalu milik satu unit kerja,
                sehingga snapshot lintas OPD tidak dibuat.
            </div>
        <?php else: ?>

            <?php if ($labelSumberBeku !== null): ?>
                <div class="small text-secondary mb-2">
                    Snapshot ini dibekukan dari <strong><?= esc($labelSumberBeku) ?></strong>.
                </div>
            <?php endif; ?>

            <?php /* Sumber layar berbeda dari sumber yang dibekukan adalah hal
                     yang wajar (operator sedang melihat-lihat), TETAPI menekan
                     "Sinkronkan" dalam keadaan itu akan menulis ulang snapshot
                     dari dokumen lain. Itu perlu disadari sebelum diklik. */ ?>
            <?php if ($snapshotAktif && ! $snapshotTerkunci && $sumberTampil !== ''
                      && ! empty($snapshotAktif['source_type'])
                      && $sumberTampil !== $snapshotAktif['source_type']): ?>
                <div class="alert alert-warning py-2 small">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                    Layar sedang menampilkan sumber <strong><?= esc(strtoupper($sumberTampil)) ?></strong>,
                    sedangkan snapshot dibekukan dari <strong><?= esc($labelSumberBeku ?? '-') ?></strong>.
                    Menekan <em>Sinkronkan Snapshot</em> sekarang akan menggantinya dengan sumber yang
                    sedang tampil.
                </div>
            <?php endif; ?>

            <?php if ($snapshotDipakai): ?>
                <div class="alert alert-info py-2 small">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    Tabel di atas sedang menampilkan <strong>data beku</strong> dari snapshot
                    versi <?= (int) ($snapshotAktif['versi'] ?? 1) ?>
                    <?php if (! empty($snapshotAktif['difinalkan_pada'])): ?>
                        (difinalkan <?= esc($snapshotAktif['difinalkan_pada']) ?>)
                    <?php endif; ?>,
                    bukan data hidup.
                </div>
            <?php endif; ?>

            <div class="d-flex flex-wrap gap-2 mb-3">
                <?php if (! $snapshotAktif): ?>
                    <?php if ($bolehSnapshot && ! empty($scope['canWrite'])): ?>
                        <form method="post" action="<?= base_url($snapshotBase . '/snapshot/siapkan') ?>">
                            <?= csrf_field() ?><?= $bidangLingkup ?>
                            <button class="btn btn-primary btn-sm">
                                <i class="fa-solid fa-camera me-1"></i>Siapkan LAKIP <?= esc($tahun) ?>
                            </button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <a class="btn btn-outline-primary btn-sm"
                       href="<?= base_url($snapshotBase) ?>?tahun=<?= esc($tahun, 'url') ?>&mode=<?= esc($mode, 'url') ?><?= $opd ? '&opd_id=' . $opd : '' ?><?= $qsSumber ?>&snapshot=<?= (int) $snapshotAktif['id'] ?>">
                        <i class="fa-solid fa-eye me-1"></i>Lihat Snapshot
                    </a>

                    <a class="btn btn-outline-secondary btn-sm"
                       href="<?= base_url($snapshotBase) ?>/snapshot/bandingkan?tahun=<?= esc($tahun, 'url') ?>&mode=<?= esc($mode, 'url') ?><?= $opd ? '&opd_id=' . $opd : '' ?><?= $qsSumber ?>">
                        <i class="fa-solid fa-code-compare me-1"></i>Bandingkan dengan Data Terbaru
                    </a>

                    <?php if ($bolehSnapshot && ! empty($scope['canWrite']) && ! $snapshotTerkunci): ?>
                        <form method="post" action="<?= base_url($snapshotBase . '/snapshot/sinkronkan') ?>"
                              onsubmit="return confirm('Buat versi baru snapshot dari data terkini? Versi lama tetap tersimpan sebagai riwayat.');">
                            <?= csrf_field() ?><?= $bidangLingkup ?>
                            <button class="btn btn-outline-warning btn-sm">
                                <i class="fa-solid fa-rotate me-1"></i>Sinkronkan Snapshot
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($bolehFinalisasi && ! empty($scope['canWrite']) && ! $snapshotTerkunci): ?>
                        <form method="post" action="<?= base_url($snapshotBase . '/snapshot/finalkan') ?>"
                              onsubmit="return confirm('Finalkan LAKIP <?= esc($tahun) ?>?\n\nSetelah final, angka tahun ini TIDAK BISA disinkronkan ulang dan tidak bisa disunting langsung. Koreksi selanjutnya hanya lewat Penyesuaian Kebijakan yang tercatat. Tindakan ini tidak dapat dibatalkan.');">
                            <?= csrf_field() ?><?= $bidangLingkup ?>
                            <button class="btn btn-success btn-sm">
                                <i class="fa-solid fa-lock me-1"></i>Finalkan / Kunci Tahun
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if (count($snapshotDaftar) > 1): ?>
                <div class="mb-3">
                    <div class="fw-semibold small mb-1">Riwayat versi</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered small mb-0" data-no-paginate>
                            <thead class="table-light">
                                <tr>
                                    <th style="width:70px">Versi</th>
                                    <th style="width:90px">Status</th>
                                    <th style="width:80px">Baris</th>
                                    <th>Dibuat</th>
                                    <th style="width:90px">Aktif</th>
                                    <th style="width:80px"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($snapshotDaftar as $s): ?>
                                    <tr>
                                        <td class="text-center"><?= (int) $s['versi'] ?></td>
                                        <td class="text-center">
                                            <span class="badge <?= $s['status'] === 'final' ? 'bg-success' : 'bg-warning text-dark' ?>">
                                                <?= esc(ucfirst($s['status'])) ?>
                                            </span>
                                        </td>
                                        <td class="text-center"><?= (int) $s['jumlah_baris'] ?></td>
                                        <td><?= esc($s['dibuat_pada']) ?></td>
                                        <td class="text-center">
                                            <?= ((int) $s['aktif'] === 1) ? '<i class="fa-solid fa-check text-success"></i>' : '<span class="text-secondary">—</span>' ?>
                                        </td>
                                        <td class="text-center">
                                            <a class="btn btn-outline-primary btn-sm"
                                               href="<?= base_url($snapshotBase) ?>?tahun=<?= esc($tahun, 'url') ?>&mode=<?= esc($mode, 'url') ?><?= $opd ? '&opd_id=' . $opd : '' ?>&snapshot=<?= (int) $s['id'] ?>">
                                                Lihat
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- =============== PENYESUAIAN KEBIJAKAN =============== -->
            <hr>
            <div class="fw-semibold mb-1">
                <i class="fa-solid fa-scale-balanced me-1"></i>Penyesuaian Kebijakan
            </div>
            <p class="small text-secondary">
                Penyesuaian adalah <strong>pengecualian</strong>, bukan cara biasa mengubah perencanaan.
                Berlaku hanya untuk LAKIP tahun ini dan wajib menyertakan dasar kebijakan.
                Bila perubahannya semestinya berlaku juga tahun-tahun berikutnya, gunakan
                <em>Usulkan sebagai Perubahan IKU</em> — itu hanya membuat draft revisi IKU,
                tidak mengubah IKU yang sedang berlaku.
            </p>

            <?php if ($bolehPenyesuaian && ! empty($scope['canWrite'])): ?>
                <form method="post" action="<?= base_url($snapshotBase . '/penyesuaian/save') ?>" class="row g-2 mb-3">
                    <?= csrf_field() ?><?= $bidangLingkup ?>

                    <div class="col-md-3">
                        <label class="form-label small fw-semibold mb-1">Indikator (target)</label>
                        <select name="target_id" class="form-select form-select-sm" required>
                            <option value="">— pilih indikator —</option>
                            <?php foreach (($indikatorRows ?? []) as $r): ?>
                                <option value="<?= (int) ($r['target_id'] ?? 0) ?>">
                                    <?= esc(mb_strimwidth((string) ($r['indikator_sasaran'] ?? ''), 0, 70, '...')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label small fw-semibold mb-1">Yang disesuaikan</label>
                        <select name="jenis" class="form-select form-select-sm" required>
                            <option value="target">Target</option>
                            <option value="realisasi">Realisasi</option>
                            <option value="satuan">Satuan</option>
                            <option value="indikator">Redaksi indikator</option>
                            <option value="lainnya">Lainnya</option>
                        </select>
                    </div>

                    <div class="col-md-1">
                        <label class="form-label small fw-semibold mb-1">Semula</label>
                        <input type="text" name="nilai_asli" class="form-control form-control-sm">
                    </div>

                    <div class="col-md-1">
                        <label class="form-label small fw-semibold mb-1">Menjadi</label>
                        <input type="text" name="nilai_disesuaikan" class="form-control form-control-sm">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small fw-semibold mb-1">Dasar kebijakan <span class="text-danger">*</span></label>
                        <input type="text" name="dasar_kebijakan" class="form-control form-control-sm" required
                               placeholder="mis. Perbup Refocusing Anggaran">
                    </div>

                    <div class="col-md-1">
                        <label class="form-label small fw-semibold mb-1">Nomor</label>
                        <input type="text" name="nomor_dasar" class="form-control form-control-sm">
                    </div>

                    <div class="col-md-1">
                        <label class="form-label small fw-semibold mb-1">Tanggal</label>
                        <input type="date" name="tanggal_dasar" class="form-control form-control-sm">
                    </div>

                    <div class="col-md-10">
                        <label class="form-label small fw-semibold mb-1">Alasan <span class="text-danger">*</span></label>
                        <input type="text" name="alasan" class="form-control form-control-sm" required>
                    </div>

                    <div class="col-md-2 d-flex align-items-end">
                        <button class="btn btn-primary btn-sm w-100">Simpan Penyesuaian</button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if (empty($penyesuaianRiwayat)): ?>
                <div class="small text-secondary">Belum ada penyesuaian kebijakan pada tahun ini.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered small align-middle mb-0" data-no-paginate>
                        <thead class="table-light">
                            <tr>
                                <th style="width:90px">Jenis</th>
                                <th style="width:150px">Semula &rarr; Menjadi</th>
                                <th>Dasar &amp; Alasan</th>
                                <th style="width:110px">Catatan</th>
                                <th style="width:220px">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($penyesuaianRiwayat as $p): ?>
                                <tr class="<?= ((int) $p['aktif'] === 0) ? 'table-secondary' : '' ?>">
                                    <td class="text-center"><?= esc(ucfirst($p['jenis'])) ?></td>
                                    <td class="text-center">
                                        <span class="text-secondary"><?= esc($p['nilai_asli'] ?? '—') ?></span>
                                        &rarr;
                                        <strong><?= esc($p['nilai_disesuaikan'] ?? '—') ?></strong>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= esc($p['dasar_kebijakan']) ?>
                                            <?= ! empty($p['nomor_dasar']) ? ' No. ' . esc($p['nomor_dasar']) : '' ?>
                                        </div>
                                        <div class="text-secondary"><?= esc($p['alasan']) ?></div>
                                    </td>
                                    <td class="text-center">
                                        <?php if ((int) $p['setelah_final'] === 1): ?>
                                            <span class="badge bg-danger" title="Dibuat setelah LAKIP difinalkan">
                                                pasca-final
                                            </span>
                                        <?php endif; ?>
                                        <?php if ((int) $p['aktif'] === 0): ?>
                                            <span class="badge bg-secondary">dicabut</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (! empty($p['iku_revisi_id'])): ?>
                                            <span class="badge bg-info text-dark">
                                                draft revisi IKU #<?= (int) $p['iku_revisi_id'] ?>
                                            </span>
                                        <?php elseif ($bolehPenyesuaian && ! empty($scope['canWrite']) && (int) $p['aktif'] === 1): ?>
                                            <form method="post" class="d-inline"
                                                  action="<?= base_url($snapshotBase . '/penyesuaian/usul-revisi/' . (int) $p['id']) ?>"
                                                  onsubmit="return confirm('Buat DRAFT revisi IKU dari penyesuaian ini? IKU yang sedang berlaku tidak akan berubah.');">
                                                <?= csrf_field() ?><?= $bidangLingkup ?>
                                                <button class="btn btn-outline-info btn-sm mb-1">
                                                    Usulkan sebagai Perubahan IKU
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($bolehPenyesuaian && ! empty($scope['canWrite']) && (int) $p['aktif'] === 1): ?>
                                            <form method="post" class="d-inline"
                                                  action="<?= base_url($snapshotBase . '/penyesuaian/cabut/' . (int) $p['id']) ?>"
                                                  onsubmit="return confirm('Cabut penyesuaian ini? Riwayatnya tetap tersimpan.');">
                                                <?= csrf_field() ?><?= $bidangLingkup ?>
                                                <button class="btn btn-outline-danger btn-sm mb-1">Cabut</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
