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
        ["Привет","Здравствуйте","Добрый день","Добрый вечер","Доброе утро","Хай","Йо","Йоу","Салют","Приветствую",
            "Доброго времени суток","Здорово","Алло","Приветики","Хей","Хэллоу","Хелло","Шалом","Прив","Привет-привет"],
        ["Привет! Как я могу помочь?","Здравствуйте! Чем могу помочь?","Рад вас видеть. Чем помочь?","Хай! О чём поговорим?",
            "Добро пожаловать. Что делаем?","Приветствую! С чего начнём?","На связи. Подскажите задачу.","Готов помочь — расскажите, что нужно.",
            "Привет! Готов к работе.","Здравствуйте! По какому вопросу?"]
    ],
    "wellbeing" => [
        ["Как дела","Как ты","Как жизнь","Как настроение","Как сам","Как поживаешь","Как у тебя дела","Как сегодня","Как там","Как идут дела","Как оно","Как ощущения"],
        ["Отлично, спасибо. Чем помочь?","Нормально. Что нужно?","Хорошо. Расскажите подробнее.","В порядке — чем могу быть полезен?",
            "Всё ок. О чём речь?","Дела идут. Что требуется?","Работаю. Какую задачу решаем?","Норм. Давайте к сути.","Хорошо! Что подсказать?","Спасибо, неплохо. Чем помочь?"]
    ],
    "smalltalk_news" => [
        ["Что нового","Что по новостям","Что слышно","Что у тебя нового","Что происходит","Какие новости","Как дела по новостям","Что там","Что по делу","Какие апдейты"],
        ["Работаю над ответами. А у вас?","Понемногу. Какая задача?","Дела идут. Чем помочь?","Всё стабильно. О чём поговорим?",
            "Есть идеи — давайте обсудим.","Могу подсказать по теме — расскажите контекст.","Готов подключиться. Что за задача?","Новостей немного. Что интересует?"]
    ],
    "identity" => [
        ["Кто ты","Ты кто","Кто там","Кто ты такой","Что ты такое","Что ты за бот","Кто вы","Кем являешься","Что умеешь","Чем занимаешься"],
        ["Я небольшой чат на N‑граммах.","Я лёгкая демо‑модель на PHP.","Я помогаю короткими ответами по контексту.",
            "Я предсказываю следующее слово по контексту.","Компактный помощник без внешних сервисов.","Учебная модель — отвечаю кратко."]
    ],
    "capabilities" => [
        ["Что ты умеешь","Какие у тебя функции","Чем можешь помочь","Где полезен","В чём твоя роль","Какие задачи решаешь","Что входит в возможности"],
        ["Могу отвечать на простые вопросы и помогать с формулировками.","Подскажу шаги и дам краткие советы.",
            "Помогаю с текстом, пояснениями и шаблонами.","Сгенерирую короткие примеры или структуры.","Подскажу базовую логику и варианты."]
    ],
    "limitations" => [
        ["Чего ты не умеешь","Какие ограничения","Где не поможешь","Чего ожидать не стоит","Есть ли ограничения","В чём слабые стороны"],
        ["Не имею доступа к интернету и реальному времени.","Не вызываю внешние API и не читаю файлы сам.",
            "Не даю проф. рекомендаций (медицина/право).","Отвечаю кратко на учебном корпусе.","Лучше с общими, короткими задачами."]
    ],
    "help" => [
        ["Помоги","Нужна помощь","Подскажи","Помощь нужна","Подскажешь","Подкинь идею","Подскажи, как сделать","Помоги разобраться","Хочу совет","Есть вопрос"],
        ["Конечно. Что именно?","Готов помочь. Опишите задачу.","Давайте разберёмся.","С радостью. В чём сложность?",
            "Расскажите детали — предложу шаги.","Что требуется на выходе?","С чего начнём? Дайте контекст."]
    ],
    "clarify" => [
        ["Уточни","Можешь уточнить","Поясни","Раскрой подробнее","Дай контекст","Что ты имел в виду","Расшифруй идею","Приведи пример","Объясни простыми словами"],
        ["Коротко поясню:","Сформулирую проще:","Разверну мысль:","Идея такая:","Смысл в следующем:","Пример:","По шагам:"]
    ],
    "repeat" => [
        ["Повтори","Скажи ещё раз","Не расслышал","Продублируй","Повторишь","Ещё раз, пожалуйста"],
        ["Дублирую:","Ещё раз тезисно:","Коротко повторю:","Сводка:"]
    ],
    "thanks" => [
        ["Спасибо","Благодарю","Мерси","Огромное спасибо","Спасибо большое","Благодарствую","Респект","Пасиб","Спасибки","Сенкс"],
        ["Пожалуйста!","Всегда рад помочь.","Обращайтесь.","Не за что.","Рад, что пригодилось.","Пожалуйста, удачи!","На здоровье!"]
    ],
    "apology" => [
        ["Извини","Сорян","Прости","Прошу прощения","Мои извинения"],
        ["Ничего страшного.","Всё ок — продолжаем.","Бывает. Давайте дальше.","Понимаю. Вернёмся к задаче?"]
    ],
    "compliment" => [
        ["Круто","Отлично","Супер","Топ","Нормально","Годно","Неплохо"],
        ["Спасибо!","Благодарю за оценку.","Рад помочь.","Приятно слышать!"]
    ],
    "bye" => [
        ["Пока","До встречи","До связи","Всего доброго","Хорошего дня","До скорого","Прощай","Увидимся","Созвонимся","Пишите"],
        ["До связи!","Хорошего дня!","Пока‑пока!","До скорого!","Удачи!","Буду на связи."]
    ],
    "ok" => [
        ["Окей","Хорошо","Ладно","Понял","Принято","Ок","Окэй","Согласен"],
        ["Окей.","Хорошо.","Принято.","Понял.","Есть.","Ок, продолжим."]
    ],
    "time" => [
        ["Сколько времени","Который час","Скажи время","Какое сейчас время"],
        ["Точное время не проверяю, могу помочь с задачей.","Часы недоступны, но подскажу по логике.","Давайте к сути запроса."]
    ],
    "weather" => [
        ["Какая погода","Будет дождь","Что с погодой","Снег идёт"],
        ["Погоду не проверяю, но помогу с планом подготовки.","Онлайн‑данных нет. Могу предложить чек‑лист."]
    ],
    "food" => [
        ["Что по еде","Любимая еда","Посоветуй перекус","Что на обед"],
        ["Могу накидать идеи меню.","Подскажу универсальные варианты."]
    ],
    "music" => [
        ["Что послушать","Любимая музыка","Подборка треков"],
        ["Предложу жанры и сценарии прослушивания.","Вот направления:"]
    ],
    "movies" => [
        ["Что посмотреть","Фильмы посоветуй","Кино на вечер"],
        ["Предложу критерии выбора и жанры.","Идеи под настроение:"]
    ],
    "sport" => [
        ["Как тренироваться","Программа тренировок","Советы по спорту"],
        ["Базовые рекомендации (не мед.) — разминка/нагрузка/заминка.","Схема:"]
    ],
    "travel" => [
        ["Куда поехать","Путешествия идеи","Маршрут на выходные"],
        ["Набросаю общий план без бронирований и цен.","Что учесть:"]
    ],
    "bug_report" => [
        ["Нашёл баг","Что‑то не работает","Ошибка на странице","Ломается"],
        ["Опишите шаги воспроизведения.","Уточните окружение и ожидание/факт.","Чек‑лист проверок:"]
    ],
    "feature_request" => [
        ["Нужна фича","Добавьте функцию","Идея улучшения"],
        ["Опишите сценарий и ожидаемый результат.","Сформулируем минимальный прототип."]
    ],
    "login" => [
        ["Не могу войти","Проблема с логином","Ошибка входа"],
        ["Проверьте логин/пароль и раскладку.","Попробуйте сброс пароля. Что видите?"]
    ],
    "password_reset" => [
        ["Забыл пароль","Сбросить пароль","Восстановить доступ"],
        ["Перейдите по ссылке сброса → новый пароль → подтверждение.","Следуйте инструкции на сайте."]
    ],
    "language_switch" => [
        ["Давай по‑английски","Переключись на русский","Можем говорить на английском"],
        ["Без проблем — отвечаю по‑русски или по‑английски.","Сменю язык ответа. Что дальше?"]
    ],
    "define" => [
        ["Что такое …","Дай определение","Определи термин","Поясни понятие"],
        ["Короткое определение:","Если упрощать:","Проще говоря:"]
    ],
    "example" => [
        ["Пример кода","Пример текста","Скетч решения"],
        ["Эскиз примера:","Черновик шаблона:","Мини‑пример:"]
    ],
    "math" => [
        ["Посчитай","Можешь прикинуть","Счёт прикинь","Оценка прикинуть"],
        ["Прикидка и формула:","Схема вычисления:"]
    ],
    "convert" => [
        ["Конвертируй единицы","Переведи в километры","Сколько это в минутах"],
        ["Общая формула и пример:","Приблизим так:"]
    ],
    "schedule" => [
        ["Назначим встречу","Когда удобно","Согласуем время"],
        ["Предложу условные слоты. Уточните день и диапазон.","Сформулируем приглашение."]
    ],
    "reminder" => [
        ["Напомни мне","Сделай напоминание","Напомни завтра"],
        ["Зафиксируйте у себя, а я дам текст‑шаблон.","Шаблон напоминания:"]
    ],
];

$en_intents = [
    "greet" => [
        ["Hello","Hi","Hey","Yo","Howdy","Hi there","Hello there","Heya","Hey hey","Greetings","Good day","Good morning","Good evening","Sup","What’s up"],
        ["Hi! How can I help?","Hello! How may I assist?","Hey! What do you need?","Greetings — what shall we do?","Welcome back. How can I help?","On it. Tell me the task.","Ready to help — what’s the goal?"]
    ],
    "wellbeing" => [
        ["How are you","How’s it going","How are u","How are things","How do you do","How’s life","How’s your day","You good","All good","Everything okay"],
        ["Great, thanks. How can I help?","I'm fine. What do you need?","Good! Tell me more.","Doing well — what’s the task?","All good. Where should we start?","Fine. Give me context."]
    ],
    "smalltalk_news" => [
        ["What's new","Anything new","What is new","What’s up lately","Any updates"],
        ["Working on answers. You?","Not much. What's the task?","All good. How can I help?","Some progress — want details?","Happy to jump in. What’s the context?"]
    ],
    "identity" => [
        ["Who are you","Who r u","What are you","What’s this","What are you exactly","Who am I talking to","What kind of bot are you"],
        ["A tiny n‑gram chat.","A small PHP helper.","A lightweight demo model.","I predict next word from context.","Compact assistant for short tasks."]
    ],
    "capabilities" => [
        ["What can you do","Capabilities","What do you handle","Where can you help","What are your skills","What’s your role"],
        ["I answer short questions and outline steps.","I help with wording and small examples.","I provide concise suggestions and templates.","I sketch simple structures and logic."]
    ],
    "limitations" => [
        ["Limits","What can’t you do","Where do you fail","Any constraints","What to not expect"],
        ["No internet/time access.","No external APIs or file I/O.","Not professional advice.","Best for short, general tasks.","Small educational corpus."]
    ],
    "help" => [
        ["Help me","Need help","Could you help","Give me a hand","I need assistance","Can you assist","Support needed"],
        ["Sure. What exactly?","Happy to help. Describe the task.","Let's figure it out.","Absolutely — what’s the goal?","Give me details and I’ll propose steps."]
    ],
    "clarify" => [
        ["Clarify","Could you clarify","Explain","Expand","Give context","What do you mean","Can you break it down"],
        ["Briefly:","In simple terms:","The idea is:","Here's the gist:","Example:"]
    ],
    "repeat" => [
        ["Repeat","Say again","Once more please","Can you repeat","Repeat that"],
        ["Repeating:","Once more, briefly:","Summary:","Again:"]
    ],
    "thanks" => [
        ["Thanks","Thank you","Thx","Many thanks","Much appreciated","Cheers","Big thanks"],
        ["You're welcome!","Anytime.","Glad to help.","My pleasure.","Happy to help!"]
    ],
    "apology" => [
        ["Sorry","My bad","Apologies","I’m sorry","Pardon"],
        ["No worries.","All good — let’s continue.","Happens. Moving on.","Understood — proceed?"]
    ],
    "compliment" => [
        ["Nice","Great","Awesome","Cool","Dope","Neat","Solid"],
        ["Thanks!","Appreciate it.","Glad it helps.","Cheers!"]
    ],
    "bye" => [
        ["Bye","See you","Goodbye","Later","Catch you later","Take care","See ya","Talk soon","Have a nice day","Till next time"],
        ["See you!","Have a nice day!","Bye‑bye!","Talk soon!","Cheers!","Take care!"]
    ],
    "ok" => [
        ["Okay","Ok","Alright","Got it","Understood","Sounds good","Roger"],
        ["Okay.","Alright.","Noted.","Got it.","Sure, let’s proceed."]
    ],
    "time" => [
        ["What time is it","Tell the time","Time please","Current time"],
        ["I can't check time here, but I can help with logic.","No clock access — let's focus on your task."]
    ],
    "weather" => [
        ["Weather now","Is it raining","Forecast today","How’s the weather"],
        ["I don’t check weather here, but I can outline prep steps.","No live weather; I can suggest a checklist."]
    ],
    "food" => [
        ["Food ideas","What to eat","Lunch ideas","Suggest a snack"],
        ["I can list generic menu ideas.","Here are common options:"]
    ],
    "music" => [
        ["What to listen","Music recommendations","Playlist ideas"],
        ["I can suggest genres and listening scenarios.","Try these directions:"]
    ],
    "movies" => [
        ["What to watch","Movie suggestions","Film for tonight"],
        ["I can propose criteria and genres.","Depending on mood, try:"]
    ],
    "sport" => [
        ["Training plan","How to train","Workout tips"],
        ["General tips (non‑medical): warm‑up / load / cool‑down.","Basic routine outline:"]
    ],
    "travel" => [
        ["Where to go","Trip ideas","Weekend route"],
        ["High‑level plan (no bookings/prices):","Checklist to consider:"]
    ],
    "bug_report" => [
        ["Found a bug","Something broke","Page error","It crashes"],
        ["Describe repro steps.","Share environment + expected vs actual.","Let’s go through checks."]
    ],
    "feature_request" => [
        ["Need a feature","Add function","Improvement idea"],
        ["Describe the scenario and desired outcome.","We can outline a minimal prototype."]
    ],
    "login" => [
        ["Can't log in","Login issue","Sign‑in error"],
        ["Check username/password and layout.","Try password reset; what do you see?"]
    ],
    "password_reset" => [
        ["Forgot password","Reset password","Recover access"],
        ["Follow reset link → new password → confirm.","Use the site's recovery instructions."]
    ],
    "language_switch" => [
        ["Let’s speak Russian","Switch to English","Change language"],
        ["No problem — I can reply in English or Russian.","I'll switch the reply language. What next?"]
    ],
    "define" => [
        ["What is …","Define term","Give a definition","Explain the concept"],
        ["Short definition:","In simpler words:","The point is:"]
    ],
    "example" => [
        ["Code example","Text example","Solution sketch"],
        ["Draft example:","Template sketch:","Mini‑example:"]
    ],
    "math" => [
        ["Do the math","Estimate","Compute quickly","Rough calculation"],
        ["I'll outline a formula and a quick estimate.","Computation outline:"]
    ],
    "convert" => [
        ["Convert units","Miles to km","Minutes to hours"],
        ["General formula and example:","Approximate like this:"]
    ],
    "schedule" => [
        ["Schedule a meeting","When works","Find a time"],
        ["I can propose tentative slots — share day/time window.","Let's prepare an invitation text."]
    ],
    "reminder" => [
        ["Remind me","Make a reminder","Ping me tomorrow"],
        ["Note it locally; I’ll give a reminder text template.","Reminder template:"]
    ],
];

// ---------- аугментации ----------
$ru_interj = ["Эээ","Кстати","Слушай","Ну","Окей","Ладно","Ммм","Значит","Смотри","Вообще","По сути","Итак"];
$en_interj = ["Well","BTW","Listen","Ok","Alright","So","Look","Basically","By the way","Anyway","Right"];
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

$out_abs = (strpos($out_path, DIRECTORY_SEPARATOR) === 0) ? $out_path : (__DIR__ . DIRECTORY_SEPARATOR . $out_path);
@mkdir(dirname($out_abs), 0777, true);
file_put_contents($out_abs, json_encode($weights, JSON_UNESCAPED_UNICODE));

echo json_encode([
    "ok" => true,
    "out_file" => basename($out_abs),
    "out_path" => $out_path,
    "N" => $N,
    "turns" => $turns,
    "tokens_total" => $L,
    "unigram_size" => count($unigram),
    "grams_levels" => $N-1,
], JSON_UNESCAPED_UNICODE);
