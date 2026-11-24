
<?php
header("Content-Type: application/json");
require_once "koneksi.php";

// GET: Mengambil semua data pelanggan
if ($_SERVER["REQUEST_METHOD"] === "GET") {

    $query = mysqli_query($conn, "SELECT * FROM pelanggan ORDER BY id_pelanggan DESC");

    if (!$query) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Gagal mengambil data pelanggan."
        ]);
        exit;
    }

    $data = [];

    while ($row = mysqli_fetch_assoc($query)) {
        $data[] = $row;
    }

    echo json_encode([
        "success" => true,
        "data" => $data
    ]);
    exit;
}

// Membaca data JSON
$input = json_decode(file_get_contents("php://input"), true);

// POST: Menambahkan pelanggan
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $nama = trim($input["nama_pelanggan"] ?? "");
    $kategori = $input["kategori_pelanggan"] ?? "";
    $alamat = trim($input["alamat"] ?? "");
    $telepon = trim($input["no_telepon"] ?? "");

    if ($nama === "" || !in_array($kategori, ["umum", "toko_mesin"])) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Nama dan kategori pelanggan harus diisi."
        ]);
        exit;
    }

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO pelanggan
        (nama_pelanggan, kategori_pelanggan, alamat, no_telepon)
        VALUES (?, ?, ?, ?)"
    );

    mysqli_stmt_bind_param($stmt, "ssss", $nama, $kategori, $alamat, $telepon);

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode([
            "success" => true,
            "message" => "Pelanggan berhasil ditambahkan.",
            "id_pelanggan" => mysqli_insert_id($conn)
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Gagal menambahkan pelanggan."
        ]);
    }

    mysqli_stmt_close($stmt);
    exit;
}

// PUT: Mengubah data pelanggan
if ($_SERVER["REQUEST_METHOD"] === "PUT") {

    $id = (int) ($input["id_pelanggan"] ?? 0);
    $nama = trim($input["nama_pelanggan"] ?? "");
    $kategori = $input["kategori_pelanggan"] ?? "";
    $alamat = trim($input["alamat"] ?? "");
    $telepon = trim($input["no_telepon"] ?? "");

    if ($id <= 0 || $nama === "" ||
        !in_array($kategori, ["umum", "toko_mesin"])) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Data pelanggan tidak valid."
        ]);
        exit;
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE pelanggan
         SET nama_pelanggan = ?, kategori_pelanggan = ?,
             alamat = ?, no_telepon = ?
         WHERE id_pelanggan = ?"
    );

    mysqli_stmt_bind_param(
        $stmt, "ssssi", $nama, $kategori, $alamat, $telepon, $id
    );

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode([
            "success" => true,
            "message" => "Data pelanggan berhasil diperbarui."
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Gagal memperbarui pelanggan."
        ]);
    }

    mysqli_stmt_close($stmt);
    exit;
}

// DELETE: Menghapus pelanggan
if ($_SERVER["REQUEST_METHOD"] === "DELETE") {

    $id = (int) ($input["id_pelanggan"] ?? 0);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "ID pelanggan tidak valid."
        ]);
        exit;
    }

    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM pelanggan WHERE id_pelanggan = ?"
    );

    mysqli_stmt_bind_param($stmt, "i", $id);

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode([
            "success" => true,
            "message" => "Pelanggan berhasil dihapus."
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Gagal menghapus pelanggan."
        ]);
    }

    mysqli_stmt_close($stmt);
    exit;
}

// Metode selain GET, POST, PUT, DELETE
http_response_code(405);
echo json_encode([
    "success" => false,
    "message" => "Metode tidak diizinkan."
]);
?>
