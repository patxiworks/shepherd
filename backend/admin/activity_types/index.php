<?php
require __DIR__ . '/../includes/lookup_page.php';
lookup_admin_page([
    'table' => 'activity_types',
    'plural' => 'Activity types',
    'singular' => 'activity type',
    'used_in' => [['activities', 'activity'], ['source', 'activity'], ['absences', 'activity']],
]);
