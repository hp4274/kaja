<?php
/**
 * Scheduling guards.
 *
 * The overlap logic moved into includes/session-repo.php when sessions gained
 * real DATETIME ranges, so that the check and the write live behind one door
 * and cannot be bypassed. This file remains as the entry point callers already
 * knew about, and simply loads it.
 *
 * Single-therapist practice, so there is no therapist_id to key on: two
 * sessions overlapping in time is a conflict, full stop.
 */

require_once __DIR__ . '/session-repo.php';
