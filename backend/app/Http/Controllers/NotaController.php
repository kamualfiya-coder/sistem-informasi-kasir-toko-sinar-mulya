<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class NotaController extends Controller
{
    // GET: Menampilkan semua nota
    public function index()
    {
        $data = DB::table('nota_pembelian')
            ->orderByDesc('id_nota')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    // POST: Menambahkan nota
    public function store(Request $request)
    {
        $request->validate([
            'tanggal_nota' => 'required|date',
            'nama_pemasok' => 'required|string|max:100',
            'keterangan' => 'nullable|string',
            'gambar' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120'
        ]);

        $gambar = $request->file('gambar');

        $namaFile = bin2hex(random_bytes(16)) . '.' .
            $gambar->getClientOriginalExtension();

        $gambar->move(
            public_path('uploads/nota_pembelian'),
            $namaFile
        );

        $lokasi = 'uploads/nota_pembelian/' . $namaFile;

        try {
            $id = DB::table('nota_pembelian')->insertGetId([
                'tanggal_nota' => $request->tanggal_nota,
                'nama_pemasok' => trim($request->nama_pemasok),
                'lokasi_file' => $lokasi,
                'keterangan' => $request->keterangan
            ], 'id_nota');

            return response()->json([
                'success' => true,
                'message' => 'Nota berhasil ditambahkan.',
                'id_nota' => $id
            ], 201);

        } catch (\Throwable $error) {

            \Log::error('GAGAL MENYIMPAN NOTA: ' . $error->getMessage(), [
                'file' => $error->getFile(),
                'line' => $error->getLine()
            ]);

            File::delete(public_path($lokasi));

            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan nota.'
            ], 500);
                }
    }
}
