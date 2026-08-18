<?php
require_once __DIR__ . '/../includes/mail-queue.php';

function clearMailQueue() {
    foreach (glob(mailQueueDir() . '/mail-*.jsonl') ?: [] as $f) {
        @unlink($f);
    }
}

test('a failed send is spooled and counted', function () {
    clearMailQueue();
    assertSame(true, queueFailedMail(['to' => 'a@example.com', 'kind' => 'intake_link'], 'smtp down'), 'queued');
    assertSame(1, queuedMailCount(), 'one message waiting');
});

test('draining sends what it can and keeps what it cannot', function () {
    clearMailQueue();
    queueFailedMail(['to' => 'good@example.com', 'kind' => 'intake_link'], 'smtp down');
    queueFailedMail(['to' => 'bad@example.com',  'kind' => 'intake_link'], 'smtp down');

    $result = drainQueuedMail(function (array $payload) {
        return $payload['to'] === 'good@example.com';
    });

    assertSame(1, $result['sent'], 'one went out');
    assertSame(1, $result['kept'], 'the other stayed queued');
    assertSame(1, queuedMailCount(), 'and is still on disk for the next run');
});

test('draining an empty queue is not an error', function () {
    clearMailQueue();
    $result = drainQueuedMail(function () { return true; });
    assertSame(0, $result['sent'], 'nothing sent');
    assertSame(0, $result['kept'], 'nothing kept');
});

test('a payload that will not encode is refused rather than silently dropped', function () {
    clearMailQueue();
    // A resource is not JSON-encodable.
    $handle = fopen('php://memory', 'r');
    assertSame(false, queueFailedMail(['to' => 'a@example.com', 'bad' => $handle], 'test'), 'refused');
    fclose($handle);
    clearMailQueue();
});
