<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Fejlécek és Könyvtárak
require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';

// Segédfüggvény a levélküldéshez
if (!function_exists('createMailer')) {
    function createMailer()
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = '192.168.151.80';
        $mail->SMTPAuth = false;
        $mail->Port = 25;
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isHTML(true);
        $mail->setFrom('pmk.rma@phoenix-mecano.hu', 'FS Access Portal');
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        return $mail;
    }
}

try {
    // Adatbázis UTF-8 kényszerítés
    if (isset($pdo)) {
        $pdo->exec("SET NAMES 'utf8mb4'");
    }

    // 1. NORMÁL TICKETEK KÜLDÉSE (Jóváhagyás, Elutasítás, Megvonás)
    $stmt = $pdo->query("SELECT b.biralat_ID, b.rendszer, b.kerelem_ID, b.admin_ID, b.dontes, b.admin_comment FROM Biralat b WHERE b.email_sent = 0");
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($all)) {
        $groups = [];
        foreach ($all as $b) {
            $sys = $b['rendszer'];
            $tbl_req = ($sys == 'DO') ? 'Kerelem_DO' : 'Kerelem';
            $tbl_user = ($sys == 'DO') ? 'Igenylok_DO' : 'Igenylo';
            $tbl_folder = ($sys == 'DO') ? 'Megosztasok_DO' : 'Megosztasok';
            $tbl_area = ($sys == 'DO') ? 'Terulet_DO' : 'Terulet';

            $d_sql = "SELECT ig.igenylo_ID, ig.igenylo_nev, ig.igenylo_email, m.megosztas_neve, t.ticket_ID 
                      FROM $tbl_req k 
                      JOIN $tbl_user ig ON k.igenylo_ID = ig.igenylo_ID 
                      JOIN $tbl_folder m ON k.megosztas_ID = m.megosztas_ID 
                      JOIN $tbl_area t ON m.terulet_ID = t.terulet_ID 
                      WHERE k.kerelem_ID = ?";

            $d_stmt = $pdo->prepare($d_sql);
            $d_stmt->execute([$b['kerelem_ID']]);
            $row = $d_stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                // Lekérjük az admin nevét ÉS e-mail címét is
                $adm_stmt = $pdo->prepare("SELECT igenylo_nev, igenylo_email FROM $tbl_user WHERE igenylo_ID = ?");
                $adm_stmt->execute([$b['admin_ID']]);
                $admin_data = $adm_stmt->fetch(PDO::FETCH_ASSOC);

                $admin_name = ($admin_data && !empty($admin_data['igenylo_nev'])) ? $admin_data['igenylo_nev'] : "IT Administrator";
                $admin_email = ($admin_data && !empty($admin_data['igenylo_email'])) ? $admin_data['igenylo_email'] : "pmk.rma@phoenix-mecano.hu";

                $key = $row['igenylo_ID'] . "_" . $b['dontes'];

                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'u_name' => $row['igenylo_nev'],
                        'u_email' => $row['igenylo_email'],
                        'admin' => $admin_name,
                        'admin_email' => $admin_email,
                        'action' => $b['dontes'],
                        'comment' => $b['admin_comment'],
                        't_id' => $row['ticket_ID'],
                        'folders' => [],
                        'b_ids' => []
                    ];
                }
                $groups[$key]['folders'][] = $row['megosztas_neve'];
                $groups[$key]['b_ids'][] = $b['biralat_ID'];
            }
        }

        foreach ($groups as $g) {
            $mail = createMailer();

            $f_list_html = "<ul><li>" . implode("</li><li>", $g['folders']) . "</li></ul>";
            $f_list_plain = implode(", ", $g['folders']);

            $reason_block = "";
            if (!empty($g['comment'])) {
                $reason_block = "<p style='background:#fee; padding:10px; border-left:4px solid #c00;'><b>Indokl&aacute;s / Megjegyz&eacute;s:</b> " . htmlspecialchars($g['comment']) . "</p>";
            }

            // Tárgy és szöveg beállítása
            if ($g['action'] == 'revoke' || $g['action'] == 'review_revoke') {
                $mail->Subject = "Fajl-szerver hozzaferes MEGVONAS - " . $g['u_name'];
                $it_muvelet = "visszavonta a jogot";
                $it_tracker = "Jogosults&aacute;g t&ouml;rl&eacute;se";
                $user_intro = "T&aacute;j&eacute;koztatjuk, hogy az al&aacute;bbi hozz&aacute;f&eacute;r&eacute;seit <b>visszavontuk</b>:";
            } elseif ($g['action'] == 'reject') {
                $mail->Subject = "Fajl-szerver igeny ELUTASITVA - " . $g['u_name'];
                $it_muvelet = "elutasította az igényt";
                $it_tracker = "Ig&eacute;ny elutas&iacute;tva";
                $user_intro = "Sajn&aacute;lattal &eacute;rtes&iacute;tj&uuml;k, hogy f&aacute;jlszerver ig&eacute;ny&eacute;t <b>elutas&iacute;tottuk</b>:";
            } else { // approve
                $mail->Subject = "Fajl-szerver hozzaferes igeny - " . $g['u_name'];
                $it_muvelet = "jóváhagyta";
                $it_tracker = "&Uacute;j ig&eacute;ny";
                $user_intro = "&Ouml;r&ouml;mmel &eacute;rtes&iacute;tj&uuml;k, hogy ig&eacute;nye <b>elfogad&aacute;sra ker&uuml;lt</b>:";
            }

            // --- IT TICKET ---
            $mail->setFrom($g['admin_email'], $g['admin']);

            $mail->addAddress('pmk.rma@phoenix-mecano.hu');
            $mail->Body = "<b>" . $g['u_name'] . "</b> f&aacute;jl-szerver hozz&aacute;f&eacute;r&eacute;si ig&eacute;ny&eacute;t <b>" . $g['admin'] . "</b> " . $it_muvelet . ".<br>" .
                $reason_block . "<br>" .
                "B&iacute;r&aacute;lat sorsz&aacute;ma: " . implode(", ", $g['b_ids']) . "<br>" .
                "Mapp&aacute;k: " . $f_list_plain . "<br>" .
                "Project: egyeb-sw<br>" .
                "Tracker: " . $it_tracker . "<br>" .
                "Status: &Uacute;j<br>" .
                "Priority: Norm&aacute;l<br>" .
                "Mell&eacute;k sz&aacute;ma: 0<br>" .
                "V&aacute;llalat / Firma: " . $g['t_id'];
            $mail->send();

            // --- EMAIL: USER ÉRTESÍTŐ ---
            $mail->clearAddresses();
            $mail->setFrom('pmk.rma@phoenix-mecano.hu', 'FS Access Portal');

            $mail->addAddress($g['u_email']);
            $mail->Subject = "FS Hozzaferes ertesito";

            $full_html = '
            <!DOCTYPE html>
            <html lang="hu">
            <head><meta charset="UTF-8"><style>body { font-family: Arial, sans-serif; color: #333; line-height: 1.5; }</style></head>
            <body>
                <p>Kedves ' . htmlspecialchars($g['u_name']) . '!</p>
                <p>' . $user_intro . '</p>
                ' . $f_list_html . '
                ' . $reason_block . '
                <br><p>&Uuml;dv&ouml;zlettel,<br>IT Csapat</p>
            </body>
            </html>';

            $mail->Body = $full_html;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<li>'], ["\n", "\n- "], $full_html));

            if ($mail->send()) {
                $ids = implode(',', $g['b_ids']);
                $pdo->query("UPDATE Biralat SET email_sent = 1 WHERE biralat_ID IN ($ids)");
            }
        }
    }

    // 2. EMLÉKEZTETŐ EMAILEK KÜLDÉSE (IT ADMIN FUNKCIÓ)
    if (isset($reminder_tasks) && is_array($reminder_tasks) && !empty($reminder_tasks)) {
        foreach ($reminder_tasks as $email => $data) {
            try {
                $mail = createMailer();
                $mail->addAddress($email, $data['name']);
                $mail->Subject = "=?UTF-8?B?" . base64_encode("[FS Portal] Jogosultság felülvizsgálat szükséges") . "?=";

                $folder_list_html = "<ul><li>" . implode("</li><li>", $data['folders']) . "</li></ul>";

                $body_html = '
                <!DOCTYPE html>
                <html lang="hu">
                <head>
                    <meta charset="UTF-8">
                    <style>
                        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
                        .footer { margin-top: 20px; font-size: 0.85em; color: #777; border-top: 1px solid #ddd; padding-top: 10px; }
                    </style>
                </head>
                <body>
                    <p>Kedves ' . htmlspecialchars($data['name']) . '!</p>
                    <p>Az informatikai biztonsági szabályzat értelmében az alábbi mappákhoz tartozó jogosultságok felülvizsgálata <b>esedékessé vált</b> (eltelt 1 év az utolsó ellenőrzés óta).</p>
                    <p>Kérlek, ellenőrizd, hogy a felsorolt mappákhoz kik férnek hozzá, és van-e még rá szükségük:</p>
                    ' . $folder_list_html . '
                    <p>A felülvizsgálat elvégzéséhez kérlek, lépj be a portálra.</p>
                    <p>Amennyiben a jogosultságok rendben vannak, a felületen a "Rendben" gombbal tudod ezt megerősíteni a dátum frissítéséhez.</p>
                    <div class="footer">
                        <p>Üdvözlettel,<br>IT Csoport<br>Phoenix Mecano Kecskemét Kft.</p>
                    </div>
                </body>
                </html>';

                $mail->Body = $body_html;
                $mail->AltBody = strip_tags(str_replace(['<br>', '<li>'], ["\n", "\n- "], $body_html));

                $mail->send();

            } catch (Exception $e) {
                error_log("Hiba az emlékeztető küldésekor ($email): " . $e->getMessage());
            }
        }
        unset($reminder_tasks);
    }

    // 3. ÚJ IGÉNYLÉSEK ÉRTESÍTÉSE A MAPPA FELELŐSÖKNEK (leader_notified)
    if (isset($new_requests_to_notify) && is_array($new_requests_to_notify) && !empty($new_requests_to_notify)) {
        if (!isset($email_send_errors))
            $email_send_errors = [];
        if (!isset($emails_sent_count))
            $emails_sent_count = 0;

        foreach ($new_requests_to_notify as $email => $data) {
            try {
                // Ellenőrizzük, hogy az e-mail cím formátuma érvényes-e
                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception("Érvénytelen e-mail cím formátum a felelősnél: '$email'");
                }

                $mail = createMailer();
                $mail->addAddress($email, $data['owner_name']);

                // Ékezetek támogatása a tárgyban
                $mail->Subject = "=?UTF-8?B?" . base64_encode("[FS Portal] Új hozzáférési igény érkezett") . "?=";

                $req_list_html = "<ul>";
                foreach ($data['requests'] as $req) {
                    $req_list_html .= "<li style='margin-bottom: 10px;'>";
                    $req_list_html .= "<b>Igénylő:</b> " . htmlspecialchars($req['requester']) . " <br>";
                    $req_list_html .= "<b>Mappa:</b> " . htmlspecialchars($req['folder']) . " (" . htmlspecialchars($req['right']) . ")<br>";
                    $req_list_html .= "<b>Indoklás:</b> <span style='color: #555; font-style: italic;'>" . htmlspecialchars($req['reason']) . "</span>";
                    $req_list_html .= "</li>";
                }
                $req_list_html .= "</ul>";

                $body_html = '
                <!DOCTYPE html>
                <html lang="hu">
                <head>
                    <meta charset="UTF-8">
                    <style>
                        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
                        .content-box { background: #f9fafb; padding: 15px; border-radius: 8px; border: 1px solid #e5e7eb; }
                    </style>
                </head>
                <body>
                    <p>Kedves ' . htmlspecialchars($data['owner_name']) . '!</p>
                    <p>Új hozzáférési igény érkezett az általad felügyelt mappá(k)hoz a fájl-szerver portálon:</p>
                    <div class="content-box">
                        ' . $req_list_html . '
                    </div>
                    <p>Kérlek, lépj be az <b>FS Access Portal</b> felületére, és a "Függő igények" menüpont alatt bíráld el a kérelme(ke)t.</p>
                    <p>Üdvözlettel,<br>IT Csoport</p>
                </body>
                </html>';

                $mail->Body = $body_html;
                $mail->AltBody = strip_tags(str_replace(['<br>', '<li>', '</li>'], ["\n", "\n- ", ""], $body_html));

                // HA SIKERESEN ELMENT A LEVÉL:
                if ($mail->send()) {
                    $emails_sent_count++;

                    // Frissítjük az adatbázist: leader_notified = 1
                    foreach ($data['requests'] as $req) {
                        $upd_tbl = ($req['sys'] == 'DO') ? 'Kerelem_DO' : 'Kerelem';
                        $req_id = (int) $req['req_id'];
                        try {
                            $pdo->query("UPDATE $upd_tbl SET leader_notified = 1 WHERE kerelem_ID = $req_id");
                        } catch (Exception $e) {
                            error_log("DB hiba leader_notified frissítéskor: " . $e->getMessage());
                        }
                    }
                } else {
                    $email_send_errors[] = "A mail szerver visszautasította a küldést ide: $email";
                }

            } catch (Exception $e) {
                $email_send_errors[] = "Hiba ($email): " . $e->getMessage();
                error_log("Hiba az új igény értesítő küldésekor ($email): " . $e->getMessage());
            }
        }
        unset($new_requests_to_notify);
    }

} catch (Exception $e) {
    error_log("PHPMailer fő hiba: " . $e->getMessage());
}
?>