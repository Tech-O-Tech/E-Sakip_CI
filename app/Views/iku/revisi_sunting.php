<?php
$title        = $title ?? 'Sunting Draft Revisi';
$judulHalaman = 'Sunting Draft: ' . $revisi['nama'];

/** @var array $isi */
/** @var array $years */
/** @var array $satuan_options */
/** @var array $indikatorLive daftar indikator IKU berjalan, untuk memilih yang digantikan */

$jenisOpsi = [
    'tetap'      => 'Tetap (tidak berubah)',
    'revisi'     => 'Direvisi (indikator sama, redaksi/target disesuaikan)',
    'pengganti'  => 'Pengganti (indikator baru menggantikan yang lama)',
    'baru'       => 'Baru (tambahan, tidak menggantikan apa pun)',
    'dihentikan' => 'Dihentikan (tidak dipakai lagi mulai revisi ini)',
];
?>
<?= $this->include('templates/shell_atas') ?>

<div class="kotak-jejak mb-4">
    <div class="fw-semibold mb-1">Anda sedang menyunting DRAFT</div>
    <div class="small text-secondary">
        Semua perubahan di halaman ini tersimpan di dalam draft saja. IKU berjalan, LAKIP,
        dashboard, dan API publik <strong>belum berubah</strong> sampai draft ini disahkan.
        Draft berlaku mulai tahun <strong><?= (int) $revisi['berlaku_mulai_tahun'] ?></strong>.
    </div>
</div>

<div class="alert alert-light border small">
    <strong>Membedakan jenis perubahan itu penting.</strong>
    <em>Direvisi</em> berarti indikatornya tetap sama — trennya boleh disambung antar tahun.
    <em>Pengganti</em> berarti indikator lama diganti indikator lain; asal-usulnya wajib dipilih supaya
    riwayatnya bisa ditelusuri. Bila rumusan/metodologinya berubah sehingga angka tahun ini
    <u>tidak sebanding</u> dengan tahun lalu, centang <strong>Tren terputus</strong> agar grafik tidak
    menyambungkan dua hal yang berbeda.
</div>

<div class="d-flex gap-2 mb-3">
    <a href="<?= base_url($baseUrl . '/revisi') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fa-solid fa-arrow-left me-1"></i>Daftar Revisi
    </a>
    <a href="<?= base_url($baseUrl . '/revisi/lihat/' . (int) $revisi['id']) ?>" class="btn btn-outline-primary btn-sm">
        <i class="fa-solid fa-eye me-1"></i>Pratinjau
    </a>
</div>

<form method="post" action="<?= base_url($baseUrl . '/revisi/sunting/' . (int) $revisi['id']) ?>">
    <?= csrf_field() ?>

    <?php if (empty($isi)): ?>
        <div class="alert alert-warning">
            Draft ini kosong — tidak ada sasaran IKU pada periode
            <?= (int) $revisi['tahun_mulai'] ?>&ndash;<?= (int) $revisi['tahun_akhir'] ?> saat draft dibuat.
        </div>
    <?php else: ?>
        <?php foreach ($isi as $sas): ?>
            <div class="card mb-4">
                <div class="card-header bg-success-subtle fw-semibold">
                    <?= esc($sas['sasaran']) ?>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle small mb-0 revisi-tabel" data-no-paginate>
                            <thead class="table-light">
                                <tr>
                                    <th style="min-width:240px">Indikator</th>
                                    <th style="width:130px">Satuan</th>
                                    <?php foreach ($years as $th): ?>
                                        <th style="width:88px"><?= $th ?></th>
                                    <?php endforeach; ?>
                                    <th style="width:230px">Jenis Perubahan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sas['indikator'] as $ind): ?>
                                    <?php $id = (int) $ind['id']; ?>
                                    <tr>
                                        <td>
                                            <textarea name="baris[<?= $id ?>][indikator]" class="form-control form-control-sm"
                                                      rows="2"><?= esc($ind['indikator']) ?></textarea>
                                            <input type="text" name="baris[<?= $id ?>][catatan_perubahan]"
                                                   class="form-control form-control-sm mt-1"
                                                   placeholder="Catatan perubahan (opsional)"
                                                   value="<?= esc($ind['catatan_perubahan'] ?? '') ?>">
                                        </td>
                                        <td>
                                            <select name="baris[<?= $id ?>][satuan]" class="form-select form-select-sm">
                                                <option value="">— pilih —</option>
                                                <?php foreach ($satuan_options as $s): ?>
                                                    <option value="<?= (int) $s['id'] ?>"
                                                        <?= ((string) $ind['satuan'] === (string) $s['id']) ? 'selected' : '' ?>>
                                                        <?= esc($s['satuan']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                                <?php
                                                // Satuan berupa teks bebas (bukan id) tetap dipertahankan
                                                // sebagai pilihan, supaya menyimpan ulang tidak menghapusnya.
                                                $bebas = trim((string) $ind['satuan']);
                                                if ($bebas !== '' && ! preg_match('/^[0-9]+$/', $bebas)): ?>
                                                    <option value="<?= esc($bebas) ?>" selected><?= esc($bebas) ?></option>
                                                <?php endif; ?>
                                            </select>
                                        </td>

                                        <?php foreach ($years as $th): ?>
                                            <td>
                                                <input type="text" class="form-control form-control-sm text-center"
                                                       name="baris[<?= $id ?>][target][<?= $th ?>]"
                                                       value="<?= esc($ind['target'][$th]['target'] ?? '') ?>">
                                            </td>
                                        <?php endforeach; ?>

                                        <td>
                                            <select name="baris[<?= $id ?>][jenis_perubahan]"
                                                    class="form-select form-select-sm pilih-jenis mb-1">
                                                <?php foreach ($jenisOpsi as $nilai => $label): ?>
                                                    <option value="<?= $nilai ?>"
                                                        <?= $ind['jenis_perubahan'] === $nilai ? 'selected' : '' ?>>
                                                        <?= esc($label) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <select name="baris[<?= $id ?>][indikator_sebelumnya_id]"
                                                    class="form-select form-select-sm mb-1 pilih-asal"
                                                    <?= $ind['jenis_perubahan'] === 'pengganti' ? '' : 'style="display:none"' ?>>
                                                <option value="">— indikator yang digantikan —</option>
                                                <?php foreach ($indikatorLive as $live): ?>
                                                    <option value="<?= (int) $live['id'] ?>"
                                                        <?= ((int) ($ind['indikator_sebelumnya_id'] ?? 0) === (int) $live['id']) ? 'selected' : '' ?>>
                                                        <?= esc(mb_strimwidth($live['indikator'], 0, 60, '...')) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" value="1"
                                                       id="subs<?= $id ?>"
                                                       name="baris[<?= $id ?>][perubahan_substansial]"
                                                    <?= ! empty($ind['perubahan_substansial']) ? 'checked' : '' ?>>
                                                <label class="form-check-label sel-kecil" for="subs<?= $id ?>">
                                                    Tren terputus
                                                </label>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <!-- baris tambah indikator baru pada sasaran ini -->
                                <tr class="table-light">
                                    <td>
                                        <textarea name="baru[s<?= (int) $sas['id'] ?>][indikator]"
                                                  class="form-control form-control-sm" rows="2"
                                                  placeholder="+ Tambah indikator baru pada sasaran ini"></textarea>
                                        <input type="hidden" name="baru[s<?= (int) $sas['id'] ?>][revisi_sasaran_id]"
                                               value="<?= (int) $sas['id'] ?>">
                                    </td>
                                    <td>
                                        <select name="baru[s<?= (int) $sas['id'] ?>][satuan]" class="form-select form-select-sm">
                                            <option value="">— pilih —</option>
                                            <?php foreach ($satuan_options as $s): ?>
                                                <option value="<?= (int) $s['id'] ?>"><?= esc($s['satuan']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <?php foreach ($years as $th): ?>
                                        <td>
                                            <input type="text" class="form-control form-control-sm text-center"
                                                   name="baru[s<?= (int) $sas['id'] ?>][target][<?= $th ?>]">
                                        </td>
                                    <?php endforeach; ?>
                                    <td>
                                        <select name="baru[s<?= (int) $sas['id'] ?>][jenis_perubahan]"
                                                class="form-select form-select-sm pilih-jenis mb-1">
                                            <option value="baru" selected>Baru (tambahan)</option>
                                            <option value="pengganti">Pengganti</option>
                                        </select>
                                        <select name="baru[s<?= (int) $sas['id'] ?>][indikator_sebelumnya_id]"
                                                class="form-select form-select-sm mb-1 pilih-asal" style="display:none">
                                            <option value="">— indikator yang digantikan —</option>
                                            <?php foreach ($indikatorLive as $live): ?>
                                                <option value="<?= (int) $live['id'] ?>">
                                                    <?= esc(mb_strimwidth($live['indikator'], 0, 60, '...')) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" value="1"
                                                   id="subsbaru<?= (int) $sas['id'] ?>"
                                                   name="baru[s<?= (int) $sas['id'] ?>][perubahan_substansial]">
                                            <label class="form-check-label sel-kecil" for="subsbaru<?= (int) $sas['id'] ?>">
                                                Tren terputus
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="d-flex gap-2">
        <button class="btn btn-success">
            <i class="fa-solid fa-floppy-disk me-1"></i>Simpan Draft
        </button>
        <a href="<?= base_url($baseUrl . '/revisi') ?>" class="btn btn-outline-secondary">Selesai</a>
    </div>
</form>

<script>
    // Pilihan "indikator yang digantikan" hanya muncul saat jenisnya Pengganti.
    // Server tetap menolak Pengganti tanpa asal-usul — ini sekadar mengurangi
    // kesempatan salah isi, bukan penggantinya.
    document.querySelectorAll('.pilih-jenis').forEach(function (sel) {
        sel.addEventListener('change', function () {
            const asal = sel.closest('td').querySelector('.pilih-asal');
            if (!asal) return;
            asal.style.display = (sel.value === 'pengganti') ? '' : 'none';
        });
    });
</script>

<?= $this->include('templates/shell_bawah') ?>
