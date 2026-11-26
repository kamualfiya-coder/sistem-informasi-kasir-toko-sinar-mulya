<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoginController extends Controller
{
    public function login(Request $request)
    {
        $username = trim($request->input('username', ''));
        $password = $request->input('password', '');

        // Validasi input
        if ($username === '' || $password === '') {
            return response()->json([
                'success' => false,
                'message' => 'Username dan password wajib diisi'
            ], 400);
        }

        try {
            // Cari akun berdasarkan username
            $user = DB::table('users')
                ->select('id_user', 'nama', 'username', 'password', 'role')
                ->where('username', $username)
                ->first();

            // Periksa akun dan password
            if (!$user || $password !== $user->password) {
                return response()->json([
                    'success' => false,
                    'message' => 'Username atau password salah'
                ], 401);
            }

            // Login berhasil
            return response()->json([
                'success' => true,
                'message' => 'Login berhasil',
                'user' => [
                    'id_user' => (int) $user->id_user,
                    'nama' => $user->nama,
                    'username' => $user->username,
                    'role' => $user->role
                ]
            ]);

        } catch (\Throwable $e) {
            \Log::error('Login gagal: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan pada server'
            ], 500);
        }
    }
}