<?php
// list_models.php — отдает список моделей из папки Models/ как JSON
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$dir = __DIR__ . DIRECTORY_SEPARATOR . 'Models';
if (!is_dir($dir)) {
    echo json_encode(['ok' => false, 'error' => 'Models directory not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
$models = [];
foreach ($files as $full) {
    $models[] = [
        'file'  => basename($full),
        'size'  => @filesize($full) ?: 0,
        'mtime' => @filemtime($full) ?: 0,
    ];
}
usort($models, fn($a,$b)=>($b['mtime']<=>$a['mtime']) ?: strcmp($a['file'],$b['file']));
echo json_encode(['ok'=>true,'models'=>$models], JSON_UNESCAPED_UNICODE);
