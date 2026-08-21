<?php
// 1. Point to the correct directory BEFORE starting the session
ini_set('session.save_path', __DIR__ . '/sessions');

// 2. Harden the session cookie (Secure, HttpOnly, SameSite)
session_set_cookie_params([
    'lifetime' => 2592000,
    'path' => '/',
    'secure' => true, // Only send over HTTPS (Cloudflare)
    'httponly' => true, // Hide from JavaScript
    'samesite' => 'Strict' // Prevent CSRF attacks
]);

// 3. Start the session so we know which one to destroy
session_start();

//Unset all session variables
$_SESSION = array();

//Completely destroy the 30 day persistent cookie we created for iOS
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
}

//Destroy the session on the server
session_destroy();

//Redirect back to the login page (index.php)
header("Location: index.php");
exit;
