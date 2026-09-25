<?php
/**
 * Answer the browser, then finish the slow part.
 *
 * Accepting a lead does two things: it writes the client and the token, which
 * takes milliseconds, and it emails the intake link, which takes about four
 * seconds of SMTP conversation with Gmail. Both used to happen before the
 * response was written, so the button sat spinning for the whole handshake.
 *
 * The write is what the admin is waiting on. The email is not: it can finish
 * after the answer has gone, and if it fails it lands in the mail queue, which
 * is where a failed send is meant to end up anyway.
 *
 * The session lock matters as much as the response. PHP holds an exclusive
 * lock on the session file for the life of a request, so four seconds of SMTP
 * blocked every other admin request behind it -- the whole panel felt stuck,
 * not just the button that was pressed.
 */

/**
 * Send $payload as the JSON response and hand control back so the caller can
 * keep working with the connection closed.
 *
 * Everything after this call is invisible to the browser: it must not echo,
 * and its failures have to be recorded somewhere the admin will actually look.
 */
function respondAndContinue(array $payload) {
    $body = json_encode($payload);

    // The browser needs to know the response is complete, or it waits for the
    // connection to close -- which is the very thing being deferred.
    if (!headers_sent()) {
        header('Content-Length: ' . strlen($body));
        header('Connection: close');
    }

    echo $body;

    // Let go of the session lock before the slow part, so other admin requests
    // are not queued behind it.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // The client is gone after this point; finishing the work is the whole
    // purpose, so a disconnect must not kill the script.
    ignore_user_abort(true);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();          // php-fpm
        return;
    }

    // mod_php: flush every buffer that is open, then the SAPI's own.
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
}
