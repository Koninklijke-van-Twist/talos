<?php

/**
 * Functies
 */

/**
 * Verstuurt een e-mail via SMTP (TLS) op basis van de $mailSettings uit auth.php.
 *
 * @param string[] $to       Lijst van ontvanger-e-mailadressen.
 * @param string   $subject  Onderwerp.
 * @param string   $bodyHtml HTML-body.
 * @param string   $bodyText Tekstfallback (optioneel).
 */
function sendMail(array $to, string $subject, string $bodyHtml, string $bodyText = ''): void
{
    global $mailSettings;

    if (empty($to)) {
        throw new InvalidArgumentException('Geen ontvangers opgegeven.');
    }

    $transport = $mailSettings['transport'] ?? 'mail';

    if ($bodyText === '') {
        $bodyText = strip_tags($bodyHtml);
    }

    if ($transport === 'smtp') {
        sendMailSmtp($to, $subject, $bodyHtml, $bodyText, $mailSettings);
    } else {
        sendMailNative($to, $subject, $bodyHtml, $bodyText, $mailSettings);
    }
}

function sendMailNative(array $to, string $subject, string $bodyHtml, string $bodyText, array $settings): void
{
    $fromEmail = $settings['from_email'] ?? 'noreply@kvt.nl';
    $fromName = $settings['from_name'] ?? 'KVT Bot';
    $boundary = 'talos_' . bin2hex(random_bytes(8));

    $headers = 'From: ' . $fromName . ' <' . $fromEmail . '>' . "\r\n";
    $headers .= 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n";

    $body = '--' . $boundary . "\r\n";
    $body .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n\r\n";
    $body .= $bodyText . "\r\n";
    $body .= '--' . $boundary . "\r\n";
    $body .= 'Content-Type: text/html; charset=UTF-8' . "\r\n\r\n";
    $body .= $bodyHtml . "\r\n";
    $body .= '--' . $boundary . '--';

    $toStr = implode(', ', array_map('trim', $to));
    $ok = mail($toStr, $subject, $body, $headers);
    if (!$ok) {
        throw new RuntimeException('mail() mislukt voor ontvangers: ' . $toStr);
    }
}

function sendMailSmtp(array $to, string $subject, string $bodyHtml, string $bodyText, array $settings): void
{
    $smtp = $settings['smtp'] ?? [];
    $host = $smtp['host'] ?? 'localhost';
    $port = (int) ($smtp['port'] ?? 587);
    $enc = $smtp['encryption'] ?? 'tls';
    $username = $smtp['username'] ?? '';
    $password = $smtp['password'] ?? '';
    $timeout = (int) ($smtp['timeout'] ?? 20);

    $from = $settings['from_email'] ?? 'noreply@kvt.nl';
    $fromName = $settings['from_name'] ?? 'KVT Bot';
    $boundary = 'talos_' . bin2hex(random_bytes(8));

    $errno = 0;
    $errstr = '';

    if ($enc === 'ssl') {
        $connHost = 'ssl://' . $host;
    } else {
        $connHost = $host;
    }

    $socket = @fsockopen($connHost, $port, $errno, $errstr, $timeout);
    if ($socket === false) {
        throw new RuntimeException('SMTP verbinding mislukt: ' . $errstr . ' (' . $errno . ')');
    }

    stream_set_timeout($socket, $timeout);

    $smtpRead = static function () use ($socket): string {
        $data = '';
        while ($line = fgets($socket, 512)) {
            $data .= $line;
            if ($line[3] === ' ') {
                break;
            }
        }
        return $data;
    };

    $smtpSend = static function (string $cmd) use ($socket): void {
        fwrite($socket, $cmd . "\r\n");
    };

    $smtpExpect = static function (string $response, string $code) use ($smtpRead): string {
        if (strncmp($response, $code, 3) !== 0) {
            throw new RuntimeException('SMTP fout (verwacht ' . $code . '): ' . trim($response));
        }
        return $response;
    };

    // Greeting
    $smtpExpect($smtpRead(), '220');
    $smtpSend('EHLO ' . gethostname());
    $smtpExpect($smtpRead(), '250');

    // STARTTLS
    if ($enc === 'tls') {
        $smtpSend('STARTTLS');
        $smtpExpect($smtpRead(), '220');
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            throw new RuntimeException('STARTTLS crypto mislukt.');
        }
        $smtpSend('EHLO ' . gethostname());
        $smtpExpect($smtpRead(), '250');
    }

    // Auth
    if ($username !== '') {
        $smtpSend('AUTH LOGIN');
        $smtpExpect($smtpRead(), '334');
        $smtpSend(base64_encode($username));
        $smtpExpect($smtpRead(), '334');
        $smtpSend(base64_encode($password));
        $smtpExpect($smtpRead(), '235');
    }

    // Mail from / Rcpt to
    $smtpSend('MAIL FROM:<' . $from . '>');
    $smtpExpect($smtpRead(), '250');

    foreach ($to as $recipient) {
        $smtpSend('RCPT TO:<' . trim($recipient) . '>');
        $smtpExpect($smtpRead(), '250');
    }

    // Data
    $smtpSend('DATA');
    $smtpExpect($smtpRead(), '354');

    $toHeader = implode(', ', array_map('trim', $to));
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $message = 'From: ' . $fromName . ' <' . $from . '>' . "\r\n";
    $message .= 'To: ' . $toHeader . "\r\n";
    $message .= 'Subject: ' . $encodedSubject . "\r\n";
    $message .= 'MIME-Version: 1.0' . "\r\n";
    $message .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n\r\n";
    $message .= '--' . $boundary . "\r\n";
    $message .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n\r\n";
    $message .= $bodyText . "\r\n";
    $message .= '--' . $boundary . "\r\n";
    $message .= 'Content-Type: text/html; charset=UTF-8' . "\r\n\r\n";
    $message .= $bodyHtml . "\r\n";
    $message .= '--' . $boundary . '--' . "\r\n";
    $message .= '.';

    $smtpSend($message);
    $smtpExpect($smtpRead(), '250');

    $smtpSend('QUIT');
    fclose($socket);
}
