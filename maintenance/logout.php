<?php
session_start();
unset($_SESSION['maintenance_user_id'], $_SESSION['maintenance_user_name']);
header('Location: login.php');
exit;
