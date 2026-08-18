<?php
/**
 * Outbound mail over SMTP.
 *
 * PHP's mail() needs a local MTA. XAMPP on Windows has none, so every send in
 * this project was silently failing and landing in the retry queue. This
 * speaks SMTP directly instead — no Composer, no vendored library, consistent
 * with the rest of the codebase being plain functions.
 *
 * Credentials live in db-config.php, which is gitignored. They are never read
 * from the settings table: a password that round-trips through a web form and
 * a database is a password with two more places to leak from.
 *
 * Gmail rewrites the From header to the authenticated account whatever you
 * put there, so From is always the SMTP user and the practice address goes in
 * Reply-To. Claiming otherwise would just produce a header nobody honours.
 */

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/settings.php';

function smtpIsConfigured() {
    return defined('SMTP_HOST') && SMTP_HOST !== ''
        && defined('SMTP_USER') && SMTP_USER !== ''
        && defined('SMTP_PASS') && SMTP_PASS !== '';
}

/**
 * Send one plain-text message. Returns true on a 250 from the server.
 *
 * Never throws: every caller already treats false as "queue it and move on",
 * and an exception escaping a mail call would roll back work that is already
 * correct.
 */
function sendMail($to, $subject, $body, $replyTo = null) {
    if (!smtpIsConfigured()) {
        error_log('[mailer] SMTP is not configured; see db-config.example.php');
        return false;
    }

    $host    = SMTP_HOST;
    $port    = defined('SMTP_PORT') ? (int) SMTP_PORT : 587;
    $user    = SMTP_USER;
    // Google prints app passwords in groups of four. The spaces are display
    // only and must not be sent.
    $pass    = str_replace(' ', '', SMTP_PASS);
    $timeout = 20;

    $fromName = getSetting('practice_name', 'Rewire With Kajal');
    $replyTo  = $replyTo ?: getSetting('practice_email', $user);

    $socket = @stream_socket_client(
        'tcp://' . $host . ':' . $port, $errno, $errstr, $timeout,
        STREAM_CLIENT_CONNECT
    );
    if (!$socket) {
        error_log('[mailer] cannot reach ' . $host . ':' . $port . ' - ' . $errstr);
        return false;
    }
    stream_set_timeout($socket, $timeout);

    $read = function () use ($socket) {
        $out = '';
        while (($line = fgets($socket, 515)) !== false) {
            $out .= $line;
            // A multi-line reply has a dash after the code; a space ends it.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $out;
    };
    $expect = function ($reply, $code) {
        return substr(trim($reply), 0, 3) === (string) $code;
    };
    $say = function ($line) use ($socket, $read) {
        fwrite($socket, $line . "\r\n");
        return $read();
    };

    $fail = function ($stage, $reply) use ($socket) {
        error_log('[mailer] ' . $stage . ' failed: ' . trim((string) $reply));
        @fclose($socket);
        return false;
    };

    if (!$expect($read(), 220))                       { return $fail('greeting', ''); }

    $ehlo = $say('EHLO localhost');
    if (!$expect($ehlo, 250))                         { return $fail('EHLO', $ehlo); }

    // 587 is STARTTLS. Port 465 is implicit TLS and would have been wrapped at
    // connect time instead; this project uses 587.
    if ($port !== 465) {
        $tls = $say('STARTTLS');
        if (!$expect($tls, 220))                      { return $fail('STARTTLS', $tls); }
        if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return $fail('TLS handshake', '');
        }
        // The EHLO is repeated because the server's advertised capabilities
        // are only trustworthy once the channel is encrypted.
        $ehlo = $say('EHLO localhost');
        if (!$expect($ehlo, 250))                     { return $fail('EHLO after TLS', $ehlo); }
    }

    $auth = $say('AUTH LOGIN');
    if (!$expect($auth, 334))                         { return $fail('AUTH LOGIN', $auth); }
    $userReply = $say(base64_encode($user));
    if (!$expect($userReply, 334))                    { return $fail('username', $userReply); }
    $passReply = $say(base64_encode($pass));
    if (!$expect($passReply, 235))                    { return $fail('authentication', $passReply); }

    $mailFrom = $say('MAIL FROM:<' . $user . '>');
    if (!$expect($mailFrom, 250))                     { return $fail('MAIL FROM', $mailFrom); }
    $rcpt = $say('RCPT TO:<' . $to . '>');
    if (!$expect($rcpt, 250) && !$expect($rcpt, 251)) { return $fail('RCPT TO', $rcpt); }

    $dataReply = $say('DATA');
    if (!$expect($dataReply, 354))                    { return $fail('DATA', $dataReply); }

    $headers = [
        'Date: ' . date('r'),
        'From: ' . mailEncodeHeader($fromName) . ' <' . $user . '>',
        'Reply-To: ' . $replyTo,
        'To: ' . $to,
        'Subject: ' . mailEncodeHeader($subject),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    // Normalise to CRLF, then dot-stuff: a body line that is a single dot
    // would otherwise end the message early.
    $normalised = str_replace(["\r\n", "\r", "\n"], "\r\n", (string) $body);
    $stuffed    = preg_replace('/^\./m', '..', $normalised);

    fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . $stuffed . "\r\n.\r\n");
    $sent = $read();

    $say('QUIT');
    @fclose($socket);

    if (!$expect($sent, 250)) {
        error_log('[mailer] message rejected: ' . trim($sent));
        return false;
    }
    return true;
}

/** RFC 2047 for anything outside plain ASCII, so accents survive the subject line. */
function mailEncodeHeader($text) {
    $text = (string) $text;
    if (preg_match('/^[\x20-\x7E]*$/', $text)) {
        return $text;
    }
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}
