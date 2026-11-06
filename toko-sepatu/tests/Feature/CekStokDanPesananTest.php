<?php

namespace Tests\Feature;

use App\Services\ExcelImporter;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CekStokDanPesananTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ExcelImporter::class)->importFolder(base_path('data'));
    }

    public function test_stok_ukuran_42_di_cabang_sama_dengan_saldo_bin(): void
    {
        $r = app(StockService::class)->check('SKU-0045', 'CABANG-PUSAT', '2026-11-05 04:36:09');

        $this->assertTrue($r['dapat_dihitung']);
        $this->assertSame(32, $r['stok_fisik']);
        $this->assertSame(0, $r['saldo_bin']['selisih_saldo_minus_program']);
    }

    public function test_stok_program_cocok_dengan_saldo_bin_untuk_semua_sku(): void
    {
        $service = app(StockService::class);
        foreach (DB::table('stok_awal')->get() as $awal) {
            $r = $service->check($awal->sku, $awal->lokasi, '2026-11-05 04:36:09');
            $saldo = (int) DB::table('saldo_bin')->where('sku', $awal->sku)->where('lokasi', $awal->lokasi)->sum('jumlah_saat_ekspor');
            $this->assertSame($saldo, $r['stok_fisik'], "{$awal->sku} {$awal->lokasi}");
        }
    }

    public function test_stok_sebelum_stok_awal_tidak_bisa_dihitung(): void
    {
        $r = app(StockService::class)->check('SKU-0002', 'CABANG-PUSAT', '2026-10-01 00:00:00');

        $this->assertFalse($r['dapat_dihitung']);
        $this->assertNotEmpty($r['alasan']);
        $this->assertFalse($r['hitung_fisik'][0]['bisa_dibandingkan']);
    }

    public function test_pesanan_siap_diambil_tanpa_bukti_bayar(): void
    {
        $r = app(OrderService::class)->check('ORD-0057');

        $this->assertSame(OrderService::TIDAK_BOLEH, $r['boleh_kemas']['jawaban']);
        $this->assertSame(OrderService::BELUM_PASTI, $r['boleh_serah']['jawaban']);
    }

    public function test_pesanan_batal_dengan_refund(): void
    {
        $r = app(OrderService::class)->check('ORD-0006');

        $this->assertSame(OrderService::TIDAK_BOLEH, $r['boleh_kemas']['jawaban']);
        $this->assertSame(OrderService::TIDAK_BOLEH, $r['boleh_serah']['jawaban']);
        $this->assertNotEmpty($r['pembatalan']['belum_diketahui']);
    }
}