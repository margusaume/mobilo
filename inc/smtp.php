<?php
declare(strict_types=1);

/**
 * Minimal SMTP client supporting SSL (implicit TLS) and STARTTLS with AUTH LOGIN.
 * This is a lightweight fallback to avoid external dependencies.
 *
 * Returns [bool success, string message]
 */
function sendSmtpEmail(
    string $host,
    int $port,
    string $encryption, // 'ssl', 'starttls', or 'none'
    string $username,
    string $password,
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $toName,
    string $subject,
    string $bodyText,
    ?string $bodyHtml = null
): array {
    $timeout = 30;
    $remote = ($encryption === 'ssl') ? 'ssl://' . $host : $host;

    $fp = @fsockopen($remote, $port, $errno, $errstr, $timeout);
    if (!$fp) {
        return [false, 'Connection failed: ' . $errstr . ' (' . $errno . ') - Remote: ' . $remote . ' Port: ' . $port];
    }
    stream_set_timeout($fp, $timeout);

    $read = function() use ($fp): string {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) break;
            $data .= $line;
            if (strlen($line) < 4) break;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $write = function(string $cmd) use ($fp) {
        fwrite($fp, $cmd . "\r\n");
    };

    $resp = $read();
    if (strpos($resp, '220') !== 0) { fclose($fp); return [false, 'Bad greeting: ' . trim($resp)]; }
    
    // Debug: Log SMTP conversation (remove in production)
    error_log("SMTP Debug - Greeting: " . trim($resp));

    $hostname = gethostname() ?: 'localhost';
    $write('EHLO ' . $hostname);
    $resp = $read();
    error_log("SMTP Debug - EHLO Response: " . trim($resp));
    if (strpos($resp, '250') !== 0) { fclose($fp); return [false, 'EHLO failed: ' . trim($resp)]; }

    if ($encryption === 'starttls') {
        $write('STARTTLS');
        $resp = $read();
        if (strpos($resp, '220') !== 0) { fclose($fp); return [false, 'STARTTLS failed: ' . trim($resp)]; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp); return [false, 'TLS negotiation failed'];
        }
        // Re-EHLO after TLS
        $write('EHLO ' . $hostname);
        $resp = $read();
        if (strpos($resp, '250') !== 0) { fclose($fp); return [false, 'EHLO after STARTTLS failed: ' . trim($resp)]; }
    }

    // AUTH LOGIN
    $write('AUTH LOGIN');
    $resp = $read();
    error_log("SMTP Debug - AUTH LOGIN Response: " . trim($resp));
    if (strpos($resp, '334') !== 0) { fclose($fp); return [false, 'AUTH LOGIN not accepted: ' . trim($resp)]; }
    $write(base64_encode($username));
    $resp = $read();
    error_log("SMTP Debug - Username Response: " . trim($resp));
    if (strpos($resp, '334') !== 0) { fclose($fp); return [false, 'Username not accepted: ' . trim($resp)]; }
    $write(base64_encode($password));
    $resp = $read();
    error_log("SMTP Debug - Password Response: " . trim($resp));
    if (strpos($resp, '235') !== 0) { fclose($fp); return [false, 'Password not accepted: ' . trim($resp)]; }

    // MAIL FROM / RCPT TO
    $write('MAIL FROM: <' . $fromEmail . '>');
    $resp = $read();
    if (strpos($resp, '250') !== 0) { fclose($fp); return [false, 'MAIL FROM failed: ' . trim($resp)]; }
    $write('RCPT TO: <' . $toEmail . '>');
    $resp = $read();
    if (strpos($resp, '250') !== 0 && strpos($resp, '251') !== 0) { fclose($fp); return [false, 'RCPT TO failed: ' . trim($resp)]; }

    // DATA
    $write('DATA');
    $resp = $read();
    if (strpos($resp, '354') !== 0) { fclose($fp); return [false, 'DATA not accepted: ' . trim($resp)]; }

    $boundary = 'bnd_' . bin2hex(random_bytes(8));
    $headers = [];
    $headers[] = 'From: ' . encodeAddress($fromName, $fromEmail);
    $headers[] = 'To: ' . encodeAddress($toName, $toEmail);
    $headers[] = 'Subject: ' . encodeHeader($subject);
    $headers[] = 'MIME-Version: 1.0';

    if ($bodyHtml !== null && $bodyHtml !== '') {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = "--$boundary\r\n" .
                "Content-Type: text/plain; charset=utf-8\r\n" .
                "Content-Transfer-Encoding: quoted-printable\r\n\r\n" .
                qp($bodyText) . "\r\n" .
                "--$boundary\r\n" .
                "Content-Type: text/html; charset=utf-8\r\n" .
                "Content-Transfer-Encoding: quoted-printable\r\n\r\n" .
                qp($bodyHtml) . "\r\n" .
                "--$boundary--\r\n";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=utf-8';
        $headers[] = 'Content-Transfer-Encoding: quoted-printable';
        $body = qp($bodyText) . "\r\n";
    }

    $data = 'Date: ' . date(DATE_RFC2822) . "\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body . ".\r\n";
    fwrite($fp, $data);
    $resp = $read();
    if (strpos($resp, '250') !== 0) { fclose($fp); return [false, 'Message not accepted: ' . trim($resp)]; }

    $write('QUIT');
    $read();
    fclose($fp);
    return [true, 'Sent'];
}

function encodeHeader(string $text): string {
    if (preg_match('/[\x80-\xFF]/', $text)) {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
    return $text;
}

function encodeAddress(string $name, string $email): string {
    $n = trim($name);
    if ($n !== '') {
        return encodeHeader($n) . ' <' . $email . '>';
    }
    return '<' . $email . '>';
}

function qp(string $s): string {
    // simple quoted-printable encoder
    return preg_replace_callback('/[\x00-\x1F\x7F-\xFF=]/u', function($m){
        $c = $m[0];
        return sprintf('=%02X', ord($c));
    }, str_replace(["\r\n","\n"],["\n","\r\n"], $s));
}


