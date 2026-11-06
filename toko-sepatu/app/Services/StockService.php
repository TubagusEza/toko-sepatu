<?php

namespace App\Services;

use DateTime;
use Illuminate\Support\Facades\DB;

/**
 * Cek stok per SKU + lokasi pada waktu tertentu (WIB).
 *
 * Rumus: stok = Stok Awal + jumlah Aktivitas (mengubah_stok = 1)
 *        dengan Stok Awal.waktu < waktu_kejadian <= waktu diminta.
 *
 * Kasir dan Proses Online TIDAK ditambahkan, karena kejadiannya sudah tercatat
 * di Aktivitas (Stok Awal + Aktivitas = Saldo BIN untuk semua SKU/lokasi).
 */
class StockService
{
    public function check(string $sku, string $lokasi, string $waktu): array
    {
        $result = [
            'sku' => $sku, 'lokasi' => $lokasi, 'waktu' => $waktu,
            'dapat_dihitung' => false, 'stok_fisik' => null, 'dapat_dijual' => null,
            'alasan' => [], 'data_kurang' => [], 'catatan' => [],
            'stok_awal' => null, 'aktivitas' => [], 'saldo_bin' => null, 'hitung_fisik' => [],
        ];

        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $waktu);
        if (! $dt || $dt->format('Y-m-d H:i:s') !== $waktu) {
            $result['alasan'][] = 'Format waktu harus YYYY-MM-DD HH:MM:SS (WIB).';

            return $result;
        }
        if (! DB::table('produk')->where('sku', $sku)->exists()) {
            $result['alasan'][] = "SKU {$sku} tidak ada di Produk.";
            $result['data_kurang'][] = '00-referensi.xlsx / Produk';

            return $result;
        }
        if (! DB::table('lokasi')->where('kode_lokasi', $lokasi)->exists()) {
            $result['alasan'][] = "Lokasi {$lokasi} tidak ada di Lokasi.";
            $result['data_kurang'][] = '00-referensi.xlsx / Lokasi';

            return $result;
        }

        // Hitung fisik selalu ditampilkan, walaupun stok tidak bisa dihitung.
        $hitung = DB::table('hitung_fisik')->where('sku', $sku)->where('lokasi', $lokasi)
            ->orderBy('waktu_baris')->get();

        $awal = DB::table('stok_awal')->where('sku', $sku)->where('lokasi', $lokasi)
            ->where('waktu', '<=', $waktu)->orderByDesc('waktu')->first();

        if (! $awal) {
            $pertama = DB::table('stok_awal')->min('waktu');
            $result['alasan'][] = "Tidak ada Stok Awal untuk {$sku} di {$lokasi} pada atau sebelum {$waktu}"
                . ($pertama ? " (Stok Awal paling awal: {$pertama})." : '.');
            $result['data_kurang'][] = "Stok awal / hasil hitung lengkap untuk {$sku} di {$lokasi} sebelum {$waktu}";
            $result['hitung_fisik'] = $this->compareCounts($hitung, $sku, $lokasi);

            return $result;
        }

        $rows = $this->movements($sku, $lokasi, $awal->waktu, $waktu);
        $stok = $awal->jumlah + $rows->sum('jumlah');

        $result['dapat_dihitung'] = true;
        $result['stok_fisik'] = $stok;
        $result['dapat_dijual'] = max($stok, 0);
        $result['stok_awal'] = $awal;
        $result['aktivitas'] = $rows->all();

        // --- Catatan dan peringatan ---
        if ($stok < 0) {
            $result['dapat_dihitung'] = false;
            $result['alasan'][] = "Hasil hitung negatif ({$stok}). Ada kejadian masuk yang belum tercatat.";
            $result['data_kurang'][] = 'Penerimaan / retur masuk yang belum ada di Aktivitas';
        }

        $telat = $rows->filter(fn ($r) => $r->waktu_masuk_laporan && $r->waktu_masuk_laporan > $waktu);
        foreach ($telat as $r) {
            $result['catatan'][] = "{$r->id_aktivitas} terjadi {$r->waktu_kejadian} tetapi baru masuk laporan {$r->waktu_masuk_laporan} "
                . "({$r->src_file} / {$r->src_sheet} / baris {$r->src_row}). Laporan pada jam yang diminta belum memuat kejadian ini.";
        }

        $laporanTerakhir = DB::table('aktivitas')->max('waktu_masuk_laporan');
        if ($laporanTerakhir && $waktu > $laporanTerakhir) {
            $result['catatan'][] = "Laporan Aktivitas terakhir masuk {$laporanTerakhir}. Kejadian setelah itu belum tentu tercatat.";
        }

        $reserved = DB::table('aktivitas')->where('sku', $sku)->where('lokasi', $lokasi)
            ->where('jenis', 'reserved')->where('waktu_kejadian', '<=', $waktu)->get();
        foreach ($reserved as $r) {
            $result['catatan'][] = "Ada reservasi {$r->jumlah} unit ({$r->id_aktivitas}, {$r->waktu_kejadian}, "
                . "{$r->src_file} / {$r->src_sheet} / baris {$r->src_row}) yang tidak mengubah stok. "
                . 'Data tidak menunjukkan apakah reservasi masih aktif; jika masih aktif, unit dapat dijual berkurang '
                . abs($r->jumlah) . '.';
        }

        $result['catatan'][] = 'Data kasir dan proses online tidak ditambahkan lagi ke stok karena sudah tercermin di Aktivitas.';

        // Saldo BIN: hanya pembanding jika waktu ekspornya sama persis dengan waktu yang diminta.
        $saldo = DB::table('saldo_bin')->where('sku', $sku)->where('lokasi', $lokasi)
            ->where('waktu_ekspor', $waktu)->get();
        if ($saldo->isNotEmpty()) {
            $result['saldo_bin'] = [
                'total' => $saldo->sum('jumlah_saat_ekspor'),
                'selisih_saldo_minus_program' => $saldo->sum('jumlah_saat_ekspor') - $stok,
                'baris' => $saldo->all(),
            ];
        }

        $result['hitung_fisik'] = $this->compareCounts($hitung, $sku, $lokasi);

        return $result;
    }

    private function movements(string $sku, string $lokasi, string $dari, string $sampai)
    {
        return DB::table('aktivitas')
            ->where('sku', $sku)->where('lokasi', $lokasi)
            ->where('mengubah_stok', true)
            ->where('waktu_kejadian', '>', $dari)
            ->where('waktu_kejadian', '<=', $sampai)
            ->orderBy('waktu_kejadian')->orderBy('id_aktivitas')
            ->get();
    }

    /**
     * Bandingkan setiap hitung fisik dengan stok program PADA WAKTU HITUNG itu sendiri.
     * Syarat: barang sama (sudah difilter), cakupan sama (seluruh lokasi, bukan satu BIN),
     * waktu bisa dihitung (ada Stok Awal sebelum waktu hitung), dan status hitung closed.
     */
    private function compareCounts($hitung, string $sku, string $lokasi): array
    {
        $out = [];
        foreach ($hitung as $h) {
            $item = ['baris' => $h, 'bisa_dibandingkan' => false, 'alasan' => [], 'selisih_fisik_minus_program' => null];

            if ($h->bin) {
                $item['alasan'][] = "Hitung fisik hanya untuk {$h->bin}, sedangkan cek stok berlaku untuk seluruh {$lokasi}. "
                    . 'Stok per BIN tidak bisa dihitung karena bin_mutation di Aktivitas tidak membawa jumlah.';
            }
            if ($h->status_hitung !== 'closed') {
                $item['alasan'][] = "Status hitung {$h->status_hitung}, belum final.";
            }
            $awal = $h->waktu_baris
                ? DB::table('stok_awal')->where('sku', $sku)->where('lokasi', $lokasi)
                    ->where('waktu', '<=', $h->waktu_baris)->orderByDesc('waktu')->first()
                : null;
            if (! $awal) {
                $item['alasan'][] = "Waktu hitung {$h->waktu_baris} sebelum Stok Awal, jadi program tidak punya angka pada waktu itu.";
            }

            if (! $item['alasan']) {
                $program = $awal->jumlah + $this->movements($sku, $lokasi, $awal->waktu, $h->waktu_baris)->sum('jumlah');
                $item['bisa_dibandingkan'] = true;
                $item['stok_program_saat_hitung'] = $program;
                $item['selisih_fisik_minus_program'] = $h->jumlah_dihitung - $program;
            }
            $out[] = $item;
        }

        return $out;
    }
}