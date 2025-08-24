<?php
// debug_weights.php — просмотрщик n-грамм с выбором модели из папки Models
// CLI примеры:
//   php debug_weights.php --dir=Models --list
//   php debug_weights.php --dir=Models --pick=1 --ctx="привет" --k=20
//   php debug_weights.php --model=auto --ctx="hello" --k=20 --lambda=0.4
//   php debug_weights.php --model=Models/weights_dialog_ru_en_50000.json --ctx="как дела"
// HTTP примеры:
//   /debug_weights.php                         -> HTML-UI выбора модели
//   /debug_weights.php?format=json             -> JSON со списком моделей
//   /debug_weights.php?model=auto&ctx=привет   -> JSON с топ-кандидатами

declare(strict_types=1);

$isCli = (PHP_SAPI === 'cli');

function arg(string $name, $default=null) {
    global $isCli;
    if ($isCli) {
        $argv = $GLOBALS['argv'] ?? [];
        foreach ($argv as $i=>$a) {
            if ($a === "--$name") return $argv[$i+1] ?? $default;
            if (str_starts_with($a, "--$name=")) return substr($a, strlen("--$name="));
        }
        return $default;
    } else {
        return $_GET[$name] ?? $default;
    }
}

function fail($msg, $code=400) {
    global $isCli;
    if ($isCli) {
        fwrite(STDERR, "[ERROR] $msg\n");
        exit(1);
    } else {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false, 'error'=>$msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function normalize_text(string $s, bool $doLower, bool $doPunct): string {
    if ($doPunct) {
        $s = str_replace(["’","‘","“","”"], ["'","'","\"","\""], $s);
        $s = preg_replace('/[!?]{2,}/u', '!', $s);
        $s = preg_replace('/\.\.{2,}/u', '..', $s);
        $s = preg_replace('/\s+/u', ' ', trim($s));
    }
    if ($doLower) $s = mb_strtolower($s, 'UTF-8');
    return $s;
}

function tokenize_simple(string $s): array {
    $s = trim($s);
    if ($s === '') return [];
    return preg_split('/\s+/u', $s);
}

function join_ctx(array $tokens): string {
    return implode(' ', $tokens);
}
function find_contexts(array $model, int $level, string $re, int $limit=50): array {
    $out = [];
    $grams = $model['grams'][$level] ?? null;
    if (!$grams) return $out;
    // безопасно собрать регэксп
    $rx = @preg_match($re, '') !== false ? $re : '/'.str_replace('/', '\/', $re).'/iu';
    foreach ($grams as $ctx => $nexts) {
        if (preg_match($rx, $ctx)) {
            $sum = 0;
            foreach ($nexts as $c) $sum += (int)$c;
            $out[] = ['ctx'=>$ctx, 'level'=>$level, 'variants'=>count($nexts), 'count_sum'=>$sum];
            if (count($out) >= $limit) break;
        }
    }
    usort($out, fn($a,$b)=> $b['count_sum'] <=> $a['count_sum']);
    return $out;
}
function load_model(string $path): array {
    if ($path === '' || !is_file($path)) fail("Model not found: $path");
    $json = file_get_contents($path);
    if ($json === false) fail("Cannot read model file: $path");
    $data = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);
    if (!is_array($data)) fail("Invalid JSON in model: $path");

    $N = (int)($data['N'] ?? $data['n'] ?? 0);
    $uni = $data['unigram'] ?? $data['uni'] ?? [];
    $gramsRaw = $data['grams'] ?? $data['g'] ?? [];
    if (!$N || !$uni || !$gramsRaw) fail("Model JSON missing required fields (N/unigram/grams).");

    $grams = [];
    if (array_is_list($gramsRaw)) {
        foreach ($gramsRaw as $k=>$v) if (is_array($v)) $grams[(int)$k] = $v;
    } else {
        foreach ($gramsRaw as $lvl=>$table) $grams[(int)$lvl] = is_array($table) ? $table : [];
    }
    ksort($grams, SORT_NUMERIC);
    return ['N'=>$N, 'unigram'=>$uni, 'grams'=>$grams];
}

function top_candidates(array $model, array $ctxTokens, int $topK, float $lambda, bool $byLevel=true): array {
    $N = $model['N'];
    $grams = $model['grams'];

    $maxCtxLen = max(0, min($N - 1, count($ctxTokens)));
    $acc = [];
    $levels = [];
    $usedLevels = [];

    for ($len = $maxCtxLen; $len >= 1; $len--) {
        $lvl = $len + 1;
        if (!isset($grams[$lvl])) continue;

        $subCtx = array_slice($ctxTokens, -$len);
        $ctxKey = join_ctx($subCtx);

        if (!isset($grams[$lvl][$ctxKey]) || !is_array($grams[$lvl][$ctxKey])) continue;

        $nexts = $grams[$lvl][$ctxKey];
        arsort($nexts, SORT_NUMERIC);

        $backoffSteps = ($maxCtxLen - $len);
        $w = ($backoffSteps === 0) ? 1.0 : pow($lambda, $backoffSteps);
        $usedLevels[] = ['lvl'=>$lvl, 'ctx'=>$ctxKey, 'w'=>$w, 'unique_nexts'=>count($nexts)];

        foreach ($nexts as $tok => $cnt) {
            $acc[$tok] = ($acc[$tok] ?? 0.0) + $w * (float)$cnt;
            if ($byLevel) $levels[$tok][] = ['lvl'=>$lvl, 'ctx'=>$ctxKey, 'count'=>(int)$cnt, 'w'=>$w];
        }
    }

    if (!$acc) {
        $uni = $model['unigram'];
        arsort($uni, SORT_NUMERIC);
        $sum = array_sum($uni) ?: 1;
        $i = 0;
        foreach ($uni as $tok=>$cnt) {
            $acc[$tok] = (float)$cnt;
            $levels[$tok][] = ['lvl'=>1, 'ctx'=>'<UNI>', 'count'=>(int)$cnt, 'w'=>1.0];
            if (++$i >= $topK*2) break;
        }
        $usedLevels[] = ['lvl'=>1, 'ctx'=>'<UNI>', 'w'=>1.0, 'unique_nexts'=>$i];
    }

    $sumAcc = array_sum($acc) ?: 1.0;
    $items = [];
    foreach ($acc as $tok=>$wcount) {
        $items[] = [
            'token' => $tok,
            'score' => $wcount,
            'p'     => $wcount / $sumAcc,
            'trace' => $byLevel ? ($levels[$tok] ?? []) : null,
        ];
    }
    usort($items, fn($a,$b)=> $b['score']<=>$a['score']);
    if (count($items) > $topK) $items = array_slice($items, 0, $topK);

    return ['candidates'=>$items, 'used_levels'=>$usedLevels, 'norm_sum'=>$sumAcc];
}

// ---------- Сканирование папки моделей ----------
function scan_models(string $dir): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    foreach (glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
        $out[] = [
            'path'  => $path,
            'name'  => basename($path),
            'mtime' => @filemtime($path) ?: 0,
            'size'  => @filesize($path) ?: 0,
        ];
    }
    // сортируем по дате (новые первыми)
    usort($out, fn($a,$b)=> $b['mtime'] <=> $a['mtime']);
    return $out;
}

function human_size(int $bytes): string {
    $u = ['B','KB','MB','GB','TB'];
    $i = 0;
    $v = (float)$bytes;
    while ($v >= 1024 && $i < count($u)-1) { $v /= 1024; $i++; }
    return sprintf('%.1f %s', $v, $u[$i]);
}

// ---------- Параметры ----------
$modelsDir = (string) arg('dir', __DIR__ . DIRECTORY_SEPARATOR . 'Models');
$modelPath = (string) arg('model', '');
$pickIdx   = arg('pick', null);
$listOnly  = (int) arg('list', 0);
$format    = (string) arg('format', $isCli ? 'text' : 'html'); // html|json|text

$ctxStr    = (string) arg('ctx', '');
$findRe    = (string) arg('find', '');   // регэксп для поиска по контекстам
$findLvl   = (int) arg('level', 2);      // уровень n-грамм для поиска (2 = биграммы)
$findLimit = (int) arg('find_limit', 50);
$topK      = max(1, (int) arg('k', 20));
$lambda    = max(0.0, min(1.0, (float) arg('lambda', 0.4)));
$lower     = (int) arg('lower', 0);
$normPunct = (int) arg('normalize_punct', 1);
$showLevels= (int) arg('show_levels', 1);

// ---------- Выбор модели ----------
$models = scan_models($modelsDir);

if ($isCli) {
    if ($listOnly) {
        if (!$models) fail("No models found in: $modelsDir");
        fwrite(STDOUT, "Models in {$modelsDir}:\n");
        foreach ($models as $i=>$m) {
            fwrite(STDOUT, sprintf("%2d) %-40s  %8s  %s\n", $i+1, $m['name'], human_size($m['size']), date('Y-m-d H:i', $m['mtime'])));
        }
        exit(0);
    }
    // --pick=N
    if ($modelPath === '' && $pickIdx !== null) {
        $n = (int)$pickIdx;
        if ($n < 1 || $n > count($models)) fail("pick index out of range, use --list first");
        $modelPath = $models[$n-1]['path'];
    }
    // --model=auto
    if ($modelPath === 'auto') {
        if (!$models) fail("No models found for auto in: $modelsDir");
        $modelPath = $models[0]['path']; // самый новый
    }
    if ($modelPath === '') {
        // Нет модели — покажем список и подсказку
        if (!$models) fail("No models found in: $modelsDir");
        fwrite(STDOUT, "Choose a model:\n");
        foreach ($models as $i=>$m) {
            fwrite(STDOUT, sprintf("%2d) %-40s  %8s  %s\n", $i+1, $m['name'], human_size($m['size']), date('Y-m-d H:i', $m['mtime'])));
        }
        fwrite(STDOUT, "\nUse one of:\n  --pick=N    (pick by index)\n  --model=PATH\n  --model=auto\n");
        exit(0);
    }
} else {
    // HTTP режим
    if ($format === 'json' && $modelPath === '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true, 'dir'=>$modelsDir, 'models'=>$models], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        exit;
    }
    if ($modelPath === 'auto') {
        if (!$models) fail("No models found for auto in: $modelsDir");
        $modelPath = $models[0]['path'];
    }
    if ($modelPath === '') {
        // Рисуем HTML-UI выбора модели
        header('Content-Type: text/html; charset=utf-8');
        $defCtx = $ctxStr ?: 'привет';
        $defK   = $topK;
        $defLam = $lambda;
        ?>
        <!doctype html>
        <html lang="ru">
        <head>
            <meta charset="utf-8">
            <title>debug_weights — Выбор модели</title>
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <style>
                body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;line-height:1.45;margin:24px;color:#111}
                .card{max-width:880px;margin:auto;border:1px solid #ddd;border-radius:12px;padding:20px;box-shadow:0 4px 14px rgba(0,0,0,.06)}
                h1{margin:0 0 12px}
                select,input,button{font:inherit}
                .row{display:flex;gap:12px;flex-wrap:wrap}
                label{font-size:14px;color:#444}
                input[type=text]{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:8px}
                select{padding:8px 10px;border:1px solid #ccc;border-radius:8px}
                .btn{padding:10px 14px;border:0;border-radius:10px;background:#111;color:#fff;cursor:pointer}
                .small{font-size:12px;color:#666}
                .table{width:100%;border-collapse:collapse;margin-top:12px}
                .table th,.table td{border-bottom:1px solid #eee;padding:8px 6px;text-align:left}
                .badge{font-size:12px;padding:2px 8px;border:1px solid #ccc;border-radius:999px}
            </style>
        </head>
        <body>
        <div class="card">
            <h1>Выбор модели N-грамм</h1>
            <?php if (!$models): ?>
                <p>В папке <code><?=htmlspecialchars($modelsDir)?></code> моделей не найдено.</p>
            <?php else: ?>
                <form method="get" action="">
                    <div class="row">
                        <div style="flex:1 1 360px">
                            <label>Модель</label><br>
                            <select name="model" required>
                                <option value="">— выберите модель —</option>
                                <option value="auto">Самая новая (auto)</option>
                                <?php foreach ($models as $m): ?>
                                    <option value="<?=htmlspecialchars($m['path'])?>">
                                        <?=htmlspecialchars($m['name'])?> — <?=human_size($m['size'])?> — <?=date('Y-m-d H:i',$m['mtime'])?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="small">Каталог: <code><?=htmlspecialchars($modelsDir)?></code></div>
                        </div>
                    </div>

                    <div class="row" style="margin-top:12px">
                        <div style="flex:1 1 320px">
                            <label>Контекст (ctx)</label>
                            <input type="text" name="ctx" value="<?=htmlspecialchars($defCtx)?>" placeholder="привет">
                        </div>
                        <div>
                            <label>Top-K (k)</label>
                            <input type="text" name="k" value="<?=htmlspecialchars((string)$defK)?>" style="width:90px">
                        </div>
                        <div>
                            <label>λ (backoff)</label>
                            <input type="text" name="lambda" value="<?=htmlspecialchars((string)$defLam)?>" style="width:90px">
                        </div>
                        <div>
                            <label><input type="checkbox" name="lower" value="1"> lower</label><br>
                            <label><input type="checkbox" name="normalize_punct" value="1" checked> normalize</label>
                        </div>
                    </div>

                    <div class="row" style="margin-top:14px">
                        <button class="btn" type="submit">Показать топ</button>
                        <a class="badge" href="?format=json">Список моделей (JSON)</a>
                    </div>
                </form>

                <table class="table">
                    <thead><tr><th>#</th><th>Файл</th><th>Размер</th><th>Дата</th></tr></thead>
                    <tbody>
                    <?php foreach ($models as $i=>$m): ?>
                        <tr>
                            <td><?=($i+1)?></td>
                            <td><?=htmlspecialchars($m['name'])?></td>
                            <td><?=human_size($m['size'])?></td>
                            <td><?=date('Y-m-d H:i',$m['mtime'])?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// ---------- Основная логика (к моменту сюда modelPath уже определён) ----------
$ctxStrNorm = normalize_text($ctxStr, (bool)$lower, (bool)$normPunct);
$model = load_model($modelPath);
if ($findRe !== '') {
    // берём явную модель, иначе самую новую из каталога
    $mp = $modelPath ?: ($models[0]['path'] ?? '');
    if ($mp === '') fail("No models found for find in: $modelsDir");
    $model = load_model($mp);
    $hits = find_contexts($model, max(2,$findLvl), $findRe, $findLimit);

    if ($isCli) {
        if (!$hits) {
            fwrite(STDOUT, "No contexts matched at level ".max(2,$findLvl)." for /{$findRe}/\n");
            exit(0);
        }
        fwrite(STDOUT, "Contexts matched (level ".max(2,$findLvl).") for /{$findRe}/:\n");
        $i=1;
        foreach ($hits as $h) {
            fwrite(STDOUT, sprintf("%2d) [n=%d] sum=%d  variants=%d\n    %s\n",
                $i++, $h['level'], $h['count_sum'], $h['variants'], $h['ctx']));
        }
        exit(0);
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => true,
            'model' => basename($mp),
            'find'  => $findRe,
            'level' => max(2,$findLvl),
            'limit' => $findLimit,
            'hits'  => $hits,
        ], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        exit;
    }
}
$ctxTokens = tokenize_simple($ctxStrNorm);
$res = top_candidates($model, $ctxTokens, $topK, $lambda, true);

// ---------- Вывод ----------
if ($isCli) {
    fwrite(STDOUT, "Model: ".basename($modelPath)." (N={$model['N']})\n");
    fwrite(STDOUT, "CTX:   \"{$ctxStrNorm}\"  [".implode(' | ', $ctxTokens)."]\n");
    if ($res['used_levels']) {
        fwrite(STDOUT, "Backoff path:\n");
        foreach ($res['used_levels'] as $u) {
            fwrite(STDOUT, sprintf("  n=%d  ctx=[%s]  w=%.3f  nexts=%d\n", $u['lvl'], $u['ctx'], $u['w'], $u['unique_nexts']));
        }
    }
    fwrite(STDOUT, "\nTop-$topK candidates:\n");
    $i=1;
    foreach ($res['candidates'] as $c) {
        $p = sprintf('%.4f', $c['p']);
        $sc= sprintf('%.1f', $c['score']);
        fwrite(STDOUT, sprintf("%2d. %-24s  p=%s  score=%s\n", $i++, $c['token'], $p, $sc));
    }
    fwrite(STDOUT, "\n--- JSON ---\n");
    $out = [
        'ok' => true,
        'model' => basename($modelPath),
        'model_path' => $modelPath,
        'N' => $model['N'],
        'ctx' => $ctxStrNorm,
        'ctx_tokens' => $ctxTokens,
        'params' => ['k'=>$topK, 'lambda'=>$lambda, 'lower'=>$lower, 'normalize_punct'=>$normPunct],
        'used_levels' => $res['used_levels'],
        'top' => $res['candidates'],
    ];
    echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'model' => basename($modelPath),
        'model_path' => $modelPath,
        'N' => $model['N'],
        'ctx' => $ctxStrNorm,
        'ctx_tokens' => $ctxTokens,
        'params' => ['k'=>$topK, 'lambda'=>$lambda, 'lower'=>$lower, 'normalize_punct'=>$normPunct],
        'used_levels' => $res['used_levels'],
        'top' => $res['candidates'],
    ], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
}
