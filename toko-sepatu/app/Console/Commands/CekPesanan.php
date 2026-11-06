<?php

namespace App\Console\Commands;

use App\Services\OrderService;
use Illuminate\Console\Command;

class CekPesanan extends Command
{
    protected $signature = 'pesanan:cek {nomor : Nomor pesanan, mis. ORD-0057} {--json}';

    protected $description = 'Tampilkan item, kejadian, dan keputusan kemas/serah untuk satu pesanan';

    public function handle(OrderService $service): int
    {
        $r = $service->check($this->argument('nomor'));

        if (! $r) {
            $this->error("Pesanan {$this->argument('nomor')} tidak ditemukan di Pesanan Online. Cek ejaan nomor, atau apakah file online terbaru sudah diimpor.");

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $o = $r['pesanan'];
        $this->info("Pesanan {$o->nomor_pesanan} ({$o->status_pesanan} / {$o->status_internal}), lokasi {$o->lokasi}");

        $this->table(['Item', 'SKU', 'Jumlah', 'Retur', 'Asal'], array_map(fn ($i) => [
            $i->id_item, $i->sku, $i->jumlah, $i->jumlah_retur, "{$i->src_file} / {$i->src_sheet} / {$i->src_row}",
        ], $r['items']));

        $this->table(['Waktu', 'Kejadian', 'Asal'], array_map(fn ($e) => [$e['waktu'], $e['kejadian'], $e['asal']], $r['kejadian']));

        $this->line("1) Boleh mulai ambil/kemas?        {$r['boleh_kemas']['jawaban']} — {$r['boleh_kemas']['alasan']}");
        $this->line("2) Boleh serahkan ke pelanggan/kurir? {$r['boleh_serah']['jawaban']} — {$r['boleh_serah']['alasan']}");

        if ($c = $r['pembatalan']) {
            $this->newLine();
            $this->info('Pembatalan / retur');
            foreach ($c['status_barang'] as $x) {
                $this->line("  Barang: {$x}");
            }
            foreach ($c['status_pembayaran'] as $x) {
                $this->line("  Pembayaran: {$x}");
            }
            foreach ($c['belum_diketahui'] as $x) {
                $this->line("  Belum diketahui: {$x}");
            }
        }
        foreach ($r['catatan'] as $c) {
            $this->warn("Catatan: {$c}");
        }

        return self::SUCCESS;
    }
}