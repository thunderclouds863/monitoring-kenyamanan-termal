<?php
session_start();
session_unset();
session_destroy();

// Hapus cookie
setcookie('remember_token', '', time() - 3600, "/");

header("Location: login.php");
exit();
