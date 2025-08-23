# PHPAiModel-NGram

>N-gram Chat Model (RU/EN)

>This repository contains a toy word-level N-gram “weights” format and pure-PHP utilities to train, merge, and run a tiny chat model on shared hosting (no SSH, no external services).

>What this is

>Format: JSON with N (order), unigram counts, grams (context → next token counts), and optional meta.

##Tools included:
>`index.html` (UI), 
>`aicore.php` (core inference), 
>`merge_weights.php` (combine & prune),
>`debug_weights.php` (debug ;))


### What this is
- **Format:** JSON with:
  - `N` — order of the model,
  - `unigram` — counts per token,
  - `grams` — maps `context → next-token` counts,

### File format
```json
{
  "N": 24,
  "unigram": { "Hello": 98, "Привет": 123, "...": 1 },
  "grams": {
    "1": { "how": { "are": 42, "much": 5 } },
    "2": { "Как\\tдела": { "?": 35, "!": 3 } },
    "3": { "Пользователь\\t:\\tПривет": { "\\n": 12 } }
  },
  "meta": {
    "domains": ["smalltalk","autos/brands"],
    "note": "runtime ignores meta.pad",
    "pad": "AAAA... (optional big padding to reach target size)"
  }
}
```

# PHPAiModel-NGram

> Модель чата на основе N-грамм (RU/EN)

Этот репозиторий содержит компактный формат «весов» для N-грамм на уровне слов и утилиты на чистом PHP для обучения, объединения и работы небольшой чат-модели на общем хостинге (без SSH и внешних сервисов).

## Что это такое

- **Формат данных:** JSON, включающий:
  - `N` — порядок модели,
  - `unigram` — частота появления отдельных токенов,
  - `grams` — отображение контекста на частоту следующего токена,
  - `meta` — дополнительная информация (опционально).

## Инструменты в комплекте

- `index.html` — веб-интерфейс,
- `aicore.php` — ядро для вывода результатов,
- `merge_weights.php` — объединение и оптимизация весов,
- `debug_weights.php` — отладка весов.

## Формат файла

```json
{
  "N": 24,
  "unigram": { "Hello": 98, "Привет": 123, "...": 1 },
  "grams": {
    "1": { "how": { "are": 42, "much": 5 } },
    "2": { "Как\tдела": { "?": 35, "!": 3 } },
    "3": { "Пользователь\t:\tПривет": { "\n": 12 } }
  },
  "meta": {
    "domains": ["smalltalk", "autos/brands"],
    "note": "Время выполнения игнорирует meta.pad",
    "pad": "AAAA... (опциональная прокладка для достижения целевого размера)"
  }
}