<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChannelEnum;
use App\Enums\NotificationStatusEnum;
use App\Enums\PriorityEnum;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Уведомление — единица рассылки SMS/Email.
 *
 * Центральная сущность сервиса. Хранит снапшот всего, что было отправлено
 * конкретному подписчику: канал, адрес назначения, тело, приоритет, а также
 * полную историю статусов доставки (`queued → sent → delivered`/`failed`).
 *
 * Архитектурные особенности:
 *
 *  1. PK — UUID v7, генерируемый приложением через `HasUuids`. Это
 *     одновременно служит ключом exactly-once на бизнес-уровне: Consumer
 *     при получении сообщения из RabbitMQ берёт Redis-lock на этот id,
 *     проверяет текущий status в одной транзакции с обновлением
 *     (`queued → sent`) и только после COMMIT'а отправляет ack брокеру.
 *     Повторная доставка того же id из RabbitMQ становится no-op.
 *
 *  2. Поля `channel` и `to_address` — СНАПШОТ на момент приёма запроса,
 *     а не «живая» ссылка на RecipientContact. Контакт может быть удалён
 *     (FK `ON DELETE SET NULL`), переименован или заменён на новый —
 *     историческая запись об отправке от этого не меняется. Именно поэтому
 *     `to_address` хранится отдельно от `recipient_contact_id`.
 *
 *  3. `priority` маппится в RabbitMQ numeric priority внутри Publisher'а;
 *     транзакционные сообщения обходят маркетинговые в очередях
 *     с заданным `x-max-priority`.
 *
 *  4. `attempts`, `provider_message_id`, `sent_at`, `delivered_at`,
 *     `failed_at`, `last_error` сознательно не входят в `$fillable`:
 *     ими управляет Worker (и WebhookController для terminal-статусов)
 *     через прямое присваивание атрибутов. Это разделяет «приём заказа
 *     на отправку» (API) и «учёт состояния доставки» (Worker + Webhook).
 *
 * @property string                 $id                   UUID v7 уведомления; PK и бизнес-ключ exactly-once.
 * @property string                 $recipient_id         UUID подписчика-получателя (FK на `recipients.id`).
 * @property ?string                $recipient_contact_id UUID контакта на момент отправки (FK на `recipient_contacts.id`); может стать NULL после удаления контакта.
 * @property ChannelEnum            $channel              Канал доставки (cast в enum), снапшот на момент приёма.
 * @property string                 $to_address           Адрес назначения на момент отправки (телефон / email), снапшот для исторической точности.
 * @property ?string                $subject              Тема сообщения; используется для email, для SMS — NULL.
 * @property string                 $body                 Тело сообщения; произвольный текст.
 * @property PriorityEnum           $priority             Приоритет доставки (cast в enum); определяет маршрутизацию в очереди RabbitMQ.
 * @property NotificationStatusEnum $status               Текущий статус жизненного цикла (cast в enum); дефолт `queued`.
 * @property int                    $attempts             Число выполненных попыток отправки; инкрементится Worker'ом перед каждым вызовом провайдера.
 * @property ?string                $last_error           Текст последней ошибки от провайдера; заполняется при переходе в FAILED.
 * @property ?string                $provider_message_id  ID, возвращённый провайдером при успешной отправке; ключ матчинга вебхуков.
 * @property ?Carbon                $sent_at              Момент успешной передачи сообщения провайдеру (вход в статус SENT).
 * @property ?Carbon                $delivered_at         Момент подтверждения доставки конечному получателю (вход в DELIVERED, из вебхука).
 * @property ?Carbon                $failed_at            Момент финального провала после исчерпания попыток (вход в FAILED).
 * @property Carbon                 $created_at           Момент приёма запроса на отправку.
 * @property Carbon                 $updated_at           Момент последнего изменения записи (смена статуса, инкремент attempts и т.п.).
 *
 * @property-read Recipient         $recipient        Подписчик-получатель (many-to-one).
 * @property-read ?RecipientContact $recipientContact Контакт, на который шла отправка; NULL, если контакт удалили после отправки.
 */
class Notification extends Model
{
    use HasUuids;

    /**
     * Отключаем auto-increment первичного ключа: `id` — UUID v7,
     * генерируется приложением в `creating`-хуке трейта `HasUuids`
     * ещё до отправки INSERT'а в БД.
     */
    public $incrementing = false;

    /**
     * Тип PK на уровне Eloquent — строка (UUID хранится как `varchar`
     * в PostgreSQL). Используется при cast'е результата запроса
     * и при привязке параметров в WHERE.
     */
    protected $keyType = 'string';

    /**
     * Mass-assignable атрибуты — поля, которые допустимо задавать
     * при ПРИЁМЕ заказа на отправку (через API). Сюда сознательно
     * не включены атрибуты, которыми управляет Worker и Webhook
     * (`attempts`, `last_error`, `provider_message_id`, `sent_at`,
     * `delivered_at`, `failed_at`) — они меняются прямым присваиванием
     * атрибутов, что чётко разделяет «принять заказ» и «изменить
     * состояние доставки» как два разных сценария жизни записи.
     *
     * `id` генерируется трейтом `HasUuids`, `created_at` / `updated_at` —
     * фреймворком.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'recipient_id',
        'recipient_contact_id',
        'channel',
        'to_address',
        'subject',
        'body',
        'priority',
        'status',
    ];

    /**
     * Cast-карта атрибутов. Включает три доменных enum'а, integer-cast
     * счётчика попыток и три datetime-таймстампа жизненного цикла.
     *
     *  - `channel`, `priority`, `status` → backed enum-классы: на чтении
     *    из БД дают типобезопасный enum-кейс, на записи сериализуются
     *    обратно в backing-строку;
     *  - `attempts` → integer: страховка от того, что PDO-драйвер
     *    PostgreSQL вернёт smallint строкой при некоторых конфигурациях;
     *  - `sent_at`, `delivered_at`, `failed_at` → datetime: преобразуются
     *    в Carbon, NULL остаётся NULL до соответствующего перехода
     *    статуса.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ChannelEnum::class,
            'priority' => PriorityEnum::class,
            'status' => NotificationStatusEnum::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * Подписчик-получатель уведомления (many-to-one). FK = `recipient_id`
     * по laravel-конвенции, совпадает с именем столбца в миграции.
     *
     * В БД задействован `ON DELETE RESTRICT`: подписчика нельзя удалить,
     * пока у него остаётся хотя бы одно уведомление. Историческая
     * целостность данных рассылок при этом обеспечивается на уровне
     * PostgreSQL, а не в коде приложения.
     *
     * @return BelongsTo<Recipient, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    /**
     * Контакт, на который шла отправка (many-to-one, nullable).
     * Имя метода `recipientContact` намеренно длиннее обычного
     * `contact` — это даёт laravel-конвенцию для FK
     * `recipient_contact_id`, совпадающую с миграцией, без явного
     * указания второго аргумента `belongsTo()`.
     *
     * В БД задействован `ON DELETE SET NULL`: при удалении контакта
     * `recipient_contact_id` обнуляется, но «снапшоты» `channel`
     * и `to_address` в этой же записи остаются на месте — историю
     * отправки можно прочитать и без живой ссылки на контакт.
     *
     * @return BelongsTo<RecipientContact, $this>
     */
    public function recipientContact(): BelongsTo
    {
        return $this->belongsTo(RecipientContact::class);
    }
}
