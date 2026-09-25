<?php
/**
 * Flat-file fallback queue.
 *
 * A lead is the one thing on this site we cannot ask the visitor to retype.
 * If the INSERT throws (DB restart, lock timeout, disk), we append the payload
 * to a newline-delimited JSON file and still tell the visitor we received it —
 * a 500 here loses the lead permanently.
 *
 * Files land in storage/queue/, which is denied to the web server by
 * storage/.htaccess. The rows contain PII; keep it that way.
 */

function leadQueueDir() {
    return __DIR__ . '/../storage/queue';
}

/**
 * Append one failed write to today's queue file.
 * Returns false only if the file itself could not be written, which is the
 * point at which the caller should surface a real error.
 */
function queueFailedLead(array $payload, $reason) {
    $dir = leadQueueDir();

    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        error_log('[lead-queue] cannot create ' . $dir);
        return false;
    }

    $record = [
        'queued_at' => gmdate('c'),
        'reason'    => (string) $reason,
        'ip'        => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
        'payload'   => $payload,
    ];

    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        error_log('[lead-queue] payload not JSON-encodable');
        return false;
    }

    $file = $dir . '/leads-' . gmdate('Y-m-d') . '.jsonl';
    $written = @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

    if ($written === false) {
        error_log('[lead-queue] cannot append to ' . $file);
        return false;
    }
    return true;
}

/** Count of queued leads awaiting manual replay, for an admin banner. */
function queuedLeadCount() {
    $files = @glob(leadQueueDir() . '/leads-*.jsonl');
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
