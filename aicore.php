<?php
// aicore.php — словная N-грамм модель, модель ОБЯЗАТЕЛЬНО передаётся как body.model
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

$MODELS_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'Models';

// читаем тело запроса (ОДИН раз)
$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true) ?? [];

// требуем модель
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

// параметры
$user        = trim((string)($body['message'] ?? ''));
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

// грузим модель
$model = json_decode(file_get_contents($weights_path), true);
if (!$model || !isset($model['unigram'], $model['grams'])) {
    http_response_code(500);
    echo json_encode(['error'=>'cannot parse weights file'], JSON_UNESCAPED_UNICODE);
    exit;
}

// чистим спец-токены
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

// ——— токенизация / детокенизация ———
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

// ——— параметры модели ———
$N     = max(2, min(100, (int)($model['N'] ?? 10)));
$grams = $model['grams'];
$uni   = $model['unigram'];

// ——— история ———
if (!isset($_SESSION['tokens'])) {
    $_SESSION['tokens'] = tokenize("Система : ты — полезный ассистент . Отвечай кратко . \n");
}
$history = &$_SESSION['tokens'];
$max_history_tokens = 2400;

// добавляем запрос и префикс роли
$history = array_merge($history, tokenize("Пользователь : ".$user."\nАссистент : "));
if (count($history) > $max_history_tokens) $history = array_slice($history, -$max_history_tokens);

// ——— генерация ———
$lambda_unigram = 0.03;
$order_gamma    = 1.25;
$min_stop_len   = 8;

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
        $chosen = sample_dist($uni, $temperature, $top_k, $top_p, $repCnt, $rep_penalty);
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
        $chosen = sample_dist($mix, $temperature, $top_k, $top_p, $repCnt, $rep_penalty);
        if ($chosen === null) break;

        $ln = count($out);
        if ($chosen === "Пользователь" && $ln > 0 && $out[$ln-1] === "\n") {
            $mix[$chosen] = 0.0;
            $chosen = sample_dist($mix ?: $uni, $temperature, $top_k, $top_p, $repCnt, $rep_penalty);
            if ($chosen === null) break;
        }
    }

    $out[] = $chosen;

    if ($chosen === "\n" && $i >= $min_stop_len) break;

    $ln = count($out);
    if ($ln >= 3 && $out[$ln-3] === "\n" && $out[$ln-2] === "Пользователь" && $out[$ln-1] === ":") {
        array_splice($out, -2);
        break;
    }
}

$reply = trim(detok($out));
if ($reply === '') $reply = 'Окей.'; // фолбэк
$history = array_merge($history, $out);

// ответ
echo json_encode([
    'reply'            => $reply,
    'tokens_generated' => count($out),
    'weights'          => basename($weights_path),
    'N'                => $N,
], JSON_UNESCAPED_UNICODE);
