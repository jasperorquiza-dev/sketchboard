<?php
// db.php
declare(strict_types=1);

try {
    // Connect to MySQL server and ensure the database exists
    $dsnWithoutDb = "mysql:host=localhost;charset=utf8mb4";
    $pdo = new PDO($dsnWithoutDb, "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS sketchboard");
    
    // Connect to the sketchboard database
    $db = new PDO("mysql:host=localhost;dbname=sketchboard;charset=utf8mb4", "root", "");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Create users table
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at INT NOT NULL
    ) ENGINE=InnoDB");
    
    // Create rooms table
    $db->exec("CREATE TABLE IF NOT EXISTS rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) UNIQUE NOT NULL,
        name VARCHAR(100) NOT NULL,
        owner_user_id INT NOT NULL,
        created_at INT NOT NULL,
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // Create room_states table
    $db->exec("CREATE TABLE IF NOT EXISTS room_states (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room VARCHAR(64) UNIQUE NOT NULL,
        state LONGTEXT NOT NULL,
        updated_at INT NOT NULL
    ) ENGINE=InnoDB");

    // Create password resets table
    $db->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(100) NOT NULL,
        token VARCHAR(100) UNIQUE NOT NULL,
        expires_at INT NOT NULL
    ) ENGINE=InnoDB");

    // Create rate_limits table
    $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        ip VARCHAR(45) NOT NULL,
        action VARCHAR(50) NOT NULL,
        timestamp INT NOT NULL,
        INDEX(ip, action)
    ) ENGINE=InnoDB");
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
