<?php

namespace App\Services\Version;

use App\Models\Versi\ArsipVersiModel;
use App\Models\Versi\RenstraVersiModel;
use App\Models\Versi\RpjmdVersiModel;
use CodeIgniter\Database\ConnectionInterface;

/**
 * Pemeta modul -> arsip isinya.
 *
 * Registri versi (`dokumen_versi`) seragam untuk keempat dokumen, tetapi ISI
 * arsipnya tidak. Kelas ini satu-satunya tempat pemetaan itu ditulis, sehingga
 * service lain tidak perlu ber-`switch` sendiri-sendiri.
 *
 * ---------------------------------------------------------------------
 * IKU DAN LAKIP SENGAJA MENGEMBALIKAN null
 *
 * Keduanya SUDAH punya arsip yang bekerja dan teruji — `iku_revisi_*` dengan
 * IkuRevisiModel, dan `lakip_snapshot_*` dengan LakipSnapshotModel. Menulis
 * ulang keduanya ke dalam bentuk ArsipVersiModel berarti membuang kode yang
 * sudah jalan dan menanggung risiko regresi tanpa imbalan apa pun (§42).
 *
 * `dokumen_versi.ref_id` yang menautkan baris registri ke kepala arsip lama,
 * dan pemanggil yang menerima null tahu harus memakai model lamanya.
 * ---------------------------------------------------------------------
 */
class ArsipRegistry
{
    private ?ConnectionInterface $db;

    /** @var array<string,ArsipVersiModel|null> */
    private array $cache = [];

    public function __construct(?ConnectionInterface $db = null)
    {
        $this->db = $db;
    }

    /**
     * Arsip untuk sebuah modul, atau null bila modul itu memakai arsip lama.
     */
    public function untuk(string $modul): ?ArsipVersiModel
    {
        $modul = strtolower(trim($modul));

        if (array_key_exists($modul, $this->cache)) {
            return $this->cache[$modul];
        }

        switch ($modul) {
            case VersionScope::MODUL_RPJMD:
                return $this->cache[$modul] = new RpjmdVersiModel($this->db);

            case VersionScope::MODUL_RENSTRA:
                return $this->cache[$modul] = new RenstraVersiModel($this->db);

            default:
                // iku  -> App\Models\Opd\IkuRevisiModel
                // lakip-> App\Models\LakipSnapshotModel
                return $this->cache[$modul] = null;
        }
    }

    /** True bila modul ini punya arsip bergaya baru dan tabelnya sudah terpasang. */
    public function siap(string $modul): bool
    {
        $arsip = $this->untuk($modul);

        return $arsip !== null && $arsip->siap();
    }
}
