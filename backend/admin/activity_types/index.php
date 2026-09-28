<?php
require __DIR__ . '/../includes/lookup_page.php';
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/multiday_activities.php';

// Migration 015 adds activity_types.is_multiday and multiday_activities; until
// it's applied the page behaves as before (no checkbox, no multi-day usage).
$mday = multiday_activities_available(pastores_db());
lookup_admin_page([
    'table' => 'activity_types',
    'plural' => 'Activity types',
    'singular' => 'activity type',
    'multiday' => $mday,
    'used_in' => array_merge(
        [['activities', 'activity'], ['source', 'activity'], ['absences', 'activity']],
        $mday ? [['multiday_activities', 'activity']] : []
    ),
]);
