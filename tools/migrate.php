<?php
/**
 * CLI Migration Runner
 * Delegates to root migrate.php for database schema migrations.
 *
 * Usage:
 *   php tools/migrate.php
 */
require_once dirname(__DIR__) . '/migrate.php';
