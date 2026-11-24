
<?php

header("Content-Type: application/json; charset=UTF-8");

require_once "koneksi.php";

function kirimJSON($status, $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Ambil data dari JavaScript
$input = json_decode(file_get_contents("php://input"), true);

$username = trim($input["username"] ?? "");
$password = $input["password"] ?? "";

// Validasi input
if ($username === "" || $password === "") {
    kirimJSON(400, [
        "success" => false,
        "message" => "Username dan password wajib diisi"
    ]);
}

// Cari akun berdasarkan username
$query = "SELECT id_user, nama, username, password, role
          FROM users
          WHERE username = ?
          LIMIT 1";

$stmt = mysqli_prepare($conn, $query);

if (!$stmt) {
    kirimJSON(500, [
        "success" => false,
        "message" => "Terjadi kesalahan pada server"
    ]);
}

mysqli_stmt_bind_param($stmt, "s", $username);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

// Periksa akun dan password
if (!$user || $password !== $user["password"]) {
    kirimJSON(401, [
        "success" => false,
        "message" => "Username atau password salah"
    ]);
}

// Login berhasil
kirimJSON(200, [
    "success" => true,
    "message" => "Login berhasil",
    "user" => [
        "id_user" => (int) $user["id_user"],
        "nama" => $user["nama"],
        "username" => $user["username"],
        "role" => $user["role"]
    ]
]);

?>
