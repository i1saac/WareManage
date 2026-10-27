<?php
// logout.php - Destroy session and redirect to login
session_start();
session_unset();     // Unset all session variables
session_destroy();   // Destroy the session

// Redirect to login page
header("Location: login.php");
exit();
?>