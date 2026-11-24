
<?php
header("Content-Type: application/json");
require_once "koneksi.php";

// GET: Menampilkan semua nota
if ($_SERVER["REQUEST_METHOD"] === "GET") {

    $query = mysqli_query(
        $conn,
        "SELECT * FROM nota_pembelian ORDER BY id_nota DESC"
    );

    if (!$query) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Gagal mengambil data nota."
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

// Membaca data dari FormData
$input = $_POST;


// POST: Menambahkan nota
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $tanggal = $_POST["tanggal_nota"] ?? "";
    $pemasok = trim($_POST["nama_pemasok"] ?? "");
    $keterangan = trim($_POST["keterangan"] ?? "");

    if ($tanggal === "" || $pemasok === "") {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Tanggal dan nama pemasok harus diisi."
        ]);
        exit;
    }

    // Periksa file gambar.
    if (!isset($_FILES["gambar"]) ||
        $_FILES["gambar"]["error"] !== UPLOAD_ERR_OK) {

        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Foto nota wajib diunggah."
        ]);
        exit;
    }

    // Validasi jenis gambar.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES["gambar"]["tmp_name"]);
    finfo_close($finfo);

    $ekstensiGambar = [
        "image/jpeg" => "jpg",
        "image/png"  => "png",
        "image/webp" => "webp"
    ];

    if (!isset($ekstensiGambar[$mime])) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Format foto harus JPG, PNG, atau WEBP."
        ]);
        exit;
    }

    // Siapkan folder penyimpanan.
    $folder = __DIR__ . "/uploads/nota_pembelian/";

    if (!is_dir($folder)) {
        mkdir($folder, 0755, true);
    }

    // Buat nama file unik.
    $namaFile = bin2hex(random_bytes(16)) . "." .
                $ekstensiGambar[$mime];

    $lokasi = "uploads/nota_pembelian/" . $namaFile;

    // Pindahkan foto ke folder.
    if (!move_uploaded_file(
        $_FILES["gambar"]["tmp_name"],
        $folder . $namaFile
    )) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Gagal menyimpan foto nota."
        ]);
        exit;
    }

    // Simpan data nota ke database.
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO nota_pembelian
        (tanggal_nota, nama_pemasok, lokasi_file, keterangan)
        VALUES (?, ?, ?, ?)"
    );

    if (!$stmt) {
        unlink($folder . $namaFile);
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Gagal menyiapkan penyimpanan nota."
        ]);
        exit;
    }

    mysqli_stmt_bind_param(
        $stmt, "ssss", $tanggal, $pemasok, $lokasi, $keterangan
    );

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode([
            "success" => true,
            "message" => "Nota berhasil ditambahkan.",
            "id_nota" => mysqli_insert_id($conn)
        ]);
    } else {
        unlink($folder . $namaFile);

        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Gagal menambahkan nota."
        ]);
    }

    mysqli_stmt_close($stmt);
    exit;
}

// PUT: Mengubah nota
if ($_SERVER["REQUEST_METHOD"] === "PUT") {

    $id = (int) ($input["id_nota"] ?? 0);
    $tanggal = $_POST["tanggal_nota"] ?? "";
$pemasok = trim($_POST["nama_pemasok"] ?? "");
    $lokasi = $input["lokasi_file"] ?? null;
    $keterangan = trim($input["keterangan"] ?? "");

    if ($id <= 0 || $tanggal === "" || $pemasok === "") {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Data nota tidak valid."
        ]);
        exit;
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE nota_pembelian
         SET tanggal_nota = ?, nama_pemasok = ?,
             lokasi_file = ?, keterangan = ?
         WHERE id_nota = ?"
    );

    mysqli_stmt_bind_param(
        $stmt, "ssssi", $tanggal, $pemasok, $lokasi, $keterangan, $id
    );

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode([
            "success" => true,
            "message" => "Nota berhasil diperbarui."
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Gagal memperbarui nota."
        ]);
    }

    mysqli_stmt_close($stmt);
    exit;
}

// DELETE: Menghapus nota
if ($_SERVER["REQUEST_METHOD"] === "DELETE") {

    $id = (int) ($input["id_nota"] ?? 0);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "ID nota tidak valid."
        ]);
        exit;
    }

    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM nota_pembelian WHERE id_nota = ?"
    );

    mysqli_stmt_bind_param($stmt, "i", $id);

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode([
            "success" => true,
            "message" => "Nota berhasil dihapus."
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Gagal menghapus nota."
        ]);
    }

    mysqli_stmt_close($stmt);
    exit;
}

// Metode tidak diizinkan
http_response_code(405);
echo json_encode([
    "success" => false,
    "message" => "Metode tidak diizinkan."
]);
?>
