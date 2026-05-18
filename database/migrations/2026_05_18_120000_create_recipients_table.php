<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Миграция: создание таблицы `recipients` — подписчиков сервиса уведомлений.
 *
 * Контакты подписчика (телефон, email) вынесены в отдельную таблицу
 * `recipient_contacts` (one-to-many): один подписчик может иметь несколько
 * каналов связи и менять их со временем.
 */
return new class extends Migration {
    /**
     * Применить миграцию: создать таблицу `recipients`.
     */
    public function up(): void
    {
        Schema::create('recipients', function (Blueprint $table) {
            $table->uuid('id')->primary()
                ->comment('Уникальный идентификатор подписчика (UUID v7, генерируется приложением).');

            $table->string('name')->nullable()
                ->comment('Отображаемое имя подписчика. Опционально — запись может быть создана только по контакту.');

            $table->timestamp('created_at')->nullable()
                ->comment('Момент создания записи о подписчике.');

            $table->timestamp('updated_at')->nullable()
                ->comment('Момент последнего изменения записи о подписчике.');

            $table->comment('Подписчики сервиса уведомлений. Каналы связи хранятся в recipient_contacts.');
        });
    }

    /**
     * Откатить миграцию: удалить таблицу `recipients`.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipients');
    }
};
