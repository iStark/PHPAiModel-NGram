<?php
/*
 * PHPAiModel-NGram — aicore.php
 * Core word-level N-gram model inference endpoint.
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
session_start();
header('Content-Type: application/json; charset=utf-8');

$MODELS_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'Models';

// read request body (only once)
$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true) ?? [];

// require model param
$requested = (string)($body['model'] ?? '');
if ($requested === '') {
    http_response_code(400);
    echo json_encode(['error' => 'model is required'], JSON_UNESCAPED_UNICODE);
    exit;
}
$safe = basename($requested);
if (!preg_match('/\.json$/i', $safe)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid model name (must be *.json)'], JSON_UNESCAPED_UNICODE);
    exit;
}
$weights_path = $MODELS_DIR . DIRECTORY_SEPARATOR . $safe;
if (!is_file($weights_path)) {
    http_response_code(404);
    echo json_encode(['error' => "weights file not found: $safe"], JSON_UNESCAPED_UNICODE);
    exit;
}

// parameters
$user        = trim((string)($body['message'] ?? ''));
$user        = mb_strtolower($user, 'UTF-8');
$max_tokens  = max(1, min(200000, (int)($body['max_tokens']  ?? 1400)));
$temperature = max(0.05, min(2.0,    (float)($body['temperature'] ?? 0.30)));
$top_k       = max(1,    min(400,    (int)($body['top_k'] ?? 160)));
$top_p       = max(0.01, min(1.0,    (float)($body['top_p'] ?? 0.85)));
$rep_penalty = max(0.0,  min(2.0,    (float)($body['rep_penalty'] ?? 1.0)));
$rep_window  = max(0,    min(4000,   (int)($body['rep_window'] ?? 180)));

if ($user === '') {
    http_response_code(400);
    echo json_encode(['error' => 'message is required'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'ru'; // default: Russian
}
$det = detect_lang_from_text($user);  // language detection helper
if ($det !== '') {
    $_SESSION['lang'] = $det;  // update session if detected
}
$target_lang = $_SESSION['lang'];      // 'ru' | 'en'

// load model
$model = json_decode(file_get_contents($weights_path), true);
if (!$model || !isset($model['unigram'], $model['grams'])) {
    http_response_code(500);
    echo json_encode(['error'=>'cannot parse weights file'], JSON_UNESCAPED_UNICODE);
    exit;
}

// remove special tokens
$drop = ['OK','FILL','DATA'];
foreach ($drop as $tok) { unset($model['unigram'][$tok]); }
foreach ($model['grams'] as $n => &$tab) {
    foreach ($tab as $ctx => &$dist) {
        foreach ($drop as $tok) { unset($dist[$tok]); }
        if (!$dist) unset($tab[$ctx]);
    }
    if (!$tab) unset($model['grams'][$n]);
}
unset($tab, $dist);

// --- tokenization / detokenization ---
function tokenize(string $text): array {
    $re = '/\n|[A-Za-zА-Яа-яЁё0-9]+|[^\sA-Za-zА-Яа-яЁё0-9]/u';
    preg_match_all($re, $text, $m);
    return $m[0] ?? [];
}
function detok(array $tokens): string {
    $noSpaceBefore = ['.', ',', '!', '?', ':', ';', ')', ']', '}', '»'];
    $noSpaceAfter  = ['(', '[', '{', '«'];
    $out = ''; $prev = '';
    foreach ($tokens as $t) {
        if ($t === "\n") { $out .= "\n"; $prev = ''; continue; }
        $sp = '';
        if ($out !== '' && $prev !== '' && !in_array($t, $noSpaceBefore, true) && !in_array($prev, $noSpaceAfter, true)) $sp = ' ';
        $out .= $sp . $t; $prev = $t;
    }
    return $out;
}

// --- language detection / token filtering ---
function detect_lang_from_text(string $s): string {
    if (preg_match('/\p{Cyrillic}/u', $s)) return 'ru';
    if (preg_match('/[A-Za-z]/',       $s)) return 'en';
    return ''; // no letters (emoji/numbers/symbols only)
}
function token_is_lang(string $t, string $lang): bool {
    $has_cyr = (bool)preg_match('/\p{Cyrillic}/u', $t);
    $has_lat = (bool)preg_match('/[A-Za-z]/',       $t);
    // neutral tokens (punctuation, digits, line breaks) are always allowed
    if (!$has_cyr && !$has_lat) return true;
    // exclude mixed words like "OKей" (both alphabets)
    if ($has_cyr && $has_lat)   return false;
    return $lang === 'ru' ? ($has_cyr && !$has_lat) : ($has_lat && !$has_cyr);
}
function filter_dist_by_lang(array $dist, string $lang): array {
    if ($lang !== 'ru' && $lang !== 'en') return $dist;
    $f = [];
    foreach ($dist as $tok => $w) {
        if (token_is_lang($tok, $lang)) $f[$tok] = $w;
    }
    return $f;
}
// ★ neutral tokens only (if distribution is empty after filtering)
function filter_neutral_only(array $dist): array {
    $f = [];
    foreach ($dist as $tok => $w) {
        $has_cyr = (bool)preg_match('/\p{Cyrillic}/u', $tok);
        $has_lat = (bool)preg_match('/[A-Za-z]/',       $tok);
        if (!$has_cyr && !$has_lat) $f[$tok] = $w;
    }
    return $f;
}

function ctx_key(array $arr): string { return implode("\t", $arr); }
function last_counts(array $seq, int $window): array {
    if ($window <= 0) return [];
    $slice = array_slice($seq, -$window);
    $cnt = []; foreach ($slice as $t) $cnt[$t] = ($cnt[$t] ?? 0) + 1; return $cnt;
}
function sample_dist(array $dist, float $temperature, int $top_k, float $top_p, array $repCnt, float $repPenalty): ?string {
    if (empty($dist)) return null;
    $weights = [];
    foreach ($dist as $tok => $cnt) {
        $w = (float)$cnt;
        if ($repPenalty > 0 && isset($repCnt[$tok])) $w = $w / (1.0 + $repPenalty * $repCnt[$tok]);
        $w = pow(max($w, 1e-9), 1.0 / $temperature);
        $weights[$tok] = $w;
    }
    arsort($weights);
    if ($top_k < count($weights)) $weights = array_slice($weights, 0, $top_k, true);
    $sum = array_sum($weights); if ($sum <= 0) return array_key_first($weights);
    $acc = 0.0; $cut = [];
    foreach ($weights as $tok => $w) { $acc += $w; $cut[$tok] = $w; if ($acc >= $top_p * $sum) break; }
    $sum2 = array_sum($cut); $r = mt_rand() / mt_getrandmax(); $acc2 = 0.0;
    foreach ($cut as $tok => $w) { $acc2 += $w / $sum2; if ($r < $acc2) return $tok; }
    end($cut); return key($cut);
}

// --- model params ---
$N     = max(2, min(100, (int)($model['N'] ?? 10)));
$grams = $model['grams'];
$uni   = $model['unigram'];

// --- history ---
if (!isset($_SESSION['tokens'])) {
    $_SESSION['tokens'] = tokenize("System : you are a helpful assistant . Answer briefly . \n");
}
$history = &$_SESSION['tokens'];
$max_history_tokens = 2400;

// append user query
$history = array_merge($history, tokenize("User : ".$user."\n"));
if (count($history) > $max_history_tokens) $history = array_slice($history, -$max_history_tokens);

// --- generation ---
$lambda_unigram = 0.03;
$order_gamma    = 1.25;
$min_stop_len   = 8;

// optional ban for repeated n-grams
$no_repeat_ngram = $no_repeat_ngram ?? 0;

$out = [];
for ($i = 0; $i < $max_tokens; $i++) {
    $seq    = array_merge($history, $out);
    $repCnt = last_counts($seq, $rep_window);

    $mix  = [];
    $hits = 0;
    $max_n_here = min($N-1, count($seq));
    for ($n = $max_n_here; $n >= 1; $n--) {
        $key = ctx_key(array_slice($seq, -$n));
        $table = $grams[(string)$n] ?? null;
        if ($table && isset($table[$key])) {
            $dist = $table[$key];
            $sum  = array_sum($dist);
            if ($sum <= 0) continue;

            $w = pow($n, $order_gamma);
            foreach ($dist as $tok => $cnt) {
                $mix[$tok] = ($mix[$tok] ?? 0.0) + $w * ($cnt / $sum);
            }
            $hits++;
        }
    }

    if ($hits === 0) {
        // fallback using only filtered unigrams
        $cand = filter_dist_by_lang($uni, $target_lang);
        if (empty($cand)) {
            // fallback to neutral tokens only
            $cand = filter_neutral_only($uni);
        }
        if (function_exists('block_no_repeat')) {
            block_no_repeat($seq, $no_repeat_ngram, $cand);
        }
        $chosen = sample_dist($cand, $temperature, $top_k, $top_p, $repCnt, $rep_penalty);
        if ($chosen === null) break;
    } else {
        if ($lambda_unigram > 0) {
            $sumU = array_sum($uni);
            if ($sumU > 0) {
                foreach ($uni as $tok => $cnt) {
                    $mix[$tok] = ($mix[$tok] ?? 0.0) + $lambda_unigram * ($cnt / $sumU);
                }
            }
        }
        // filter by language AFTER unigram mix
        $mix = filter_dist_by_lang($mix, $target_lang);

        if (function_exists('block_no_repeat')) {
            block_no_repeat($seq, $no_repeat_ngram, $mix);
        }
        $chosen = sample_dist($mix, $temperature, $top_k, $top_p, $repCnt, $rep_penalty);
        if ($chosen === null) break;

        // avoid switching to "User" role on a new line
        $ln = count($out);
        if ($chosen === "User" && $ln > 0 && $out[$ln-1] === "\n") {
            $mix[$chosen] = 0.0;
            $chosen = sample_dist($mix ?: filter_dist_by_lang($uni, $target_lang), $temperature, $top_k, $top_p, $repCnt, $rep_penalty);
            if ($chosen === null) break;
        }
    }

    $out[] = $chosen;

    if ($chosen === "\n" && $i >= $min_stop_len) break;

    $ln = count($out);
    if ($ln >= 3 && $out[$ln-3] === "\n" && $out[$ln-2] === "User" && $out[$ln-1] === ":") {
        array_splice($out, -2);
        break;
    }
}

$reply = trim(detok($out));
if ($reply === '') {
    $reply = ($target_lang === 'en' ? 'ok.' : 'окей.');
}
// normalize to lowercase
$reply = mb_strtolower($reply, 'UTF-8');

$history = array_merge($history, $out);

// response
echo json_encode([
    'reply'            => $reply,
    'tokens_generated' => count($out),
    'weights'          => basename($weights_path),
    'N'                => $N,
], JSON_UNESCAPED_UNICODE);
