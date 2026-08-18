<?php
/**
 * Spool for mail that failed to send.
 *
 * Modelled on includes/lead-queue.php. A bounced or refused send is the one
 * failure mode nobody notices: the database is correct, the screen says
 * success, and the person simply never hears from us. Spooling it to disk
 * makes the failure countable and retryable.
 *
 * Files land in storage/queue/, which storage/.htaccess denies to the web
 * server. The payloads carry email addresses and personal intake links; keep
 * it that way.
 */

function mailQueueDir() {
    return __DIR__ . '/../storage/queue';
}

function queueFailedMail(array $payload, $reason) {
    $dir = mailQueueDir();

    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        error_log('[mail-queue] cannot create ' . $dir);
        return false;
    }

    $record = [
        'queued_at' => gmdate('c'),
        'reason'    => (string) $reason,
        'payload'   => $payload,
    ];

    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        error_log('[mail-queue] payload not JSON-encodable');
        return false;
    }

    $file    = $dir . '/mail-' . gmdate('Y-m-d') . '.jsonl';
    $written = @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

    if ($written === false) {
        error_log('[mail-queue] cannot append to ' . $file);
        return false;
    }
    return true;
}

function queuedMailCount() {
    $files = @glob(mailQueueDir() . '/mail-*.jsonl');
    if (!$files) {
        return 0;
    }
    $total = 0;
    foreach ($files as $file) {
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $total += $lines ? count($lines) : 0;
    }
    return $total;
}

/**
 * Retry everything on the spool. $sender receives one payload and returns true
 * when it went out. Anything still failing is rewritten to the file, so a
 * permanently bad address does not take the rest of the queue down with it —
 * it simply stays visible in the count until someone looks.
 */
function drainQueuedMail(callable $sender) {
    $files = @glob(mailQueueDir() . '/mail-*.jsonl');
    $sent  = 0;
    $kept  = 0;

    if (!$files) {
        return ['sent' => 0, 'kept' => 0];
    }

    foreach ($files as $file) {
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            @unlink($file);
            continue;
        }

        $remaining = [];
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if (!is_array($record) || !isset($record['payload'])) {
                continue;   // unreadable line: drop it rather than retry forever
            }
            if ($sender($record['payload']) === true) {
                $sent++;
            } else {
                $remaining[] = $line;
                $kept++;
            }
        }

        if ($remaining) {
            @file_put_contents($file, implode(PHP_EOL, $remaining) . PHP_EOL, LOCK_EX);
        } else {
            @unlink($file);
        }
    }

    return ['sent' => $sent, 'kept' => $kept];
}
