<?php
require_once __DIR__ . '/_auth.php';
$_SESSION = [];
session_destroy();
header('Location: ' . ABSOLUTE_URL . '/portal/teacher_login.php');
exit;
