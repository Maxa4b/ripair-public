<?php
// API endpoint for sending a confirmation email after a booking
// Uses PHPMailer and environment variables for SMTP configuration.

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/sms_helpers.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../phpmailer/src/Exception.php';
require '../phpmailer/src/PHPMailer.php';
require '../phpmailer/src/SMTP.php';

/**
 * Generate a simple one-page quote (devis) PDF based on the provided details.
 *
 * This helper constructs a PDF manually without external libraries, using a
 * layout inspired by the official RIPAIR quotes. It outputs a temporary
 * file path. If creation fails, null is returned and the email is still sent.
 *
 * @param string $clientName  Name of the customer
 * @param string $quoteDate   Human-readable date/time of the appointment
 * @param string $device      Device description (category, brand, model)
 * @param string $problem     Problem description
 * @param float  $price       Original price before discount
 * @param float  $discountPct Percentage discount applied (0–100)
 * @param string $store       Store name
 * @param string $address     Store address
 * @param string $quoteNumber Optional custom quote number (if empty, auto-generated)
 * @return string|null        Path to the generated PDF or null on failure
 */
function generateManualQuotePdf(string $clientName, string $quoteDate, string $device, string $problem, float $price, float $discountPct, string $store, string $address, string $quoteNumber = '', array $details = [], float $durationMin = 0): ?string
{
    // Basic sanity check
    if ($price <= 0 || !$clientName) {
        return null;
    }

    // Auto-generate a quote number based on timestamp if not provided
    if (!$quoteNumber) {
        $quoteNumber = 'RIP-' . date('YmdHis');
    }

    // If details are provided, compute total price from them; otherwise use provided price
    $items = [];
    $calcPrice = 0.0;
    if (is_array($details) && !empty($details)) {
        foreach ($details as $it) {
            $desc  = isset($it['problem']) ? trim($it['problem']) : '';
            $p     = isset($it['price']) ? floatval($it['price']) : 0.0;
            if ($desc === '') $desc = 'Réparation';
            $items[] = [ 'desc' => $desc, 'price' => $p ];
            $calcPrice += $p;
        }
    } else {
        // Default single line item
        $desc = 'Réparation ' . ($device ?: '') . ' – ' . ($problem ?: '');
        $items[] = [ 'desc' => $desc, 'price' => $price ];
        $calcPrice = $price;
    }
    // Optional: if a duration and hourly rate (e.g. 30€/h) is provided, add labour line
    // You can adjust the hourly rate here
    $hourlyRate = 0; // e.g. 30; set to 0 to omit labour cost
    if ($durationMin > 0 && $hourlyRate > 0) {
        $hours = round($durationMin / 60, 2);
        $labourPrice = $hours * $hourlyRate;
        $items[] = [ 'desc' => "Main-d'oeuvre (" . $hours . ' h)', 'price' => $labourPrice ];
        $calcPrice += $labourPrice;
    }
    // Calculate discount and totals
    $discountAmount = ($discountPct > 0) ? round($calcPrice * $discountPct / 100, 2) : 0.0;
    $total          = round($calcPrice - $discountAmount, 2);
    // Format values
    $fmtDiscount     = $discountAmount > 0 ? number_format($discountAmount, 2, ',', ' ') . ' €' : '—';
    $fmtTotal        = number_format($total, 2, ',', ' ') . ' €';

    // Prepare PDF objects
    $objects = [];
    $xref = [];
    $buffer = '';

    // Helper to create a new object
    $addObject = function($content) use (&$objects) {
        $objects[] = $content;
        return count($objects);
    };

    // PDF header
    $buffer .= "%PDF-1.4\n";

    // Fonts
    // Define fonts with WinAnsiEncoding to support western European characters
    $font1 = $addObject("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>");
    $font2 = $addObject("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>");

    // Page content stream
    $content = '';
    // Helper for placing text
    $write = function($x, $y, $text, $size = 10, $bold = false) use (&$content) {
        $font = $bold ? 2 : 1;
        // Convert to Windows-1252 (WinAnsi) for proper accent support with /WinAnsiEncoding
        $converted = @iconv('UTF-8', 'Windows-1252//IGNORE', $text);
        if ($converted === false) {
            $converted = $text;
        }
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
        $content .= sprintf("BT /F%d %d Tf %.2f %.2f Td (%s) Tj ET\n", $font, $size, $x, $y, $escaped);
    };
    // Coordinates: A4 595x842 points (72 points per inch)
    $yStart = 800;
    // Header: logo placeholder and company info
    $write(40, $yStart, 'RIPAIR', 20, true);
    $write(40, $yStart - 18, 'RÉPARATION ÉLECTRONIQUE', 10);
    // Coordonnées générales (sans mettre en avant un magasin spécifique)
    $write(400, $yStart, '12 Chemin de Guitayne', 9);
    $write(400, $yStart - 14, '33610 Cestas', 9);
    $write(400, $yStart - 28, 'contact@ripair.shop', 9);
    $write(400, $yStart - 42, '06 15 58 87 82', 9);

    // Quote info
    $write(40, $yStart - 60, 'Devis n° ' . $quoteNumber, 12, true);
    $write(40, $yStart - 74, 'Date : ' . $quoteDate, 9);
    $write(40, $yStart - 88, 'Client : ' . $clientName, 9);

    // Horizontal separator
    $content .= "0 0 0 rg 1 w 40 " . ($yStart - 100) . " 515 0.5 re S\n";
    $headerY = $yStart - 118;
    // Table headers
    $write(40, $headerY, 'Description', 10, true);
    $write(300, $headerY, 'Quantité', 10, true);
    $write(370, $headerY, 'PU', 10, true);
    $write(430, $headerY, 'TVA', 10, true);
    $write(490, $headerY, 'Montant', 10, true);
    // Write each line item
    $currentY = $headerY - 14;
    foreach ($items as $it) {
        $desc  = $it['desc'];
        $p     = $it['price'];
        $fmt   = number_format($p, 2, ',', ' ') . ' €';
        $write(40, $currentY, $desc, 9);
        $write(300, $currentY, '1', 9);
        $write(370, $currentY, $fmt, 9);
        $write(430, $currentY, '0%', 9);
        $write(490, $currentY, $fmt, 9);
        $currentY -= 14;
    }

    // Sous-total line (HT = TTC because TVA 0%)
    $fmtSubtotal = number_format($calcPrice, 2, ',', ' ') . ' €';
    $write(370, $currentY, 'Sous-total HT', 9, true);
    $write(490, $currentY, $fmtSubtotal, 9);

    // Discount line if applicable
    if ($discountAmount > 0) {
        $currentY -= 14;
        $write(370, $currentY, 'Remise', 9, true);
        $write(490, $currentY, '-' . $fmtDiscount, 9);
    }

    // Total line
    $currentY -= 14;
    $write(370, $currentY, 'Total TTC', 11, true);
    $write(490, $currentY, $fmtTotal, 11, true);

    // Footer with notes
    $footerY = 120;
    $write(40, $footerY, 'Cette offre est valable 30 jours à compter de la date du devis.', 8);
    $write(40, $footerY - 12, 'Un acompte peut être demandé avant la commande des pièces.', 8);
    $write(40, $footerY - 12, "Tout devis accepté ne peut faire l'objet d'une annulation.", 8);
    $write(40, $footerY - 24, 'Merci de votre confiance.', 8);

    // Wrap the content in a stream object
    $stream = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
    $contentsObj = $addObject($stream);

    // Page object referencing fonts and the content stream
    $pageObjNum = $addObject("<< /Type /Page /Parent 3 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 " . $font1 . " 0 R /F2 " . $font2 . " 0 R >> >> /Contents " . $contentsObj . " 0 R >>");

    // Pages tree
    $pagesObjNum = $addObject("<< /Type /Pages /Kids [" . $pageObjNum . " 0 R] /Count 1 >>");

    // Catalog
    $catalogObjNum = $addObject("<< /Type /Catalog /Pages " . $pagesObjNum . " 0 R >>");

    // Build xref table
    $offset = strlen($buffer);
    $xrefTable = "0 1\n0000000000 65535 f \n";
    foreach ($objects as $i => $obj) {
        $xrefTable .= str_pad($offset, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        $buffer .= ($i+1) . " 0 obj\n" . $obj . "\nendobj\n";
        $offset = strlen($buffer);
    }
    $xrefOffset = strlen($buffer);
    $buffer .= "xref\n" . $xrefTable;
    $buffer .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root " . $catalogObjNum . " 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

    // Save to temporary file
    $tmpFile = tempnam(sys_get_temp_dir(), 'ripair_devis_');
    if (!$tmpFile) {
        return null;
    }
    file_put_contents($tmpFile, $buffer);
    return $tmpFile;
}

/**
 * Normalise un numéro de téléphone en format E.164 (par défaut France +33).
 *
 * @param string $raw   Numéro fourni par l'utilisateur
 * @param string $defaultCountryCode Code pays par défaut (ex: +33)
 * @return string Numéro normalisé ou chaîne vide si invalide
 */

header('Content-Type: application/json; charset=utf-8');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Read JSON payload
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

// Extract fields
$name        = trim($data['name'] ?? '');
$email       = trim($data['email'] ?? '');
$phoneInput    = trim($data['phone'] ?? '');
$phone        = $phoneInput;
$device      = trim($data['device'] ?? '');
$problem     = trim($data['problem'] ?? '');
$price       = trim($data['price'] ?? '');
$discountPct = trim($data['discount'] ?? '');
$store       = trim($data['store'] ?? '');
$address     = trim($data['address'] ?? '');
$startIso    = trim($data['start_datetime'] ?? '');
$cancelToken = trim($data['cancel_token'] ?? '');

if ($phone === '' && $cancelToken !== '' && isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare('SELECT phone FROM appointments WHERE cancel_token = :token LIMIT 1');
        $stmt->execute([':token' => $cancelToken]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['phone'])) {
            $phone = trim((string) $row['phone']);
        }
    } catch (Throwable $e) {
        error_log('[sms] failed to fetch phone via cancel token: ' . $e->getMessage());
    }
}

// Optional details and duration for multi-problem quotes
$details           = $data['details'] ?? null;
$durationMinParam  = $data['duration_min'] ?? null;
$cancelExpiryIso   = trim($data['cancel_token_expires_at'] ?? '');
$cancelAllowedFlag = $data['can_cancel'] ?? null;
$cancelAllowed     = ($cancelToken !== '') ? true : false;
if ($cancelToken !== '' && $cancelAllowedFlag !== null) {
    $cancelAllowed = filter_var($cancelAllowedFlag, FILTER_VALIDATE_BOOL);
}
$cancelExpiryFr = '';
if ($cancelToken !== '' && $cancelExpiryIso !== '') {
    try {
        $cancelExpiryFr = (new DateTime($cancelExpiryIso))->format('d/m/Y à H\\hi');
        if (new DateTime($cancelExpiryIso) <= new DateTime('now')) {
            $cancelAllowed = false;
        }
    } catch (Throwable $ignore) {
        $cancelExpiryFr = '';
    }
}
// Basic validation
if (!$name || !$email || !$startIso) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

// Format date/time
$dt = new DateTime($startIso);
$dateFr = $dt->format('d/m/Y à H\\hi');

// Load SMTP settings from env
$smtpHost = env('SMTP_HOST');
$smtpPort = env('SMTP_PORT');
$smtpUser = env('SMTP_USERNAME');
$smtpPass = env('SMTP_PASSWORD');
$smtpSecure = env('SMTP_SECURE', 'tls');
$mailFrom = env('MAIL_FROM');
$mailFromName = env('MAIL_FROM_NAME', 'RIPAIR');
$mailToDefault = env('MAIL_TO');

try {
    $mail = new PHPMailer(true);
    // SMTP configuration
    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->Port = $smtpPort;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPass;
    $mail->SMTPSecure = ($smtpSecure === 'ssl')
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;

    // Encoding & HTML
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';
    $mail->isHTML(true);

    // Sender and recipients
    $mail->setFrom($mailFrom, $mailFromName);
    // Send to the customer
    $mail->addAddress($email, $name);
    // Send a copy to default recipient (internal mailbox)
    if ($mailToDefault) {
        $mail->addAddress($mailToDefault);
    }

    // Subject
    // Pour un rendez-vous confirmé, utiliser un intitulé clair
    $mail->Subject = "Rendez-vous confirmé - RIPAIR";

    // Préparer des chaînes échappées
    $escName     = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $escDevice   = $device ? htmlspecialchars($device, ENT_QUOTES, 'UTF-8') : '';
    $escProblem  = $problem ? htmlspecialchars($problem, ENT_QUOTES, 'UTF-8') : '';
    $escTag      = isset($data['tag']) ? htmlspecialchars($data['tag'], ENT_QUOTES, 'UTF-8') : '';
    $priceLine   = ($price !== '' && is_numeric($price)) ? number_format((float)$price, 2, ',', ' ') . ' €' : '';
    $discountLine = ($discountPct && is_numeric($discountPct) && (float)$discountPct > 0)
        ? htmlspecialchars($discountPct, ENT_QUOTES, 'UTF-8') . '%' : '';
    $escStore    = $store ? htmlspecialchars($store, ENT_QUOTES, 'UTF-8') : '';
    $escAddress  = $address ? htmlspecialchars($address, ENT_QUOTES, 'UTF-8') : '';

    // Joindre les images (logo, illustration et icônes)
    $logoPath = __DIR__ . '/../assets/img/logoweb.png';
    if (file_exists($logoPath)) {
        $mail->addEmbeddedImage($logoPath, 'logoRipair', 'logo.png');
    }
    // Hero spécifique pour la confirmation de rendez-vous
    $heroConfirm = __DIR__ . '/../assets/img/hero_confirm.png';
    if (file_exists($heroConfirm)) {
        $mail->addEmbeddedImage($heroConfirm, 'heroConfirm', 'hero_confirm.png');
    }
    // Icônes garanties/moderne : versions PNG transparentes aux couleurs RIPAIR
    $icoShield = __DIR__ . '/../assets/img/icon_shield.png';
    if (file_exists($icoShield)) {
        $mail->addEmbeddedImage($icoShield, 'icoShield', 'icon_shield.png');
    }
    $icoWrench = __DIR__ . '/../assets/img/icon_wrench.png';
    if (file_exists($icoWrench)) {
        $mail->addEmbeddedImage($icoWrench, 'icoWrench', 'icon_wrench.png');
    }
    $icoPrice = __DIR__ . '/../assets/img/icon_price.png';
    if (file_exists($icoPrice)) {
        $mail->addEmbeddedImage($icoPrice, 'icoPrice', 'icon_price.png');
    }
    // Icônes de réseaux sociaux (versions PNG avec fond transparent)
    $socFacebook = __DIR__ . '/../assets/img/facebook.png';
    if (file_exists($socFacebook)) {
        $mail->addEmbeddedImage($socFacebook, 'socFacebook', 'facebook.png');
    }
    $socInstagram = __DIR__ . '/../assets/img/instagram.png';
    if (file_exists($socInstagram)) {
        $mail->addEmbeddedImage($socInstagram, 'socInstagram', 'instagram.png');
    }

    // Calculer le total après remise et l'économie
    $calcTotal       = '';
    $calcTotalAmount = 0.0; // montant remisé utilisé pour l'affichage e-mail / texte
    $saveAmount      = '';
    $basePrice       = 0.0; // prix de référence avant remise (utilisé pour le PDF)
    if ($price !== '' && is_numeric($price)) {
        $basePrice       = (float)$price;
        $discountRate    = ($discountPct && is_numeric($discountPct)) ? ((float)$discountPct) : 0;
        $calcTotalAmount = $basePrice;
        if ($discountRate > 0) {
            $reduction       = round($basePrice * $discountRate / 100, 2);
            $calcTotalAmount = $basePrice - $reduction;
            $saveAmount      = number_format($reduction, 2, ',', ' ') . ' €';
        }
        $calcTotal = number_format($calcTotalAmount, 2, ',', ' ') . ' €';
    }

    // Générer un lien d'annulation. On encode l'email et la date/heure ISO.
    $cancelUrl = '';
        if ($cancelAllowed && $cancelToken !== '') {
            $baseCancelUrl = env('CANCEL_URL_BASE', 'https://ripair.shop/api/cancel_appointment.php');
            $sep = (strpos($baseCancelUrl, '?') === false) ? '?' : '&';
            $cancelUrl = rtrim($baseCancelUrl, '&?') . $sep . 'token=' . urlencode($cancelToken);
        }
        // Définir une description pour le devis SumUp
        $descriptionForSumUp = trim(
            ($device ? ('Réparation ' . $device) : 'Réparation')
            . ($problem ? (' - ' . $problem) : '')
    );


    // Construire l'email de confirmation inspiré du modèle "Rendez-vous confirmé"
    $htmlBody = "<html><body style='background-color:#f5f7fb;padding:20px 0;font-family:Segoe UI,Roboto,sans-serif;'>"
      . "<table align='center' width='100%' cellpadding='0' cellspacing='0' style='max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;'>"
      . "<tr><td style='padding:20px;text-align:center;background-color:#ffffff;'>"
      . "<img src='cid:logoRipair' alt='RIPAIR' style='width:90px;height:auto;margin-bottom:6px;'>"
      . "<h1 style='color:#3abafc;font-size:26px;margin:6px 0;'>Rendez-vous confirmé</h1>"
      . "<p style='margin:8px 0 20px;color:#333;font-size:14px;'>Bonjour <strong>{$escName}</strong>, nos experts vous attendent !</p>"
      . "<img src='cid:heroConfirm' alt='' style='width:220px;height:auto;margin:0 auto 20px;display:block;'>"
      . "</td></tr>"

      . "<tr><td style='padding:20px 20px 10px;'>"
      . "<table width='100%' cellpadding='0' cellspacing='0' style='background:#f5f7fb;border-radius:8px;padding:12px;'>"
      . "<tr><td style='width:50%;vertical-align:top;'>"
      . "<table cellpadding='0' cellspacing='0' style='width:100%;'>"
      . "<tr>"
      . "<td style='vertical-align:top;width:24px;'><span style='font-size:20px;margin-right:6px;'>📍</span></td>"
      . "<td style='vertical-align:top;'><strong>{$escStore}</strong><br><span style='color:#555;font-size:13px;'>{$escAddress}</span></td>"
      . "</tr>"
      . "</table>"
      . "</td>"
      . "<td style='width:50%;vertical-align:top;'>"
      . "<table cellpadding='0' cellspacing='0' style='width:100%;'>"
      . "<tr>"
      . "<td style='vertical-align:top;width:24px;'><span style='font-size:20px;margin-right:6px;'>🗓️</span></td>"
      . "<td style='vertical-align:top;'><strong>{$dateFr}</strong><br><span style='color:#555;font-size:13px;'>Votre rendez-vous</span></td>"
      . "</tr>"
      . "</table>"
      . "</td></tr>"
      . "</table>"
      . "</td></tr>"

      . "<tr><td style='padding:20px;'>"
      . "<h3 style='color:#333;margin-top:0;font-size:18px;'>Récapitulatif du devis" . ($escTag ? " n° {$escTag}" : "") . "</h3>"
      . "<table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;font-size:14px;color:#333;margin-bottom:12px;'>"
      . ($escDevice ? "<tr><td style='padding:4px 0;width:40%;color:#888;'>Appareil</td><td style='padding:4px 0;'>{$escDevice}</td></tr>" : "")
      . ($escProblem ? "<tr><td style='padding:4px 0;width:40%;color:#888;'>Problème(s)</td><td style='padding:4px 0;'>{$escProblem}</td></tr>" : "")
      . ($priceLine ? "<tr><td style='padding:4px 0;width:40%;color:#888;'>Montant initial</td><td style='padding:4px 0;'>{$priceLine}</td></tr>" : "")
      . ($discountLine && $priceLine ? "<tr><td style='padding:4px 0;width:40%;color:#888;'>Remise</td><td style='padding:4px 0;'>- {$discountLine}</td></tr>" : "")
      . ($calcTotal ? "<tr><td style='padding:4px 0;width:40%;font-weight:bold;'>Total estimé</td><td style='padding:4px 0;font-weight:bold;'>" . ($discountLine ? "<span style='text-decoration:line-through;color:#888;'>{$priceLine}</span> <span style='color:#3abafc;'>{$calcTotal}</span>" : "{$calcTotal}") . "</td></tr>" : "")
      . "</table>"
      . ($saveAmount ? "<p style='color:#3abafc;font-weight:bold;margin:8px 0;'>Vous économisez {$saveAmount}</p>" : "")
      . "</td></tr>"

      . "<tr><td style='padding:20px;'>"
      . "<div style='background:#fff7e6;border:1px solid #ffe4b5;border-radius:8px;padding:12px;color:#995500;font-weight:600;font-size:14px;text-align:center;'>"
      . "Veuillez noter que nous devons commander certaines pièces pour votre réparation. Il faut compter un délai de 3 à 5 jours pour recevoir ces pièces en magasin. Une fois la pièce commandée, il n’est plus possible d’annuler le rendez-vous."
      . "</div>"
      . "</td></tr>"

        . ($cancelUrl ? "<tr><td style='text-align:center;padding:20px;'>"
        . "<p style='margin:0 0 12px;font-size:14px;color:#333;'>Vous pouvez annuler votre rendez-vous tant que la pièce n'est pas commandée."
        . ($cancelExpiryFr ? "<br><span style='font-size:13px;color:#6f7c8f;'>Lien valable jusqu’au {$cancelExpiryFr} (heure de Paris).</span>" : "")
        . "</p>"
        . "<a href='{$cancelUrl}' style='display:inline-block;background-color:#ff4b4b;color:#ffffff;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:600;font-size:16px;'>Annuler le rendez-vous</a>"
        . "</td></tr>" : "")
        . "<tr><td style='text-align:center;padding:0 20px 20px;'>"
        . "<a href='https://maps.app.goo.gl/ELQWgA273JBZpVF2A' style='display:inline-block;background-color:#3abafc;color:#ffffff;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:600;font-size:16px;'>Voir l'emplacement du rendez-vous</a>"
        . "</td></tr>"

      . "<tr><td style='padding:20px;background-color:#f5f7fb;'>"
      . "<table width='100%' cellpadding='0' cellspacing='0' style='text-align:center;'>"
      . "<tr>"
      . "<td style='width:33%;padding:10px;'>"
      . "<img src='cid:icoShield' alt='' style='width:50px;height:auto;margin-bottom:6px;'>"
      . "<div style='font-weight:600;color:#333;'>Garantie 1 an</div>"
      . "</td>"
      . "<td style='width:33%;padding:10px;'>"
      . "<img src='cid:icoWrench' alt='' style='width:50px;height:auto;margin-bottom:6px;'>"
      . "<div style='font-weight:600;color:#333;'>Réparation rapide</div>"
      . "</td>"
      . "<td style='width:33%;padding:10px;'>"
      . "<img src='cid:icoPrice' alt='' style='width:50px;height:auto;margin-bottom:6px;'>"
      . "<div style='font-weight:600;color:#333;'>Prix bas</div>"
      . "</td>"
      . "</tr>"
      . "</table>"
      . "</td></tr>"

      . "<tr><td style='background:#ffffff;padding:20px;text-align:center;'>"
      . "<p style='font-size:14px;color:#333;margin-bottom:15px;'>Nous avons hâte de vous redonner le sourire ! À très vite !</p>"
      . "<div style='margin-bottom:15px;'>"
      . "<a href='https://www.facebook.com/ripair.shop' style='margin:0 5px;'><img src='cid:socFacebook' alt='Facebook' style='width:24px;height:24px;'></a>"
      . "<a href='https://www.instagram.com/ripair.shop' style='margin:0 5px;'><img src='cid:socInstagram' alt='Instagram' style='width:24px;height:24px;'></a>"
      . "</div>"
      . "<p style='font-size:12px;color:#aaa;margin:0;'>&copy; 2025 RIPAIR — Tous droits réservés.</p>"
      . "</td></tr>"

      . "<tr><td style='background:#f5f7fb;padding:10px;text-align:center;font-size:11px;color:#999;'>"
      . "Cet e-mail a été envoyé automatiquement depuis <strong>ripair.shop</strong>."
      . "</td></tr>"

      . "</table>"
      . "</body></html>";
    $mail->Body = $htmlBody;

    // Plain text version
    $textLines = [];
    $textLines[] = 'Votre rendez-vous est confirmé !';
    if ($escDevice)   $textLines[] = 'Appareil : ' . $escDevice;
    if ($escProblem)  $textLines[] = 'Problème(s) : ' . $escProblem;
    $textLines[] = 'Date et heure : ' . $dateFr;
    if ($priceLine)   $textLines[] = 'Montant initial : ' . $priceLine;
    if ($discountLine) $textLines[] = 'Remise : ' . $discountLine;
    if ($calcTotal)   $textLines[] = 'Total estimé : ' . $calcTotal;
    if ($saveAmount)  $textLines[] = 'Vous économisez ' . $saveAmount;
    if ($escStore)    $textLines[] = 'Boutique : ' . $escStore;
    if ($escAddress)  $textLines[] = 'Adresse : ' . $escAddress;
     $textLines[] = 'Nous devons commander certaines pièces pour votre réparation. Comptez un délai de 3 à 5 jours pour les recevoir. Une fois la pièce commandée, l’annulation du rendez-vous n’est plus possible.';
    if ($cancelUrl) {
        $textLines[] = 'Pour annuler votre rendez-vous (avant commande des pièces) : ' . $cancelUrl;
        if ($cancelExpiryFr) {
            $textLines[] = 'Lien valable jusqu’au ' . $cancelExpiryFr . ' (heure de Paris).';
        }
    }
    // Lien vers la carte Google
    $textLines[] = 'Voir l’emplacement du rendez-vous : https://maps.app.goo.gl/ELQWgA273JBZpVF2A';
    $textLines[] = 'Nous avons hâte de vous accueillir !';
    $textLines[] = '--';
    $textLines[] = 'Cet e-mail est envoyé automatiquement depuis ripair.shop.';
    $mail->AltBody = implode("\n", $textLines);

    // Déterminer le prix de référence (avant remise) à utiliser dans le PDF
    $pdfBasePrice = $basePrice;
    if ($pdfBasePrice <= 0 && is_array($details) && !empty($details)) {
        $tmp = 0.0;
        foreach ($details as $item) {
            if (isset($item['price']) && is_numeric($item['price'])) {
                $tmp += (float)$item['price'];
            }
        }
        if ($tmp > 0) {
            $pdfBasePrice = $tmp;
        }
    }
    if ($pdfBasePrice <= 0 && $calcTotalAmount > 0) {
        $pdfBasePrice = $calcTotalAmount;
    }

    // Générez le devis PDF manuellement et joignez-le s'il est disponible
    if ($pdfBasePrice > 0) {
        try {
            // Utiliser une fonction interne pour fabriquer un devis PDF simple
            $pdfPath = generateManualQuotePdf(
                $escName,
                $dateFr,
                $device,
                $problem,
                (float)$pdfBasePrice,
                (float)$discountPct,
                $escStore,
                $escAddress,
                '',
                (is_array($details) ? $details : []),
                ((isset($durationMinParam) && is_numeric($durationMinParam)) ? (float)$durationMinParam : 0.0)
            );
            if ($pdfPath) {
                $mail->addAttachment($pdfPath, 'devis-ripair.pdf');
            }
        } catch (\Throwable $ex) {
            // Ignore silently — PDF non attaché si erreur
        }
    }

    // Send email
    $mail->send();

    $response = ['success' => true, 'client_phone_input' => $phoneInput, 'client_phone_used' => $phone, 'client_phone_fallback' => ($phoneInput === '' && $phone !== '')];
    $totalForSms = $calcTotalAmount > 0 ? number_format($calcTotalAmount, 2, ',', ' ') . ' €' : '';
    $dateForSms  = isset($dt) ? $dt->format('d/m/Y H\hi') : '';
    $storeLabel  = $store !== '' ? $store : 'RIPAIR';
    $addressLabel = $address !== '' ? $address : '';

    // SMS vers le client
    if ($phone !== '') {
        $firstName = trim($name);
        if ($firstName !== '') {
            $parts = preg_split('/\s+/', $firstName);
            if ($parts && isset($parts[0]) && $parts[0] !== '') {
                $firstName = $parts[0];
            }
        }
        $clientSmsLines = [];
        $clientSmsLines[] = 'RIPAIR - Confirmation RDV';
        $clientSmsLines[] = 'Bonjour ' . ($firstName !== '' ? $firstName : 'cher client') . ',';
        if ($dateForSms !== '') {
            $clientSmsLines[] = 'Date : ' . $dateForSms;
        }
        $locationParts = array_filter([$storeLabel, $addressLabel]);
        if (!empty($locationParts)) {
            $clientSmsLines[] = 'Lieu : ' . implode(' - ', $locationParts);
        }
        if ($totalForSms !== '') {
            $clientSmsLines[] = 'Total estimé : ' . $totalForSms;
        }
        if ($cancelAllowed && $cancelUrl !== '') {
            $clientSmsLines[] = 'Annulation : ' . $cancelUrl;
            if ($cancelExpiryFr) {
                $clientSmsLines[] = "Lien valable jusqu'au " . $cancelExpiryFr . '.';
            }
        }
        $clientSmsLines[] = "À bientôt, l'équipe RIPAIR.";
        $clientSmsMessage = implode("\n", array_filter($clientSmsLines));
        $clientSmsResult = sendSmsMessage($phone, $clientSmsMessage);
        if (!$clientSmsResult['sent']) {
            error_log('[sms] client SMS failed: ' . ($clientSmsResult['error'] ?? 'unknown'));
        }
        $response['client_sms'] = $clientSmsResult;
    }

    // SMS de notification interne (plusieurs destinataires possibles séparés par , ;)
    $adminRecipients = getInternalSmsRecipients($pdo ?? null);
    if (!empty($adminRecipients)) {
        $adminLines = [];
        $adminLines[] = 'RIPAIR - Nouveau RDV';
        if ($name !== '' || $phone !== '') {
            $details = trim($name . ' ' . ($phone !== '' ? '(' . $phone . ')' : ''));
            if ($details !== '') {
                $adminLines[] = 'Client : ' . $details;
            }
        }
        if ($dateForSms !== '') {
            $adminLines[] = 'Date : ' . $dateForSms;
        }
        if ($storeLabel !== '' || $addressLabel !== '') {
            $adminLines[] = 'Lieu : ' . implode(' - ', array_filter([$storeLabel, $addressLabel]));
        }
        if ($device !== '' || $problem !== '') {
            $adminLines[] = 'Détails : ' . trim(($device ? $device : '') . ($problem ? ' – ' . $problem : ''));
        }
        if ($totalForSms !== '') {
            $adminLines[] = 'Total estimé : ' . $totalForSms;
        }
        $adminMessage = implode("\n", array_filter($adminLines));

        $adminResults = [];
        foreach ($adminRecipients as $adminRecipient) {
            $result = sendSmsMessage($adminRecipient, $adminMessage);
            if (!$result['sent']) {
                error_log('[sms] admin SMS failed (' . $adminRecipient . '): ' . ($result['error'] ?? 'unknown'));
            }
            $adminResults[] = $result;
        }
        if (!empty($adminResults)) {
            $response['admin_sms'] = $adminResults;
        }
    }

    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Mailer Error: ' . $mail->ErrorInfo]);
}



