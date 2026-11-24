<?php

$host = getenv("DB_HOST");
$user = getenv("DB_USER");
$password = getenv("DB_PASSWORD");
$database = getenv("DB_NAME");
$port = (int) getenv("DB_PORT");

$conn = mysqli_init();

mysqli_ssl_set(
    $conn,
    null,
    null,
    "/etc/ssl/certs/tidb-ca.pem",
    null,
    null
);

$connected = mysqli_real_connect(
    $conn,
    $host,
    $user,
    $password,
    $database,
    $port,
    null,
    MYSQLI_CLIENT_SSL
);

if (!$connected) {
    http_response_code(500);
    die("Koneksi database gagal.");
}

mysqli_set_charset($conn, "utf8mb4");

?>