<?php

namespace App\Console\Commands;

use App\Services\StockService;
use Illuminate\Console\Command;

class CekStok extends Command
{
    protected $signature = 'stok:cek {sku} {lokasi} {waktu : WIB, format "YYYY-MM-DD HH:MM:SS"} {--json}';

    protected $description = 'Hitung unit yang dapat dijual untuk SKU + lokasi pada waktu tertentu';

    public function handle(StockService $service): int
    {
        $r = $service->check($this->argument('sku'), $this->argument('lokasi'), $this->argument('waktu'));

        if ($this->option('json')) {
            $this->line(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Cek stok {$r['sku']} di {$r['lokasi']} sampai {$r['waktu']} WIB");

        if ($r['dapat_dihitung']) {
            $this->line("Stok fisik menurut catatan : {$r['stok_fisik']}");
            $this->line("Unit dapat dijual          : {$r['dapat_dijual']}");
        } else {
            $this->error('Angka tidak dapat dihitung.');
        }
        foreach ($r['alasan'] as $a) {
            $this->line("  Alasan: {$a}");
        }
        foreach ($r['data_kurang'] as $d) {
            $this->line("  Data yang kurang: {$d}");
        }

        $this->newLine();
        $this->line('Asumsi: stok = Stok Awal + Aktivitas (mengubah_stok = 1) berdasarkan waktu_kejadian <= waktu diminta.');

        if ($a = $r['stok_awal']) {
            $this->line("Stok Awal {$a->jumlah} pada {$a->waktu} ({$a->src_file} / {$a->src_sheet} / baris {$a->src_row})");
        }
        if ($r['aktivitas']) {
            $this->table(['ID', 'Waktu kejadian', 'Jenis', 'Jumlah', 'Masuk laporan', 'Asal'], array_map(fn ($x) => [
                $x->id_aktivitas, $x->waktu_kejadian, $x->jenis, $x->jumlah, $x->waktu_masuk_laporan,
                "{$x->src_file} / {$x->src_sheet} / {$x->src_row}",
            ], $r['aktivitas']));
        }
        foreach ($r['catatan'] as $c) {
            $this->warn("Catatan: {$c}");
        }
        if ($s = $r['saldo_bin']) {
            $this->line("Saldo BIN pada waktu yang sama: {$s['total']} (selisih saldo − program = {$s['selisih_saldo_minus_program']})");
        }

        $this->newLine();
        $this->info('Hasil hitung fisik untuk SKU dan lokasi ini:');
        if (! $r['hitung_fisik']) {
            $this->line('  Tidak ada data hitung fisik.');
        }
        foreach ($r['hitung_fisik'] as $h) {
            $b = $h['baris'];
            $this->line("- {$b->id_baris} ({$b->id_hitung}) {$b->waktu_baris}, BIN " . ($b->bin ?? '-')
                . ", sistem saat itu {$b->jumlah_sistem_saat_itu}, dihitung {$b->jumlah_dihitung}, selisih dicatat {$b->selisih_dicatat}"
                . " ({$b->src_file} / {$b->src_sheet} / baris {$b->src_row})");
            if ($h['bisa_dibandingkan']) {
                $this->line("    Hitung fisik − program = {$h['selisih_fisik_minus_program']} (program saat hitung: {$h['stok_program_saat_hitung']})");
            } else {
                foreach ($h['alasan'] as $a) {
                    $this->line("    Tidak dibandingkan: {$a}");
                }
            }
        }

        return self::SUCCESS;
    }
}