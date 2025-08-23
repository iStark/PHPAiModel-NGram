<?php
// chat_word.php — словная N-грамм модель (до 100), фиксированный вес: weights50_merged.json
// Чистый PHP. Без правил. Добавлены: фолбэк-ответ и аккуратный стоп.

session_start();
header('Content-Type: application/json; charset=utf-8');

// --- Всегда один файл весов ---
$weightsFile = __DIR__ . '/weights.json';
if (!is_file($weightsFile)) {
    http_response_code(500);
    echo json_encode(['error'=>"weights file not found: ".basename($weightsFile)], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$user = trim((string)($input['message'] ?? ''));
$max_tokens  = max(1, min(200000, (int)($input['max_tokens'] ?? 1400)));
$temperature = max(0.05, min(2.0,  (float)($input['temperature'] ?? 0.30)));
$top_k       = max(1,    min(400,  (int)($input['top_k'] ?? 160)));
$top_p       = max(0.01, min(1.0,  (float)($input['top_p'] ?? 0.85)));
$rep_penalty = max(0.0,  min(2.0,  (float)($input['rep_penalty'] ?? 1.0)));
$rep_window  = max(0,    min(4000, (int)($input['rep_window'] ?? 180)));

if ($user === '') { http_response_code(400); echo json_encode(['error'=>'message is required'], JSON_UNESCAPED_UNICODE); exit; }

$model = json_decode(file_get_contents($weightsFile), true);
// после $model = json_decode(...);
$drop = ['OK','FILL','DATA'];
foreach ($drop as $tok) { unset($model['unigram'][$tok]); }
foreach ($model['grams'] as $n => &$tab) {
    foreach ($tab as $ctx => &$dist) {
        foreach ($drop as $tok) { unset($dist[$tok]); }
        if (!$dist) unset($tab[$ctx]);
    }
    if (!$tab) unset($model['grams'][$n]);
}
unset($tab,$dist);
if (!$model || !isset($model['unigram'],$model['grams'])) {
    http_response_code(500); echo json_encode(['error'=>'cannot parse weights file'], JSON_UNESCAPED_UNICODE); exit;
}

// --- токенизация / детокенизация ---
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

// --- модель ---
$N = max(2, min(100, (int)($model['N'] ?? 10)));
$grams = $model['grams']; $uni = $model['unigram'];

// --- история ---
if (!isset($_SESSION['tokens'])) {
    // минимальный праймер (без «правил»)
    $_SESSION['tokens'] = tokenize("Система : ты — полезный ассистент . Отвечай кратко . \n");
}
$history = &$_SESSION['tokens'];
$max_history_tokens = 2400;

// Добавляем запрос и префикс роли
$history = array_merge($history, tokenize("Пользователь : ".$user."\nАссистент : "));
if (count($history) > $max_history_tokens) $history = array_slice($history, -$max_history_tokens);

// --- генерация (смесь контекстов + сглаживание униграммой) ---
$lambda_unigram = 0.03; // меньше подпора униграммой
$order_gamma    = 1.25; // сильнее вес длинных n-грамм
$min_stop_len   = 8;     // минимум токенов до стопа по переводу строки

$out = [];
for ($i = 0; $i < $max_tokens; $i++) {
    $seq    = array_merge($history, $out);
    $repCnt = last_counts($seq, $rep_window);

    // 1) собираем смесь распределений по всем найденным порядкам
    $mix  = [];
    $hits = 0;
    $max_n_here = min($N-1, count($seq));
    for ($n = $max_n_here; $n >= 1; $n--) {
        $key = ctx_key(array_slice($seq, -$n));
        $table = $grams[(string)$n] ?? null;
        if ($table && isset($table[$key])) {
            $dist = $table[$key];
            $sum  = 0; foreach ($dist as $c) $sum += $c;
            if ($sum <= 0) continue;

            // вес уровня: длиннее контекст — выше вес
            $w = pow($n, $order_gamma);

            // нормализуем локальное распределение и добавляем в смесь
            foreach ($dist as $tok => $cnt) {
                $mix[$tok] = ($mix[$tok] ?? 0.0) + $w * ($cnt / $sum);
            }
            $hits++;
        }
    }

    // 2) если совпадений нет — фолбэк на униграммы
    if ($hits === 0) {
        $chosen = sample_dist($uni, $temperature, $top_k, $top_p, $repCnt, $rep_penalty);
        if ($chosen === null) break;
    } else {
        // 3) сглаживание смесью униграмм (немного «поддержки» частым токенам)
        if ($lambda_unigram > 0) {
            $sumU = 0; foreach ($uni as $c) $sumU += $c;
            if ($sumU > 0) {
                foreach ($uni as $tok => $cnt) {
                    $mix[$tok] = ($mix[$tok] ?? 0.0) + $lambda_unigram * ($cnt / $sumU);
                }
            }
        }

        // 4) сэмплинг из смеси с температурой/топ-k/топ-p и штрафом за повторы
        $chosen = sample_dist($mix, $temperature, $top_k, $top_p, $repCnt, $rep_penalty);
        if ($chosen === null) break;

        // защита от ухода в новую роль прямо после перевода строки
        $ln = count($out);
        if ($chosen === "Пользователь" && $ln > 0 && $out[$ln-1] === "\n") {
            // запретим этот токен и пересэмплим один раз
            $mix[$chosen] = 0.0;
            $chosen = sample_dist($mix ?: $uni, $temperature, $top_k, $top_p, $repCnt, $rep_penalty);
            if ($chosen === null) break;
        }
    }

    $out[] = $chosen;

    // мягкий стоп по переносу строки
    if ($chosen === "\n" && $i >= $min_stop_len) break;

    // дополнительный стоп: если модель начала «Пользователь :» — аккуратно обрежем
    $ln = count($out);
    if ($ln >= 3 && $out[$ln-3] === "\n" && $out[$ln-2] === "Пользователь" && $out[$ln-1] === ":") {
        array_splice($out, -2); // убрали "Пользователь :"
        break;
    }
}

$reply = trim(detok($out));
if ($reply === '') $reply = 'Окей.'; // фолбэк

$history = array_merge($history, $out);

echo json_encode([
    'reply' => $reply,
    'tokens_generated' => count($out),
    'weights' => basename($weightsFile),
    'N' => $N
], JSON_UNESCAPED_UNICODE);
