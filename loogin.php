<?php


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!file_exists('config.php')) {
    die("HIBA: Nincs meg a config.php");
}

require 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        try {
            // Lekérdezés az igenylo_nev alapján
            $stmt = $pdo->prepare("SELECT igenylo_ID, igenylo_nev, igenylo_password FROM igenylo WHERE igenylo_nev = :uname LIMIT 1");
            $stmt->execute(['uname' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Jelszó ellenőrzése (titkosított jelszó esetén)
            if ($user && password_verify($password, $user['igenylo_password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['igenylo_ID'];
                $_SESSION['username'] = $user['igenylo_nev'];
                $_SESSION['full_name'] = $user['igenylo_nev'];
                $_SESSION['logged_in'] = true;
                session_write_close();

                header("Location: /index.php");
                exit;
            } else {
                $error = "Helytelen felhasználónév vagy jelszó!";
            }
        } catch (PDOException $e) {
            $error = "Adatbázis hiba történt.";
            error_log("Login error: " . $e->getMessage());
        }
    } else {
        $error = "Kérjük, töltsön ki minden mezőt!";
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FS Access Portal - Login</title>
    <link rel="stylesheet" href="loginstyle.css">
</head>
<body>
<div class="login-card">
    <div class="logo-container">
        <div class="logo-wrapper"><img src="pm_logo.png" class="logo-pm" alt="PM"></div>
        <div class="logo-wrapper"><img src="do_logo.jpg" class="logo-do" alt="DO"></div>
    </div>
    <h2>FS Access Portal</h2>
    <div class="sub-txt">Bejelentkezés az ügyfélkapuba</div>

    <?php if ($error): ?>
        <div class="error-msg"><strong>Hiba:</strong> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="text" name="username" placeholder="Felhasználónév" required autofocus>
        <input type="password" name="password" placeholder="Jelszó" required>
        <button type="submit">Bejelentkezés</button>
    </form>

    <div class="footer-link">
        Még nincs fiókja? <a href="register.php">Regisztráció itt</a>
    </div>
</div>
</body>
</html>
