
<?php

header("Content-Type: application/json; charset=UTF-8");

require_once "koneksi.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


// ======================================
// FUNGSI RESPONS JSON
// ======================================
function kirimJSON($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}


// ======================================
// PENGATURAN REQUEST
// ======================================
$input = json_decode(file_get_contents("php://input"), true);

if (!is_array($input)) {
    $input = [];
}

$action = $_GET["action"] ?? "";


// ======================================
// 1. MENGAMBIL RINGKASAN TRANSAKSI DAN TAGIHAN
// ======================================
if ($_SERVER["REQUEST_METHOD"] === "GET" && $action === "ringkasan") {

    try {

        // Mengambil pembayaran yang benar-benar diterima
        
        $query = mysqli_query($conn, "
            SELECT
                p.id_pembayaran,
                p.id_transaksi,
                p.tanggal_pembayaran,
                p.jumlah_bayar,
                p.metode_pembayaran,
                t.jenis_transaksi,
                t.tanggal_transaksi,
                CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM detail_pembayaran dp
                        WHERE dp.id_pembayaran = p.id_pembayaran
                    )
                    THEN 1
                    ELSE 0
                END AS pernah_menjadi_utang
            FROM pembayaran p
            JOIN transaksi t
                ON p.id_transaksi = t.id_transaksi
            ORDER BY p.tanggal_pembayaran DESC
        ");


        $transaksi = [];

        while ($row = mysqli_fetch_assoc($query)) {
            $transaksi[] = $row;
        }


        // Mengambil transaksi yang belum lunas
        $queryTagihan = mysqli_query($conn, "
            SELECT *
            FROM transaksi
            WHERE status_pembayaran != 'lunas'
            ORDER BY tanggal_transaksi DESC
        ");

        $tagihan = [];

        while ($row = mysqli_fetch_assoc($queryTagihan)) {

            $idTransaksi = (int)$row["id_transaksi"];


            // Mengambil barang yang masih memiliki sisa tagihan
            $stmtDetail = mysqli_prepare($conn, "
                SELECT
                    dt.id_detail_transaksi,
                    dt.nama_barang,
                    dt.jumlah,
                    dt.harga_jual,
                    dt.subtotal,
                    COALESCE(SUM(dp.jumlah_dibayar), 0) AS sudah_dibayar
                FROM detail_transaksi dt
                LEFT JOIN detail_pembayaran dp
                    ON dt.id_detail_transaksi = dp.id_detail_transaksi
                WHERE dt.id_transaksi = ?
                GROUP BY
                    dt.id_detail_transaksi,
                    dt.nama_barang,
                    dt.jumlah,
                    dt.harga_jual,
                    dt.subtotal
            ");

            mysqli_stmt_bind_param(
                $stmtDetail,
                "i",
                $idTransaksi
            );

            mysqli_stmt_execute($stmtDetail);

            $hasilDetail = mysqli_stmt_get_result($stmtDetail);

            $remainingItems = [];

            while ($detail = mysqli_fetch_assoc($hasilDetail)) {

                $sisa = round(
                    (float)$detail["subtotal"] -
                    (float)$detail["sudah_dibayar"],
                    2
                );

                if ($sisa > 0) {
                    $remainingItems[] = [
                        "id" => (int)$detail["id_detail_transaksi"],
                        "name" => $detail["nama_barang"],
                        "qty" => (int)$detail["jumlah"],
                        "price" => (float)$detail["harga_jual"],
                        "subtotal" => $sisa
                    ];
                }
            }

            mysqli_stmt_close($stmtDetail);


            // Mengambil riwayat pembayaran
            $stmtBayar = mysqli_prepare($conn, "
                SELECT
                    jumlah_bayar,
                    tanggal_pembayaran,
                    metode_pembayaran
                FROM pembayaran
                WHERE id_transaksi = ?
                ORDER BY tanggal_pembayaran ASC
            ");

            mysqli_stmt_bind_param(
                $stmtBayar,
                "i",
                $idTransaksi
            );

            mysqli_stmt_execute($stmtBayar);

            $hasilBayar = mysqli_stmt_get_result($stmtBayar);

            $payments = [];

            while ($bayar = mysqli_fetch_assoc($hasilBayar)) {
                $payments[] = [
                    "total" => (float)$bayar["jumlah_bayar"],
                    "date" => $bayar["tanggal_pembayaran"],
                    "payment" => $bayar["metode_pembayaran"]
                ];
            }

            mysqli_stmt_close($stmtBayar);


            $tagihan[] = [
                "id" => $idTransaksi,
                "date" => $row["tanggal_transaksi"],
                "remainingItems" => $remainingItems,
                "payments" => $payments
            ];
        }


        kirimJSON([
            "success" => true,
            "transaksi" => $transaksi,
            "tagihan" => $tagihan
        ]);

    } catch (Throwable $e) {

        error_log("Ringkasan transaksi: " . $e->getMessage());

        kirimJSON([
            "success" => false,
            "message" => "Gagal mengambil data transaksi dan tagihan."
        ], 500);
    }
}


// ======================================
// 2. MENYIMPAN TRANSAKSI BARU
// ======================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && $action === "create") {

    $idUser = (int)($input["id_user"] ?? 0);
    $tanggal = $input["tanggal_transaksi"] ?? date("Y-m-d H:i:s");
    $jenis = $input["jenis_transaksi"] ?? "";
    $metode = $input["metode_pembayaran"] ?? "";
    $items = $input["items"] ?? [];

    if (
        $idUser <= 0 ||
        !in_array($jenis, ["umum", "toko_mesin"], true) ||
        !in_array($metode, ["tunai", "transfer", "utang"], true) ||
        !is_array($items) ||
        count($items) === 0
    ) {
        kirimJSON([
            "success" => false,
            "message" => "Data transaksi tidak lengkap atau tidak valid."
        ], 400);
    }


    mysqli_begin_transaction($conn);

    try {

        $total = 0;
        $daftarBarang = [];


        // Memeriksa barang dan mengunci stok
        foreach ($items as $item) {

            $idBarang = (int)($item["id_barang"] ?? 0);
            $jumlah = (int)($item["jumlah"] ?? 0);

            if ($idBarang <= 0 || $jumlah <= 0) {
                throw new Exception("Data barang tidak valid.");
            }


            $stmt = mysqli_prepare($conn, "
                SELECT
                    nama_barang,
                    stok,
                    harga_jual_umum,
                    harga_jual_toko_mesin
                FROM barang
                WHERE id_barang = ?
                FOR UPDATE
            ");

            mysqli_stmt_bind_param(
                $stmt,
                "i",
                $idBarang
            );

            mysqli_stmt_execute($stmt);

            $hasil = mysqli_stmt_get_result($stmt);
            $barang = mysqli_fetch_assoc($hasil);

            mysqli_stmt_close($stmt);


            if (!$barang) {
                throw new Exception("Barang tidak ditemukan.");
            }

            if ((int)$barang["stok"] < $jumlah) {
                throw new Exception("Stok barang tidak mencukupi.");
            }


            // Mengambil harga berdasarkan jenis transaksi
            $harga = ($jenis === "umum")
                ? (float)$barang["harga_jual_umum"]
                : (float)$barang["harga_jual_toko_mesin"];


            $subtotal = round($harga * $jumlah, 2);

            $total += $subtotal;


            $daftarBarang[] = [
                "id_barang" => $idBarang,
                "nama_barang" => $barang["nama_barang"],
                "jumlah" => $jumlah,
                "harga" => $harga,
                "subtotal" => $subtotal
            ];
        }


        $total = round($total, 2);

        $status = ($metode === "utang")
            ? "belum_lunas"
            : "lunas";


        // Menyimpan transaksi utama
        $stmtTransaksi = mysqli_prepare($conn, "
            INSERT INTO transaksi (
                id_user,
                jenis_transaksi,
                tanggal_transaksi,
                total_transaksi,
                status_pembayaran
            )
            VALUES (?, ?, ?, ?, ?)
        ");

        mysqli_stmt_bind_param(
            $stmtTransaksi,
            "issds",
            $idUser,
            $jenis,
            $tanggal,
            $total,
            $status
        );

        mysqli_stmt_execute($stmtTransaksi);

        $idTransaksi = mysqli_insert_id($conn);

        mysqli_stmt_close($stmtTransaksi);


        // Menyimpan detail transaksi dan mengurangi stok
        foreach ($daftarBarang as $item) {

            $stmtDetail = mysqli_prepare($conn, "
                INSERT INTO detail_transaksi (
                    id_transaksi,
                    id_barang,
                    nama_barang,
                    jumlah,
                    harga_jual,
                    subtotal
                )
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            mysqli_stmt_bind_param(
                $stmtDetail,
                "iisidd",
                $idTransaksi,
                $item["id_barang"],
                $item["nama_barang"],
                $item["jumlah"],
                $item["harga"],
                $item["subtotal"]
            );

            mysqli_stmt_execute($stmtDetail);

            mysqli_stmt_close($stmtDetail);


            // Mengurangi stok barang
            $stmtStok = mysqli_prepare($conn, "
                UPDATE barang
                SET stok = stok - ?
                WHERE id_barang = ?
            ");

            mysqli_stmt_bind_param(
                $stmtStok,
                "ii",
                $item["jumlah"],
                $item["id_barang"]
            );

            mysqli_stmt_execute($stmtStok);

            mysqli_stmt_close($stmtStok);
        }


        // Pembayaran tunai/transfer langsung dicatat
        // Utang tidak langsung dihitung sebagai pemasukan
        if ($metode !== "utang") {

            $stmtBayar = mysqli_prepare($conn, "
                INSERT INTO pembayaran (
                    id_transaksi,
                    tanggal_pembayaran,
                    jumlah_bayar,
                    metode_pembayaran
                )
                VALUES (?, ?, ?, ?)
            ");

            mysqli_stmt_bind_param(
                $stmtBayar,
                "isds",
                $idTransaksi,
                $tanggal,
                $total,
                $metode
            );

            mysqli_stmt_execute($stmtBayar);

            mysqli_stmt_close($stmtBayar);
        }


        mysqli_commit($conn);


        kirimJSON([
            "success" => true,
            "message" => "Transaksi berhasil disimpan.",
            "id_transaksi" => $idTransaksi,
            "total_transaksi" => $total
        ]);

    } catch (Throwable $e) {

        mysqli_rollback($conn);

        error_log("Simpan transaksi: " . $e->getMessage());

        kirimJSON([
            "success" => false,
            "message" => $e->getMessage()
        ], 400);
    }
}


// ======================================
// 3. MEMBAYAR TAGIHAN / CICILAN
// ======================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && $action === "pay-bill") {

    $idTransaksi = (int)($input["id_transaksi"] ?? 0);
    $metode = $input["metode_pembayaran"] ?? "";
    $detail = $input["detail"] ?? [];

    if (
        $idTransaksi <= 0 ||
        !in_array($metode, ["tunai", "transfer"], true) ||
        !is_array($detail) ||
        count($detail) === 0
    ) {
        kirimJSON([
            "success" => false,
            "message" => "Data pembayaran tagihan tidak valid."
        ], 400);
    }


    mysqli_begin_transaction($conn);

    try {

        // Mengunci transaksi yang akan dibayar
        $stmtTransaksi = mysqli_prepare($conn, "
            SELECT
                total_transaksi,
                status_pembayaran
            FROM transaksi
            WHERE id_transaksi = ?
            FOR UPDATE
        ");

        mysqli_stmt_bind_param(
            $stmtTransaksi,
            "i",
            $idTransaksi
        );

        mysqli_stmt_execute($stmtTransaksi);

        $hasilTransaksi = mysqli_stmt_get_result($stmtTransaksi);
        $transaksi = mysqli_fetch_assoc($hasilTransaksi);

        mysqli_stmt_close($stmtTransaksi);


        if (!$transaksi) {
            throw new Exception("Transaksi tidak ditemukan.");
        }

        if ($transaksi["status_pembayaran"] === "lunas") {
            throw new Exception("Tagihan sudah lunas.");
        }


        $daftarPembayaran = [];
        $totalBayar = 0;
        $idTerpilih = [];


        foreach ($detail as $item) {

            $idDetail = (int)($item["id_detail_transaksi"] ?? 0);

            $jumlahBayar = round(
                (float)($item["jumlah_dibayar"] ?? 0),
                2
            );


            if ($idDetail <= 0 || $jumlahBayar <= 0) {
                throw new Exception("Detail pembayaran tidak valid.");
            }

            if (in_array($idDetail, $idTerpilih, true)) {
                throw new Exception("Barang yang sama dipilih lebih dari sekali.");
            }

            $idTerpilih[] = $idDetail;


            // Memastikan detail barang milik transaksi
            $stmtDetail = mysqli_prepare($conn, "
                SELECT subtotal
                FROM detail_transaksi
                WHERE id_detail_transaksi = ?
                  AND id_transaksi = ?
                FOR UPDATE
            ");

            mysqli_stmt_bind_param(
                $stmtDetail,
                "ii",
                $idDetail,
                $idTransaksi
            );

            mysqli_stmt_execute($stmtDetail);

            $hasilDetail = mysqli_stmt_get_result($stmtDetail);
            $dataDetail = mysqli_fetch_assoc($hasilDetail);

            mysqli_stmt_close($stmtDetail);


            if (!$dataDetail) {
                throw new Exception("Detail barang tidak ditemukan.");
            }


            // Menghitung jumlah yang sudah dibayar
            $stmtSudahBayar = mysqli_prepare($conn, "
                SELECT COALESCE(SUM(jumlah_dibayar), 0) AS sudah_dibayar
                FROM detail_pembayaran
                WHERE id_detail_transaksi = ?
            ");

            mysqli_stmt_bind_param(
                $stmtSudahBayar,
                "i",
                $idDetail
            );

            mysqli_stmt_execute($stmtSudahBayar);

            $hasilSudahBayar = mysqli_stmt_get_result($stmtSudahBayar);
            $dataSudahBayar = mysqli_fetch_assoc($hasilSudahBayar);

            mysqli_stmt_close($stmtSudahBayar);


            $sisa = round(
                (float)$dataDetail["subtotal"] -
                (float)$dataSudahBayar["sudah_dibayar"],
                2
            );


            if ($sisa <= 0) {
                throw new Exception("Barang tersebut sudah lunas.");
            }

            if (abs($jumlahBayar - $sisa) > 0.01) {
                throw new Exception(
                    "Jumlah pembayaran barang tidak sesuai dengan sisa tagihan."
                );
            }


            $daftarPembayaran[] = [
                "id_detail_transaksi" => $idDetail,
                "jumlah_dibayar" => $jumlahBayar
            ];

            $totalBayar += $jumlahBayar;
        }


        if ($totalBayar <= 0) {
            throw new Exception("Jumlah pembayaran harus lebih dari nol.");
        }

        $totalBayar = round($totalBayar, 2);

        $tanggalBayar = date("Y-m-d H:i:s");


        // Menyimpan pembayaran yang benar-benar diterima
        $stmtBayar = mysqli_prepare($conn, "
            INSERT INTO pembayaran (
                id_transaksi,
                tanggal_pembayaran,
                jumlah_bayar,
                metode_pembayaran
            )
            VALUES (?, ?, ?, ?)
        ");

        mysqli_stmt_bind_param(
            $stmtBayar,
            "isds",
            $idTransaksi,
            $tanggalBayar,
            $totalBayar,
            $metode
        );

        mysqli_stmt_execute($stmtBayar);

        $idPembayaran = mysqli_insert_id($conn);

        mysqli_stmt_close($stmtBayar);


        // Mengalokasikan pembayaran ke barang yang dipilih
        foreach ($daftarPembayaran as $item) {

            $stmtAlokasi = mysqli_prepare($conn, "
                INSERT INTO detail_pembayaran (
                    id_pembayaran,
                    id_detail_transaksi,
                    jumlah_dibayar
                )
                VALUES (?, ?, ?)
            ");

            mysqli_stmt_bind_param(
                $stmtAlokasi,
                "iid",
                $idPembayaran,
                $item["id_detail_transaksi"],
                $item["jumlah_dibayar"]
            );

            mysqli_stmt_execute($stmtAlokasi);

            mysqli_stmt_close($stmtAlokasi);
        }


        // Menghitung total uang yang sudah diterima
        $stmtTotal = mysqli_prepare($conn, "
            SELECT COALESCE(SUM(jumlah_bayar), 0) AS total_dibayar
            FROM pembayaran
            WHERE id_transaksi = ?
        ");

        mysqli_stmt_bind_param(
            $stmtTotal,
            "i",
            $idTransaksi
        );

        mysqli_stmt_execute($stmtTotal);

        $hasilTotal = mysqli_stmt_get_result($stmtTotal);
        $dataTotal = mysqli_fetch_assoc($hasilTotal);

        mysqli_stmt_close($stmtTotal);


        $totalDibayar = (float)$dataTotal["total_dibayar"];
        $totalTransaksi = (float)$transaksi["total_transaksi"];


        $statusBaru = ($totalDibayar >= $totalTransaksi - 0.01)
            ? "lunas"
            : "sebagian";


        // Memperbarui status tagihan
        $stmtUpdate = mysqli_prepare($conn, "
            UPDATE transaksi
            SET status_pembayaran = ?
            WHERE id_transaksi = ?
        ");

        mysqli_stmt_bind_param(
            $stmtUpdate,
            "si",
            $statusBaru,
            $idTransaksi
        );

        mysqli_stmt_execute($stmtUpdate);

        mysqli_stmt_close($stmtUpdate);


        mysqli_commit($conn);


        kirimJSON([
            "success" => true,
            "message" => "Pembayaran tagihan berhasil disimpan.",
            "total_pembayaran" => $totalBayar,
            "status_pembayaran" => $statusBaru
        ]);

    } catch (Throwable $e) {

        mysqli_rollback($conn);

        error_log("Pembayaran tagihan: " . $e->getMessage());

        kirimJSON([
            "success" => false,
            "message" => $e->getMessage()
        ], 400);
    }
}


// ======================================
// ACTION TIDAK DIKENALI
// ======================================
kirimJSON([
    "success" => false,
    "message" => "Permintaan tidak dikenali."
], 400);

?>
