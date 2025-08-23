<?php
// reset.php — очищает историю диалога
declare(strict_types=1);
session_start();
unset($_SESSION['tokens']);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true]);