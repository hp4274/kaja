<?php
/**
 * Practice agreements published as files in storage/policies/ (denied to the
 * web by .htaccess; only portal/api/policy-download.php serves them).
 */

const PORTAL_POLICY_EXT = ['pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'txt' => 'text/plain; charset=utf-8'];

/** [basename => label] for every publishable file currently in the folder. */
function portalPolicies() {
    $dir = dirname(__DIR__, 2) . '/storage/policies';
    $out = [];
    foreach ((array) @scandir($dir) as $f) {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if ($f[0] !== '.' && isset(PORTAL_POLICY_EXT[$ext]) && is_file($dir . '/' . $f)) {
            $out[$f] = ucfirst(trim(preg_replace('/[-_]+/', ' ', pathinfo($f, PATHINFO_FILENAME))));
        }
    }
    return $out;
}
