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
                echo json_encode(['success' => false, 'error' => publicError($e)]);
                exit;
            }

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('form_question_edited',:d,'form',:rid)")
               ->execute([':d' => 'Edited intake question #' . $id, ':rid' => $id]);

            echo json_encode(['success' => true]);
            break;

        case 'add_question':
            // Yes or no, required, end of the section. The only thing asked
            // for is the wording; the field id is derived from it.
            $version = intval($_POST['version'] ?? 0);
            $section = trim($_POST['section'] ?? '');
            $label   = trim($_POST['label'] ?? '');

            if (!$version || $section === '' || $label === '') {
                echo json_encode(['success' => false, 'error' => 'A question needs some wording']);
                exit;
            }

            try {
                $added = addFormQuestion($db, $version, $section, $label);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => publicError($e)]);
                exit;
            }

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('form_question_added',:d,'form',:rid)")
               ->execute([':d' => 'Added "' . $label . '" to ' . $section . ' in intake version ' . $version,
                          ':rid' => $added['id']]);

            echo json_encode(['success' => true] + $added);
            break;

        case 'reorder_questions':
            // The order arrives as the ids in the order they now sit on screen.
            // The numbers are derived from it here -- see reorderFormQuestions()
            // for why they are no longer typed in by hand.
            $version = intval($_POST['version'] ?? 0);
            $section = trim($_POST['section'] ?? '');
            $ids     = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));

            if (!$version || $section === '' || !$ids) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }

            try {
                $n = reorderFormQuestions($db, $version, $section, $ids);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => publicError($e)]);
                exit;
            }

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('form_reordered',:d,'form',:rid)")
               ->execute([':d' => 'Reordered ' . $section . ' in intake version ' . $version, ':rid' => $version]);

            echo json_encode(['success' => true, 'renumbered' => $n]);
            break;

        case 'normalise_order':
            $version = intval($_POST['version'] ?? 0);
            if (!$version) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }

            try {
                $order = normaliseFormOrder($db, $version);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => publicError($e)]);
                exit;
            }

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('form_reordered',:d,'form',:rid)")
               ->execute([':d' => 'Restored the shipped section order in intake version ' . $version, ':rid' => $version]);

            echo json_encode(['success' => true, 'sections' => $order]);
            break;

        case 'publish_version':
            $from = intval($_POST['from_version'] ?? 0);
            $name = trim($_POST['name'] ?? '');

            try {
                $new = publishNewFormVersion($db, $from, $name !== '' ? $name : null);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => publicError($e)]);
                exit;
            }

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('form_version_published',:d,'form',:rid)")
               ->execute([':d' => 'Created intake form "' . ($name !== '' ? $name : formTemplateName($db, $new)) . '" from version ' . $from, ':rid' => $new]);

            echo json_encode(['success' => true, 'version' => $new, 'name' => formTemplateName($db, $new)]);
            break;

        case 'rename_template':
            $version = intval($_POST['version'] ?? 0);
            $name    = trim($_POST['name'] ?? '');

            if (!$version || !formVersionExists($db, $version)) {
                echo json_encode(['success' => false, 'error' => 'That form does not exist']);
                exit;
            }

            try {
                setFormTemplateName($db, $version, $name);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => publicError($e)]);
                exit;
            }

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('form_renamed',:d,'form',:rid)")
               ->execute([':d' => 'Renamed intake form version ' . $version . ' to "' . $name . '"', ':rid' => $version]);

            echo json_encode(['success' => true]);
            break;

        case 'delete_template':
            $version = intval($_POST['version'] ?? 0);
            $name    = formTemplateName($db, $version);

            try {
                deleteFormVersion($db, $version);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => publicError($e)]);
                exit;
            }

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('form_deleted',:d,'form',NULL)")
               ->execute([':d' => 'Deleted intake form "' . $name . '" (version ' . $version . ')']);

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
