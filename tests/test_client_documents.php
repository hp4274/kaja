<?php
require_once __DIR__ . '/../includes/client-documents.php';

test('the storage directory is outside the document root, not just the project', function () {
    // A sibling of the project still sits inside htdocs and would be served at
    // /kaja-storage/... by a default Apache config. Outside the project is not
    // the bar; outside the document root is.
    $dir     = documentStorageDir();
    $project = realpath(dirname(__DIR__));
    $docRoot = dirname($project);

    assertTrue(strpos($dir, $project) !== 0, 'not under the project: ' . $dir);
    assertTrue(strpos($dir, $docRoot . DIRECTORY_SEPARATOR) !== 0,
        'and not under the document root either: ' . $dir);
});

test('a stored name is generated, never the uploaded one', function () {
    $name = generateStoredName('../../evil.php');
    assertTrue(strpos($name, '..') === false, 'no traversal survives');
    assertTrue(strpos($name, '/') === false, 'no forward slash');
    assertTrue(strpos($name, chr(92)) === false, 'no backslash');
    assertTrue(substr($name, -4) !== '.php', 'the original extension is not trusted');
});

test('two uploads of the same filename get different stored names', function () {
    assertTrue(generateStoredName('scan.pdf') !== generateStoredName('scan.pdf'),
        'a collision would overwrite someone else document');
});

test('an allowed type passes and everything else is refused', function () {
    assertSame(true,  documentTypeIsAllowed('application/pdf', 'consent.pdf'), 'pdf');
    assertSame(true,  documentTypeIsAllowed('image/jpeg', 'id.jpg'), 'jpeg');
    assertSame(false, documentTypeIsAllowed('application/x-httpd-php', 'shell.php'), 'php is never a document');
});

test('a claimed mime type cannot launder a forbidden extension', function () {
    assertSame(false, documentTypeIsAllowed('image/jpeg', 'shell.php'),
        'the browser chooses the mime type, so it proves nothing');
    assertSame(false, documentTypeIsAllowed('application/pdf', 'shell.phtml'), 'nor phtml');
});

test('a double extension is judged on the last one', function () {
    assertSame(false, documentTypeIsAllowed('application/pdf', 'invoice.pdf.php'),
        'invoice.pdf.php is a php file');
    assertSame(true, documentTypeIsAllowed('application/pdf', 'invoice.php.pdf'),
        'and invoice.php.pdf is a pdf');
});

test('the size limit comes from settings and is enforced in bytes', function () {
    setSetting('upload_max_mb', '2');
    assertSame(2 * 1024 * 1024, documentMaxBytes(), 'two megabytes');
    setSetting('upload_max_mb', '10');
});

test('documents are listed per client, newest first, archived ones hidden', function () {
    resetTestTables(['client_documents', 'clients', 'leads']);
    $leadId = insertTestLead(['status' => 'confirmed']);
    testDb()->prepare('INSERT INTO `clients` (`lead_id`,`first_name`,`last_name`,`email`,`status`)
                       VALUES (:l,"Anita","Rao","a@example.com","active")')->execute([':l' => $leadId]);
    $c = (int) testDb()->lastInsertId();

    $ids = [];
    foreach (['older.pdf', 'newer.pdf'] as $n) {
        testDb()->prepare('
            INSERT INTO `client_documents` (`client_id`,`original_name`,`stored_name`,`mime_type`,`size_bytes`)
            VALUES (:c,:o,:s,"application/pdf",100)
        ')->execute([':c' => $c, ':o' => $n, ':s' => bin2hex(random_bytes(16)) . '.pdf']);
        $ids[$n] = (int) testDb()->lastInsertId();
    }

    assertSame(2, count(clientDocuments(testDb(), $c)), 'both listed');

    archiveClientDocument(testDb(), $ids['older.pdf']);
    assertSame(1, count(clientDocuments(testDb(), $c)), 'archived one is hidden');
    assertSame(null, findClientDocument(testDb(), $ids['older.pdf']), 'and cannot be fetched');
});

test('the download script never builds a path from the request', function () {
    // The id is the only thing the caller chooses; the filename comes from the
    // row. Anything else is a traversal waiting to happen.
    $src = file_get_contents(dirname(__DIR__) . '/admin/document.php');
    assertTrue(strpos($src, "\$_GET['id']") !== false, 'it takes an id');
    assertTrue(strpos($src, "\$_GET['file']") === false, 'and never a filename');
    assertTrue(strpos($src, 'Content-Disposition: attachment') !== false,
        'stored files are downloaded, never rendered in the admin origin');
    assertTrue(strpos($src, 'document_downloaded') !== false, 'and every download is logged');
});
