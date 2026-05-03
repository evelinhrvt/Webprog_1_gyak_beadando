<?php
/**
 * FS Access Portal - Teljesen javított INDEX.PHP
 * Hibakeresés bekapcsolva az 500-as hiba (Internal Server Error) felderítéséhez!
 */

// --- HIBAKERESÉS BEKAPCSOLÁSA ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'config.php';

// --- LOGOUT ---
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

// --- LOGIN ELLENŐRZÉS ---
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

// Felhasználói adatok a munkamenetből (login.php állította be)
$my_user_id = (int) ($_SESSION['user_id'] ?? 0);
$my_full_name = $_SESSION['username'] ?? 'Ismeretlen Felhasználó';
$current_user_id = $my_user_id; // Kompatibilitás miatt

// Friss jogosultság lekérése minden oldalbetöltésnél az 'igenylo' táblából
$stmt_role = $pdo->prepare("SELECT igenylo_jog FROM igenylo WHERE igenylo_ID = ?");
$stmt_role->execute([$my_user_id]);
$role = $stmt_role->fetchColumn() ?: 'user';
$role = trim($role);

$message = '';
$message_type = '';
$page = $_GET['page'] ?? 'igenyles';
$search_term = trim($_GET['search'] ?? '');

// --- SEGÉDFÜGGVÉNYEK ---
function getStatusBadge($status)
{
    switch ($status) {
        case 'pending':
            return '<span class="status-badge st-pending">Függőben</span>';
        case 'accepted':
            return '<span class="status-badge st-accepted">Elfogadva</span>';
        case 'rejected':
            return '<span class="status-badge st-rejected">Elutasítva</span>';
        case 'revoke':
            return '<span class="status-badge st-revoke">Visszavonva</span>';
        default:
            return '<span class="status-badge">' . htmlspecialchars($status) . '</span>';
    }
}

// --- MŰVELETEK FELDOLGOZÁSA (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ÚJ IGÉNY
    if ($action == 'new_request') {
        $company = $_POST['company'] ?? '';
        $reason = $_POST['reason'] ?? '';
        $selected_indices = $_POST['selected_folders'] ?? [];
        $folder_data_list = $_POST['folders_json'] ?? [];
        $rights_list = $_POST['rights'] ?? [];

        if (!empty($selected_indices)) {
            $isDO = (strpos($company, 'DewertOkin') !== false);
            $tbl_req = $isDO ? 'Kerelem_DO' : 'Kerelem';
            $tbl_folder = $isDO ? 'Megosztasok_DO' : 'Megosztasok';
            $tbl_user = $isDO ? 'Igenylok_DO' : 'igenylo';

            $new_requests_to_notify = []; // Ebbe gyűjtjük az értesítendő felelősöket

            foreach ($selected_indices as $idx) {
                $f_data = json_decode($folder_data_list[$idx], true);
                $req_right = $rights_list[$idx];

                // Ha van RW jog és azt kérték:
                $final_id = ($req_right == 'rw' && !empty($f_data['rw'])) ? $f_data['rw'] : $f_data['ro'];
                $type_name = ($req_right == 'rw' && !empty($f_data['rw'])) ? 'írás' : 'olvasás';

                if ($final_id) {
                    $stmt = $pdo->prepare("INSERT INTO $tbl_req (igenylo_ID, megosztas_ID, indoklas, hozzaferes_tipusa, kerelem_datum, status) VALUES (?,?,?,?,NOW(), 'pending')");
                    $stmt->execute([$my_user_id, $final_id, $reason, $type_name]);

                    $inserted_req_id = $pdo->lastInsertId();

                    // --- FELELŐS KERESÉSE AZ E-MAIL KÜLDÉSHEZ ---
                    $own_stmt = $pdo->prepare("SELECT i.igenylo_nev, i.igenylo_email, m.megosztas_neve FROM $tbl_folder m JOIN $tbl_user i ON m.felelos_ID = i.igenylo_ID WHERE m.megosztas_ID = ?");
                    $own_stmt->execute([$final_id]);
                    $owner_data = $own_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($owner_data && !empty($owner_data['igenylo_email'])) {
                        $o_email = trim($owner_data['igenylo_email']);

                        // Csoportosítjuk a felelős e-mail címe alapján
                        if (!isset($new_requests_to_notify[$o_email])) {
                            $new_requests_to_notify[$o_email] = [
                                    'owner_name' => $owner_data['igenylo_nev'],
                                    'requests' => []
                            ];
                        }
                        $new_requests_to_notify[$o_email]['requests'][] = [
                                'req_id' => $inserted_req_id,
                                'sys' => $isDO ? 'DO' : 'PM',
                                'folder' => $owner_data['megosztas_neve'],
                                'right' => $type_name,
                                'reason' => $reason,
                                'requester' => $my_full_name
                        ];
                    }
                }
            }

            // Ticket.php hívása
            if (!empty($new_requests_to_notify)) {
                $emails_sent_count = 0;
                $email_send_errors = [];

                if (file_exists('ticket.php')) {
                    include 'ticket.php';
                }

                if (!empty($email_send_errors)) {
                    $message = "✅ Az igényeket rögzítettük, DE hiba történt az e-mail küldésekor: <br>⚠️ " . implode("<br>⚠️ ", $email_send_errors);
                    $message_type = "warning";
                } elseif ($emails_sent_count > 0) {
                    $message = "✅ Az igényeket sikeresen rögzítettük és $emails_sent_count db értesítőt elküldtünk a felelősöknek!";
                    $message_type = "success";
                } else {
                    $message = "✅ Az igényeket rögzítettük, de technikai okokból nem ment ki értesítő.";
                    $message_type = "warning";
                }
            } else {
                $message = "✅ Az igényeket sikeresen rögzítettük (de a rendszer nem talált érvényes felelős e-mail címet a mappához).";
                $message_type = "warning";
            }
        }
    }
    // ELFOGADÁS / ELUTASÍTÁS / VISSZAVONÁS
    elseif (in_array($action, ['approve', 'reject', 'revoke'])) {
        $raw_ids = (array) ($_POST['req_data'] ?? []);
        $comments = $_POST['admin_comment'] ?? [];

        $success_cnt = 0;
        $error_msgs = [];
        $processed_requests = [];

        if (!empty($raw_ids)) {
            foreach ($raw_ids as $val) {
                list($id, $src) = explode('|', $val);
                $tbl = ($src == 'DO') ? 'Kerelem_DO' : 'Kerelem';
                $tbl_folder = ($src == 'DO') ? 'Megosztasok_DO' : 'Megosztasok';
                $tbl_user = ($src == 'DO') ? 'Igenylok_DO' : 'igenylo';

                $check_stmt = $pdo->prepare("SELECT k.igenylo_ID, m.felelos_ID, m.megosztas_neve, i.igenylo_nev, i.igenylo_email 
                                             FROM $tbl k 
                                             JOIN $tbl_folder m ON k.megosztas_ID = m.megosztas_ID 
                                             JOIN $tbl_user i ON k.igenylo_ID = i.igenylo_ID 
                                             WHERE k.kerelem_ID = ?");
                $check_stmt->execute([$id]);
                $req_info = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$req_info) {
                    continue;
                }

                if ($req_info['igenylo_ID'] == $my_user_id) {
                    $error_msgs[] = "A(z) $id. számú igényt nem bírálhatod el, mert a sajátod!";
                    continue;
                }

                if ($req_info['felelos_ID'] != $my_user_id && !in_array(strtolower($role), ['it_admin'])) {
                    $error_msgs[] = "A(z) $id. számú igényt nem bírálhatod el, mert másodlagos felelősként nincs admin jogod!";
                    continue;
                }

                $status = ($action == 'approve') ? 'accepted' : ($action == 'reject' ? 'rejected' : 'revoke');
                $pdo->prepare("UPDATE $tbl SET status=? WHERE kerelem_ID=?")->execute([$status, $id]);

                $admin_note = isset($comments[$id]) ? trim($comments[$id]) : null;

                $pdo->prepare("INSERT INTO Biralat (kerelem_ID, admin_ID, rendszer, dontes, admin_comment, datum, email_sent) VALUES (?,?,?,?,?,NOW(), 0)")
                        ->execute([$id, $my_user_id, $src, $action, $admin_note]);

                $processed_requests[] = [
                        'req_id' => $id,
                        'folder_name' => $req_info['megosztas_neve'],
                        'requester_name' => $req_info['igenylo_nev'],
                        'requester_email' => $req_info['igenylo_email'],
                        'admin_comment' => $admin_note,
                        'action' => $action
                ];

                $success_cnt++;
            }

            if ($success_cnt > 0) {
                $_POST['processed_requests'] = $processed_requests;
                if (file_exists('ticket.php')) {
                    include 'ticket.php';
                }
                $message = "✅ $success_cnt db művelet sikeresen végrehajtva. (Értesítés elküldve az igénylőnek)";
                $message_type = "success";
            }
            if (!empty($error_msgs)) {
                $message .= " <br>⚠️ " . implode("<br>⚠️ ", $error_msgs);
                $message_type = ($success_cnt == 0) ? "error" : "warning";
            }
        }
    }
    // ÚJ MAPPA LÉTREHOZÁSA (CSAK IT ADMIN)
    elseif ($action == 'create_folder' && $role === 'it_admin') {
        $sys = $_POST['new_system'] ?? '';
        $base_name = trim($_POST['folder_base_name'] ?? '');
        $area_id = $_POST['area_id'] ?? '';
        $owner_id = $_POST['owner_id'] ?? '';
        $sec_owner_id = !empty($_POST['sec_owner_id']) ? $_POST['sec_owner_id'] : null;
        $create_ro = isset($_POST['create_ro']);
        $create_rw = isset($_POST['create_rw']);

        if ($sys && $base_name && $area_id && $owner_id && ($create_ro || $create_rw)) {
            $tbl_folder = ($sys == 'DO') ? 'Megosztasok_DO' : 'Megosztasok';
            $inserted = 0;
            try {
                if ($create_ro) {
                    $name = $base_name . '_RO';
                    $stmt = $pdo->prepare("INSERT INTO $tbl_folder (megosztas_neve, terulet_ID, felelos_ID, masodlagos_felelos_ID) VALUES (?,?,?,?)");
                    $stmt->execute([$name, $area_id, $owner_id, $sec_owner_id]);
                    $inserted++;
                }
                if ($create_rw) {
                    $name = $base_name . '_RW';
                    $stmt = $pdo->prepare("INSERT INTO $tbl_folder (megosztas_neve, terulet_ID, felelos_ID, masodlagos_felelos_ID) VALUES (?,?,?,?)");
                    $stmt->execute([$name, $area_id, $owner_id, $sec_owner_id]);
                    $inserted++;
                }
                $message = "✅ $inserted db mappa sikeresen létrehozva ($sys rendszer)!";
                $message_type = "success";
            } catch (Exception $e) {
                $message = "Hiba történt: " . $e->getMessage();
                $message_type = "error";
            }
        } else {
            $message = "⚠️ Minden kötelező mezőt tölts ki!";
            $message_type = "error";
        }
    }
    // DIREKT ADATBÁZIS BESZÚRÁS (CSAK IT ADMIN)
    elseif ($action == 'add_db_folder' && $role === 'it_admin') {
        $sys = $_POST['db_system'] ?? '';
        $exact_name = trim($_POST['db_folder_name'] ?? '');
        $area_id = $_POST['db_area_id'] ?? '';
        $owner_id = $_POST['db_owner_id'] ?? '';
        $sec_owner_id = !empty($_POST['db_sec_owner_id']) ? $_POST['db_sec_owner_id'] : null;

        if ($sys && $exact_name && $area_id && $owner_id) {
            $tbl_folder = ($sys == 'DO') ? 'Megosztasok_DO' : 'Megosztasok';
            try {
                $stmt = $pdo->prepare("INSERT INTO $tbl_folder (megosztas_neve, terulet_ID, felelos_ID, masodlagos_felelos_ID) VALUES (?,?,?,?)");
                $stmt->execute([$exact_name, $area_id, $owner_id, $sec_owner_id]);
                $message = "✅ Új adatbázis rekord sikeresen rögzítve: $exact_name ($sys)";
                $message_type = "success";

                $search_term = '';
                $_GET['search'] = '';

            } catch (Exception $e) {
                $message = "DB Hiba: " . $e->getMessage();
                $message_type = "error";
            }
        } else {
            $message = "⚠️ Minden mező kitöltése kötelező!";
            $message_type = "error";
        }
    }
    // MAPPA FELELŐSÖK FRISSÍTÉSE (CSAK IT ADMIN)
    elseif ($action == 'update_db_folder_owner' && $role === 'it_admin') {
        $f_id = $_POST['f_id'] ?? 0;
        $f_sys = $_POST['f_sys'] ?? '';
        $new_owner = $_POST['new_owner'] ?? '';
        $new_sec = !empty($_POST['new_sec']) ? $_POST['new_sec'] : null;

        if ($f_id && $f_sys && $new_owner) {
            $tbl_folder = ($f_sys == 'DO') ? 'Megosztasok_DO' : 'Megosztasok';
            try {
                $stmt = $pdo->prepare("UPDATE $tbl_folder SET felelos_ID = ?, masodlagos_felelos_ID = ? WHERE megosztas_ID = ?");
                $stmt->execute([$new_owner, $new_sec, $f_id]);
                $message = "✅ Mappa ($f_id) felelősei sikeresen frissítve!";
                $message_type = "success";
            } catch (Exception $e) {
                $message = "Hiba a frissítéskor: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
    // MAPPA TÖRLÉSE (CSAK IT ADMIN)
    elseif ($action == 'delete_db_folder' && $role === 'it_admin') {
        $del_id = $_POST['del_id'] ?? 0;
        $del_sys = $_POST['del_sys'] ?? '';

        if ($del_id && $del_sys) {
            $tbl_folder = ($del_sys == 'DO') ? 'Megosztasok_DO' : 'Megosztasok';
            try {
                $stmt = $pdo->prepare("DELETE FROM $tbl_folder WHERE megosztas_ID = ?");
                $stmt->execute([$del_id]);
                $message = "✅ Mappa (ID: $del_id) sikeresen törölve!";
                $message_type = "success";
            } catch (Exception $e) {
                $message = "Hiba a törléskor (lehet, hogy van hivatkozás rá): " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
    // FELHASZNÁLÓ KEZELÉS (CSAK IT ADMIN)
    elseif (($action == 'update_user_role' || $action == 'add_new_user') && $role === 'it_admin') {
        if ($action == 'update_user_role') {
            $target_user_id = $_POST['target_user_id'] ?? 0;
            $new_role = $_POST['new_role'] ?? 'user';

            if ($target_user_id && in_array($new_role, ['user', 'mappa_felelos', 'masodlagos_felelos', 'it_admin'])) {
                if ($target_user_id == $my_user_id && $new_role !== 'it_admin') {
                    $message = "⚠️ Saját magad IT Admin jogát nem veheted el ezen a felületen!";
                    $message_type = "warning";
                } else {
                    $stmt = $pdo->prepare("UPDATE igenylo SET igenylo_jog = ? WHERE igenylo_ID = ?");
                    $stmt->execute([$new_role, $target_user_id]);
                    $message = "✅ Felhasználó jogosultsága frissítve!";
                    $message_type = "success";
                }
            }
        } elseif ($action == 'add_new_user') {
            $u_name = trim($_POST['u_name'] ?? '');
            $u_email = trim($_POST['u_email'] ?? '');
            $u_role = $_POST['u_role'] ?? 'user';
            $default_password = password_hash('AlapJelszo123', PASSWORD_DEFAULT);

            if ($u_name) {
                $check = $pdo->prepare("SELECT igenylo_ID FROM igenylo WHERE igenylo_nev = ?");
                $check->execute([$u_name]);
                if ($check->rowCount() > 0) {
                    $message = "⚠️ Ilyen nevű felhasználó már létezik!";
                    $message_type = "error";
                } else {
                    $sql_insert = "INSERT INTO igenylo (igenylo_nev, igenylo_email, igenylo_jog, igenylo_password) VALUES (?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql_insert);
                    try {
                        $stmt->execute([$u_name, $u_email, $u_role, $default_password]);
                        $message = "✅ Új felhasználó sikeresen hozzáadva: $u_name (Jelszó: AlapJelszo123)";
                        $message_type = "success";
                    } catch (Exception $e) {
                        $message = "Adatbázis hiba: " . $e->getMessage();
                        $message_type = "error";
                    }
                }
            } else {
                $message = "⚠️ A név megadása kötelező!";
                $message_type = "error";
            }
        }
    }
    // FELÜLVIZSGÁLAT MENTÉSE (Ticket küldéssel)
    elseif ($action == 'submit_review' && ($role === 'it_admin' || $role === 'mappa_felelos')) {
        $rev_sys = $_POST['rev_sys'] ?? '';
        $rev_folder_id = (int) $_POST['rev_folder_id'] ?? 0;
        $users_to_revoke = $_POST['revoke_users'] ?? [];

        if ($rev_sys && $rev_folder_id) {
            $tbl_req = ($rev_sys == 'DO') ? 'Kerelem_DO' : 'Kerelem';
            $tbl_folder = ($rev_sys == 'DO') ? 'Megosztasok_DO' : 'Megosztasok';

            $check_sql = "SELECT felelos_ID FROM $tbl_folder WHERE megosztas_ID = ?";
            $stmt_check = $pdo->prepare($check_sql);
            $stmt_check->execute([$rev_folder_id]);
            $real_felelos_id = $stmt_check->fetchColumn();

            $is_authorized = ($my_user_id == $real_felelos_id || $role === 'it_admin');

            if (!$is_authorized) {
                $message = "⛔ HIBA: Nincs jogosultságod ezt a mappát felülvizsgálni! Csak a mappa FŐ felelőse teheti meg.";
                $message_type = "error";
            } else {
                $revoked_ids_for_ticket = [];
                $revoked_count = 0;

                if (!empty($users_to_revoke)) {
                    foreach ($users_to_revoke as $req_id) {
                        $stmt = $pdo->prepare("UPDATE $tbl_req SET status = 'revoke' WHERE kerelem_ID = ?");
                        $stmt->execute([$req_id]);

                        $pdo->prepare("INSERT INTO Biralat (kerelem_ID, admin_ID, rendszer, dontes, admin_comment, datum, email_sent) VALUES (?, ?, ?, 'review_revoke', 'Időszakos felülvizsgálat során megvonva', NOW(), 0)")
                                ->execute([$req_id, $my_user_id, $rev_sys]);

                        $revoked_ids_for_ticket[] = $req_id . '|' . $rev_sys;
                        $revoked_count++;
                    }

                    if (count($revoked_ids_for_ticket) > 0) {
                        $temp_post = $_POST['req_data'] ?? null;
                        $_POST['req_data'] = $revoked_ids_for_ticket;

                        $saved_action = $action;
                        $action = 'revoke';

                        if (file_exists('ticket.php')) {
                            include 'ticket.php';
                        }

                        $action = $saved_action;
                        $_POST['req_data'] = $temp_post;
                    }
                }

                $stmt = $pdo->prepare("UPDATE $tbl_folder SET utolso_ellenorzes_datum = NOW() WHERE megosztas_ID = ?");
                $stmt->execute([$rev_folder_id]);

                $message = "✅ Felülvizsgálat rögzítve! $revoked_count jogosultság megvonva, értesítések elküldve.";
                $message_type = "success";
            }
        }
    }
    // EMLÉKEZTETŐ EMAILEK KÜLDÉSE
    elseif ($action == 'send_reminders' && $role === 'it_admin') {
        $year_ago = date('Y-m-d H:i:s', strtotime('-1 year'));

        $sql_remind = "
            SELECT m.megosztas_neve, i.igenylo_nev, i.igenylo_email, 'PM' as rendszer 
            FROM Megosztasok m 
            JOIN igenylo i ON m.felelos_ID = i.igenylo_ID 
            WHERE (m.utolso_ellenorzes_datum IS NULL OR m.utolso_ellenorzes_datum < '$year_ago')
            AND i.igenylo_email IS NOT NULL AND i.igenylo_email != ''
            AND i.igenylo_jog = 'mappa_felelos'
            
            UNION ALL
            
            SELECT m.megosztas_neve, i.igenylo_nev, i.igenylo_email, 'DO' as rendszer 
            FROM Megosztasok_DO m 
            JOIN Igenylok_DO i ON m.felelos_ID = i.igenylo_ID 
            WHERE (m.utolso_ellenorzes_datum IS NULL OR m.utolso_ellenorzes_datum < '$year_ago')
            AND i.igenylo_email IS NOT NULL AND i.igenylo_email != ''
        ";

        $stmt = $pdo->query($sql_remind);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $reminder_tasks = [];

        foreach ($rows as $r) {
            $email = trim($r['igenylo_email']);
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            if (!isset($reminder_tasks[$email])) {
                $reminder_tasks[$email] = [
                        'name' => $r['igenylo_nev'],
                        'folders' => []
                ];
            }
            $reminder_tasks[$email]['folders'][] = $r['megosztas_neve'] . " (" . $r['rendszer'] . ")";
        }

        if (!empty($reminder_tasks)) {
            if (file_exists('ticket.php')) {
                include 'ticket.php';
            }

            $sent_count = count($reminder_tasks);
            $message = "✅ Emlékeztetők feldolgozva és átadva a levelezőnek! Összesen $sent_count fő felelős kapott értesítést.";
            $message_type = "success";
        } else {
            $message = "ℹ️ Nincs kiküldendő emlékeztető (minden mappa rendben van, vagy nincs megfelelő jogosultság/email).";
            $message_type = "warning";
        }
    }
}

// ADATOK LEKÉRÉSE LISTÁKHOZ
$all_areas = ['PM' => [], 'DO' => []];
$all_users = ['PM' => [], 'DO' => []];

if (strtolower($role) === 'it_admin') {
    $stmt = $pdo->query("SELECT terulet_ID, terulet_nev FROM Terulet ORDER BY terulet_nev");
    $all_areas['PM'] = $stmt->fetchAll();
    $stmt = $pdo->query("SELECT terulet_ID, terulet_nev FROM Terulet_DO ORDER BY terulet_nev");
    $all_areas['DO'] = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT igenylo_ID, igenylo_nev, terulet_ID FROM igenylo ORDER BY igenylo_nev");
    $all_users['PM'] = $stmt->fetchAll();
    $stmt = $pdo->query("SELECT igenylo_ID, igenylo_nev, terulet_ID FROM Igenylok_DO ORDER BY igenylo_nev");
    $all_users['DO'] = $stmt->fetchAll();
}

// --- MAPPA STRUKTÚRA BETÖLTÉSE ---
$folders_by_dept = [];
$stmt = $pdo->query("(SELECT 'PM' as src, t.terulet_nev, m.megosztas_neve, m.megosztas_ID FROM Megosztasok m JOIN Terulet t ON m.terulet_id = t.terulet_id)
                      UNION ALL
                      (SELECT 'DO' as src, t.terulet_nev, m.megosztas_neve, m.megosztas_ID FROM Megosztasok_DO m JOIN Terulet_DO t ON m.terulet_id = t.terulet_id)");

while ($r = $stmt->fetch()) {
    $comp = ($r['src'] == 'PM') ? 'Phoenix Mecano Kecskemét Kft.' : 'DewertOkin Kft.';
    $terulet_nev = trim($r['terulet_nev']);
    $is_ro = (substr($r['megosztas_neve'], -3) === '_RO');
    $is_rw = (substr($r['megosztas_neve'], -3) === '_RW');

    if ($is_ro || $is_rw) {
        $base = substr($r['megosztas_neve'], 0, -3);
        if (!isset($folders_by_dept[$comp][$terulet_nev][$base])) {
            $folders_by_dept[$comp][$terulet_nev][$base] = ['ro' => null, 'rw' => null];
        }
        if ($is_ro)
            $folders_by_dept[$comp][$terulet_nev][$base]['ro'] = $r['megosztas_ID'];
        if ($is_rw)
            $folders_by_dept[$comp][$terulet_nev][$base]['rw'] = $r['megosztas_ID'];
    } else {
        $base = $r['megosztas_neve'];
        if (!isset($folders_by_dept[$comp][$terulet_nev][$base])) {
            $folders_by_dept[$comp][$terulet_nev][$base] = ['ro' => null, 'rw' => null];
        }
        $folders_by_dept[$comp][$terulet_nev][$base]['ro'] = $r['megosztas_ID'];
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FS Access Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #003C71; --accent: #EF3340; --bg: #F3F4F6; --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        body { font-family: 'Inter', sans-serif; margin: 0; display: flex; height: 100vh; background: var(--bg); color: #111827; }

        .sidebar { width: 280px; background: var(--primary); color: white; display: flex; flex-direction: column; flex-shrink: 0; height: 100vh; overflow-y: auto; }
        .sidebar-header { padding: 30px 20px; background: rgba(0,0,0,0.2); border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar nav { padding: 20px 0; flex-grow: 1; }
        .sidebar a { padding: 14px 25px; color: #D1D5DB; text-decoration: none; display: flex; align-items: center; transition: 0.2s; font-weight: 500; border-left: 4px solid transparent; }
        .sidebar a:hover { background: rgba(255,255,255,0.1); color: white; }
        .sidebar a.active { background: rgba(255,255,255,0.1); color: white; border-left-color: var(--accent); }

        .main { flex: 1; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
        .top-bar { background: white; padding: 0 40px; height: 70px; display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid var(--accent); flex-shrink: 0; }
        .content { padding: 40px; overflow-y: auto; flex: 1; }

        .logout-btn { color: var(--accent); font-weight: 600; text-decoration: none; padding: 8px 18px; border: 2px solid var(--accent); border-radius: 6px; transition: 0.2s; }
        .logout-btn:hover { background: var(--accent); color: white; }

        .card { background: white; padding: 30px; border-radius: 12px; box-shadow: var(--card-shadow); margin-bottom: 25px; }

        .table-wrapper { overflow-x: auto; border-radius: 8px; border: 1px solid #E5E7EB; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 1000px; }
        th { text-align: left; padding: 16px; background: #F9FAFB; color: #6B7280; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; border-bottom: 1px solid #E5E7EB; white-space: nowrap; }
        td { padding: 12px 16px; border-bottom: 1px solid #F3F4F6; font-size: 0.95rem; vertical-align: middle; }

        .col-nowrap { white-space: nowrap; }
        .col-reason { min-width: 200px; max-width: 350px; white-space: normal; font-style: italic; color: #555; line-height: 1.4; }

        .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; text-transform: uppercase; }
        .st-pending { background: #FEF3C7; color: #92400E; }
        .st-accepted { background: #D1FAE5; color: #065F46; }
        .st-rejected { background: #FEE2E2; color: #991B1B; }
        .st-revoke { background: #F3F4F6; color: #374151; }

        label { display: block; font-weight: 600; margin-bottom: 8px; font-size: 0.9rem; color: #4B5563; }

        select, input[type="text"] { width: 100%; height: 46px; padding: 0 12px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 0.95rem; margin-bottom: 20px; box-sizing: border-box; }
        input[type="text"].search-input { margin: 0; flex: 1; }

        .folder-list { border: 1px solid #E5E7EB; border-radius: 8px; background: #F9FAFB; max-height: 350px; overflow-y: auto; margin-bottom: 20px; }
        .folder-row { display: flex; align-items: center; padding: 12px 15px; border-bottom: 1px solid #E5E7EB; background: white; }

        .btn { height: 46px; padding: 0 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.9rem; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: 0.2s; white-space: nowrap; box-sizing: border-box; }
        .btn-green { background: #10B981 !important; color: white !important; }
        .btn-red { background: #EF4444 !important; color: white !important; }
        .btn-blue { background: #3B82F6 !important; color: white !important; }

        .msg-box { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
        .msg-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
        .msg-error { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
        .msg-warning { background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; }

        .search-container { display: flex; gap: 10px; margin-bottom: 20px; align-items: center; width: 100%; }
        .search-btn { background: #374151; color: white; border: none; height: 46px; padding: 0 20px; border-radius: 8px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; }

        optgroup { font-weight: 700; color: #003C71; background-color: #F3F4F6; }
        optgroup option { font-weight: 400; color: #111827; background-color: white; }
    </style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-header">
        <b><?= htmlspecialchars($my_full_name) ?></b>
        <br><small style="opacity:0.7;">[Jogosultság: <?= htmlspecialchars($role) ?>]</small>
    </div>
    <nav>
        <a href="?page=igenyles" class="<?= $page == 'igenyles' ? 'active' : '' ?>">📝 Új Igénylés</a>
        <a href="?page=fuggo" class="<?= $page == 'fuggo' ? 'active' : '' ?>">⏳ Függő igények</a>
        <a href="?page=elfogadott" class="<?= $page == 'elfogadott' ? 'active' : '' ?>">🗂️ Archívum</a>

        <?php if (in_array(strtolower($role), ['it_admin', 'mappa_felelos', 'masodlagos_felelos'])): ?>
            <a href="?page=felulvizsgalat" class="<?= $page == 'felulvizsgalat' ? 'active' : '' ?>">🔍 Felülvizsgálat</a>
        <?php endif; ?>

        <?php if ($role === 'it_admin'): ?>
            <hr style="border-color:rgba(255,255,255,0.1); margin:10px 20px;">
            <a href="?page=felulvizsgalat_log" class="<?= $page == 'felulvizsgalat_log' ? 'active' : '' ?>">📜 Felülvizsgálati Napló</a>
            <a href="?page=uj_mappa" class="<?= $page == 'uj_mappa' ? 'active' : '' ?>">➕ Mappa Létrehozása</a>
            <a href="?page=felhasznalok" class="<?= $page == 'felhasznalok' ? 'active' : '' ?>">👥 Felhasználók & Jogok</a>
            <a href="?page=adatbazis" class="<?= $page == 'adatbazis' ? 'active' : '' ?>">🗄️ Adatbázis (Megosztások)</a>
        <?php endif; ?>
    </nav>
</div>

<div class="main">
    <div class="top-bar">
        <h2>
            <?php
            if ($page == 'igenyles') echo 'Új Igénylés';
            elseif ($page == 'fuggo') echo 'Függő igények';
            elseif ($page == 'elfogadott') echo 'Archívum';
            elseif ($page == 'uj_mappa') echo 'Új Mappa (IT)';
            elseif ($page == 'adatbazis') echo 'Megosztások - Mappafelelős szerkesztése';
            elseif ($page == 'felhasznalok') echo 'Felhasználók és Jogosultságok';
            elseif ($page == 'felulvizsgalat') echo 'Jogosultság Felülvizsgálat';
            elseif ($page == 'felulvizsgalat_log') echo 'Felülvizsgálati Napló';
            ?>
        </h2>
        <a href="?logout=1" class="logout-btn">Kijelentkezés</a>
    </div>

    <div class="content">
        <?php if ($message): ?>
            <div class="msg-box msg-<?= $message_type == 'error' ? 'error' : ($message_type == 'warning' ? 'warning' : 'success') ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <?php if ($page == 'felhasznalok' && $role === 'it_admin'): ?>

            <div class="card">
                <h3 style="margin-top:0;">Új felhasználó hozzáadása</h3>
                <form method="post" style="display:flex; gap:15px; align-items:flex-end; flex-wrap:wrap;">
                    <input type="hidden" name="action" value="add_new_user">
                    <div style="flex:2; min-width:200px;"><label>Teljes Név</label><input type="text" name="u_name" required style="margin-bottom:0;"></div>
                    <div style="flex:2; min-width:200px;"><label>Email cím (Opcionális)</label><input type="text" name="u_email" style="margin-bottom:0;"></div>
                    <div style="flex:1; min-width:150px;">
                        <label>Jogosultság</label>
                        <select name="u_role" style="margin-bottom:0;">
                            <option value="user">User</option>
                            <option value="mappa_felelos">Mappa Felelős</option>
                            <option value="masodlagos_felelos">Másodlagos Felelős</option>
                            <option value="it_admin">IT Admin</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-green">Hozzáadás</button>
                </form>
            </div>

            <?php
            $usr_filter = $search_term ? "WHERE igenylo_nev LIKE " . $pdo->quote('%' . $search_term . '%') : "";
            $stmt_users = $pdo->query("SELECT * FROM igenylo $usr_filter ORDER BY igenylo_nev ASC");
            ?>
            <div class="search-container">
                <form method="get" style="display:flex; gap:10px; width:100%; align-items:center; margin:0;">
                    <input type="hidden" name="page" value="felhasznalok">
                    <input type="text" name="search" class="search-input" value="<?= htmlspecialchars($search_term) ?>" placeholder="Keresés név alapján...">
                    <button type="submit" class="search-btn">Keresés</button>
                </form>
            </div>
            <div class="card">
                <h3>Regisztrált Felhasználók (PMK)</h3>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>ID</th><th>Név</th><th>Email</th><th>Jog</th><th>Művelet</th></tr></thead>
                        <tbody>
                        <?php while ($u = $stmt_users->fetch(PDO::FETCH_ASSOC)):
                            $current_role = $u['igenylo_jog'] ?? 'user';
                            $bg = '#E5E7EB';
                            if ($current_role == 'it_admin') $bg = '#000';
                            elseif ($current_role == 'mappa_felelos') $bg = '#003C71';
                            elseif ($current_role == 'masodlagos_felelos') $bg = '#6B7280';
                            ?>
                            <tr>
                                <td><?= $u['igenylo_ID'] ?></td>
                                <td><b><?= htmlspecialchars($u['igenylo_nev']) ?></b></td>
                                <td><?= htmlspecialchars($u['igenylo_email'] ?? '-') ?></td>
                                <form method="post">
                                    <input type="hidden" name="action" value="update_user_role">
                                    <input type="hidden" name="target_user_id" value="<?= $u['igenylo_ID'] ?>">
                                    <td>
                                        <select name="new_role" style="margin:0; height:auto; padding:6px; font-size:0.85rem; border:2px solid <?= $bg ?>; font-weight:bold;">
                                            <option value="user" <?= $current_role == 'user' ? 'selected' : '' ?>>User</option>
                                            <option value="mappa_felelos" <?= $current_role == 'mappa_felelos' ? 'selected' : '' ?>>Mappa Felelős</option>
                                            <option value="masodlagos_felelos" <?= $current_role == 'masodlagos_felelos' ? 'selected' : '' ?>>Másodlagos Felelős</option>
                                            <option value="it_admin" <?= $current_role == 'it_admin' ? 'selected' : '' ?>>IT Admin</option>
                                        </select>
                                    </td>
                                    <td><button type="submit" class="btn btn-blue" style="height:auto; padding:6px 12px; font-size:0.85rem;">Mentés</button></td>
                                </form>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($page == 'uj_mappa' && $role === 'it_admin'): ?>
            <div class="card" style="max-width: 700px; margin: 0 auto;">
                <h3>Új fájl-szerver mappa rögzítése</h3>
                <form method="post">
                    <input type="hidden" name="action" value="create_folder">
                    <label>Rendszer / Cég</label>
                    <select name="new_system" id="sysSel" onchange="updateSysLists()" required>
                        <option value="">-- Válassz --</option>
                        <option value="PM">PMK</option>
                        <option value="DO">DO</option>
                    </select>
                    <label>Mappa neve</label><input type="text" name="folder_base_name" required>
                    <div style="background:#F3F4F6; padding:15px; border-radius:8px; margin-bottom:20px;">
                        <label>Létrehozandó verziók:</label>
                        <input type="checkbox" name="create_ro" checked> _RO
                        <input type="checkbox" name="create_rw" checked> _RW
                    </div>
                    <label>Terület</label>
                    <select name="area_id" id="areaSelect" onchange="updateOwners()" required></select>

                    <label>Felelős</label><select name="owner_id" id="ownerSelect" required></select>
                    <label>Másodlagos Felelős</label><select name="sec_owner_id" id="secOwnerSelect"></select>

                    <button type="submit" class="btn btn-green" style="width:100%; justify-content:center; padding:15px; margin-top:10px;">LÉTREHOZÁS</button>
                </form>
            </div>
            <script>
                const areas = <?= json_encode($all_areas) ?>;
                const users = <?= json_encode($all_users) ?>;

                function updateSysLists() {
                    const sys = document.getElementById('sysSel').value;
                    const areaSel = document.getElementById('areaSelect');
                    areaSel.innerHTML = '<option value="">-- Válassz --</option>';
                    if (sys && areas[sys]) {
                        areas[sys].forEach(a => { areaSel.innerHTML += `<option value="${a.terulet_ID}">${a.terulet_nev}</option>`; });
                    }
                    updateOwners();
                }

                function updateOwners() {
                    const sys = document.getElementById('sysSel').value;
                    const areaId = document.getElementById('areaSelect').value;
                    const ownerSel = document.getElementById('ownerSelect');
                    const secOwnerSel = document.getElementById('secOwnerSelect');
                    ownerSel.innerHTML = '<option value="">-- Válassz --</option>';
                    secOwnerSel.innerHTML = '<option value="">-- Nincs --</option>';

                    if (sys && users[sys]) {
                        let primaryUsers = []; let otherUsers = [];
                        users[sys].forEach(u => {
                            if (areaId && u.terulet_ID == areaId) primaryUsers.push(u);
                            else otherUsers.push(u);
                        });

                        if (primaryUsers.length > 0) {
                            let optGroup = '<optgroup label="Adott terület felhasználói">';
                            primaryUsers.forEach(u => optGroup += `<option value="${u.igenylo_ID}">${u.igenylo_nev}</option>`);
                            optGroup += '</optgroup>';
                            ownerSel.innerHTML += optGroup; secOwnerSel.innerHTML += optGroup;
                        }
                        if (otherUsers.length > 0) {
                            let optGroup = '<optgroup label="Többi felhasználó">';
                            otherUsers.forEach(u => optGroup += `<option value="${u.igenylo_ID}">${u.igenylo_nev}</option>`);
                            optGroup += '</optgroup>';
                            ownerSel.innerHTML += optGroup; secOwnerSel.innerHTML += optGroup;
                        }
                    }
                }
            </script>

        <?php elseif ($page == 'igenyles'): ?>
            <div class="card" style="max-width: 700px; margin: 0 auto;">
                <div style="display: flex; justify-content: center; align-items: center; gap: 40px; margin-bottom: 30px;">
                    <img src="pm_logo.png" alt="PMK" style="height: 90px; width: 300px;">
                    <img src="do_logo.jpg" alt="DewertOkin" style="height: 90px; width: 260px;">
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="new_request">
                    <div style="display: flex; gap: 20px;">
                        <div style="flex:1;">
                            <label>Cég</label>
                            <select name="company" id="compSel" onchange="updateAreas()" required>
                                <option value="">-- Válassz --</option>
                                <option value="Phoenix Mecano Kecskemét Kft.">PMK</option>
                                <option value="DewertOkin Kft.">DO</option>
                            </select>
                        </div>
                        <div style="flex:1;"><label>Részleg</label><select id="areaSel" onchange="updateFolders()" required></select></div>
                    </div>
                    <div id="folderCont" class="folder-list"></div>
                    <label>Indoklás</label><input type="text" name="reason" required>
                    <button type="submit" class="btn btn-green" style="width:100%; justify-content:center; padding:15px;">KÜLDÉS</button>
                </form>
            </div>
            <script>
                const data = <?= json_encode($folders_by_dept) ?>;
                function updateAreas() { const c = document.getElementById('compSel').value, a = document.getElementById('areaSel'); a.innerHTML = '<option value="">-- Válassz --</option>'; if(c && data[c]) Object.keys(data[c]).sort().forEach(i => a.innerHTML += `<option value="${i}">${i}</option>`); updateFolders(); }
                function updateFolders() { const c = document.getElementById('compSel').value, r = document.getElementById('areaSel').value, cont = document.getElementById('folderCont'); cont.innerHTML = ''; if(c && r && data[c][r]) { Object.keys(data[c][r]).sort().forEach((f, i) => { const folder = data[c][r][f]; cont.innerHTML += `<div class="folder-row"><div style="flex:1; display:flex; align-items:center;"><input type="checkbox" name="selected_folders[]" value="${i}" style="width:18px; margin-right:12px;"><b>${f}</b><input type="hidden" name="folders_json[${i}]" value='${JSON.stringify(folder)}'></div><select name="rights[${i}]" style="width:120px; height:auto; margin:0;"><option value="ro">Olvasás</option>${folder.rw ? '<option value="rw">Írás</option>' : ''}</select></div>`; }); } }
            </script>

        <?php elseif ($page == 'adatbazis' && $role === 'it_admin'): ?>
            <div class="card">
                <h3 style="margin-top:0;">Új adatbázis rekord felvétele (Manual)</h3>
                <form method="post" style="display:flex; flex-wrap:wrap; gap:15px; align-items:flex-end;">
                    <input type="hidden" name="action" value="add_db_folder">
                    <div style="flex:1;">
                        <label>Rendszer</label>
                        <select name="db_system" id="dbSysSel" onchange="updateDbLists()" required style="margin-bottom:0;">
                            <option value="">-- Válassz --</option>
                            <option value="PM">PMK</option>
                            <option value="DO">DO</option>
                        </select>
                    </div>
                    <div style="flex:2;"><label>Megosztás pontos neve</label><input type="text" name="db_folder_name" placeholder="Pl: K_CAD_RW" required style="margin-bottom:0;"></div>
                    <div style="flex:1;"><label>Terület</label><select name="db_area_id" id="dbAreaSel" onchange="updateDbOwners()" required style="margin-bottom:0;"></select></div>
                    <div style="flex:1;"><label>Felelős</label><select name="db_owner_id" id="dbOwnerSel" required style="margin-bottom:0;"></select></div>
                    <div style="flex:1;"><label>Másodlagos Felelős</label><select name="db_sec_owner_id" id="dbSecOwnerSel" style="margin-bottom:0;"></select></div>
                    <button type="submit" class="btn btn-blue">Felvétel</button>
                </form>
            </div>
            <div class="card" style="padding:15px; background:#F9FAFB;">
                <form method="get" class="search-container" style="margin:0; width:100%;">
                    <input type="hidden" name="page" value="adatbazis">
                    <b style="white-space:nowrap;">Keresés mappára:</b>
                    <input type="text" name="search" class="search-input" value="<?= htmlspecialchars($search_term) ?>" placeholder="Mappa neve..." style="margin:0; flex:1;">
                    <button type="submit" class="btn btn-green">Keresés</button>
                </form>
            </div>

        <?php
        $db_search_q = $search_term ? "WHERE megosztas_neve LIKE " . $pdo->quote('%' . $search_term . '%') : "";
        $sql_db = "SELECT megosztas_ID, megosztas_neve, terulet_ID, felelos_ID, masodlagos_felelos_ID, 'PM' as rendszer FROM Megosztasok $db_search_q
                           UNION ALL
                           SELECT megosztas_ID, megosztas_neve, terulet_ID, felelos_ID, masodlagos_felelos_ID, 'DO' as rendszer FROM Megosztasok_DO $db_search_q
                           ORDER BY rendszer, megosztas_ID";
        $stmt_db = $pdo->query($sql_db);
        ?>
            <div class="card">
                <h3>Adatbázis Lista</h3>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Sys</th><th>ID</th><th>Megosztás Neve</th><th>Terület</th><th>Felelős</th><th>Másodlagps Felelős</th><th>Művelet</th><th>Törlés</th></tr></thead>
                        <tbody>
                        <?php while ($row = $stmt_db->fetch(PDO::FETCH_ASSOC)):
                            $sys_key = $row['rendszer'];
                            $terulet_nev = $row['terulet_ID'];
                            foreach ($all_areas[$sys_key] as $a) {
                                if ($a['terulet_ID'] == $row['terulet_ID']) {
                                    $terulet_nev = $a['terulet_nev']; break;
                                }
                            }
                            ?>
                            <tr>
                                <td><span class="status-badge" style="background: <?= $row['rendszer'] == 'PM' ? '#003C71' : '#EF3340' ?>; color: white;"><?= $row['rendszer'] == 'PM' ? 'PMK' : $row['rendszer'] ?></span></td>
                                <td><?= htmlspecialchars($row['megosztas_ID']) ?></td>
                                <td><b><?= htmlspecialchars($row['megosztas_neve']) ?></b></td>
                                <td><small><?= htmlspecialchars($terulet_nev) ?></small></td>
                                <form method="post">
                                    <input type="hidden" name="action" value="update_db_folder_owner">
                                    <input type="hidden" name="f_id" value="<?= $row['megosztas_ID'] ?>">
                                    <input type="hidden" name="f_sys" value="<?= $row['rendszer'] ?>">
                                    <td>
                                        <select name="new_owner" style="margin:0; height:auto; width:150px; font-size:0.85rem; padding:4px;">
                                            <?php foreach ($all_users[$sys_key] as $u): ?>
                                                <option value="<?= $u['igenylo_ID'] ?>" <?= $u['igenylo_ID'] == $row['felelos_ID'] ? 'selected' : '' ?>><?= htmlspecialchars($u['igenylo_nev']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="new_sec" style="margin:0; height:auto; width:150px; font-size:0.85rem; padding:4px;">
                                            <option value="">-- Nincs --</option>
                                            <?php foreach ($all_users[$sys_key] as $u): ?>
                                                <option value="<?= $u['igenylo_ID'] ?>" <?= $u['igenylo_ID'] == $row['masodlagos_felelos_ID'] ? 'selected' : '' ?>><?= htmlspecialchars($u['igenylo_nev']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><button type="submit" class="btn btn-blue" style="height:auto; padding:6px 10px; font-size:0.8rem;">Mentés</button></td>
                                </form>
                                <td>
                                    <form method="post" onsubmit="return confirm('Biztosan törölni szeretnéd ezt a mappát? A művelet nem visszavonható!');" style="margin:0;">
                                        <input type="hidden" name="action" value="delete_db_folder">
                                        <input type="hidden" name="del_id" value="<?= $row['megosztas_ID'] ?>">
                                        <input type="hidden" name="del_sys" value="<?= $row['rendszer'] ?>">
                                        <button type="submit" class="btn btn-red" style="height:auto; padding:6px 10px; font-size:0.8rem;">🗑️ Törlés</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <script>
                const dbAreas = <?= json_encode($all_areas) ?>; const dbUsers = <?= json_encode($all_users) ?>;
                function updateDbLists() {
                    const sys = document.getElementById('dbSysSel').value, areaSel = document.getElementById('dbAreaSel');
                    areaSel.innerHTML = '<option value="">-- Válassz --</option>';
                    if (sys && dbAreas[sys]) dbAreas[sys].forEach(a => areaSel.innerHTML += `<option value="${a.terulet_ID}">${a.terulet_nev}</option>`);
                    updateDbOwners();
                }
                function updateDbOwners() {
                    const sys = document.getElementById('dbSysSel').value, areaId = document.getElementById('dbAreaSel').value, ownerSel = document.getElementById('dbOwnerSel'), secOwnerSel = document.getElementById('dbSecOwnerSel');
                    ownerSel.innerHTML = '<option value="">-- Válassz --</option>'; secOwnerSel.innerHTML = '<option value="">-- Opc. --</option>';
                    if (sys && dbUsers[sys]) {
                        let primaryUsers = []; let otherUsers = [];
                        dbUsers[sys].forEach(u => { if (areaId && u.terulet_ID == areaId) primaryUsers.push(u); else otherUsers.push(u); });
                        if (primaryUsers.length > 0) {
                            let optGroup = '<optgroup label="Adott terület felhasználói">'; primaryUsers.forEach(u => optGroup += `<option value="${u.igenylo_ID}">${u.igenylo_nev}</option>`); optGroup += '</optgroup>';
                            ownerSel.innerHTML += optGroup; secOwnerSel.innerHTML += optGroup;
                        }
                        if (otherUsers.length > 0) {
                            let optGroup = '<optgroup label="Többi felhasználó">'; otherUsers.forEach(u => optGroup += `<option value="${u.igenylo_ID}">${u.igenylo_nev}</option>`); optGroup += '</optgroup>';
                            ownerSel.innerHTML += optGroup; secOwnerSel.innerHTML += optGroup;
                        }
                    }
                }
            </script>

        <?php elseif ($page == 'felulvizsgalat'): ?>
        <?php
        $review_folder_id = $_GET['folder_id'] ?? null;
        $review_sys = $_GET['sys'] ?? null;

        if ($review_folder_id && $review_sys):
        $tbl_req = ($review_sys == 'DO') ? 'Kerelem_DO' : 'Kerelem';
        $tbl_user = ($review_sys == 'DO') ? 'Igenylok_DO' : 'igenylo';
        $tbl_folder = ($review_sys == 'DO') ? 'Megosztasok_DO' : 'Megosztasok';

        $stmt = $pdo->prepare("SELECT megosztas_neve FROM $tbl_folder WHERE megosztas_ID = ?");
        $stmt->execute([$review_folder_id]);
        $f_name = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT k.kerelem_ID, k.igenylo_ID, k.kerelem_datum, k.hozzaferes_tipusa, i.igenylo_nev, i.igenylo_email FROM $tbl_req k JOIN $tbl_user i ON k.igenylo_ID = i.igenylo_ID WHERE k.megosztas_ID = ? AND k.status = 'accepted' ORDER BY i.igenylo_nev ASC");
        $stmt->execute([$review_folder_id]);
        $active_users = $stmt->fetchAll();
        ?>
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h3>Ellenőrzés: <span style="color:var(--primary);"><?= htmlspecialchars($f_name) ?></span> (<?= $review_sys ?>)</h3>
                    <a href="?page=felulvizsgalat" class="btn btn-blue" style="text-decoration:none;">&larr; Vissza a listához</a>
                </div>
                <div class="msg-box msg-warning">ℹ️ Kérlek, pipáld be azokat a felhasználókat, akiktől <b style="color:#d32f2f; font-weight:bold;">MEG SZERETNÉD VONNI</b> a jogosultságot!</div>
                <form method="post">
                    <input type="hidden" name="action" value="submit_review">
                    <input type="hidden" name="rev_sys" value="<?= $review_sys ?>">
                    <input type="hidden" name="rev_folder_id" value="<?= $review_folder_id ?>">
                    <div class="table-wrapper">
                        <table>
                            <thead><tr><th style="width:50px; text-align:center; color:#d32f2f;">Megvonnád?</th><th>Felhasználó</th><th>Email</th><th>Jog típusa</th><th>Mióta van joga?</th></tr></thead>
                            <tbody>
                            <?php if (count($active_users) > 0): ?>
                                <?php foreach ($active_users as $au): ?>
                                    <tr>
                                        <td style="text-align:center;"><input type="checkbox" name="revoke_users[]" value="<?= $au['kerelem_ID'] ?>" style="width:20px; height:20px; cursor:pointer;"></td>
                                        <td><b><?= htmlspecialchars($au['igenylo_nev']) ?></b></td>
                                        <td><?= htmlspecialchars($au['igenylo_email'] ?? '-') ?></td>
                                        <td><?= $au['hozzaferes_tipusa'] ?></td>
                                        <td><?= date('Y.m.d', strtotime($au['kerelem_datum'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align:center;">Jelenleg nincs aktív felhasználó ezen a mappán.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top:20px; text-align:right;">
                        <button type="submit" class="btn btn-green" onclick="return confirm('Biztosan véglegesíted az ellenőrzést?')">✅ Ellenőrzés lezárása (1 évre)</button>
                    </div>
                </form>
            </div>
        <?php else:
            $six_months_ago = date('Y-m-d H:i:s', strtotime('-6 months'));
            $is_it_admin = ($role === 'it_admin') ? 1 : 0;
            $search_condition = "";
            if (!empty($search_term)) {
                $quoted_search = $pdo->quote('%' . $search_term . '%');
                $search_condition = " AND (m.megosztas_neve LIKE $quoted_search OR i.igenylo_nev LIKE $quoted_search) ";
            }

            $sql_list = "SELECT m.megosztas_ID, m.megosztas_neve, m.felelos_ID, m.utolso_ellenorzes_datum, 'PM' as rendszer, i.igenylo_nev
                        FROM Megosztasok m LEFT JOIN igenylo i ON m.felelos_ID = i.igenylo_ID
                        WHERE (m.utolso_ellenorzes_datum IS NULL OR m.utolso_ellenorzes_datum < '$six_months_ago')
                        AND ( $is_it_admin = 1 OR m.felelos_ID = $my_user_id OR m.masodlagos_felelos_ID = $my_user_id ) $search_condition
                        UNION ALL
                        SELECT m.megosztas_ID, m.megosztas_neve, m.felelos_ID, m.utolso_ellenorzes_datum, 'DO' as rendszer, i.igenylo_nev
                        FROM Megosztasok_DO m LEFT JOIN Igenylok_DO i ON m.felelos_ID = i.igenylo_ID
                        WHERE (m.utolso_ellenorzes_datum IS NULL OR m.utolso_ellenorzes_datum < '$six_months_ago')
                        AND ( $is_it_admin = 1 OR m.felelos_ID = $my_user_id OR m.masodlagos_felelos_ID = $my_user_id ) $search_condition";

            $tasks = $pdo->query($sql_list)->fetchAll();
            ?>
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3>⚠️ Felülvizsgálatra váró mappák</h3>
                    <?php if ($role === 'it_admin' && count($tasks) > 0): ?>
                        <form method="post" onsubmit="return confirm('Biztosan küldesz emlékeztető emailt csak a FELELŐSÖKNEK?');">
                            <input type="hidden" name="action" value="send_reminders">
                            <button type="submit" class="btn btn-blue">📧 Emlékeztetők küldése (Csak a mappa felelősöknek)</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="search-container">
                    <form method="get" style="display:flex; gap:10px; width:100%; align-items:center; margin:0;">
                        <input type="hidden" name="page" value="felulvizsgalat">
                        <input type="text" name="search" class="search-input" value="<?= htmlspecialchars($search_term) ?>" placeholder="Keresés mappa vagy felelős alapján...">
                        <button type="submit" class="search-btn">Keresés</button>
                    </form>
                </div>
                <?php if (count($tasks) == 0): ?>
                    <div class="msg-box msg-success">🎉 Nincs elmaradás! Minden mappa ellenőrizve van.</div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table>
                            <thead><tr><th>Rendszer</th><th>Mappa neve</th><th>Felelős</th><th>Utolsó ellenőrzés</th><th>Művelet</th></tr></thead>
                            <tbody>
                            <?php foreach ($tasks as $t): ?>
                                <tr>
                                    <td><span class="status-badge" style="background: <?= $t['rendszer'] == 'PM' ? '#003C71' : '#EF3340' ?>; color: white;"><?= $t['rendszer'] ?></span></td>
                                    <td><b><?= htmlspecialchars($t['megosztas_neve']) ?></b></td>
                                    <td><?= htmlspecialchars($t['igenylo_nev'] ?? 'Ismeretlen ID: ' . $t['felelos_ID']) ?><?php if ($t['felelos_ID'] == $my_user_id) echo ' <small>(Te - Fő)</small>'; ?></td>
                                    <td><?= $t['utolso_ellenorzes_datum'] ? date('Y.m.d', strtotime($t['utolso_ellenorzes_datum'])) : '<span style="color:red; font-weight:bold;">Sosem volt</span>' ?></td>
                                    <td>
                                        <?php if ($role === 'it_admin' || $t['felelos_ID'] == $my_user_id): ?>
                                            <a href="?page=felulvizsgalat&sys=<?= $t['rendszer'] ?>&folder_id=<?= $t['megosztas_ID'] ?>" class="btn btn-blue" style="text-decoration:none; height:auto; padding: 6px 12px; font-size: 0.9rem;">Ellenőrzés indítása &rarr;</a>
                                        <?php else: ?>
                                            <span class="status-badge" style="background:#E5E7EB; color:#6B7280;">Másodlagos (nincs jog)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php elseif ($page == 'felulvizsgalat_log' && $role === 'it_admin'): ?>
        <?php
        $f_mappa = trim($_GET['f_mappa'] ?? '');
        $f_owner = trim($_GET['f_owner'] ?? '');
        $where_clauses = ["m.utolso_ellenorzes_datum IS NOT NULL"];
        $params = [];
        if ($f_mappa) { $where_clauses[] = "m.megosztas_neve LIKE ?"; $params[] = "%$f_mappa%"; }
        if ($f_owner) { $where_clauses[] = "i.igenylo_nev LIKE ?"; $params[] = "%$f_owner%"; }
        $where_sql = implode(" AND ", $where_clauses);

        $sql_log = "SELECT m.megosztas_ID, m.megosztas_neve, m.utolso_ellenorzes_datum, 'PM' as rendszer, i.igenylo_nev as felelos_nev
                    FROM Megosztasok m JOIN igenylo i ON m.felelos_ID = i.igenylo_ID WHERE $where_sql
                    UNION ALL
                    SELECT m.megosztas_ID, m.megosztas_neve, m.utolso_ellenorzes_datum, 'DO' as rendszer, i.igenylo_nev as felelos_nev
                    FROM Megosztasok_DO m JOIN Igenylok_DO i ON m.felelos_ID = i.igenylo_ID WHERE $where_sql
                    ORDER BY utolso_ellenorzes_datum DESC";
        $stmt_log = $pdo->prepare($sql_log);
        $stmt_log->execute(array_merge($params, $params));
        $logs = $stmt_log->fetchAll();
        ?>
            <div class="card">
                <h3>📜 Felülvizsgálati Napló (Lezárt ellenőrzések)</h3>
                <form method="get" class="search-container" style="background: #f9fafb; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb; display:flex; flex-wrap:wrap; gap:15px; align-items:flex-end;">
                    <input type="hidden" name="page" value="felulvizsgalat_log">
                    <div style="flex: 1; min-width: 200px;"><label>Mappa neve:</label><input type="text" name="f_mappa" class="search-input" value="<?= htmlspecialchars($f_mappa) ?>"></div>
                    <div style="flex: 1; min-width: 200px;"><label>Felelős neve:</label><input type="text" name="f_owner" class="search-input" value="<?= htmlspecialchars($f_owner) ?>"></div>
                    <div style="display: flex; gap: 10px;"><button type="submit" class="btn btn-blue" style="margin: 0;">Szűrés</button><a href="?page=felulvizsgalat_log" class="btn" style="background:#6B7280; color:white; text-decoration:none;">Alaphelyzet</a></div>
                </form>
                <div class="table-wrapper" style="margin-top: 20px;">
                    <table>
                        <thead><tr><th>Rendszer</th><th>Mappa neve</th><th>Felelős (Tulajdonos)</th><th>Utolsó sikeres felülvizsgálat</th><th>Státusz</th></tr></thead>
                        <tbody>
                        <?php if (count($logs) > 0): ?>
                            <?php foreach ($logs as $l): ?>
                                <tr>
                                    <td><span class="status-badge" style="background: <?= $l['rendszer'] == 'PM' ? '#003C71' : '#EF3340' ?>; color: white;"><?= $l['rendszer'] ?></span></td>
                                    <td><b><?= htmlspecialchars($l['megosztas_neve']) ?></b></td>
                                    <td><?= htmlspecialchars($l['felelos_nev']) ?></td>
                                    <td><?= date('Y.m.d H:i', strtotime($l['utolso_ellenorzes_datum'])) ?></td>
                                    <?php if (strtotime($l['utolso_ellenorzes_datum']) < strtotime('-1 year')) echo '<td><span class="status-badge st-rejected">Elmaradott</span></td>'; else echo '<td><span class="status-badge st-accepted">Rendben</span></td>'; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center; padding: 30px;">Nincs a szűrésnek megfelelő lezárt felülvizsgálat.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php else: ?>
            <div class="search-container">
                <form method="get" style="display:flex; gap:10px; width:100%; align-items:center; margin:0;">
                    <input type="hidden" name="page" value="<?= htmlspecialchars($page) ?>">
                    <input type="text" name="search" class="search-input" placeholder="Keresés..." value="<?= htmlspecialchars($search_term) ?>">
                    <button type="submit" class="search-btn">Keresés</button>
                </form>
            </div>
            <form method="post">
                <?php if ($page == 'fuggo' && strtolower($role) !== 'user'): ?>
                    <div class="card" style="padding: 15px; display: flex; gap: 10px; background: #F9FAFB; align-items: center; border: 1px solid #E5E7EB;">
                        <span style="font-size: 0.9rem; color: #4B5563; font-weight: 600;">Csoportos:</span>
                        <button type="submit" name="action" value="approve" class="btn btn-green" style="height:auto;">✓ Elfogadás</button>
                        <button type="submit" name="action" value="reject" class="btn btn-red" style="height:auto;">✕ Elutasítás</button>
                    </div>
                <?php endif; ?>
                <div class="card" style="padding:0; overflow:hidden;">
                    <div class="table-wrapper">
                        <table>
                            <thead>
                            <tr>
                                <?php if (strtolower($role) !== 'user'): ?><th style="width: 40px;"><input type="checkbox" onclick="var c=document.getElementsByName('req_data[]'); for(var i=0;i<c.length;i++) c[i].checked=this.checked;"></th><?php endif; ?>
                                <th>Státusz</th>
                                <th>Dátum</th>
                                <th>Igénylő</th>
                                <th>Igénylés oka</th>
                                <th>Mappa</th>
                                <th>Jog</th>
                                <th>Jóváhagyta</th>
                                <?php if (strtolower($role) !== 'user'): ?><th style="min-width: 180px;">Admin megjegyzés</th><?php endif; ?>
                                <th style="text-align:right;">Művelet</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            $st_filter = ($page == 'fuggo') ? "status='pending'" : "status IN ('accepted','rejected','revoke')";

                            $q = "SELECT * FROM ( 
                                        (SELECT k.kerelem_ID, k.status, k.indoklas, k.kerelem_datum, i.igenylo_nev, m.megosztas_neve, k.hozzaferes_tipusa, 'PM' as src, m.felelos_ID as f1, m.masodlagos_felelos_ID as f2,
                                            (SELECT COALESCE(u1.igenylo_nev, u2.igenylo_nev) FROM Biralat b LEFT JOIN igenylo u1 ON b.admin_ID = u1.igenylo_ID LEFT JOIN Igenylok_DO u2 ON b.admin_ID = u2.igenylo_ID WHERE b.kerelem_ID = k.kerelem_ID AND b.rendszer = 'PM' ORDER BY b.datum DESC LIMIT 1) as jovahagyo_nev,
                                            (SELECT admin_comment FROM Biralat b WHERE b.kerelem_ID = k.kerelem_ID AND b.rendszer = 'PM' ORDER BY b.datum DESC LIMIT 1) as admin_megjegyzes
                                        FROM Kerelem k JOIN igenylo i ON k.igenylo_ID=i.igenylo_ID JOIN Megosztasok m ON k.megosztas_ID=m.megosztas_ID) 
                                        UNION ALL 
                                        (SELECT k.kerelem_ID, k.status, k.indoklas, k.kerelem_datum, i.igenylo_nev, m.megosztas_neve, k.hozzaferes_tipusa, 'DO' as src, m.felelos_ID as f1, m.masodlagos_felelos_ID as f2,
                                            (SELECT COALESCE(u1.igenylo_nev, u2.igenylo_nev) FROM Biralat b LEFT JOIN igenylo u1 ON b.admin_ID = u1.igenylo_ID LEFT JOIN Igenylok_DO u2 ON b.admin_ID = u2.igenylo_ID WHERE b.kerelem_ID = k.kerelem_ID AND b.rendszer = 'DO' ORDER BY b.datum DESC LIMIT 1) as jovahagyo_nev,
                                            (SELECT admin_comment FROM Biralat b WHERE b.kerelem_ID = k.kerelem_ID AND b.rendszer = 'DO' ORDER BY b.datum DESC LIMIT 1) as admin_megjegyzes
                                        FROM Kerelem_DO k JOIN Igenylok_DO i ON k.igenylo_ID=i.igenylo_ID JOIN Megosztasok_DO m ON k.megosztas_ID=m.megosztas_ID) 
                                    ) as t WHERE $st_filter";

                            if ($search_term) $q .= " AND (igenylo_nev LIKE " . $pdo->quote('%' . $search_term . '%') . " OR megosztas_neve LIKE " . $pdo->quote('%' . $search_term . '%') . ")";

                            if (strtolower($role) == 'user') $q .= " AND igenylo_nev=" . $pdo->quote($my_full_name);
                            elseif (in_array(strtolower($role), ['mappa_felelos', 'masodlagos_felelos'])) $q .= " AND (f1=$my_user_id OR f2=$my_user_id OR igenylo_nev=" . $pdo->quote($my_full_name) . ")";

                            $q .= " ORDER BY kerelem_ID DESC";

                            $res = $pdo->query($q);
                            while ($r = $res->fetch()):
                                $display_date = date('Y.m.d H:i', strtotime($r['kerelem_datum']));
                                $can_approve = (in_array(strtolower($role), ['it_admin']) || (strtolower($role) == 'mappa_felelos' && $r['f1'] == $my_user_id));
                                ?>
                                <tr>
                                    <?php if (strtolower($role) !== 'user'): ?>
                                        <td><?php if ($can_approve && in_array($r['status'], ['pending', 'accepted'])): ?><input type="checkbox" id="chk_<?= $r['kerelem_ID'] ?>" name="req_data[]" value="<?= $r['kerelem_ID'] ?>|<?= $r['src'] ?>"><?php endif; ?></td>
                                    <?php endif; ?>
                                    <td><?= getStatusBadge($r['status']) ?></td>
                                    <td class="col-nowrap"><?= $display_date ?></td>
                                    <td><b><?= htmlspecialchars($r['igenylo_nev']) ?></b></td>
                                    <td class="col-reason"><?= htmlspecialchars($r['indoklas']) ?></td>
                                    <td class="col-nowrap"><?= str_replace(['_RO', '_RW'], '', $r['megosztas_neve']) ?></td>
                                    <td><?= $r['hozzaferes_tipusa'] ?></td>
                                    <td><?= $r['jovahagyo_nev'] ? htmlspecialchars($r['jovahagyo_nev']) : '-' ?></td>
                                    <?php if (strtolower($role) !== 'user'): ?>
                                        <td>
                                            <?php if (in_array($r['status'], ['pending', 'accepted']) && $can_approve): ?>
                                                <?php if ($r['status'] == 'accepted' && !empty($r['admin_megjegyzes'])): ?><div style="font-size:0.75rem; color:#6B7280; margin-bottom:4px;"><i>Elfogadva: <?= htmlspecialchars($r['admin_megjegyzes']) ?></i></div><?php endif; ?>
                                                <input type="text" name="admin_comment[<?= $r['kerelem_ID'] ?>]" placeholder="<?= $r['status'] == 'accepted' ? 'Visszavonás oka...' : 'Megjegyzés...' ?>" style="margin:0; width:100%; padding:6px; height:auto; border-radius:6px;">
                                            <?php else: ?>
                                                <span style="color:#6B7280; font-style:italic; font-size:0.85rem;"><?= htmlspecialchars($r['admin_megjegyzes'] ?? '-') ?></span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <?php if ($can_approve): ?>
                                            <?php if ($r['status'] == 'pending'): ?>
                                                <button type="submit" name="action" value="approve" class="btn btn-green" style="height:32px; padding:0 10px;" onclick="document.getElementById('chk_<?= $r['kerelem_ID'] ?>').checked = true;">✓</button>
                                                <button type="submit" name="action" value="reject" class="btn btn-red" style="height:32px; padding:0 10px;" onclick="document.getElementById('chk_<?= $r['kerelem_ID'] ?>').checked = true;">✕</button>
                                            <?php elseif ($r['status'] == 'accepted'): ?>
                                                <button type="submit" name="action" value="revoke" class="btn btn-red" style="font-size:0.8rem; height:32px; padding:0 10px;" onclick="document.getElementById('chk_<?= $r['kerelem_ID'] ?>').checked = true;">Visszavonás</button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="font-size:0.8rem; color:#888;">Másodlagos</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>