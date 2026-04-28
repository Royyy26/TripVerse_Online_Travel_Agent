<?php
session_start();
include 'connect.php';

function sanitize($data, $conn) {
    return mysqli_real_escape_string($conn, trim($data));
}

// Fungsi untuk generate ID user baru
function generateUserId($conn) {
    $query = "SELECT MAX(id_user) AS max_id FROM user WHERE id_user LIKE 'USR%'";
    $result = $conn->query($query);
    $row = $result->fetch_assoc();
    
    if ($row && $row['max_id']) {
        $lastId = $row['max_id'];
        $num = (int)substr($lastId, 3); // Ambil angka setelah 'USR'
        $newNum = $num + 1;
        return "USR" . str_pad($newNum, 3, "0", STR_PAD_LEFT); // USR006
    } else {
        return "USR001"; // Jika belum ada user
    }
}

// Fungsi untuk generate ID customer baru
function generateCustomerId($conn) {
    $query = "SELECT MAX(customer_id) AS max_id FROM customer WHERE customer_id LIKE 'CUST%'";
    $result = $conn->query($query);
    $row = $result->fetch_assoc();
    
    if ($row && $row['max_id']) {
        $lastId = $row['max_id'];
        // Ambil angka setelah 'CUST'
        if (strlen($lastId) > 4) {
            $num = (int)substr($lastId, 4);
            $newNum = $num + 1;
            // Pad dengan 0 di depan (5 digit)
            return "CUST" . str_pad($newNum, 5, "0", STR_PAD_LEFT);
        } else {
            return "CUST00001"; // Format default jika ada masalah parsing
        }
    } else {
        return "CUST00001"; // Jika belum ada customer
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['signUp'])) {
    // Ambil dan sanitasi data
    $firstName = sanitize($_POST['fName'] ?? '', $conn);
    $lastName  = sanitize($_POST['lName'] ?? '', $conn);
    $username  = sanitize($_POST['uName'] ?? '', $conn);
    $email     = sanitize($_POST['email'] ?? '', $conn);
    $phone     = sanitize($_POST['no_hp'] ?? '', $conn); // PERHATIAN: ini 'no_hp', bukan 'phone'
    $password  = sanitize($_POST['password'] ?? '', $conn);
    $role      = 'user';
    
    // Debug: cek apakah data masuk
    error_log("Data yang diterima - First: $firstName, Last: $lastName, User: $username, Email: $email, Phone: $phone, Pass: [HIDDEN]");

    // Validasi field wajib (tanpa lastName karena optional)
    $requiredFields = ['firstName', 'username', 'email', 'phone', 'password'];
    $missingFields = [];
    
    if (empty($firstName)) $missingFields[] = 'First Name';
    if (empty($username)) $missingFields[] = 'Username';
    if (empty($email)) $missingFields[] = 'Email';
    if (empty($phone)) $missingFields[] = 'Phone Number';
    if (empty($password)) $missingFields[] = 'Password';

    if (!empty($missingFields)) {
        $fields = implode(', ', $missingFields);
        echo "<script>
                alert('Mohon isi field berikut: $fields');
                window.history.back();
              </script>";
        exit;
    }

    // Validasi format email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "<script>
                alert('Format email tidak valid!');
                window.history.back();
              </script>";
        exit;
    }

    // Validasi format nomor telepon (harus dimulai dengan +62)
    if (!preg_match('/^\+62[0-9]{8,11}$/', $phone)) {
        echo "<script>
                alert('Format nomor telepon harus +62 diikuti 8-11 digit angka!');
                window.history.back();
              </script>";
        exit;
    }

    // Cek apakah email sudah digunakan untuk role 'user' saja
    $checkEmail = "SELECT * FROM user WHERE email = ? AND role = ?";
    $stmt = $conn->prepare($checkEmail);
    $stmt->bind_param("ss", $email, $role);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        echo "<script>
                alert('Email sudah terdaftar sebagai user!');
                window.history.back();
              </script>";
        $stmt->close();
        exit;
    }
    $stmt->close();
    
    // Cek apakah username sudah digunakan
    $checkUsername = "SELECT * FROM user WHERE username = ?";
    $stmt = $conn->prepare($checkUsername);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        echo "<script>
                alert('Username sudah digunakan! Silakan pilih username lain.');
                window.history.back();
              </script>";
        $stmt->close();
        exit;
    }
    $stmt->close();

    // Mulai transaksi untuk menjaga konsistensi data
    $conn->begin_transaction();

    try {
        // Generate ID baru
        $id_user = generateUserId($conn);
        
        // Untuk sekarang, kita simpan password plain text (sesuai contoh)
        // Di production, HARUS menggunakan password_hash()
        $hashed_password = $password; // Untuk saat ini
        
        // Simpan data ke tabel user
        $insertQuery = "INSERT INTO user (id_user, first_name, last_name, username, email, no_hp, password, role)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insertQuery);
        $stmt->bind_param("ssssssss", $id_user, $firstName, $lastName, $username, $email, $phone, $hashed_password, $role);

        if (!$stmt->execute()) {
            throw new Exception("Gagal menyimpan data user: " . $conn->error);
        }
        $stmt->close();

        // GENERATE CUSTOMER ID dan INSERT ke tabel customer
        $customer_id = generateCustomerId($conn);
        
        // Gabungkan first name dan last name untuk nama customer
        $customer_name = trim($firstName . ' ' . $lastName);
        
        // Insert ke tabel customer
        $insertCustomerQuery = "INSERT INTO customer (customer_id, id_user, email, nama, no_hp, created_at) 
                               VALUES (?, ?, ?, ?, ?, NOW())";
        $stmt2 = $conn->prepare($insertCustomerQuery);
        $stmt2->bind_param("sssss", $customer_id, $id_user, $email, $customer_name, $phone);
        
        if (!$stmt2->execute()) {
            throw new Exception("Gagal menyimpan data customer: " . $conn->error);
        }
        $stmt2->close();

        // Commit transaksi jika semua berhasil
        $conn->commit();

        // Set session untuk notifikasi sukses
        $_SESSION['registration_success'] = true;
        $_SESSION['customer_name'] = $customer_name;
        
        echo "<script>
                alert('Registrasi berhasil! Akun customer Anda telah dibuat. Silakan login.');
                window.location.href = 'login.php?success=1';
              </script>";
        
    } catch (Exception $e) {
        // Rollback jika ada error
        $conn->rollback();
        
        echo "<script>
                alert('Error saat registrasi: " . addslashes($e->getMessage()) . "');
                window.history.back();
              </script>";
    }
} else {
    // Jika akses langsung ke file
    echo "<script>
            alert('Akses tidak valid!');
            window.location.href = 'login.php';
          </script>";
}

$conn->close();
?>