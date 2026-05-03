<?php
/**
 * ÉRTESÍTÉS KÜLDÉSE A MAPPA FELELÕSÉNEK (PC VEZETÕ)
 */
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// PHPMailer betöltése...
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

try {
    // Lekérdezzük az új igényt és a hozzá tartozó felelõs (vezetõ) adatait
    // Feltételezzük a 'leader_notified' oszlop meglétét a Kerelem táblákban
    $sql = "
        SELECT 'PM' as rendszer, k.kerelem_ID, ig.igenylo_nev as kerelmezo, 
               m.megosztas_neve, vez.igenylo_email as vezeto_email, vez.igenylo_nev as vezeto_nev
        FROM Kerelem k
        JOIN Igenylo ig ON k.igenylo_ID = ig.igenylo_ID
        JOIN Megosztasok m ON k.megosztas_ID = m.megosztas_ID
        JOIN Igenylo vez ON m.felelos_ID = vez.igenylo_ID
        WHERE k.leader_notified = 0
        
        UNION ALL
        
        SELECT 'DO' as rendszer, k.kerelem_ID, ig.igenylo_nev as kerelmezo, 
               m.megosztas_neve, vez.igenylo_email as vezeto_email, vez.igenylo_nev as vezeto_nev
        FROM Kerelem_DO k
        JOIN Igenylok_DO ig ON k.igenylo_ID = ig.igenylo_ID
        JOIN Megosztasok_DO m ON k.megosztas_ID = m.megosztas_ID
        JOIN Igenylok_DO vez ON m.felelos_ID = vez.igenylo_ID
        WHERE k.leader_notified = 0
        LIMIT 1";

    $stmt = $pdo->query($sql);
    $adat = $stmt->fetch();

    if ($adat) {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = '192.168.151.80';
        $mail->SMTPAuth = false;
        $mail->Port = 25;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom('pmk.rma@phoenix-mecano.hu', 'FS Access Portal');

        // A címzett dinamikusan a táblázatból vett felelõs lesz
        $mail->addAddress($adat['vezeto_email'], $adat['vezeto_nev']);

        $mail->isHTML(true);
        $mail->Subject = "Jóváhagyásra váró hozzáférés: " . $adat['megosztas_neve'];

        $mail->Body = "
            <h3>Tisztelt " . $adat['vezeto_nev'] . "!</h3>
            <p>Új hozzáférési igény érkezett egy Önöz tartozó mappához.</p>
            <table border='0' cellpadding='5'>
                <tr><td><strong>Igénylõ:</strong></td><td>" . $adat['kerelmezo'] . "</td></tr>
                <tr><td><strong>Mappa:</strong></td><td>" . $adat['megosztas_neve'] . "</td></tr>
                <tr><td><strong>Rendszer:</strong></td><td>" . $adat['rendszer'] . "</td></tr>
            </table>
            <p>Kérjük, bírálja el az igényt a portálon.</p>
            <br>
            <p>Üdvözlettel,<br>IT Csapat</p>";

        if ($mail->send()) {
            // Jelöljük, hogy errõl a kérelemrõl már ment ki levél a vezetõnek
            $table = ($adat['rendszer'] === 'PM') ? 'Kerelem' : 'Kerelem_DO';
            $update = $pdo->prepare("UPDATE $table SET leader_notified = 1 WHERE kerelem_ID = ?");
            $update->execute([$adat['kerelem_ID']]);
        }
    }
} catch (Exception $e) {
    error_log("Hiba a vezetõi értesítésnél: " . $e->getMessage());
}
?>