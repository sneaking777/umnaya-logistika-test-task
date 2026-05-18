<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Миграция: создание таблицы `recipient_contacts` — каналов связи подписчиков.
 *
 * Связь many-to-one с `recipients`: один подписчик может иметь несколько
 * контактов (SMS, email и т.п.). Каскадно удаляется вместе с подписчиком.
 */
return new class extends Migration {
    /**
     * Применить миграцию: создать таблицу `recipient_contacts`.
     */
    public function up(): void
    {
        Schema::create('recipient_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary()
                ->comment('Уникальный идентификатор контакта (UUID v7, генерируется приложением).');

            $table->foreignUuid('recipient_id')
                ->constrained('recipients')
                ->cascadeOnDelete()
                ->comment('Подписчик-владелец контакта. ON DELETE CASCADE: контакты удаляются вместе с подписчиком.');

            $table->string('channel', 16)
                ->comment('Канал доставки: sms | email. Валидация значений — на уровне PHP-enum в модели, а не CHECK constraint.');

            $table->string('value')
                ->comment('Значение контакта: номер телефона для sms или email-адрес для email.');

            $table->timestamp('verified_at')->nullable()
                ->comment('Момент подтверждения владения контактом (например, после 2FA). NULL — контакт не подтверждён.');

            $table->timestamp('created_at')->nullable()
                ->comment('Момент создания записи о контакте.');

            $table->timestamp('updated_at')->nullable()
                ->comment('Момент последнего изменения записи о контакте.');

            $table->unique(['recipient_id', 'channel', 'value']);
            $table->index(['recipient_id', 'channel']);

            $table->comment('Каналы связи подписчиков (SMS, email и т.п.). Один подписчик может иметь несколько контактов разных типов.');
        });
    }

    /**
     * Откатить миграцию: удалить таблицу `recipient_contacts`.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipient_contacts');
    }
};
