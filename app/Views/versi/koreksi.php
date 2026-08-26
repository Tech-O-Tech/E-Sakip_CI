<?php

/**
 * Ajukan koreksi pada versi yang sudah ditetapkan (§20, §21).
 *
 * Pemakai memilih entitas → kolom → nilai usulan. Pilihan kolom dibatasi daftar
 * putih dari VersionCorrectionService; kolom nilai (target, satuan, baseline),
 * penunjuk induk, dan tanggal berlaku sama sekali tidak muncul di sini karena
 * perubahannya bersifat substantif dan jalannya versi baru.
 *
 * @var array $isi         pohon arsip versi
 * @var array $daftarPutih entity_type => ['label'=>..., 'kolom'=>[field=>label]]
 * @var array $riwayat     permintaan koreksi versi ini
 */
$title        = $title ?? 'Ajukan Koreksi';
$judulHalaman = $judulHalaman ?? 'Ajukan Koreksi';

$badgeStatus = static fn (string $s): array => match ($s) {
    'pending'   => ['bg-primary', 'Menunggu keputusan'],
    'approved'  => ['bg-success', 'Disetujui & diterapkan'],
    'returned'  => ['bg-danger', 'Dikembalikan'],
    'cancelled' => ['bg-dark', 'Dibatalkan'],
    default     => ['bg-light text-dark', $s],
};

/**
 * Susun daftar entitas yang bisa dikoreksi, sekalian membawa nilai kolomnya
 * supaya form bisa mengisi "nilai sekarang" tanpa query tambahan.
 */
$pilihan = [];

$tambah = static function (string $tipe, array $row, string $konteks) use (&$pilihan, $daftarPutih) {
    if (! isset($daftarPutih[$tipe])) {
        return;
    }

    $nilai = [];

    foreach ($daftarPutih[$tipe]['kolom'] as $kolom => $meta) {
        $nilai[$kolom] = (string) ($row[$kolom] ?? '');
    }

    $pilihan[] = [
        'tipe'    => $tipe,
        'id'      => (int) $row['id'],
        'label'   => $daftarPutih[$tipe]['label'],
        'konteks' => $konteks,
        'nilai'   => $nilai,
    ];
};

// Kepala versi selalu bisa dikoreksi.
$tambah('dokumen_versi', $versi, 'Keterangan versi ini');

foreach ($isi as $akar) {
    $anakTujuan = is_array($akar['tujuan'] ?? null) ? $akar['tujuan'] : null;

    if ($anakTujuan !== null) {
        $tambah('rpjmd_versi_misi', $akar, mb_strimwidth((string) ($akar['misi'] ?? ''), 0, 60, '...'));
    }

    foreach ($anakTujuan ?? [$akar] as $t) {
        $tipeTujuan = $anakTujuan !== null ? 'rpjmd_versi_tujuan' : 'renstra_versi_tujuan';
        $namaTujuan = (string) ($t['tujuan_rpjmd'] ?? $t['tujuan'] ?? '');
        $tambah($tipeTujuan, $t, mb_strimwidth($namaTujuan, 0, 60, '...'));

        foreach ($t['sasaran'] ?? [] as $s) {
            $tipeSasaran = $anakTujuan !== null ? 'rpjmd_versi_sasaran' : 'renstra_versi_sasaran';
            $namaSasaran = (string) ($s['sasaran_rpjmd'] ?? $s['sasaran'] ?? '');
            $tambah($tipeSasaran, $s, mb_strimwidth($namaSasaran, 0, 60, '...'));

            foreach ($s['indikator'] ?? [] as $i) {
                $tipeInd = $anakTujuan !== null
                    ? 'rpjmd_versi_indikator_sasaran'
                    : 'renstra_versi_indikator_sasaran';
                $namaInd = mb_strimwidth((string) ($i['indikator_sasaran'] ?? ''), 0, 55, '...');
                $tambah($tipeInd, $i, $namaInd);

                // Target dicantumkan satu entri per tahun, karena yang dikoreksi
                // memang satu angka pada satu tahun tertentu.
                $tipeTarget = $anakTujuan !== null ? 'rpjmd_versi_target' : 'renstra_versi_target';

                foreach ($i['target'] ?? [] as $tg) {
                    $tambah($tipeTarget, $tg, 'Tahun ' . $tg['tahun'] . ' — ' . $namaInd);
                }
            }
        }
    }
}
?>
<?= $this->include('templates/shell_atas') ?>

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <span class="fw-semibold">V<?= (int) $versi['version_no'] ?> — <?= esc($versi['label']) ?></span>
        <div class="text-secondary sel-kecil">
            <?= esc($namaDokumen) ?> <?= (int) $versi['periode_mulai'] ?>&ndash;<?= (int) $versi['periode_akhir'] ?>
            &middot; berlaku sejak <?= esc($versi['effective_from']) ?>
        </div>
    </div>
    <a href="<?= base_url($baseUrl . '/versi/lihat/' . (int) $versi['id']) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fa-solid fa-arrow-left me-1"></i>Kembali ke Versi
    </a>
</div>

<div class="kotak-jejak mb-3">
    <div class="fw-semibold mb-1">Dua jenis koreksi, dua tingkat kehati-hatian</div>
    <div class="small text-secondary">
        <strong>Koreksi teks</strong> — salah ketik, ejaan, nomor dokumen, catatan. Cukup beralasan.
        <br>
        <strong>Koreksi nilai</strong> — satuan, baseline, target. <span class="text-danger fw-semibold">Wajib
        menyertakan dasar tertulis</span>, dan <strong>ditolak bila versi ini sudah pernah dipakai LAKIP</strong>.
        Sesudah angkanya masuk laporan, mengubahnya akan membuat arsip dan laporan menyatakan hal
        yang berbeda — untuk itu jalannya menerbitkan versi baru.
        <br><br>
        Yang tetap tidak bisa dikoreksi sama sekali: <strong>hierarki, tanggal berlaku, serta
        penambahan atau penghapusan baris</strong> — ketiganya mengubah bentuk dokumen, bukan isinya.
        <br><br>
        Satu hal yang tidak bisa dijaga sistem: mengganti rumusan indikator bisa berarti memperbaiki
        ejaan, bisa juga berarti mengganti indikatornya. Keduanya menyentuh kolom yang sama — itulah
        sebabnya setiap koreksi <strong>menunggu persetujuan Admin Kabupaten</strong>, yang melihat
        nilai lama dan baru berdampingan sebelum memutuskan.
    </div>
</div>

<?php if ($dipakaiLakip > 0): ?>
    <div class="kotak-jejak awas mb-4">
        <div class="fw-semibold text-danger mb-1">
            <i class="fa-solid fa-triangle-exclamation me-1"></i>Versi ini sudah dipakai
            <?= (int) $dipakaiLakip ?> snapshot LAKIP
        </div>
        <div class="small text-secondary">
            Koreksi <strong>teks</strong> masih bisa diajukan. Koreksi <strong>nilai</strong>
            (satuan, baseline, target) akan ditolak — angkanya sudah masuk laporan.
        </div>
    </div>
<?php endif; ?>

<?php if (empty($pilihan)): ?>
    <div class="alert alert-light border">Tidak ada entitas yang bisa dikoreksi pada versi ini.</div>
<?php else: ?>
    <form method="post" action="<?= base_url($baseUrl . '/versi/koreksi/' . (int) $versi['id']) ?>"
          class="card border-0 shadow-sm mb-4" id="formKoreksi">
        <?= csrf_field() ?>
        <div class="card-header bg-white fw-semibold">Permintaan Koreksi Baru</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Bagian yang dikoreksi <span class="text-danger">*</span></label>
                    <select id="pilihEntitas" class="form-select form-select-sm" required>
                        <option value="">— pilih —</option>
                        <?php foreach ($pilihan as $idx => $p): ?>
                            <option value="<?= $idx ?>">
                                [<?= esc($p['label']) ?>] <?= esc($p['konteks']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Kolom <span class="text-danger">*</span></label>
                    <select name="field" id="pilihKolom" class="form-select form-select-sm" required disabled>
                        <option value="">— pilih bagian dulu —</option>
                    </select>
                </div>

                <input type="hidden" name="entity_type" id="entityType">
                <input type="hidden" name="entity_id" id="entityId">

                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Nilai sekarang</label>
                    <textarea id="nilaiSekarang" rows="3" class="form-control form-control-sm bg-light" readonly></textarea>
                </div>

                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Usulan nilai baru <span class="text-danger">*</span></label>
                    <textarea name="requested_value" id="nilaiBaru" rows="3"
                              class="form-control form-control-sm" required></textarea>
                </div>

                <div class="col-md-8">
                    <label class="form-label small fw-semibold">Alasan koreksi <span class="text-danger">*</span></label>
                    <textarea name="reason" rows="2" class="form-control form-control-sm" required
                              placeholder="mis. salah ketik pada kata ..."><?= esc(old('reason')) ?></textarea>
                </div>

                <div class="col-md-4">
                    <label class="form-label small fw-semibold">
                        Dasar tertulis
                        <span class="text-danger d-none" id="dasarWajib">*</span>
                    </label>
                    <input type="text" name="dasar" id="inputDasar" maxlength="255"
                           class="form-control form-control-sm"
                           value="<?= esc(old('dasar')) ?>" placeholder="Nota dinas / berita acara">
                    <div class="form-text" id="dasarKeterangan">Opsional untuk koreksi teks.</div>
                </div>
            </div>
        </div>
        <div class="card-footer bg-white d-flex justify-content-end">
            <button class="btn btn-success btn-sm">
                <i class="fa-solid fa-paper-plane me-1"></i>Ajukan Koreksi
            </button>
        </div>
    </form>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Riwayat Permintaan Koreksi</div>
    <div class="card-body">
        <?php if (empty($riwayat)): ?>
            <div class="text-secondary small">Belum ada permintaan koreksi pada versi ini.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered align-middle small revisi-tabel" data-no-paginate>
                    <thead class="table-success">
                        <tr>
                            <th style="width:150px">Kolom</th>
                            <th>Sebelum</th>
                            <th>Usulan</th>
                            <th style="width:150px">Status</th>
                            <th style="width:90px">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($riwayat as $k): ?>
                            <?php [$kelas, $labelStatus] = $badgeStatus((string) $k['status']); ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= esc($k['field']) ?></div>
                                    <div class="text-secondary sel-kecil"><?= esc($k['entity_type']) ?></div>
                                </td>
                                <td class="sel-kecil garis-putus"><?= esc($k['old_value'] ?? '—') ?></td>
                                <td class="sel-kecil"><strong><?= esc($k['requested_value'] ?? '—') ?></strong></td>
                                <td>
                                    <span class="badge <?= $kelas ?>"><?= esc($labelStatus) ?></span>
                                    <?php if (! empty($k['review_note'])): ?>
                                        <div class="text-danger sel-kecil mt-1">
                                            Catatan: <?= esc($k['review_note']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="text-secondary sel-kecil mt-1"><?= esc($k['reason']) ?></div>
                                </td>
                                <td>
                                    <?php if ($k['status'] === 'pending'): ?>
                                        <form method="post"
                                              action="<?= base_url($baseUrl . '/versi/koreksi/' . (int) $versi['id'] . '/batal/' . (int) $k['id']) ?>"
                                              onsubmit="return confirm('Batalkan permintaan koreksi ini?')">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-outline-danger btn-sm">Batalkan</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-secondary sel-kecil">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
<?php /* HEX_TAG dkk. wajib: isinya teks indikator dari basis data, dan tanpa
         itu '</script>' di dalam teks menutup blok ini = stored XSS. */ ?>
    var data = <?= json_encode($pilihan, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var kolomMeta = <?= json_encode(array_map(static fn ($d) => $d['kolom'], $daftarPutih), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var dipakaiLakip = <?= (int) $dipakaiLakip ?>;

    var selEntitas = document.getElementById('pilihEntitas');
    var selKolom   = document.getElementById('pilihKolom');
    var sekarang   = document.getElementById('nilaiSekarang');
    var baru       = document.getElementById('nilaiBaru');
    var inputDasar = document.getElementById('inputDasar');
    var tandaDasar = document.getElementById('dasarWajib');
    var ketDasar   = document.getElementById('dasarKeterangan');

    if (!selEntitas) { return; }

    // Koreksi nilai menuntut dasar tertulis, dan ditolak bila versi sudah
    // dipakai LAKIP. Diberitahukan lebih awal di sini supaya operator tidak
    // kehilangan isian formnya di server.
    function sesuaikanKelas() {
        var p = data[selEntitas.value];
        if (!p) { return; }

        var meta  = (kolomMeta[p.tipe] || {})[selKolom.value] || {};
        var nilai = meta.kelas === 'nilai';

        inputDasar.required = nilai;
        tandaDasar.classList.toggle('d-none', !nilai);
        ketDasar.textContent = nilai
            ? 'WAJIB untuk koreksi nilai (satuan, baseline, target).'
            : 'Opsional untuk koreksi teks.';
        ketDasar.classList.toggle('text-danger', nilai);

        if (nilai && dipakaiLakip > 0) {
            ketDasar.textContent = 'Versi ini sudah dipakai LAKIP — koreksi nilai akan ditolak. '
                + 'Terbitkan versi baru.';
        }
    }

    selEntitas.addEventListener('change', function () {
        var p = data[selEntitas.value];
        selKolom.innerHTML = '';
        sekarang.value = '';
        baru.value = '';

        if (!p) { selKolom.disabled = true; return; }

        document.getElementById('entityType').value = p.tipe;
        document.getElementById('entityId').value   = p.id;

        var meta = kolomMeta[p.tipe] || {};
        Object.keys(p.nilai).forEach(function (k) {
            var o = document.createElement('option');
            o.value = k;
            o.textContent = (meta[k] ? meta[k].label : k)
                + (meta[k] && meta[k].kelas === 'nilai' ? '  [nilai]' : '');
            selKolom.appendChild(o);
        });

        selKolom.disabled = false;
        selKolom.dispatchEvent(new Event('change'));
    });

    selKolom.addEventListener('change', function () {
        var p = data[selEntitas.value];
        if (!p) { return; }
        // Nilai sekarang ditampilkan agar pengaju melihat persis apa yang diubah;
        // nilai baru diisi salinannya supaya ia menyunting, bukan mengetik ulang.
        sekarang.value = p.nilai[selKolom.value] || '';
        baru.value = sekarang.value;
        sesuaikanKelas();
    });
})();
</script>

<?= $this->include('templates/shell_bawah') ?>
