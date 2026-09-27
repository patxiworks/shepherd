<?php
require __DIR__ . '/../includes/lookup_page.php';
lookup_admin_page([
    'table' => 'priests',
    'plural' => 'Priests',
    'singular' => 'priest',
    'zone' => true,
    'used_in' => [['activities', 'priest'], ['source', 'priest'], ['absences', 'priest']],
]);
