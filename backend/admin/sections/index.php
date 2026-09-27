<?php
require __DIR__ . '/../includes/lookup_page.php';
lookup_admin_page([
    'table' => 'sections',
    'plural' => 'Sections',
    'singular' => 'section',
    'used_in' => [['activities', 'section'], ['source', 'section'], ['centres', 'section'], ['users', 'section']],
]);
