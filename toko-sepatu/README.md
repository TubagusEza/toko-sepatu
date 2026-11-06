# cek stok dan cek pesanan

Program ini djalankan melalui perintah terminal (Laravel 13, PHP 8.2+, MySQL 8 / MariaDB 10.6+) untuk mengimpor empat file Excel, lalu menjawab pertanyaan stok dan pesanan beserta baris Excel yang menjadi buktinya.

## 1. Menyiapkan

```bash
composer install
cp .env.example .env        # lalu isi DB_* (lihat di bawah)
php artisan key:generate
```

Isi `.env`:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=toko_sepatu
DB_USERNAME=root
DB_PASSWORD=
```

Buat database kosong, lalu buat semua tabel dari kosong:

```bash
mysql -u root -p -e "CREATE DATABASE toko_langkah CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan migrate:fresh
```

Skema ada di `database/migrations/2026_11_06_000001_create_toko_langkah_tables.php`.

## 2. Impor

Letakkan keempat file Excel di folder `data/` (sudah ada di repo), lalu:

```bash
php artisan import:excel data
php artisan import:excel data           # kedua kali: file dilewati karena hash sama
php artisan import:excel data --force   # paksa baca ulang: 0 baris baru, semua "sama"
```

File Excel hanya dibaca, tidak pernah disimpan ulang. Setiap baris yang disimpan mencatat `src_file`, `src_sheet`, dan `src_row` (nomor baris Excel, header = baris 1).

## 3. Cek stok

```bash
php artisan stok:cek SKU-0045 CABANG-PUSAT "2026-11-05 04:36:09"
php artisan stok:cek SKU-0003 CABANG-PUSAT "2026-11-04 12:00:00"
php artisan stok:cek SKU-0002 CABANG-PUSAT "2026-10-01 00:00:00"   # tidak dapat dihitung
php artisan stok:cek SKU-0046 CABANG-PUSAT "2026-11-06 00:00:00"   # ada reservasi
```

Cara menghitung:

- **Stok = Stok Awal + jumlah semua baris Aktivitas dengan `mengubah_stok = 1` dan `Stok Awal.waktu < waktu_kejadian <= waktu yang diminta`.**
- Unit dapat dijual = stok tersebut. Baris `reserved` (`mengubah_stok = 0`) tidak dikurangkan, tapi ditampilkan sebagai catatan karena data tidak menunjukkan apakah reservasinya masih aktif.
- Kasir dan Proses Online **tidak** ditambahkan, karena kejadian yang sama sudah tercatat di Aktivitas. Buktinya: pada waktu ekspor Saldo BIN (`2026-11-05 04:36:09`), hasil program sama dengan total Saldo BIN untuk ke-56 SKU × lokasi (lihat tes).
- Tidak dapat dihitung jika SKU/lokasi tidak dikenal, format waktu salah, waktu sebelum Stok Awal, atau hasil negatif.
- Catatan muncul jika ada kejadian yang baru masuk laporan setelah waktu yang diminta, atau jika waktu yang diminta melewati laporan terakhir.

Hitung fisik: setiap baris untuk SKU dan lokasi itu ditampilkan. Selisih "hitung fisik − program" hanya dihitung jika (1) SKU sama, (2) cakupan sama, yaitu hitung untuk seluruh lokasi dan bukan satu BIN, (3) program punya angka pada waktu hitung (ada Stok Awal sebelum waktu itu), dan (4) status hitung `closed`. Program dibandingkan pada **waktu hitung**, bukan pada waktu yang diminta. Semua hitung fisik di data ini (BIN-0043, 15 Juni 2026) tidak memenuhi syarat 2 dan 3, dan alasannya ditampilkan.

## 4. Cek pesanan

```bash
php artisan pesanan:cek ORD-0057
php artisan pesanan:cek ORD-0006
php artisan pesanan:cek ORD-0004
php artisan pesanan:cek ORD-0057 --json
```

Output: item, kejadian berurutan waktu (pesanan, bayar, proses, kemas, mutasi stok, retur) beserta file/sheet/baris, lalu dua jawaban:

1. **Boleh mulai ambil/kemas?** Tidak boleh jika batal/retur, sudah dikirim, atau semua item sudah dikemas (supaya tidak dobel). Belum bisa dipastikan jika status tidak dikenal atau tidak ada tanggal bayar. Boleh jika aktif, sudah bayar, dan belum dikemas.
2. **Boleh diserahkan ke pelanggan/kurir?** Tidak boleh jika batal/retur, sudah diserahkan, atau belum dikemas. Belum bisa dipastikan jika status tidak dikenal, tidak ada tanggal bayar, atau tidak ada mutasi stok keluar. Boleh jika ketiganya ada.

Untuk pesanan batal/retur, ditampilkan status barang (dikemas? stok keluar?) dan status pembayaran (tanggal bayar, refund) secara terpisah, beserta hal yang belum diketahui.

## 5. Tes

```bash
php artisan test
```

Tes memakai SQLite in-memory (lihat `phpunit.xml`), jadi tidak perlu database MySQL terpisah.

- `ImportTest`: impor tiga kali (biasa, biasa, `--force`), dan jumlah transaksi tetap sama. Tes ini juga memeriksa file/sheet/baris asal.
- `CekStokDanPesananTest`: SKU-0045 di cabang = 32 = Saldo BIN; semua 56 SKU × lokasi cocok dengan Saldo BIN; cek stok sebelum Stok Awal tidak dapat dihitung; ORD-0057 dan ORD-0006 memberi jawaban yang diharapkan.

## 6. Dua kasus yang bisa membuat staf keliru

### Kasus 1: ORD-0057, "siap diambil" tanpa bukti bayar

- **Terkait**: ORD-0057, SKU-0045 (ukuran 42).
- **Bukti**: `02-online.xlsx` / Pesanan Online / baris 58 (`AWAITING_COLLECTION`, `DONE ONLINE`, `tanggal_bayar` kosong); Item Online / baris 59; Proses Online / baris 58 (FUL-0057, `DONE ONLINE`, waktu_kemas = waktu_ubah = 2026-11-04 16:28:07); `01-gudang.xlsx` / Aktivitas / baris 502 (sales_final −1 GUDANG-ONLINE pada detik yang sama).
- **Yang terlihat**: Status menunjukkan barang siap diambil, tapi tidak ada catatan pembayaran. Status `DONE ONLINE` hanya muncul sekali di seluruh data. Setelah baris 502, stok SKU-0045 di GUDANG-ONLINE menjadi 0, dan tempat pengambilan tidak tercatat.
- **Akibat**: Barang diserahkan sebelum dibayar, atau staf mengambil unit dari rak cabang tanpa mencatatnya.
- **Cek dulu**: Bukti bayar di sistem pembayaran/marketplace, arti `AWAITING_COLLECTION`/`DONE ONLINE`, posisi fisik paket, dan identitas pengambil.

### Kasus 2: ORD-0006, batal dan refund tapi barang pernah dikemas

- **Terkait**: ORD-0006, SKU-0019, RET-0001, POS-0013, POS-0060.
- **Bukti**: `02-online.xlsx` / Pesanan Online / baris 7 (CANCELLED, tanpa tanggal bayar); Proses Online / baris 6 (FUL-0005 `INSTOCK`, tapi waktu_kemas 2026-11-01 11:08:56); Retur Online / baris 2 (REFUND completed 17:14:21); Item Online / baris 7 (`jumlah_retur = 0`); `03-kasir.xlsx` / Penjualan Kasir / baris 14–15 dan Item Kasir / baris 18–19 (jual +1 lalu batal −1 pada detik yang sama). Tidak ada baris Aktivitas untuk barang ini.
- **Yang terlihat**: Ada refund yang selesai padahal pembayaran tidak tercatat. Barang punya waktu kemas tapi statusnya "INSTOCK". Tidak ada catatan barang kembali ke rak.
- **Akibat**: Barang dianggap sudah ada di rak dan dijual lagi padahal paketnya masih tertahan. Refund bisa terproses dua kali. Stok bisa terhitung ganda jika kasir ikut dijumlahkan.
- **Cek dulu**: Posisi fisik paket, mutasi stok masuk kembali di aplikasi gudang, dan bukti uang masuk/keluar.

## 7. Pilihan desain dan asumsi

- **Kolom waktu yang dipakai**: Aktivitas memakai `waktu_kejadian`, karena soal meminta "waktu kejadian sampai dengan waktu yang diminta". `waktu_masuk_laporan` hanya dipakai untuk peringatan. Contoh: penerimaan SKU-0003 terjadi 2 Nov (baris 307) tapi baru dilaporkan 5 Nov, sedangkan penjualannya terjadi 3 Nov dan dilaporkan 4 Nov; dengan waktu laporan, stok 4 Nov menjadi −1. Hitung Fisik memakai `waktu_baris`. Pesanan memakai `waktu_pesan` dan `tanggal_bayar` (hanya tanggal). Proses memakai `waktu_kemas` sebagai bukti kemas; `waktu_ubah` hanya waktu perubahan status; `waktu_efek_stok` kosong semua. `waktu_catat` di kasir dan online adalah waktu pencatatan, bukan kejadian.
- **Kunci**: Primary key memakai ID bisnis dari Excel (id_aktivitas, nomor_pesanan, dan seterusnya; Stok Awal memakai sku + lokasi + waktu). Nomor baris bukan kunci, karena bisa berubah di file berikutnya.
- **Status disimpan apa adanya** (VARCHAR), sesuai petunjuk data.
- `ukuran` "-" disimpan sebagai NULL; nilai aslinya ada di `ukuran_raw`.
- Lokasi stok pesanan online diambil dari Aktivitas, bukan dari Pesanan Online, karena sebagian pesanan diambil dari CABANG-PUSAT (misal FUL-0021, Aktivitas baris 193).

## 8. File import baru

Langkahnya: `php artisan import:excel <folder>` atau satu file per perintah.

1. Program menghitung hash SHA-256 file. Jika file identik sudah pernah diimpor, file dilewati dan dicatat `skipped_same_hash` di `import_file`.
2. Program mengenali sheet berdasarkan **nama sheet**, bukan nama file, lalu memeriksa header. Jika ada kolom yang hilang, impor file itu dibatalkan seluruhnya (transaksi database) dengan pesan yang jelas.
3. **Mengenali transaksi yang sudah pernah diimpor**: per baris, program mencari ID bisnisnya. Jika ID ada dan `row_hash` sama, baris dilewati. Jika ID ada tapi isinya berbeda, data lama **tidak ditimpa**; versi baru disimpan di `import_conflict` (status `pending`) untuk diperiksa. Jika ID baru, baris disisipkan.
4. **Transaksi lama yang baru dikirim** (waktu_kejadian lama, waktu_masuk_laporan baru): disimpan dengan waktu kejadian aslinya, sehingga cek stok untuk waktu lampau bisa berubah. Waktu laporannya ikut disimpan, dan cek stok menampilkan catatan jika ada kejadian yang dilaporkan setelah waktu yang diminta.
5. Kasir tidak punya kunci ke Aktivitas, jadi struk kasir tidak pernah menambah atau mengurangi stok; struk hanya disimpan sebagai pendukung.

