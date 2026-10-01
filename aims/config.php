<?php
// Database connection and session bootstrap

declare(strict_types=1);

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'aims_db';

// First connect without selecting a database, then ensure the DB exists.
$mysqli = new mysqli($db_host, $db_user, $db_pass);

if ($mysqli->connect_error) {
    die('Database connection failed: ' . $mysqli->connect_error);
}

// Create database if it does not exist (matches aims_schema.sql).
if (! $mysqli->query("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
    die('Failed to create database: ' . $mysqli->error);
}

if (! $mysqli->select_db($db_name)) {
    die('Failed to select database: ' . $mysqli->error);
}

if (! $mysqli->set_charset('utf8mb4')) {
    // Not fatal, but helpful for debugging if needed.
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function is_logged_in(): bool
{
    return isset($_SESSION['user_id'], $_SESSION['role']);
}

function require_login(): void
{
    if (! is_logged_in()) {
        // Redirect to login page relative to the site root or current location
        // Assuming /aims/login.php is the path.
        header('Location: /aims/login.php');
        exit;
    }
}

function require_role(string $role): void
{
    require_login();

    if (($_SESSION['role'] ?? '') !== $role) {
        // Simple forbidden response; you can route to a custom 403 page instead.
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }
}

