<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\ProgramPkModel;
use App\Models\OpdModel;
use CodeIgniter\HTTP\ResponseInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProgramPkController extends BaseController
{
    /**
     * Lebar baku tiap ruas kode SIPD pada Lampiran 8 APBD.
     *
     * SIPD mencetak sebagian sel sebagai angka (1, 2, 3) dan sebagian lagi
     * sebagai teks (01, 02, 0003) untuk kode yang sama persis. Tanpa
     * normalisasi, "02" dan "2" menghasilkan kode berbeda sehingga re-import
     * file yang sama membuat data ganda.
     *
     * @var array<string, int[]> per segmen kode (dipisah titik)
     */
    private const KODE_WIDTH = [
        'B' => [1],     // urusan
        'C' => [2],     // bidang urusan
        'D' => [2],     // program
        'E' => [1, 2],  // kegiatan, mis. "2.01"
        'F' => [4],     // sub kegiatan
    ];

    protected $programPkModel;
    protected $OpdModel;

    public function __construct()
    {
        $this->programPkModel = new ProgramPkModel();
        $this->OpdModel = new OpdModel();
    }

    /**
     * Display list of programs
     */
    public function index()
    {
        $level = $this->request->getGet('level') ?? 'program';

        // Master Program/Kegiatan/Sub dikelola PER TAHUN ANGGARAN.
        $years      = $this->programPkModel->getAvailableYears();
        $tahunParam = $this->request->getGet('tahun');
        $tahun      = ($tahunParam !== null && $tahunParam !== '')
            ? (int) $tahunParam
            : ($years[0] ?? (int) date('Y')); // default: tahun terbaru yang ada

        switch ($level) {

            case 'kegiatan':
                $dataList = $this->programPkModel->getAllKegiatan($tahun);
                break;

            case 'sub':
                $dataList = $this->programPkModel->getAllSubKegiatan($tahun);
                break;

            default:
                $dataList = $this->programPkModel->getAllPrograms($tahun);
                $level = 'program';
        }

        $data = [
            'title' => 'Manajemen Program PK',
            'level' => $level,
            'dataList' => $dataList,
            'tahun' => $tahun,
            'tahunList' => $years,
        ];

        return view('adminKabupaten/program_pk/program', $data);
    }

    /**
     * Show form for creating new program
     */
    public function tambah()
    {
        $data = [
            'title' => 'Tambah Program PK',
            'validation' => session()->getFlashdata('validation'),
            'opds' => $this->OpdModel->getAllOpd()
        ];

        return view('adminKabupaten/program_pk/tambah_program', $data);
    }


    public function import()
    {
        $data = [
            'title' => 'Import Program PK',
            'opds' => $this->OpdModel->findAll(),
            'validation' => session()->getFlashdata('validation')
        ];

        return view('adminKabupaten/program_pk/import_program', $data);
    }


    /**
     * Unduh template Excel import (cocok dengan parser processImport).
     * Kolom: A=Kode Perangkat Daerah/Unit, B=Urusan, C=Bidang Urusan,
     *        D=Program, E=Kegiatan, F=Sub Kegiatan, G=Uraian, K=Anggaran.
     * Level ditentukan dari D/E/F pada baris itu sendiri.
     */
    public function template()
    {
        $ss    = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Data');

        foreach (['A' => 24, 'B' => 8, 'C' => 10, 'D' => 10, 'E' => 12, 'F' => 12, 'G' => 60, 'H' => 6, 'I' => 6, 'J' => 6, 'K' => 22] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        // Baris 1: judul (dilewati importer)
        $sheet->mergeCells('A1:K1');
        $sheet->setCellValue('A1', 'TEMPLATE IMPORT PROGRAM / KEGIATAN / SUB KEGIATAN PK — FORMAT SIPD');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

        // Baris 2: header kolom (juga dilewati importer; data mulai baris 3)
        $headers = [
            'A2' => 'KODE PERANGKAT DAERAH / UNIT',
            'B2' => 'URUSAN',
            'C2' => 'BIDANG URUSAN',
            'D2' => 'PROGRAM',
            'E2' => 'KEGIATAN',
            'F2' => 'SUB KEGIATAN',
            'G2' => 'URAIAN (Nama Program / Kegiatan / Sub Kegiatan)',
            'K2' => 'ANGGARAN (Rp)',
        ];
        foreach ($headers as $cell => $val) {
            $sheet->setCellValue($cell, $val);
        }
        $sheet->getStyle('A2:K2')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A2:K2')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('00743E');
        $sheet->getStyle('A2:K2')->getAlignment()->setHorizontal('center')->setVertical('center');
        $sheet->getStyle('A2:K2')->getAlignment()->setWrapText(true);

        // Baris 3+: contoh (Program -> Kegiatan -> Sub). A/B/C boleh dikosongkan
        // pada baris turunan (importer mewarisi nilai di atasnya / fill-down),
        // tetapi D/E/F harus diisi sesuai levelnya karena justru kolom itulah
        // yang menentukan baris tersebut Program, Kegiatan, atau Sub Kegiatan.
        $rows = [
            // [A, B, C, D, E, F, Uraian, Anggaran]
            ['1.01.2.22.0.00.01.0000', '1', '01', '01', '', '', 'PROGRAM PENUNJANG URUSAN PEMERINTAHAN DAERAH', 1500000000],
            ['', '', '', '01', '2.01', '', 'Perencanaan, Penganggaran, dan Evaluasi Kinerja Perangkat Daerah', 500000000],
            ['', '', '', '01', '2.01', '0001', 'Penyusunan Dokumen Perencanaan Perangkat Daerah', 200000000],
            ['', '', '', '01', '2.01', '0002', 'Evaluasi Kinerja Perangkat Daerah', 300000000],
            ['', '', '', '01', '2.02', '', 'Administrasi Keuangan Perangkat Daerah', 800000000],
            ['', '', '', '01', '2.02', '0001', 'Penyediaan Gaji dan Tunjangan ASN', 800000000],
            ['', '2', '22', '02', '', '', 'PROGRAM PENGEMBANGAN KEBUDAYAAN', 400000000],
            ['', '2', '22', '02', '2.01', '', 'Pengelolaan Kebudayaan yang Masyarakat Pelakunya dalam Daerah Kabupaten/Kota', 400000000],
            ['', '2', '22', '02', '2.01', '0001', 'Pelindungan, Pengembangan, Pemanfaatan Objek Pemajuan Kebudayaan', 400000000],
        ];
        $r = 3;
        $textCols = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3, 'E' => 4, 'F' => 5];
        foreach ($rows as $row) {
            foreach ($textCols as $col => $idx) {
                // Ditulis sebagai teks agar nol di depan ("01", "0001") tidak hilang.
                $sheet->setCellValueExplicit("{$col}{$r}", $row[$idx], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $sheet->setCellValue("G{$r}", $row[6]);
            $sheet->setCellValueExplicit("K{$r}", $row[7], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $r++;
        }
        $lastRow = $r - 1;
        $sheet->getStyle("A3:K{$lastRow}")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $sheet->getStyle("A2:K2")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $sheet->getStyle("K3:K{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("A3:F{$lastRow}")->getAlignment()->setHorizontal('center');

        // Sheet petunjuk
        $help = $ss->createSheet();
        $help->setTitle('Petunjuk');
        $help->getColumnDimension('A')->setWidth(100);
        $petunjuk = [
            'PETUNJUK PENGISIAN TEMPLATE IMPORT',
            '',
            '1. Baris 1 (judul) dan baris 2 (header) JANGAN dihapus — data dimulai pada baris ke-3.',
            '2. Kolom yang dibaca importer: A, B, C, D, E, F, G, dan K. Kolom H, I, J diabaikan.',
            '   A = Kode Perangkat Daerah/Unit   B = Urusan   C = Bidang Urusan',
            '   D = Program   E = Kegiatan   F = Sub Kegiatan   G = Uraian   K = Anggaran',
            '',
            'PENENTUAN LEVEL (berdasarkan kolom D, E, & F pada baris itu sendiri):',
            '   • PROGRAM       : isi D. Kolom E dan F DIKOSONGKAN.',
            '   • KEGIATAN      : isi D dan E. Kolom F DIKOSONGKAN.',
            '   • SUB KEGIATAN  : isi D, E, DAN F.',
            '   • Baris dengan D kosong (OPD / Urusan / Bidang Urusan / TOTAL) otomatis dilewati.',
            '',
            '3. Kolom A, B, dan C boleh dikosongkan pada baris turunan — nilainya mewarisi baris di atasnya.',
            '   Kolom D, E, dan F TIDAK diwarisi karena menjadi penentu level baris.',
            '4. Kode unik dibentuk dari jalur lengkap A+B+C+D (Program), +E (Kegiatan), +F (Sub Kegiatan).',
            '   Karena itu B dan C wajib benar: Program "1.1.2" (Pendidikan) dan "2.22.2" (Kebudayaan)',
            '   sama-sama berkode program "2" dan akan tertukar bila B/C dikosongkan.',
            '5. Nol di depan tidak masalah: "1"/"01", "2"/"02", dan "3"/"0003" dianggap kode yang sama.',
            '6. Kolom G = nama Program/Kegiatan/Sub Kegiatan.',
            '7. Kolom K = anggaran baris tersebut (angka saja, tanpa "Rp").',
            '8. Urutkan: Program diikuti Kegiatan-nya, lalu Sub Kegiatan-nya (seperti contoh di sheet "Data").',
            '9. Tahun Anggaran, Jenis Anggaran, dan OPD dipilih di halaman Import (bukan di file ini).',
            '10. Re-import pada tahun & jenis anggaran yang sama akan MEMPERBARUI data (berdasar kode), bukan menduplikasi.',
            '11. Centang "Simulasi saja" di halaman Import untuk mengecek hasil tanpa menyimpan ke database.',
        ];
        $hr = 1;
        foreach ($petunjuk as $line) {
            $help->setCellValue("A{$hr}", $line);
            $hr++;
        }
        $help->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $help->getStyle('A8')->getFont()->setBold(true);
        $help->getStyle("A1:A{$hr}")->getAlignment()->setWrapText(true);

        $ss->setActiveSheetIndex(0);

        $filename = 'Template_Import_Program_PK.xlsx';
        if (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
        $writer->save('php://output');
        exit;
    }

    /**
     * Import Lampiran 8 APBD (format SIPD).
     *
     * Peta kolom: A = kode perangkat daerah/unit, B = urusan, C = bidang
     * urusan, D = program, E = kegiatan, F = sub kegiatan, G = uraian,
     * K = anggaran Rancangan APBD.
     *
     * Level baris ditentukan dari kolom D/E/F baris itu sendiri:
     *   D terisi, E & F kosong  -> Program
     *   D & E terisi, F kosong  -> Kegiatan
     *   D, E & F terisi         -> Sub Kegiatan
     *   D kosong                -> baris OPD/urusan/bidang urusan/total (dilewati)
     *
     * Kode hirarki dibentuk dari jalur SIPD lengkap (A+B+C+D ... +E ... +F),
     * bukan hanya A+D. Tanpa B dan C, "1.1.2" (Pengelolaan Pendidikan) dan
     * "2.22.2" (Pengembangan Kebudayaan) menghasilkan kode yang sama sehingga
     * anggaran program saling menimpa dan kegiatan masuk ke program yang salah.
     */
    public function processImport()
    {
        $file = $this->request->getFile('file');
        if (!$file || !$file->isValid()) {
            return redirect()->back()->with('error', 'File tidak valid');
        }

        $ext = strtolower($file->getExtension());

        if (!in_array($ext, ['xls', 'xlsx'], true)) {
            return redirect()->back()->with('error', 'Format file harus Excel');
        }

        $tahun         = (int) $this->request->getPost('tahun_anggaran');
        $opdId         = (int) $this->request->getPost('opd_id');
        $jenisAnggaran = trim((string) $this->request->getPost('jenis_anggaran'));
        $sheetName     = trim((string) $this->request->getPost('sheet'));
        $dryRun        = (bool) $this->request->getPost('dryrun');

        if ($tahun <= 0) {
            return redirect()->back()->with('error', 'Tahun anggaran wajib diisi');
        }

        if ($opdId <= 0) {
            return redirect()->back()->with('error', 'OPD wajib dipilih');
        }

        if ($jenisAnggaran === '') {
            return redirect()->back()->with('error', 'Jenis anggaran wajib dipilih');
        }

        try {
            $spreadsheet = IOFactory::load($file->getTempName());
        } catch (\Throwable $e) {
            log_message('error', 'Gagal membaca file import Program PK: ' . $e->getMessage());
            return redirect()->back()->with('error', 'File Excel tidak dapat dibaca: ' . $e->getMessage());
        }

        $sheet = $sheetName !== ''
            ? $spreadsheet->getSheetByName($sheetName)
            : $spreadsheet->getActiveSheet();

        if (!$sheet) {
            return redirect()->back()->with('error', 'Sheet "' . $sheetName . '" tidak ditemukan pada file');
        }

        $db = \Config\Database::connect();
        $db->transException(true)->transBegin();

        try {
            $stat = $this->importLampiran8($db, $sheet, $opdId, $tahun, $jenisAnggaran);

            if ($dryRun) {
                $db->transRollback();
            } else {
                $db->transCommit();
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Gagal import Program PK: ' . $e->getMessage());

            return redirect()->back()->with('error', 'Import gagal, transaksi dibatalkan: ' . $e->getMessage());
        }

        $ringkasan = sprintf(
            'Program %d baru / %d diperbarui, Kegiatan %d baru / %d diperbarui, Sub Kegiatan %d baru / %d diperbarui.',
            $stat['program_baru'],
            $stat['program_update'],
            $stat['kegiatan_baru'],
            $stat['kegiatan_update'],
            $stat['sub_baru'],
            $stat['sub_update']
        );

        if ($stat['program_auto'] > 0 || $stat['kegiatan_auto'] > 0) {
            $ringkasan .= ' ' . $stat['program_auto'] . ' program dan ' . $stat['kegiatan_auto']
                . ' kegiatan dibuat otomatis karena unit terkait tidak mencantumkan barisnya di file'
                . ' (anggarannya dihitung dari total rincian di bawahnya).';
        }

        if ($stat['lewat'] > 0) {
            $ringkasan .= ' ' . $stat['lewat'] . ' baris dilewati karena induknya tidak ditemukan.';
        }

        $pesan = $dryRun
            ? 'Simulasi selesai (tidak ada data yang disimpan). ' . $ringkasan
            : 'Import Program, Kegiatan, dan Sub Kegiatan berhasil. ' . $ringkasan;

        return redirect()->to('/adminkab/program_pk/import')->with('success', $pesan);
    }

    /**
     * Inti import Lampiran 8: baca sheet, bentuk kode hirarki, lalu upsert
     * Program -> Kegiatan -> Sub Kegiatan. Dipanggil di dalam transaksi.
     *
     * @return array<string, int> ringkasan jumlah data baru/diperbarui/dilewati
     */
    private function importLampiran8(
        $db,
        Worksheet $sheet,
        int $opdId,
        int $tahun,
        string $jenisAnggaran
    ): array {
        $rows = $sheet->toArray(null, true, true, true);

        $tbProgram  = $db->table('program_pk');
        $tbKegiatan = $db->table('kegiatan_pk');
        $tbSub      = $db->table('sub_kegiatan_pk');
        $now        = date('Y-m-d H:i:s');

        // Konteks yang boleh diwarisi baris di bawahnya. Hanya A/B/C, karena
        // ketiganya murni penanda posisi; D/E/F justru penentu level baris
        // sehingga harus dibaca apa adanya (mewarisi D membuat baris urusan
        // ikut terbaca sebagai program).
        $ctxUnit   = '';
        $ctxUrusan = '';
        $ctxBidang = '';

        // Parent aktif. Selalu diturunkan dari kode baris yang sedang diproses,
        // bukan sekadar entitas terakhir yang kebetulan terbaca.
        $currentProgramId    = null;
        $currentKegiatanId   = null;
        $currentProgramKode  = null;
        $currentKegiatanKode = null;
        $currentKegiatanE    = '';

        // Memo id per kode selama satu proses import.
        $programIdByKode  = [];
        $kegiatanIdByKode = [];

        // Kode yang benar-benar punya baris sendiri di file, dan nomenklatur
        // namanya per B.C.D(.E) — nama program/kegiatan sama di semua unit.
        $kodeProgramDenganBaris  = [];
        $kodeKegiatanDenganBaris = [];
        $namaProgramNomenklatur  = [];
        $namaKegiatanNomenklatur = [];

        // Kegiatan yang dipakai sub kegiatan tanpa punya baris sendiri.
        $kegiatanTanpaBaris = [];

        $stat = [
            'program_baru'    => 0,
            'program_update'  => 0,
            'program_auto'    => 0,
            'kegiatan_baru'   => 0,
            'kegiatan_update' => 0,
            'kegiatan_auto'   => 0,
            'sub_baru'        => 0,
            'sub_update'      => 0,
            'lewat'           => 0,
        ];

        foreach ($rows as $rowNo => $r) {
            if (!is_int($rowNo)) {
                continue;
            }

            $A = $this->cleanUraian($r['A'] ?? '');
            $G = $this->cleanUraian($r['G'] ?? '');

            // Ganti perangkat daerah/unit (mis. dari Dinas ke Puskesmas):
            // seluruh konteks dan parent di bawahnya harus direset.
            if ($A !== '' && $A !== $ctxUnit) {
                $ctxUnit             = $A;
                $ctxUrusan           = '';
                $ctxBidang           = '';
                $currentProgramId    = null;
                $currentKegiatanId   = null;
                $currentProgramKode  = null;
                $currentKegiatanKode = null;
                $currentKegiatanE    = '';
            }

            $nB = $this->normalizeKodeRuas($r['B'] ?? '', self::KODE_WIDTH['B']);
            $nC = $this->normalizeKodeRuas($r['C'] ?? '', self::KODE_WIDTH['C']);
            $nD = $this->normalizeKodeRuas($r['D'] ?? '', self::KODE_WIDTH['D']);
            $nE = $this->normalizeKodeRuas($r['E'] ?? '', self::KODE_WIDTH['E']);
            $nF = $this->normalizeKodeRuas($r['F'] ?? '', self::KODE_WIDTH['F']);

            // Urusan berganti -> bidang urusan di bawahnya tidak berlaku lagi.
            if ($nB !== '') {
                if ($nB !== $ctxUrusan) {
                    $ctxUrusan = $nB;
                    $ctxBidang = '';
                }
            } else {
                $nB = $ctxUrusan;
            }

            if ($nC !== '') {
                $ctxBidang = $nC;
            } else {
                $nC = $ctxBidang;
            }

            // Baris OPD / urusan / bidang urusan / TOTAL / tanda tangan.
            // Reset parent supaya baris berikutnya tidak menempel ke
            // program atau kegiatan dari blok sebelumnya.
            if ($nD === '' || $nD === '00') {
                $currentProgramId    = null;
                $currentKegiatanId   = null;
                $currentProgramKode  = null;
                $currentKegiatanKode = null;
                $currentKegiatanE    = '';
                continue;
            }

            if ($G === '') {
                continue;
            }

            $kodeProgram = ($ctxUnit !== '' ? $ctxUnit : '0')
                . '.' . ($nB !== '' ? $nB : '0')
                . '.' . ($nC !== '' ? $nC : '00')
                . '.' . $nD;

            // Sub kegiatan yang kolom E-nya dikosongkan (gaya fill-down)
            // mewarisi kegiatan terakhir pada program yang sama.
            if ($nE === '' && $nF !== '' && $currentKegiatanE !== '' && $currentProgramKode === $kodeProgram) {
                $nE = $currentKegiatanE;
            }

            // Anggaran wajib diambil dari kolom K baris entitas ini sendiri.
            // null = sel benar-benar kosong, nilai lama dipertahankan.
            $anggaran = $this->parseAnggaranCell($sheet, $rowNo);

            /**
             * =========================
             * PROGRAM
             * =========================
             */
            if ($nE === '') {
                $data = [
                    'opd_id'           => $opdId,
                    'kode_program'     => $kodeProgram,
                    'program_kegiatan' => $G,
                    'tahun_anggaran'   => $tahun,
                    'jenis_anggaran'   => $jenisAnggaran,
                    'updated_at'       => $now,
                ];
                if ($anggaran !== null) {
                    $data['anggaran'] = $anggaran;
                }

                $programId = $this->findProgramId(
                    $tbProgram,
                    $programIdByKode,
                    $kodeProgram,
                    $opdId,
                    $tahun,
                    $jenisAnggaran
                );

                if ($programId !== null) {
                    $tbProgram->where('id', $programId)->update($data);
                    $stat['program_update']++;
                } else {
                    $data['created_at'] = $now;
                    $tbProgram->insert($data);
                    $programId = (int) $db->insertID();
                    $stat['program_baru']++;
                }

                $programIdByKode[$kodeProgram]        = $programId;
                $kodeProgramDenganBaris[$kodeProgram] = true;
                $namaProgramNomenklatur[$nB . '.' . $nC . '.' . $nD] = $G;
                $currentProgramId   = $programId;
                $currentProgramKode = $kodeProgram;

                // Pindah program -> kegiatan aktif tidak berlaku lagi.
                $currentKegiatanId   = null;
                $currentKegiatanKode = null;
                $currentKegiatanE    = '';
                continue;
            }

            // Kegiatan & sub kegiatan wajib menempel pada program milik baris
            // ini (A+B+C+D), bukan program terakhir yang kebetulan terbaca.
            // Program aktif hanya dipakai ulang bila kodenya memang sama.
            $programId = ($currentProgramKode === $kodeProgram && $currentProgramId !== null)
                ? $currentProgramId
                : $this->findProgramId(
                    $tbProgram,
                    $programIdByKode,
                    $kodeProgram,
                    $opdId,
                    $tahun,
                    $jenisAnggaran
                );

            if ($programId === null) {
                // Sebagian unit (mis. Puskesmas dan UPT) langsung mencantumkan
                // kegiatan tanpa baris Program tersendiri. Programnya dibuat
                // dari jalur kode baris ini supaya kegiatan tetap masuk ke
                // hirarki yang benar — bukan menempel ke program terakhir yang
                // kebetulan terbaca seperti perilaku importer lama.
                $nomenklatur = $nB . '.' . $nC . '.' . $nD;

                $tbProgram->insert([
                    'opd_id'           => $opdId,
                    'kode_program'     => $kodeProgram,
                    // Nama program identik di semua unit, ambil dari baris
                    // program unit induk yang sudah terbaca lebih dulu.
                    'program_kegiatan' => $namaProgramNomenklatur[$nomenklatur] ?? ('PROGRAM ' . $nomenklatur),
                    'tahun_anggaran'   => $tahun,
                    'jenis_anggaran'   => $jenisAnggaran,
                    'anggaran'         => 0,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);

                $programId                    = (int) $db->insertID();
                $programIdByKode[$kodeProgram] = $programId;
                $stat['program_auto']++;
            }

            $currentProgramId   = $programId;
            $currentProgramKode = $kodeProgram;
            $kodeKegiatan       = $kodeProgram . '.' . $nE;

            /**
             * =========================
             * KEGIATAN
             * =========================
             */
            if ($nF === '') {
                $data = [
                    'program_id'     => $programId,
                    'kode_kegiatan'  => $kodeKegiatan,
                    'kegiatan'       => $G,
                    'tahun_anggaran' => $tahun,
                    'jenis_anggaran' => $jenisAnggaran,
                    'updated_at'     => $now,
                ];
                if ($anggaran !== null) {
                    $data['anggaran'] = $anggaran;
                }

                // Dicocokkan lewat kode di bawah program induknya, bukan
                // lewat nama kegiatan (nama bisa berubah antar dokumen dan
                // nama yang sama dipakai di banyak program).
                $kegiatanId = $this->findKegiatanId(
                    $tbKegiatan,
                    $kegiatanIdByKode,
                    $kodeKegiatan,
                    $programId,
                    $tahun,
                    $jenisAnggaran
                );

                if ($kegiatanId !== null) {
                    $tbKegiatan->where('id', $kegiatanId)->update($data);
                    $stat['kegiatan_update']++;
                } else {
                    $data['created_at'] = $now;
                    $tbKegiatan->insert($data);
                    $kegiatanId = (int) $db->insertID();
                    $stat['kegiatan_baru']++;
                }

                $kegiatanIdByKode[$programId . '|' . $kodeKegiatan]  = $kegiatanId;
                $kodeKegiatanDenganBaris[$kodeKegiatan]              = true;
                $namaKegiatanNomenklatur[$nB . '.' . $nC . '.' . $nD . '.' . $nE] = $G;
                $currentKegiatanId   = $kegiatanId;
                $currentKegiatanKode = $kodeKegiatan;
                $currentKegiatanE    = $nE;
                continue;
            }

            /**
             * =========================
             * SUB KEGIATAN
             * =========================
             */
            // Sama seperti program: kegiatan aktif hanya dipakai ulang bila
            // kode kegiatan baris ini persis sama (kode sudah memuat jalur
            // program, jadi tidak mungkin nyantol ke program lain).
            $kegiatanId = ($currentKegiatanKode === $kodeKegiatan && $currentKegiatanId !== null)
                ? $currentKegiatanId
                : $this->findKegiatanId(
                    $tbKegiatan,
                    $kegiatanIdByKode,
                    $kodeKegiatan,
                    $programId,
                    $tahun,
                    $jenisAnggaran
                );

            if ($kegiatanId === null) {
                // Seperti pada program: ada unit (mis. Kelurahan) yang langsung
                // mencantumkan sub kegiatan tanpa baris Kegiatan tersendiri.
                $nomenklatur = $nB . '.' . $nC . '.' . $nD . '.' . $nE;

                $tbKegiatan->insert([
                    'program_id'     => $programId,
                    'kode_kegiatan'  => $kodeKegiatan,
                    'kegiatan'       => $namaKegiatanNomenklatur[$nomenklatur] ?? ('Kegiatan ' . $nomenklatur),
                    'tahun_anggaran' => $tahun,
                    'jenis_anggaran' => $jenisAnggaran,
                    'anggaran'       => 0,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);

                $kegiatanId = (int) $db->insertID();
                $kegiatanIdByKode[$programId . '|' . $kodeKegiatan] = $kegiatanId;
                $stat['kegiatan_auto']++;
            }

            if (!isset($kodeKegiatanDenganBaris[$kodeKegiatan])) {
                $kegiatanTanpaBaris[$kodeKegiatan] = $kegiatanId;
            }

            $currentKegiatanId   = $kegiatanId;
            $currentKegiatanKode = $kodeKegiatan;
            $kodeSub             = $kodeKegiatan . '.' . $nF;

            $data = [
                'kegiatan_id'      => $kegiatanId,
                'kode_sub_kegiatan' => $kodeSub,
                'sub_kegiatan'     => $G,
                'tahun_anggaran'   => $tahun,
                'jenis_anggaran'   => $jenisAnggaran,
                'updated_at'       => $now,
            ];
            if ($anggaran !== null) {
                $data['anggaran'] = $anggaran;
            }

            // Dicari di bawah kegiatan induknya. Kode sub yang sama
            // (mis. "0001") dipakai ulang di banyak kegiatan, jadi
            // pencarian hanya lewat kode + tahun pasti salah sasaran.
            $subId = $this->findSubKegiatanId(
                $tbSub,
                $kodeSub,
                $kegiatanId,
                $tahun,
                $jenisAnggaran
            );

            if ($subId !== null) {
                $tbSub->where('id', $subId)->update($data);
                $stat['sub_update']++;
            } else {
                $data['created_at'] = $now;
                $tbSub->insert($data);
                $stat['sub_baru']++;
            }
        }

        // Entitas tanpa baris sendiri di file tidak punya kolom K, sehingga
        // anggarannya diisi dari total anak di bawahnya. Dihitung ulang setiap
        // import agar hasilnya tetap sama saat file yang sama diimpor kembali.
        // Entitas yang punya baris sendiri tidak disentuh — anggarannya wajib
        // apa adanya dari kolom K.
        // Kegiatan lebih dulu, karena totalnya menjadi sumber total program.
        foreach ($kegiatanTanpaBaris as $kode => $id) {
            if (isset($kodeKegiatanDenganBaris[$kode])) {
                continue;
            }

            $tbKegiatan->where('id', $id)->update([
                'anggaran'   => $this->sumAnggaran($db, 'sub_kegiatan_pk', 'kegiatan_id', $id),
                'updated_at' => $now,
            ]);
        }

        foreach ($programIdByKode as $kode => $id) {
            if (isset($kodeProgramDenganBaris[$kode])) {
                continue;
            }

            $tbProgram->where('id', $id)->update([
                'anggaran'   => $this->sumAnggaran($db, 'kegiatan_pk', 'program_id', $id),
                'updated_at' => $now,
            ]);
        }

        return $stat;
    }

    /**
     * Total anggaran anak dari satu induk, dipakai untuk mengisi anggaran
     * entitas yang tidak punya barisnya sendiri di Lampiran 8.
     */
    private function sumAnggaran($db, string $table, string $parentColumn, int $parentId): int
    {
        $row = $db->table($table)
            ->selectSum('anggaran', 'total')
            ->where($parentColumn, $parentId)
            ->get()
            ->getRow();

        return (int) ($row->total ?? 0);
    }

    /**
     * Normalisasi satu ruas kode SIPD ke bentuk baku.
     *
     * Tiap segmen (dipisah titik) dibuang nol depannya lalu dikembalikan ke
     * lebar baku, sehingga "1"/"01", "2"/"02", dan "3"/"0003" selalu
     * menghasilkan nilai yang sama. Bentuk berlebar tetap ini juga membuat
     * pengurutan string pada kolom kode tetap urut secara numerik.
     *
     * @param int[] $widths lebar per segmen; segmen berikutnya memakai lebar terakhir
     *
     * @return string '' bila sel kosong atau bukan kode angka
     */
    private function normalizeKodeRuas($value, array $widths): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $fallbackWidth = $widths ? (int) $widths[array_key_last($widths)] : 1;
        $segments      = [];

        foreach (explode('.', $value) as $index => $segment) {
            $segment = trim($segment);

            // Bukan kode angka — mis. baris header ("PROGRAM"), "TOTAL", atau
            // blok tanda tangan. Dianggap tidak berkode supaya baris itu tidak
            // ikut terbaca sebagai Program/Kegiatan/Sub Kegiatan.
            if ($segment === '' || preg_match('/^\d+$/', $segment) !== 1) {
                return '';
            }

            $digits = ltrim($segment, '0');
            if ($digits === '') {
                $digits = '0';
            }

            $segments[] = str_pad($digits, $widths[$index] ?? $fallbackWidth, '0', STR_PAD_LEFT);
        }

        return implode('.', $segments);
    }

    /**
     * Rapikan uraian dari Excel: sel SIPD kerap memuat baris baru dan spasi
     * ganda hasil pembungkusan teks.
     */
    private function cleanUraian($value): string
    {
        $value = (string) $value;
        $clean = preg_replace('/\s+/u', ' ', $value);

        return trim($clean ?? $value);
    }

    /**
     * Baca anggaran (kolom K) pada satu baris.
     *
     * @return int|null null bila sel kosong, agar nilai lama tidak tertimpa 0
     */
    private function parseAnggaranCell(Worksheet $sheet, int $row): ?int
    {
        $cell = $sheet->getCell('K' . $row);
        $raw  = $cell->getValue();

        if (is_int($raw) || is_float($raw)) {
            return (int) round((float) $raw);
        }

        $text = trim((string) $raw);
        if ($text === '') {
            $text = trim((string) $cell->getFormattedValue());
        }
        if ($text === '' || $text === '-') {
            return null;
        }

        $negatif = str_starts_with($text, '(') || str_starts_with($text, '-');
        $text    = preg_replace('/[^0-9.,]/', '', $text);
        if ($text === '') {
            return null;
        }

        // Buang pecahan. SIPD mencetak "89,920,779,177.000000000" maupun
        // "190,754,000.00"; format Indonesia memakai "190.754.000,00".
        $dot   = strrpos($text, '.');
        $comma = strrpos($text, ',');
        $sep   = max($dot === false ? -1 : $dot, $comma === false ? -1 : $comma);

        if ($sep >= 0) {
            $tail = substr($text, $sep + 1);
            // Ruas terakhir tepat 3 digit = kelompok ribuan, bukan pecahan.
            if (preg_match('/^\d{3}$/', $tail) !== 1) {
                $text = substr($text, 0, $sep);
            }
        }

        $digits = preg_replace('/\D/', '', $text);
        if ($digits === '') {
            return 0;
        }

        return $negatif ? -(int) $digits : (int) $digits;
    }

    /**
     * Cari program dengan kunci import: opd_id + tahun + jenis anggaran + kode.
     *
     * @param array<string, int> $cache memo per proses import
     */
    private function findProgramId(
        $tbProgram,
        array &$cache,
        string $kodeProgram,
        int $opdId,
        int $tahun,
        string $jenisAnggaran
    ): ?int {
        if (isset($cache[$kodeProgram])) {
            return $cache[$kodeProgram];
        }

        $row = $tbProgram
            ->where('opd_id', $opdId)
            ->where('tahun_anggaran', $tahun)
            ->where('jenis_anggaran', $jenisAnggaran)
            ->where('kode_program', $kodeProgram)
            ->get()
            ->getRow();

        if (!$row) {
            return null;
        }

        return $cache[$kodeProgram] = (int) $row->id;
    }

    /**
     * Cari kegiatan di bawah program induknya: program_id + kode + tahun + jenis.
     *
     * @param array<string, int> $cache memo per proses import
     */
    private function findKegiatanId(
        $tbKegiatan,
        array &$cache,
        string $kodeKegiatan,
        int $programId,
        int $tahun,
        string $jenisAnggaran
    ): ?int {
        $cacheKey = $programId . '|' . $kodeKegiatan;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $row = $tbKegiatan
            ->where('program_id', $programId)
            ->where('kode_kegiatan', $kodeKegiatan)
            ->where('tahun_anggaran', $tahun)
            ->where('jenis_anggaran', $jenisAnggaran)
            ->get()
            ->getRow();

        if (!$row) {
            return null;
        }

        return $cache[$cacheKey] = (int) $row->id;
    }

    /**
     * Cari sub kegiatan di bawah kegiatan induknya:
     * kegiatan_id + kode + tahun + jenis.
     */
    private function findSubKegiatanId(
        $tbSub,
        string $kodeSub,
        int $kegiatanId,
        int $tahun,
        string $jenisAnggaran
    ): ?int {
        $row = $tbSub
            ->where('kegiatan_id', $kegiatanId)
            ->where('kode_sub_kegiatan', $kodeSub)
            ->where('tahun_anggaran', $tahun)
            ->where('jenis_anggaran', $jenisAnggaran)
            ->get()
            ->getRow();

        return $row ? (int) $row->id : null;
    }

    private function normalizeMoney($value): int
    {
        $value = trim((string) $value);
        $value = preg_replace('/[,.]\d{1,2}$/', '', $value);
        return (int) preg_replace('/\D/', '', (string) $value);
    }

    private function hasMoneyValue($value): bool
    {
        return preg_match('/\d/', (string) $value) === 1;
    }

    private function normalizeProgramPayload($programs): array
    {
        if (empty($programs) || !is_array($programs)) {
            throw new \InvalidArgumentException('Program tidak boleh kosong');
        }

        $programs = array_values($programs);
        $normalized = [];

        foreach ($programs as $pIndex => $p) {
            $namaProgram = trim((string) ($p['nama'] ?? ''));
            $rawAnggaranProgram = $p['anggaran'] ?? '';
            $anggaranProgram = $this->normalizeMoney($rawAnggaranProgram);

            if ($namaProgram === '') {
                throw new \InvalidArgumentException('Nama program wajib diisi');
            }
            if (!$this->hasMoneyValue($rawAnggaranProgram)) {
                throw new \InvalidArgumentException('Anggaran program tidak valid');
            }

            $kegiatanPayload = $p['kegiatan'] ?? [];
            if (empty($kegiatanPayload) || !is_array($kegiatanPayload)) {
                throw new \InvalidArgumentException('Minimal harus ada 1 kegiatan');
            }

            $programData = [
                'id' => isset($p['id']) ? (int) $p['id'] : null,
                'nama' => $namaProgram,
                'anggaran' => $anggaranProgram,
                'kegiatan' => [],
            ];

            foreach ($kegiatanPayload as $k) {
                $namaKegiatan = trim((string) ($k['nama'] ?? ''));
                $rawAnggaranKegiatan = $k['anggaran'] ?? '';
                $anggaranKegiatan = $this->normalizeMoney($rawAnggaranKegiatan);

                if ($namaKegiatan === '') {
                    throw new \InvalidArgumentException('Nama kegiatan wajib diisi');
                }
                if (!$this->hasMoneyValue($rawAnggaranKegiatan)) {
                    throw new \InvalidArgumentException('Anggaran kegiatan tidak valid');
                }

                $kegiatanData = [
                    'id' => isset($k['id']) ? (int) $k['id'] : null,
                    'nama' => $namaKegiatan,
                    'anggaran' => $anggaranKegiatan,
                    'sub' => [],
                ];

                foreach (($k['sub'] ?? []) as $s) {
                    $namaSub = trim((string) ($s['nama'] ?? ''));
                    $rawAnggaranSub = $s['anggaran'] ?? '';
                    $anggaranSub = $this->normalizeMoney($rawAnggaranSub);

                    if ($namaSub === '') {
                        throw new \InvalidArgumentException('Nama sub kegiatan wajib diisi');
                    }
                    if (!$this->hasMoneyValue($rawAnggaranSub)) {
                        throw new \InvalidArgumentException('Anggaran sub kegiatan tidak valid');
                    }

                    $kegiatanData['sub'][] = [
                        'id' => isset($s['id']) ? (int) $s['id'] : null,
                        'nama' => $namaSub,
                        'anggaran' => $anggaranSub,
                    ];
                }

                $programData['kegiatan'][] = $kegiatanData;
            }

            $normalized[] = $programData;
        }

        return $normalized;
    }

    private function normalizeIds(array $ids): array
    {
        $ids = array_map(static fn ($id) => (int) $id, $ids);
        $ids = array_filter($ids, static fn ($id) => $id > 0);

        return array_values(array_unique($ids));
    }

    private function deletePkSubkegiatanUsageBySubIds($db, array $subIds): void
    {
        $subIds = $this->normalizeIds($subIds);
        if (empty($subIds)) {
            return;
        }

        $db->table('pk_subkegiatan')
            ->whereIn('subkegiatan_id', $subIds)
            ->delete();
    }

    private function deletePkKegiatanUsageByKegiatanIds($db, array $kegiatanIds): void
    {
        $kegiatanIds = $this->normalizeIds($kegiatanIds);
        if (empty($kegiatanIds)) {
            return;
        }

        $pkKegiatanRows = $db->table('pk_kegiatan')
            ->select('id')
            ->whereIn('kegiatan_id', $kegiatanIds)
            ->get()
            ->getResultArray();
        $pkKegiatanIds = $this->normalizeIds(array_column($pkKegiatanRows, 'id'));

        if (!empty($pkKegiatanIds)) {
            $db->table('pk_subkegiatan')
                ->whereIn('pk_kegiatan_id', $pkKegiatanIds)
                ->delete();
            $db->table('pk_kegiatan')
                ->whereIn('id', $pkKegiatanIds)
                ->delete();
        }
    }

    private function deletePkProgramUsageByProgramIds($db, array $programIds): void
    {
        $programIds = $this->normalizeIds($programIds);
        if (empty($programIds)) {
            return;
        }

        $pkProgramRows = $db->table('pk_program')
            ->select('id')
            ->whereIn('program_id', $programIds)
            ->get()
            ->getResultArray();
        $pkProgramIds = $this->normalizeIds(array_column($pkProgramRows, 'id'));

        if (!empty($pkProgramIds)) {
            $pkKegiatanRows = $db->table('pk_kegiatan')
                ->select('id')
                ->whereIn('pk_program_id', $pkProgramIds)
                ->get()
                ->getResultArray();
            $pkKegiatanIds = $this->normalizeIds(array_column($pkKegiatanRows, 'id'));

            if (!empty($pkKegiatanIds)) {
                $db->table('pk_subkegiatan')
                    ->whereIn('pk_kegiatan_id', $pkKegiatanIds)
                    ->delete();
                $db->table('pk_kegiatan')
                    ->whereIn('id', $pkKegiatanIds)
                    ->delete();
            }

            $db->table('pk_program')
                ->whereIn('id', $pkProgramIds)
                ->delete();
        }
    }

    private function deleteProgramPkTree($db, int $programId): void
    {
        $kegiatanRows = $db->table('kegiatan_pk')
            ->select('id')
            ->where('program_id', $programId)
            ->get()
            ->getResultArray();
        $kegiatanIds = $this->normalizeIds(array_column($kegiatanRows, 'id'));

        if (!empty($kegiatanIds)) {
            $subRows = $db->table('sub_kegiatan_pk')
                ->select('id')
                ->whereIn('kegiatan_id', $kegiatanIds)
                ->get()
                ->getResultArray();
            $subIds = $this->normalizeIds(array_column($subRows, 'id'));

            $this->deletePkSubkegiatanUsageBySubIds($db, $subIds);
            $this->deletePkKegiatanUsageByKegiatanIds($db, $kegiatanIds);

            $db->table('sub_kegiatan_pk')
                ->whereIn('kegiatan_id', $kegiatanIds)
                ->delete();
            $db->table('kegiatan_pk')
                ->whereIn('id', $kegiatanIds)
                ->delete();
        }

        $this->deletePkProgramUsageByProgramIds($db, [$programId]);
        $db->table('program_pk')->where('id', $programId)->delete();
    }

    public function save()
    {
        $db = \Config\Database::connect();
        $transactionStarted = false;

        try {
            $tahun = (int) $this->request->getPost('tahun_anggaran');
            $opdId = (int) $this->request->getPost('opd_id');
            $jenisAnggaran = trim((string) $this->request->getPost('jenis_anggaran'));
            $programs = $this->normalizeProgramPayload($this->request->getPost('program'));

            if ($tahun <= 0) {
                throw new \InvalidArgumentException('Tahun anggaran wajib diisi');
            }
            if ($opdId <= 0) {
                throw new \InvalidArgumentException('OPD wajib dipilih');
            }
            if ($jenisAnggaran === '') {
                throw new \InvalidArgumentException('Jenis anggaran wajib dipilih');
            }

            $db->transException(true)->transBegin();
            $transactionStarted = true;

            foreach ($programs as $p) {
                $db->table('program_pk')->insert([
                    'kode_program' => uniqid('PRG-'),
                    'opd_id' => $opdId,
                    'program_kegiatan' => $p['nama'],
                    'tahun_anggaran' => $tahun,
                    'anggaran' => $p['anggaran'],
                    'jenis_anggaran' => $jenisAnggaran,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                $programId = $db->insertID();

                foreach ($p['kegiatan'] as $k) {
                    $db->table('kegiatan_pk')->insert([
                        'program_id' => $programId,
                        'kode_kegiatan' => uniqid('KEG-'),
                        'kegiatan' => $k['nama'],
                        'tahun_anggaran' => $tahun,
                        'anggaran' => $k['anggaran'],
                        'jenis_anggaran' => $jenisAnggaran,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);

                    $kegiatanId = $db->insertID();

                    foreach ($k['sub'] as $s) {
                        $db->table('sub_kegiatan_pk')->insert([
                            'kegiatan_id' => $kegiatanId,
                            'kode_sub_kegiatan' => uniqid('SUB-'),
                            'sub_kegiatan' => $s['nama'],
                            'tahun_anggaran' => $tahun,
                            'anggaran' => $s['anggaran'],
                            'jenis_anggaran' => $jenisAnggaran,
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                }
            }

            $db->transCommit();
            $transactionStarted = false;

            return redirect()->to('/adminkab/program_pk')->with('success', 'Data berhasil disimpan');
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                $db->transRollback();
            }

            log_message('error', 'Gagal menyimpan Program PK: ' . $e->getMessage());
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Show form for editing program
     */
    public function edit($id)
    {
        $program = $this->programPkModel->getProgramWithDetails($id);

        if (!$program) {
            session()->setFlashdata('error', 'Program PK tidak ditemukan');
            return redirect()->to('/adminkab/program_pk');
        }

        // dd($program);

        $data = [
            'title' => 'Edit Program PK',
            'program' => $program,
            'validation' => session()->getFlashdata('validation'),
            'opds' => $this->OpdModel->getAllOpd(),
        ];

        return view('adminKabupaten/program_pk/edit_program', $data);
    }

    /**
     * Update program
     */
    public function update($id)
    {
        $db = \Config\Database::connect();
        $transactionStarted = false;

        try {
            $program = $this->programPkModel->getProgramById($id);
            if (!$program) {
                throw new \InvalidArgumentException('Program PK tidak ditemukan');
            }

            $programs = $this->normalizeProgramPayload($this->request->getPost('program'));
            $programData = $programs[0];
            $tahun = (int) $this->request->getPost('tahun_anggaran');
            $opdId = (int) $this->request->getPost('opd_id');
            $jenisAnggaran = trim((string) $this->request->getPost('jenis_anggaran'));

            if ($tahun <= 0) {
                throw new \InvalidArgumentException('Tahun anggaran wajib diisi');
            }
            if ($opdId <= 0) {
                throw new \InvalidArgumentException('OPD wajib dipilih');
            }
            if ($jenisAnggaran === '') {
                throw new \InvalidArgumentException('Jenis anggaran wajib dipilih');
            }

            $db->transException(true)->transBegin();
            $transactionStarted = true;
            $now = date('Y-m-d H:i:s');

            $db->table('program_pk')
                ->where('id', $id)
                ->update([
                    'program_kegiatan' => $programData['nama'],
                    'anggaran' => $programData['anggaran'],
                    'opd_id' => $opdId,
                    'tahun_anggaran' => $tahun,
                    'jenis_anggaran' => $jenisAnggaran,
                    'updated_at' => $now
                ]);

            $existingKegiatanRows = $db->table('kegiatan_pk')
                ->select('id')
                ->where('program_id', $id)
                ->get()
                ->getResultArray();
            $existingKegiatanIds = $this->normalizeIds(array_column($existingKegiatanRows, 'id'));
            $usedKegiatanIds = [];

            foreach ($programData['kegiatan'] as $k) {
                $kegiatanId = (int) ($k['id'] ?? 0);
                $kegiatanPayload = [
                    'kegiatan' => $k['nama'],
                    'anggaran' => $k['anggaran'],
                    'tahun_anggaran' => $tahun,
                    'jenis_anggaran' => $jenisAnggaran,
                    'updated_at' => $now
                ];

                if ($kegiatanId > 0 && in_array($kegiatanId, $existingKegiatanIds, true)) {
                    $db->table('kegiatan_pk')
                        ->where('id', $kegiatanId)
                        ->update($kegiatanPayload);
                } else {
                    $kegiatanPayload['program_id'] = $id;
                    $kegiatanPayload['kode_kegiatan'] = uniqid('KEG-');
                    $kegiatanPayload['created_at'] = $now;
                    $db->table('kegiatan_pk')->insert($kegiatanPayload);
                    $kegiatanId = (int) $db->insertID();
                }
                $usedKegiatanIds[] = $kegiatanId;

                $existingSubRows = $db->table('sub_kegiatan_pk')
                    ->select('id')
                    ->where('kegiatan_id', $kegiatanId)
                    ->get()
                    ->getResultArray();
                $existingSubIds = $this->normalizeIds(array_column($existingSubRows, 'id'));
                $usedSubIds = [];

                foreach ($k['sub'] as $s) {
                    $subId = (int) ($s['id'] ?? 0);
                    $subPayload = [
                        'sub_kegiatan' => $s['nama'],
                        'anggaran' => $s['anggaran'],
                        'tahun_anggaran' => $tahun,
                        'jenis_anggaran' => $jenisAnggaran,
                        'updated_at' => $now
                    ];

                    if ($subId > 0 && in_array($subId, $existingSubIds, true)) {
                        $db->table('sub_kegiatan_pk')
                            ->where('id', $subId)
                            ->update($subPayload);
                    } else {
                        $subPayload['kegiatan_id'] = $kegiatanId;
                        $subPayload['kode_sub_kegiatan'] = uniqid('SUB-');
                        $subPayload['created_at'] = $now;
                        $db->table('sub_kegiatan_pk')->insert($subPayload);
                        $subId = (int) $db->insertID();
                    }
                    $usedSubIds[] = $subId;
                }

                $deleteSubIds = array_diff($existingSubIds, $usedSubIds);
                if (!empty($deleteSubIds)) {
                    $this->deletePkSubkegiatanUsageBySubIds($db, $deleteSubIds);
                    $db->table('sub_kegiatan_pk')
                        ->whereIn('id', $deleteSubIds)
                        ->delete();
                }
            }

            $deleteKegiatanIds = array_diff($existingKegiatanIds, $usedKegiatanIds);
            if (!empty($deleteKegiatanIds)) {
                $subRows = $db->table('sub_kegiatan_pk')
                    ->select('id')
                    ->whereIn('kegiatan_id', $deleteKegiatanIds)
                    ->get()
                    ->getResultArray();
                $this->deletePkSubkegiatanUsageBySubIds($db, array_column($subRows, 'id'));
                $this->deletePkKegiatanUsageByKegiatanIds($db, $deleteKegiatanIds);

                $db->table('sub_kegiatan_pk')
                    ->whereIn('kegiatan_id', $deleteKegiatanIds)
                    ->delete();
                $db->table('kegiatan_pk')
                    ->whereIn('id', $deleteKegiatanIds)
                    ->delete();
            }

            $db->transCommit();
            $transactionStarted = false;

            return redirect()->to('/adminkab/program_pk')
                ->with('success', 'Program berhasil diperbarui');
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                $db->transRollback();
            }

            log_message('error', 'Gagal memperbarui Program PK ID ' . $id . ': ' . $e->getMessage());
            return redirect()->back()->withInput()->with('error', 'Gagal memperbarui data: ' . $e->getMessage());
        }
    }

    /**
     * Delete program
     */
    public function delete($id)
    {
        $program = $this->programPkModel->getProgramById($id);

        if (!$program) {
            session()->setFlashdata('error', 'Program PK tidak ditemukan');
            return redirect()->to('/adminkab/program_pk');
        }

        $db = \Config\Database::connect();
        try {
            $db->transException(true)->transBegin();
            $this->deleteProgramPkTree($db, (int) $id);
            $db->transCommit();
            session()->setFlashdata('success', 'Program PK berhasil dihapus');
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Gagal menghapus Program PK ID ' . $id . ': ' . $e->getMessage());
            session()->setFlashdata('error', 'Gagal menghapus program PK: ' . $e->getMessage());
        }

        return redirect()->to('/adminkab/program_pk');
    }
}
