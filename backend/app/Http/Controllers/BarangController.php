<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BarangController extends Controller
{
    // Mengambil semua data barang
    public function index()
    {
        try {
            $barang = DB::table('barang')
                ->orderByDesc('id_barang')
                ->get()
                ->map(function ($item) {
                    if ($item->gambar) {
                        $item->gambar = asset($item->gambar);
                    }

                    return $item;
                });

            return response()->json([
                'success' => true,
                'data' => $barang
            ]);
        } catch (\Throwable $e) {
            \Log::error('GAGAL MENGAMBIL BARANG: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Menambah dan mengedit barang
    public function store(Request $request)
    {
        $aksi = $request->input('aksi', 'tambah');

        $kode = $request->input('kode_barang');
        $nama = $request->input('nama_barang');
        $stok = $request->input('stok');
        $hargaUmum = $request->input('harga_jual_umum');
        $hargaTokoMesin = $request->input('harga_jual_toko_mesin');

        // Validasi data
        if (
            empty($nama) ||
            $stok === null ||
            $hargaUmum === null ||
            $hargaTokoMesin === null ||
            !is_numeric($stok) ||
            !is_numeric($hargaUmum) ||
            !is_numeric($hargaTokoMesin) ||
            !preg_match('/^\d+$/', (string) $stok) ||
            $stok < 0 ||
            $hargaUmum < 0 ||
            $hargaTokoMesin < 0
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Data barang tidak valid'
            ], 400);
        }

        if (!in_array($aksi, ['tambah', 'edit'])) {
            return response()->json([
                'success' => false,
                'message' => 'Aksi tidak valid'
            ], 400);
        }

        // Menyiapkan data barang
        $data = [
            'nama_barang' => $nama,
            'stok' => $stok,
            'harga_jual_umum' => $hargaUmum,
            'harga_jual_toko_mesin' => $hargaTokoMesin
        ];

        if ($aksi === 'edit') {
            $id = (int) $request->input('id_barang');

            if ($id <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'ID barang tidak valid'
                ], 400);
            }

            $barang = DB::table('barang')
                ->where('id_barang', $id)
                ->first();

            if (!$barang) {
                return response()->json([
                    'success' => false,
                    'message' => 'Barang tidak ditemukan'
                ], 404);
            }

            // Mempertahankan gambar lama jika tidak ada gambar baru
            $data['gambar'] = $barang->gambar;
        } else {
            if (empty($kode)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kode barang wajib diisi'
                ], 400);
            }

            $data['kode_barang'] = $kode;
            $data['gambar'] = null;
        }

        // Upload gambar jika ada
        if ($request->hasFile('gambar')) {
            $file = $request->file('gambar');

            if (!$file->isValid() || $file->getSize() > 5 * 1024 * 1024) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gambar gagal diunggah atau melebihi 5 MB'
                ], 400);
            }

            $mime = $file->getMimeType();

            $format = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp'
            ];

            if (
                !isset($format[$mime]) ||
                @getimagesize($file->getRealPath()) === false
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Format gambar harus JPG, PNG, atau WEBP'
                ], 400);
            }

            $namaFile = uniqid('barang_', true) . '.' . $format[$mime];

            $folder = public_path('uploads/barang');

            if (!is_dir($folder)) {
                mkdir($folder, 0755, true);
            }

            $file->move($folder, $namaFile);

            $data['gambar'] = 'uploads/barang/' . $namaFile;
        }

        try {
            if ($aksi === 'edit') {
                DB::table('barang')
                    ->where('id_barang', $id)
                    ->update($data);
            } else {
                DB::table('barang')->insert($data);
            }

            return response()->json([
                'success' => true,
                'message' => 'Data barang berhasil disimpan'
            ]);
        } catch (\Throwable $e) {
            \Log::error('GAGAL MENYIMPAN BARANG: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Menghapus barang
    public function destroy(Request $request)
    {
        $id = (int) $request->input('id_barang', 0);

        if ($id <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'ID barang tidak valid'
            ], 400);
        }

        try {
            $terhapus = DB::table('barang')
                ->where('id_barang', $id)
                ->delete();

            if ($terhapus === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Barang tidak ditemukan'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Barang berhasil dihapus'
            ]);

        } catch (\Throwable $e) {
            \Log::error('Gagal menghapus barang: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus barang'
            ], 500);
        }
    }
}
