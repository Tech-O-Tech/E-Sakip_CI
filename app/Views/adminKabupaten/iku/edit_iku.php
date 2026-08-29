<?php

/**
 * Sunting KETERANGAN indikator IKU Kabupaten.
 *
 * Isinya sengaja SATU berkas dengan layar OPD
 * (templates/iku/_edit_keterangan) supaya aturannya tidak bercabang: yang
 * boleh disunting hanya keterangan tiap indikator dan redaksi sasaran.
 *
 * Nama indikator, satuan, dan target datang dari RPJMD lewat sync, dan
 * perubahannya berjalan lewat Versi IKU supaya tercatat dan disahkan.
 * Pembatasannya bukan hanya di layar: `AdminKab\IkuController::update()`
 * hanya menulis kolom yang disebut namanya di sana.
 */
$title = $title ?? 'Edit IKU Kabupaten';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= esc($title) ?></title>
    <?= $this->include('adminKabupaten/templates/style.php'); ?>
</head>

<body class="bg-light min-vh-100 d-flex flex-column position-relative">
    <div id="main-content" class="content-wrapper d-flex flex-column" style="transition: margin-left .3s ease;">

        <?= $this->include('adminKabupaten/templates/header.php'); ?>
        <?= $this->include('adminKabupaten/templates/sidebar.php'); ?>

        <main class="flex-fill p-4 mt-2">
            <div class="mx-auto" style="width: 100%; max-width: 1200px;">
                <?php $this->setData([
                    'action_url'  => base_url('adminkab/iku/update'),
                    'back_url'    => base_url('adminkab/iku?mode=kabupaten'),
                    'labelSumber' => 'RPJMD',
                    'labelDampak' => 'kolom Sasaran pada Cascading Kabupaten',
                ]); ?>
                <?= $this->include('templates/iku/_edit_keterangan') ?>
            </div>
        </main>

        <?= $this->include('adminKabupaten/templates/footer.php'); ?>
    </div>
</body>

</html>
