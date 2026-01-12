<?php
declare(strict_types=1);

if (!function_exists('sms_log')) {
    function sms_log(string $message): void
    {
        error_log('[sms] ' . $message);
    }
}

if (!function_exists('normalizePhoneForSms')) {
    function normalizePhoneForSms(string $raw, string $defaultCountryCode = '+33'): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $clean = str_replace([' ', '.', '-', '_', '(', ')'], '', $raw);
        if ($clean === '') {
            return '';
        }

        if ($clean[0] === '+') {
            $digits = preg_replace('/[^0-9]/', '', substr($clean, 1));
            return ($digits !== '') ? '+' . $digits : '';
        }

        if (strpos($clean, '00') === 0) {
            $digits = preg_replace('/[^0-9]/', '', substr($clean, 2));
            return ($digits !== '') ? '+' . $digits : '';
        }

        $digits = preg_replace('/[^0-9]/', '', $clean);
        if ($digits === '') {
            return '';
        }
        if ($clean[0] === '0' && strlen($digits) >= 9) {
            $local = substr($digits, 1);
            $prefix = $defaultCountryCode !== '' ? $defaultCountryCode : '+';
            if ($prefix[0] !== '+') {
                $prefix = '+' . $prefix;
            }
            return $prefix . $local;
        }

        $prefix = $defaultCountryCode !== '' ? $defaultCountryCode : '+';
        if ($prefix[0] !== '+') {
            $prefix = '+' . $prefix;
        }
        return $prefix . $digits;
    }
}

if (!function_exists('sendSmsMessage')) {
    /**
     * @return array{sent:bool,to?:string,error?:string,status?:int,twilio_error?:string,twilio_code?:mixed,twilio_info?:string}
     */
    function sendSmsMessage(string $recipient, string $message): array
    {
        $recipient = trim($recipient);
        $message   = trim($message);

        if ($recipient === '' || $message === '') {
            sms_log('Abort: missing number or message');
            return ['sent' => false, 'error' => 'missing_parameters'];
        }

        if (!function_exists('curl_init')) {
            sms_log('cURL extension not available, trying stream fallback');
        }

        static $config = null;
        if ($config === null) {
            $config = [
                'sid'                   => env('TWILIO_ACCOUNT_SID', $_ENV['TWILIO_ACCOUNT_SID'] ?? ''),
                'token'                 => env('TWILIO_AUTH_TOKEN', $_ENV['TWILIO_AUTH_TOKEN'] ?? ''),
                'from'                  => env('TWILIO_FROM', $_ENV['TWILIO_FROM'] ?? ''),
                'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID', $_ENV['TWILIO_MESSAGING_SERVICE_SID'] ?? ''),
                'country'               => env('SMS_DEFAULT_COUNTRY_CODE', $_ENV['SMS_DEFAULT_COUNTRY_CODE'] ?? '+33'),
            ];
        }

        if ($config['sid'] === '' || $config['token'] === '' || ($config['from'] === '' && $config['messaging_service_sid'] === '')) {
            sms_log('Abort: Twilio configuration missing.');
            return ['sent' => false, 'error' => 'missing_config'];
        }

        $normalized = normalizePhoneForSms($recipient, $config['country'] ?: '+33');
        if ($normalized === '') {
            sms_log('Abort: invalid number ' . $recipient);
            return ['sent' => false, 'error' => 'invalid_number'];
        }

        $payloadFields = [
            'To'   => $normalized,
            'Body' => $message,
        ];

        if ($config['messaging_service_sid'] !== '') {
            $payloadFields['MessagingServiceSid'] = $config['messaging_service_sid'];
        } else {
            $payloadFields['From'] = $config['from'];
        }

        $url = sprintf(
            'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json',
            rawurlencode($config['sid'])
        );

        $payload = http_build_query($payloadFields, '', '&');

        if (!function_exists('curl_init')) {
            $fallback = sendSmsMessageStreamFallback($url, $payload, $config, $normalized);
            if ($fallback !== null) {
                return $fallback;
            }
            return ['sent' => false, 'error' => 'curl_not_available'];
        }

        sms_log('Attempt via cURL to ' . $normalized);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_USERPWD        => $config['sid'] . ':' . $config['token'],
            CURLOPT_TIMEOUT        => 10,
        ]);

        $responseBody = curl_exec($ch);
        $curlErr      = curl_error($ch);
        $statusCode   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decodedBody = null;
        if ($responseBody !== false && $responseBody !== null) {
            $decodedBody = json_decode($responseBody, true);
        }

        if ($responseBody === false || $curlErr) {
            sms_log('cURL transport error: ' . $curlErr);
            $twilioError = is_array($decodedBody) ? ($decodedBody['message'] ?? $decodedBody['error_message'] ?? '') : '';
            return [
                'sent'         => false,
                'error'        => 'transport_error',
                'status'       => $statusCode,
                'twilio_error' => $twilioError,
            ];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $twilioMsg  = '';
            $twilioCode = null;
            $twilioMore = '';
            if (is_array($decodedBody)) {
                $twilioMsg  = $decodedBody['message'] ?? $decodedBody['error_message'] ?? '';
                $twilioCode = $decodedBody['code'] ?? null;
                $twilioMore = $decodedBody['more_info'] ?? '';
            }
            sms_log('Twilio responded with status ' . $statusCode . ' code ' . ($twilioCode ?? 'n/a') . ' message: ' . $twilioMsg . ' more: ' . $twilioMore);
            return [
                'sent'         => false,
                'error'        => 'provider_error',
                'status'       => $statusCode,
                'twilio_error' => $twilioMsg,
                'twilio_code'  => $twilioCode,
                'twilio_info'  => $twilioMore,
            ];
        }

        $sid = is_array($decodedBody) ? ($decodedBody['sid'] ?? null) : null;
        sms_log('SMS envoyé avec succès via cURL vers ' . $normalized . ' (SID: ' . ($sid ?? 'n/a') . ')');
        return ['sent' => true, 'to' => $normalized, 'sid' => $sid];
    }
}

if (!function_exists('getHelixInternalSmsRecipients')) {
    function getHelixInternalSmsRecipients(?\PDO $connection = null): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        if ($connection === null) {
            global $pdo;
            if (isset($pdo) && $pdo instanceof \PDO) {
                $connection = $pdo;
            }
        }

        if (!$connection instanceof \PDO) {
            return $cached = [];
        }

        try {
            $stmt = $connection->prepare('SELECT value FROM helix_settings WHERE `key` = :key LIMIT 1');
            $stmt->execute([':key' => 'notifications.internal_sms']);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row || !isset($row['value'])) {
                return $cached = [];
            }

            $data = json_decode($row['value'], true);
            if (!is_array($data) || empty($data['enabled'])) {
                return $cached = [];
            }

            $numbers = [];
            if (!empty($data['numbers'])) {
                $candidates = is_array($data['numbers']) ? $data['numbers'] : [$data['numbers']];
                foreach ($candidates as $number) {
                    $normalized = '';
                    if (is_array($number)) {
                        $flags = ['enabled', 'active', 'checked', 'selected'];
                        $skip = false;
                        foreach ($flags as $flag) {
                            if (array_key_exists($flag, $number)) {
                                $flagVal = $number[$flag];
                                $isFalse = is_bool($flagVal) ? ($flagVal === false) : !filter_var($flagVal, FILTER_VALIDATE_BOOL);
                                if ($isFalse) {
                                    $skip = true;
                                    break;
                                }
                            }
                        }
                        if ($skip) {
                            continue;
                        }
                        $candidateValue = $number['value'] ?? $number['number'] ?? $number['phone'] ?? $number['contact'] ?? '';
                        $normalized = trim((string) $candidateValue);
                    } else {
                        $normalized = trim((string) $number);
                    }
                    if ($normalized !== '') {
                        $numbers[] = $normalized;
                   }
                }
            }

            return $cached = $numbers;
        } catch (\Throwable $e) {
            sms_log('Impossible de charger helix_settings : ' . $e->getMessage());
            return $cached = [];
        }
    }
}

if (!function_exists('getInternalSmsRecipients')) {
    function getInternalSmsRecipients(?\PDO $connection = null): array
    {
        $recipients = [];

        foreach (getHelixInternalSmsRecipients($connection) as $helixRecipient) {
            $recipients[] = $helixRecipient;
        }

        if (empty($recipients)) {
            return [];
        }

        $unique = [];
        foreach ($recipients as $recipient) {
            $unique[$recipient] = true;
        }

        return array_keys($unique);
    }
}

if (!function_exists('sendSmsMessageStreamFallback')) {
    function sendSmsMessageStreamFallback(string $url, string $payload, array $config, string $normalized): ?array
    {
        if (!in_array('ssl', stream_get_transports(), true)) {
            sms_log('Stream fallback indisponible (transport SSL absent)');
            return null;
        }

        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode($config['sid'] . ':' . $config['token']),
        ];

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => implode("\r\n", $headers),
                'content' => $payload,
                'timeout' => 12,
            ],
        ]);

        sms_log('Attempt via stream fallback');

        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            $error = error_get_last();
            sms_log('Stream fallback échec : ' . ($error['message'] ?? 'unknown error'));
            return null;
        }

        $statusCode = 0;
        if (!empty($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $statusCode = (int)$m[1];
        }
        $decodedBody = json_decode($responseBody, true);

        if ($statusCode >= 200 && $statusCode < 300) {
            $sid = is_array($decodedBody) ? ($decodedBody['sid'] ?? null) : null;
            sms_log('SMS envoyé via stream fallback vers ' . $normalized . ' (status ' . $statusCode . ', SID: ' . ($sid ?? 'n/a') . ')');
            return ['sent' => true, 'to' => $normalized, 'sid' => $sid];
        }

        $twilioMsg  = is_array($decodedBody) ? ($decodedBody['message'] ?? $decodedBody['error_message'] ?? '') : '';
        $twilioCode = is_array($decodedBody) ? ($decodedBody['code'] ?? null) : null;
        $twilioInfo = is_array($decodedBody) ? ($decodedBody['more_info'] ?? '') : '';
        sms_log('Stream fallback Twilio error: status ' . $statusCode . ' message ' . $twilioMsg);

        return [
            'sent'         => false,
            'error'        => 'provider_error',
            'status'       => $statusCode,
            'twilio_error' => $twilioMsg,
            'twilio_code'  => $twilioCode,
            'twilio_info'  => $twilioInfo,
        ];
    }
}
