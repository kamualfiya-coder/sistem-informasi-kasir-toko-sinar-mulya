<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BarangController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\NotaController;
use App\Http\Controllers\TransaksiController;

// Tes API
Route::get('/tes', function () {
    return response()->json([
        'status' => 'berhasil',
        'pesan' => 'API Laravel Toko Sinar Mulya aktif!'
    ]);
});

// API Barang
Route::get('/barang', [BarangController::class, 'index']);
Route::post('/barang', [BarangController::class, 'store']);
Route::delete('/barang', [BarangController::class, 'destroy']);

// API Login
Route::post('/login', [LoginController::class, 'login']);

// API Nota Pembelian
Route::get('/nota', [NotaController::class, 'index']);
Route::post('/nota', [NotaController::class, 'store']);

// API Transaksi
Route::get('/transaksi/ringkasan', [TransaksiController::class, 'ringkasan']);
Route::post('/transaksi', [TransaksiController::class, 'store']);
Route::post('/transaksi/pay-bill', [TransaksiController::class, 'payBill']);
