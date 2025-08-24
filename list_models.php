<?php
/*
 * PHPAiModel-NGram — list_models.php
 * Returns list of models from /Models directory as JSON.
 *
 * Developed by: Artur Strazewicz — concept, architecture, PHP N-gram runtime, UI.
 * Year: 2025. License: MIT.
 *
 * Links:
 *   GitHub:      https://github.com/iStark/PHPAiModel-NGram
 *   LinkedIn:    https://www.linkedin.com/in/arthur-stark/
 *   TruthSocial: https://truthsocial.com/@strazewicz
 *   X (Twitter): https://x.com/strazewicz
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$dir = __DIR__ . DIRECTORY_SEPARATOR . 'Models';

// check if Models/ exists
if (!is_dir($dir)) {
    echo json_encode(['ok' => false, 'error' => 'Models directory not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

// scan all *.json files
$files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
$models = [];
foreach ($files as $full) {
    $models[] = [
        'file'  => basename($full),
        'size'  => @filesize($full) ?: 0,
        'mtime' => @filemtime($full) ?: 0,
    ];
}

// sort by modification time (newest first), then by filename
usort($models, fn($a,$b)=>($b['mtime']<=>$a['mtime']) ?: strcmp($a['file'],$b['file']));

// output
echo json_encode(['ok'=>true,'models'=>$models], JSON_UNESCAPED_UNICODE);
