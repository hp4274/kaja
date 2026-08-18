<?php
/**
 * One merged history for a lead.
 *
 * Three sources feed it — activity_log rows scoped to this lead, the lead's
 * notes, and its intake_links lifecycle — because no single table knows the
 * whole story. Sorting happens in PHP after the merge: three ORDER BYs cannot
 * interleave, and a UNION would force every source into one column shape.
 */

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/lead-notes.php';

function leadTimeline(PDO $db, $leadId) {
    $leadId  = (int) $leadId;
    $entries = [];

    $stmt = $db->prepare('SELECT `name`, `source`, `created_at` FROM `leads` WHERE `id` = :id');
    $stmt->execute([':id' => $leadId]);
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lead) {
        return [];
    }

    $entries[] = [
        'at'     => $lead['created_at'],
        'icon'   => 'bi-inbox',
        'tone'   => 'teal',
        'text'   => $lead['name'] . ' submitted the ' . $lead['source'] . ' form',
        'author' => null,
    ];

    $acts = $db->prepare('
        SELECT `action`, `description`, `created_at`
        FROM `activity_log`
        WHERE `reference_type` = "lead" AND `reference_id` = :id
        ORDER BY `created_at` ASC
    ');
    $acts->execute([':id' => $leadId]);
    foreach ($acts as $row) {
        $entries[] = [
            'at'     => $row['created_at'],
            'icon'   => $row['action'] === 'client_converted' ? 'bi-person-check' : 'bi-arrow-repeat',
            'tone'   => $row['action'] === 'client_converted' ? 'green' : 'teal',
            'text'   => $row['description'],
            'author' => null,
        ];
    }

    $links = $db->prepare('
        SELECT `status`, `created_at`, `opened_at`, `submitted_at`, `expires_at`
        FROM `intake_links`
        WHERE `lead_id` = :id
        ORDER BY `created_at` ASC
    ');
    $links->execute([':id' => $leadId]);
    foreach ($links as $link) {
        $entries[] = [
            'at'     => $link['created_at'],
            'icon'   => 'bi-envelope',
            'tone'   => 'teal',
            'text'   => 'Intake link sent (expires ' . date('d M Y', strtotime($link['expires_at'])) . ')',
            'author' => null,
        ];
        if (!empty($link['opened_at'])) {
            $entries[] = [
                'at' => $link['opened_at'], 'icon' => 'bi-envelope-open', 'tone' => 'teal',
                'text' => 'Intake form opened', 'author' => null,
            ];
        }
        if (!empty($link['submitted_at'])) {
            $entries[] = [
                'at' => $link['submitted_at'], 'icon' => 'bi-check2-circle', 'tone' => 'green',
                'text' => 'Intake questionnaire submitted', 'author' => null,
            ];
        }
    }

    foreach (leadNotes($db, $leadId) as $note) {
        $entries[] = [
            'at'     => $note['created_at'],
            'icon'   => 'bi-chat-left-text',
            'tone'   => 'amber',
            'text'   => $note['content'],
            'author' => $note['author'],
        ];
    }

    usort($entries, function ($a, $b) {
        return strtotime($b['at']) <=> strtotime($a['at']);
    });

    return $entries;
}
