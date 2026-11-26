<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransaksiController extends Controller
{
    // ======================================
    // 1. MENGAMBIL RINGKASAN TRANSAKSI DAN TAGIHAN
    // ======================================
    public function ringkasan()
    {
        // Mengambil pembayaran yang benar-benar diterima
        $transaksi = DB::table('pembayaran as p')
            ->join('transaksi as t', 'p.id_transaksi', '=', 't.id_transaksi')
            ->select(
                'p.id_pembayaran',
                'p.id_transaksi',
                'p.tanggal_pembayaran',
                'p.jumlah_bayar',
                'p.metode_pembayaran',
                't.jenis_transaksi',
                't.tanggal_transaksi'
            )
            ->selectRaw("
                CASE WHEN EXISTS (
                    SELECT 1
                    FROM detail_pembayaran dp
                    WHERE dp.id_pembayaran = p.id_pembayaran
                ) THEN 1 ELSE 0 END AS pernah_menjadi_utang
            ")
            ->orderByDesc('p.tanggal_pembayaran')
            ->get();

        // Mengambil transaksi yang belum lunas
        $daftarTagihan = DB::table('transaksi')
            ->where('status_pembayaran', '!=', 'lunas')
            ->orderByDesc('tanggal_transaksi')
            ->get();

        $tagihan = [];

        foreach ($daftarTagihan as $row) {
            $idTransaksi = $row->id_transaksi;

            // Mengambil barang yang masih memiliki sisa tagihan
            $details = DB::table('detail_transaksi as dt')
                ->leftJoin(
                    'detail_pembayaran as dp',
                    'dt.id_detail_transaksi',
                    '=',
                    'dp.id_detail_transaksi'
                )
                ->where('dt.id_transaksi', $idTransaksi)
                ->select(
                    'dt.id_detail_transaksi',
                    'dt.nama_barang',
                    'dt.jumlah',
                    'dt.harga_jual',
                    'dt.subtotal'
                )
                ->selectRaw('COALESCE(SUM(dp.jumlah_dibayar), 0) AS sudah_dibayar')
                ->groupBy(
                    'dt.id_detail_transaksi',
                    'dt.nama_barang',
                    'dt.jumlah',
                    'dt.harga_jual',
                    'dt.subtotal'
                )
                ->get();

            $remainingItems = [];

            foreach ($details as $detail) {
                $sisa = round(
                    (float) $detail->subtotal -
                    (float) $detail->sudah_dibayar,
                    2
                );

                if ($sisa > 0) {
                    $remainingItems[] = [
                        'id' => (int) $detail->id_detail_transaksi,
                        'name' => $detail->nama_barang,
                        'qty' => (int) $detail->jumlah,
                        'price' => (float) $detail->harga_jual,
                        'subtotal' => $sisa
                    ];
                }
            }

            // Mengambil riwayat pembayaran
            $payments = DB::table('pembayaran')
                ->where('id_transaksi', $idTransaksi)
                ->orderBy('tanggal_pembayaran')
                ->get()
                ->map(function ($bayar) {
                    return [
                        'total' => (float) $bayar->jumlah_bayar,
                        'date' => $bayar->tanggal_pembayaran,
                        'payment' => $bayar->metode_pembayaran
                    ];
                })
                ->toArray();

            $tagihan[] = [
                'id' => $idTransaksi,
                'date' => $row->tanggal_transaksi,
                'remainingItems' => $remainingItems,
                'payments' => $payments
            ];
        }

        return response()->json([
            'success' => true,
            'transaksi' => $transaksi,
            'tagihan' => $tagihan
        ]);
    }


    // ======================================
    // 2. MENYIMPAN TRANSAKSI BARU
    // ======================================
    public function store(Request $request)
    {
        $idUser = (int) $request->input('id_user', 0);

        $tanggal = $request->input(
            'tanggal_transaksi',
            now()->format('Y-m-d H:i:s')
        );

        $jenis = $request->input('jenis_transaksi');
        $metode = $request->input('metode_pembayaran');
        $items = $request->input('items', []);

        // Memeriksa kelengkapan data transaksi
        if (
            $idUser <= 0 ||
            !in_array($jenis, ['umum', 'toko_mesin'], true) ||
            !in_array($metode, ['tunai', 'transfer', 'utang'], true) ||
            !is_array($items) ||
            count($items) === 0
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Data transaksi tidak lengkap atau tidak valid.'
            ], 400);
        }

        try {
            $hasil = DB::transaction(function () use (
                $idUser,
                $tanggal,
                $jenis,
                $metode,
                $items
            ) {
                $total = 0;
                $daftarBarang = [];

                // Memeriksa barang dan mengunci stok
                foreach ($items as $item) {
                    $idBarang = (int) ($item['id_barang'] ?? 0);
                    $jumlah = (int) ($item['jumlah'] ?? 0);

                    if ($idBarang <= 0 || $jumlah <= 0) {
                        throw new \Exception('Data barang tidak valid.');
                    }

                    $barang = DB::table('barang')
                        ->where('id_barang', $idBarang)
                        ->lockForUpdate()
                        ->first();

                    if (!$barang) {
                        throw new \Exception('Barang tidak ditemukan.');
                    }

                    if ((int) $barang->stok < $jumlah) {
                        throw new \Exception('Stok barang tidak mencukupi.');
                    }

                    // Mengambil harga sesuai jenis transaksi
                    $harga = ($jenis === 'umum')
                        ? (float) $barang->harga_jual_umum
                        : (float) $barang->harga_jual_toko_mesin;

                    $subtotal = round($harga * $jumlah, 2);
                    $total += $subtotal;

                    $daftarBarang[] = [
                        'id_barang' => $idBarang,
                        'nama_barang' => $barang->nama_barang,
                        'jumlah' => $jumlah,
                        'harga' => $harga,
                        'subtotal' => $subtotal
                    ];
                }

                $total = round($total, 2);

                $status = ($metode === 'utang')
                    ? 'belum_lunas'
                    : 'lunas';

                // Menyimpan transaksi utama
                $idTransaksi = DB::table('transaksi')->insertGetId([
                    'id_user' => $idUser,
                    'jenis_transaksi' => $jenis,
                    'tanggal_transaksi' => $tanggal,
                    'total_transaksi' => $total,
                    'status_pembayaran' => $status
                ], 'id_transaksi');

                // Menyimpan detail transaksi dan mengurangi stok
                foreach ($daftarBarang as $item) {
                    DB::table('detail_transaksi')->insert([
                        'id_transaksi' => $idTransaksi,
                        'id_barang' => $item['id_barang'],
                        'nama_barang' => $item['nama_barang'],
                        'jumlah' => $item['jumlah'],
                        'harga_jual' => $item['harga'],
                        'subtotal' => $item['subtotal']
                    ]);

                    DB::table('barang')
                        ->where('id_barang', $item['id_barang'])
                        ->decrement('stok', $item['jumlah']);
                }

                // Tunai dan transfer langsung dicatat sebagai pembayaran
                // Utang tidak langsung dihitung sebagai pemasukan
                if ($metode !== 'utang') {
                    DB::table('pembayaran')->insert([
                        'id_transaksi' => $idTransaksi,
                        'tanggal_pembayaran' => $tanggal,
                        'jumlah_bayar' => $total,
                        'metode_pembayaran' => $metode
                    ]);
                }

                return [
                    'id_transaksi' => $idTransaksi,
                    'total_transaksi' => $total
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Transaksi berhasil disimpan.',
                'id_transaksi' => $hasil['id_transaksi'],
                'total_transaksi' => $hasil['total_transaksi']
            ]);

        } catch (\Throwable $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage()
            ], 400);
        }
    }


    // ======================================
    // 3. MEMBAYAR TAGIHAN UTANG
    // ======================================
    public function payBill(Request $request)
    {
        $idTransaksi = (int) $request->input('id_transaksi', 0);
        $metode = $request->input('metode_pembayaran');
        $details = $request->input('detail', []);

        if (
            $idTransaksi <= 0 ||
            !in_array($metode, ['tunai', 'transfer'], true) ||
            !is_array($details) ||
            count($details) === 0
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Data pembayaran tidak lengkap atau tidak valid.'
            ], 400);
        }

        try {
            $hasil = DB::transaction(function () use (
                $idTransaksi,
                $metode,
                $details
            ) {
                // Mengunci transaksi
                $transaksi = DB::table('transaksi')
                    ->where('id_transaksi', $idTransaksi)
                    ->lockForUpdate()
                    ->first();

                if (!$transaksi) {
                    throw new \Exception('Tagihan tidak ditemukan.');
                }

                if ($transaksi->status_pembayaran === 'lunas') {
                    throw new \Exception('Tagihan sudah lunas.');
                }

                $totalBayar = 0;
                $daftarPembayaran = [];
                $idTerpilih = [];

                foreach ($details as $item) {
                    $idDetail = (int) ($item['id_detail_transaksi'] ?? 0);
                    $jumlahBayar = (float) ($item['jumlah_dibayar'] ?? 0);

                    if ($idDetail <= 0 || $jumlahBayar <= 0) {
                        throw new \Exception('Data barang yang dibayar tidak valid.');
                    }

                    if (in_array($idDetail, $idTerpilih, true)) {
                        throw new \Exception('Barang yang dipilih tidak boleh duplikat.');
                    }

                    $idTerpilih[] = $idDetail;

                    // Memastikan detail barang milik transaksi ini
                    $detail = DB::table('detail_transaksi')
                        ->where('id_detail_transaksi', $idDetail)
                        ->where('id_transaksi', $idTransaksi)
                        ->lockForUpdate()
                        ->first();

                    if (!$detail) {
                        throw new \Exception('Detail barang tidak ditemukan.');
                    }

                    // Menghitung jumlah yang sudah dibayar
                    $sudahDibayar = DB::table('detail_pembayaran')
                        ->where('id_detail_transaksi', $idDetail)
                        ->sum('jumlah_dibayar');

                    $sisa = round(
                        (float) $detail->subtotal - (float) $sudahDibayar,
                        2
                    );

                    if ($sisa <= 0) {
                        throw new \Exception('Barang ini sudah lunas.');
                    }

                    if (abs($jumlahBayar - $sisa) > 0.01) {
                        throw new \Exception('Jumlah pembayaran tidak sesuai dengan sisa tagihan.');
                    }

                    $totalBayar += $jumlahBayar;

                    $daftarPembayaran[] = [
                        'id_detail_transaksi' => $idDetail,
                        'jumlah_dibayar' => $jumlahBayar
                    ];
                }

                $totalBayar = round($totalBayar, 2);

                // Mencatat pembayaran yang diterima
                $idPembayaran = DB::table('pembayaran')->insertGetId([
                    'id_transaksi' => $idTransaksi,
                    'tanggal_pembayaran' => now(),
                    'jumlah_bayar' => $totalBayar,
                    'metode_pembayaran' => $metode
                ], 'id_pembayaran');

                // Mencatat pembayaran setiap barang
                foreach ($daftarPembayaran as $item) {
                    DB::table('detail_pembayaran')->insert([
                        'id_pembayaran' => $idPembayaran,
                        'id_detail_transaksi' => $item['id_detail_transaksi'],
                        'jumlah_dibayar' => $item['jumlah_dibayar']
                    ]);
                }

                // Menghitung seluruh pembayaran yang sudah diterima
                $totalDibayar = DB::table('pembayaran')
                    ->where('id_transaksi', $idTransaksi)
                    ->sum('jumlah_bayar');

                $status = ((float) $totalDibayar >=
                    (float) $transaksi->total_transaksi - 0.01)
                    ? 'lunas'
                    : 'sebagian';

                DB::table('transaksi')
                    ->where('id_transaksi', $idTransaksi)
                    ->update([
                        'status_pembayaran' => $status
                    ]);

                return [
                    'total_bayar' => $totalBayar,
                    'status_pembayaran' => $status
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Pembayaran tagihan berhasil.',
                'total_bayar' => $hasil['total_bayar'],
                'status_pembayaran' => $hasil['status_pembayaran']
            ]);

        } catch (\Throwable $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage()
            ], 400);
        }
    }
}
