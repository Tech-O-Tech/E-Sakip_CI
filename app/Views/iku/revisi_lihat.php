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
<?php /* NULL pada berlaku_sampai_tahun berarti "belum ada revisi
                         berikutnya", BUKAN "berlaku selamanya" — IKU berhenti
                         di ujung periodenya. Yang ditampilkan karena itu tahun
                         akhir periode; nilainya sengaja TIDAK ditulis ke basis
                         data supaya sahkan() tetap bebas menjahit ulang
                         timeline tanpa harus membatalkan angka tampilan. */ ?>
                    <?= $revisi['berlaku_sampai_tahun'] !== null
                        ? (int) $revisi['berlaku_sampai_tahun']
                        : (int) $revisi['tahun_akhir'] ?>
                <?php endif; ?>
            </div>

            <?php /* Hanya muncul saat revisinya memang boleh disunting (draft,
                     atau berlaku di bawah izin). Mengubah tahun berlaku revisi
                     BERLAKU ikut menggeser batas revisi sebelumnya — modelnya
                     yang menjahit, form ini cuma menyampaikan niat. */ ?>
            <?php if (! empty($bolehUbahBerlaku)): ?>
                <form method="post"
                      action="<?= base_url($baseUrl . '/revisi/berlaku/' . (int) $revisi['id']) ?>"
                      class="d-flex align-items-center gap-1 mt-2"
                      onsubmit="return confirm('Ubah tahun mulai berlaku revisi ini? Masa berlaku revisi sebelumnya ikut disesuaikan.');">
                    <?= csrf_field() ?>
                    <?php
                    /* Daftarnya OTOMATIS mengikuti periode IKU revisi ini —
                       yang periodenya sendiri mengikuti Renstra sumbernya.
                       Tahun pertama periode tidak pernah ditawarkan: itu jatah
                       Kondisi Awal. Tahun yang sudah dipakai revisi lain
                       ditandai dan tidak bisa dipilih, jadi bentrok dicegah
                       SEBELUM tombol ditekan — bukan ditolak sesudahnya. */
                    $bebas    = $tahunBebas ?? [];
                    $terpakai = $tahunTerpakai ?? [];
                    $kini     = (int) $revisi['berlaku_mulai_tahun'];
                    ?>
                    <select name="berlaku_mulai_tahun" class="form-select form-select-sm" style="width:auto">
                        <?php foreach (range((int) $revisi['tahun_mulai'] + 1, (int) $revisi['tahun_akhir']) as $th): ?>
                            <?php $dipakai = $terpakai[$th] ?? null; ?>
                            <option value="<?= $th ?>"
                                <?= $th === $kini ? 'selected' : '' ?>
                                <?= $dipakai !== null ? 'disabled' : '' ?>>
                                <?= $th ?><?= $dipakai !== null
                                    ? ' — dipakai revisi ke-' . esc($dipakai['nomor']) . ' (' . esc($dipakai['status']) . ')'
                                    : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-outline-primary btn-sm text-nowrap">
                        <i class="fa-solid fa-calendar-pen me-1"></i>Ubah Tahun
                    </button>
                </form>

                <?php if (($tahunBebas ?? []) === []): ?>
                    <div class="small text-danger mt-1">
                        Seluruh tahun lain pada periode <?= (int) $revisi['tahun_mulai'] ?>&ndash;<?= (int) $revisi['tahun_akhir'] ?>
                        sudah dipakai revisi lain. Geser dulu tahun berlaku salah satunya.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
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
            <div class="fw-semibold"><?= esc(($revisi['dibekukan_pada'] ?? null) ?: '—') ?></div>
        </div>
        <?php if (! empty($revisi['catatan'])): ?>
            <div class="col-12">
                <div class="text-secondary">Catatan</div>
                <div><?= esc($revisi['catatan']) ?></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
/* Keadaan kunci revisi ini. Dikirim IkuRevisiTrait::revisiLihat(); layar lama
   yang belum mengirimnya tetap merender arsip beku seperti sebelumnya. */
$keadaanIzin = $keadaanIzin ?? ['terkunci' => false, 'izin' => null, 'boleh_minta' => false,
                               'boleh_tarik' => false, 'sedang_disunting' => false, 'alasan' => null];
$aksiIzin    = base_url($baseUrl . '/revisi/izin');
?>

<?php if (! empty($keadaanIzin['sedang_disunting'])): ?>
    <div class="alert alert-warning">
        <div class="fw-semibold mb-1">
            <i class="fa-solid fa-unlock me-1"></i>Revisi ini sedang terbuka untuk diperbaiki
        </div>
        <div class="small mb-2"><?= esc($keadaanIzin['alasan'] ?? '') ?></div>

        <?php /* Dua langkah terpisah dengan sengaja: menyimpan boleh berkali-kali,
                 sedangkan menerapkan ke IKU berjalan adalah keputusan tersendiri
                 yang menutup izinnya. */ ?>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary btn-sm"
               href="<?= base_url($baseUrl . '/revisi/sunting/' . (int) $revisi['id']) ?>">
                <i class="fa-solid fa-pen me-1"></i>Sunting Isi Revisi
            </a>

            <form method="post" action="<?= $aksiIzin . '/selesai/' . (int) $revisi['id'] ?>"
                  onsubmit="return confirm('Terapkan perbaikan ke IKU berjalan dan tutup izin sunting?\n\nIKU berjalan akan mengikuti isi arsip revisi ini. Laporan LAKIP yang sudah difinalkan tidak ikut berubah.');">
                <?= csrf_field() ?>
                <button class="btn btn-success btn-sm">
                    <i class="fa-solid fa-check me-1"></i>Selesai &amp; Terapkan ke IKU Berjalan
                </button>
            </form>
        </div>
    </div>

<?php elseif (! empty($keadaanIzin['terkunci'])): ?>
    <div class="alert alert-light border">
        <div class="fw-semibold mb-1">
            <i class="fa-solid fa-lock me-1"></i>Arsip beku
        </div>
        <div class="small mb-2">
            Isinya adalah IKU sebagaimana berlaku pada masanya &mdash; LAKIP tahun-tahun
            tersebut membacanya dari sini.
            <?= esc($keadaanIzin['alasan'] ?? '') ?>
        </div>

        <?php if (! empty($keadaanIzin['boleh_tarik']) && ! empty($keadaanIzin['izin'])): ?>
            <div class="small text-secondary mb-2">
                Diajukan <?= esc($keadaanIzin['izin']['diminta_nama'] ?? '&mdash;') ?>
                <?= ! empty($keadaanIzin['izin']['diminta_pada'])
                    ? esc(date('d M Y H:i', strtotime($keadaanIzin['izin']['diminta_pada'])))
                    : '' ?>
                &mdash; alasan: <?= esc($keadaanIzin['izin']['alasan'] ?? '') ?>
            </div>
            <form method="post" action="<?= $aksiIzin . '/tarik/' . (int) $keadaanIzin['izin']['id'] ?>"
                  onsubmit="return confirm('Tarik permohonan izin sunting?');">
                <?= csrf_field() ?>
                <button class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-rotate-left me-1"></i>Tarik Permohonan
                </button>
            </form>
        <?php endif; ?>

        <?php if (! empty($keadaanIzin['boleh_minta'])): ?>
            <form method="post" action="<?= $aksiIzin . '/ajukan/' . (int) $revisi['id'] ?>" class="row g-2">
                <?= csrf_field() ?>
                <div class="col-md-9">
                    <input type="text" name="alasan" class="form-control form-control-sm" required
                           maxlength="500"
                           placeholder="Alasan perbaikan — mis. salah ketik indikator 3, atau target 2032 keliru">
                </div>
                <div class="col-md-3 d-grid">
                    <button class="btn btn-warning btn-sm">
                        <i class="fa-solid fa-unlock me-1"></i>Ajukan Izin Sunting
                    </button>
                </div>
            </form>
        <?php endif; ?>
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
