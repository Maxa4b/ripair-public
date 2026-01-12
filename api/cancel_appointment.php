<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/sms_helpers.php';

header('Content-Type: text/html; charset=utf-8');


function notifyCancellation(array $appointment, \PDO $pdo): void
{
    $tz = new DateTimeZone('Europe/Paris');
    $startLabel = '';
    if (!empty($appointment['start_datetime'])) {
        try {
            $start = new DateTimeImmutable($appointment['start_datetime'], $tz);
            $startLabel = $start->format('d/m/Y H\hi');
        } catch (Throwable $e) {
            $startLabel = $appointment['start_datetime'];
        }
    }

    $serviceLabel = trim((string)($appointment['service_label'] ?? ''));
    if ($serviceLabel === '') {
        $serviceLabel = 'Rendez-vous RIPAIR';
    }
    $clientName = trim((string)($appointment['customer_name'] ?? ''));
    $clientPhone = trim((string)($appointment['customer_phone'] ?? ''));

    if ($clientPhone !== '') {
        $lines = [
            'RIPAIR - Annulation RDV',
            $clientName !== '' ? ('Bonjour ' . $clientName . ',') : 'Bonjour,',
        ];
        if ($startLabel !== '') {
            $lines[] = 'Votre rendez-vous du ' . $startLabel . ' est annulé.';
        } else {
            $lines[] = 'Votre rendez-vous est annulé.';
        }
        if ($serviceLabel !== '') {
            $lines[] = 'Service : ' . $serviceLabel;
        }
        $lines[] = 'Contactez-nous pour reprogrammer via ripair.shop.';
        $lines[] = 'Merci pour votre confiance.';

        $message = implode("\n", $lines);
        $smsResult = sendSmsMessage($clientPhone, $message);
        if (!$smsResult['sent']) {
            error_log('[sms] cancel client SMS failed: ' . ($smsResult['error'] ?? 'unknown'));
        }
    }

    $adminRecipients = getInternalSmsRecipients($pdo);
    if (!empty($adminRecipients)) {
        $lines = [
            'RIPAIR - RDV annulé',
        ];
        if ($startLabel !== '') {
            $lines[] = 'Date : ' . $startLabel;
        }
        if ($serviceLabel !== '') {
            $lines[] = 'Service : ' . $serviceLabel;
        }
        if ($clientName !== '' || $clientPhone !== '') {
            $clientDetails = trim($clientName . ' ' . ($clientPhone !== '' ? '(' . $clientPhone . ')' : ''));
            if ($clientDetails !== '') {
                $lines[] = 'Client : ' . $clientDetails;
            }
        }
        $lines[] = 'Source : annulation en ligne.';
        $message = implode("\n", $lines);

        foreach ($adminRecipients as $recipient) {
            $result = sendSmsMessage($recipient, $message);
            if (!$result['sent']) {
                error_log('[sms] cancel admin SMS failed (' . $recipient . '): ' . ($result['error'] ?? 'unknown'));
            }
        }
    }
}

/**
 * Rend une page HTML harmonisée pour l'annulation (succès/erreur).
 */
function render_cancel_page(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);

    $accent  = $data['accent'] ?? '#3abafc';
    $tone    = $data['tone'] ?? 'info'; // success | error | info
    $title   = htmlspecialchars($data['title'] ?? 'Information', ENT_QUOTES, 'UTF-8');
    $message = $data['message'] ?? '';
    $note    = $data['note'] ?? '';
    $button  = $data['button'] ?? ['label' => 'Revenir au site', 'href' => 'https://ripair.shop/'];
    $reasons = $data['reasons'] ?? [];

    $icons = [
        'success' => '<path d="M12 22s8-4 8-10V5L12 2 4 5v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
        'error'   => '<path d="M12 22s8-4 8-10V5L12 2 4 5v7c0 6 8 10 8 10z"/><path d="m9 9 6 6"/><path d="m15 9-6 6"/>',
        'info'    => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>'
    ];
    $iconSvg = $icons[$tone] ?? $icons['info'];

    $buttonLabel = htmlspecialchars($button['label'] ?? 'Revenir au site', ENT_QUOTES, 'UTF-8');
    $buttonHref  = htmlspecialchars($button['href'] ?? 'https://ripair.shop/', ENT_QUOTES, 'UTF-8');

    $reasonsHtml = '';
    if (!empty($reasons) && is_array($reasons)) {
        $items = array_map(
            fn ($reason) => '<li>' . htmlspecialchars((string)$reason, ENT_QUOTES, 'UTF-8') . '</li>',
            $reasons
        );
        $reasonsHtml = '<ul class="reason-list">' . implode('', $items) . '</ul>';
    }

    $badgeClass = 'badge';
    if ($tone === 'success') $badgeClass .= ' badge--success';
    if ($tone === 'error')   $badgeClass .= ' badge--error';

    echo "<!doctype html><html lang='fr'><head><meta charset='utf-8'><title>{$title} - RIPAIR</title>"
        . "<meta name='viewport' content='width=device-width, initial-scale=1'/>"
        . "<style>
            :root{color-scheme:light;}
            body{margin:0;padding:48px 16px;font-family:'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;
                 background:linear-gradient(145deg,#f5f7fb 0%,#e8f2ff 100%);}
            .card{max-width:640px;margin:0 auto;background:#ffffff;border-radius:28px;padding:36px;
                  box-shadow:0 30px 80px rgba(27,80,129,0.12);}
            .badge{display:inline-flex;align-items:center;justify-content:center;width:76px;height:76px;
                   border-radius:24px;margin-bottom:24px;background:rgba(58,186,252,0.12);color:{$accent};}
            .badge--success{background:rgba(52,211,153,0.14);color:#16a34a;}
            .badge--error{background:rgba(255,75,75,0.14);color:#ff4b4b;}
            h1{margin:0 0 14px;font-size:30px;line-height:1.25;color:#0b172b;}
            p{margin:0 0 18px;font-size:16px;color:#4b5563;}
            .note{margin-top:12px;font-size:15px;color:#6b7280;}
            .btn{display:inline-flex;align-items:center;gap:10px;margin-top:26px;padding:14px 24px;
                 border-radius:999px;background:{$accent};color:#ffffff;font-weight:600;text-decoration:none;
                 box-shadow:0 18px 36px rgba(58,186,252,0.24);transition:transform .12s ease, box-shadow .12s ease;}
            .btn:hover{transform:translateY(-1px);box-shadow:0 22px 44px rgba(58,186,252,0.28);}
            .btn svg{width:20px;height:20px;}
            .reason-list{margin:0 0 12px 0;padding-left:22px;color:#374151;}
            .reason-list li{margin-bottom:8px;}
        </style></head><body>";

    echo "<div class='card'>"
        . "<div class='{$badgeClass}'><svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.9'"
        . " stroke-linecap='round' stroke-linejoin='round'>{$iconSvg}</svg></div>"
        . "<h1>{$title}</h1>";

    if ($message !== '') {
        echo "<p>{$message}</p>";
    }
    if ($reasonsHtml !== '') {
        echo $reasonsHtml;
    }
    if ($note !== '') {
        echo "<p class='note'>{$note}</p>";
    }

    echo "<a class='btn' href='{$buttonHref}'><svg viewBox='0 0 24 24' fill='none' stroke='currentColor'"
        . " stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'>"
        . "<polyline points='15 18 21 12 15 6'/><path d='M3 12h18'/></svg>{$buttonLabel}</a>"
        . "</div></body></html>";
    exit;
}

// Vérifie la connexion PDO
if (!isset($pdo) || !($pdo instanceof PDO)) {
    render_cancel_page([
        'accent'  => '#ff4b4b',
        'tone'    => 'error',
        'title'   => 'Erreur serveur',
        'message' => "La connexion &agrave; la base de donn&eacute;es n'a pas pu &ecirc;tre &eacute;tablie.",
        'note'    => 'Merci de réessayer plus tard ou de contacter notre support.'
    ], 500);
}

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
if ($token === '') {
    render_cancel_page([
        'accent'  => '#ff4b4b',
        'tone'    => 'error',
        'title'   => 'Lien invalide',
        'message' => "Le lien d'annulation est manquant ou incomplet."
    ], 400);
}

try {
    $hasSlotColumn = false;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'slot_id'");
        $hasSlotColumn = (bool)$col->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $ignore) {
        $hasSlotColumn = false;
    }

    $selectSql = "
        SELECT id, customer_email, customer_name, customer_phone, service_label,
               start_datetime, end_datetime, status,
               cancel_token_expires_at, cancel_token_used_at,
               part_ordered_at" . ($hasSlotColumn ? ", slot_id" : "") . "
        FROM appointments
        WHERE cancel_token = :token
        LIMIT 1
    ";
    $stmt = $pdo->prepare($selectSql);
    $stmt->execute([':token' => $token]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$appointment) {
        render_cancel_page([
            'accent'  => '#ff4b4b',
            'tone'    => 'error',
            'title'   => 'Annulation impossible',
            'message' => "Ce lien n'est plus valide ou a d&eacute;j&agrave; &eacute;t&eacute; utilis&eacute;.",
            'note'    => 'Si vous pensez qu’il s’agit d’une erreur, contactez le support RIPAIR.'
        ], 404);
    }

    $status          = strtolower((string)$appointment['status']);
    $partOrderedAt   = $appointment['part_ordered_at'] ?? null;
    $expiresAt       = $appointment['cancel_token_expires_at'] ?? null;
    $alreadyUsed     = $appointment['cancel_token_used_at'] ?? null;
    $guardMessages   = [];
    $now             = new DateTimeImmutable('now', new DateTimeZone('Europe/Paris'));

    if ($expiresAt && $now > new DateTimeImmutable($expiresAt)) {
        $guardMessages[] = "Le d&eacute;lai d'annulation en ligne est d&eacute;pass&eacute;.";
    }
    if ($partOrderedAt) {
        $guardMessages[] = "La pi&egrave;ce a d&eacute;j&agrave; &eacute;t&eacute; command&eacute;e pour cette intervention.";
    }
    if ($status !== 'booked') {
        $guardMessages[] = "Ce rendez-vous n'est plus actif.";
    }
    if ($alreadyUsed) {
        $guardMessages[] = "Le lien d'annulation a d&eacute;j&agrave; &eacute;t&eacute; utilis&eacute;.";
    }

    if (!empty($guardMessages)) {
        render_cancel_page([
            'accent'  => '#ff4b4b',
            'tone'    => 'error',
            'title'   => 'Annulation impossible',
            'message' => "Ce rendez-vous ne peut plus &ecirc;tre annul&eacute; en ligne.",
            'note'    => 'Merci de contacter directement RIPAIR pour toute assistance suppl&eacute;mentaire.',
            'reasons' => $guardMessages
        ], 403);
    }

    $pdo->beginTransaction();

    $upd = $pdo->prepare("
        UPDATE appointments
        SET status = 'cancelled',
            cancelled_at = NOW(),
            cancel_token_used_at = NOW(),
            cancel_token = NULL,
            cancel_token_expires_at = NULL
        WHERE id = :id
    ");
    $upd->execute([':id' => $appointment['id']]);

    if ($hasSlotColumn) {
        $slotId = isset($appointment['slot_id']) ? (int)$appointment['slot_id'] : 0;
        if ($slotId > 0) {
            try {
                $slotStmt = $pdo->prepare("UPDATE slots SET is_booked = 0 WHERE id = :id");
                $slotStmt->execute([':id' => $slotId]);
            } catch (Throwable $ignore) {
                // ignore if table/constraint absent
            }
        }
    }

    $pdo->commit();

    try {
        notifyCancellation($appointment, $pdo);
    } catch (Throwable $notifyError) {
        error_log('[cancel_appointment][notify] ' . $notifyError->getMessage());
    }

    render_cancel_page([
        'accent'  => '#3abafc',
        'tone'    => 'success',
        'title'   => 'Rendez-vous annulé',
        'message' => 'Votre rendez-vous a bien été annulé. Nous espérons vous revoir bientôt.',
        'note'    => 'Un e-mail de confirmation vient de vous être envoyé. Merci pour votre confiance.'
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[cancel_appointment] ' . $e->getMessage());
    render_cancel_page([
        'accent'  => '#ff4b4b',
        'tone'    => 'error',
        'title'   => 'Erreur serveur',
        'message' => 'Une erreur est survenue lors de l’annulation de votre rendez-vous.',
        'note'    => 'Veuillez réessayer plus tard ou contacter notre support.'
    ], 500);
}
