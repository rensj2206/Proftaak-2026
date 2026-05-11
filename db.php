<?php
// ─────────────────────────────────────────────
//  db.php  –  Database connection + table setup
//  Edit the 4 constants below to match your MySQL
// ─────────────────────────────────────────────

define('DB_HOST', 'localhost');
define('DB_USER', 'root');       // your MySQL username
define('DB_PASS', '');           // your MySQL password
define('DB_NAME', 'plasmaticgames');

function getDB(): mysqli {
    static $conn = null;
    if ($conn !== null) return $conn;

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    if ($conn->connect_error) {
        die(json_encode(['error' => 'DB connection failed: ' . $conn->connect_error]));
    }

    // Create database if it doesn't exist
    $conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $conn->select_db(DB_NAME);

    // Users table
    $conn->query("
        CREATE TABLE IF NOT EXISTS users (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            username    VARCHAR(30)  NOT NULL UNIQUE,
            email       VARCHAR(100) NOT NULL UNIQUE,
            password    VARCHAR(255) NOT NULL,
            score       INT          NOT NULL DEFAULT 0,
            wins        INT          NOT NULL DEFAULT 0,
            played      INT          NOT NULL DEFAULT 0,
            joined      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    return $conn;
}