<?php

/**
 * Penutup cangkang halaman bersama. Pasangan templates/shell_atas.php.
 * Direktori template dipilih ulang dari role dengan aturan yang sama.
 */
$tpl = in_array(session()->get('role'), ['admin_opd', 'admin_kecamatan'], true)
    ? 'adminOpd'
    : 'adminKabupaten';
?>
            </div>
        </main>

        <?= $this->include($tpl . '/templates/footer.php'); ?>
    </div>
</body>

</html>
