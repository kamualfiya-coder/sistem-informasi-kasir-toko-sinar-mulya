
<?php

$host = "localhost";
$user = "root";
$password = "";
$database = "db_toko_sinar_mulya";

$conn = mysqli_connect(
    $host,
    $user,
    $password,
    $database
);

if (!$conn) {
    http_response_code(500);
    die("Koneksi database gagal.");
}

mysqli_set_charset($conn, "utf8mb4");

?>
