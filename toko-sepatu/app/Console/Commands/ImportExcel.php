<?php

namespace App\Console\Commands;

use App\Services\ExcelImporter;
use Illuminate\Console\Command;

class ImportExcel extends Command
{
    protected $signature = 'import:excel {folder=data : Folder berisi file .xlsx}
                            {--force : Tetap baca file walaupun hash-nya sama}';

    protected $description = 'Impor semua file Excel di folder ke database (aman diulang)';

    public function handle(ExcelImporter $importer): int
    {
        $results = $importer->importFolder($this->argument('folder'), (bool) $this->option('force'));

        $this->table(
            ['File', 'Status', 'Baris baru', 'Sama (dilewati)', 'Berubah (konflik)'],
            array_map(fn ($r) => [$r['file'], $r['status'], $r['inserted'], $r['unchanged'], $r['changed']], $results)
        );

        return self::SUCCESS;
    }
}