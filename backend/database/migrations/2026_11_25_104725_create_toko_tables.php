
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabel barang
        Schema::create('barang', function (Blueprint $table) {
            $table->increments('id_barang');
            $table->string('kode_barang', 50);
            $table->string('nama_barang', 100);
            $table->integer('stok');
            $table->decimal('harga_jual_umum', 12, 2);
            $table->decimal('harga_jual_toko_mesin', 12, 2);
            $table->string('gambar', 255)->nullable();
        });

        // Tabel pelanggan
        Schema::create('pelanggan', function (Blueprint $table) {
            $table->increments('id_pelanggan');
            $table->string('nama_pelanggan', 100);
            $table->enum('kategori_pelanggan', ['umum', 'toko_mesin']);
            $table->string('alamat', 255)->nullable();
            $table->string('no_telepon', 20)->nullable();
        });

        // Tabel transaksi
        Schema::create('transaksi', function (Blueprint $table) {
            $table->increments('id_transaksi');
            $table->unsignedInteger('id_pelanggan')->nullable();
            $table->unsignedInteger('id_user');
            $table->enum('jenis_transaksi', ['umum', 'toko_mesin'])
                ->default('umum');
            $table->dateTime('tanggal_transaksi');
            $table->decimal('total_transaksi', 12, 2);
            $table->enum('status_pembayaran', [
                'belum_lunas', 'sebagian', 'lunas'
            ]);

            $table->foreign('id_pelanggan')
                ->references('id_pelanggan')->on('pelanggan')
                ->nullOnDelete();

            $table->foreign('id_user')
                ->references('id_user')->on('users');
        });

        // Tabel pembayaran
        Schema::create('pembayaran', function (Blueprint $table) {
            $table->increments('id_pembayaran');
            $table->unsignedInteger('id_transaksi');
            $table->dateTime('tanggal_pembayaran');
            $table->decimal('jumlah_bayar', 12, 2);
            $table->enum('metode_pembayaran', ['tunai', 'transfer']);

            $table->foreign('id_transaksi')
                ->references('id_transaksi')->on('transaksi')
                ->cascadeOnDelete();
        });

        // Tabel detail transaksi
        Schema::create('detail_transaksi', function (Blueprint $table) {
            $table->increments('id_detail_transaksi');
            $table->unsignedInteger('id_transaksi');
            $table->unsignedInteger('id_barang')->nullable();
            $table->string('nama_barang', 100)->nullable();
            $table->integer('jumlah');
            $table->decimal('harga_jual', 12, 2);
            $table->decimal('subtotal', 12, 2);

            $table->foreign('id_transaksi')
                ->references('id_transaksi')->on('transaksi')
                ->cascadeOnDelete();

            $table->foreign('id_barang')
                ->references('id_barang')->on('barang')
                ->nullOnDelete();
        });

        // Tabel detail pembayaran
        Schema::create('detail_pembayaran', function (Blueprint $table) {
            $table->increments('id_detail_pembayaran');
            $table->unsignedInteger('id_pembayaran');
            $table->unsignedInteger('id_detail_transaksi');
            $table->decimal('jumlah_dibayar', 12, 2);

            $table->foreign('id_pembayaran')
                ->references('id_pembayaran')->on('pembayaran')
                ->cascadeOnDelete();

            $table->foreign('id_detail_transaksi')
                ->references('id_detail_transaksi')->on('detail_transaksi')
                ->cascadeOnDelete();
        });

        // Tabel nota pembelian
        Schema::create('nota_pembelian', function (Blueprint $table) {
            $table->increments('id_nota');
            $table->date('tanggal_nota');
            $table->string('nama_pemasok', 100);
            $table->string('lokasi_file', 255)->nullable();
            $table->text('keterangan')->nullable();
        });

        // Tabel laporan
        Schema::create('laporan', function (Blueprint $table) {
            $table->increments('id_laporan');
            $table->string('jenis_laporan', 100);
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->dateTime('tanggal_dibuat');
            $table->string('lokasi_file', 25)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detail_pembayaran');
        Schema::dropIfExists('detail_transaksi');
        Schema::dropIfExists('pembayaran');
        Schema::dropIfExists('transaksi');
        Schema::dropIfExists('laporan');
        Schema::dropIfExists('nota_pembelian');
        Schema::dropIfExists('pelanggan');
        Schema::dropIfExists('barang');
    }
};
