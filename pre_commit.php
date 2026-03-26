<?php
// Simple script to test parsing
require_once 'includes/Database.php';
$db = new Database();
if ($db->getConnection()) {
    echo "DB Connection initialized properly in pre-commit check.\n";
}
