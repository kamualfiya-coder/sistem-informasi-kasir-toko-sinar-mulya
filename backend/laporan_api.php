
<?php
header("Content-Type: application/json");
require_once "koneksi.php";

// GET: Menampilkan semua laporan
if ($_SERVER["REQUEST_METHOD"] === "GET") {

    $query = mysqli_query(
        $conn,
        "SELECT * FROM laporan ORDER BY id_laporan DESC"
    );

    if (!$query) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Gagal mengambil data laporan."
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

$input = json_decode(file_get_contents("php://input"), true);

// POST: Menambahkan laporan
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $jenis = trim($input["jenis_laporan"] ?? "");
    $mulai = $input["tanggal_mulai"] ?? "";
    $selesai = $input["tanggal_selesai"] ?? "";
    $file = $input["lokasi_file"] ?? null;

    if ($jenis === "" || $mulai === "" || $selesai === "") {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Jenis laporan dan tanggal harus diisi."
        ]);
        exit;
    }

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO laporan
        (jenis_laporan, tanggal_mulai, tanggal_selesai,
         tanggal_dibuat, lokasi_file)
        VALUES (?, ?, ?, NOW(), ?)"
    );

    mysqli_stmt_bind_param(
        $stmt, "ssss", $jenis, $mulai, $selesai, $file
    );

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode([
            "success" => true,
            "message" => "Laporan berhasil ditambahkan.",
            "id_laporan" => mysqli_insert_id($conn)
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Gagal menambahkan laporan."
        ]);
    }

    mysqli_stmt_close($stmt);
    exit;
}

// PUT: Mengubah data laporan
if ($_SERVER["REQUEST_METHOD"] === "PUT") {

    $id = (int) ($input["id_laporan"] ?? 0);
    $jenis = trim($input["jenis_laporan"] ?? "");
    $mulai = $input["tanggal_mulai"] ?? "";
    $selesai = $input["tanggal_selesai"] ?? "";
    $file = $input["lokasi_file"] ?? null;

    if ($id <= 0 || $jenis === "" ||
        $mulai === "" || $selesai === "") {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Data laporan tidak valid."
        ]);
        exit;
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE laporan
         SET jenis_laporan = ?, tanggal_mulai = ?,
             tanggal_selesai = ?, lokasi_file = ?
         WHERE id_laporan = ?"
    );

    mysqli_stmt_bind_param(
        $stmt, "ssssi", $jenis, $mulai, $selesai, $file, $id
    );

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode([
            "success" => true,
            "message" => "Laporan berhasil diperbarui."
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Gagal memperbarui laporan."
        ]);
    }

    mysqli_stmt_close($stmt);
    exit;
}

// DELETE: Menghapus laporan
if ($_SERVER["REQUEST_METHOD"] === "DELETE") {

    $id = (int) ($input["id_laporan"] ?? 0);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "ID laporan tidak valid."
        ]);
        exit;
    }

    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM laporan WHERE id_laporan = ?"
    );

    mysqli_stmt_bind_param($stmt, "i", $id);

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode([
            "success" => true,
            "message" => "Laporan berhasil dihapus."
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Gagal menghapus laporan."
        ]);
    }

    mysqli_stmt_close($stmt);
    exit;
}

http_response_code(405);
echo json_encode([
    "success" => false,
    "message" => "Metode tidak diizinkan."
]);
?>
