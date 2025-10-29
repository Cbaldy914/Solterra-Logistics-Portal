<?php

class Mailer {
    public static function send(string $toEmail, string $subject, string $body, ?string $fromEmail = null, ?string $fromName = null): bool {
        $cfg = $GLOBALS['config']['MAIL'] ?? [];
        if (!($cfg['enabled'] ?? false)) return false;

        $fromEmail = $fromEmail ?: ($cfg['from_address'] ?? 'no-reply@localhost');
        $fromName = $fromName ?: ($cfg['from_name'] ?? 'CRM');

        $smtp = $cfg['smtp'] ?? [];
        $useSmtp = !empty($smtp['enabled']);
        $graph = $cfg['graph'] ?? [];
        $useGraph = !empty($graph['enabled']);

        if ($useSmtp) {
            try {
                return self::sendViaSmtp($smtp, $fromEmail, $fromName, $toEmail, $subject, $body);
            } catch (Throwable $e) {
                self::debug('SMTP send failed: ' . $e->getMessage());
                // fall through to try Graph or PHP mail
            }
        }

        if ($useGraph) {
            self::debug('Sending via Microsoft Graph');
            try {
                return self::sendViaGraph($fromEmail, $fromName, $toEmail, $subject, $body);
            } catch (Throwable $e2) {
                self::debug('Graph send failed: ' . $e2->getMessage());
                // fall through to optional PHP mail
            }
        }

        $headers = [];
        $headers[] = 'From: ' . self::formatAddress($fromEmail, $fromName);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: quoted-printable';
        $headerStr = implode("\r\n", $headers);
        $encodedBody = function_exists('quoted_printable_encode') ? quoted_printable_encode($body) : $body;
        self::debug('Falling back to PHP mail()');
        return @mail($toEmail, self::encodeHeader($subject), $encodedBody, $headerStr);
    }

    private static function sendViaGraph(string $fromEmail, string $fromName, string $toEmail, string $subject, string $body): bool {
        $ms = $GLOBALS['config']['MSFT'] ?? [];
        $tenant = (string)($ms['tenant_id'] ?? 'common');
        $clientId = (string)($ms['client_id'] ?? '');
        $clientSecret = (string)($ms['client_secret'] ?? '');
        if (!$clientId || !$clientSecret) {
            throw new RuntimeException('Graph not configured');
        }

        // 1) App token (client credentials)
        $tokenUrl = 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token';
        $post = http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
            'scope' => 'https://graph.microsoft.com/.default',
        ]);
        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) throw new RuntimeException('Graph token error: ' . curl_error($ch));
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $tok = json_decode($resp, true) ?: [];
        if ($code >= 400 || empty($tok['access_token'])) {
            throw new RuntimeException('Graph token HTTP ' . $code . ' ' . ($tok['error_description'] ?? $tok['error'] ?? 'unknown'));
        }
        $accessToken = (string)$tok['access_token'];

        // 2) Send mail as the notifications mailbox
        $sendUrl = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($fromEmail) . '/sendMail';
        $payload = [
            'message' => [
                'subject' => $subject,
                'body' => [ 'contentType' => 'Text', 'content' => $body ],
                'toRecipients' => [[ 'emailAddress' => [ 'address' => $toEmail ] ]],
                // from/sender is implicit as the targeted user
                'replyTo' => [[ 'emailAddress' => [ 'address' => $fromEmail, 'name' => $fromName ] ]],
            ],
            'saveToSentItems' => true,
        ];
        $ch2 = curl_init($sendUrl);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp2 = curl_exec($ch2);
        if ($resp2 === false) throw new RuntimeException('Graph send error: ' . curl_error($ch2));
        $code2 = curl_getinfo($ch2, CURLINFO_RESPONSE_CODE);
        curl_close($ch2);
        if ($code2 >= 400) {
            $err = $resp2 ?: '';
            throw new RuntimeException('Graph send HTTP ' . $code2 . ' ' . $err);
        }
        return true;
    }

    private static function sendViaSmtp(array $smtp, string $fromEmail, string $fromName, string $toEmail, string $subject, string $body): bool {
        $host = $smtp['host'] ?? '';
        $port = (int)($smtp['port'] ?? 587);
        $username = $smtp['username'] ?? '';
        $password = $smtp['password'] ?? '';
        $encryption = strtolower($smtp['encryption'] ?? 'tls'); // 'tls' for STARTTLS
        $timeout = (int)($smtp['timeout'] ?? 10);
        if (!$host || !$port || !$username || !$password) {
            throw new RuntimeException('SMTP not configured');
        }

        $errno = 0; $errstr = '';
        self::debug("Connecting to {$host}:{$port} (timeout {$timeout}s)");
        $stream = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$stream) {
            throw new RuntimeException('SMTP connect failed: ' . $errstr);
        }
        stream_set_timeout($stream, $timeout);

        self::expect($stream, 220);
        self::sendLine($stream, 'EHLO ' . self::ehloDomain());
        self::expect($stream, 250);

        if ($encryption === 'tls' || $encryption === 'starttls') {
            self::sendLine($stream, 'STARTTLS');
            self::expect($stream, 220);
            $cryptoOk = @stream_socket_enable_crypto($stream, true, defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$cryptoOk) {
                $cryptoOk = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            }
            if (!$cryptoOk) {
                throw new RuntimeException('Failed to start TLS');
            }
            self::sendLine($stream, 'EHLO ' . self::ehloDomain());
            self::expect($stream, 250);
        }

        // AUTH LOGIN
        self::sendLine($stream, 'AUTH LOGIN');
        self::expect($stream, 334);
        self::sendLine($stream, base64_encode($username));
        self::expect($stream, 334);
        self::sendLine($stream, base64_encode($password));
        self::expect($stream, 235);

        self::sendLine($stream, 'MAIL FROM:<' . $fromEmail . '>');
        self::expect($stream, 250);
        self::sendLine($stream, 'RCPT TO:<' . $toEmail . '>');
        $code = self::readCode($stream);
        if ($code !== 250 && $code !== 251) {
            throw new RuntimeException('RCPT failed, code ' . $code);
        }
        self::sendLine($stream, 'DATA');
        self::expect($stream, 354);

        $headers = [];
        $headers[] = 'From: ' . self::formatAddress($fromEmail, $fromName);
        $headers[] = 'To: <' . $toEmail . '>';
        $headers[] = 'Subject: ' . self::encodeHeader($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: quoted-printable';
        $msg = implode("\r\n", $headers) . "\r\n\r\n" . (function_exists('quoted_printable_encode') ? quoted_printable_encode($body) : $body);

        // Normalize line endings and dot-stuffing per RFC5321
        $msg = str_replace(["\r\n", "\r"], "\n", $msg);
        $msg = str_replace("\n.", "\n..", $msg);
        $msg = str_replace("\n", "\r\n", $msg);

        fwrite($stream, $msg . "\r\n.\r\n");
        self::expect($stream, 250);

        self::sendLine($stream, 'QUIT');
        fclose($stream);
        return true;
    }

    private static function formatAddress(string $email, ?string $name = null): string {
        if ($name === null || $name === '') return '<' . $email . '>';
        $encName = self::encodeHeader($name);
        return $encName . ' <' . $email . '>';
    }

    private static function encodeHeader(string $text): string {
        // Use simple UTF-8 base64 header encoding
        if (preg_match('/[\x80-\xFF]/', $text)) {
            return '=?UTF-8?B?' . base64_encode($text) . '?=';
        }
        return $text;
    }

    private static function ehloDomain(): string {
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        if (!$host) $host = 'localhost';
        return $host;
    }

    private static function sendLine($stream, string $line): void {
        fwrite($stream, $line . "\r\n");
    }

    private static function readLine($stream): string {
        $line = fgets($stream, 8192);
        if ($line === false) return '';
        return rtrim($line, "\r\n");
    }

    private static function readCode($stream): int {
        $line = self::readLine($stream);
        if ($line === '') return 0;
        $code = (int)substr($line, 0, 3);
        // Handle multiline replies: lines with '-' indicate more to come
        while (strlen($line) >= 4 && $line[3] === '-') {
            $line = self::readLine($stream);
        }
        return $code;
    }

    private static function expect($stream, int $expectedCode): void {
        $code = self::readCode($stream);
        if ($code !== $expectedCode) {
            throw new RuntimeException('SMTP expected ' . $expectedCode . ' got ' . $code);
        }
    }

    private static function debug(string $message): void {
        $cfg = $GLOBALS['config']['MAIL']['smtp'] ?? [];
        if (!empty($cfg['debug'])) {
            error_log('[Mailer] ' . $message);
        }
    }
}

