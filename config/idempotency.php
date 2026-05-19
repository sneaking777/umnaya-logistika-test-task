<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Idempotency-Key — параметры middleware
    |--------------------------------------------------------------------------
    |
    | Используется App\Http\Middleware\IdempotencyKey для дедупликации
    | повторных POST-запросов с одинаковым заголовком Idempotency-Key.
    |
    | Быстрый путь — Redis (короткий TTL-кэш ответа). Параллельно
    | ground-truth-запись в таблицу idempotency_keys: даже если Redis
    | потеряет ключ, UNIQUE(key, scope) защитит на уровне БД.
    |
    | `ttl_seconds` управляет одновременно TTL ключа в Redis и значением
    | поля `expires_at` в БД-таблице — оба должны истекать синхронно,
    | иначе cleanup-задача БД может удалить запись, на которую Redis
    | ещё ссылается (и наоборот).
    |
    */

    'ttl_seconds' => (int) env('IDEMPOTENCY_TTL_SECONDS', 86400),

    'redis_prefix' => env('IDEMPOTENCY_REDIS_PREFIX', 'idem'),
];
