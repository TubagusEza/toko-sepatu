<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Kolom asal data yang dipakai semua tabel bisnis. */
    private function asal(Blueprint $t): void
    {
        $t->string('src_file');
        $t->string('src_sheet', 100);
        $t->unsignedInteger('src_row');
        $t->char('row_hash', 64);
        $t->foreignId('import_file_id')->constrained('import_file');
    }

    public function up(): void
    {
        // ---------- Jejak impor ----------
        Schema::create('import_batch', function (Blueprint $t) {
            $t->id();
            $t->dateTime('started_at');
            $t->dateTime('finished_at')->nullable();
            $t->string('note')->nullable();
        });

        Schema::create('import_file', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained('import_batch');
            $t->string('file_name');
            $t->char('file_hash', 64)->index();
            $t->string('status', 20); // imported / skipped_same_hash / failed
            $t->unsignedInteger('rows_inserted')->default(0);
            $t->unsignedInteger('rows_unchanged')->default(0);
            $t->unsignedInteger('rows_changed')->default(0);
            $t->dateTime('imported_at');
        });

        Schema::create('import_conflict', function (Blueprint $t) {
            $t->id();
            $t->foreignId('import_file_id')->constrained('import_file');
            $t->string('target_table', 64);
            $t->string('business_key', 100);
            $t->string('src_sheet', 100);
            $t->unsignedInteger('src_row');
            $t->char('old_row_hash', 64);
            $t->char('new_row_hash', 64);
            $t->json('new_payload');
            $t->string('resolution', 20)->default('pending');
            $t->dateTime('created_at');
            $t->index(['target_table', 'business_key']);
        });

        // ---------- Master (00-referensi.xlsx) ----------
        Schema::create('lokasi', function (Blueprint $t) {
            $t->string('kode_lokasi', 30)->primary();
            $t->string('nama_lokasi', 100);
            $this->asal($t);
        });

        Schema::create('produk', function (Blueprint $t) {
            $t->string('sku', 30)->primary();
            $t->string('jenis', 50);
            $t->string('ukuran', 10)->nullable();     // '-' disimpan sebagai NULL
            $t->string('ukuran_raw', 10)->nullable(); // nilai asli Excel
            $this->asal($t);
        });

        Schema::create('stok_awal', function (Blueprint $t) {
            $t->id();
            $t->string('sku', 30);
            $t->string('lokasi', 30);
            $t->dateTime('waktu');
            $t->integer('jumlah');
            $t->string('keterangan')->nullable();
            $this->asal($t);
            $t->unique(['sku', 'lokasi', 'waktu']);
            $t->foreign('sku')->references('sku')->on('produk');
            $t->foreign('lokasi')->references('kode_lokasi')->on('lokasi');
        });

        // ---------- Gudang (01-gudang.xlsx) ----------
        Schema::create('saldo_bin', function (Blueprint $t) {
            $t->string('id_saldo', 30)->primary();
            $t->string('sku', 30);
            $t->string('lokasi', 30);
            $t->string('bin', 30);
            $t->integer('jumlah_saat_ekspor');
            $t->dateTime('waktu_ekspor');
            $this->asal($t);
            $t->index(['sku', 'lokasi', 'waktu_ekspor']);
            $t->foreign('sku')->references('sku')->on('produk');
            $t->foreign('lokasi')->references('kode_lokasi')->on('lokasi');
        });

        Schema::create('aktivitas', function (Blueprint $t) {
            $t->string('id_aktivitas', 30)->primary();
            $t->dateTime('waktu_kejadian');
            $t->string('lokasi', 30);
            $t->string('sku', 30);
            $t->string('jenis', 30);
            $t->integer('jumlah');
            $t->string('status', 30);
            $t->boolean('mengubah_stok');
            $t->string('asal_data', 50)->nullable();
            $t->string('id_asal', 30)->nullable()->index(); // FUL-xxxx = proses_online.id_proses
            $t->string('id_detail_asal', 30)->nullable();
            $t->dateTime('waktu_masuk_laporan')->nullable();
            $this->asal($t);
            $t->index(['sku', 'lokasi', 'waktu_kejadian']);
            $t->foreign('sku')->references('sku')->on('produk');
            $t->foreign('lokasi')->references('kode_lokasi')->on('lokasi');
        });

        Schema::create('hitung_fisik', function (Blueprint $t) {
            $t->string('id_baris', 30)->primary();
            $t->string('id_hitung', 30)->index();
            $t->string('sku', 30);
            $t->string('lokasi', 30);
            $t->string('bin', 30)->nullable();
            $t->string('status_hitung', 30);
            $t->dateTime('waktu_mulai')->nullable();
            $t->dateTime('waktu_baris')->nullable();
            $t->integer('jumlah_sistem_saat_itu')->nullable();
            $t->integer('jumlah_dihitung')->nullable();
            $t->integer('selisih_dicatat')->nullable();
            $this->asal($t);
            $t->index(['sku', 'lokasi']);
            $t->foreign('sku')->references('sku')->on('produk');
            $t->foreign('lokasi')->references('kode_lokasi')->on('lokasi');
        });

        // ---------- Online (02-online.xlsx) ----------
        Schema::create('pesanan_online', function (Blueprint $t) {
            $t->string('nomor_pesanan', 30)->primary();
            $t->string('lokasi', 30);
            $t->string('status_pesanan', 40);
            $t->string('status_internal', 40)->nullable();
            $t->dateTime('waktu_pesan')->nullable();
            $t->date('tanggal_bayar')->nullable(); // hanya tanggal
            $t->dateTime('waktu_catat')->nullable();
            $this->asal($t);
            $t->foreign('lokasi')->references('kode_lokasi')->on('lokasi');
        });

        Schema::create('item_online', function (Blueprint $t) {
            $t->string('id_item', 30)->primary();
            $t->string('nomor_pesanan', 30)->index();
            $t->string('sku', 30);
            $t->integer('jumlah');
            $t->integer('jumlah_retur')->default(0);
            $this->asal($t);
            $t->foreign('nomor_pesanan')->references('nomor_pesanan')->on('pesanan_online');
            $t->foreign('sku')->references('sku')->on('produk');
        });

        // id_item tidak unik: satu item bisa punya beberapa baris proses.
        Schema::create('proses_online', function (Blueprint $t) {
            $t->string('id_proses', 30)->primary();
            $t->string('id_item', 30)->index();
            $t->string('sku', 30);
            $t->integer('jumlah');
            $t->string('status', 40);
            $t->dateTime('waktu_catat')->nullable();
            $t->dateTime('waktu_ubah')->nullable();
            $t->dateTime('waktu_kemas')->nullable();
            $t->dateTime('waktu_efek_stok')->nullable();
            $this->asal($t);
            $t->foreign('id_item')->references('id_item')->on('item_online');
            $t->foreign('sku')->references('sku')->on('produk');
        });

        Schema::create('retur_online', function (Blueprint $t) {
            $t->string('id_retur', 30)->primary();
            $t->string('nomor_pesanan', 30)->index();
            $t->string('jenis', 30);
            $t->string('status', 30);
            $t->string('hasil_proses', 30)->nullable();
            $t->dateTime('waktu_catat')->nullable();
            $t->dateTime('waktu_selesai')->nullable();
            $this->asal($t);
            $t->foreign('nomor_pesanan')->references('nomor_pesanan')->on('pesanan_online');
        });

        // ---------- Kasir (03-kasir.xlsx) ----------
        Schema::create('struk_kasir', function (Blueprint $t) {
            $t->string('nomor_struk', 30)->primary();
            $t->string('lokasi', 30);
            $t->string('status', 30);
            $t->string('flag_retur', 10)->nullable();
            $t->dateTime('waktu_catat')->nullable();
            $this->asal($t);
            $t->foreign('lokasi')->references('kode_lokasi')->on('lokasi');
        });

        Schema::create('item_kasir', function (Blueprint $t) {
            $t->string('id_item', 30)->primary();
            $t->string('nomor_struk', 30)->index();
            $t->string('sku', 30);
            $t->string('bin', 30)->nullable();
            $t->integer('jumlah');
            $t->dateTime('waktu_catat')->nullable();
            $this->asal($t);
            $t->foreign('nomor_struk')->references('nomor_struk')->on('struk_kasir');
            $t->foreign('sku')->references('sku')->on('produk');
        });
    }

    public function down(): void
    {
        foreach ([
            'item_kasir', 'struk_kasir', 'retur_online', 'proses_online', 'item_online',
            'pesanan_online', 'hitung_fisik', 'aktivitas', 'saldo_bin', 'stok_awal',
            'produk', 'lokasi', 'import_conflict', 'import_file', 'import_batch',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};