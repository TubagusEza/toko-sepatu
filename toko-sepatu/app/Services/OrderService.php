<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Cek pesanan online: item, kejadian, dan dua keputusan terpisah
 * (boleh ambil/kemas? boleh serahkan ke pelanggan/kurir?).
 */
class OrderService
{
    public const BOLEH = 'BOLEH';
    public const TIDAK_BOLEH = 'TIDAK BOLEH';
    public const BELUM_PASTI = 'BELUM BISA DIPASTIKAN';

    /** Status pesanan yang berarti barang sudah keluar ke kurir/pelanggan. */
    private const SUDAH_DISERAHKAN = ['SHIPPED', 'IN_TRANSIT', 'DELIVERED', 'TO_CONFIRM_RECEIVE', 'COMPLETED'];

    /** Status yang dikenali; status lain dijawab "belum bisa dipastikan". */
    private const STATUS_DIKENAL = ['SHIPPED', 'IN_TRANSIT', 'DELIVERED', 'TO_CONFIRM_RECEIVE', 'COMPLETED',
        'CANCELLED', 'AWAITING_COLLECTION'];

    public function check(string $nomor): ?array
    {
        $order = DB::table('pesanan_online')->where('nomor_pesanan', $nomor)->first();
        if (! $order) {
            return null;
        }

        $items = DB::table('item_online')->where('nomor_pesanan', $nomor)->orderBy('id_item')->get();
        $proses = DB::table('proses_online')->whereIn('id_item', $items->pluck('id_item'))
            ->orderBy('waktu_catat')->get();
        $aktivitas = DB::table('aktivitas')->whereIn('id_asal', $proses->pluck('id_proses'))->get();
        $retur = DB::table('retur_online')->where('nomor_pesanan', $nomor)->get();

        $events = $this->timeline($order, $proses, $aktivitas, $retur);

        // ---------- Fakta dari data ----------
        $batal = $order->status_pesanan === 'CANCELLED' || $order->status_internal === 'CANCEL';
        $adaRetur = $retur->isNotEmpty();
        $dibayar = $order->tanggal_bayar !== null;
        $sudahDiserahkan = in_array($order->status_pesanan, self::SUDAH_DISERAHKAN, true);
        $statusDikenal = in_array($order->status_pesanan, self::STATUS_DIKENAL, true);

        $perItem = [];
        $catatan = [];
        foreach ($items as $item) {
            $p = $proses->where('id_item', $item->id_item);
            $kemas = $p->first(fn ($x) => $x->waktu_kemas !== null);
            $done = $p->first(fn ($x) => str_starts_with($x->status, 'DONE'));
            $keluar = $aktivitas->whereIn('id_asal', $p->pluck('id_proses'))->where('jumlah', '<', 0);
            $perItem[$item->id_item] = [
                'dikemas' => $kemas !== null,
                'proses_done' => $done !== null,
                'stok_keluar' => $keluar->isNotEmpty(),
                'lokasi_stok' => $keluar->pluck('lokasi')->unique()->values()->all(),
            ];
            if ($p->count() > 1) {
                $catatan[] = "Item {$item->id_item} punya {$p->count()} baris proses (" . $p->pluck('id_proses')->implode(', ')
                    . '). Hanya satu unit yang dipesan; jangan diproses dua kali.';
            }
            if ($p->isEmpty()) {
                $catatan[] = "Item {$item->id_item} belum punya baris proses di Proses Online.";
            }
            if ($done && $keluar->isEmpty()) {
                $catatan[] = "Item {$item->id_item} berstatus {$done->status} tetapi tidak ada mutasi stok keluar di Aktivitas.";
            }
            if ($kemas && ! $done) {
                $catatan[] = "Item {$item->id_item} punya waktu kemas {$kemas->waktu_kemas} tetapi status prosesnya {$kemas->status}.";
            }
        }
        $semuaDikemas = $items->isNotEmpty() && collect($perItem)->every(fn ($x) => $x['dikemas']);
        $semuaKeluar = $items->isNotEmpty() && collect($perItem)->every(fn ($x) => $x['stok_keluar']);

        if (! $dibayar) {
            $catatan[] = 'tanggal_bayar kosong. Data tidak memuat metode bayar, jadi tidak bisa dibedakan antara belum bayar, COD, atau data tidak diekspor.';
        }

        // ---------- Keputusan 1: ambil / kemas ----------
        $kemas = match (true) {
            $batal || $adaRetur => [self::TIDAK_BOLEH, 'Pesanan dibatalkan atau ada retur/refund.'],
            $sudahDiserahkan => [self::TIDAK_BOLEH, "Status pesanan {$order->status_pesanan}: barang sudah keluar. Jangan mengemas ulang."],
            $semuaDikemas => [self::TIDAK_BOLEH, 'Semua item sudah punya waktu kemas. Jangan mengemas dua kali; cek fisik paketnya.'],
            ! $statusDikenal => [self::BELUM_PASTI, "Status {$order->status_pesanan} belum dikenali."],
            ! $dibayar => [self::BELUM_PASTI, 'Belum ada bukti bayar (tanggal_bayar kosong).'],
            default => [self::BOLEH, 'Pesanan aktif, sudah ada tanggal bayar, dan belum dikemas.'],
        };

        // ---------- Keputusan 2: serahkan ke pelanggan / kurir ----------
        $serah = match (true) {
            $batal || $adaRetur => [self::TIDAK_BOLEH, 'Pesanan dibatalkan atau ada retur/refund.'],
            $sudahDiserahkan => [self::TIDAK_BOLEH, "Status pesanan {$order->status_pesanan}: barang sudah diserahkan sebelumnya."],
            ! $statusDikenal => [self::BELUM_PASTI, "Status {$order->status_pesanan} belum dikenali."],
            ! $semuaDikemas => [self::TIDAK_BOLEH, 'Belum semua item punya catatan kemas.'],
            ! $dibayar => [self::BELUM_PASTI, 'Barang tercatat sudah dikemas, tetapi tidak ada bukti bayar (tanggal_bayar kosong).'],
            ! $semuaKeluar => [self::BELUM_PASTI, 'Tidak ada mutasi stok keluar di Aktivitas untuk semua item.'],
            default => [self::BOLEH, 'Sudah dibayar, sudah dikemas, dan stok keluar tercatat.'],
        };

        return [
            'pesanan' => $order,
            'items' => $items->all(),
            'proses' => $proses->all(),
            'kejadian' => $events,
            'boleh_kemas' => ['jawaban' => $kemas[0], 'alasan' => $kemas[1]],
            'boleh_serah' => ['jawaban' => $serah[0], 'alasan' => $serah[1]],
            'pembatalan' => ($batal || $adaRetur) ? $this->cancelSummary($order, $perItem, $retur, $dibayar) : null,
            'catatan' => $catatan,
        ];
    }

    private function cancelSummary($order, array $perItem, $retur, bool $dibayar): array
    {
        $barang = [];
        $belumDiketahui = [];
        foreach ($perItem as $id => $x) {
            $barang[] = "{$id}: " . ($x['dikemas'] ? 'pernah dikemas' : 'tidak ada catatan kemas')
                . ', ' . ($x['stok_keluar'] ? 'stok keluar tercatat di ' . implode(', ', $x['lokasi_stok']) : 'tidak ada stok keluar di Aktivitas');
            if ($x['dikemas'] || $x['stok_keluar']) {
                $belumDiketahui[] = "Posisi fisik barang {$id}: apakah sudah kembali ke rak? Tidak ada catatan barang masuk kembali.";
            }
        }

        $bayar = [$dibayar ? "Tanggal bayar {$order->tanggal_bayar}." : 'Tidak ada tanggal bayar.'];
        foreach ($retur as $r) {
            $bayar[] = "{$r->id_retur}: {$r->jenis} {$r->status}, hasil {$r->hasil_proses}, selesai " . ($r->waktu_selesai ?? '-')
                . " ({$r->src_file} / {$r->src_sheet} / baris {$r->src_row}).";
        }
        if (! $dibayar && $retur->where('jenis', 'REFUND')->isNotEmpty()) {
            $belumDiketahui[] = 'Ada refund, tetapi pembayaran awalnya tidak tercatat. Cek apakah uang benar-benar pernah diterima.';
        }
        if ($retur->isEmpty()) {
            $belumDiketahui[] = 'Tidak ada data retur/refund. Status pengembalian uang tidak diketahui.';
        }

        return ['status_barang' => $barang, 'status_pembayaran' => $bayar, 'belum_diketahui' => $belumDiketahui];
    }

    private function timeline($order, $proses, $aktivitas, $retur): array
    {
        $src = fn ($r) => "{$r->src_file} / {$r->src_sheet} / baris {$r->src_row}";
        $e = [];
        $e[] = [$order->waktu_pesan, "Pesanan dibuat, status sekarang {$order->status_pesanan} / {$order->status_internal}", $src($order)];
        if ($order->tanggal_bayar) {
            $e[] = [$order->tanggal_bayar, 'Tanggal bayar (tanpa jam)', $src($order)];
        }
        foreach ($proses as $p) {
            $e[] = [$p->waktu_catat, "Proses {$p->id_proses} ({$p->id_item}, {$p->sku}) dicatat, status {$p->status}", $src($p)];
            if ($p->waktu_kemas) {
                $e[] = [$p->waktu_kemas, "Proses {$p->id_proses} dikemas", $src($p)];
            }
            if ($p->waktu_ubah) {
                $e[] = [$p->waktu_ubah, "Proses {$p->id_proses} terakhir diubah", $src($p)];
            }
        }
        foreach ($aktivitas as $a) {
            $e[] = [$a->waktu_kejadian, "Stok {$a->jenis} {$a->jumlah} di {$a->lokasi} ({$a->id_aktivitas})", $src($a)];
        }
        foreach ($retur as $r) {
            $e[] = [$r->waktu_catat, "Retur {$r->id_retur} {$r->jenis} dicatat", $src($r)];
            if ($r->waktu_selesai) {
                $e[] = [$r->waktu_selesai, "Retur {$r->id_retur} selesai ({$r->status}, {$r->hasil_proses})", $src($r)];
            }
        }
        usort($e, fn ($a, $b) => strcmp((string) $a[0], (string) $b[0]));

        return array_map(fn ($x) => ['waktu' => $x[0], 'kejadian' => $x[1], 'asal' => $x[2]], $e);
    }
}