<?php

namespace App\Models;

use CodeIgniter\Model;

class CascadingModel extends Model
{
    protected $db;
    protected $table = 'rpjmd_cascading';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'indikator_sasaran_id',
        'opd_id',
        'pk_program_id',
        'tahun'
    ];

    protected $useTimestamps = true;

    public function __construct()
    {
        parent::__construct();
        $this->db = \Config\Database::connect();
    }

    // =====================================================================
    // SUMBER TAMPILAN CASCADING KABUPATEN: IKU Kabupaten dulu, RPJMD sebagai
    // jaring pengaman. Kembaran SASARAN_ES2_SELECT dkk. pada cascading OPD.
    //
    // Nama kolom hasil TIDAK berubah (`sasaran_rpjmd`, `indikator_sasaran`,
    // `satuan`, `baseline`): view kabupaten, ekspor Excel, cetak, API, dan
    // analisis AI membacanya dengan nama itu.
    // =====================================================================

    private const SASARAN_KAB_SELECT   = "COALESCE(NULLIF(iks.sasaran, ''), s.sasaran_rpjmd)";
    private const INDIKATOR_KAB_SELECT = "COALESCE(NULLIF(iki.indikator, ''), i.indikator_sasaran)";
    private const SATUAN_KAB_SELECT    = "COALESCE(satiku.satuan, NULLIF(iki.satuan, ''), i.satuan)";
    private const BASELINE_KAB_SELECT  = "COALESCE(NULLIF(iki.baseline, ''), i.baseline)";

    public function getMatrix($start, $end)
    {
        // ==========================================================
        // 1. BACKBONE RPJMD: Misi -> Tujuan -> Sasaran -> Indikator
        //    Selalu tampil walau OPD belum di-mapping.
        //    (Tidak lagi memakai WHERE pada tabel LEFT JOIN yang
        //     dulu menyebabkan baris RPJMD tanpa mapping menghilang.)
        // ==========================================================
        // Server yang belum menjalankan db/update_2026-08-28_silsilah_iku_kabupaten.sql
        // tidak punya kolom jejaknya. Tanpa penjaga ini seluruh menu Cascading
        // Kabupaten mati dengan "Unknown column" — perilaku lamanya masih sah.
        $adaSilsilahIku = $this->db->fieldExists('source_indikator_id', 'iku_indikator');

        $sasaranKab   = $adaSilsilahIku ? self::SASARAN_KAB_SELECT   : 's.sasaran_rpjmd';
        $indikatorKab = $adaSilsilahIku ? self::INDIKATOR_KAB_SELECT : 'i.indikator_sasaran';
        $satuanKab    = $adaSilsilahIku ? self::SATUAN_KAB_SELECT    : 'i.satuan';
        $baselineKab  = $adaSilsilahIku ? self::BASELINE_KAB_SELECT  : 'i.baseline';
        $lineageKab   = $adaSilsilahIku ? 'iki.id' : 'NULL';

        $backboneQ = $this->db->table('rpjmd_misi m')
            ->select("
                m.id as misi_id,
                m.misi,

                t.id as tujuan_id,
                t.tujuan_rpjmd,

                s.id as sasaran_id,
                {$sasaranKab} as sasaran_rpjmd,
                s.csf,

                i.id as indikator_id,
                {$indikatorKab} as indikator_sasaran,
                {$satuanKab} as satuan,
                {$baselineKab} as baseline,
                {$lineageKab} as iku_indikator_id
            ", false)
            ->join('rpjmd_tujuan t', 't.misi_id = m.id', 'left')
            ->join('rpjmd_sasaran s', 's.tujuan_id = t.id', 'left')
            ->join('rpjmd_indikator_sasaran i', 'i.sasaran_id = s.id', 'left')
            ->where('m.tahun_mulai', (int) $start)
            ->where('m.tahun_akhir', (int) $end)
            ->orderBy('m.id', 'ASC')
            ->orderBy('t.id', 'ASC')
            ->orderBy('s.id', 'ASC')
            ->orderBy('i.id', 'ASC');

        // Jembatan ke IKU Kabupaten. Baris RPJMD yang punya padanan IKU
        // ditampilkan memakai teks & satuan IKU — dokumen itulah yang resmi
        // dinilai LAKIP. Yang tidak punya padanan tetap tampil apa adanya dari
        // RPJMD: IKU memang PILIHAN indikator utama, bukan salinan penuh.
        if ($adaSilsilahIku) {
            $backboneQ
                ->join(
                    'iku_indikator iki',
                    "iki.source_indikator_id = i.id AND iki.source_type = 'rpjmd'
                     AND iki.dihentikan_pada IS NULL",
                    'left',
                    false
                )
                ->join(
                    'iku_sasaran iks',
                    'iks.id = iki.iku_sasaran_id AND iks.opd_id IS NULL',
                    'left',
                    false
                )
                ->join(
                    'satuan satiku',
                    "satiku.id = iki.satuan AND iki.satuan REGEXP '^[0-9]+$'",
                    'left',
                    false
                );
        }

        $backbone = $backboneQ->get()->getResultArray();

        if (empty($backbone)) {
            return [];
        }

        // ==========================================================
        // 2-3b. Sumber data OPD & Program (helper bersama, dipakai juga
        //       oleh getPohonKinerja agar pohon SELARAS dengan cascading).
        // ==========================================================
        $opdBySasaran      = $this->opdBySasaranMap();              // sasaran_id => [opd_id => nama_opd]
        $manualByIndikator = $this->manualMappingMap($start, $end); // indikator_id => [opd_id => ['nama_opd','programs']]
        $programByOpd      = $this->programByOpdMap();              // opd_id => [program_kegiatan,...]

        // ==========================================================
        // 4. RAKIT BARIS FLAT untuk view
        //    Gabung OPD otomatis (renstra) + OPD mapping manual.
        //    Program: utamakan mapping manual, fallback ke PK otomatis.
        // ==========================================================
        $rows = [];

        foreach ($backbone as $b) {
            $sasaranId = $b['sasaran_id'];
            $indikatorId = $b['indikator_id'];

            // Gabungan OPD: dari renstra (otomatis) + dari mapping manual
            $opdSet = $opdBySasaran[$sasaranId] ?? [];
            foreach ($manualByIndikator[$indikatorId] ?? [] as $opdId => $info) {
                $opdSet[$opdId] = $info['nama_opd'];
            }

            // Indikator dianggap "mapped" bila ada mapping manual apa pun
            $isMapped = !empty($manualByIndikator[$indikatorId]) ? 1 : 0;

            asort($opdSet); // urutkan OPD berdasarkan nama

            if (empty($opdSet)) {
                // Tidak ada OPD sama sekali -> baris tetap tampil (kolom OPD kosong)
                $rows[] = $b + [
                    'nama_opd' => null,
                    'program_kegiatan' => null,
                    'is_mapped' => $isMapped,
                ];
                continue;
            }

            foreach ($opdSet as $opdId => $namaOpd) {
                // Program: utamakan mapping manual; jika tidak ada, pakai
                // program PK otomatis milik OPD tsb (hybrid).
                $manualPrograms = $manualByIndikator[$indikatorId][$opdId]['programs'] ?? [];
                $programs = !empty($manualPrograms)
                    ? $manualPrograms
                    : ($programByOpd[$opdId] ?? []);

                if (empty($programs)) {
                    // OPD muncul otomatis tapi tidak punya program (manual maupun PK)
                    $rows[] = $b + [
                        'nama_opd' => $namaOpd,
                        'program_kegiatan' => null,
                        'is_mapped' => $isMapped,
                    ];
                } else {
                    foreach ($programs as $prog) {
                        $rows[] = $b + [
                            'nama_opd' => $namaOpd,
                            'program_kegiatan' => $prog,
                            'is_mapped' => $isMapped,
                        ];
                    }
                }
            }
        }

        // ==========================================================
        // 5. AMBIL & ATTACH TARGET per indikator
        // ==========================================================
        $indikatorIds = array_values(array_unique(array_filter(array_column($rows, 'indikator_id'))));

        if (empty($indikatorIds)) {
            return $rows;
        }

        $targets = $this->db->table('rpjmd_target')
            ->select('indikator_sasaran_id, tahun, target_tahunan')
            ->whereIn('indikator_sasaran_id', $indikatorIds)
            ->get()
            ->getResultArray();

        $targetMap = [];
        foreach ($targets as $t) {
            $targetMap[$t['indikator_sasaran_id']][$t['tahun']] = $t['target_tahunan'];
        }

        // Target IKU menang atas target RPJMD untuk baris yang berjembatan:
        // percuma menampilkan teks indikator dari IKU tetapi angkanya dari
        // RPJMD — satu baris jadi memuat dua dokumen sekaligus.
        $targetIku = [];
        $idIku     = array_values(array_unique(array_filter(array_column($rows, 'iku_indikator_id'))));

        if ($idIku !== [] && $this->db->tableExists('iku_target')) {
            foreach ($this->db->table('iku_target')
                ->select('iku_indikator_id, tahun, target')
                ->whereIn('iku_indikator_id', $idIku)
                ->get()->getResultArray() as $t) {
                $targetIku[(int) $t['iku_indikator_id']][$t['tahun']] = $t['target'];
            }
        }

        foreach ($rows as &$r) {
            $ikuId = (int) ($r['iku_indikator_id'] ?? 0);

            $r['targets'] = $ikuId > 0 && ! empty($targetIku[$ikuId])
                ? $targetIku[$ikuId]
                : ($targetMap[$r['indikator_id']] ?? []);
        }
        unset($r);

        return $rows;
    }

    /**
     * OPD per sasaran RPJMD, ditarik otomatis dari rantai Renstra.
     * renstra_tujuan.rpjmd_sasaran_id -> rpjmd_sasaran.id ;
     * OPD diambil dari renstra_sasaran.opd_id.
     * @return array sasaran_id => [ opd_id => nama_opd ]
     */
    private function opdBySasaranMap(): array
    {
        $rows = $this->db->table('renstra_tujuan rt')
            ->select('rt.rpjmd_sasaran_id as sasaran_id, rs.opd_id, o.nama_opd')
            ->join('renstra_sasaran rs', 'rs.renstra_tujuan_id = rt.id', 'inner')
            ->join('opd o', 'o.id = rs.opd_id', 'inner')
            ->where('rt.rpjmd_sasaran_id IS NOT NULL')
            ->groupBy('rt.rpjmd_sasaran_id, rs.opd_id, o.nama_opd')
            ->orderBy('o.nama_opd', 'ASC')
            ->get()
            ->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['sasaran_id']][$row['opd_id']] = $row['nama_opd'];
        }
        return $map;
    }

    /**
     * Program JPT per OPD, ditarik otomatis dari rantai PK (Perjanjian Kinerja).
     * program_pk.opd_id NULL, jadi OPD hanya bisa dijangkau lewat tabel pk.
     * Diambil program tahun PK TERBARU per OPD.
     * @return array opd_id => [ program_kegiatan, ... ]
     */
    private function programByOpdMap(): array
    {
        $rows = $this->db->table('pk')
            ->select('pk.opd_id, pk.tahun, p.program_kegiatan')
            ->join('pk_sasaran ps', 'ps.pk_id = pk.id', 'inner')
            ->join('pk_indikator pi', 'pi.pk_sasaran_id = ps.id', 'inner')
            ->join('pk_program pp', 'pp.pk_indikator_id = pi.id', 'inner')
            ->join('program_pk p', 'p.id = pp.program_id', 'inner')
            ->where('pi.jenis', 'jpt')
            ->orderBy('pk.opd_id', 'ASC')
            ->orderBy('pk.tahun', 'DESC')
            ->get()
            ->getResultArray();

        $byOpd  = [];
        $latest = [];
        foreach ($rows as $row) {
            $opd = $row['opd_id'];
            $th  = (int) $row['tahun'];
            if (!isset($latest[$opd])) {
                $latest[$opd] = $th; // baris terurut tahun DESC -> pertama = terbaru
            }
            if ($th !== $latest[$opd]) {
                continue;
            }
            $prog = $row['program_kegiatan'];
            if ($prog !== null && $prog !== '' && !in_array($prog, $byOpd[$opd] ?? [], true)) {
                $byOpd[$opd][] = $prog;
            }
        }
        return $byOpd;
    }

    /**
     * Mapping manual cascading (rpjmd_cascading) untuk satu periode.
     * @return array indikator_id => [ opd_id => ['nama_opd' => ..., 'programs' => [...] ] ]
     */
    private function manualMappingMap($start, $end): array
    {
        $rows = $this->db->table('rpjmd_cascading map')
            ->select('map.indikator_sasaran_id, map.opd_id, o.nama_opd, p.program_kegiatan')
            ->join('pk_program pp', 'pp.id = map.pk_program_id', 'left')
            ->join('program_pk p', 'p.id = pp.program_id', 'left')
            ->join('opd o', 'o.id = map.opd_id', 'left')
            ->where('map.tahun >=', (int) $start)
            ->where('map.tahun <=', (int) $end)
            ->get()
            ->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $ind = $row['indikator_sasaran_id'];
            $opd = $row['opd_id'];
            if (!isset($map[$ind][$opd])) {
                $map[$ind][$opd] = ['nama_opd' => $row['nama_opd'], 'programs' => []];
            }
            if (!empty($row['program_kegiatan'])) {
                $map[$ind][$opd]['programs'][] = $row['program_kegiatan'];
            }
        }
        return $map;
    }

    public function getPkProgramByOpd($opdId, $tahun)
    {
        return $this->db->table('pk_program pp')
            ->select('MIN(pp.id) as id, p.program_kegiatan')
            ->join('pk_indikator pi', 'pi.id = pp.pk_indikator_id')
            ->join('pk_sasaran ps', 'ps.id = pi.pk_sasaran_id')
            ->join('pk pk', 'pk.id = ps.pk_id')
            ->join('program_pk p', 'p.id = pp.program_id')
            ->where('pk.opd_id', $opdId)
            ->where('pk.tahun', $tahun)
            ->where('pi.jenis', 'jpt')
            ->groupBy('pp.program_id')
            ->orderBy('p.program_kegiatan', 'ASC')
            ->get()
            ->getResultArray();
    }
    public function saveBatchMapping(array $data)
    {
        if (empty($data))
            return false;

        return $this->db->table($this->table)
            ->ignore(true)
            ->insertBatch($data);
    }

    public function isProgramBelongsToOpd($programId, $opdId)
    {
        return $this->db->table('pk_program pr')
            ->join('pk_indikator i', 'i.id = pr.pk_indikator_id')
            ->join('pk_sasaran s', 's.id = i.pk_sasaran_id')
            ->join('pk p', 'p.id = s.pk_id')
            ->where('pr.id', $programId)
            ->where('p.opd_id', $opdId)
            ->countAllResults() > 0;
    }

    public function getExistingMapping($indikatorId, $tahun)
    {
        return $this->db->table('rpjmd_cascading c')
            ->select('c.opd_id, c.pk_program_id')
            ->where('c.indikator_sasaran_id', $indikatorId)
            ->where('c.tahun', $tahun)
            ->get()
            ->getResultArray();
    }

    public function deleteByIndikatorAndYear($indikatorId, $tahun)
    {
        return $this->db->table($this->table)
            ->where('indikator_sasaran_id', $indikatorId)
            ->where('tahun', $tahun)
            ->delete();
    }

    public function getPdfMatrix($start, $end)
    {
        $rows = $this->db->table('rpjmd_indikator_sasaran i')
            ->select("
            i.id as indikator_id,
            i.indikator_sasaran,
            i.satuan,
            i.baseline,

            map.opd_id,
            o.nama_opd,

            p.program_kegiatan
        ")
            ->join('rpjmd_cascading map', 'map.indikator_sasaran_id = i.id', 'left')
            ->join('pk_program pp', 'pp.id = map.pk_program_id', 'left')
            ->join('program_pk p', 'p.id = pp.program_id', 'left')
            ->join('opd o', 'o.id = map.opd_id', 'left')
            ->where('map.tahun >=', (int) $start)
            ->where('map.tahun <=', (int) $end)
            ->orderBy('i.id')
            ->orderBy('o.nama_opd')
            ->get()
            ->getResultArray();

        $grouped = [];

        foreach ($rows as $r) {

            $indikator = $r['indikator_id'];
            $opd = $r['opd_id'];

            if (!isset($grouped[$indikator])) {
                $grouped[$indikator] = [
                    'indikator' => $r['indikator_sasaran'],
                    'satuan' => $r['satuan'],
                    'baseline' => $r['baseline'],
                    'opd' => []
                ];
            }

            if (!isset($grouped[$indikator]['opd'][$opd])) {
                $grouped[$indikator]['opd'][$opd] = [
                    'nama_opd' => $r['nama_opd'],
                    'program' => []
                ];
            }

            if ($r['program_kegiatan']) {
                $grouped[$indikator]['opd'][$opd]['program'][] =
                    $r['program_kegiatan'];
            }
        }

        return $grouped;
    }

    // adminopd
    // =====================================================================
    // SUMBER TAMPILAN ESELON II: IKU dulu, Renstra sebagai jaring pengaman.
    //
    // Sejak db/update_2026-08-27_cascading_sumber_iku.sql, baris cascading
    // boleh berjangkar ke IKU lewat `iku_indikator_id`. Bila jangkar itu
    // terisi, teks yang DITAMPILKAN diambil dari IKU — sehingga revisi IKU
    // langsung terbaca di cascading tanpa menunggu Renstra ikut diubah.
    // Bila kosong (belum dipetakan, atau OPD-nya memang belum selaras),
    // COALESCE jatuh ke Renstra dan tampilannya persis seperti sebelumnya.
    //
    // Nama kolom hasil sengaja TIDAK diubah (`renstra_sasaran`,
    // `indikator_sasaran`, `satuan`): 20+ pemanggil — view OPD, view
    // Kabupaten, ekspor Excel, cetak, API, dan analisis AI — membacanya
    // dengan nama itu.
    // =====================================================================

    /** `iku_indikator.satuan` menyimpan id numerik ke `satuan`, atau teks bebas. */
    private const SATUAN_JOIN_IKU = "siku.id = iki.satuan AND iki.satuan REGEXP '^[0-9]+$'";

    private const SASARAN_ES2_SELECT    = "COALESCE(NULLIF(iks.sasaran, ''), rs.sasaran)";
    private const INDIKATOR_ES2_SELECT  = "COALESCE(NULLIF(iki.indikator, ''), ris.indikator_sasaran)";
    private const SATUAN_ES2_SELECT     = "COALESCE(siku.satuan, NULLIF(iki.satuan, ''), ris.satuan)";

    /**
     * Padanan indikator IKU untuk sebuah indikator sasaran Renstra.
     *
     * Dipakai saat baris cascading BARU dibuat, supaya baris itu langsung
     * berjangkar ganda seperti hasil backfill migrasi — bukan lahir hanya
     * berjangkar Renstra lalu ikut mati bila indikator Renstra-nya dihapus.
     *
     * Silsilah didahulukan, teks belakangan: `source_indikator_id` ditulis
     * oleh Sync IKU dan menunjuk id, jadi ia tetap benar walau redaksinya
     * kemudian dirapikan. Pencocokan teks hanya jaring terakhir untuk baris
     * IKU yang diketik manual dan belum punya silsilah.
     *
     * @return int|null id `iku_indikator`, atau null bila padanannya tidak
     *                  tunggal — menebak lebih buruk daripada membiarkan
     *                  baris itu tetap membaca Renstra.
     */
    public function padananIkuIndikator($renstraIndikatorId): ?int
    {
        $renstraIndikatorId = (int) $renstraIndikatorId;

        if ($renstraIndikatorId <= 0
            || ! $this->db->fieldExists('source_indikator_id', 'iku_indikator')) {
            return null;
        }

        $punyaPensiun = $this->db->fieldExists('dihentikan_pada', 'iku_indikator');

        // 1. Lewat silsilah.
        $b = $this->db->table('iku_indikator')
            ->select('id')
            ->where('source_indikator_id', $renstraIndikatorId);

        if ($punyaPensiun) {
            $b->where('dihentikan_pada IS NULL', null, false);
        }

        $baris = $b->limit(2)->get()->getResultArray();

        if (count($baris) === 1) {
            return (int) $baris[0]['id'];
        }

        if ($baris !== []) {
            return null; // silsilah ganda: jangan menebak
        }

        // 2. Jaring terakhir: cocokkan teks, aturan sama persis dengan
        //    IkuModel::normalkanTeks() dan migrasi 2026-08-27.
        $rapikan = static fn (string $kolom): string
            => "TRIM(REGEXP_REPLACE({$kolom}, '[[:space:]]+', ' '))";

        $b = $this->db->table('renstra_indikator_sasaran ris')
            ->select('iki.id', false)
            ->join('renstra_sasaran rs', 'rs.id = ris.renstra_sasaran_id')
            ->join(
                'iku_sasaran iks',
                'iks.opd_id = rs.opd_id
                 AND iks.tahun_mulai = rs.tahun_mulai
                 AND iks.tahun_akhir = rs.tahun_akhir
                 AND ' . $rapikan('iks.sasaran') . ' = ' . $rapikan('rs.sasaran'),
                'inner',
                false
            )
            ->join(
                'iku_indikator iki',
                'iki.iku_sasaran_id = iks.id
                 AND ' . $rapikan('iki.indikator') . ' = ' . $rapikan('ris.indikator_sasaran'),
                'inner',
                false
            )
            ->where('ris.id', $renstraIndikatorId);

        if ($punyaPensiun) {
            $b->where('iki.dihentikan_pada IS NULL', null, false)
                ->where('iks.dihentikan_pada IS NULL', null, false);
        }

        $baris = $b->limit(2)->get()->getResultArray();

        return count($baris) === 1 ? (int) $baris[0]['id'] : null;
    }

    public function getCascadingMatrixByOpd($opdId, $startYear = null, $endYear = null)
    {
        // Hindari query rusak (ON clause "opd_id = NULL") bila OPD tidak diketahui,
        // mis. akun super admin yang tidak terikat OPD.
        if (empty($opdId)) {
            return [];
        }

        // Server yang belum menjalankan migrasi 2026-08-27 tidak punya kolom
        // jangkar IKU. Tanpa penjaga ini, seluruh menu Cascading mati dengan
        // "Unknown column" — padahal perilaku lamanya masih sah sepenuhnya.
        $adaJangkarIku = $this->db->fieldExists('iku_indikator_id', 'cascading_sasaran_opd');

        $sasaranEs2   = $adaJangkarIku ? self::SASARAN_ES2_SELECT   : 'rs.sasaran';
        $indikatorEs2 = $adaJangkarIku ? self::INDIKATOR_ES2_SELECT : 'ris.indikator_sasaran';
        $satuanEs2    = $adaJangkarIku ? self::SATUAN_ES2_SELECT    : 'ris.satuan';
        $lineageEs2   = $adaJangkarIku
            ? 'es3.source_type as es2_source_type, es3.iku_indikator_id as es2_iku_indikator_id,'
            : "'renstra' as es2_source_type, NULL as es2_iku_indikator_id,";

        $builder = $this->db->table('renstra_sasaran rs')
            ->select("
            t.id as tujuan_id,
            t.tujuan_rpjmd,

            s.id as sasaran_id,
            s.sasaran_rpjmd,

            rt.id as renstra_tujuan_id,
            rt.tujuan as renstra_tujuan,

            rit.id as indikator_tujuan_id,
            rit.indikator_tujuan,

            rs.csf as csf_es2,
            rs.id as renstra_sasaran_id,
            {$sasaranEs2} as renstra_sasaran,

            ris.id as indikator_id,
            {$indikatorEs2} as indikator_sasaran,
            {$satuanEs2} as satuan,

            {$lineageEs2}

            es3.csf as csf_es3,
            es3.id as es3_id,
            es3.nama_sasaran as es3_sasaran,

            i3.id as es3_indikator_id,
            i3.indikator as es3_indikator,

            es4.csf as csf_es4,
            es4.id as es4_id,
            es4.nama_sasaran as es4_sasaran,

            i4.id as es4_indikator_id,
            i4.indikator as es4_indikator,

            pel.csf as csf_pelaksana,
            pel.id as pelaksana_id,
            pel.nama_sasaran as pelaksana_sasaran,

            ipel.id as pelaksana_indikator_id,
            ipel.indikator as pelaksana_indikator
        ")
            ->join('renstra_tujuan rt', 'rt.id=rs.renstra_tujuan_id', 'left')
            ->join('renstra_indikator_tujuan rit', 'rit.tujuan_id=rt.id', 'left')
            ->join('rpjmd_sasaran s', 's.id=rt.rpjmd_sasaran_id', 'left')
            ->join('rpjmd_tujuan t', 't.id=s.tujuan_id', 'left')
            ->join('renstra_indikator_sasaran ris', 'ris.renstra_sasaran_id=rs.id', 'left')
            ->join(
                'cascading_sasaran_opd es3',
                'es3.renstra_indikator_sasaran_id = ris.id 
            AND es3.level="es3" 
            AND es3.opd_id=' . $this->db->escape($opdId),
                'left'
            )
            ->join(
                'cascading_indikator_opd i3',
                'i3.cascading_sasaran_id = es3.id',
                'left'
            )
            ->join(
                'cascading_sasaran_opd es4',
                'es4.es3_indikator_id = i3.id AND es4.level="es4"',
                'left'
            )
            ->join(
                'cascading_indikator_opd i4',
                'i4.cascading_sasaran_id = es4.id',
                'left'
            )
            // Jenjang PELAKSANA: pola yang sama persis dengan es4, satu tingkat
            // lebih dalam. `es3_indikator_id` di sini berisi id indikator ES IV
            // (kolomnya memang bermakna "indikator induk", lihat migrasi
            // 2026-07-27-000009).
            ->join(
                'cascading_sasaran_opd pel',
                'pel.es3_indikator_id = i4.id AND pel.level="pelaksana"',
                'left'
            )
            ->join(
                'cascading_indikator_opd ipel',
                'ipel.cascading_sasaran_id = pel.id',
                'left'
            )
            ->where('rs.opd_id', $opdId);

        // Jangkar IKU baris ES III — menentukan teks Eselon II yang tampil.
        // Ditambahkan belakangan dengan sengaja: alias `es3` sudah terpasang
        // di atas, dan MySQL hanya menuntut tabel yang diacu ON sudah lebih
        // dulu ada di urutan FROM, bukan tepat sebelumnya.
        if ($adaJangkarIku) {
            $builder->join('iku_indikator iki', 'iki.id = es3.iku_indikator_id', 'left')
                ->join('iku_sasaran iks', 'iks.id = iki.iku_sasaran_id', 'left')
                ->join('satuan siku', self::SATUAN_JOIN_IKU, 'left', false);
        }

        if ($startYear && $endYear) {
            $builder->where('rs.tahun_mulai', $startYear);
            $builder->where('rs.tahun_akhir', $endYear);
        }

        $rows = $builder
            ->orderBy('t.id', 'ASC')
            ->orderBy('s.id', 'ASC')
            ->orderBy('rt.id', 'ASC')
            ->orderBy('rs.id', 'ASC')
            ->orderBy('ris.id', 'ASC')
            ->orderBy('es3.id', 'ASC')
            ->orderBy('i3.id', 'ASC')
            ->orderBy('es4.id', 'ASC')
            ->orderBy('i4.id', 'ASC')
            ->orderBy('pel.id', 'ASC')
            ->orderBy('ipel.id', 'ASC')
            ->orderBy('rit.id', 'ASC')
            ->get()
            ->getResultArray();

        return $this->alignIndikatorTujuanRows($rows);
    }

    private function alignIndikatorTujuanRows(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $groups = [];
        $groupOrder = [];

        foreach ($rows as $row) {
            $groupKey = !empty($row['renstra_tujuan_id'])
                ? 'rt_' . $row['renstra_tujuan_id']
                : 'row_' . $this->cascadeMatrixRowKey($row);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'base' => $row,
                    'indikator_tujuan' => [],
                    'cascade_rows' => [],
                ];
                $groupOrder[] = $groupKey;
            }

            if (!empty($row['indikator_tujuan_id'])) {
                $indikatorKey = (string) $row['indikator_tujuan_id'];
                $groups[$groupKey]['indikator_tujuan'][$indikatorKey] = [
                    'indikator_tujuan_id' => $row['indikator_tujuan_id'],
                    'indikator_tujuan' => $row['indikator_tujuan'],
                ];
            }

            $cascadeKey = $this->cascadeMatrixRowKey($row);
            if (!isset($groups[$groupKey]['cascade_rows'][$cascadeKey])) {
                $cascadeRow = $row;
                $cascadeRow['indikator_tujuan_id'] = null;
                $cascadeRow['indikator_tujuan'] = null;
                $groups[$groupKey]['cascade_rows'][$cascadeKey] = $cascadeRow;
            }
        }

        $aligned = [];

        foreach ($groupOrder as $groupKey) {
            $group = $groups[$groupKey];
            $indikatorTujuan = array_values($group['indikator_tujuan']);
            $cascadeRows = array_values($group['cascade_rows']);
            $totalRows = max(count($indikatorTujuan), count($cascadeRows), 1);
            $indikatorTujuanCount = count($indikatorTujuan);

            for ($i = 0; $i < $totalRows; $i++) {
                $row = $cascadeRows[$i] ?? $this->blankCascadeMatrixRow($group['base']);

                if ($indikatorTujuanCount > 0) {
                    $indikatorIndex = min(
                        $indikatorTujuanCount - 1,
                        (int) floor($i * $indikatorTujuanCount / $totalRows)
                    );

                    $row['indikator_tujuan_id'] = $indikatorTujuan[$indikatorIndex]['indikator_tujuan_id'];
                    $row['indikator_tujuan'] = $indikatorTujuan[$indikatorIndex]['indikator_tujuan'];
                } else {
                    $row['indikator_tujuan_id'] = null;
                    $row['indikator_tujuan'] = null;
                }

                $aligned[] = $row;
            }
        }

        return $aligned;
    }

    private function cascadeMatrixRowKey(array $row): string
    {
        $fields = [
            'renstra_sasaran_id',
            'indikator_id',
            'es3_id',
            'es3_indikator_id',
            'es4_id',
            'es4_indikator_id',
            'pelaksana_id',
            'pelaksana_indikator_id',
        ];

        $parts = [];
        foreach ($fields as $field) {
            $parts[] = (string) ($row[$field] ?? '');
        }

        return implode('|', $parts);
    }

    private function blankCascadeMatrixRow(array $base): array
    {
        foreach ([
            'csf_es2',
            'renstra_sasaran_id',
            'renstra_sasaran',
            'indikator_id',
            'indikator_sasaran',
            'satuan',
            'csf_es3',
            'es3_id',
            'es3_sasaran',
            'es3_indikator_id',
            'es3_indikator',
            'csf_es4',
            'es4_id',
            'es4_sasaran',
            'es4_indikator_id',
            'es4_indikator',
            'csf_pelaksana',
            'pelaksana_id',
            'pelaksana_sasaran',
            'pelaksana_indikator_id',
            'pelaksana_indikator',
        ] as $field) {
            $base[$field] = null;
        }

        return $base;
    }
    public function getCascadingTree($renstraIndikatorId, $opdId)
    {
        return $this->db->table('cascading_sasaran_opd')
            ->where('renstra_indikator_sasaran_id', $renstraIndikatorId)
            ->where('opd_id', $opdId)
            ->orderBy('level', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function insertSasaran($data)
    {
        $this->db->table('cascading_sasaran_opd')
            ->insert($data);

        return $this->db->insertID();
    }
    public function insertIndikator($data)
    {
        return $this->db->table('cascading_indikator_opd')
            ->insert($data);
    }
    public function getIndikatorBySasaran($sasaranId)
    {
        return $this->db->table('cascading_indikator_opd')
            ->where('cascading_sasaran_id', $sasaranId)
            ->get()
            ->getResultArray();
    }
    public function getRenstraHierarchyByOpd($opdId)
    {
        return $this->db->table('rpjmd_tujuan t')
            ->select("
            t.id as rpjmd_tujuan_id,
            t.tujuan_rpjmd,

            s.id as rpjmd_sasaran_id,
            s.sasaran_rpjmd,

            rt.id as renstra_tujuan_id,
            rt.tujuan as renstra_tujuan,

            rs.id as renstra_sasaran_id,
            rs.sasaran as renstra_sasaran,

            ris.id as indikator_id,
            ris.indikator_sasaran,
            ris.satuan
        ")

            ->join('rpjmd_sasaran s', 's.tujuan_id = t.id', 'left')

            ->join(
                'renstra_tujuan rt',
                'rt.rpjmd_sasaran_id = s.id',
                'left'
            )

            ->join(
                'renstra_sasaran rs',
                'rs.renstra_tujuan_id = rt.id',
                'left'
            )

            ->join(
                'renstra_indikator_sasaran ris',
                'ris.renstra_sasaran_id = rs.id',
                'left'
            )

            ->where('rs.opd_id', $opdId)

            ->orderBy('t.id', 'ASC')
            ->orderBy('s.id', 'ASC')
            ->orderBy('rt.id', 'ASC')
            ->orderBy('rs.id', 'ASC')
            ->orderBy('ris.id', 'ASC')

            ->get()
            ->getResultArray();
    }


    public function getRenstraByOpd($opdId)
    {
        return $this->db->table('rpjmd_tujuan t')
            ->select("
            t.id as tujuan_id,
            t.tujuan_rpjmd,

            s.id as sasaran_id,
            s.sasaran_rpjmd,

            rt.id as renstra_tujuan_id,
            rt.tujuan as renstra_tujuan,

            rs.id as renstra_sasaran_id,
            rs.sasaran as renstra_sasaran,

            ris.id as indikator_id,
            ris.indikator_sasaran,
            ris.satuan
        ")

            ->join('rpjmd_sasaran s', 's.tujuan_id = t.id', 'left')

            ->join(
                'renstra_tujuan rt',
                'rt.rpjmd_sasaran_id = s.id',
                'left'
            )

            ->join(
                'renstra_sasaran rs',
                'rs.renstra_tujuan_id = rt.id',
                'left'
            )

            ->join(
                'renstra_indikator_sasaran ris',
                'ris.renstra_sasaran_id = rs.id',
                'left'
            )

            ->where('rs.opd_id', $opdId)

            ->orderBy('t.id', 'ASC')
            ->orderBy('s.id', 'ASC')
            ->orderBy('rt.id', 'ASC')
            ->orderBy('rs.id', 'ASC')
            ->orderBy('ris.id', 'ASC')

            ->get()
            ->getResultArray();
    }


    /**
     * Get hierarchical tree data for Pohon Kinerja PDF
     * Misi → Tujuan → Indikator Tujuan + CSF → Sasaran → Indikator Sasaran
     */
    public function getPohonKinerja($tahunMulai, $tahunAkhir)
    {
        // 1. Get all Misi for the period
        $misiList = $this->db->table('rpjmd_misi')
            ->where('tahun_mulai', $tahunMulai)
            ->where('tahun_akhir', $tahunAkhir)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        if (empty($misiList)) {
            return [];
        }

        $misiIds = array_column($misiList, 'id');

        // 2. Get all Tujuan for these Misi
        $tujuanList = $this->db->table('rpjmd_tujuan')
            ->whereIn('misi_id', $misiIds)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $tujuanIds = array_column($tujuanList, 'id');

        // 3. Get all Indikator Tujuan and Sasaran for these Tujuan
        $indikatorTujuanList = [];
        $sasaranList = [];
        $sasaranIds = [];
        
        if (!empty($tujuanIds)) {
            $indikatorTujuanList = $this->db->table('rpjmd_indikator_tujuan')
                ->whereIn('tujuan_id', $tujuanIds)
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();

            $sasaranList = $this->db->table('rpjmd_sasaran')
                ->whereIn('tujuan_id', $tujuanIds)
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();

            $sasaranIds = array_column($sasaranList, 'id');
        }

        // 4. Get all Indikator Sasaran for these Sasaran
        $indikatorSasaranList = [];
        if (!empty($sasaranIds)) {
            $indikatorSasaranList = $this->db->table('rpjmd_indikator_sasaran')
                ->whereIn('sasaran_id', $sasaranIds)
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();
        }

        // --- SUMBER OPD & PROGRAM (logika identik dengan cascading getMatrix) ---
        $opdBySasaran      = $this->opdBySasaranMap();
        $manualByIndikator = $this->manualMappingMap($tahunMulai, $tahunAkhir);
        $programByOpd      = $this->programByOpdMap();

        // --- GROUPING IN MEMORY ---

        // Group Indikator Sasaran by sasaran_id
        $groupedIndikatorSasaran = [];
        foreach ($indikatorSasaranList as $indSas) {
            $groupedIndikatorSasaran[$indSas['sasaran_id']][] = $indSas;
        }

        // Group Sasaran by tujuan_id + lampirkan Indikator Sasaran & cabang OPD/Program
        $groupedSasaran = [];
        foreach ($sasaranList as $sasaran) {
            $sid = $sasaran['id'];
            $indikatorSasaran = $groupedIndikatorSasaran[$sid] ?? [];
            $indIds = array_column($indikatorSasaran, 'id');

            // OPD: otomatis dari Renstra + union OPD dari mapping manual indikator sasaran ini
            $opdSet = $opdBySasaran[$sid] ?? [];
            foreach ($indIds as $ind) {
                foreach ($manualByIndikator[$ind] ?? [] as $opdId => $info) {
                    $opdSet[$opdId] = $info['nama_opd'];
                }
            }
            asort($opdSet);

            // Program per OPD: utamakan mapping manual (gabungan indikator sasaran ini),
            // jika tidak ada pakai program PK otomatis (hybrid, sama seperti cascading).
            $opdNodes = [];
            foreach ($opdSet as $opdId => $namaOpd) {
                $manualProgs = [];
                foreach ($indIds as $ind) {
                    foreach ($manualByIndikator[$ind][$opdId]['programs'] ?? [] as $pg) {
                        if (!in_array($pg, $manualProgs, true)) {
                            $manualProgs[] = $pg;
                        }
                    }
                }
                $opdNodes[] = [
                    'nama_opd' => $namaOpd,
                    'programs' => !empty($manualProgs) ? $manualProgs : ($programByOpd[$opdId] ?? []),
                ];
            }

            $groupedSasaran[$sasaran['tujuan_id']][] = [
                'id' => $sid,
                'sasaran_rpjmd' => $sasaran['sasaran_rpjmd'],
                'csf' => $sasaran['csf'] ?? '',
                'indikator_sasaran' => $indikatorSasaran,
                'opd' => $opdNodes,
            ];
        }

        // Group Indikator Tujuan by tujuan_id
        $groupedIndikatorTujuan = [];
        foreach ($indikatorTujuanList as $indTuj) {
            $groupedIndikatorTujuan[$indTuj['tujuan_id']][] = $indTuj;
        }

        // Group Tujuan by misi_id and attach Sasaran & Indikator Tujuan
        $groupedTujuan = [];
        foreach ($tujuanList as $tujuan) {
            $tujuanNode = [
                'id' => $tujuan['id'],
                'tujuan_rpjmd' => $tujuan['tujuan_rpjmd'],
                'indikator_tujuan' => $groupedIndikatorTujuan[$tujuan['id']] ?? [],
                'sasaran' => $groupedSasaran[$tujuan['id']] ?? []
            ];
            $groupedTujuan[$tujuan['misi_id']][] = $tujuanNode;
        }

        // Assemble final tree
        $tree = [];
        foreach ($misiList as $misi) {
            $misiNode = [
                'id' => $misi['id'],
                'misi' => $misi['misi'],
                'tujuan' => $groupedTujuan[$misi['id']] ?? []
            ];
            $tree[] = $misiNode;
        }

        return $tree;
    }

    // =====================================================================
    // MODE 1 — KABUPATEN: backbone RPJMD sampai Indikator Sasaran (tanpa OPD)
    // Satu baris per indikator (atau per sasaran bila belum ada indikator).
    // =====================================================================
    public function getRpjmdMatrix($start, $end)
    {
        $backbone = $this->db->table('rpjmd_misi m')
            ->select("
                t.id as tujuan_id,
                t.tujuan_rpjmd,

                s.id as sasaran_id,
                s.sasaran_rpjmd,
                s.csf,

                i.id as indikator_id,
                i.indikator_sasaran,
                i.satuan,
                i.baseline
            ", false)
            ->join('rpjmd_tujuan t', 't.misi_id = m.id', 'left')
            ->join('rpjmd_sasaran s', 's.tujuan_id = t.id', 'left')
            ->join('rpjmd_indikator_sasaran i', 'i.sasaran_id = s.id', 'left')
            ->where('m.tahun_mulai', (int) $start)
            ->where('m.tahun_akhir', (int) $end)
            ->orderBy('t.id', 'ASC')
            ->orderBy('s.id', 'ASC')
            ->orderBy('i.id', 'ASC')
            ->get()
            ->getResultArray();

        if (empty($backbone)) {
            return [];
        }

        // Tandai indikator yang sudah punya mapping manual (untuk tombol Aksi)
        $manual = $this->manualMappingMap($start, $end);

        $rows = [];
        foreach ($backbone as $b) {
            $rows[] = $b + [
                'is_mapped' => !empty($manual[$b['indikator_id']]) ? 1 : 0,
            ];
        }

        // Target per tahun
        $indikatorIds = array_values(array_unique(array_filter(array_column($rows, 'indikator_id'))));
        $targetMap = [];
        if (!empty($indikatorIds)) {
            $targets = $this->db->table('rpjmd_target')
                ->select('indikator_sasaran_id, tahun, target_tahunan')
                ->whereIn('indikator_sasaran_id', $indikatorIds)
                ->get()
                ->getResultArray();
            foreach ($targets as $t) {
                $targetMap[$t['indikator_sasaran_id']][$t['tahun']] = $t['target_tahunan'];
            }
        }
        foreach ($rows as &$r) {
            $r['targets'] = $targetMap[$r['indikator_id']] ?? [];
        }
        unset($r);

        return $rows;
    }

    // =====================================================================
    // MODE 3 — KESELURUHAN: RPJMD (Tujuan→Sasaran) turun ke Renstra tiap OPD,
    // lalu diteruskan ke cascade internal OPD: Eselon III → Eselon IV/JF →
    // PELAKSANA (tabel rekursif cascading_sasaran_opd + cascading_indikator_opd).
    //
    // Relasi: renstra_tujuan.rpjmd_sasaran_id = rpjmd_sasaran.id
    // LEFT JOIN agar sasaran RPJMD tanpa renstra tetap tampil (kolom '-').
    //
    // Catatan penting: setiap join ke cascading_sasaran_opd DIKUNCI ke
    // rs.opd_id sehingga cascade satu OPD tidak pernah menempel pada indikator
    // Renstra OPD lain. Semua dilakukan dalam SATU query (bukan N+1).
    // =====================================================================
    public function getKeseluruhanMatrix($start, $end)
    {
        $start = (int) $start;
        $end   = (int) $end;

        $rows = $this->db->table('rpjmd_misi m')
            ->select("
                t.id as tujuan_id,
                t.tujuan_rpjmd,

                s.id as sasaran_id,
                s.sasaran_rpjmd,

                o.id as opd_id,
                o.nama_opd,

                rt.id as renstra_tujuan_id,
                rt.tujuan as renstra_tujuan,

                rs.id as renstra_sasaran_id,
                rs.sasaran as renstra_sasaran,

                ris.id as renstra_indikator_id,
                ris.indikator_sasaran as renstra_indikator,

                es3.id as es3_id,
                es3.nama_sasaran as es3_sasaran,
                i3.id as es3_indikator_id,
                i3.indikator as es3_indikator,

                es4.id as es4_id,
                es4.nama_sasaran as es4_sasaran,
                i4.id as es4_indikator_id,
                i4.indikator as es4_indikator,

                pel.id as pelaksana_id,
                pel.nama_sasaran as pelaksana_sasaran,
                ipel.id as pelaksana_indikator_id,
                ipel.indikator as pelaksana_indikator
            ", false)
            ->join('rpjmd_tujuan t', 't.misi_id = m.id', 'left')
            ->join('rpjmd_sasaran s', 's.tujuan_id = t.id', 'left')
            ->join('renstra_tujuan rt', 'rt.rpjmd_sasaran_id = s.id', 'left')
            ->join(
                'renstra_sasaran rs',
                "rs.renstra_tujuan_id = rt.id AND rs.tahun_mulai = {$start} AND rs.tahun_akhir = {$end}",
                'left',
                false
            )
            ->join('opd o', 'o.id = rs.opd_id', 'left')
            ->join('renstra_indikator_sasaran ris', 'ris.renstra_sasaran_id = rs.id', 'left')
            // ESELON III — milik OPD pemilik sasaran renstra tsb
            ->join(
                'cascading_sasaran_opd es3',
                "es3.renstra_indikator_sasaran_id = ris.id AND es3.level = 'es3' AND es3.opd_id = rs.opd_id",
                'left',
                false
            )
            ->join('cascading_indikator_opd i3', 'i3.cascading_sasaran_id = es3.id', 'left')
            // ESELON IV / JF
            ->join(
                'cascading_sasaran_opd es4',
                "es4.es3_indikator_id = i3.id AND es4.level = 'es4'",
                'left',
                false
            )
            ->join('cascading_indikator_opd i4', 'i4.cascading_sasaran_id = es4.id', 'left')
            // PELAKSANA — `es3_indikator_id` di sini berisi id indikator ES IV
            // (kolomnya bermakna "indikator induk", lihat migrasi 2026-07-27-000009)
            ->join(
                'cascading_sasaran_opd pel',
                "pel.es3_indikator_id = i4.id AND pel.level = 'pelaksana'",
                'left',
                false
            )
            ->join('cascading_indikator_opd ipel', 'ipel.cascading_sasaran_id = pel.id', 'left')
            ->where('m.tahun_mulai', $start)
            ->where('m.tahun_akhir', $end)
            ->orderBy('t.id', 'ASC')
            ->orderBy('s.id', 'ASC')
            ->orderBy('o.nama_opd', 'ASC')
            ->orderBy('rt.id', 'ASC')
            ->orderBy('rs.id', 'ASC')
            ->orderBy('ris.id', 'ASC')
            ->orderBy('es3.id', 'ASC')
            ->orderBy('i3.id', 'ASC')
            ->orderBy('es4.id', 'ASC')
            ->orderBy('i4.id', 'ASC')
            ->orderBy('pel.id', 'ASC')
            ->orderBy('ipel.id', 'ASC')
            ->get()
            ->getResultArray();

        return $rows;
    }

    /**
     * Renstra (per OPD) yang terhubung ke tiap sasaran RPJMD, untuk Pohon Keseluruhan.
     * @return array sasaran_id => [ opd_id => ['nama_opd', 'tujuan' => [ rt_id => ['nama','sasaran'=>[ rs_id => ['nama','indikators'=>[]] ]] ]] ]
     */
    private function renstraBySasaranMap($start, $end): array
    {
        $rows = $this->db->table('renstra_tujuan rt')
            ->select('
                rt.rpjmd_sasaran_id as sasaran_id,
                rt.id as rt_id,
                rt.tujuan as renstra_tujuan,
                rs.id as rs_id,
                rs.sasaran as renstra_sasaran,
                rs.opd_id,
                o.nama_opd,
                ris.id as ris_id,
                ris.indikator_sasaran as renstra_indikator
            ')
            ->join('renstra_sasaran rs', 'rs.renstra_tujuan_id = rt.id', 'inner')
            ->join('opd o', 'o.id = rs.opd_id', 'inner')
            ->join('renstra_indikator_sasaran ris', 'ris.renstra_sasaran_id = rs.id', 'left')
            ->where('rt.rpjmd_sasaran_id IS NOT NULL')
            ->where('rs.tahun_mulai', (int) $start)
            ->where('rs.tahun_akhir', (int) $end)
            ->orderBy('o.nama_opd', 'ASC')
            ->orderBy('rt.id', 'ASC')
            ->orderBy('rs.id', 'ASC')
            ->orderBy('ris.id', 'ASC')
            ->get()
            ->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $sid = $r['sasaran_id'];
            $opd = $r['opd_id'];
            if (!isset($map[$sid][$opd])) {
                $map[$sid][$opd] = ['nama_opd' => $r['nama_opd'], 'tujuan' => []];
            }
            $rt = $r['rt_id'];
            if (!isset($map[$sid][$opd]['tujuan'][$rt])) {
                $map[$sid][$opd]['tujuan'][$rt] = ['nama' => $r['renstra_tujuan'], 'sasaran' => []];
            }
            $rs = $r['rs_id'];
            if (!isset($map[$sid][$opd]['tujuan'][$rt]['sasaran'][$rs])) {
                $map[$sid][$opd]['tujuan'][$rt]['sasaran'][$rs] = ['nama' => $r['renstra_sasaran'], 'indikators' => []];
            }
            if (!empty($r['ris_id'])) {
                $map[$sid][$opd]['tujuan'][$rt]['sasaran'][$rs]['indikators'][$r['ris_id']] = $r['renstra_indikator'];
            }
        }
        return $map;
    }

    /**
     * Pohon Kinerja Keseluruhan: Visi → Misi → Tujuan RPJMD → Sasaran RPJMD
     *  → (per OPD) Tujuan Renstra → Sasaran Renstra → Indikator Renstra.
     */
    public function getKeseluruhanTree($tahunMulai, $tahunAkhir)
    {
        $misiList = $this->db->table('rpjmd_misi')
            ->where('tahun_mulai', $tahunMulai)
            ->where('tahun_akhir', $tahunAkhir)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        if (empty($misiList)) {
            return [];
        }

        $misiIds = array_column($misiList, 'id');

        $tujuanList = $this->db->table('rpjmd_tujuan')
            ->whereIn('misi_id', $misiIds)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $tujuanIds = array_column($tujuanList, 'id');

        $indikatorTujuanList = [];
        $sasaranList = [];
        $sasaranIds = [];
        if (!empty($tujuanIds)) {
            $indikatorTujuanList = $this->db->table('rpjmd_indikator_tujuan')
                ->whereIn('tujuan_id', $tujuanIds)
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();

            $sasaranList = $this->db->table('rpjmd_sasaran')
                ->whereIn('tujuan_id', $tujuanIds)
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();

            $sasaranIds = array_column($sasaranList, 'id');
        }

        $indikatorSasaranList = [];
        if (!empty($sasaranIds)) {
            $indikatorSasaranList = $this->db->table('rpjmd_indikator_sasaran')
                ->whereIn('sasaran_id', $sasaranIds)
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();
        }

        $renstraBySasaran = $this->renstraBySasaranMap($tahunMulai, $tahunAkhir);

        $groupedIndikatorSasaran = [];
        foreach ($indikatorSasaranList as $is) {
            $groupedIndikatorSasaran[$is['sasaran_id']][] = $is;
        }

        $groupedSasaran = [];
        foreach ($sasaranList as $sasaran) {
            $sid = $sasaran['id'];

            // Ubah map renstra OPD menjadi list bersih untuk view
            $opdNodes = [];
            foreach ($renstraBySasaran[$sid] ?? [] as $opd) {
                $tujuan = [];
                foreach ($opd['tujuan'] as $t) {
                    $sasaranArr = [];
                    foreach ($t['sasaran'] as $s) {
                        $sasaranArr[] = [
                            'nama' => $s['nama'],
                            'indikators' => array_values($s['indikators']),
                        ];
                    }
                    $tujuan[] = ['nama' => $t['nama'], 'sasaran' => $sasaranArr];
                }
                $opdNodes[] = ['nama_opd' => $opd['nama_opd'], 'tujuan' => $tujuan];
            }

            $groupedSasaran[$sasaran['tujuan_id']][] = [
                'id' => $sid,
                'sasaran_rpjmd' => $sasaran['sasaran_rpjmd'],
                'indikator_sasaran' => $groupedIndikatorSasaran[$sid] ?? [],
                'opd' => $opdNodes,
            ];
        }

        $groupedIndikatorTujuan = [];
        foreach ($indikatorTujuanList as $it) {
            $groupedIndikatorTujuan[$it['tujuan_id']][] = $it;
        }

        $groupedTujuan = [];
        foreach ($tujuanList as $tujuan) {
            $groupedTujuan[$tujuan['misi_id']][] = [
                'id' => $tujuan['id'],
                'tujuan_rpjmd' => $tujuan['tujuan_rpjmd'],
                'indikator_tujuan' => $groupedIndikatorTujuan[$tujuan['id']] ?? [],
                'sasaran' => $groupedSasaran[$tujuan['id']] ?? [],
            ];
        }

        $tree = [];
        foreach ($misiList as $misi) {
            $tree[] = [
                'id' => $misi['id'],
                'misi' => $misi['misi'],
                'tujuan' => $groupedTujuan[$misi['id']] ?? [],
            ];
        }

        return $tree;
    }

    /**
     * Pohon Keseluruhan versi ringkas (tanpa Visi/Misi/Tujuan RPJMD/Sasaran RPJMD):
     * Perangkat Daerah → Tujuan Renstra → Sasaran Renstra (ES II) → Indikator,
     * DITERUSKAN ke cascade internal OPD: Eselon III → Eselon IV/JF → PELAKSANA.
     *
     * Satu query saja (semua jenjang di-LEFT JOIN) supaya tidak N+1.
     *
     * @return array list [
     *   ['nama_opd', 'tujuan' => [
     *      ['nama', 'sasaran' => [
     *         ['nama', 'indikators' => [...], 'es3s' => [
     *            ['nama', 'indikators' => [...], 'es4s' => [
     *               ['nama', 'indikators' => [...], 'pelaksanas' => [
     *                  ['nama', 'indikators' => [...]]
     *               ]]
     *            ]]
     *         ]]
     *      ]]
     *   ]]
     * ]
     */
    public function getKeseluruhanByOpd($start, $end): array
    {
        $rows = $this->db->table('renstra_tujuan rt')
            ->select('
                rs.opd_id,
                o.nama_opd,
                rt.id as rt_id,
                rt.tujuan as renstra_tujuan,
                rs.id as rs_id,
                rs.sasaran as renstra_sasaran,
                ris.id as ris_id,
                ris.indikator_sasaran as renstra_indikator,
                es3.id as es3_id,
                es3.nama_sasaran as es3_sasaran,
                i3.id as i3_id,
                i3.indikator as es3_indikator,
                es4.id as es4_id,
                es4.nama_sasaran as es4_sasaran,
                i4.id as i4_id,
                i4.indikator as es4_indikator,
                pel.id as pel_id,
                pel.nama_sasaran as pelaksana_sasaran,
                ipel.id as ipel_id,
                ipel.indikator as pelaksana_indikator
            ')
            ->join('renstra_sasaran rs', 'rs.renstra_tujuan_id = rt.id', 'inner')
            ->join('opd o', 'o.id = rs.opd_id', 'inner')
            ->join('renstra_indikator_sasaran ris', 'ris.renstra_sasaran_id = rs.id', 'left')
            ->join(
                'cascading_sasaran_opd es3',
                "es3.renstra_indikator_sasaran_id = ris.id AND es3.level = 'es3' AND es3.opd_id = rs.opd_id",
                'left',
                false
            )
            ->join('cascading_indikator_opd i3', 'i3.cascading_sasaran_id = es3.id', 'left')
            ->join(
                'cascading_sasaran_opd es4',
                "es4.es3_indikator_id = i3.id AND es4.level = 'es4'",
                'left',
                false
            )
            ->join('cascading_indikator_opd i4', 'i4.cascading_sasaran_id = es4.id', 'left')
            ->join(
                'cascading_sasaran_opd pel',
                "pel.es3_indikator_id = i4.id AND pel.level = 'pelaksana'",
                'left',
                false
            )
            ->join('cascading_indikator_opd ipel', 'ipel.cascading_sasaran_id = pel.id', 'left')
            ->where('rt.rpjmd_sasaran_id IS NOT NULL')
            ->where('rs.tahun_mulai', (int) $start)
            ->where('rs.tahun_akhir', (int) $end)
            ->orderBy('o.nama_opd', 'ASC')
            ->orderBy('rt.id', 'ASC')
            ->orderBy('rs.id', 'ASC')
            ->orderBy('ris.id', 'ASC')
            ->orderBy('es3.id', 'ASC')
            ->orderBy('i3.id', 'ASC')
            ->orderBy('es4.id', 'ASC')
            ->orderBy('i4.id', 'ASC')
            ->orderBy('pel.id', 'ASC')
            ->orderBy('ipel.id', 'ASC')
            ->get()
            ->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $opd = $r['opd_id'];
            if (!isset($map[$opd])) {
                $map[$opd] = ['nama_opd' => $r['nama_opd'], 'tujuan' => []];
            }
            $rt = $r['rt_id'];
            if (!isset($map[$opd]['tujuan'][$rt])) {
                $map[$opd]['tujuan'][$rt] = ['nama' => $r['renstra_tujuan'], 'sasaran' => []];
            }
            $rs = $r['rs_id'];
            if (!isset($map[$opd]['tujuan'][$rt]['sasaran'][$rs])) {
                $map[$opd]['tujuan'][$rt]['sasaran'][$rs] = [
                    'nama'       => $r['renstra_sasaran'],
                    'indikators' => [],
                    'es3s'       => [],
                ];
            }
            $sasNode = &$map[$opd]['tujuan'][$rt]['sasaran'][$rs];

            if (!empty($r['ris_id'])) {
                $sasNode['indikators'][$r['ris_id']] = $r['renstra_indikator'];
            }

            if (empty($r['es3_id'])) {
                unset($sasNode);
                continue;
            }
            if (!isset($sasNode['es3s'][$r['es3_id']])) {
                $sasNode['es3s'][$r['es3_id']] = ['nama' => $r['es3_sasaran'], 'indikators' => [], 'es4s' => []];
            }
            $es3Node = &$sasNode['es3s'][$r['es3_id']];
            if (!empty($r['i3_id'])) {
                $es3Node['indikators'][$r['i3_id']] = $r['es3_indikator'];
            }

            if (empty($r['es4_id'])) {
                unset($es3Node, $sasNode);
                continue;
            }
            if (!isset($es3Node['es4s'][$r['es4_id']])) {
                $es3Node['es4s'][$r['es4_id']] = ['nama' => $r['es4_sasaran'], 'indikators' => [], 'pelaksanas' => []];
            }
            $es4Node = &$es3Node['es4s'][$r['es4_id']];
            if (!empty($r['i4_id'])) {
                $es4Node['indikators'][$r['i4_id']] = $r['es4_indikator'];
            }

            if (!empty($r['pel_id'])) {
                if (!isset($es4Node['pelaksanas'][$r['pel_id']])) {
                    $es4Node['pelaksanas'][$r['pel_id']] = ['nama' => $r['pelaksana_sasaran'], 'indikators' => []];
                }
                if (!empty($r['ipel_id'])) {
                    $es4Node['pelaksanas'][$r['pel_id']]['indikators'][$r['ipel_id']] = $r['pelaksana_indikator'];
                }
            }

            unset($es4Node, $es3Node, $sasNode);
        }

        // Bersihkan jadi list (buang key id)
        $tree = [];
        foreach ($map as $opd) {
            $tujuanList = [];
            foreach ($opd['tujuan'] as $t) {
                $sasaranList = [];
                foreach ($t['sasaran'] as $s) {
                    $es3List = [];
                    foreach ($s['es3s'] as $e3) {
                        $es4List = [];
                        foreach ($e3['es4s'] as $e4) {
                            $pelList = [];
                            foreach ($e4['pelaksanas'] as $p) {
                                $pelList[] = ['nama' => $p['nama'], 'indikators' => array_values($p['indikators'])];
                            }
                            $es4List[] = [
                                'nama'       => $e4['nama'],
                                'indikators' => array_values($e4['indikators']),
                                'pelaksanas' => $pelList,
                            ];
                        }
                        $es3List[] = [
                            'nama'       => $e3['nama'],
                            'indikators' => array_values($e3['indikators']),
                            'es4s'       => $es4List,
                        ];
                    }
                    $sasaranList[] = [
                        'nama'       => $s['nama'],
                        'indikators' => array_values($s['indikators']),
                        'es3s'       => $es3List,
                    ];
                }
                $tujuanList[] = ['nama' => $t['nama'], 'sasaran' => $sasaranList];
            }
            $tree[] = ['nama_opd' => $opd['nama_opd'], 'tujuan' => $tujuanList];
        }

        return $tree;
    }

    /**
     * Program & kegiatan PK yang menempel pada tiap node Eselon III (kabid)
     * pada Pohon Kinerja / Cascading OPD.
     *
     * Tidak ada kunci struktural apa pun dari cascading_sasaran_opd ke
     * pk_indikator, jadi jembatannya PENCOCOKAN TEKS ternormalisasi - pola yang
     * sudah dipakai PkRenaksiController::autoOpdsForSasaran. Urutan prioritas:
     * teks INDIKATOR dulu (lebih presisi), baru teks SASARAN sebagai cadangan,
     * dan hanya untuk node yang belum dapat lewat indikator. Tanpa pemilihan
     * prioritas itu, node yang sudah presisi ikut menyerap kegiatan milik
     * saudara sekandung yang kebetulan seteks sasarannya.
     *
     * Program DITURUNKAN DARI kegiatan (kegiatan_pk.program_id), BUKAN dari
     * pk_program.program_id. Sebabnya nyata: 452 dari 2051 tautan (22%) punya
     * program induk berbeda antara kedua jalur itu, sehingga menyandingkan
     * keduanya akan memperlihatkan kegiatan yang menempel pada program salah.
     *
     * Jenis PK jenjang es3 tidak selalu 'administrator': pada OPD kecamatan,
     * teks es3 cascading justru sepadan dengan pk.jenis 'pengawas' (konsisten
     * dengan relabel eselon kecamatan). Dideteksi dari DATA - ada tidaknya PK
     * berjenis 'camat' di OPD itu - bukan dari nama OPD. Tanpa aturan ini
     * seluruh kecamatan tampil kosong (cakupan 50% vs 59%).
     *
     * Node yang teksnya tidak cocok sengaja dibiarkan KOSONG, bukan diisi
     * seluruh program OPD, supaya program tidak menempel pada kabid yang salah.
     *
     * @return array<int, array<int, array<string, mixed>>> [es3_id => daftar program]
     */
    public function programPkByEs3(int $opdId, $periodeStart = null, $periodeEnd = null): array
    {
        if ($opdId <= 0) {
            return [];
        }

        $db = $this->db;

        // Jenjang es3 kecamatan memakai PK 'pengawas'; OPD biasa 'administrator'.
        $adaCamat = (int) $db->table('pk')
            ->where('opd_id', $opdId)->where('jenis', 'camat')
            ->countAllResults();
        $jenisEs3 = $adaCamat > 0 ? 'pengawas' : 'administrator';

        // Tahun PK: terbaru di dalam periode; kalau kosong, terbaru apa pun.
        $bt = $db->table('pk')->selectMax('tahun', 'tahun')
            ->where('opd_id', $opdId)->where('jenis', $jenisEs3);
        if ($periodeStart !== null && $periodeEnd !== null) {
            $bt->where('tahun >=', (int) $periodeStart)->where('tahun <=', (int) $periodeEnd);
        }
        $tahun = $bt->get()->getRowArray()['tahun'] ?? null;

        if ($tahun === null && $periodeStart !== null) {
            $tahun = $db->table('pk')->selectMax('tahun', 'tahun')
                ->where('opd_id', $opdId)->where('jenis', $jenisEs3)
                ->get()->getRowArray()['tahun'] ?? null;
        }
        if ($tahun === null) {
            return [];
        }

        $norm = static function (string $kol): string {
            return "LOWER(TRIM(REGEXP_REPLACE({$kol}, '[[:space:]]+', ' ')))";
        };

        // Sisi PK: indikator + teks sasaran & indikator yang sudah dinormalkan.
        $pkRows = $db->table('pk')
            ->select('pi.id AS pk_indikator_id')
            ->select($norm('ps.sasaran') . ' AS k_sas', false)
            ->select($norm('pi.indikator') . ' AS k_ind', false)
            ->join('pk_sasaran ps', 'ps.pk_id = pk.id AND ps.jenis = pk.jenis', 'inner')
            ->join('pk_indikator pi', 'pi.pk_sasaran_id = ps.id', 'inner')
            ->where('pk.opd_id', $opdId)
            ->where('pk.jenis', $jenisEs3)
            ->where('pk.tahun', $tahun)
            ->get()->getResultArray();

        if (empty($pkRows)) {
            return [];
        }

        // Sisi cascading: node es3 + teks sasaran & indikatornya.
        $nodeRows = $db->table('cascading_sasaran_opd cs')
            ->select('cs.id AS node_id')
            ->select($norm('cs.nama_sasaran') . ' AS k_sas', false)
            ->select($norm('ci.indikator') . ' AS k_ind', false)
            ->join('cascading_indikator_opd ci', 'ci.cascading_sasaran_id = cs.id', 'left')
            ->where('cs.opd_id', $opdId)
            ->where('cs.level', 'es3')
            ->get()->getResultArray();

        if (empty($nodeRows)) {
            return [];
        }

        $pkByInd = [];
        $pkBySas = [];
        foreach ($pkRows as $r) {
            $id = (int) $r['pk_indikator_id'];
            if (($r['k_ind'] ?? '') !== '') {
                $pkByInd[$r['k_ind']][$id] = $id;
            }
            if (($r['k_sas'] ?? '') !== '') {
                $pkBySas[$r['k_sas']][$id] = $id;
            }
        }

        $cocokInd = [];
        $cocokSas = [];
        foreach ($nodeRows as $r) {
            $node = (int) $r['node_id'];
            if (($r['k_ind'] ?? '') !== '' && isset($pkByInd[$r['k_ind']])) {
                foreach ($pkByInd[$r['k_ind']] as $id) {
                    $cocokInd[$node][$id] = $id;
                }
            }
            if (($r['k_sas'] ?? '') !== '' && isset($pkBySas[$r['k_sas']])) {
                foreach ($pkBySas[$r['k_sas']] as $id) {
                    $cocokSas[$node][$id] = $id;
                }
            }
        }

        $indikatorPerNode = [];
        $semuaNode = array_unique(array_merge(array_keys($cocokInd), array_keys($cocokSas)));
        foreach ($semuaNode as $node) {
            // Cocok indikator menang; sasaran hanya cadangan bila indikator nihil.
            $indikatorPerNode[$node] = !empty($cocokInd[$node])
                ? array_values($cocokInd[$node])
                : array_values($cocokSas[$node] ?? []);
        }
        if (empty($indikatorPerNode)) {
            return [];
        }

        $semuaIndikator = array_values(array_unique(array_merge(...array_values($indikatorPerNode))));

        // Satu query batch: indikator -> kegiatan -> program INDUK kegiatan itu.
        $unitRows = $db->table('pk_program pp')
            ->select('pp.pk_indikator_id,
                      pr.id AS program_id, pr.kode_program, pr.program_kegiatan,
                      kg.id AS kegiatan_id, kg.kode_kegiatan, kg.kegiatan')
            ->join('pk_kegiatan pkg', 'pkg.pk_program_id = pp.id', 'inner')
            ->join('kegiatan_pk kg', 'kg.id = pkg.kegiatan_id', 'inner')
            ->join('program_pk pr', 'pr.id = kg.program_id', 'inner')
            ->whereIn('pp.pk_indikator_id', $semuaIndikator)
            ->orderBy('pr.kode_program', 'ASC')
            ->orderBy('pr.id', 'ASC')
            ->orderBy('kg.kode_kegiatan', 'ASC')
            ->get()->getResultArray();

        $unitByIndikator = [];
        foreach ($unitRows as $r) {
            $unitByIndikator[(int) $r['pk_indikator_id']][] = $r;
        }

        $hasil = [];
        foreach ($indikatorPerNode as $node => $ids) {
            $programs = [];
            foreach ($ids as $id) {
                foreach ($unitByIndikator[$id] ?? [] as $r) {
                    $pid = (int) $r['program_id'];
                    if (!isset($programs[$pid])) {
                        $programs[$pid] = [
                            'program_id' => $pid,
                            'kode'       => ($r['kode_program'] ?? '') !== '' ? $r['kode_program'] : null,
                            'nama'       => (string) $r['program_kegiatan'],
                            'kegiatan'   => [],
                        ];
                    }
                    $kid = (int) $r['kegiatan_id'];
                    $programs[$pid]['kegiatan'][$kid] = [
                        'kegiatan_id' => $kid,
                        'kode'        => ($r['kode_kegiatan'] ?? '') !== '' ? $r['kode_kegiatan'] : null,
                        'nama'        => (string) $r['kegiatan'],
                    ];
                }
            }
            if (empty($programs)) {
                continue; // cocok teks tapi rantainya putus -> tetap kosong, jujur
            }
            foreach ($programs as &$p) {
                $p['kegiatan'] = array_values($p['kegiatan']);
            }
            unset($p);
            $hasil[(int) $node] = array_values($programs);
        }

        return $hasil;
    }
}
