<?php

namespace Tests\Feature;

use App\Services\ExcelImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private const TABEL = ['stok_awal', 'saldo_bin', 'aktivitas', 'hitung_fisik', 'pesanan_online',
        'item_online', 'proses_online', 'retur_online', 'struk_kasir', 'item_kasir'];

    private function hitung(): array
    {
        return collect(self::TABEL)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    public function test_impor_ulang_tidak_menggandakan_transaksi(): void
    {
        $importer = app(ExcelImporter::class);

        $importer->importFolder(base_path('data'));
        $pertama = $this->hitung();

        // Impor biasa kedua: file dilewati karena hash sama.
        $importer->importFolder(base_path('data'));
        $this->assertSame($pertama, $this->hitung());

        // Impor paksa: setiap baris dibaca ulang, tetap tidak ada yang bertambah.
        $hasil = $importer->importFolder(base_path('data'), force: true);
        $this->assertSame($pertama, $this->hitung());
        $this->assertSame(0, array_sum(array_column($hasil, 'inserted')));
        $this->assertSame(0, DB::table('import_conflict')->count());

        $this->assertSame(560, $pertama['aktivitas']);
        $this->assertSame(57, $pertama['pesanan_online']);
    }

    public function test_setiap_baris_menyimpan_file_sheet_dan_nomor_baris(): void
    {
        app(ExcelImporter::class)->importFolder(base_path('data'));

        $row = DB::table('aktivitas')->where('id_aktivitas', 'ACT-0003')->first();
        $this->assertSame('01-gudang.xlsx', $row->src_file);
        $this->assertSame('Aktivitas', $row->src_sheet);
        $this->assertSame(2, (int) $row->src_row);
    }
}