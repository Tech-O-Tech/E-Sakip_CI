<?php
/**
 * Partial Pohon Kinerja OPD (legenda + pohon).
 * Variabel dibutuhkan dari parent view:
 *   $tree  array  hasil buildOpdTree()
 */
$tree = $tree ?? [];
// CSF disembunyikan di semua tampilan pohon OPD (admin_kab & adminOpd). Bisa di-override true.
$showCsf = $showCsf ?? false;
// Indikator diberi kode "IK" secara default (admin_kab & adminOpd).
$showKode = $showKode ?? true;
// Program & kegiatan PK di bawah kabid (Eselon III). Default FALSE = aman:
// partial ini dipakai bersama halaman PUBLIK, jadi hanya controller admin
// yang boleh menyalakannya lewat array data (bukan argumen include).
$showProgramPk = $showProgramPk ?? false;
?>

<!-- LEGENDA WARNA -->
<div class="pohon-legend">
    <span class="lg-title">Keterangan:</span>
    <div class="lg-item"><span class="lg-swatch" style="background:linear-gradient(135deg,#15803d,#166534)"></span> Tujuan RPJMD</div>
    <?php // Jenjang sasaran dinomori 1–5 (RPJMD, ESS II, ESS III, ESS IV/JF, Pelaksana) ?>
    <div class="lg-item"><span class="lg-swatch" style="background:linear-gradient(135deg,#0f766e,#115e59)"></span> Sasaran 1</div>
    <div class="lg-item"><span class="lg-swatch" style="background:linear-gradient(135deg,#2563eb,#1e40af)"></span> Tujuan Renstra</div>
    <div class="lg-item"><span class="lg-swatch" style="background:linear-gradient(135deg,#c2410c,#9a3412)"></span> Sasaran 2</div>
    <div class="lg-item"><span class="lg-swatch" style="background:linear-gradient(135deg,#9333ea,#7e22ce)"></span> Sasaran 3</div>
    <div class="lg-item"><span class="lg-swatch" style="background:linear-gradient(135deg,#e11d48,#be123c)"></span> Sasaran 4</div>
    <?php // Pelaksana: oranye tua — beda jelas dari merah Eselon IV, tetap selaras palet ?>
    <div class="lg-item"><span class="lg-swatch" style="background:linear-gradient(135deg,#b45309,#92400e)"></span> Sasaran 5</div>
    <div class="lg-item"><span class="lg-swatch" style="background:#eef2f5;border:1px solid #dbe4de"></span> Indikator Kinerja</div>
    <?php if ($showCsf): ?>
        <div class="lg-item"><span class="lg-swatch" style="background:#faf3e6;border:1px solid #ecdcb8"></span> CSF</div>
    <?php endif; ?>
    <?php if ($showProgramPk): ?>
        <div class="lg-item"><span class="lg-swatch" style="background:#eef4ff;border:1px solid #c7d7fb"></span> Program PK</div>
        <div class="lg-item"><span class="lg-swatch" style="background:#f2fbf5;border:1px solid #c3e9d0"></span> Kegiatan PK</div>
    <?php endif; ?>
</div>

<div class="tree-container text-center">
    <div class="tree" id="tree-container">
        <ul>
            <?php foreach ($tree as $tujuanRpjmd): ?>
                <li>
                    <!-- L1: Tujuan RPJMD -->
                    <div class="tree-node">
                        <div class="box-l1">
                            <div class="node-label">Tujuan RPJMD</div>
                            <?= nl2br(esc($tujuanRpjmd['nama'])) ?>
                        </div>
                    </div>

                    <?php if (!empty($tujuanRpjmd['sasarans'])): ?>
                        <ul>
                            <?php foreach ($tujuanRpjmd['sasarans'] as $sasaranRpjmd): ?>
                                <li>
                                    <!-- L2: Sasaran RPJMD -->
                                    <div class="tree-node">
                                        <div class="box-l2">
                                            <div class="node-label">Sasaran 1</div>
                                            <?= nl2br(esc($sasaranRpjmd['nama'])) ?>
                                        </div>
                                    </div>

                                    <?php if (!empty($sasaranRpjmd['tujuan_renstras'])): ?>
                                        <ul>
                                            <?php foreach ($sasaranRpjmd['tujuan_renstras'] as $tujuanRenstra): ?>
                                                <li>
                                                    <!-- L3: Tujuan Renstra -->
                                                    <div class="tree-node">
                                                        <div class="box-l3">
                                                            <div class="node-label">Tujuan Renstra</div>
                                                            <?= nl2br(esc($tujuanRenstra['nama'])) ?>
                                                        </div>
                                                        <?php foreach (($tujuanRenstra['indikator_tujuan'] ?? []) as $indikatorTujuan): ?>
                                                            <div class="box-iks"><?php if ($showKode): ?><span class="ind-kode">IK</span><?php endif; ?><?= nl2br(esc($indikatorTujuan)) ?></div>
                                                        <?php endforeach; ?>
                                                    </div>

                                                    <?php if (!empty($tujuanRenstra['es2s'])): ?>
                                                        <ul>
                                                            <?php foreach ($tujuanRenstra['es2s'] as $es2): ?>
                                                                <li>
                                                                    <!-- L4: Sasaran ESS II -->
                                                                    <div class="tree-node">
                                                                        <?php if ($showCsf && !empty($es2['csf'])): ?>
                                                                            <div class="box-csf">
                                                                                <div class="node-label" style="opacity:.8">CSF</div>
                                                                                <?= nl2br(esc($es2['csf'])) ?>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                        <div class="box-es2">
                                                                            <div class="node-label">Sasaran 2</div>
                                                                            <?= nl2br(esc($es2['nama'])) ?>
                                                                        </div>
                                                                        <?php foreach ($es2['indikators'] as $indikatorEs2): ?>
                                                                            <div class="box-iks"><?php if ($showKode): ?><span class="ind-kode">IK</span><?php endif; ?><?= nl2br(esc($indikatorEs2)) ?></div>
                                                                        <?php endforeach; ?>
                                                                    </div>

                                                                    <?php if (!empty($es2['es3s'])): ?>
                                                                        <ul>
                                                                            <?php foreach ($es2['es3s'] as $es3): ?>
                                                                                <li>
                                                                                    <!-- L5: Sasaran ESS III -->
                                                                                    <div class="tree-node">
                                                                                        <?php if ($showCsf && !empty($es3['csf'])): ?>
                                                                                            <div class="box-csf">
                                                                                                <div class="node-label" style="opacity:.8">CSF</div>
                                                                                                <?= nl2br(esc($es3['csf'])) ?>
                                                                                            </div>
                                                                                        <?php endif; ?>
                                                                                        <div class="box-es3">
                                                                                            <div class="node-label">Sasaran 3</div>
                                                                                            <?= nl2br(esc($es3['nama'])) ?>
                                                                                        </div>
                                                                                        <?php foreach ($es3['indikators'] as $indikatorEs3): ?>
                                                                                            <div class="box-iks"><?php if ($showKode): ?><span class="ind-kode">IK</span><?php endif; ?><?= nl2br(esc($indikatorEs3)) ?></div>
                                                                                        <?php endforeach; ?>
                                                                                        <?php // Program PK + kegiatan di bawahnya. Program diturunkan DARI kegiatannya,
                                                                                             // jadi pasangan program-kegiatan selalu konsisten. Node yang teksnya tidak
                                                                                             // cocok dengan PK mana pun sengaja dibiarkan kosong. ?>
                                                                                        <?php if ($showProgramPk && !empty($es3['programs'])): ?>
                                                                                            <?php foreach ($es3['programs'] as $progEs3): ?>
                                                                                                <div class="box-prog"><?php if ($showKode): ?><span class="prog-kode">PRG</span><?php endif; ?><?= esc($progEs3['nama']) ?></div>
                                                                                                <?php foreach ($progEs3['kegiatan'] as $kegEs3): ?>
                                                                                                    <div class="box-keg"><?php if ($showKode): ?><span class="keg-kode">KEG</span><?php endif; ?><?= esc($kegEs3['nama']) ?></div>
                                                                                                <?php endforeach; ?>
                                                                                            <?php endforeach; ?>
                                                                                        <?php endif; ?>
                                                                                    </div>

                                                                                    <?php if (!empty($es3['es4s'])): ?>
                                                                                        <ul>
                                                                                            <?php foreach ($es3['es4s'] as $es4): ?>
                                                                                                <li>
                                                                                                    <!-- L6: Sasaran ESS IV -->
                                                                                                    <div class="tree-node">
                                                                                                        <?php if ($showCsf && !empty($es4['csf'])): ?>
                                                                                                            <div class="box-csf">
                                                                                                                <div class="node-label" style="opacity:.8">CSF</div>
                                                                                                                <?= nl2br(esc($es4['csf'])) ?>
                                                                                                            </div>
                                                                                                        <?php endif; ?>
                                                                                                        <div class="box-es4">
                                                                                                            <div class="node-label">Sasaran 4</div>
                                                                                                            <?= nl2br(esc($es4['nama'])) ?>
                                                                                                        </div>
                                                                                                        <?php foreach ($es4['indikators'] as $indikatorEs4): ?>
                                                                                                            <div class="box-iks"><?php if ($showKode): ?><span class="ind-kode">IK</span><?php endif; ?><?= nl2br(esc($indikatorEs4)) ?></div>
                                                                                                        <?php endforeach; ?>
                                                                                                    </div>

                                                                                                    <?php // L7: PELAKSANA — jenjang terakhir, di bawah Eselon IV / JF ?>
                                                                                                    <?php if (!empty($es4['pelaksanas'])): ?>
                                                                                                        <ul>
                                                                                                            <?php foreach ($es4['pelaksanas'] as $pel): ?>
                                                                                                                <li>
                                                                                                                    <div class="tree-node">
                                                                                                                        <?php if ($showCsf && !empty($pel['csf'])): ?>
                                                                                                                            <div class="box-csf">
                                                                                                                                <div class="node-label" style="opacity:.8">CSF</div>
                                                                                                                                <?= nl2br(esc($pel['csf'])) ?>
                                                                                                                            </div>
                                                                                                                        <?php endif; ?>
                                                                                                                        <div class="box-pelaksana">
                                                                                                                            <div class="node-label">Sasaran 5</div>
                                                                                                                            <?= nl2br(esc($pel['nama'])) ?>
                                                                                                                        </div>
                                                                                                                        <?php foreach ($pel['indikators'] as $indikatorPel): ?>
                                                                                                                            <div class="box-iks"><?php if ($showKode): ?><span class="ind-kode">IK</span><?php endif; ?><?= nl2br(esc($indikatorPel)) ?></div>
                                                                                                                        <?php endforeach; ?>
                                                                                                                    </div>
                                                                                                                </li>
                                                                                                            <?php endforeach; ?>
                                                                                                        </ul>
                                                                                                    <?php endif; ?>
                                                                                                </li>
                                                                                            <?php endforeach; ?>
                                                                                        </ul>
                                                                                    <?php endif; ?>
                                                                                </li>
                                                                            <?php endforeach; ?>
                                                                        </ul>
                                                                    <?php endif; ?>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
