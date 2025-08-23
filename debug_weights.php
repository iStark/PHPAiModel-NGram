<?php
$fn = __DIR__ . '/weights.json';
$s  = @file_get_contents($fn);
$m  = $s ? json_decode($s, true) : null;
header('Content-Type: text/plain; charset=utf-8');
echo is_file($fn) ? "OK file\n" : "NO FILE\n";
echo "bytes: ".strlen((string)$s)."\n";
echo "json: ".(is_array($m) ? "OK\n" : "BAD\n");
if (is_array($m)) {
    echo "N: ".($m['N'] ?? '?')."\n";
    echo "unigram: ".count($m['unigram'] ?? [])."\n";
    $g = $m['grams'] ?? [];
    echo "levels: ".implode(',', array_keys($g))."\n";
}
