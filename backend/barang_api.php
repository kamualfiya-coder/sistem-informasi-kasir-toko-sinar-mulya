
<?php

header("Content-Type: application/json; charset=UTF-8");

require_once "koneksi.php";

function kirimJSON($status, $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Lokasi penyimpanan gambar
$folderUpload = __DIR__ . "/uploads/barang/";

if (!is_dir($folderUpload)) {
    mkdir($folderUpload, 0775, true);
}

// Fungsi upload gambar
function uploadGambar($file, $folderUpload) {

    if (!isset($file) || $file["error"] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file["error"] !== UPLOAD_ERR_OK) {
        return false;
    }

    if ($file["size"] > 5 * 1024 * 1024) {
        return false;
    }

    $info = getimagesize($file["tmp_name"]);

    if ($info === false) {
        return false;
    }

    $mime = $info["mime"];

    $daftarTipe = [
        "image/jpeg" => "jpg",
        "image/png"  => "png",
        "image/webp" => "webp"
    ];

    if (!isset($daftarTipe[$mime])) {
        return false;
    }

    $namaFile = uniqid("barang_", true) . "." . $daftarTipe[$mime];

    if (!move_uploaded_file(
        $file["tmp_name"],
        $folderUpload . $namaFile
    )) {
        return false;
    }

    return "uploads/barang/" . $namaFile;
}

// Ambil metode request
$method = $_SERVER["REQUEST_METHOD"];

// ===============================
// GET: AMBIL SEMUA DATA BARANG
// ===============================
if ($method === "GET") {

    $query = mysqli_query($conn, "SELECT * FROM barang ORDER BY id_barang DESC");

    if (!$query) {
        kirimJSON(500, [
            "success" => false,
            "message" => "Gagal mengambil data barang"
        ]);
    }

    $data = [];

    while ($row = mysqli_fetch_assoc($query)) {
        $data[] = $row;
    }

    kirimJSON(200, [
        "success" => true,
        "data" => $data
    ]);
}

// ===============================
// POST: TAMBAH ATAU EDIT BARANG
// ===============================
if ($method === "POST") {

    $aksi = $_POST["aksi"] ?? "tambah";

    $kode = trim($_POST["kode_barang"] ?? "");
    $nama = trim($_POST["nama_barang"] ?? "");
    $stok = $_POST["stok"] ?? "";
    $hargaUmum = $_POST["harga_jual_umum"] ?? "";
    $hargaMesin = $_POST["harga_jual_toko_mesin"] ?? "";

    if (
        $nama === "" ||
        $stok === "" ||
        $hargaUmum === "" ||
        $hargaMesin === "" ||
        !is_numeric($stok) ||
        !is_numeric($hargaUmum) ||
        !is_numeric($hargaMesin) ||
        (float)$stok < 0 ||
        (int)$stok != (float)$stok ||
        (float)$hargaUmum < 0 ||
        (float)$hargaMesin < 0
    ) {
        kirimJSON(400, [
            "success" => false,
            "message" => "Data barang tidak valid"
        ]);
    }

    $stok = (int)$stok;
    $hargaUmum = (float)$hargaUmum;
    $hargaMesin = (float)$hargaMesin;

    if ($aksi === "edit") {

        $id = (int)($_POST["id_barang"] ?? 0);

        if ($id <= 0) {
            kirimJSON(400, [
                "success" => false,
                "message" => "ID barang tidak valid"
            ]);
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT gambar FROM barang WHERE id_barang = ?"
        );

        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);
        $barangLama = mysqli_fetch_assoc($result);

        mysqli_stmt_close($stmt);

        if (!$barangLama) {
            kirimJSON(404, [
                "success" => false,
                "message" => "Barang tidak ditemukan"
            ]);
        }

        $gambar = $barangLama["gambar"];

        $gambarBaru = uploadGambar($_FILES["gambar"] ?? null, $folderUpload);

        if ($gambarBaru === false) {
            kirimJSON(400, [
                "success" => false,
                "message" => "Gambar tidak valid atau gagal diunggah"
            ]);
        }

        if ($gambarBaru !== null) {
            $gambar = $gambarBaru;
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE barang
             SET kode_barang = ?, nama_barang = ?, stok = ?,
                 harga_jual_umum = ?, harga_jual_toko_mesin = ?,
                 gambar = ?
             WHERE id_barang = ?"
        );

        mysqli_stmt_bind_param(
            $stmt,
            "ssiddsi",
            $kode,
            $nama,
            $stok,
            $hargaUmum,
            $hargaMesin,
            $gambar,
            $id
        );

    } else {

        if ($kode === "") {
            kirimJSON(400, [
                "success" => false,
                "message" => "Kode barang wajib diisi"
            ]);
        }

        $gambar = uploadGambar($_FILES["gambar"] ?? null, $folderUpload);

        if ($gambar === false) {
            kirimJSON(400, [
                "success" => false,
                "message" => "Gambar tidak valid atau gagal diunggah"
            ]);
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO barang
             (kode_barang, nama_barang, stok, harga_jual_umum,
              harga_jual_toko_mesin, gambar)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        mysqli_stmt_bind_param(
            $stmt,
            "ssidds",
            $kode,
            $nama,
            $stok,
            $hargaUmum,
            $hargaMesin,
            $gambar
        );
    }

    if (!$stmt || !mysqli_stmt_execute($stmt)) {
        if ($stmt) {
            mysqli_stmt_close($stmt);
        }

        kirimJSON(500, [
            "success" => false,
            "message" => "Gagal menyimpan data barang"
        ]);
    }

    mysqli_stmt_close($stmt);

    kirimJSON(200, [
        "success" => true,
        "message" => "Data barang berhasil disimpan"
    ]);
}


// ===============================
// DELETE: HAPUS BARANG
// ===============================
if ($method === "DELETE") {

    $input = json_decode(file_get_contents("php://input"), true);
    $id = (int)($input["id_barang"] ?? 0);

    if ($id <= 0) {
        kirimJSON(400, [
            "success" => false,
            "message" => "ID barang tidak valid"
        ]);
    }

    try {

        $stmt = mysqli_prepare(
            $conn,
            "DELETE FROM barang WHERE id_barang = ?"
        );

        if (!$stmt) {
            throw new Exception(mysqli_error($conn));
        }

        mysqli_stmt_bind_param($stmt, "i", $id);

        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception(mysqli_stmt_error($stmt));
        }

        $terhapus = mysqli_stmt_affected_rows($stmt);

        mysqli_stmt_close($stmt);

        if ($terhapus === 0) {
            kirimJSON(404, [
                "success" => false,
                "message" => "Barang tidak ditemukan"
            ]);
        }

        kirimJSON(200, [
            "success" => true,
            "message" => "Barang berhasil dihapus"
        ]);

    } catch (Throwable $e) {

        error_log("Gagal menghapus barang: " . $e->getMessage());

        kirimJSON(500, [
            "success" => false,
            "message" => "Gagal menghapus barang: " . $e->getMessage()
        ]);
    }
}
