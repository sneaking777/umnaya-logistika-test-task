<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Миграция: создание таблицы `notifications` — единиц рассылки.
 *
 * Центральная таблица сервиса. id (UUID) служит ключом exactly-once
 * на бизнес-уровне: воркер при обработке сообщения из RabbitMQ берёт
 * Redis-lock на этот id, проверяет статус в БД, обновляет его в одной
 * транзакции с отправкой провайдеру и только потом отправляет ack
 * в брокер. Повторная доставка того же id будет no-op.
 */
return new class extends Migration {
    /**
     * Применить миграцию: создать таблицу `notifications` и сопутствующие индексы.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary()
                ->comment('Уникальный идентификатор уведомления (UUID v7). Ключ exactly-once на бизнес-уровне.');

            $table->foreignUuid('recipient_id')
                ->constrained('recipients')
                ->restrictOnDelete()
                ->comment('Подписчик-получатель. ON DELETE RESTRICT: подписчика нельзя удалить, пока есть история уведомлений.');

            $table->foreignUuid('recipient_contact_id')
                ->nullable()
                ->constrained('recipient_contacts')
                ->nullOnDelete()
                ->comment('Контакт, на который шла отправка. ON DELETE SET NULL: контакт удалили — снапшот остаётся в to_address.');

            $table->string('channel', 16)
                ->comment('Канал доставки на момент отправки (sms | email). Снапшот: не меняется при последующих правках контакта.');

            $table->string('to_address')
                ->comment('Адрес назначения на момент отправки (телефон или email). Снапшот для исторической точности.');

            $table->string('subject')->nullable()
                ->comment('Тема сообщения. Используется для email; для SMS — NULL.');

            $table->text('body')
                ->comment('Тело сообщения. Может содержать произвольный текст любой длины.');

            $table->string('priority', 16)
                ->comment('Приоритет доставки: transactional | marketing. Маппится в RabbitMQ priority (10 / 1).');

            $table->string('status', 16)->default('queued')
                ->comment('Текущий статус: queued → sent → delivered (успех) или failed. Дефолт queued при создании.');

            $table->unsignedSmallInteger('attempts')->default(0)
                ->comment('Число выполненных попыток отправки. Инкрементится воркером перед каждым вызовом провайдера.');

            $table->text('last_error')->nullable()
                ->comment('Текст последней ошибки от провайдера (для failed). NULL — ошибок не было.');

            $table->string('provider_message_id')->nullable()
                ->comment('ID, возвращённый провайдером при успешной отправке. Используется для матчинга callback. NULL до отправки.');

            $table->timestamp('sent_at')->nullable()
                ->comment('Момент успешной передачи сообщения провайдеру (статус sent).');

            $table->timestamp('delivered_at')->nullable()
                ->comment('Момент подтверждения доставки конечному получателю (статус delivered, из callback).');

            $table->timestamp('failed_at')->nullable()
                ->comment('Момент финального провала после исчерпания попыток (статус failed).');

            $table->timestamp('created_at')->nullable()
                ->comment('Момент приёма запроса на отправку.');

            $table->timestamp('updated_at')->nullable()
                ->comment('Момент последнего изменения записи (смена статуса, инкремент attempts и т.п.).');

            $table->index(['recipient_id', 'created_at']);
            $table->index('status');

            $table->comment('Уведомления: единицы рассылки SMS/Email с приоритетами и историей статусов.');
        });

        // Partial index для матчинга вебхуков провайдера: индексируем только
        // те строки, где provider_message_id IS NOT NULL (т.е. уже отправлено).
        // Экономит ~50% места и ускоряет INSERT'ы queued-записей (их большинство).
        // Laravel Schema Builder partial индексы не поддерживает — поэтому DB::statement.
        DB::statement(
            'CREATE INDEX notifications_provider_message_id_idx '.
            'ON notifications (provider_message_id) '.
            'WHERE provider_message_id IS NOT NULL'
        );
    }

    /**
     * Откатить миграцию: удалить таблицу `notifications` со всеми её индексами.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
