<?php
/**
 * Legacy Dashboard Redirect
 * Redirects to the central therapist portal at admin/index.php
 */
session_start();
header("Location: admin/index.php");
exit;
