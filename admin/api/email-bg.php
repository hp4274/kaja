<?php
/**
 * Background image for one email: upload, replace, remove.
 *
 * The image is stored in the code tree (images/email-backgrounds/) and the
 * setting holds only its filename. It is never attached to a message; the
 * mailer references it by URL.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../db-config.php';
require_once __DIR__ . '/../../includes/settings.php';
require_once __DIR__ . '/../../includes/email-templates.php';

function bgFail($message) {
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bgFail('POST only.');
}

$templates = emailTemplates();
$id = (string) ($_POST['template'] ?? '');
if (!isset($templates[$id])) {
    bgFail('Unknown email.');
}
$key = $templates[$id]['bg_key'];

/** Delete the stored file for $id, if there is one. */
function bgDeleteCurrent($id) {
    $current = emailBackgroundFile($id);
    if ($current !== '') {
        // emailBackgroundFile() only returns a name in the shape this code
        // generates, so this cannot be walked out of the directory.
        @unlink(emailBackgroundDir() . '/' . $current);
    }
}

$action = (string) ($_POST['action'] ?? '');

if ($action === 'remove') {
    bgDeleteCurrent($id);
    setSetting($key, '');
    echo json_encode(['success' => true, 'url' => '', 'public' => '']);
    exit;
}

if ($action !== 'upload') {
    bgFail('Unknown action.');
}

$file = $_FILES['image'] ?? null;
if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
    bgFail('Choose an image first.');
}
if ($file['error'] !== UPLOAD_ERR_OK) {
    // The two size errors are the ones a person can act on.
    bgFail(in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
        ? 'That file is too large.' : 'The upload failed. Try again.');
}
if ($file['size'] > emailBackgroundMaxBytes()) {
    bgFail('That image is ' . round($file['size'] / 1024) . ' KB. The limit is '
         . (emailBackgroundMaxBytes() / 1024) . ' KB, so the email stays quick to open on a phone.');
}

// The type comes from the file's own bytes, not from its name or the header
// the browser sent, and the extension comes from the type. getimagesize() as
// well: a file can claim a MIME type and still not decode as a picture.
$mime  = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
$types = emailBackgroundTypes();
if (!isset($types[$mime]) || @getimagesize($file['tmp_name']) === false) {
    bgFail('Use a JPG, PNG, WebP or GIF image.');
}

$dir = emailBackgroundDir();
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    bgFail('The images/email-backgrounds folder cannot be created.');
}
if (!is_writable($dir)) {
    bgFail('The images/email-backgrounds folder is not writable.');
}

// A fresh name every time. Mail clients and image proxies cache by URL, so
// replacing a picture under the same name would keep showing the old one.
$name = $id . '-' . bin2hex(random_bytes(4)) . '.' . $types[$mime];
if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
    bgFail('The image could not be saved.');
}

bgDeleteCurrent($id);
setSetting($key, $name);

echo json_encode([
    'success' => true,
    'url'     => emailBackgroundPreviewUrl($id),
    'public'  => emailBackgroundUrl($id),
    'private' => emailUrlIsPrivate(emailBackgroundUrl($id)),
]);
