<?php
// 1. Hibakezelés: Éles környezetben a hibák naplózása fontos, nem a kiírásuk
error_reporting(E_ALL);
ini_set('display_errors', 0); // 0 = Nem írja ki a böngészőbe (biztonsági okokból)
ini_set('log_errors', 1);     // 1 = Mentse el fájlba a hibákat
ini_set('error_log', __DIR__ . '/php_errors.log'); // A hibanapló helye

// 2. Munkamenet kezelése: A felhasználó azonosításához szükséges
$sessionPath = __DIR__ . '/sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0775, true);
}
session_save_path($sessionPath);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. Adatbázis hozzáférési adatok
$host = 'localhost';
$port = 3306;
$db = 'fs_acces';
$user = 'root';
$pass = '';
$charset = 'utf8mb4'; // Támogatja a speciális karaktereket (pl. hosszú ő, ű)

// 4. PDO konfiguráció: Modern és biztonságos adatbázis-kezelés
$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Hiba esetén dobjon "Exception"-t
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Az adatokat alapból tömbként kapjuk vissza
    PDO::ATTR_EMULATE_PREPARES => false,                  // SQL injection elleni védelem (valódi prepared statements)
];

try {
    // Kapcsolódás megkísérlése
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Ha nem sikerül kapcsolódni, írjuk be a logba, de a user ne lássa a jelszót/IP-t
    error_log("Adatbázis csatlakozási hiba: " . $e->getMessage());
    die("Szerverhiba történt. Kérjük, nézze meg a hibanaplót.");
}
?>
