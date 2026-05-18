<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Журнал обработанных Idempotency-Key.
 *
 * Используется HTTP-middleware'ом на входе POST-эндпоинтов
 * (`POST /api/v1/notifications/bulk` и т.п.): первый запрос с парой
 * `(key, scope)` отрабатывается обычным путём, его ответ кэшируется
 * в этой таблице (а параллельно в Redis для быстрого пути), повторный
 * запрос с тем же ключом получает сохранённый ответ без повторного
 * выполнения бизнес-логики. Это защищает от двойной отправки рассылок
 * при ретрае со стороны клиента или сетевых проблемах.
 *
 * Архитектурные особенности модели:
 *
 *  1. PK — обычный auto-increment bigint, а не UUID: на эту таблицу
 *     никто не ссылается извне, FK на неё нет, и сокращать ключ
 *     до UUID v7 ради сортируемости смысла не имеет.
 *  2. Запись АРРЕND-ONLY: `updated_at` сознательно отсутствует
 *     в миграции и отключён константой `UPDATED_AT = null`. Существующая
 *     запись либо живёт до `expires_at`, либо удаляется cleanup-задачей —
 *     обновлять её незачем.
 *  3. `response_body` хранится как `jsonb` в PostgreSQL и кастится
 *     в `array` на стороне модели; Laravel сам (де)сериализует JSON.
 *  4. `UNIQUE(key, scope)` на уровне БД — основной механизм
 *     ground-truth-проверки: даже если Redis-lookup пропустит повтор
 *     (например, при недоступности Redis), INSERT в эту таблицу
 *     упадёт на нарушении уникального индекса.
 *
 * @property int     $id              Внутренний auto-increment PK.
 * @property string  $key             Значение HTTP-заголовка Idempotency-Key.
 * @property string  $scope           Имя API-эндпоинта (например, `notifications.bulk`); защищает от коллизий ключа между разными API.
 * @property int     $response_status HTTP-статус закэшированного ответа (200, 201, 422 и т.п.).
 * @property array   $response_body   Тело закэшированного ответа; хранится как jsonb, кастится в array.
 * @property Carbon  $created_at      Момент первого обработанного запроса с этим ключом.
 * @property Carbon  $expires_at      Момент истечения записи (`created_at + IDEMPOTENCY_TTL_SECONDS`); граница cleanup'а.
 */
class IdempotencyKey extends Model
{
    /**
     * Отключаем колонку `updated_at`: журнал append-only, существующая
     * запись не модифицируется. Eloquent при `save()` не будет пытаться
     * её обновлять, но `created_at` продолжит заполняться автоматически.
     */
    const UPDATED_AT = null;

    /**
     * Mass-assignable атрибуты, которые проставляет middleware при
     * первой обработке запроса. `id` — auto-increment, `created_at` —
     * фреймворком.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'scope',
        'response_status',
        'response_body',
        'expires_at',
    ];

    /**
     * Cast-карта атрибутов:
     *
     *  - `response_status` → integer: страховка от того, что PDO-драйвер
     *    PostgreSQL вернёт smallint строкой при некоторых конфигурациях;
     *  - `response_body` → array: jsonb сериализуется при записи и
     *    десериализуется при чтении автоматически;
     *  - `expires_at` → datetime: преобразуется в Carbon, чтобы было
     *    удобно сравнивать с `now()` в cleanup-задаче.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_body' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
