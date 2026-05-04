<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('default_charset', 'UTF-8');

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

require 'config.php';

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: index_old.php');
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
            bekuldo VARCHAR(120) NOT NULL DEFAULT 'Vend&eacute;g',
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
    header('Location: index_old.php?page=' . urlencode($page));
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
            $contactErrors[] = 'A n&eacute;v legal&aacute;bb 3 karakter legyen.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $contactErrors[] = '&Eacute;rv&eacute;nyes e-mail c&iacute;met adj meg.';
        }
        if (strlen($targy) < 3) {
            $contactErrors[] = 'A t&aacute;rgy legal&aacute;bb 3 karakter legyen.';
        }
        if (strlen($uzenet) < 10) {
            $contactErrors[] = 'Az &uuml;zenet legal&aacute;bb 10 karakter legyen.';
        }

        if (!$contactErrors) {
            $sender = $isLoggedIn ? $fullName : 'Vend&eacute;g';
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
            $notice = 'Nem siker&uuml;lt a k&eacute;pfelt&ouml;lt&eacute;s.';
            $noticeType = 'error';
            $page = 'kepek';
        } else {
            $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $tmpPath = $_FILES['kep']['tmp_name'];
            $mime = mime_content_type($tmpPath);

            if (!isset($allowedTypes[$mime])) {
                $notice = 'Csak JPG, PNG, WEBP vagy GIF k&eacute;pet lehet felt&ouml;lteni.';
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

                $notice = 'A k&eacute;p ment&eacute;se nem siker&uuml;lt.';
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
            $notice = 'A n&eacute;v, ter&uuml;let &eacute;s felel&#337;s mez&#337; kit&ouml;lt&eacute;se k&ouml;telez&#337;.';
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
    <title>FS Access Portal beadand&oacute;</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header>
    <div class="topbar">
        <div class="brand">
            <img src="pm_logo.png" alt="Phoenix Mecano">
            <strong>FS Access Portal</strong>
        </div>
        <nav aria-label="F&#337;men&uuml;">
            <a href="index.php?page=fooldal" class="<?= $page === 'fooldal' ? 'active' : '' ?>">F&#337;oldal</a>
            <a href="index.php?page=kepek" class="<?= $page === 'kepek' ? 'active' : '' ?>">K&eacute;pek</a>
            <a href="index.php?page=kapcsolat" class="<?= in_array($page, ['kapcsolat', 'kapcsolat_elkuldve'], true) ? 'active' : '' ?>">Kapcsolat</a>
            <a href="index.php?page=crud" class="<?= $page === 'crud' ? 'active' : '' ?>">CRUD</a>
            <?php if ($isLoggedIn): ?>
                <a href="index.php?page=uzenetek" class="<?= $page === 'uzenetek' ? 'active' : '' ?>">&Uuml;zenetek</a>
                <a href="index.php?logout=1">Kil&eacute;p&eacute;s</a>
            <?php else: ?>
                <a href="login.php">Bejelentkez&eacute;s</a>
            <?php endif; ?>
        </nav>
        <div class="userbox">
            <?php if ($isLoggedIn): ?>
                <b>Bejelentkezett:</b>
                <?= e($fullName) ?> (<?= e($loginName) ?>)
            <?php else: ?>
                Vend&eacute;g felhaszn&aacute;l&oacute;
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
                    Helyi PHP alap&uacute; jogosults&aacute;gig&eacute;nyl&#337; webalkalmaz&aacute;s, amely f&aacute;jlszerver megoszt&aacute;sokhoz kezeli az ig&eacute;nyeket,
                    a felel&#337;s&ouml;ket &eacute;s az alapvet&#337; adminisztr&aacute;ci&oacute;s m&#369;veleteket.
                </p>
                <div class="stat-grid">
                    <div class="stat"><strong>PHP</strong> szerveroldal</div>
                    <div class="stat"><strong>MySQL</strong> adatb&aacute;zis</div>
                    <div class="stat"><strong>HTML5</strong> reszponz&iacute;v fel&uuml;let</div>
                </div>
            </div>
            <aside class="hero-panel">
                <div class="logo-row">
                    <img src="pm_logo.png" alt="Phoenix Mecano logo">
                    <img src="do_logo.jpg" alt="DewertOkin logo">
                </div>
                <p class="muted">
                    A rendszer c&eacute;lja, hogy &aacute;tl&aacute;that&oacute; legyen, ki milyen megoszt&aacute;shoz k&eacute;r hozz&aacute;f&eacute;r&eacute;st,
                    &eacute;s a felel&#337;s&ouml;k gyorsan tudjanak d&ouml;nteni.
                </p>
            </aside>
        </section>

        <section class="section grid-3">
            <article class="card">
                <h3>Ig&eacute;nyl&eacute;s</h3>
                <p>A felhaszn&aacute;l&oacute; kiv&aacute;lasztja a sz&uuml;ks&eacute;ges megoszt&aacute;st...</p>
                <a class="card-button" href="index_old.php">Ig&eacute;nyl&eacute;s</a>
            </article>
            <article class="card">
                <h3>J&oacute;v&aacute;hagy&aacute;s</h3>
                <p>A felel&#337;s&ouml;k &eacute;s adminisztr&aacute;torok k&uuml;l&ouml;n szerepk&ouml;r alapj&aacute;n l&aacute;thatj&aacute;k &eacute;s kezelhetik a hozz&aacute;juk tartoz&oacute; k&eacute;relmeket.</p>
            </article>
            <article class="card">
                <h3>Napl&oacute;z&aacute;s</h3>
                <p>A kapcsolati &uuml;zenetek, felt&ouml;lt&ouml;tt k&eacute;pek &eacute;s CRUD m&#369;veletek lok&aacute;lis adatb&aacute;zissal m&#369;k&ouml;dnek.</p>
            </article>
        </section>

        <section class="section grid-2">
            <div class="card">
                <h2>Helyi vide&oacute;</h2>
                <video controls poster="pm_logo.png">
                    <source src="media/helyi-bemutato.mp4" type="video/mp4">
                    A b&ouml;ng&eacute;sz&#337;d nem t&aacute;mogatja a vide&oacute; lej&aacute;tsz&aacute;s&aacute;t.
                </video>
                <p class="muted">Ide ker&uuml;l a saj&aacute;t, legfeljebb 5 m&aacute;sodperces vide&oacute;: <code>media/helyi-bemutato.mp4</code>.</p>
            </div>
            <div class="card">
                <h2>Szolg&aacute;ltat&oacute;i vide&oacute;</h2>
                <iframe src="https://www.youtube.com/embed/R4su6L0zzc0?start=2" title="YouTube bemutat&oacute;" allowfullscreen></iframe>
            </div>
        </section>

        <section class="section card">
            <h2>Google t&eacute;rk&eacute;p</h2>
            <iframe
                src="https://www.google.com/maps?q=Phoenix%20Mecano%20Kecskem%C3%A9t&output=embed"
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade"
                title="Phoenix Mecano t&eacute;rk&eacute;p"></iframe>
        </section>
    <?php endif; ?>

    <?php if ($page === 'kepek'): ?>
        <section class="section">
            <h1>K&eacute;pgal&eacute;ria</h1>
            <p class="lead">A gal&eacute;ria a projekt k&eacute;peit jelen&iacute;ti meg. &Uacute;j k&eacute;pet csak bejelentkezett felhaszn&aacute;l&oacute; t&ouml;lthet fel.</p>
        </section>

        <?php if ($isLoggedIn): ?>
            <section class="card section">
                <h2>&Uacute;j k&eacute;p felt&ouml;lt&eacute;se</h2>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_image">
                    <label for="kep">K&eacute;p kiv&aacute;laszt&aacute;sa</label>
                    <input type="file" id="kep" name="kep" accept="image/jpeg,image/png,image/webp,image/gif">
                    <button type="submit">Felt&ouml;lt&eacute;s</button>
                </form>
            </section>
        <?php else: ?>
            <div class="empty section">K&eacute;pfelt&ouml;lt&eacute;shez be kell jelentkezni.</div>
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
                    <figcaption>Alap&eacute;rtelmezett projektk&eacute;p</figcaption>
                </figure>
                <figure>
                    <img src="do_logo.jpg" alt="DewertOkin">
                    <figcaption>Alap&eacute;rtelmezett projektk&eacute;p</figcaption>
                </figure>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($page === 'kapcsolat'): ?>
        <section class="section grid-2">
            <div>
                <h1>Kapcsolat</h1>
                <p class="lead">Az &#369;rlap kliensoldali JavaScript &eacute;s szerveroldali PHP ellen&#337;rz&eacute;st is haszn&aacute;l, majd az &uuml;zenetet adatb&aacute;zisba menti.</p>
            </div>
            <div class="card">
                <form method="post" id="contactForm" novalidate>
                    <input type="hidden" name="action" value="contact_submit">
                    <label for="nev">N&eacute;v</label>
                    <input id="nev" name="nev" value="<?= e($_POST['nev'] ?? '') ?>">

                    <label for="email">E-mail</label>
                    <input id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>">

                    <label for="targy">T&aacute;rgy</label>
                    <input id="targy" name="targy" value="<?= e($_POST['targy'] ?? '') ?>">

                    <label for="uzenet">&Uuml;zenet</label>
                    <textarea id="uzenet" name="uzenet"><?= e($_POST['uzenet'] ?? '') ?></textarea>

                    <div id="clientErrors" class="client-error" aria-live="polite"></div>
                    <button type="submit">&Uuml;zenet k&uuml;ld&eacute;se</button>
                </form>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($page === 'kapcsolat_elkuldve' && $contactResult): ?>
        <section class="section card">
            <h1>Elk&uuml;ld&ouml;tt &uuml;zenet</h1>
            <p><strong>N&eacute;v:</strong> <?= e($contactResult['nev']) ?></p>
            <p><strong>E-mail:</strong> <?= e($contactResult['email']) ?></p>
            <p><strong>T&aacute;rgy:</strong> <?= e($contactResult['targy']) ?></p>
            <p><strong>Bek&uuml;ld&#337;:</strong> <?= e($contactResult['bekuldo']) ?></p>
            <p><strong>&Uuml;zenet:</strong><br><?= nl2br(e($contactResult['uzenet'])) ?></p>
            <a class="btn secondary" href="index.php?page=kapcsolat">&Uacute;j &uuml;zenet</a>
        </section>
    <?php endif; ?>

    <?php if ($page === 'uzenetek'): ?>
        <section class="section">
            <h1>&Uuml;zenetek</h1>
            <p class="lead">A kapcsolat &#369;rlapon bek&uuml;ld&ouml;tt &uuml;zenetek ford&iacute;tott id&#337;rendben.</p>
            <?php if ($messages): ?>
                <table>
                    <thead>
                    <tr>
                        <th>K&uuml;ld&eacute;s ideje</th>
                        <th>N&eacute;v</th>
                        <th>E-mail</th>
                        <th>T&aacute;rgy</th>
                        <th>Bek&uuml;ld&#337;</th>
                        <th>&Uuml;zenet</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($messages as $message): ?>
                        <tr>
                            <td><?= e($message['kuldes_ideje']) ?></td>
                            <td><?= e($message['nev']) ?></td>
                            <td><?= e($message['email']) ?></td>
                            <td><?= e($message['targy']) ?></td>
                            <td><?= e($message['bekuldo'] ?: 'Vend&eacute;g') ?></td>
                            <td><?= nl2br(e($message['uzenet'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty">M&eacute;g nincs kapcsolat &uuml;zenet.</div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($page === 'crud'): ?>
        <section class="section">
            <h1>CRUD - megoszt&aacute;sok</h1>
            <p class="lead">Create, Read, Update, Delete m&#369;veletek a v&aacute;lasztott adatb&aacute;zis <code>megosztasok</code> t&aacute;bl&aacute;j&aacute;n.</p>
        </section>

        <?php if (!$isLoggedIn): ?>
            <div class="empty">A CRUD m&#369;veletek megtekinthet&#337;k, de m&oacute;dos&iacute;t&aacute;shoz be kell jelentkezni.</div>
        <?php endif; ?>

        <?php if ($isLoggedIn): ?>
            <section class="card section">
                <h2>&Uacute;j megoszt&aacute;s l&eacute;trehoz&aacute;sa</h2>
                <form method="post" class="crud-row">
                    <input type="hidden" name="action" value="crud_create">
                    <div>
                        <label>N&eacute;v</label>
                        <input name="megosztas_neve" placeholder="P&eacute;lda_RO">
                    </div>
                    <div>
                        <label>Ter&uuml;let</label>
                        <input name="terulet_ID" value="1">
                    </div>
                    <div>
                        <label>Felel&#337;s</label>
                        <input name="felelos_ID" value="1">
                    </div>
                    <div>
                        <label>M&aacute;sodlagos</label>
                        <input name="masodlagos_felelos" value="0">
                    </div>
                    <button type="submit">Ment&eacute;s</button>
                </form>
            </section>
        <?php endif; ?>

        <section class="section">
            <?php if ($folders): ?>
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Megoszt&aacute;s neve</th>
                        <th>Ter&uuml;let</th>
                        <th>Felel&#337;s</th>
                        <th>M&aacute;sodlagos felel&#337;s</th>
                        <th>Utols&oacute; ellen&#337;rz&eacute;s</th>
                        <?php if ($isLoggedIn): ?><th>M&#369;veletek</th><?php endif; ?>
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
                                    <button form="<?= e($rowFormId) ?>" type="submit" name="action" value="crud_update">M&oacute;dos&iacute;t&aacute;s</button>
                                    <button form="<?= e($rowFormId) ?>" class="danger" type="submit" name="action" value="crud_delete" onclick="return confirm('Biztosan t&ouml;rl&ouml;d ezt a rekordot?')">T&ouml;rl&eacute;s</button>
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
                <div class="empty">Nincs megjelen&iacute;thet&#337; megoszt&aacute;s az adatb&aacute;zisban.</div>
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

        if (nev.length < 3) errors.push('A n&eacute;v legal&aacute;bb 3 karakter legyen.');
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.push('&Eacute;rv&eacute;nyes e-mail c&iacute;met adj meg.');
        if (targy.length < 3) errors.push('A t&aacute;rgy legal&aacute;bb 3 karakter legyen.');
        if (uzenet.length < 10) errors.push('Az &uuml;zenet legal&aacute;bb 10 karakter legyen.');

        document.getElementById('clientErrors').innerHTML = errors.join('<br>');
        if (errors.length > 0) {
            event.preventDefault();
        }
    });
}
</script>
</body>
</html>
