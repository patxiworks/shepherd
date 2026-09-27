<?php
require __DIR__ . '/../includes/lookup_page.php';
lookup_admin_page([
    'table' => 'labors',
    'plural' => 'Labors',
    'singular' => 'labor',
    'used_in' => [['activities', 'labor'], ['source', 'labor']],
]);
