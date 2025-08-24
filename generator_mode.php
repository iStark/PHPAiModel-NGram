<?php
// generator_mode.php — генератор весов N-грамм (RU/EN) с расширенными интентами ×10
// - Без токена "Ассистент" в словаре/граммах
// - Аугментации: междометия, пунктуация, эмодзи, лёгкие опечатки, регистры, редкий код-микс
// - Прунинг редких токенов/переходов, усиленные стоп-переходы . ! ? -> \n
// Запуск:
//   CLI:  php generator_mode.php --turns=50000 --N=12 --out=Models/weights_dialog_ru_en_50k.json
//   HTTP: /generator_mode.php?turns=50000&N=12&out=Models/weights_dialog_ru_en_50k.json
declare(strict_types=1);
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}

// ---------- параметры ----------
function arg($name, $default=null) {
    if (php_sapi_name()==='cli') {
        foreach ($GLOBALS['argv'] ?? [] as $a) {
            if (preg_match('/^--'.preg_quote($name,'/').'(=(.*))?$/u', $a, $m)) {
                return $m[2] ?? '1';
            }
        }
        return $default;
    }
    return $_POST[$name] ?? $_GET[$name] ?? $default;
}

$N            = max(2, min(24, (int)(arg('N', 12))));
$turns        = max(1000, min(500000, (int)(arg('turns', 50000))));
$min_unigram  = max(1, min(50, (int)(arg('min_unigram', 2))));
$min_gram     = max(1, min(50, (int)(arg('min_gram', 2))));
$seed         = (int)(arg('seed', 42));
$punct_bonus  = max(0, min(10000, (int)(arg('punct_bonus', 1600))));
$out_path     = (string)(arg('out', 'Models/weights_dialog_ru_en_'.$turns.'.json'));

$MODELS_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'Models';
if (!is_dir($MODELS_DIR)) { @mkdir($MODELS_DIR, 0777, true); }
mt_srand($seed);

// ---------- расширенные интенты (×10) ----------
// ВАЖНО: каждая запись — массив из ДВУХ массивов: [ [варианты пользователя], [варианты ответа] ]
$ru_intents = [
    "greet" => [
        ["привет","здравствуйте","добрый день","добрый вечер","доброе утро","хай","йо","йоу","салют","приветствую",
            "доброго времени суток","здорово","алло","приветики","хей","хэллоу","хелло","шалом","прив","привет-привет"],
        ["привет! как я могу помочь?","здравствуйте! чем могу помочь?","рад вас видеть. чем помочь?","хай! о чём поговорим?",
            "добро пожаловать. что делаем?","приветствую! с чего начнём?","на связи. подскажите задачу.","готов помочь — расскажите, что нужно.",
            "привет! готов к работе.","здравствуйте! по какому вопросу?"]
    ],
    "wellbeing" => [
        ["как дела","как ты","как жизнь","как настроение","как сам","как поживаешь","как у тебя дела","как сегодня","как там","как идут дела","как оно","как ощущения"],
        ["отлично, спасибо. чем помочь?","нормально. что нужно?","хорошо. расскажите подробнее.","в порядке — чем могу быть полезен?",
            "всё ок. о чём речь?","дела идут. что требуется?","работаю. какую задачу решаем?","норм. давайте к сути.","хорошо! что подсказать?","спасибо, неплохо. чем помочь?"]
    ],
    "smalltalk_news" => [
        ["что нового","что по новостям","что слышно","что у тебя нового","что происходит","какие новости","как дела по новостям","что там","что по делу","какие апдейты"],
        ["работаю над ответами. а у вас?","понемногу. какая задача?","дела идут. чем помочь?","всё стабильно. о чём поговорим?",
            "есть идеи — давайте обсудим.","могу подсказать по теме — расскажите контекст.","готов подключиться. что за задача?","новостей немного. что интересует?"]
    ],
    "identity" => [
        ["кто ты","ты кто","кто там","кто ты такой","что ты такое","что ты за бот","кто вы","кем являешься","что умеешь","чем занимаешься"],
        ["я небольшой чат на n-граммах.","я лёгкая демо-модель на php.","я помогаю короткими ответами по контексту.",
            "я предсказываю следующее слово по контексту.","компактный помощник без внешних сервисов.","учебная модель — отвечаю кратко."]
    ],
    "capabilities" => [
        ["что ты умеешь","какие у тебя функции","чем можешь помочь","где полезен","в чём твоя роль","какие задачи решаешь","что входит в возможности"],
        ["могу отвечать на простые вопросы и помогать с формулировками.","подскажу шаги и дам краткие советы.",
            "помогаю с текстом, пояснениями и шаблонами.","сгенерирую короткие примеры или структуры.","подскажу базовую логику и варианты."]
    ],
    "limitations" => [
        ["чего ты не умеешь","какие ограничения","где не поможешь","чего ожидать не стоит","есть ли ограничения","в чём слабые стороны"],
        ["не имею доступа к интернету и реальному времени.","не вызываю внешние api и не читаю файлы сам.",
            "не даю проф. рекомендаций (медицина/право).","отвечаю кратко на учебном корпусе.","лучше с общими, короткими задачами."]
    ],
    "help" => [
        ["помоги","нужна помощь","подскажи","помощь нужна","подскажешь","подкинь идею","подскажи, как сделать","помоги разобраться","хочу совет","есть вопрос"],
        ["конечно. что именно?","готов помочь. опишите задачу.","давайте разберёмся.","с радостью. в чём сложность?",
            "расскажите детали — предложу шаги.","что требуется на выходе?","с чего начнём? дайте контекст."]
    ],
    "clarify" => [
        ["уточни","можешь уточнить","поясни","раскрой подробнее","дай контекст","что ты имел в виду","расшифруй идею","приведи пример","объясни простыми словами"],
        ["коротко поясню:","сформулирую проще:","разверну мысль:","идея такая:","смысл в следующем:","пример:","по шагам:"]
    ],
    "repeat" => [
        ["повтори","скажи ещё раз","не расслышал","продублируй","повторишь","ещё раз, пожалуйста"],
        ["дублирую:","ещё раз тезисно:","коротко повторю:","сводка:"]
    ],
    "thanks" => [
        ["спасибо","благодарю","мерси","огромное спасибо","спасибо большое","благодарствую","респект","пасиб","спасибки","сенкс"],
        ["пожалуйста!","всегда рад помочь.","обращайтесь.","не за что.","рад, что пригодилось.","пожалуйста, удачи!","на здоровье!"]
    ],
    "apology" => [
        ["извини","сорян","прости","прошу прощения","мои извинения"],
        ["ничего страшного.","всё ок — продолжаем.","бывает. давайте дальше.","понимаю. вернёмся к задаче?"]
    ],
    "compliment" => [
        ["круто","отлично","супер","топ","нормально","годно","неплохо"],
        ["спасибо!","благодарю за оценку.","рад помочь.","приятно слышать!"]
    ],
    "bye" => [
        ["пока","до встречи","до связи","всего доброго","хорошего дня","до скорого","прощай","увидимся","созвонимся","пишите"],
        ["до связи!","хорошего дня!","пока-пока!","до скорого!","удачи!","буду на связи."]
    ],
    "ok" => [
        ["окей","хорошо","ладно","понял","принято","ок","окэй","согласен"],
        ["окей.","хорошо.","принято.","понял.","есть.","ок, продолжим."]
    ],
    "time" => [
        ["сколько времени","который час","скажи время","какое сейчас время"],
        ["точное время не проверяю, могу помочь с задачей.","часы недоступны, но подскажу по логике.","давайте к сути запроса."]
    ],
    "weather" => [
        ["какая погода","будет дождь","что с погодой","снег идёт"],
        ["погоду не проверяю, но помогу с планом подготовки.","онлайн-данных нет. могу предложить чек-лист."]
    ],
    "food" => [
        ["что по еде","любимая еда","посоветуй перекус","что на обед"],
        ["могу накидать идеи меню.","подскажу универсальные варианты."]
    ],
    "music" => [
        ["что послушать","любимая музыка","подборка треков"],
        ["предложу жанры и сценарии прослушивания.","вот направления:"]
    ],
    "movies" => [
        ["что посмотреть","фильмы посоветуй","кино на вечер"],
        ["предложу критерии выбора и жанры.","идеи под настроение:"]
    ],
    "sport" => [
        ["как тренироваться","программа тренировок","советы по спорту"],
        ["базовые рекомендации (не мед.) — разминка/нагрузка/заминка.","схема:"]
    ],
    "travel" => [
        ["куда поехать","путешествия идеи","маршрут на выходные"],
        ["набросаю общий план без бронирований и цен.","что учесть:"]
    ],
    "bug_report" => [
        ["нашёл баг","что-то не работает","ошибка на странице","ломается"],
        ["опишите шаги воспроизведения.","уточните окружение и ожидание/факт.","чек-лист проверок:"]
    ],
    "feature_request" => [
        ["нужна фича","добавьте функцию","идея улучшения"],
        ["опишите сценарий и ожидаемый результат.","сформулируем минимальный прототип."]
    ],
    "login" => [
        ["не могу войти","проблема с логином","ошибка входа"],
        ["проверьте логин/пароль и раскладку.","попробуйте сброс пароля. что видите?"]
    ],
    "password_reset" => [
        ["забыл пароль","сбросить пароль","восстановить доступ"],
        ["перейдите по ссылке сброса → новый пароль → подтверждение.","следуйте инструкции на сайте."]
    ],
    "language_switch" => [
        ["давай по-английски","переключись на русский","можем говорить на английском"],
        ["без проблем — отвечаю по-русски или по-английски.","сменю язык ответа. что дальше?"]
    ],
    "define" => [
        ["что такое …","дай определение","определи термин","поясни понятие"],
        ["короткое определение:","если упрощать:","проще говоря:"]
    ],
    "example" => [
        ["пример кода","пример текста","скетч решения"],
        ["эскиз примера:","черновик шаблона:","мини-пример:"]
    ],
    "math" => [
        ["посчитай","можешь прикинуть","счёт прикинь","оценка прикинуть"],
        ["прикидка и формула:","схема вычисления:"]
    ],
    "convert" => [
        ["конвертируй единицы","переведи в километры","сколько это в минутах"],
        ["общая формула и пример:","приблизим так:"]
    ],
    "schedule" => [
        ["назначим встречу","когда удобно","согласуем время"],
        ["предложу условные слоты. уточните день и диапазон.","сформулируем приглашение."]
    ],
    "reminder" => [
        ["напомни мне","сделай напоминание","напомни завтра"],
        ["зафиксируйте у себя, а я дам текст-шаблон.","шаблон напоминания:"]
    ],
];

$en_intents = [
    "greet" => [
        ["hello","hi","hey","yo","howdy","hi there","hello there","heya","hey hey","greetings","good day","good morning","good evening","sup","what’s up"],
        ["hi! how can i help?","hello! how may i assist?","hey! what do you need?","greetings — what shall we do?","welcome back. how can i help?","on it. tell me the task.","ready to help — what’s the goal?"]
    ],
    "wellbeing" => [
        ["how are you","how’s it going","how are u","how are things","how do you do","how’s life","how’s your day","you good","all good","everything okay"],
        ["great, thanks. how can i help?","i'm fine. what do you need?","good! tell me more.","doing well — what’s the task?","all good. where should we start?","fine. give me context."]
    ],
    "smalltalk_news" => [
        ["what's new","anything new","what is new","what’s up lately","any updates"],
        ["working on answers. you?","not much. what's the task?","all good. how can i help?","some progress — want details?","happy to jump in. what’s the context?"]
    ],
    "identity" => [
        ["who are you","who r u","what are you","what’s this","what are you exactly","who am i talking to","what kind of bot are you"],
        ["a tiny n-gram chat.","a small php helper.","a lightweight demo model.","i predict next word from context.","compact assistant for short tasks."]
    ],
    "capabilities" => [
        ["what can you do","capabilities","what do you handle","where can you help","what are your skills","what’s your role"],
        ["i answer short questions and outline steps.","i help with wording and small examples.","i provide concise suggestions and templates.","i sketch simple structures and logic."]
    ],
    "limitations" => [
        ["limits","what can’t you do","where do you fail","any constraints","what to not expect"],
        ["no internet/time access.","no external apis or file i/o.","not professional advice.","best for short, general tasks.","small educational corpus."]
    ],
    "help" => [
        ["help me","need help","could you help","give me a hand","i need assistance","can you assist","support needed"],
        ["sure. what exactly?","happy to help. describe the task.","let's figure it out.","absolutely — what’s the goal?","give me details and i’ll propose steps."]
    ],
    "clarify" => [
        ["clarify","could you clarify","explain","expand","give context","what do you mean","can you break it down"],
        ["briefly:","in simple terms:","the idea is:","here's the gist:","example:"]
    ],
    "repeat" => [
        ["repeat","say again","once more please","can you repeat","repeat that"],
        ["repeating:","once more, briefly:","summary:","again:"]
    ],
    "thanks" => [
        ["thanks","thank you","thx","many thanks","much appreciated","cheers","big thanks"],
        ["you're welcome!","anytime.","glad to help.","my pleasure.","happy to help!"]
    ],
    "apology" => [
        ["sorry","my bad","apologies","i’m sorry","pardon"],
        ["no worries.","all good — let’s continue.","happens. moving on.","understood — proceed?"]
    ],
    "compliment" => [
        ["nice","great","awesome","cool","dope","neat","solid"],
        ["thanks!","appreciate it.","glad it helps.","cheers!"]
    ],
    "bye" => [
        ["bye","see you","goodbye","later","catch you later","take care","see ya","talk soon","have a nice day","till next time"],
        ["see you!","have a nice day!","bye-bye!","talk soon!","cheers!","take care!"]
    ],
    "ok" => [
        ["okay","ok","alright","got it","understood","sounds good","roger"],
        ["okay.","alright.","noted.","got it.","sure, let’s proceed."]
    ],
    "time" => [
        ["what time is it","tell the time","time please","current time"],
        ["i can't check time here, but i can help with logic.","no clock access — let's focus on your task."]
    ],
    "weather" => [
        ["weather now","is it raining","forecast today","how’s the weather"],
        ["i don’t check weather here, but i can outline prep steps.","no live weather; i can suggest a checklist."]
    ],
    "food" => [
        ["food ideas","what to eat","lunch ideas","suggest a snack"],
        ["i can list generic menu ideas.","here are common options:"]
    ],
    "music" => [
        ["what to listen","music recommendations","playlist ideas"],
        ["i can suggest genres and listening scenarios.","try these directions:"]
    ],
    "movies" => [
        ["what to watch","movie suggestions","film for tonight"],
        ["i can propose criteria and genres.","depending on mood, try:"]
    ],
    "sport" => [
        ["training plan","how to train","workout tips"],
        ["general tips (non-medical): warm-up / load / cool-down.","basic routine outline:"]
    ],
    "travel" => [
        ["where to go","trip ideas","weekend route"],
        ["high-level plan (no bookings/prices):","checklist to consider:"]
    ],
    "bug_report" => [
        ["found a bug","something broke","page error","it crashes"],
        ["describe repro steps.","share environment + expected vs actual.","let’s go through checks."]
    ],
    "feature_request" => [
        ["need a feature","add function","improvement idea"],
        ["describe the scenario and desired outcome.","we can outline a minimal prototype."]
    ],
    "login" => [
        ["can't log in","login issue","sign-in error"],
        ["check username/password and layout.","try password reset; what do you see?"]
    ],
    "password_reset" => [
        ["forgot password","reset password","recover access"],
        ["follow reset link → new password → confirm.","use the site's recovery instructions."]
    ],
    "language_switch" => [
        ["let’s speak russian","switch to english","change language"],
        ["no problem — i can reply in english or russian.","i'll switch the reply language. what next?"]
    ],
    "define" => [
        ["what is …","define term","give a definition","explain the concept"],
        ["short definition:","in simpler words:","the point is:"]
    ],
    "example" => [
        ["code example","text example","solution sketch"],
        ["draft example:","template sketch:","mini-example:"]
    ],
    "math" => [
        ["do the math","estimate","compute quickly","rough calculation"],
        ["i'll outline a formula and a quick estimate.","computation outline:"]
    ],
    "convert" => [
        ["convert units","miles to km","minutes to hours"],
        ["general formula and example:","approximate like this:"]
    ],
    "schedule" => [
        ["schedule a meeting","when works","find a time"],
        ["i can propose tentative slots — share day/time window.","let's prepare an invitation text."]
    ],
    "reminder" => [
        ["remind me","make a reminder","ping me tomorrow"],
        ["note it locally; i’ll give a reminder text template.","reminder template:"]
    ],
];

// ---------- аугментации ----------
$ru_interj = ["эээ","кстати","слушай","ну","окей","ладно","ммм","значит","смотри","вообще","по сути","итак"];
$en_interj = ["well","btw","listen","ok","alright","so","look","basically","by the way","anyway","right"];

$emoji_tail = ["", " 🙂", " 👋", " 😉", " ✨", " 🙌", " 👍"];
$punct_alt  = ["", ".", "!", "!!", "?!"];

function maybe(float $p): bool { return (mt_rand()/mt_getrandmax()) < $p; }
function one(array $arr) { return $arr[array_rand($arr)]; }

function sprinkle_interjection(string $text, string $lang): string {
    global $ru_interj, $en_interj;
    if (maybe(0.25)) {
        $pool = $lang==='ru' ? $ru_interj : $en_interj;
        return one($pool).", ".$text;
    }
    return $text;
}
function vary_punct(string $text): string {
    global $punct_alt;
    return rtrim($text).one($punct_alt);
}
function lite_typos(string $text): string {
    if (maybe(0.12)) $text = preg_replace('/([А-Яа-яA-Za-z])/u', '$1$1', $text, 1) ?? $text;
    if (maybe(0.15)) $text = str_replace(',', '', $text);
    return $text;
}
function case_mix(string $text): string {
    if (maybe(0.10)) return mb_strtoupper($text,'UTF-8');
    if (maybe(0.10)) return mb_convert_case($text, MB_CASE_TITLE, 'UTF-8');
    return $text;
}
function decorate_reply(string $text, string $lang): string {
    global $emoji_tail;
    return vary_punct($text).one($emoji_tail);
}
function tokenize(string $text): array {
    preg_match_all('/\n|[A-Za-zА-Яа-яЁё0-9]+|[^\sA-Za-zА-Яа-яЁё0-9]/u', $text, $m);
    return $m[0] ?? [];
}

// ---------- генерация корпуса ----------
$buf = [];
for ($i=0; $i<$turns; $i++) {
    $lang = ($i % 2 === 0) ? 'ru' : 'en';
    if ($lang==='ru') {
        $key = array_rand($ru_intents);
        $pair = $ru_intents[$key];
        // list($U,$A) безопаснее для старых версий, чем [$U,$A] =
        list($U, $A) = $pair;
        $u = sprinkle_interjection(one($U), 'ru');
        $u = vary_punct(lite_typos(case_mix($u)));
        $a = decorate_reply(one($A), 'ru');
        if (maybe(0.08)) $a .= " OK.";
    } else {
        $key = array_rand($en_intents);
        $pair = $en_intents[$key];
        list($U, $A) = $pair;
        $u = sprinkle_interjection(one($U), 'en');
        $u = vary_punct(lite_typos(case_mix($u)));
        $a = decorate_reply(one($A), 'en');
        if (maybe(0.08)) $a .= " Окей.";
    }
    $buf[] = "Пользователь : ".$u."\n".$a."\n";
}
$corpus = implode('', $buf);
$tokens = tokenize($corpus);

// ---------- счётчики ----------
$unigram = [];
$grams = [];
for ($k=1; $k<$N; $k++) $grams[(string)$k] = [];

foreach ($tokens as $t) {
    if ($t === "Ассистент") continue;
    $unigram[$t] = ($unigram[$t] ?? 0) + 1;
}
$L = count($tokens);
for ($i=0; $i<$L; $i++) {
    $nxt = $tokens[$i];
    if ($nxt === "Ассистент") continue;
    for ($n=1; $n<$N; $n++) {
        if ($i-$n < 0) break;
        $ctx = array_slice($tokens, $i-$n, $n);
        $key = implode("\t", $ctx);
        if (!isset($grams[(string)$n][$key])) $grams[(string)$n][$key] = [];
        $grams[(string)$n][$key][$nxt] = ($grams[(string)$n][$key][$nxt] ?? 0) + 1;
    }
}

// ---------- прунинг ----------
foreach (array_keys($unigram) as $tok) { if ($unigram[$tok] < $min_unigram) unset($unigram[$tok]); }
for ($n=1; $n<$N; $n++) {
    foreach (array_keys($grams[(string)$n]) as $key) {
        $dist = $grams[(string)$n][$key];
        $filt = [];
        foreach ($dist as $tok=>$cnt) {
            if (isset($unigram[$tok]) && $cnt >= $min_gram) $filt[$tok] = $cnt;
        }
        if ($filt) $grams[(string)$n][$key] = $filt; else unset($grams[(string)$n][$key]);
    }
}

// ---------- стоп-переходы после пунктуации ----------
foreach (['.','!','?'] as $p) {
    if (!isset($grams["1"][$p])) $grams["1"][$p] = [];
    $grams["1"][$p]["\n"] = ($grams["1"][$p]["\n"] ?? 0) + $punct_bonus;
}
$unigram["\n"] = max($unigram["\n"] ?? 0, 30);

// ---------- сборка и сохранение ----------
$weights = [
    "N" => $N,
    "unigram" => $unigram,
    "grams" => $grams,
    "meta" => [
        "domains" => ["smalltalk-ru-en-augmented"],
        "note"    => "generated by generator_mode.php; no token 'Ассистент'; turns={$turns}; seed={$seed}",
        "created" => time(),
        "size_tokens" => $L,
        "turns"  => $turns,
    ],
];

$out_abs = (strpos($out_path, DIRECTORY_SEPARATOR) === 0)
    ? $out_path
    : (__DIR__ . DIRECTORY_SEPARATOR . $out_path);

@mkdir(dirname($out_abs), 0777, true);

$json = json_encode($weights, JSON_UNESCAPED_UNICODE);
if ($json === false) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "json_encode_failed",
        "json_last_error" => json_last_error(),
        "json_last_error_msg" => json_last_error_msg(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$bytes = @file_put_contents($out_abs, $json);
if ($bytes === false) {
    $err = error_get_last()['message'] ?? 'unknown';
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "write_failed",
        "path" => $out_abs,
        "dir_exists" => is_dir(dirname($out_abs)),
        "dir_writable" => is_writable(dirname($out_abs)),
        "php_error" => $err,
        "hint" => "Проверь права на запись/квоту/open_basedir",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    "ok" => true,
    "out_file" => basename($out_abs),
    "out_path" => $out_path,
    "out_abs"  => $out_abs,   // ← добавь это, чтобы видеть точное место
    "N" => $N,
    "turns" => $turns,
    "tokens_total" => $L,
    "unigram_size" => count($unigram),
    "grams_levels" => $N-1,
], JSON_UNESCAPED_UNICODE);
