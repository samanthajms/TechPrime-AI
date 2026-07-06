<?php
session_start();
require_once __DIR__ . '/config/database.php';

// 1. Security Check: Must be logged in to send
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDbConnection();
    
    $sender_id = (int)$_SESSION['user_id'];
    $receiver_id = (int)$_POST['receiver_id'];
    $message = trim($_POST['message']);

    // 2. Prevent empty messages
    if (empty($message)) {
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit();
    }

    // 3. Insert into database (Matching your NEW structure)
    $query = "INSERT INTO messages (sender_id, receiver_id, message, created_at) VALUES (?, ?, ?, NOW())";
    $stmt = $db->prepare($query);
    $stmt->bind_param("iis", $sender_id, $receiver_id, $message);

    if ($stmt->execute()) {
        // 4. Redirect back to where the user was (Client or Seller page)
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit();
    } else {
        echo "Error sending message: " . $db->error;
    }
} else {
    // If someone tries to access this file directly without POST
    header("Location: ../index.php");
    exit();
}