<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application
    |--------------------------------------------------------------------------
    */

    'name' => env('APP_NAME', 'Laravel'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Localization
    |--------------------------------------------------------------------------
    |
    | Локализация в этом сервисе не используется (API возвращает только
    | технические сообщения). Дефолты оставлены для совместимости с
    | Laravel-ядром (validation messages).
    |
    */

    'locale' => env('APP_LOCALE', 'en'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | Генерируется через `php artisan key:generate`. APP_PREVIOUS_KEYS —
    | список предыдущих ключей (через запятую) для корректной расшифровки
    | старых данных при ротации ключа.
    |
    */

    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode
    |--------------------------------------------------------------------------
    |
    | Используем file driver — флаг хранится в storage/framework/down.
    | `cache` driver требует таблицу cache (database cache), которой
    |  у нас нет — кэш в Redis.
    */

    'maintenance' => [
        'driver' => 'file',
    ],

];
