<?php

namespace App\Services;

/**
 * Peta sheet Excel -> tabel database.
 * Urutan array = urutan impor (tabel induk dulu, supaya foreign key aman).
 *
 * 'key'     : kolom kunci bisnis (dipakai untuk mendeteksi baris yang sudah pernah diimpor)
 * 'columns' : header Excel => tipe (str, int, bool, datetime, date)
 */
class SheetMap
{
    public static function all(): array
    {
        return [
            'Lokasi' => [
                'table' => 'lokasi', 'key' => ['kode_lokasi'],
                'columns' => ['kode_lokasi' => 'str', 'nama_lokasi' => 'str'],
            ],
            'Produk' => [
                'table' => 'produk', 'key' => ['sku'],
                'columns' => ['sku' => 'str', 'jenis' => 'str', 'ukuran' => 'str'],
            ],
            'Stok Awal' => [
                'table' => 'stok_awal', 'key' => ['sku', 'lokasi', 'waktu'],
                'columns' => ['sku' => 'str', 'lokasi' => 'str', 'waktu' => 'datetime', 'jumlah' => 'int', 'keterangan' => 'str'],
            ],
            'Saldo BIN' => [
                'table' => 'saldo_bin', 'key' => ['id_saldo'],
                'columns' => ['id_saldo' => 'str', 'sku' => 'str', 'lokasi' => 'str', 'bin' => 'str',
                    'jumlah_saat_ekspor' => 'int', 'waktu_ekspor' => 'datetime'],
            ],
            'Aktivitas' => [
                'table' => 'aktivitas', 'key' => ['id_aktivitas'],
                'columns' => ['id_aktivitas' => 'str', 'waktu_kejadian' => 'datetime', 'lokasi' => 'str', 'sku' => 'str',
                    'jenis' => 'str', 'jumlah' => 'int', 'status' => 'str', 'mengubah_stok' => 'bool',
                    'asal_data' => 'str', 'id_asal' => 'str', 'id_detail_asal' => 'str', 'waktu_masuk_laporan' => 'datetime'],
            ],
            'Hitung Fisik' => [
                'table' => 'hitung_fisik', 'key' => ['id_baris'],
                'columns' => ['id_baris' => 'str', 'id_hitung' => 'str', 'sku' => 'str', 'lokasi' => 'str', 'bin' => 'str',
                    'status_hitung' => 'str', 'waktu_mulai' => 'datetime', 'waktu_baris' => 'datetime',
                    'jumlah_sistem_saat_itu' => 'int', 'jumlah_dihitung' => 'int', 'selisih_dicatat' => 'int'],
            ],
            'Pesanan Online' => [
                'table' => 'pesanan_online', 'key' => ['nomor_pesanan'],
                'columns' => ['nomor_pesanan' => 'str', 'lokasi' => 'str', 'status_pesanan' => 'str', 'status_internal' => 'str',
                    'waktu_pesan' => 'datetime', 'tanggal_bayar' => 'date', 'waktu_catat' => 'datetime'],
            ],
            'Item Online' => [
                'table' => 'item_online', 'key' => ['id_item'],
                'columns' => ['id_item' => 'str', 'nomor_pesanan' => 'str', 'sku' => 'str', 'jumlah' => 'int', 'jumlah_retur' => 'int'],
            ],
            'Proses Online' => [
                'table' => 'proses_online', 'key' => ['id_proses'],
                'columns' => ['id_proses' => 'str', 'id_item' => 'str', 'sku' => 'str', 'jumlah' => 'int', 'status' => 'str',
                    'waktu_catat' => 'datetime', 'waktu_ubah' => 'datetime', 'waktu_kemas' => 'datetime', 'waktu_efek_stok' => 'datetime'],
            ],
            'Retur Online' => [
                'table' => 'retur_online', 'key' => ['id_retur'],
                'columns' => ['id_retur' => 'str', 'nomor_pesanan' => 'str', 'jenis' => 'str', 'status' => 'str',
                    'hasil_proses' => 'str', 'waktu_catat' => 'datetime', 'waktu_selesai' => 'datetime'],
            ],
            'Penjualan Kasir' => [
                'table' => 'struk_kasir', 'key' => ['nomor_struk'],
                'columns' => ['nomor_struk' => 'str', 'lokasi' => 'str', 'status' => 'str', 'flag_retur' => 'str', 'waktu_catat' => 'datetime'],
            ],
            'Item Kasir' => [
                'table' => 'item_kasir', 'key' => ['id_item'],
                'columns' => ['id_item' => 'str', 'nomor_struk' => 'str', 'sku' => 'str', 'bin' => 'str', 'jumlah' => 'int', 'waktu_catat' => 'datetime'],
            ],
        ];
    }
}