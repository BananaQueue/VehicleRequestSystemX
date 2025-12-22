<?php
require_once __DIR__ . '/../includes/session.php';
require '../db.php';
require_once __DIR__ . '/delete_entry.php'; // Include the generic delete utility

require_role('admin', '../login.php');

handle_delete_entry(
    $pdo,
    'users',
    'id',
    'name',
    "../dashboardX.php#employeeManagement"
);
