<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Подписчик сервиса уведомлений.
 *
 * Корневая сущность доменной модели. Хранит ровно то, что неизменно
 * относительно конкретного человека/системы-получателя: идентификатор
 * и (опционально) отображаемое имя. Каналы связи (SMS, email)
 * сознательно вынесены в отдельную таблицу `recipient_contacts` —
 * у подписчика может быть несколько контактов разных типов, и они
 * меняются со временем независимо от самой записи о подписчике.
 *
 * Первичный ключ — UUID версии 7, генерируется приложением через
 * `HasUuids`-trait Laravel 13.x (в 13.x его дефолт переведён с v4
 * на v7). UUID v7 сохраняет хронологическую упорядоченность, что
 * даёт хорошую кластеризацию в B-tree индексе PostgreSQL
 * и минимизирует page-split'ы при массовых INSERT'ах.
 *
 * @property string  $id          UUID v7 подписчика; PK, генерируется приложением.
 * @property ?string $name        Отображаемое имя; может быть NULL — учётка может быть создана только по контакту.
 * @property Carbon  $created_at  Момент создания записи о подписчике.
 * @property Carbon  $updated_at  Момент последнего изменения записи.
 *
 * @property-read Collection<int, RecipientContact> $contacts      Каналы связи подписчика (one-to-many).
 * @property-read Collection<int, Notification>     $notifications История уведомлений подписчика (one-to-many).
 */
class Recipient extends Model
{
    use HasUuids;

    /**
     * Отключаем auto-increment первичного ключа: `id` — UUID, который
     * генерируется приложением в `creating`-хуке трейта `HasUuids`
     * ещё до отправки INSERT'а в БД.
     */
    public $incrementing = false;

    /**
     * Тип PK на уровне Eloquent — строка (UUID хранится как `varchar`
     * в PostgreSQL). Eloquent использует это значение при cast'е
     * результата запроса и при привязке параметров в WHERE-условиях.
     */
    protected $keyType = 'string';

    /**
     * Список mass-assignable атрибутов для `Recipient::create([...])`
     * и `$model->fill([...])`. Намеренно содержит только `name`:
     * `id` генерируется трейтом `HasUuids`, `created_at` / `updated_at` —
     * фреймворком; задавать их извне через массовое присваивание
     * запрещено как защита от подмены идентификатора и таймстампов
     * через произвольный JSON в HTTP-запросе.
     *
     * @var array<int, string>
     */
    protected $fillable = ['name'];

    /**
     * Каналы связи подписчика — одна запись `Recipient` ↔ много
     * записей `RecipientContact` (SMS, email и т.п.).
     *
     * В БД связь обеспечена FK `recipient_contacts.recipient_id`
     * с `ON DELETE CASCADE`: при удалении подписчика все его контакты
     * удаляются автоматически на уровне PostgreSQL.
     *
     * @return HasMany<RecipientContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(RecipientContact::class);
    }

    /**
     * История уведомлений подписчика — одна запись `Recipient` ↔ много
     * записей `Notification`. Используется эндпоинтом
     * `GET /api/v1/recipients/{id}/notifications`, который отдаёт
     * историю и статусы доставки конкретному подписчику.
     *
     * В БД связь обеспечена FK `notifications.recipient_id`
     * с `ON DELETE RESTRICT`: подписчика нельзя удалить, пока у него
     * остаётся хотя бы одно уведомление — это сохраняет историческую
     * целостность данных рассылок.
     *
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
