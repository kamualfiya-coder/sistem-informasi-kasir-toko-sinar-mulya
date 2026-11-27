<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    // POST: Menambahkan nota pembelian manual
    public function store(Request $request)
    {
        $request->validate([
            'tanggal_nota' => 'required|date',
            'nama_pemasok' => 'required|string|max:100',
            'keterangan' => 'required|json'
        ]);

        try {
            $rincian = json_decode($request->keterangan, true);

            if (
                !is_array($rincian) ||
                !isset($rincian['items']) ||
                !is_array($rincian['items']) ||
                !isset($rincian['grand_total'])
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Format rincian nota tidak valid.'
                ], 422);
            }

            foreach ($rincian['items'] as $item) {
                if (
                    !isset($item['name'], $item['quantity'], $item['unit_price'], $item['total']) ||
                    !is_string($item['name']) ||
                    trim($item['name']) === '' ||
                    !is_numeric($item['quantity']) ||
                    $item['quantity'] <= 0 ||
                    !is_numeric($item['unit_price']) ||
                    $item['unit_price'] < 0 ||
                    !is_numeric($item['total']) ||
                    $item['total'] < 0
                ) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Data salah satu barang tidak valid.'
                    ], 422);
                }
            }

            $id = DB::table('nota_pembelian')->insertGetId([
                'tanggal_nota' => $request->tanggal_nota,
                'nama_pemasok' => trim($request->nama_pemasok),
                'lokasi_file' => null,
                'keterangan' => $request->keterangan
            ], 'id_nota');

            return response()->json([
                'success' => true,
                'message' => 'Nota pembelian berhasil disimpan.',
                'id_nota' => $id
            ], 201);

        } catch (\Throwable $error) {
            \Log::error('GAGAL MENYIMPAN NOTA: ' . $error->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan nota.'
            ], 500);
        }
    }


    // PUT: Mengedit nota pembelian
    public function update(Request $request, $id)
    {
        $request->validate([
            'tanggal_nota' => 'required|date',
            'nama_pemasok' => 'required|string|max:100',
            'keterangan' => 'required|json'
        ]);

        $nota = DB::table('nota_pembelian')
            ->where('id_nota', $id)
            ->first();

        if (!$nota) {
            return response()->json([
                'success' => false,
                'message' => 'Nota tidak ditemukan.'
            ], 404);
        }

        $rincian = json_decode($request->keterangan, true);

        if (
            !is_array($rincian) ||
            empty($rincian['items']) ||
            !isset($rincian['grand_total'])
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Format rincian nota tidak valid.'
            ], 422);
        }

        foreach ($rincian['items'] as $item) {
            if (
                !isset($item['name'], $item['quantity'], $item['unit_price'], $item['total']) ||
                !is_string($item['name']) ||
                trim($item['name']) === '' ||
                !is_numeric($item['quantity']) ||
                $item['quantity'] <= 0 ||
                !is_numeric($item['unit_price']) ||
                $item['unit_price'] < 0 ||
                !is_numeric($item['total']) ||
                $item['total'] < 0 ||
                (float) $item['total'] !==
                    (float) $item['quantity'] * (float) $item['unit_price']
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data barang tidak valid.'
                ], 422);
            }
        }

        $totalHitung = array_sum(array_column($rincian['items'], 'total'));

        if ((float) $rincian['grand_total'] !== (float) $totalHitung) {
            return response()->json([
                'success' => false,
                'message' => 'Total pembelian tidak sesuai.'
            ], 422);
        }

        DB::table('nota_pembelian')
            ->where('id_nota', $id)
            ->update([
                'tanggal_nota' => $request->tanggal_nota,
                'nama_pemasok' => trim($request->nama_pemasok),
                'keterangan' => $request->keterangan
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Nota berhasil diperbarui.'
        ]);
    }

    // DELETE: Menghapus nota pembelian
    public function destroy($id)
    {
        $nota = DB::table('nota_pembelian')
            ->where('id_nota', $id)
            ->first();

        if (!$nota) {
            return response()->json([
                'success' => false,
                'message' => 'Nota tidak ditemukan.'
            ], 404);
        }

        DB::table('nota_pembelian')
            ->where('id_nota', $id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Nota berhasil dihapus.'
        ]);
    }

}
