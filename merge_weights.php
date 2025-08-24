<?php
/*
 * PHPAiModel-NGram — merge_weights.php
 * Merge multiple JSON weight files (word-level N‑grams) into a single file,
 * preserving the UNION of actually present n‑gram orders (no truncation by min(N_i)).
 * Includes size‑target pruning to fit an approximate output size.
 *
 * Developed by: Artur Strazewicz — concept, architecture, PHP N‑gram runtime, UI.
 * Year: 2025. License: MIT.
 *
 * Links:
 *   GitHub:      https://github.com/iStark/PHPAiModel-NGram
 *   LinkedIn:    https://www.linkedin.com/in/arthur-stark/
 *   TruthSocial: https://truthsocial.com/@strazewicz
 *   X (Twitter): https://x.com/strazewicz
 *
 * Result notes:
 *  - N = max(N_i, 1 + max(orders)) so the generator can back off to the highest orders present.
 *  - levels = union of all keys in grams from all inputs (e.g., 1..20,25,30,40,60,80,99).
 *  - meta.allowed_ngrams — explicit list of resulting orders for quick inspection.
 */

@ini_set('memory_limit', '4096M');
@set_time_limit(0);

/** Load a model JSON file into array (or null on failure). */
function load_model(string $path){
    $s=@file_get_contents($path);
    return $s? json_decode($s,true): null;
}

/** Save array as JSON file (UTF‑8, no escaping), return bool. */
function save_json(string $path, array $m){
    $s=json_encode($m, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    return $s!==false && file_put_contents($path,$s)!==false;
}

/** Calculate encoded JSON size in bytes (or -1 on error). */
function json_size_bytes(array $m){
    $s=json_encode($m, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    return $s===false? -1: strlen($s);
}

/**
 * Merge models:
 *  - Takes union of available gram orders across all sources.
 *  - Sums counts for unigrams and for every context→next on every order in the union.
 *  - Computes resulting N as max( maxN, 1 + max(order) ).
 */
function merge_models(array $paths): array {
    $maxN = 2;
    $orders_set = [];          // union of existing orders
    $uni = [];
    $grams = [];               // will be allocated lazily

    // 1) First pass: collect maxN and union of orders
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

    // If none of the sources has grams — return only unigrams
    if (empty($orders_set)) {
        $uni = [];
        foreach ($models as $m) {
            foreach ((array)($m['unigram'] ?? []) as $t=>$c) $uni[$t] = ($uni[$t] ?? 0) + (int)$c;
        }
        return ['N'=>max(10,$maxN),'unigram'=>$uni,'grams'=>[],
            'meta'=>['allowed_ngrams'=>[],'note'=>'no grams in sources']];
    }

    // 2) Pre-allocate containers for all actually present orders (union)
    $orders = array_keys($orders_set);
    sort($orders, SORT_NUMERIC);
    foreach ($orders as $n) $grams[(string)$n] = [];

    // 3) Sum unigrams and grams across ALL union levels
    foreach ($models as $m){
        foreach ((array)($m['unigram'] ?? []) as $t=>$c){
            $uni[$t] = ($uni[$t] ?? 0) + (int)$c;
        }
        foreach ((array)($m['grams'] ?? []) as $nStr=>$table){
            $n = (int)$nStr;
            if (!isset($grams[$nStr])) continue; // safety: ignore orders not in union
            foreach ((array)$table as $ctx=>$dist){
                if (!isset($grams[$nStr][$ctx])) $grams[$nStr][$ctx] = [];
                foreach ((array)$dist as $tok=>$cnt){
                    $grams[$nStr][$ctx][$tok] = ($grams[$nStr][$ctx][$tok] ?? 0) + (int)$cnt;
                }
            }
        }
    }

    // 4) Compute "reasonable" N for the merged model:
    //    max( maxN, 1 + max(orders) ) so the generator can back off to the longest available level.
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

/**
 * Prune merged counts to approximately fit the target size:
 *  - Drop tokens/transitions with freq < min_freq
 *  - Drop contexts whose sum < min_ctx_sum
 *  - Keep only top_m transitions per context
 * Returns the pruned structure with updated meta.allowed_ngrams.
 */
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
        if (empty($g)) unset($grams[$n]); // drop empty levels
    } unset($g);

    // Recompute list of available levels and save it in meta
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

    if (!$paths){ $err='File list is empty.'; }
    elseif (!preg_match('/^[\w\.\-]+$/u', $outf)){ $err='Invalid output filename.'; }
    else {
        $counts = merge_models($paths);

        // Heuristic pruning loop to approach target size
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

        if(!$best){ $err='Failed to build merged model.'; }
        else {
            if (save_json(__DIR__.'/'.$outf, $best)){
                $ok = "Done: {$outf} (~".number_format($bestb/1048576,2)." MB)";
            } else { $err='Unable to write file (check permissions).'; }
        }
    }
}
?>
<!doctype html>
<meta charset="utf-8">
<title>Merge weights → single file (~50MB)</title>
<style>
    body{font-family:system-ui,Arial;margin:2rem;max-width:980px}
    .ok{color:#070}.err{color:#900}textarea{width:100%;height:160px}
    .muted{color:#666;font-size:.9em}
</style>
<h1>Merge N‑gram weights into one (~50MB)</h1>
<p class="muted">
    Developed by <strong>Artur Strazewicz</strong>, MIT license.
    <a href="https://github.com/iStark/PHPAiModel-NGram" target="_blank" rel="noopener">GitHub</a> •
    <a href="https://www.linkedin.com/in/arthur-stark/" target="_blank" rel="noopener">LinkedIn</a> •
    <a href="https://truthsocial.com/@strazewicz" target="_blank" rel="noopener">Truth Social</a> •
    <a href="https://x.com/strazewicz" target="_blank" rel="noopener">X (Twitter)</a>
</p>

<?php if(!empty($log)) echo '<pre>'.htmlspecialchars(implode("\n",$log)).'</pre>'; ?>
<?php if($ok): ?><p class="ok"><?=htmlspecialchars($ok)?></p><?php endif; ?>
<?php if($err): ?><p class="err"><?=htmlspecialchars($err)?></p><?php endif; ?>

<form method="post">
    <p>Files (one per line, relative to this script):</p>
    <p><textarea name="files" placeholder="e.g.:
weights100_20k.json
weights100_180k.json
weights10_greetings_boost.json"></textarea></p>

    <p>
        Target size (MB):
        <input type="number" step="1" name="target_mb" value="<?=isset($_POST['target_mb'])?(float)$_POST['target_mb']:50?>">
        &nbsp;Output file:
        <input type="text" name="out_file" value="<?=htmlspecialchars($_POST['out_file'] ?? 'weights50_merged.json')?>">
    </p>

    <p><button>Merge & prune to size</button></p>
</form>
