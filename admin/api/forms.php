<?php
/**
 * Form module actions.
 *
 * Every write goes through includes/form-builder.php, which is what enforces
 * that a version anyone has answered can no longer be edited.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../includes/form-builder.php';
require_once __DIR__ . '/../../includes/settings.php';

$db     = getDbConnection();
$action = isset($_POST['action']) ? trim($_POST['action']) : '';

try {
    switch ($action) {
        case 'update_question':
            $id = intval($_POST['question_id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }

            $fields = [];
            foreach (['label', 'section', 'field_type', 'sort_order'] as $f) {
                if (array_key_exists($f, $_POST)) {
                    $fields[$f] = $_POST[$f];
                }
            }
            if (array_key_exists('is_required', $_POST)) {
                $fields['is_required'] = ($_POST['is_required'] === '1');
            }

            try {
                updateFormQuestion($db, $id, $fields);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('form_question_edited',:d,'form',:rid)")
               ->execute([':d' => 'Edited intake question #' . $id, ':rid' => $id]);

            echo json_encode(['success' => true]);
            break;

        case 'publish_version':
            $from = intval($_POST['from_version'] ?? 0);

            try {
                $new = publishNewFormVersion($db, $from);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('form_version_published',:d,'form',:rid)")
               ->execute([':d' => 'Published intake form version ' . $new . ' from ' . $from, ':rid' => $new]);

            echo json_encode(['success' => true, 'version' => $new]);
            break;

        case 'set_live_version':
            $version = intval($_POST['version'] ?? 0);

            if (!formVersionExists($db, $version)) {
                echo json_encode(['success' => false, 'error' => 'That version does not exist']);
                exit;
            }

            // Only new links pick this up. issueIntakeToken pins the version
            // onto each row at send time, so nothing already in an inbox moves.
            setSetting('intake_form_version', (string) $version);

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('form_version_live',:d,'form',:rid)")
               ->execute([':d' => 'New intake links now use form version ' . $version, ':rid' => $version]);

            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    error_log('[forms-api] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
