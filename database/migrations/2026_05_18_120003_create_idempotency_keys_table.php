<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Миграция: создание таблицы `idempotency_keys` — журнала Idempotency-Key.
 *
 * Используется как ground-truth fallback при недоступности Redis: даже если
 * Redis пропустит повтор, UNIQUE(key, scope) в БД отловит дубль на уровне
 * INSERT. Запись append-only: создаётся → ожидает истечения → удаляется
 * cleanup-задачей по `expires_at`.
 */
return new class extends Migration {
    /**
     * Применить миграцию: создать таблицу `idempotency_keys`.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id()
                ->comment('Внутренний счётчик. UUID не нужен — на таблицу нет FK снаружи.');

            $table->string('key')
                ->comment('Значение Idempotency-Key из HTTP-заголовка запроса.');

            $table->string('scope', 64)
                ->comment('Имя API-эндпоинта (например, notifications.bulk). Защищает от коллизии ключа между разными API.');

            $table->unsignedSmallInteger('response_status')
                ->comment('HTTP-статус закэшированного ответа (200, 201, 422, ...).');

            $table->jsonb('response_body')
                ->comment('Тело закэшированного ответа в JSONB. Возвращается клиенту при повторе.');

            $table->timestamp('created_at')
                ->comment('Момент первого обработанного запроса с этим ключом.');

            $table->timestamp('expires_at')
                ->comment('Момент истечения записи (created_at + IDEMPOTENCY_TTL_SECONDS). Используется cleanup-задачей.');

            $table->unique(['key', 'scope']);
            $table->index('expires_at');

            $table->comment('Журнал обработанных Idempotency-Key. Append-only; cleanup по expires_at.');
        });
    }

    /**
     * Откатить миграцию: удалить таблицу `idempotency_keys`.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
