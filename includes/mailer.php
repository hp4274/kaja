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
 * Send one message. Returns true on a 250 from the server.
 *
 * Plain text by default. Give $background an absolute http(s) URL and the
 * message goes out as multipart/alternative instead: the same text, plus an
 * HTML rendering with that image behind it. The image is referenced by URL,
 * never attached, so the message stays a few kilobytes whatever the picture
 * weighs -- and a client that blocks remote images still gets a readable mail.
 *
 * Never throws: every caller already treats false as "queue it and move on",
 * and an exception escaping a mail call would roll back work that is already
 * correct.
 */
function sendMail($to, $subject, $body, $replyTo = null, $background = '') {
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
    ];

    if (mailBackgroundIsUsable($background)) {
        $boundary  = 'kaja-' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $payload   = mailMultipartBody($boundary, (string) $body, $background);
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $payload   = (string) $body;
    }

    // Normalise to CRLF, then dot-stuff: a body line that is a single dot
    // would otherwise end the message early.
    $normalised = str_replace(["\r\n", "\r", "\n"], "\r\n", $payload);
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

/** Only a real http(s) URL is ever put into a message. */
function mailBackgroundIsUsable($url) {
    return is_string($url) && preg_match('~^https?://[^\s"\'<>]+$~i', $url) === 1;
}

/**
 * The HTML rendering of a plain-text body, with an image behind it.
 *
 * The text sits on a white card, not straight on the picture: whatever image
 * gets chosen, the words stay legible. The outer cell carries the image three
 * ways (attribute, shorthand, and a flat colour) because mail clients honour
 * different ones, and the flat colour is what a client that blocks remote
 * images or ignores backgrounds -- desktop Outlook -- falls back to.
 *
 * The body is escaped BEFORE links are made, so a template can carry no
 * markup of its own, and every URL in it becomes clickable.
 */
function mailHtmlBody($text, $background) {
    $safe = htmlspecialchars(str_replace(["\r\n", "\r"], "\n", (string) $text), ENT_QUOTES, 'UTF-8');
    $safe = preg_replace('~(https?://[^\s<]*[^\s<.,;:!?)])~i', '<a href="$1" style="color:#0d7377;">$1</a>', $safe);
    $safe = nl2br($safe, false);

    $bg   = htmlspecialchars($background, ENT_QUOTES, 'UTF-8');
    $font = "font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;";

    return '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f3f4f6;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
        . ' bgcolor="#f3f4f6" background="' . $bg . '"'
        . ' style="background:#f3f4f6 url(\'' . $bg . '\') center top / cover no-repeat;">'
        . '<tr><td align="center" style="padding:32px 16px;">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"'
        . ' style="width:100%;max-width:600px;background:#ffffff;border-radius:8px;">'
        . '<tr><td style="padding:28px 32px;' . $font . 'font-size:15px;line-height:1.6;color:#1f2937;">'
        . $safe
        . '</td></tr></table></td></tr></table></body></html>';
}

/**
 * plain + html, as multipart/alternative.
 *
 * Quoted-printable, not 8bit: the HTML is a handful of very long lines, and
 * SMTP caps a line at 998 characters. quoted_printable_encode() wraps them.
 * It is fed CRLF: given a bare LF it encodes it as =0A, which decodes to the
 * wrong line ending.
 * The plain part comes first, because clients show the LAST part they can.
 */
function mailMultipartBody($boundary, $text, $background) {
    $part = function ($type, $content) use ($boundary) {
        return '--' . $boundary . "\r\n"
            . 'Content-Type: ' . $type . '; charset=UTF-8' . "\r\n"
            . 'Content-Transfer-Encoding: quoted-printable' . "\r\n\r\n"
            . quoted_printable_encode(str_replace(["\r\n", "\r", "\n"], "\r\n", $content)) . "\r\n";
    };

    return $part('text/plain', $text)
         . $part('text/html', mailHtmlBody($text, $background))
         . '--' . $boundary . '--';
}
