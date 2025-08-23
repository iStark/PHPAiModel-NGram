<?php
// merge_weights.php — объединяет несколько JSON-весов (словные N-граммы) в один файл,
// сохраняя все реально имеющиеся порядки n-грамм (union), без усечения по min(N_i).
// Скрипт подрезает модель под целевой размер через прунинг.
//
// Итоги:
//  - N = max(N_i, 1 + max(orders)) — чтобы генератор мог бэкофиться до высоких порядков.
//  - levels = union ключей grams из всех входов (например: 1..20,25,30,40,60,80,99).
//  - meta.allowed_ngrams — явный список порядков, чтобы легко проверить результат.

@ini_set('memory_limit', '4096M');
@set_time_limit(0);

function load_model(string $path){
    $s=@file_get_contents($path);
    return $s? json_decode($s,true): null;
}
function save_json(string $path, array $m){
    $s=json_encode($m, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    return $s!==false && file_put_contents($path,$s)!==false;
}
function json_size_bytes(array $m){
    $s=json_encode($m, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    return $s===false? -1: strlen($s);
}

function merge_models(array $paths): array {
    $maxN = 2;                 // будем брать максимум N_i
    $orders_set = [];          // union порядков, которые реально присутствуют
    $uni = [];
    $grams = [];               // граммы создадим "на лету"

    // 1) Первый проход: собрать maxN и union порядков
    $models = [];
    foreach ($paths as $p){
        $m = load_model($p);
        if (!$m || !is_array($m)) continue;
        $models[] = $m;
        $Ni = (int)($m['N'] ?? 10);
        if ($Ni > $maxN) $maxN = $Ni;

        foreach ((array)($m['grams'] ?? []) as $nStr => $table){
            $n = (int)$nStr;
            if ($n >= 1) $orders_set[$n] = true;
        }
    }

    if (!$models){
        return ['N'=>10,'unigram'=>[],'grams'=>[],'meta'=>['note'=>'no models loaded']];
    }

    // Если вдруг ни у кого нет grams — вернём только униграммы.
    if (empty($orders_set)) {
        $uni = [];
        foreach ($models as $m) {
            foreach ((array)($m['unigram'] ?? []) as $t=>$c) $uni[$t] = ($uni[$t] ?? 0) + (int)$c;
        }
        return ['N'=>max(10,$maxN),'unigram'=>$uni,'grams'=>[],
            'meta'=>['allowed_ngrams'=>[],'note'=>'no grams in sources']];
    }

    // 2) Инициализируем контейнеры под все реально встречающиеся порядки (union)
    $orders = array_keys($orders_set);
    sort($orders, SORT_NUMERIC);
    foreach ($orders as $n) $grams[(string)$n] = [];

    // 3) Суммируем униграммы и граммы по ВСЕМ уровням из union
    foreach ($models as $m){
        foreach ((array)($m['unigram'] ?? []) as $t=>$c){
            $uni[$t] = ($uni[$t] ?? 0) + (int)$c;
        }
        foreach ((array)($m['grams'] ?? []) as $nStr=>$table){
            $n = (int)$nStr;
            if (!isset($grams[$nStr])) continue; // этот порядок не в union (на всякий случай)
            foreach ((array)$table as $ctx=>$dist){
                if (!isset($grams[$nStr][$ctx])) $grams[$nStr][$ctx] = [];
                foreach ((array)$dist as $tok=>$cnt){
                    $grams[$nStr][$ctx][$tok] = ($grams[$nStr][$ctx][$tok] ?? 0) + (int)$cnt;
                }
            }
        }
    }

    // 4) Вычислим "разумный" N итоговой модели:
    //    пусть будет max( maxN, 1 + max(orders) ), чтобы генератор мог бэкофиться вплоть до самых длинных уровней.
    $maxOrder = max($orders);
    $N = max($maxN, $maxOrder + 1);

    return [
        'N' => $N,
        'unigram' => $uni,
        'grams' => $grams,
        'meta' => [
            'allowed_ngrams' => $orders,
        ]
    ];
}

function prune(array $counts, int $min_freq, int $min_ctx_sum, int $top_m): array {
    $N=$counts['N']; $uni=$counts['unigram']; $grams=$counts['grams'];

    if ($min_freq>1) foreach($uni as $t=>$c) if($c<$min_freq) unset($uni[$t]);

    foreach($grams as $n=>&$g){
        foreach($g as $k=>&$dist){
            if ($min_freq>1) foreach($dist as $t=>$c) if($c<$min_freq) unset($dist[$t]);
            if (empty($dist)){ unset($g[$k]); continue; }
            $sum=0; foreach($dist as $c) $sum+=$c;
            if ($sum<$min_ctx_sum){ unset($g[$k]); continue; }
            if ($top_m>0 && count($dist)>$top_m){
                arsort($dist);
                $dist=array_slice($dist,0,$top_m,true);
            }
        }
        if (empty($g)) unset($grams[$n]); // если всё вычистили — убираем уровень
    } unset($g);

    // Пересчитать список уровней, сохранить его явно в meta
    $orders = array_map('intval', array_keys($grams));
    sort($orders, SORT_NUMERIC);

    return [
        'N'=>$N,
        'unigram'=>$uni,
        'grams'=>$grams,
        'meta'=>[
            'min_freq'=>$min_freq,
            'min_ctx_sum'=>$min_ctx_sum,
            'top_m_per_ctx'=>$top_m,
            'allowed_ngrams'=>$orders
        ]
    ];
}

$ok=$err=null; $log=[];
if ($_SERVER['REQUEST_METHOD']==='POST'){
    $list = trim((string)($_POST['files'] ?? ''));
    $target = (float)($_POST['target_mb'] ?? 50.0);
    $outf = trim((string)($_POST['out_file'] ?? 'weights50_merged.json'));

    $paths = array_values(array_filter(array_map('trim', explode("\n",$list))));
    foreach ($paths as &$p){ $p = __DIR__ . '/' . $p; }

    if (!$paths){ $err='Список файлов пуст.'; }
    elseif (!preg_match('/^[\w\.\-]+$/u', $outf)){ $err='Некорректное имя файла.'; }
    else {
        $counts = merge_models($paths);

        // авто-подбор прунинга
        $minf=1; $minc=3; $topm=24;
        $target_bytes = (int)round($target*1024*1024);
        $loose = (int)round($target_bytes*1.10);
        $best=null; $bestb=PHP_INT_MAX;

        for($iter=1;$iter<=8;$iter++){
            $m = prune($counts, $minf, $minc, $topm);
            $b = json_size_bytes($m);
            $orders = implode(',', $m['meta']['allowed_ngrams'] ?? []);
            $log[] = "iter {$iter}: min_freq={$minf}, min_ctx_sum={$minc}, top_m={$topm} => ".number_format($b/1048576,2)." MB; levels=[{$orders}]";
            if ($b>0 && $b<$bestb){ $best=$m; $bestb=$b; }
            if ($b > $loose){
                if ($b > 3*$target_bytes){ $minc = (int)max($minc+2, ceil($minc*1.7)); }
                else { $minc = (int)max($minc+1, ceil($minc*1.3)); }
                if ($topm>16) $topm=16; elseif($topm>12)$topm=12; elseif($topm>8)$topm=8; else $minf=min(3,$minf+1);
                continue;
            }
            break;
        }

        if(!$best){ $err='Не удалось собрать merged-модель.'; }
        else {
            if (save_json(__DIR__.'/'.$outf, $best)){
                $ok = "Готово: {$outf} (~".number_format($bestb/1048576,2)." MB)";
            } else { $err='Не удалось записать файл (права на запись?)'; }
        }
    }
}
?>
<!doctype html><meta charset="utf-8"><title>Merge весов → один файл (~50MB)</title>
<style>body{font-family:system-ui,Arial;margin:2rem;max-width:980px}.ok{color:#070}.err{color:#900}textarea{width:100%;height:160px}</style>
<h1>Слияние весов в один (~50MB)</h1>
<?php if(!empty($log)) echo '<pre>'.htmlspecialchars(implode("\n",$log)).'</pre>'; ?>
<?php if($ok): ?><p class="ok"><?=htmlspecialchars($ok)?></p><?php endif; ?>
<?php if($err): ?><p class="err"><?=htmlspecialchars($err)?></p><?php endif; ?>
<form method="post">
    <p>Файлы (по одному на строке, лежат рядом со скриптом):</p>
    <p><textarea name="files" placeholder="например:
weights100_20k.json
weights100_180k.json
weights10_greetings_boost.json"></textarea></p>
    <p>Целевой размер (MB): <input type="number" step="1" name="target_mb" value="<?=isset($_POST['target_mb'])?(float)$_POST['target_mb']:50?>">
        &nbsp;Файл: <input type="text" name="out_file" value="<?=htmlspecialchars($_POST['out_file'] ?? 'weights50_merged.json')?>"></p>
    <p><button>Слить и подрезать под размер</button></p>
</form>
