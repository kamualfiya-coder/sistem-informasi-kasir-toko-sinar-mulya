// ======================================================
// BAGIAN 1. PENGATURAN AWAL
// ======================================================


// Mengambil elemen HTML berdasarkan ID.
const $ = (id) => document.getElementById(id);


// Alamat file PHP API.
const API = {
    barang: "http://127.0.0.1:8000/api/barang",
    transaksi: "http://127.0.0.1:8000/api/transaksi",
    login: "http://127.0.0.1:8000/api/login",
    nota: "http://127.0.0.1:8000/api/nota"
};


// Mengambil tanggal hari ini.
function getToday() {
    const today = new Date();

    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, "0");
    const day = String(today.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}`;
}


// Mengubah angka menjadi format Rupiah.
function rupiah(number) {
    return new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0
    }).format(Number(number) || 0);
}


// Membuat ID unik.
function buatId() {
    return Date.now().toString() +
        Math.random().toString(16).slice(2);
}


// Mengamankan teks sebelum dimasukkan ke HTML.
function escapeHTML(value) {
    return String(value ?? "").replace(/[&<>"']/g, (char) => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;"
    })[char]);
}


// Mengubah tanggal menjadi format Indonesia.
function formatTanggal(date) {
    if (!date) return "-";

    const tanggal = String(date).slice(0, 10);
    const hasilTanggal = new Date(`${tanggal}T00:00:00`);

    if (Number.isNaN(hasilTanggal.getTime())) {
        return "-";
    }

    return hasilTanggal.toLocaleDateString("id-ID", {
        day: "numeric",
        month: "long",
        year: "numeric"
    });
}


// Menampilkan pesan pemberitahuan.
function showToast(message) {
    const toast = $("toast");

    if (!toast) {
        alert(message);
        return;
    }

    toast.textContent = message;
    toast.classList.add("show");

    setTimeout(() => {
        toast.classList.remove("show");
    }, 2500);
}

// ======================================================
// BAGIAN 2. KONEKSI API DAN PENGAMBILAN DATA
// ======================================================


// ------------------------------------------------------
// 2.1. FUNGSI KONEKSI API
// ------------------------------------------------------

// Mengirim permintaan ke PHP API.
async function apiRequest(url, options = {}) {
    const response = await fetch(url, options);

    let hasil;

    try {
        hasil = await response.json();
    } catch (error) {
        throw new Error("Respons dari server tidak valid.");
    }

    if (!response.ok || hasil?.success === false) {
        throw new Error(
            hasil?.pesan ||
            hasil?.message ||
            "Permintaan ke server gagal."
        );
    }

    return hasil;
}


// Menyiapkan data JSON untuk dikirim ke PHP.
function jsonOptions(method, data) {
    return {
        method: method,
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify(data)
    };
}


// ------------------------------------------------------
// 2.2. PENYIMPANAN DATA APLIKASI
// ------------------------------------------------------

// Data barang.
let products = [];

// Data pembayaran yang benar-benar diterima.
let transactions = [];

// Data tagihan yang belum lunas.
let bills = [];

// Data nota pembelian.
let receipts = [];


// ------------------------------------------------------
// 2.3. MENGAMBIL DATA BARANG
// ------------------------------------------------------

async function ambilBarangDatabase() {
    try {
        const hasil = await apiRequest(API.barang);

        const daftarBarang = Array.isArray(hasil)
            ? hasil
            : (Array.isArray(hasil.data) ? hasil.data : []);

        products = daftarBarang.map((barang) => ({
            id: Number(barang.id_barang),
            kode: barang.kode_barang,
            name: barang.nama_barang,
            stock: Number(barang.stok),
            hargaUmum: Number(barang.harga_jual_umum),
            hargaTokoMesin: Number(barang.harga_jual_toko_mesin),
            category: "Barang",
            image: barang.gambar || ""
        }));

        // Memperbarui tampilan katalog jika tersedia.
        if (typeof renderCatalog === "function") {
            renderCatalog();
        }

        // Memperbarui daftar barang pemilik.
        if (
            typeof renderAdminProducts === "function" &&
            currentUser &&
            isOwner()
        ) {
            renderAdminProducts();
        }

        return products;

    } catch (error) {
        console.error("Gagal mengambil data barang:", error);

        showToast("Gagal mengambil data barang dari database.");

        return [];
    }
}


// ------------------------------------------------------
// 2.4. MENGAMBIL DATA TRANSAKSI DAN TAGIHAN
// ------------------------------------------------------

async function ambilDataTransaksi() {
    try {
        const hasil = await apiRequest(
            `${API.transaksi}/ringkasan`
        );

        transactions = Array.isArray(hasil.transaksi)
            ? hasil.transaksi
            : [];

        bills = Array.isArray(hasil.tagihan)
            ? hasil.tagihan
            : [];

        return hasil;

    } catch (error) {
        console.error("Gagal mengambil data transaksi:", error);

        transactions = [];
        bills = [];
        
        return null;
    }
}


// ------------------------------------------------------
// 2.5. MENGAMBIL DATA NOTA PEMBELIAN
// ------------------------------------------------------
// Mengambil data Nota Pembelian dari Laravel.

async function ambilDataNota() {

    try {

        const hasil = await apiRequest(API.nota);

        receipts = (hasil.data || []).map((nota) => ({
            id: nota.id_nota,
            supplier: nota.nama_pemasok,
            date: nota.tanggal_nota,
            image: nota.lokasi_file
                ? `http://127.0.0.1:8000/${nota.lokasi_file}`
                : "",
            description: nota.keterangan
        }));

        return hasil;

    } catch (error) {

        console.error("Gagal mengambil data Nota:", error);

        receipts = [];

        return null;
    }
}

// ======================================================
// BAGIAN 3. VARIABEL APLIKASI DAN HAK AKSES
// ======================================================


// ------------------------------------------------------
// 3.1. VARIABEL TRANSAKSI
// ------------------------------------------------------

// Jenis transaksi yang sedang digunakan.
let activeTransactionType = "Umum";

// Tanggal transaksi yang dipilih.
let selectedTransactionDate = getToday();

// Daftar barang dalam keranjang.
let cart = [];

// Metode pembayaran yang dipilih.
let selectedPayment = "";

// Kata kunci pencarian barang.
let searchKeyword = "";

let selectedBillPaymentMethod = "";


// ------------------------------------------------------
// 3.2. VARIABEL PENGELOLAAN DATA
// ------------------------------------------------------

// ID barang yang sedang diedit.
let activeProductId = null;

// ID tagihan yang sedang dibuka.
let activeBillId = null;

// Daftar barang yang dipilih untuk pembayaran tagihan.
let selectedBillItems = [];


// ------------------------------------------------------
// 3.3. VARIABEL PENGGUNA
// ------------------------------------------------------

// Data pengguna yang sedang login.
let currentUser = JSON.parse(localStorage.getItem("currentUser")) || null;

// Status login pengguna.
let isAdminLoggedIn = false;


// ------------------------------------------------------
// 3.4. PEMERIKSAAN ROLE PENGGUNA
// ------------------------------------------------------

// Memeriksa apakah pengguna adalah pemilik.
function isOwner() {
    return currentUser?.role === "owner";
}


// Memeriksa apakah pengguna adalah kasir.
function isCashier() {
    return currentUser?.role === "cashier";
}


// Memastikan pengguna sudah login.
function pastikanLogin() {
    if (!currentUser) {
        showToast("Silakan login terlebih dahulu.");
        return false;
    }

    return true;
}


// Memastikan pengguna memiliki hak akses pemilik.
function pastikanPemilik() {
    if (!pastikanLogin()) {
        return false;
    }

    if (!isOwner()) {
        showToast("Menu ini hanya dapat diakses pemilik.");
        return false;
    }

    return true;
}


// ------------------------------------------------------
// 3.5. DAFTAR ELEMEN KHUSUS PEMILIK
// ------------------------------------------------------

const ownerOnlyElements = [
    "updateProductButtonAdmin",
    "generalReportButtonAdmin",
    "machineReportButtonAdmin"
];


// Memperbarui tampilan berdasarkan role pengguna.
function perbaruiHakAkses() {

    // Menampilkan atau menyembunyikan tombol khusus pemilik.
    ownerOnlyElements.forEach((id) => {
        const element = $(id);

        if (element) {
            element.classList.toggle(
                "hidden",
                !isOwner()
            );
        }
    });

    // Menampilkan informasi pengguna yang sedang login.
    const userLabel = $("loggedInUser");

    if (userLabel) {
        if (currentUser) {
            const namaRole = isOwner()
                ? "Pemilik"
                : "Kasir";

            userLabel.textContent =
                `Login sebagai: ${currentUser.name} (${namaRole})`;
        } else {
            userLabel.textContent = "";
        }
    }
}

// ======================================================
// BAGIAN 4. NAVIGASI HALAMAN
// ======================================================


// ------------------------------------------------------
// 4.1. DAFTAR SELURUH HALAMAN
// ------------------------------------------------------

const screens = [
    "adminLoginScreen",

    "homeScreenKasir",
    "homeScreenAdmin",

    "catalogScreen",
    "cartScreen",

    "machineMenuScreen",
    "billListScreen",
    "billDetailScreen",

    "successScreen",

    "reportScreen",
    "machineReportScreen",

    "updateMenuScreen",
    "productAdminScreen",
    "tambahBarangScreen",

    "dataNotaScreen",
    "notaPembelianScreen"
];


// ------------------------------------------------------
// 4.2. DAFTAR HALAMAN KHUSUS PEMILIK
// ------------------------------------------------------

const halamanKhususPemilik = [
    "homeScreenAdmin",
    "reportScreen",
    "machineReportScreen",
    "updateMenuScreen",
    "productAdminScreen",
    "tambahBarangScreen",
    "dataNotaScreen",
    "notaPembelianScreen"
];


// ------------------------------------------------------
// 4.3. MENAMPILKAN HALAMAN
// ------------------------------------------------------

function showScreen(screenId) {

    // Memastikan halaman yang dituju tersedia.
    if (!screens.includes(screenId)) {
        console.error("Halaman tidak terdaftar:", screenId);
        return;
    }

    // Semua halaman selain login wajib memiliki pengguna.
    if (screenId !== "adminLoginScreen" && !pastikanLogin()) {
        showScreen("adminLoginScreen");
        return;
    }

    // Halaman khusus pemilik tidak boleh dibuka kasir.
    if (
        halamanKhususPemilik.includes(screenId) &&
        !isOwner()
    ) {
        showToast("Halaman ini hanya dapat diakses pemilik.");

        showScreen(
            isCashier()
                ? "homeScreenKasir"
                : "adminLoginScreen"
        );

        return;
    }

    // Halaman beranda kasir hanya untuk role kasir.
    if (screenId === "homeScreenKasir" && !isCashier()) {
        showScreen(
            isOwner()
                ? "homeScreenAdmin"
                : "adminLoginScreen"
        );

        return;
    }

    // Sembunyikan semua halaman.
    screens.forEach((id) => {
        const element = $(id);

        if (element) {
            element.classList.add("hidden");
            element.style.display = "none";
        }
    });

    // Tampilkan halaman yang dipilih.
    const target = $(screenId);

    if (!target) {
        console.error("Elemen halaman tidak ditemukan:", screenId);
        return;
    }

    target.classList.remove("hidden");
    target.style.display = "";

    // Menampilkan tombol kembali kecuali di halaman utama dan login.
    const backButton = $("backButton");

    if (backButton) {
        const halamanUtama = [
            "adminLoginScreen",
            "homeScreenKasir",
            "homeScreenAdmin"
        ];

        backButton.classList.toggle(
            "hidden",
            halamanUtama.includes(screenId)
        );
    }

    // Memperbarui tampilan hak akses.
    perbaruiHakAkses();

    // Menjalankan fungsi sesuai halaman.
    switch (screenId) {

        case "homeScreenKasir":
        case "homeScreenAdmin":
            if (typeof renderHome === "function") {
                renderHome();
            }
            break;

        case "catalogScreen":
            if (typeof renderCatalog === "function") {
                renderCatalog();
            }
            break;

        case "cartScreen":
            if (typeof renderCart === "function") {
                renderCart();
            }
            break;

        case "billListScreen":
            if (typeof renderBillst === "function") {
                renderBillst();
            }
            break;

        case "billDetailScreen":
            if (typeof renderBillDetail === "function") {
                renderBillDetail();
            }
            break;

        case "reportScreen":
            if (typeof renderGeneralReport === "function") {
                renderGeneralReport();
            }
            break;

        case "machineReportScreen":
            if (typeof renderMachineReport === "function") {
                renderMachineReport();
            }
            break;

        case "productAdminScreen":
            if (typeof renderAdminProducts === "function") {
                renderAdminProducts();
            }
            break;

        case "dataNotaScreen":
            if (typeof renderReceipts === "function") {
                renderReceipts();
            }
            break;
    }
}


// ------------------------------------------------------
// 4.4. MENENTUKAN HALAMAN BERANDA PENGGUNA
// ------------------------------------------------------

function getHomeScreen() {
    return isOwner()
        ? "homeScreenAdmin"
        : "homeScreenKasir";
}


// ------------------------------------------------------
// 4.5. NAVIGASI TOMBOL KEMBALI
// ------------------------------------------------------

function goBack() {

    // Mencari halaman yang sedang aktif.
    const currentScreen = screens.find((id) => {
        const element = $(id);

        return element &&
            !element.classList.contains("hidden") &&
            element.style.display !== "none";
    });

    // Menentukan halaman tujuan tombol kembali.
    const routes = {

        catalogScreen:
            activeTransactionType === "Toko Mesin"
                ? "machineMenuScreen"
                : getHomeScreen(),

        cartScreen:
            "catalogScreen",

        machineMenuScreen:
            getHomeScreen(),

        billListScreen:
            "machineMenuScreen",

        billDetailScreen:
            "billListScreen",

        successScreen:
            getHomeScreen(),

        reportScreen:
            "homeScreenAdmin",

        machineReportScreen:
            "homeScreenAdmin",

        updateMenuScreen:
            "homeScreenAdmin",

        productAdminScreen:
            "updateMenuScreen",

        tambahBarangScreen:
            "productAdminScreen",

        dataNotaScreen:
            "updateMenuScreen",

        notaPembelianScreen:
            "updateMenuScreen"
    };

    const tujuan = routes[currentScreen] || getHomeScreen();

    showScreen(tujuan);
}

// ======================================================
// BAGIAN 5. BERANDA DAN GRAFIK PEMASUKAN
// ======================================================


// ------------------------------------------------------
// 5.1. MENGHITUNG PEMASUKAN
// ------------------------------------------------------

// Menghitung jumlah pembayaran yang benar-benar diterima.
function hitungPemasukan(data) {
    return data.reduce((total, pembayaran) => {
        return total + Number(
            pembayaran.jumlah_bayar ??
            pembayaran.jumlahBayar ??
            pembayaran.total ??
            0
        );
    }, 0);
}


// ------------------------------------------------------
// 5.2. MENGAMBIL TANGGAL PEMBAYARAN
// ------------------------------------------------------

function ambilTanggalPembayaran(pembayaran) {
    const tanggal =
        pembayaran.tanggal_pembayaran ??
        pembayaran.tanggalPembayaran ??
        pembayaran.date ??
        "";

    return String(tanggal).substring(0, 10);
}


// ------------------------------------------------------
// 5.3. MENENTUKAN JENIS TRANSAKSI
// ------------------------------------------------------

function ambilJenisTransaksi(pembayaran) {
    const jenis =
        pembayaran.jenis_transaksi ??
        pembayaran.jenisTransaksi ??
        pembayaran.type ??
        "";

    if (jenis === "umum" || jenis === "Umum") {
        return "Umum";
    }

    if (
        jenis === "toko_mesin" ||
        jenis === "Toko Mesin"
    ) {
        return "Toko Mesin";
    }

    return "";
}


// ------------------------------------------------------
// 5.4. FORMAT ANGKA GRAFIK
// ------------------------------------------------------

function formatAngkaGrafik(number) {
    if (number >= 1000000) {
        return (number / 1000000).toFixed(1) + " jt";
    }

    if (number >= 1000) {
        return (number / 1000).toFixed(0) + " rb";
    }

    return String(number);
}


// ------------------------------------------------------
// 5.5. MENGAMBIL DATA PEMASUKAN MINGGUAN
// ------------------------------------------------------

function ambilDataMingguan() {

    const tanggalDipilih = new Date(
        selectedTransactionDate + "T00:00:00"
    );

    // Menentukan hari Senin pada minggu yang dipilih.
    const hari = tanggalDipilih.getDay();

    const selisihSenin = hari === 0
        ? 6
        : hari - 1;

    const senin = new Date(tanggalDipilih);

    senin.setDate(
        tanggalDipilih.getDate() - selisihSenin
    );

    const namaHari = [
        "Senin",
        "Selasa",
        "Rabu",
        "Kamis",
        "Jumat",
        "Sabtu",
        "Minggu"
    ];

    return namaHari.map((nama, index) => {

        const tanggal = new Date(senin);

        tanggal.setDate(
            senin.getDate() + index
        );

        const tanggalString = [
            tanggal.getFullYear(),
            String(tanggal.getMonth() + 1).padStart(2, "0"),
            String(tanggal.getDate()).padStart(2, "0")
        ].join("-");

        // Mengambil pembayaran pada tanggal tersebut.
        const pembayaranHari = transactions.filter((p) => {
            return ambilTanggalPembayaran(p) === tanggalString;
        });

        return {
            nama: nama,

            umum: hitungPemasukan(
                pembayaranHari.filter((p) => {
                    return ambilJenisTransaksi(p) === "Umum";
                })
            ),

            mesin: hitungPemasukan(
                pembayaranHari.filter((p) => {
                    return ambilJenisTransaksi(p) === "Toko Mesin";
                })
            )
        };
    });
}


// ------------------------------------------------------
// 5.6. MENGGAMBAR GRAFIK
// ------------------------------------------------------

function drawChart(canvasId) {

    const canvas = $(canvasId);

    if (!canvas) return;

    const context = canvas.getContext("2d");

    if (!context) return;

    const dataMinggu = ambilDataMingguan();

    const width = canvas.clientWidth || 300;
    const height = canvas.clientHeight || 220;

    const ratio = window.devicePixelRatio || 1;

    canvas.width = width * ratio;
    canvas.height = height * ratio;

    context.setTransform(
        ratio,
        0,
        0,
        ratio,
        0,
        0
    );

    context.clearRect(0, 0, width, height);


    // Menentukan nilai pemasukan terbesar.
    const nilaiMaksimal = Math.max(
        ...dataMinggu.map((data) => {
            return Math.max(data.umum, data.mesin);
        }),
        1
    );


    // Area grafik.
    const chartTop = 30;
    const chartBottom = height - 40;
    const chartHeight = chartBottom - chartTop;

    const jarakKelompok = width / 7;

    const lebarBatang = Math.min(
        15,
        jarakKelompok * 0.25
    );


    // Menggambar garis latar.
    context.strokeStyle = "#e8ebf2";
    context.lineWidth = 1;

    for (let i = 0; i <= 3; i++) {

        const y =
            chartTop + (chartHeight / 3) * i;

        context.beginPath();
        context.moveTo(8, y);
        context.lineTo(width - 8, y);
        context.stroke();
    }


    // Menggambar batang pemasukan.
    dataMinggu.forEach((data, index) => {

        const tengah =
            jarakKelompok * index +
            jarakKelompok / 2;

        const tinggiUmum =
            (data.umum / nilaiMaksimal) * chartHeight;

        const tinggiMesin =
            (data.mesin / nilaiMaksimal) * chartHeight;


        // Pemasukan transaksi umum.
        context.fillStyle = "#4054bd";

        context.fillRect(
            tengah - lebarBatang - 1,
            chartBottom - tinggiUmum,
            lebarBatang,
            tinggiUmum
        );


        // Pemasukan transaksi Toko Mesin.
        context.fillStyle = "#79add4";

        context.fillRect(
            tengah + 1,
            chartBottom - tinggiMesin,
            lebarBatang,
            tinggiMesin
        );


        // Nama hari.
        context.fillStyle = "#8490a5";
        context.font = "10px Arial";
        context.textAlign = "center";

        context.fillText(
            data.nama,
            tengah,
            height - 12
        );
    });
}


// ------------------------------------------------------
// 5.7. MENAMPILKAN BERANDA
// ------------------------------------------------------

function renderHome() {

    // Memilih elemen berdasarkan role pengguna.
    const isPemilik = isOwner();

    const dateTextId = isPemilik
        ? "homeDateTextAdmin"
        : "homeDateTextKasir";

    const datePickerId = isPemilik
        ? "datePickerAdmin"
        : "datePickerKasir";

    const chartId = isPemilik
        ? "salesChartAdmin"
        : "salesChartKasir";


    // Menampilkan tanggal.
    if ($(dateTextId)) {
        $(dateTextId).textContent =
            formatTanggal(selectedTransactionDate);
    }

    // Mengisi tanggal pada date picker.
    if ($(datePickerId)) {
        $(datePickerId).value =
            selectedTransactionDate;
    }

    // Menggambar grafik sesuai halaman.
    drawChart(chartId);
}

// ======================================================
// BAGIAN 6. KATALOG BARANG
// ======================================================


// ------------------------------------------------------
// 6.1. MENGAMBIL HARGA BARANG
// ------------------------------------------------------

// Harga mengikuti jenis transaksi yang sedang berlangsung.
function getProductPrice(product) {

    return activeTransactionType === "Toko Mesin"
        ? Number(product.hargaTokoMesin || 0)
        : Number(product.hargaUmum || 0);
}


// ------------------------------------------------------
// 6.2. MENGATUR TEMA TRANSAKSI
// ------------------------------------------------------

function setTransactionTheme(type) {

    activeTransactionType = type;

    // Mengubah tema halaman.
    document.body.classList.toggle(
        "machine-theme",
        type === "Toko Mesin"
    );


    // Mengubah judul katalog.
    if ($("catalogTitle")) {
        $("catalogTitle").textContent =
            type === "Toko Mesin"
                ? "Toko Mesin"
                : "Transaksi Umum";
    }


    // Mengubah judul keranjang.
    if ($("cartTitle")) {
        $("cartTitle").textContent =
            type === "Toko Mesin"
                ? "Keranjang Toko Mesin"
                : "Keranjang";
    }


    // Utang hanya tersedia untuk Toko Mesin.
    if ($("debtPaymentButton")) {
        $("debtPaymentButton").classList.toggle(
            "hidden",
            type !== "Toko Mesin"
        );
    }


    // Menghapus pilihan pembayaran sebelumnya.
    selectedPayment = "";

    // Memperbarui tampilan keranjang.
    renderCart();
}


// ------------------------------------------------------
// 6.3. MENAMPILKAN KATALOG BARANG
// ------------------------------------------------------

function renderCatalog() {

    const container = $("productGrid");

    if (!container) return;


    // Memfilter barang berdasarkan kata kunci.
    const filteredProducts = products.filter((product) => {

        return String(product.name || "")
            .toLowerCase()
            .includes(searchKeyword.toLowerCase());

    });


    // Jika barang tidak ditemukan.
    if (filteredProducts.length === 0) {

        container.innerHTML =
            "<p>Barang tidak ditemukan.</p>";

        return;
    }


    // Menampilkan kartu barang.
    container.innerHTML = filteredProducts.map((product) => {

        const harga = getProductPrice(product);

        const cartItem = cart.find((item) => {
            return Number(item.productId) === Number(product.id);
        });

        const jumlah = cartItem
            ? Number(cartItem.qty)
            : 0;

        return `
            <div class="product-card">

                ${
                    product.image
                        ? `
                            <img
                                src="${escapeHTML(product.image)}"
                                class="product-image"
                                alt="${escapeHTML(product.name)}"
                            >
                        `
                        : `
                            <div class="product-art">
                                📦
                            </div>
                        `
                }

                <h3>
                    ${escapeHTML(product.name)}
                </h3>

                <p>
                    ${rupiah(harga)}
                </p>

                <small>
                    Stok: ${Number(product.stock)}
                </small>

                <div class="quick-quantity">

                    ${
                        jumlah > 0
                            ? `
                                <button
                                    data-minus="${product.id}"
                                >
                                    −
                                </button>

                                <span>
                                    ${jumlah}
                                </span>

                                <button
                                    data-plus="${product.id}"
                                    ${jumlah >= Number(product.stock) ? "disabled" : ""}
                                >
                                    +
                                </button>
                            `
                            : `
                                <button
                                    data-add="${product.id}"
                                    ${Number(product.stock) <= 0 ? "disabled" : ""}
                                >
                                    ${
                                        Number(product.stock) <= 0
                                            ? "Stok Habis"
                                            : "+"
                                    }
                                </button>
                            `
                    }

                </div>

            </div>
        `;

    }).join("");


    // Mengaktifkan tombol tambah barang.
    container.querySelectorAll("[data-add]").forEach((button) => {

        button.addEventListener("click", () => {

            addToCart(Number(button.dataset.add));

        });

    });


    // Mengaktifkan tombol tambah jumlah.
    container.querySelectorAll("[data-plus]").forEach((button) => {

        button.addEventListener("click", () => {

            ubahJumlahBarang(
                Number(button.dataset.plus),
                1
            );

        });

    });


    // Mengaktifkan tombol kurangi jumlah.
    container.querySelectorAll("[data-minus]").forEach((button) => {

        button.addEventListener("click", () => {

            ubahJumlahBarang(
                Number(button.dataset.minus),
                -1
            );

        });

    });
}


// ------------------------------------------------------
// 6.4. MENAMBAHKAN BARANG KE KERANJANG
// ------------------------------------------------------

function addToCart(productId) {

    const product = products.find((item) => {
        return Number(item.id) === Number(productId);
    });

    if (!product) {
        showToast("Barang tidak ditemukan.");
        return;
    }


    const cartItem = cart.find((item) => {
        return Number(item.productId) === Number(productId);
    });

    const jumlahDiKeranjang = cartItem
        ? Number(cartItem.qty)
        : 0;


    // Memeriksa ketersediaan stok.
    if (jumlahDiKeranjang >= Number(product.stock)) {

        showToast("Stok barang tidak mencukupi.");

        return;
    }


    // Jika barang sudah ada, jumlahnya ditambah.
    if (cartItem) {

        cartItem.qty++;

    } else {

        // Jika belum ada, masukkan barang baru.
        cart.push({
            productId: Number(product.id),
            name: product.name,
            qty: 1,
            price: getProductPrice(product)
        });

    }


    showToast("Barang ditambahkan.");

    renderCatalog();
    renderCart();
}


// ------------------------------------------------------
// 6.5. MENGUBAH JUMLAH BARANG DARI KATALOG
// ------------------------------------------------------

function ubahJumlahBarang(productId, perubahan) {

    const product = products.find((item) => {
        return Number(item.id) === Number(productId);
    });

    const cartItem = cart.find((item) => {
        return Number(item.productId) === Number(productId);
    });

    if (!product || !cartItem) return;


    const jumlahBaru =
        Number(cartItem.qty) + Number(perubahan);


    // Memeriksa stok barang.
    if (jumlahBaru > Number(product.stock)) {

        showToast("Stok barang tidak mencukupi.");

        return;
    }


    // Menghapus barang jika jumlahnya nol.
    if (jumlahBaru <= 0) {

        cart = cart.filter((item) => {
            return Number(item.productId) !== Number(productId);
        });

    } else {

        cartItem.qty = jumlahBaru;

    }


    renderCatalog();
    renderCart();
}

// ======================================================
// BAGIAN 7. KERANJANG DAN PEMBAYARAN
// ======================================================


// ------------------------------------------------------
// 7.1. MENGHITUNG TOTAL BELANJA
// ------------------------------------------------------

function getCartTotal() {

    return cart.reduce((total, item) => {

        return total +
            Number(item.qty) * Number(item.price);

    }, 0);
}


// ------------------------------------------------------
// 7.2. MENGHITUNG JUMLAH BARANG
// ------------------------------------------------------

function getCartCount() {

    return cart.reduce((total, item) => {

        return total + Number(item.qty);

    }, 0);
}


// ------------------------------------------------------
// 7.3. MENAMPILKAN KERANJANG
// ------------------------------------------------------

function renderCart() {

    const container = $("cartItems");

    if (!container) return;


    // Jika keranjang kosong.
    if (cart.length === 0) {

        container.innerHTML =
            "<p>Keranjang masih kosong.</p>";

    } else {

        // Menampilkan seluruh barang dalam keranjang.
        container.innerHTML = cart.map((item, index) => {

            return `
                <div class="cart-item">

                    <div>

                        <strong>
                            ${escapeHTML(item.name)}
                        </strong>

                        <small>
                            ${rupiah(item.price)} x ${item.qty}
                        </small>

                        <p>
                            ${rupiah(item.price * item.qty)}
                        </p>

                    </div>

                    <div class="cart-actions">

                        <button data-minus="${index}">
                            −
                        </button>

                        <span>
                            ${item.qty}
                        </span>

                        <button data-plus="${index}">
                            +
                        </button>

                        <button data-remove="${index}">
                            Hapus
                        </button>

                    </div>

                </div>
            `;

        }).join("");

    }


    // Menampilkan total belanja.
    if ($("cartTotal")) {
        $("cartTotal").textContent =
            rupiah(getCartTotal());
    }


    // Menampilkan jumlah seluruh barang.
    if ($("cartCount")) {
        $("cartCount").textContent =
            getCartCount();
    }


    // Mengaktifkan tombol kurangi jumlah.
    container.querySelectorAll("[data-minus]").forEach((button) => {

        button.addEventListener("click", () => {

            changeCartQuantity(
                Number(button.dataset.minus),
                -1
            );

        });

    });


    // Mengaktifkan tombol tambah jumlah.
    container.querySelectorAll("[data-plus]").forEach((button) => {

        button.addEventListener("click", () => {

            changeCartQuantity(
                Number(button.dataset.plus),
                1
            );

        });

    });


    // Mengaktifkan tombol hapus barang.
    container.querySelectorAll("[data-remove]").forEach((button) => {

        button.addEventListener("click", () => {

            cart.splice(
                Number(button.dataset.remove),
                1
            );

            renderCart();

        });

    });


    // Menandai metode pembayaran yang dipilih.
    document.querySelectorAll("[data-payment]").forEach((button) => {

        button.classList.toggle(
            "selected",
            button.dataset.payment === selectedPayment
        );

    });


    // Utang hanya tersedia untuk Toko Mesin.
    if ($("debtPaymentButton")) {

        $("debtPaymentButton").classList.toggle(
            "hidden",
            activeTransactionType !== "Toko Mesin"
        );

    }


    // Mengatur tombol selesaikan transaksi.
    if ($("finishButton")) {

        $("finishButton").disabled =
            cart.length === 0 ||
            !selectedPayment;

    }
}


// ------------------------------------------------------
// 7.4. MENGUBAH JUMLAH BARANG DI KERANJANG
// ------------------------------------------------------

function changeCartQuantity(index, perubahan) {

    const item = cart[index];

    if (!item) return;


    const product = products.find((barang) => {
        return Number(barang.id) === Number(item.productId);
    });

    if (!product) return;


    const jumlahBaru =
        Number(item.qty) + Number(perubahan);


    // Menghapus barang jika jumlahnya nol.
    if (jumlahBaru <= 0) {

        cart.splice(index, 1);

    } else if (jumlahBaru > Number(product.stock)) {

        showToast("Stok barang tidak mencukupi.");

        return;

    } else {

        item.qty = jumlahBaru;

    }


    renderCart();
    renderCatalog();
}


// ------------------------------------------------------
// 7.5. MEMBUAT DATA BARANG UNTUK TRANSAKSI
// ------------------------------------------------------

function buatItemTransaksi() {

    return cart.map((item) => ({

        id_barang: Number(item.productId),

        jumlah: Number(item.qty)

    }));

}


// ------------------------------------------------------
// 7.6. MEMERIKSA KETERSEDIAAN STOK
// ------------------------------------------------------

function cekStokKeranjang() {

    return cart.every((item) => {

        const product = products.find((barang) => {
            return Number(barang.id) === Number(item.productId);
        });

        return product &&
            Number(product.stock) >= Number(item.qty);

    });

}


// ------------------------------------------------------
// 7.7. MENYIMPAN TRANSAKSI KE DATABASE
// ------------------------------------------------------

async function completeTransaction() {

    // Memeriksa isi keranjang.
    if (cart.length === 0) {

        showToast("Keranjang masih kosong.");

        return;
    }


    // Memeriksa metode pembayaran.
    if (!selectedPayment) {

        showToast("Pilih metode pembayaran terlebih dahulu.");

        return;
    }


    // Utang hanya boleh digunakan untuk Toko Mesin.
    if (
        selectedPayment === "Utang" &&
        activeTransactionType !== "Toko Mesin"
    ) {

        showToast(
            "Pembayaran utang hanya tersedia di Toko Mesin."
        );

        return;
    }


    // Memeriksa data pengguna.
    if (!currentUser || !currentUser.id_user) {

        showToast(
            "Data pengguna tidak ditemukan. Silakan login kembali."
        );

        return;
    }


    // Memeriksa stok sebelum transaksi.
    if (!cekStokKeranjang()) {

        showToast("Ada barang yang stoknya tidak mencukupi.");

        await ambilBarangDatabase();

        return;
    }


    // Mengubah jenis transaksi ke format database.
    const jenisTransaksi =
        activeTransactionType === "Toko Mesin"
            ? "toko_mesin"
            : "umum";


    // Mengubah metode pembayaran ke format database.
    const metodePembayaran = {
        "Tunai": "tunai",
        "Transfer": "transfer",
        "Utang": "utang"
    }[selectedPayment];


    // Menyiapkan data transaksi.
    const dataTransaksi = {

        id_user: Number(currentUser.id_user),

        tanggal_transaksi:
            selectedTransactionDate + " 00:00:00",

        jenis_transaksi: jenisTransaksi,

        metode_pembayaran: metodePembayaran,

        items: buatItemTransaksi()

    };


    const finishButton = $("finishButton");

    if (finishButton) {
        finishButton.disabled = true;
    }


    try {

        // Mengirim transaksi ke database.
        // Mengirim transaksi ke database.
        await apiRequest(
            API.transaksi,
            jsonOptions("POST", dataTransaksi)
        );


        // Memperbarui data barang dan transaksi.
        await ambilBarangDatabase();
        await ambilDataTransaksi();


        // Mengosongkan keranjang setelah berhasil.
        cart = [];
        selectedPayment = "";


        // Menampilkan halaman transaksi berhasil.
        showScreen("successScreen");


    } catch (error) {

        console.error(
            "Gagal menyimpan transaksi:",
            error
        );

        showToast(
            error.message || "Transaksi gagal disimpan."
        );

    } finally {

        if (finishButton) {

            finishButton.disabled =
                cart.length === 0 ||
                !selectedPayment;

        }

    }
}

// ======================================================
// BAGIAN 8. TAGIHAN DAN CICILAN
// ======================================================


// ------------------------------------------------------
// 8.1. MENAMPILKAN DAFTAR TAGIHAN
// ------------------------------------------------------

function renderBillList() {

    const container = $("billList");

    if (!container) return;

    const countElement = $("billCount");

    if (countElement) {
        countElement.textContent = bills.length;
    }
    
    if (bills.length === 0) {

        container.innerHTML =
            "<p>Tidak ada tagihan yang belum lunas.</p>";

        return;
    }

    container.innerHTML = bills.map((bill) => {

        const totalTagihan = (bill.remainingItems || [])
            .reduce((total, item) => {
                return total + Number(item.subtotal || 0);
            }, 0);

        return `
            <div class="bill-card">

                <div>
                    <h3>Tagihan #${escapeHTML(bill.id)}</h3>

                    <p>
                        Tanggal: ${formatTanggal(bill.date)}
                    </p>

                    <p>
                        Sisa tagihan: ${rupiah(totalTagihan)}
                    </p>
                </div>

                <button data-open-bill="${escapeHTML(bill.id)}">
                    Lihat Detail
                </button>

            </div>
        `;

    }).join("");

    container.querySelectorAll("[data-open-bill]").forEach((button) => {

        button.addEventListener("click", () => {

            openBillDetail(button.dataset.openBill);

        });

    });
}


// ------------------------------------------------------
// 8.2. MEMBUKA DETAIL TAGIHAN
// ------------------------------------------------------

function openBillDetail(billId) {

    const bill = bills.find((item) => {
        return String(item.id) === String(billId);
    });

    if (!bill) {

        showToast("Data tagihan tidak ditemukan.");

        return;
    }

    activeBillId = bill.id;

    selectedBillItems = [];

    renderBillDetail();

    showScreen("billDetailScreen");
}


// ------------------------------------------------------
// 8.3. MENAMPILKAN DETAIL TAGIHAN
// ------------------------------------------------------

function renderBillDetail() {

    const bill = bills.find((item) => {
        return String(item.id) === String(activeBillId);
    });

    const container = $("billDetail");

    if (!bill || !container) return;

    const items = bill.remainingItems || [];

    if (items.length === 0) {

        container.innerHTML =
            "<p>Tagihan ini sudah lunas.</p>";

        return;
    }

    container.innerHTML = items.map((item) => {

        const idDetail = Number(item.id);

        const checked = selectedBillItems.includes(idDetail);

        return `
            <div class="bill-detail-item">

                <label>
                    <input
                        type="checkbox"
                        data-bill-item="${idDetail}"
                        ${checked ? "checked" : ""}
                    >

                    <span>
                        ${escapeHTML(item.name)}
                    </span>
                </label>

                <p>
                    Jumlah: ${Number(item.qty)}
                </p>

                <p>
                    Sisa pembayaran: ${rupiah(item.subtotal)}
                </p>

            </div>
        `;

    }).join("");


    container.querySelectorAll("[data-bill-item]").forEach((checkbox) => {

        checkbox.addEventListener("change", () => {

            const id = Number(checkbox.dataset.billItem);

            if (checkbox.checked) {

                if (!selectedBillItems.includes(id)) {
                    selectedBillItems.push(id);
                }

            } else {

                selectedBillItems =
                    selectedBillItems.filter((itemId) => {
                        return itemId !== id;
                    });

            }

            renderBillPaymentTotal();

        });

    });

    renderBillPaymentTotal();
}


// ------------------------------------------------------
// 8.4. MENGHITUNG TOTAL PEMBAYARAN CICILAN
// ------------------------------------------------------

function renderBillPaymentTotal() {

    const bill = bills.find((item) => {
        return String(item.id) === String(activeBillId);
    });

    if (!bill) return;

    const total = (bill.remainingItems || [])
        .filter((item) => {
            return selectedBillItems.includes(Number(item.id));
        })
        .reduce((sum, item) => {
            return sum + Number(item.subtotal || 0);
        }, 0);

    const totalElement = $("billPaymentTotal");

    if (totalElement) {
        totalElement.textContent = rupiah(total);
    }

    const paymentButton = $("billFinish");

    if (paymentButton) {
        paymentButton.disabled =
            selectedBillItems.length === 0 ||
            selectedBillPaymentMethod === "";
    }
}

// ------------------------------------------------------
// 8.5. MENYIMPAN PEMBAYARAN CICILAN
// ------------------------------------------------------

async function paySelectedBill() {

    const bill = bills.find((item) => {
        return String(item.id) === String(activeBillId);
    });

    if (!bill) {
        showToast("Tagihan tidak ditemukan.");
        return;
    }

    if (selectedBillItems.length === 0) {
        showToast("Pilih barang yang akan dibayar.");
        return;
    }

    const paymentMethod = selectedBillPaymentMethod;

    if (!["tunai", "transfer"].includes(paymentMethod)) {
        showToast("Pilih metode pembayaran.");
        return;
    }

    const paymentButton = $("billFinish");

    const detailPembayaran = (bill.remainingItems || [])
        .filter((item) => {
            return selectedBillItems.includes(Number(item.id));
        })
        .map((item) => ({
            id_detail_transaksi: Number(item.id),
            jumlah_dibayar: Number(item.subtotal)
        }));

    if (paymentButton) {
        paymentButton.disabled = true;
    }

    try {

        await apiRequest(
            `${API.transaksi}/pay-bill`,
            jsonOptions("POST", {
                id_transaksi: Number(bill.id),
                metode_pembayaran: paymentMethod,
                detail: detailPembayaran
            })
        );

        await ambilDataTransaksi();

        selectedBillItems = [];
        selectedBillPaymentMethod = "";

        showToast("Pembayaran tagihan berhasil.");

        showScreen("billListScreen");

        renderBillList();

    } catch (error) {

        console.error("Gagal membayar tagihan:", error);

        showToast(
            error.message || "Pembayaran tagihan gagal."
        );

    } finally {

        if (paymentButton) {
            paymentButton.disabled = false;
        }

    }
}

// ======================================================
// BAGIAN 9. LAPORAN PENJUALAN
// ======================================================


// ------------------------------------------------------
// 9.1. MENGAMBIL DATA LAPORAN BERDASARKAN JENIS
// ------------------------------------------------------

function ambilDataLaporan(jenis) {
    return transactions.filter((pembayaran) => {
        return ambilJenisTransaksi(pembayaran) === jenis;
    });
}


// ------------------------------------------------------
// 9.2. MEMFILTER LAPORAN BERDASARKAN BULAN
// ------------------------------------------------------

function filterLaporanBulanan(data, bulanTahun) {
    return data.filter((pembayaran) => {
        const tanggal = ambilTanggalPembayaran(pembayaran);

        if (!tanggal) return false;

        return tanggal.substring(0, 7) === bulanTahun;
    });
}


// ------------------------------------------------------
// 9.3. MENAMPILKAN LAPORAN
// ------------------------------------------------------

// ------------------------------------------------------
// 9.3. MENAMPILKAN LAPORAN PER HARI
// ------------------------------------------------------

function renderReport(jenis) {
    const isMesin = jenis === "Toko Mesin";

    const bulanInput = isMesin
        ? $("machineReportMonth")
        : $("reportMonth");

    const container = isMesin
        ? $("machineTransactionList")
        : $("transactionList");

    const totalElement = isMesin
        ? $("machineReportTotal")
        : $("reportTotal");

    if (!container || !totalElement) return;

    const sekarang = new Date();

    const bulanTahun = bulanInput?.value ||
        `${sekarang.getFullYear()}-${String(
            sekarang.getMonth() + 1
        ).padStart(2, "0")}`;

    const dataLaporan = ambilDataLaporan(jenis);

    const dataBulanan = filterLaporanBulanan(
        dataLaporan,
        bulanTahun
    );

    const totalPemasukan = hitungPemasukan(dataBulanan);

    totalElement.textContent = rupiah(totalPemasukan);

    if (dataBulanan.length === 0) {
        container.innerHTML =
            "<p>Belum ada pembayaran pada periode ini.</p>";
        return;
    }

    // Mengelompokkan pembayaran berdasarkan tanggal.
    const kelompokTanggal = {};

    dataBulanan.forEach((pembayaran) => {
        const tanggal = ambilTanggalPembayaran(pembayaran);

        if (!kelompokTanggal[tanggal]) {
            kelompokTanggal[tanggal] = [];
        }

        kelompokTanggal[tanggal].push(pembayaran);
    });

    // Menampilkan kotak untuk setiap tanggal.
    container.innerHTML = Object.keys(kelompokTanggal)
        .sort((a, b) => b.localeCompare(a))
        .map((tanggal) => {
            const pembayaranHari = kelompokTanggal[tanggal];

            const totalHari = hitungPemasukan(pembayaranHari);

            return `
                <div class="report-day-card">
                    <div class="report-day-header">
                        <strong>${formatTanggal(tanggal)}</strong>
                        <strong>${rupiah(totalHari)}</strong>
                    </div>

                    <div class="report-day-details">
                        
                        ${pembayaranHari.map((pembayaran) => {
                            const jumlah = Number(
                                pembayaran.jumlah_bayar ??
                                pembayaran.jumlahBayar ??
                                pembayaran.total ??
                                0
                            );

                            let metode = pembayaran.metode_pembayaran || "-";

                            if (
                                isMesin &&
                                Number(pembayaran.pernah_menjadi_utang) === 1
                            ) {
                                metode += " (Pernah Menjadi Utang)";
                            }

                            return `
                                <div class="report-day-item">
                                    <span>${escapeHTML(metode)}</span>
                                    <strong>${rupiah(jumlah)}</strong>
                                </div>
                            `;
                        }).join("")}

                    </div>
                </div>
            `;
        }).join("");
}

// ------------------------------------------------------
// 9.4. MENAMPILKAN LAPORAN UMUM
// ------------------------------------------------------

function renderGeneralReport() {
    renderReport("Umum");
}


// ------------------------------------------------------
// 9.5. MENAMPILKAN LAPORAN TOKO MESIN
// ------------------------------------------------------

function renderMachineReport() {
    renderReport("Toko Mesin");
}


// ------------------------------------------------------
// 9.6. MEMPERBARUI LAPORAN
// ------------------------------------------------------

function refreshReports() {
    renderGeneralReport();
    renderMachineReport();
}


// ------------------------------------------------------
// 9.7. FILTER BULAN LAPORAN
// ------------------------------------------------------

$("reportMonth")?.addEventListener("change", () => {
    renderGeneralReport();
});

$("machineReportMonth")?.addEventListener("change", () => {
    renderMachineReport();
});

// ======================================================
// BAGIAN 10. LOGIN, PENGELOLAAN BARANG, NOTA PEMBELIAN,
// EVENT LISTENER, DAN INISIALISASI
// ======================================================


// ------------------------------------------------------
// 10.1. LOGIN PENGGUNA
// ------------------------------------------------------
async function loginUser() {

    const username = $("loginUsername")?.value.trim();
    const password = $("loginPassword")?.value;

    if (!username || !password) {
        showToast("Masukkan username dan password.");
        return;
    }

    const loginButton = $("loginButton");

    if (loginButton) loginButton.disabled = true;

    try {

        const hasil = await apiRequest(
            API.login,
            jsonOptions("POST", {
                username,
                password
            })
        );

        const account = hasil.user;

        if (!account) {
            throw new Error("Data pengguna tidak ditemukan.");
        }

        currentUser = {
            id_user: Number(account.id_user),
            username: account.username,
            name: account.nama,
            role: account.role
        };

        localStorage.setItem("currentUser", JSON.stringify(currentUser));

        isAdminLoggedIn = isOwner();

        if (!isOwner() && !isCashier()) {
            currentUser = null;
            throw new Error("Hak akses pengguna tidak dikenali.");
        }

        perbaruiHakAkses();

        // LANGSUNG MASUK BERANDA SETELAH LOGIN BERHASIL
        if (isOwner()) {
            showScreen("homeScreenAdmin");
        } else {
            showScreen("homeScreenKasir");
        }

        showToast("Login berhasil.");

        // Memuat data setelah berhasil masuk beranda.
        try {

            await ambilBarangDatabase();
            await ambilDataTransaksi();
            await ambilDataNota();

            renderHome();
            renderCatalog();

        } catch (error) {

            console.error("Gagal memuat data awal:", error);

            showToast("Berhasil login, tetapi ada data yang gagal dimuat.");

        }

    } catch (error) {

        console.error("Login gagal:", error);

        showToast(
            error.message || "Username atau password salah."
        );

    } finally {

        if (loginButton) {
            loginButton.disabled = false;
        }

    }

}

// ------------------------------------------------------
// 10.2. LOGOUT PENGGUNA
// ------------------------------------------------------

function logoutUser() {

    currentUser = null;

    localStorage.removeItem("currentUser");

    isAdminLoggedIn = false;

    cart = [];

    selectedPayment = "";

    selectedBillItems = [];

    activeBillId = null;

    searchKeyword = "";

    perbaruiHakAkses();

    showScreen("adminLoginScreen");

    showToast("Berhasil keluar dari akun.");

}


// ------------------------------------------------------
// 10.3. MENAMPILKAN DATA BARANG ADMIN
// ------------------------------------------------------

function renderAdminProducts() {

    const container = $("adminProductList");

    if (!container) return;

    if (!isOwner()) return;


    if (products.length === 0) {

        container.innerHTML =
            "<p>Belum ada data barang.</p>";

        return;
    }


    container.innerHTML = products.map((product) => {

        return `
            <div class="admin-product-item">

                <div>
                    ${
                        product.image
                        ? `
                            <img
                                src="${escapeHTML(product.image)}"
                                alt="${escapeHTML(product.name)}"
                                class="admin-product-image"
                            >
                        `
                        : `
                            <p>Foto belum tersedia</p>
                        `
                    }

                    <strong>
                        ${escapeHTML(product.name)}
                    </strong>

                    <p>
                        Kode: ${escapeHTML(product.kode || "-")}
                    </p>

                    <p>
                        Stok: ${Number(product.stock)}
                    </p>

                    <p>
                        Harga Umum: ${rupiah(product.hargaUmum)}
                    </p>

                    <p>
                        Harga Toko Mesin: ${rupiah(product.hargaTokoMesin)}
                    </p>
                </div>

                <div>
                    <button data-edit-product="${product.id}">
                        Edit
                    </button>

                    <button data-delete-product="${product.id}">
                        Hapus
                    </button>
                </div>

            </div>
        `;

}).join("");

    container.querySelectorAll("[data-edit-product]").forEach((button) => {

        button.addEventListener("click", () => {

            editProduct(Number(button.dataset.editProduct));

        });

    });


    container.querySelectorAll("[data-delete-product]").forEach((button) => {

        button.addEventListener("click", () => {

            deleteProduct(Number(button.dataset.deleteProduct));

        });

    });

}


// ------------------------------------------------------
// 10.4. MENGOSONGKAN FORM BARANG
// ------------------------------------------------------

function resetProductForm() {

    if ($("productId")) {
        $("productId").value = "";
    }

    if ($("productCode")) {
        $("productCode").value = "";
    }

    if ($("productName")) {
        $("productName").value = "";
    }

    if ($("productStock")) {
        $("productStock").value = "";
    }

    if ($("generalPrice")) {
        $("generalPrice").value = "";
    }

    if ($("machinePrice")) {
        $("machinePrice").value = "";
    }

    if ($("productImage")) {
        $("productImage").value = "";
    }

    if ($("productFormTitle")) {
        $("productFormTitle").textContent = "Tambah Barang";
    }

}


// ------------------------------------------------------
// 10.5. MENGISI FORM EDIT BARANG
// ------------------------------------------------------

function editProduct(productId) {

    if (!pastikanPemilik()) return;

    const product = products.find((item) => {
        return Number(item.id) === Number(productId);
    });

    if (!product) {

        showToast("Barang tidak ditemukan.");

        return;
    }


    if ($("productId")) {
        $("productId").value = product.id;
    }

    if ($("productCode")) {
        $("productCode").value = product.kode || "";
    }

    if ($("productName")) {
        $("productName").value = product.name;
    }

    if ($("productStock")) {
        $("productStock").value = product.stock;
    }

    if ($("generalPrice")) {
        $("generalPrice").value = product.hargaUmum;
    }

    if ($("machinePrice")) {
        $("machinePrice").value = product.hargaTokoMesin;
    }

    if ($("productFormTitle")) {
        $("productFormTitle").textContent = "Edit Barang";
    }

    showScreen("tambahBarangScreen");

}


// ------------------------------------------------------
// 10.6. MENYIMPAN BARANG BARU ATAU HASIL EDIT
// ------------------------------------------------------

async function saveProduct() {
    console.count("JUMLAH SAVE PRODUCT");
    
    if (!pastikanPemilik()) return;


    const id = $("productId")?.value;

    const kode = $("productCode")?.value.trim();

    const nama = $("productName")?.value.trim();

    const stok = $("productStock")?.value;

    const hargaUmum = $("generalPrice")?.value;

    const hargaMesin = $("machinePrice")?.value;

    const gambar = $("productImage")?.files[0];


    if (!nama || stok === "" || hargaUmum === "" || hargaMesin === "") {

        showToast("Lengkapi seluruh data barang.");

        return;
    }


    if (
        !Number.isInteger(Number(stok)) ||
        Number(stok) < 0 ||
        Number(hargaUmum) < 0 ||
        Number(hargaMesin) < 0
    ) {

        showToast("Periksa kembali stok dan harga barang.");

        return;
    }


    if (!id && !kode) {

        showToast("Kode barang wajib diisi.");

        return;
    }


    const formData = new FormData();

    formData.append("aksi", id ? "edit" : "tambah");

    formData.append("nama_barang", nama);

    formData.append("stok", stok);

    formData.append("harga_jual_umum", hargaUmum);

    formData.append("harga_jual_toko_mesin", hargaMesin);


    if (id) {

        formData.append("id_barang", id);

    } else {

        formData.append("kode_barang", kode);

    }


    if (gambar) {
        formData.append("gambar", gambar);
    }


    const saveButton = $("saveProductButton");

    if (saveButton) {
        saveButton.disabled = true;
    }


    try {

        await apiRequest(API.barang, {
            method: "POST",
            body: formData
        });


        await ambilBarangDatabase();

        resetProductForm();

        showToast(
            id
                ? "Barang berhasil diperbarui."
                : "Barang berhasil ditambahkan."
        );

        showScreen("updateMenuScreen");

        renderAdminProducts();

    } catch (error) {

        console.error("Gagal menyimpan barang:", error);

        showToast(
            error.message || "Data barang gagal disimpan."
        );

    } finally {

        if (saveButton) {
            saveButton.disabled = false;
        }

    }

}


// ------------------------------------------------------
// 10.7. MENGHAPUS BARANG
// ------------------------------------------------------

async function deleteProduct(productId) {

    if (!pastikanPemilik()) return;

    const product = products.find((item) => {
        return Number(item.id) === Number(productId);
    });

    if (!product) {

        showToast("Barang tidak ditemukan.");

        return;
    }


    const yakin = confirm(
        `Hapus barang "${product.name}"?`
    );

    if (!yakin) return;


    try {

        await apiRequest(API.barang, {
            method: "DELETE",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                id_barang: Number(productId)
            })
        });


        // Mengambil ulang data setelah penghapusan.
        await ambilBarangDatabase();

        showToast("Barang berhasil dihapus.");

    } catch (error) {

        console.error("Gagal menghapus barang:", error);

        showToast(
            error.message || "Barang gagal dihapus."
        );

    }

}


// ------------------------------------------------------
// 10.8. MENAMPILKAN DATA NOTA PEMBELIAN
// ------------------------------------------------------

function renderReceipts() {

    const container = $("receiptList");

    if (!container) return;

    if (!isOwner()) return;


    if (receipts.length === 0) {

        container.innerHTML =
            "<p>Belum ada nota pembelian.</p>";

        return;
    }


    container.innerHTML = receipts.map((receipt) => {

        return `
            <div class="receipt-item">

                <div>
                    <strong>
                        ${escapeHTML(receipt.supplier)}
                    </strong>

                    <p>
                        Tanggal: ${formatTanggal(receipt.date)}
                    </p>

                </div>

                ${
                    receipt.image
                        ? `
                            <a
                                href="${escapeHTML(receipt.image)}"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Lihat Nota
                            </a>
                        `
                        : ""
                }

            </div>
        `;

    }).join("");

}


// ------------------------------------------------------
// 10.9. MENYIMPAN NOTA PEMBELIAN
// ------------------------------------------------------

async function saveReceipt() {
    console.log("FUNGSI SAVE RECEIPT DIJALANKAN");

    if (!pastikanPemilik()) return;


    const tanggal = $("receiptDate")?.value;

    const pemasok = $("receiptSupplier")?.value.trim();

    const keterangan = $("receiptDescription")?.value.trim();

    const gambar = $("receiptImage")?.files[0];


    if (!tanggal || !pemasok || !gambar) {

        showToast(
            "Tanggal, pemasok, dan gambar nota wajib diisi."
        );

        return;
    }


    const formData = new FormData();

    formData.append("tanggal_nota", tanggal);

    formData.append("nama_pemasok", pemasok);

    formData.append("keterangan", keterangan);

    formData.append("gambar", gambar);


    const saveButton = $("saveReceiptButton");

    if (saveButton) {
        saveButton.disabled = true;
    }


    try {

        console.log("1. MULAI MENGIRIM DATA KE API");

        const hasilSimpan = await apiRequest(API.nota, {
            method: "POST",
            body: formData
        });

        console.log("2. RESPONS API:", hasilSimpan);


        await ambilDataNota();

        if ($("receiptDate")) {
            $("receiptDate").value = "";
        }

        if ($("receiptSupplier")) {
            $("receiptSupplier").value = "";
        }

        if ($("receiptDescription")) {
            $("receiptDescription").value = "";
        }

        if ($("receiptImage")) {
            $("receiptImage").value = "";
        }


        renderReceipts();

        showScreen("updateMenuScreen");

        showToast("Nota pembelian berhasil disimpan.");

    } catch (error) {

        console.error("Gagal menyimpan nota:", error);

        showToast(
            error.message || "Nota pembelian gagal disimpan."
        );

    } finally {

        if (saveButton) {
            saveButton.disabled = false;
        }

    }

}

// ------------------------------------------------------
// 10.10. EVENT LISTENER UPDATE BARANG
// ------------------------------------------------------

// DATA BARANG
$("dataBarangOption")?.addEventListener("click", async () => {

    if (!pastikanPemilik()) return;

    await ambilBarangDatabase();

    renderAdminProducts();

    showScreen("productAdminScreen");

});


// TAMBAH BARANG
$("tambahBarangOption")?.addEventListener("click", () => {

    if (!pastikanPemilik()) return;

    resetProductForm();

    showScreen("tambahBarangScreen");

});


// DATA NOTA PEMBELIAN
$("dataNotaOption")?.addEventListener("click", async () => {

    if (!pastikanPemilik()) return;

    await ambilDataNota();

    renderReceipts();

    showScreen("dataNotaScreen");

});


// TAMBAH NOTA PEMBELIAN
$("notaPembelianOption")?.addEventListener("click", () => {

    if (!pastikanPemilik()) return;

    showScreen("notaPembelianScreen");

});


// BATAL TAMBAH / EDIT BARANG
$("cancelProductButton")?.addEventListener("click", () => {

    resetProductForm();

    showScreen("updateMenuScreen");

});


// SIMPAN NOTA PEMBELIAN
$("saveReceiptButton")?.addEventListener("click", function () {
    console.log("1. TOMBOL NOTA DIKLIK");

    alert("Tombol Simpan Nota terdeteksi!");

    console.log("2. SEBELUM MEMANGGIL SAVE RECEIPT");

    saveReceipt();

    console.log("3. SETELAH MEMANGGIL SAVE RECEIPT");
});

// ------------------------------------------------------
// 10.10. EVENT LISTENER LOGIN DAN LOGOUT
// ------------------------------------------------------

// ======================================================
// EVENT LISTENER LOGIN
// ======================================================

$("loginForm")?.addEventListener("submit", (event) => {

    event.preventDefault();

    loginUser();

});


// EVENT LISTENER LOGOUT

$("kasirLogoutButton")?.addEventListener("click", logoutUser);

$("adminLogoutButton")?.addEventListener("click", logoutUser);
// ------------------------------------------------------
// 10.11. EVENT LISTENER BERANDA
// ------------------------------------------------------

$("generalButtonKasir")?.addEventListener("click", () => {

    setTransactionTheme("Umum");

    showScreen("catalogScreen");

});


$("machineButtonKasir")?.addEventListener("click", () => {

    showScreen("machineMenuScreen");

});


$("generalButtonAdmin")?.addEventListener("click", () => {

    setTransactionTheme("Umum");

    showScreen("catalogScreen");

});


$("machineButtonAdmin")?.addEventListener("click", () => {

    showScreen("machineMenuScreen");

});


$("updateProductButtonAdmin")?.addEventListener("click", () => {

    if (!pastikanPemilik()) return;

    showScreen("updateMenuScreen");

});


$("generalReportButtonAdmin")?.addEventListener("click", () => {

    if (!pastikanPemilik()) return;

    renderGeneralReport();

    showScreen("reportScreen");

});


$("machineReportButtonAdmin")?.addEventListener("click", () => {

    if (!pastikanPemilik()) return;

    renderMachineReport();

    showScreen("machineReportScreen");

});

// ------------------------------------------------------
// EVENT LISTENER KEMBALI KE BERANDA SETELAH TRANSAKSI
// ------------------------------------------------------

$("successHomeButton")?.addEventListener("click", () => {

    if (!pastikanLogin()) return;

    // Mengosongkan data transaksi sebelumnya.
    cart = [];
    selectedPayment = "";

    // Kembali ke beranda sesuai hak akses.
    if (isOwner()) {
        showScreen("homeScreenAdmin");
    } else {
        showScreen("homeScreenKasir");
    }

    renderHome();

});

// ------------------------------------------------------
// 10.12. EVENT LISTENER TANGGAL BERANDA
// ------------------------------------------------------

function pasangDatePicker(buttonId, pickerId) {

    const button = $(buttonId);

    const picker = $(pickerId);

    if (!button || !picker) return;


    button.addEventListener("click", () => {

        if (typeof picker.showPicker === "function") {

            picker.showPicker();

        } else {

            picker.click();

        }

    });


    picker.addEventListener("change", () => {

        if (!picker.value) return;

        selectedTransactionDate = picker.value;

        renderHome();

    });

}


pasangDatePicker(
    "homeDateButtonKasir",
    "datePickerKasir"
);

pasangDatePicker(
    "homeDateButtonAdmin",
    "datePickerAdmin"
);


// ------------------------------------------------------
// 10.13. EVENT LISTENER KATALOG DAN KERANJANG
// ------------------------------------------------------

$("searchInput")?.addEventListener("input", (event) => {

    searchKeyword = event.target.value.trim();

    renderCatalog();

});


$("openCartButton")?.addEventListener("click", () => {

    renderCart();

    showScreen("cartScreen");

});


$("backButton")?.addEventListener("click", goBack);


$("finishButton")?.addEventListener("click", completeTransaction);


document.querySelectorAll("[data-payment]").forEach((button) => {

    button.addEventListener("click", () => {

        const metode = button.dataset.payment;

        if (
            metode === "Utang" &&
            activeTransactionType !== "Toko Mesin"
        ) {

            showToast(
                "Utang hanya tersedia untuk transaksi Toko Mesin."
            );

            return;
        }

        selectedPayment = metode;

        renderCart();

    });

});


/// ------------------------------------------------------
// 10.14. EVENT LISTENER TAGIHAN
// ------------------------------------------------------

// EVENT LISTENER METODE PEMBAYARAN TAGIHAN

document.querySelectorAll(".bill-method").forEach((button) => {

    button.addEventListener("click", () => {

        selectedBillPaymentMethod =
            button.dataset.method.toLowerCase();

        document.querySelectorAll(".bill-method").forEach((item) => {
            item.setAttribute("aria-pressed", "false");
        });

        button.setAttribute("aria-pressed", "true");

        renderBillPaymentTotal();

    });

});


// EVENT LISTENER TOMBOL PEMBAYARAN SELESAI

$("billFinish")?.addEventListener("click", () => {

    paySelectedBill();

});

$("billOption")?.addEventListener("click", async () => {

    if (!pastikanLogin()) return;

    await ambilDataTransaksi();

    renderBillList();

    showScreen("billListScreen");

});

// ------------------------------------------------------
// 10.15. EVENT LISTENER TRANSAKSI BARU TOKO MESIN
// ------------------------------------------------------

$("newMachineOption")?.addEventListener("click", () => {

    if (!pastikanLogin()) return;

    setTransactionTheme("Toko Mesin");

    showScreen("catalogScreen");

});

// ------------------------------------------------------
// 10.16. EVENT LISTENER LAPORAN
// ------------------------------------------------------

$("reportMonth")?.addEventListener("change", refreshReports);

$("reportYear")?.addEventListener("change", refreshReports);


// ------------------------------------------------------
// 10.17. EVENT LISTENER PENGELOLAAN BARANG
// ------------------------------------------------------

$("addProductButton")?.addEventListener("click", () => {

    if (!pastikanPemilik()) return;

    resetProductForm();

    showScreen("tambahBarangScreen");

});


$("saveProductButton")?.addEventListener("click", (event) => {
    event.preventDefault();
    saveProduct();
});

$("cancelProductButton")?.addEventListener("click", () => {

    resetProductForm();

    showScreen("productAdminScreen");

});


// ------------------------------------------------------
// 10.18. EVENT LISTENER NOTA PEMBELIAN
// ------------------------------------------------------

$("openReceiptListButton")?.addEventListener("click", async () => {

    if (!pastikanPemilik()) return;

    await ambilDataNota();

    renderReceipts();

    showScreen("dataNotaScreen");

});


$("addReceiptButton")?.addEventListener("click", () => {

    if (!pastikanPemilik()) return;

    showScreen("notaPembelianScreen");

});


$("saveReceiptButton")?.addEventListener("click", saveReceipt);

// ------------------------------------------------------
// 10.19. INISIALISASI APLIKASI
// ------------------------------------------------------


async function initApp() {

    selectedTransactionDate = getToday();

    perbaruiHakAkses();

    // Memeriksa akun yang tersimpan.
    if (currentUser) {
        if (isOwner()) {
            showScreen("homeScreenAdmin");
        } else if (isCashier()) {
            showScreen("homeScreenKasir");
        } else {
            currentUser = null;
            localStorage.removeItem("currentUser");
            showScreen("adminLoginScreen");
        }
    } else {
        showScreen("adminLoginScreen");
    }

    // Memuat data awal dari database.
    const hasil = await Promise.allSettled([
        ambilBarangDatabase(),
        ambilDataTransaksi(),
        ambilDataNota()
    ]);

    hasil.forEach((item, index) => {
        if (item.status === "rejected") {
            console.error("Gagal memuat data:", item.reason);
        }
    });

    renderCatalog();
    renderCart();
    refreshReports();

    if (currentUser) {
        renderHome();
    }

    window.addEventListener("resize", () => {
        if (currentUser) {
            renderHome();
        }
    });
}


// Menjalankan aplikasi setelah halaman selesai dimuat.
document.addEventListener("DOMContentLoaded", initApp);

document.addEventListener("click", function(event) {
    if (
        event.target.id === "saveProductButton" ||
        event.target.id === "saveReceiptButton"
    ) {
        console.log("TOMBOL SIMPAN DIKLIK:", event.target.id);
    }
});