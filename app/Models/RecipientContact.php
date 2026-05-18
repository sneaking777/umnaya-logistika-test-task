<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChannelEnum;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Контакт подписчика — канал связи (SMS, email и т.п.).
 *
 * Many-to-one к Recipient: у одного подписчика может быть несколько
 * контактов разных каналов, и они меняются со временем независимо
 * от самой записи о подписчике.
 *
 * Принципиальное решение схемы: значение контакта (номер/email)
 * и канал хранятся как обычные строковые поля одной таблицы,
 * а не нормализуются в отдельные `phones` / `emails`. Причины:
 *
 *  - доменно у нас всего два канала, и расширение этого списка —
 *    редкое архитектурное событие (см. ChannelEnum), а не «горячий»
 *    путь развития схемы;
 *  - UNIQUE(recipient_id, channel, value) на уровне БД уже даёт
 *    защиту от повторного заведения того же канала.
 *
 * Поле `verified_at` намеренно отделено от собственно факта существования
 * контакта: незаподтверждённый контакт можно завести (и, например,
 * сразу же отправить на него welcome-сообщение), а пометить
 * «подтверждённым» — только после успешного 2FA-обмена или клика
 * по магической ссылке. NULL — контакт ещё не подтверждён.
 *
 * @property string       $id           UUID v7 контакта; PK, генерируется приложением.
 * @property string       $recipient_id UUID подписчика-владельца (FK на `recipients.id`).
 * @property ChannelEnum  $channel      Канал доставки (sms | email); cast в enum.
 * @property string       $value        Значение контакта: номер телефона для SMS или email-адрес.
 * @property ?Carbon      $verified_at  Момент подтверждения владения контактом; NULL — не подтверждён.
 * @property Carbon       $created_at   Момент создания записи о контакте.
 * @property Carbon       $updated_at   Момент последнего изменения записи.
 *
 * @property-read Recipient                     $recipient     Подписчик-владелец контакта (many-to-one).
 * @property-read Collection<int, Notification> $notifications Уведомления, отправленные через этот контакт (one-to-many).
 */
class RecipientContact extends Model
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
     * в PostgreSQL). Используется Eloquent'ом при cast'е результата
     * запроса и при привязке параметров в WHERE-условиях.
     */
    protected $keyType = 'string';

    /**
     * Список mass-assignable атрибутов для `RecipientContact::create([...])`
     * и `$model->fill([...])`. Содержит реальные «доменные» поля, которые
     * допустимо задавать из бизнес-кода; `id` генерируется трейтом
     * `HasUuids`, `created_at` / `updated_at` — фреймворком.
     *
     * `verified_at` оставлен здесь намеренно: им управляет бизнес-код
     * подтверждения (например, после 2FA), и в простом случае это удобно
     * делать прямым `update(['verified_at' => now()])`. Если со временем
     * понадобится более жёсткая защита — заменим на отдельный метод
     * `markVerified()` и уберём поле из fillable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'recipient_id',
        'channel',
        'value',
        'verified_at'
    ];

    /**
     * Cast-карта атрибутов. В Laravel 11+ предпочтительнее объявлять
     * casts методом, а не свойством `$casts`: метод позволяет использовать
     * выражения и константы, удобнее аннотируется PHPDoc и не «фризится»
     * при разборе класса до момента вызова.
     *
     *  - `channel` → ChannelEnum: на чтении из БД даёт типобезопасный
     *    enum-кейс, на записи сериализуется обратно в backing-строку;
     *  - `verified_at` → datetime: преобразуется в Carbon, NULL
     *    остаётся NULL.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ChannelEnum::class,
            'verified_at' => 'datetime'
        ];
    }

    /**
     * Подписчик-владелец контакта (many-to-one). FK = `recipient_id`
     * по laravel-конвенции (snake_case от имени родительской модели + `_id`),
     * что совпадает с именем столбца в миграции `recipient_contacts`.
     *
     * В БД задействован `ON DELETE CASCADE`: контакты исчезают вместе
     * с подписчиком на уровне PostgreSQL — отдельно об этом думать
     * в коде приложения не нужно.
     *
     * @return BelongsTo<Recipient, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    /**
     * Уведомления, отправленные через этот контакт (one-to-many).
     * FK на стороне `notifications` = `recipient_contact_id`
     * (snake_case + `_id` от имени этой модели — laravel-конвенция).
     *
     * В БД задействован `ON DELETE SET NULL`: если контакт удалят,
     * `recipient_contact_id` в notifications обнулится, но сам
     * исторический снапшот (`channel`, `to_address` в notifications)
     * останется неизменным — это нужно для корректного отображения
     * истории доставки после ротации контактов.
     *
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
