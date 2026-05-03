<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'config.php';

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$loginName = $_SESSION['username'] ?? '';
$fullName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? '';
$page = $_GET['page'] ?? 'fooldal';
$allowedPages = ['fooldal', 'kepek', 'kapcsolat', 'kapcsolat_elkuldve', 'uzenetek', 'crud'];

if (!in_array($page, $allowedPages, true)) {
    $page = 'fooldal';
}

if ($page === 'uzenetek' && !$isLoggedIn) {
    header('Location: login.php');
    exit;
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ensureBeadandoTables(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kapcsolat_uzenetek (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nev VARCHAR(120) NOT NULL,
            email VARCHAR(180) NOT NULL,
            targy VARCHAR(180) NOT NULL,
            uzenet TEXT NOT NULL,
            bekuldo VARCHAR(120) NOT NULL DEFAULT 'Vendég',
            kuldes_ideje DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS galeria_kepek (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fajlnev VARCHAR(255) NOT NULL,
            eredeti_nev VARCHAR(255) NOT NULL,
            feltolto VARCHAR(120) NOT NULL,
            feltoltes_ideje DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function fetchGallery(PDO $pdo)
{
    return $pdo->query("SELECT * FROM galeria_kepek ORDER BY feltoltes_ideje DESC")->fetchAll();
}

function fetchMessages(PDO $pdo)
{
    return $pdo->query("SELECT * FROM kapcsolat_uzenetek ORDER BY kuldes_ideje DESC")->fetchAll();
}

function fetchFolders(PDO $pdo)
{
    return $pdo->query("SELECT megosztas_ID, megosztas_neve, terulet_ID, felelos_ID, masodlagos_felelos, utolso_ellenorzes_datum FROM megosztasok ORDER BY megosztas_ID DESC")->fetchAll();
}

function redirectTo($page)
{
    header('Location: index.php?page=' . urlencode($page));
    exit;
}

ensureBeadandoTables($pdo);

$notice = '';
$noticeType = 'success';
$contactErrors = [];
$contactResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'contact_submit') {
        $nev = trim($_POST['nev'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $targy = trim($_POST['targy'] ?? '');
        $uzenet = trim($_POST['uzenet'] ?? '');

        if (strlen($nev) < 3) {
            $contactErrors[] = 'A név legalább 3 karakter legyen.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $contactErrors[] = 'Érvényes e-mail címet adj meg.';
        }
        if (strlen($targy) < 3) {
            $contactErrors[] = 'A tárgy legalább 3 karakter legyen.';
        }
        if (strlen($uzenet) < 10) {
            $contactErrors[] = 'Az üzenet legalább 10 karakter legyen.';
        }

        if (!$contactErrors) {
            $sender = $isLoggedIn ? $fullName : 'Vendég';
            $stmt = $pdo->prepare("INSERT INTO kapcsolat_uzenetek (nev, email, targy, uzenet, bekuldo) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nev, $email, $targy, $uzenet, $sender]);
            $page = 'kapcsolat_elkuldve';
            $contactResult = [
                    'nev' => $nev,
                    'email' => $email,
                    'targy' => $targy,
                    'uzenet' => $uzenet,
                    'bekuldo' => $sender,
            ];
        } else {
            $page = 'kapcsolat';
            $notice = implode('<br>', array_map('e', $contactErrors));
            $noticeType = 'error';
        }
    }

    if ($action === 'upload_image') {
        if (!$isLoggedIn) {
            redirectTo('kepek');
        }

        if (!isset($_FILES['kep']) || $_FILES['kep']['error'] !== UPLOAD_ERR_OK) {
            $notice = 'Nem sikerült a képfeltöltés.';
            $noticeType = 'error';
            $page = 'kepek';
        } else {
            $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $tmpPath = $_FILES['kep']['tmp_name'];
            $mime = mime_content_type($tmpPath);

            if (!isset($allowedTypes[$mime])) {
                $notice = 'Csak JPG, PNG, WEBP vagy GIF képet lehet feltölteni.';
                $noticeType = 'error';
                $page = 'kepek';
            } else {
                $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'gallery';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }
                $safeName = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowedTypes[$mime];
                $target = $uploadDir . DIRECTORY_SEPARATOR . $safeName;

                if (move_uploaded_file($tmpPath, $target)) {
                    $stmt = $pdo->prepare("INSERT INTO galeria_kepek (fajlnev, eredeti_nev, feltolto) VALUES (?, ?, ?)");
                    $stmt->execute([$safeName, $_FILES['kep']['name'], $fullName]);
                    redirectTo('kepek');
                }

                $notice = 'A kép mentése nem sikerült.';
                $noticeType = 'error';
                $page = 'kepek';
            }
        }
    }

    if ($action === 'crud_create' || $action === 'crud_update' || $action === 'crud_delete') {
        if (!$isLoggedIn) {
            header('Location: login.php');
            exit;
        }

        if ($action === 'crud_delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare("DELETE FROM megosztasok WHERE megosztas_ID = ?");
                $stmt->execute([$id]);
            }
            redirectTo('crud');
        }

        $nev = trim($_POST['megosztas_neve'] ?? '');
        $terulet = (int) ($_POST['terulet_ID'] ?? 0);
        $felelos = (int) ($_POST['felelos_ID'] ?? 0);
        $masodlagos = (int) ($_POST['masodlagos_felelos'] ?? 0);

        if ($nev === '' || $terulet <= 0 || $felelos <= 0) {
            $notice = 'A név, terület és felelős mező kitöltése kötelező.';
            $noticeType = 'error';
            $page = 'crud';
        } elseif ($action === 'crud_create') {
            $stmt = $pdo->prepare("INSERT INTO megosztasok (megosztas_neve, terulet_ID, felelos_ID, masodlagos_felelos, utolso_ellenorzes_datum) VALUES (?, ?, ?, ?, CURDATE())");
            $stmt->execute([$nev, $terulet, $felelos, $masodlagos]);
            redirectTo('crud');
        } else {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE megosztasok SET megosztas_neve = ?, terulet_ID = ?, felelos_ID = ?, masodlagos_felelos = ? WHERE megosztas_ID = ?");
            $stmt->execute([$nev, $terulet, $felelos, $masodlagos, $id]);
            redirectTo('crud');
        }
    }
}

$gallery = fetchGallery($pdo);
$messages = $page === 'uzenetek' ? fetchMessages($pdo) : [];
$folders = $page === 'crud' ? fetchFolders($pdo) : [];
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FS Access Portal beadandó</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header>
    <div class="topbar">
        <div class="brand">
            <img src="pm_logo.png" alt="Phoenix Mecano">
            <strong>FS Access Portal</strong>
        </div>
        <nav aria-label="Főmenü">
            <a href="index.php?page=fooldal" class="<?= $page === 'fooldal' ? 'active' : '' ?>">Főoldal</a>
            <a href="index.php?page=kepek" class="<?= $page === 'kepek' ? 'active' : '' ?>">Képek</a>
            <a href="index.php?page=kapcsolat" class="<?= in_array($page, ['kapcsolat', 'kapcsolat_elkuldve'], true) ? 'active' : '' ?>">Kapcsolat</a>
            <a href="index.php?page=crud" class="<?= $page === 'crud' ? 'active' : '' ?>">CRUD</a>
            <?php if ($isLoggedIn): ?>
                <a href="index.php?page=uzenetek" class="<?= $page === 'uzenetek' ? 'active' : '' ?>">Üzenetek</a>
                <a href="index.php?logout=1">Kilépés</a>
            <?php else: ?>
                <a href="login.php">Bejelentkezés</a>
            <?php endif; ?>
        </nav>
        <div class="userbox">
            <?php if ($isLoggedIn): ?>
                <b>Bejelentkezett:</b>
                <?= e($fullName) ?> (<?= e($loginName) ?>)
            <?php else: ?>
                Vendég felhasználó
            <?php endif; ?>
        </div>
    </div>
</header>

<main>
    <?php if ($notice): ?>
        <div class="notice <?= $noticeType === 'error' ? 'error' : '' ?>"><?= $notice ?></div>
    <?php endif; ?>

    <?php if ($page === 'fooldal'): ?>
        <section class="hero">
            <div>
                <h1>FS Access Portal</h1>
                <p class="lead">
                    Helyi PHP alapú jogosultságigénylő webalkalmazás, amely fájlszerver megosztásokhoz kezeli az igényeket,
                    a felelősöket és az alapvető adminisztrációs műveleteket.
                </p>
                <div class="stat-grid">
                    <div class="stat"><strong>PHP</strong> szerveroldal</div>
                    <div class="stat"><strong>MySQL</strong> adatbázis</div>
                    <div class="stat"><strong>HTML5</strong> reszponzív felület</div>
                </div>
            </div>
            <aside class="hero-panel">
                <div class="logo-row">
                    <img src="pm_logo.png" alt="Phoenix Mecano logo">
                    <img src="do_logo.jpg" alt="DewertOkin logo">
                </div>
                <p class="muted">
                    A rendszer célja, hogy átlátható legyen, ki milyen megosztáshoz kér hozzáférést,
                    és a felelősök gyorsan tudjanak dönteni.
                </p>
            </aside>
        </section>

        <section class="section grid-3">
            <a href="index_old.php" class="card-link">
                <article class="card">
                    <h3>Igénylés</h3>
                    <p>A felhasználó kiválasztja a szükséges megosztást, megadja az indoklást, majd az igény bekerül az adatbázisba.</p>
                </article>
            </a>
            <article class="card">
                <h3>Jóváhagyás</h3>
                <p>A felelősök és adminisztrátorok külön szerepkör alapján láthatják és kezelhetik a hozzájuk tartozó kérelmeket.</p>
            </article>
            <article class="card">
                <h3>Naplózás</h3>
                <p>A kapcsolati üzenetek, feltöltött képek és CRUD műveletek lokális adatbázissal működnek.</p>
            </article>
        </section>

        <section class="section grid-2">
            <div class="card">
                <h2>Helyi videó</h2>
                <video controls poster="pm_logo.png">
                    <source src="media/helyi-bemutato.mp4" type="video/mp4">
                    A böngésződ nem támogatja a videó lejátszását.
                </video>
                <p class="muted">Ide kerül a saját, legfeljebb 5 másodperces videó: <code>media/helyi-bemutato.mp4</code>.</p>
            </div>
            <div class="card">
                <h2>Szolgáltatói videó</h2>
                <iframe src="https://www.youtube.com/embed/R4su6L0zzc0?start=2" title="YouTube bemutató" allowfullscreen></iframe>
            </div>
        </section>

        <section class="section card">
            <h2>Google térkép</h2>
            <iframe
                    src="https://www.google.com/maps?q=Phoenix%20Mecano%20Kecskem%C3%A9t&output=embed"
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"
                    title="Phoenix Mecano térkép"></iframe>
        </section>
    <?php endif; ?>

    <?php if ($page === 'kepek'): ?>
        <section class="section">
            <h1>Képgaléria</h1>
            <p class="lead">A galéria a projekt képeit jeleníti meg. Új képet csak bejelentkezett felhasználó tölthet fel.</p>
        </section>

        <?php if ($isLoggedIn): ?>
            <section class="card section">
                <h2>Új kép feltöltése</h2>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_image">
                    <label for="kep">Kép kiválasztása</label>
                    <input type="file" id="kep" name="kep" accept="image/jpeg,image/png,image/webp,image/gif">
                    <button type="submit">Feltöltés</button>
                </form>
            </section>
        <?php else: ?>
            <div class="empty section">Képfeltöltéshez be kell jelentkezni.</div>
        <?php endif; ?>

        <section class="gallery section">
            <?php if ($gallery): ?>
                <?php foreach ($gallery as $image): ?>
                    <figure>
                        <img src="uploads/gallery/<?= e($image['fajlnev']) ?>" alt="<?= e($image['eredeti_nev']) ?>">
                        <figcaption><?= e($image['eredeti_nev']) ?><br><?= e($image['feltolto']) ?>, <?= e($image['feltoltes_ideje']) ?></figcaption>
                    </figure>
                <?php endforeach; ?>
            <?php else: ?>
                <figure>
                    <img src="pm_logo.png" alt="Phoenix Mecano">
                    <figcaption>Alapértelmezett projektkép</figcaption>
                </figure>
                <figure>
                    <img src="do_logo.jpg" alt="DewertOkin">
                    <figcaption>Alapértelmezett projektkép</figcaption>
                </figure>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($page === 'kapcsolat'): ?>
        <section class="section grid-2">
            <div>
                <h1>Kapcsolat</h1>
                <p class="lead">Az űrlap kliensoldali JavaScript és szerveroldali PHP ellenőrzést is használ, majd az üzenetet adatbázisba menti.</p>
            </div>
            <div class="card">
                <form method="post" id="contactForm" novalidate>
                    <input type="hidden" name="action" value="contact_submit">
                    <label for="nev">Név</label>
                    <input id="nev" name="nev" value="<?= e($_POST['nev'] ?? '') ?>">

                    <label for="email">E-mail</label>
                    <input id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>">

                    <label for="targy">Tárgy</label>
                    <input id="targy" name="targy" value="<?= e($_POST['targy'] ?? '') ?>">

                    <label for="uzenet">Üzenet</label>
                    <textarea id="uzenet" name="uzenet"><?= e($_POST['uzenet'] ?? '') ?></textarea>

                    <div id="clientErrors" class="client-error" aria-live="polite"></div>
                    <button type="submit">Üzenet küldése</button>
                </form>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($page === 'kapcsolat_elkuldve' && $contactResult): ?>
        <section class="section card">
            <h1>Elküldött üzenet</h1>
            <p><strong>Név:</strong> <?= e($contactResult['nev']) ?></p>
            <p><strong>E-mail:</strong> <?= e($contactResult['email']) ?></p>
            <p><strong>Tárgy:</strong> <?= e($contactResult['targy']) ?></p>
            <p><strong>Beküldő:</strong> <?= e($contactResult['bekuldo']) ?></p>
            <p><strong>Üzenet:</strong><br><?= nl2br(e($contactResult['uzenet'])) ?></p>
            <a class="btn secondary" href="index.php?page=kapcsolat">Új üzenet</a>
        </section>
    <?php endif; ?>

    <?php if ($page === 'uzenetek'): ?>
        <section class="section">
            <h1>Üzenetek</h1>
            <p class="lead">A kapcsolat űrlapon beküldött üzenetek fordított időrendben.</p>
            <?php if ($messages): ?>
                <table>
                    <thead>
                    <tr>
                        <th>Küldés ideje</th>
                        <th>Név</th>
                        <th>E-mail</th>
                        <th>Tárgy</th>
                        <th>Beküldő</th>
                        <th>Üzenet</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($messages as $message): ?>
                        <tr>
                            <td><?= e($message['kuldes_ideje']) ?></td>
                            <td><?= e($message['nev']) ?></td>
                            <td><?= e($message['email']) ?></td>
                            <td><?= e($message['targy']) ?></td>
                            <td><?= e($message['bekuldo'] ?: 'Vendég') ?></td>
                            <td><?= nl2br(e($message['uzenet'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty">Még nincs kapcsolat üzenet.</div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($page === 'crud'): ?>
        <section class="section">
            <h1>CRUD - megosztások</h1>
            <p class="lead">Create, Read, Update, Delete műveletek a választott adatbázis <code>megosztasok</code> tábláján.</p>
        </section>

        <?php if (!$isLoggedIn): ?>
            <div class="empty">A CRUD műveletek megtekinthetők, de módosításhoz be kell jelentkezni.</div>
        <?php endif; ?>

        <?php if ($isLoggedIn): ?>
            <section class="card section">
                <h2>Új megosztás létrehozása</h2>
                <form method="post" class="crud-row">
                    <input type="hidden" name="action" value="crud_create">
                    <div>
                        <label>Név</label>
                        <input name="megosztas_neve" placeholder="Példa_RO">
                    </div>
                    <div>
                        <label>Terület</label>
                        <input name="terulet_ID" value="1">
                    </div>
                    <div>
                        <label>Felelős</label>
                        <input name="felelos_ID" value="1">
                    </div>
                    <div>
                        <label>Másodlagos</label>
                        <input name="masodlagos_felelos" value="0">
                    </div>
                    <button type="submit">Mentés</button>
                </form>
            </section>
        <?php endif; ?>

        <section class="section">
            <?php if ($folders): ?>
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Megosztás neve</th>
                        <th>Terület</th>
                        <th>Felelős</th>
                        <th>Másodlagos felelős</th>
                        <th>Utolsó ellenőrzés</th>
                        <?php if ($isLoggedIn): ?><th>Műveletek</th><?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($folders as $folder): ?>
                        <?php $rowFormId = 'crud-row-' . (int) $folder['megosztas_ID']; ?>
                        <tr>
                            <?php if ($isLoggedIn): ?>
                                <td>
                                    <form id="<?= e($rowFormId) ?>" method="post"></form>
                                    <?= e($folder['megosztas_ID']) ?>
                                    <input form="<?= e($rowFormId) ?>" type="hidden" name="id" value="<?= e($folder['megosztas_ID']) ?>">
                                </td>
                                <td><input form="<?= e($rowFormId) ?>" name="megosztas_neve" value="<?= e($folder['megosztas_neve']) ?>"></td>
                                <td><input form="<?= e($rowFormId) ?>" name="terulet_ID" value="<?= e($folder['terulet_ID']) ?>"></td>
                                <td><input form="<?= e($rowFormId) ?>" name="felelos_ID" value="<?= e($folder['felelos_ID']) ?>"></td>
                                <td><input form="<?= e($rowFormId) ?>" name="masodlagos_felelos" value="<?= e($folder['masodlagos_felelos']) ?>"></td>
                                <td><?= e($folder['utolso_ellenorzes_datum']) ?></td>
                                <td>
                                    <button form="<?= e($rowFormId) ?>" type="submit" name="action" value="crud_update">Módosítás</button>
                                    <button form="<?= e($rowFormId) ?>" class="danger" type="submit" name="action" value="crud_delete" onclick="return confirm('Biztosan törlöd ezt a rekordot?')">Törlés</button>
                                </td>
                            <?php else: ?>
                                <td><?= e($folder['megosztas_ID']) ?></td>
                                <td><?= e($folder['megosztas_neve']) ?></td>
                                <td><?= e($folder['terulet_ID']) ?></td>
                                <td><?= e($folder['felelos_ID']) ?></td>
                                <td><?= e($folder['masodlagos_felelos']) ?></td>
                                <td><?= e($folder['utolso_ellenorzes_datum']) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty">Nincs megjeleníthető megosztás az adatbázisban.</div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>

<script>
    const contactForm = document.getElementById('contactForm');
    if (contactForm) {
        contactForm.addEventListener('submit', function (event) {
            const nev = document.getElementById('nev').value.trim();
            const email = document.getElementById('email').value.trim();
            const targy = document.getElementById('targy').value.trim();
            const uzenet = document.getElementById('uzenet').value.trim();
            const errors = [];

            if (nev.length < 3) errors.push('A név legalább 3 karakter legyen.');
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.push('Érvényes e-mail címet adj meg.');
            if (targy.length < 3) errors.push('A tárgy legalább 3 karakter legyen.');
            if (uzenet.length < 10) errors.push('Az üzenet legalább 10 karakter legyen.');

            document.getElementById('clientErrors').innerHTML = errors.join('<br>');
            if (errors.length > 0) {
                event.preventDefault();
            }
        });
    }
</script>
</body>
</html>
