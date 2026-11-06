<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

/**
 * Mengimpor file Excel ke database tanpa mengubah file aslinya.
 *
 * Idempoten:
 *  1. File dengan hash yang sama dengan impor sukses sebelumnya dilewati (kecuali --force).
 *  2. Setiap baris dicari dengan kunci bisnisnya. Baris baru disisipkan; baris yang sudah ada
 *     dan isinya sama diabaikan; baris yang sudah ada tetapi isinya berbeda dicatat di
 *     import_conflict dan TIDAK menimpa data lama.
 */
class ExcelImporter
{
    /** Urutan file: referensi dulu (master), lalu transaksi. */
    public function importFolder(string $folder, bool $force = false): array
    {
        $files = glob(rtrim($folder, '/') . '/*.xlsx');
        sort($files);
        if (! $files) {
            throw new RuntimeException("Tidak ada file .xlsx di folder {$folder}");
        }

        $batchId = DB::table('import_batch')->insertGetId(['started_at' => now()]);
        $results = [];
        foreach ($files as $file) {
            $results[] = $this->importFile($file, $batchId, $force);
        }
        DB::table('import_batch')->where('id', $batchId)->update(['finished_at' => now()]);

        return $results;
    }

    public function importFile(string $path, ?int $batchId = null, bool $force = false): array
    {
        $batchId ??= DB::table('import_batch')->insertGetId(['started_at' => now()]);
        $fileName = basename($path);
        $hash = hash_file('sha256', $path);

        $sudahPernah = DB::table('import_file')
            ->where('file_hash', $hash)->where('status', 'imported')->exists();

        if ($sudahPernah && ! $force) {
            DB::table('import_file')->insert([
                'batch_id' => $batchId, 'file_name' => $fileName, 'file_hash' => $hash,
                'status' => 'skipped_same_hash', 'imported_at' => now(),
            ]);

            return ['file' => $fileName, 'status' => 'skipped_same_hash', 'inserted' => 0, 'unchanged' => 0, 'changed' => 0];
        }

        $fileId = DB::table('import_file')->insertGetId([
            'batch_id' => $batchId, 'file_name' => $fileName, 'file_hash' => $hash,
            'status' => 'failed', 'imported_at' => now(),
        ]);

        // Hanya dibaca; file Excel tidak pernah disimpan ulang.
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $count = ['inserted' => 0, 'unchanged' => 0, 'changed' => 0];

        DB::transaction(function () use ($spreadsheet, $fileName, $fileId, &$count) {
            foreach (SheetMap::all() as $sheetName => $map) {
                $sheet = $spreadsheet->getSheetByName($sheetName);
                if (! $sheet) {
                    continue; // sheet ini tidak ada di file ini
                }
                $rows = $sheet->toArray(null, false, false, false);
                $header = array_map(fn ($h) => trim((string) $h), array_shift($rows) ?? []);

                $missing = array_diff(array_keys($map['columns']), $header);
                if ($missing) {
                    throw new RuntimeException("{$fileName} / {$sheetName}: kolom tidak ditemukan: " . implode(', ', $missing));
                }

                foreach ($rows as $i => $raw) {
                    $excelRow = $i + 2; // baris 1 = header
                    if (count(array_filter($raw, fn ($v) => $v !== null && $v !== '')) === 0) {
                        continue; // baris kosong
                    }
                    $record = [];
                    foreach ($map['columns'] as $col => $type) {
                        $record[$col] = $this->convert($raw[array_search($col, $header)], $type);
                    }
                    if ($map['table'] === 'produk') {
                        $record['ukuran_raw'] = $record['ukuran'];
                        $record['ukuran'] = $record['ukuran'] === '-' ? null : $record['ukuran'];
                    }

                    $result = $this->saveRow($map, $record, $fileName, $sheetName, $excelRow, $fileId);
                    $count[$result]++;
                }
            }
        });

        DB::table('import_file')->where('id', $fileId)->update([
            'status' => 'imported',
            'rows_inserted' => $count['inserted'],
            'rows_unchanged' => $count['unchanged'],
            'rows_changed' => $count['changed'],
        ]);

        return ['file' => $fileName, 'status' => 'imported'] + $count;
    }

    private function saveRow(array $map, array $record, string $file, string $sheet, int $row, int $fileId): string
    {
        $rowHash = hash('sha256', json_encode($record));
        $keyValues = array_intersect_key($record, array_flip($map['key']));

        $existing = DB::table($map['table'])->where($keyValues)->first(['row_hash']);

        if (! $existing) {
            DB::table($map['table'])->insert($record + [
                'src_file' => $file, 'src_sheet' => $sheet, 'src_row' => $row,
                'row_hash' => $rowHash, 'import_file_id' => $fileId,
            ]);

            return 'inserted';
        }

        if ($existing->row_hash === $rowHash) {
            return 'unchanged';
        }

        DB::table('import_conflict')->insert([
            'import_file_id' => $fileId,
            'target_table' => $map['table'],
            'business_key' => implode('|', $keyValues),
            'src_sheet' => $sheet,
            'src_row' => $row,
            'old_row_hash' => $existing->row_hash,
            'new_row_hash' => $rowHash,
            'new_payload' => json_encode($record),
            'created_at' => now(),
        ]);

        return 'changed';
    }

    private function convert(mixed $value, string $type): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return match ($type) {
            'int' => (int) $value,
            'bool' => in_array(strtolower(trim((string) $value)), ['1', 'true', 'ya', 'y'], true),
            'datetime' => $this->toDate($value)->format('Y-m-d H:i:s'),
            'date' => $this->toDate($value)->format('Y-m-d'),
            default => trim((string) $value),
        };
    }

    /** Excel bisa menyimpan tanggal sebagai teks atau sebagai angka serial. Keduanya WIB. */
    private function toDate(mixed $value): Carbon
    {
        if (is_numeric($value)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value));
        }

        return Carbon::parse(trim((string) $value));
    }
}