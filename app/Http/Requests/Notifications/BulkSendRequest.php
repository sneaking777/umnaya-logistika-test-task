<?php

declare(strict_types=1);

namespace App\Http\Requests\Notifications;

use App\Enums\ChannelEnum;
use App\Enums\PriorityEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FormRequest для `POST /api/v1/notifications/bulk` — массовая рассылка.
 *
 * Контракт: клиент присылает один JSON-объект с массивом `notifications`.
 * Каждый элемент — независимое сообщение со своим каналом, адресатом,
 * приоритетом и телом; в одном batch'е допустимо смешивать transactional
 * и marketing — Publisher разведёт их по разным очередям на основании
 * поля `priority` каждого элемента.
 *
 * Пример входного payload'а:
 *
 * ```json
 * {
 *   "notifications": [
 *     {
 *       "recipient_id": "0190f4a0-...",
 *       "channel": "sms",
 *       "to_address": "+77001234567",
 *       "subject": null,
 *       "body": "Ваш код: 123456",
 *       "priority": "transactional"
 *     },
 *     {
 *       "recipient_id": "0190f4a0-...",
 *       "channel": "email",
 *       "to_address": "user@example.com",
 *       "subject": "Скидка 30%",
 *       "body": "Только сегодня...",
 *       "priority": "marketing"
 *     }
 *   ]
 * }
 * ```
 *
 * Идемпотентность входящего запроса обеспечивается отдельным middleware
 * по HTTP-заголовку `Idempotency-Key` — на уровне FormRequest этот
 * аспект сознательно не валидируется: заголовки обрабатываются другим
 * слоем приложения.
 */
class BulkSendRequest extends FormRequest
{
    /**
     * Разрешение на выполнение запроса. Эндпоинт открыт без авторизации
     * (test-task); в реальном проде здесь была бы проверка API-ключа
     * или m2m-токена через middleware (`auth:api`) либо allow-list по IP.
     *
     * Возвращаем безусловный `true`, чтобы Laravel не отдавал 403
     * до выполнения валидации полей.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Карта правил Laravel-валидации. Стратегия:
     *
     *  - Структурные ограничения сверху: массив 1..1000 элементов.
     *    Потолок 1000 защищает сервис от запроса на миллион сообщений
     *    одним POST'ом; цифра захардкожена, при необходимости легко
     *    вынести в `config('notifications.bulk_max_size')`.
     *
     *  - Адресат сообщения — `recipient_id` (UUID + проверка наличия
     *    в БД через `exists`). Контакт (`recipient_contact_id`) фронт
     *    указывать не обязан: Controller сам найдёт или создаст запись
     *    в `recipient_contacts` по тройке (recipient, channel, value).
     *
     *  - Канал и приоритет валидируются через enum-правило Laravel 11+
     *    (`Rule::enum`): принимаются только backing-значения case'ов
     *    ChannelEnum и PriorityEnum, всё остальное даёт 422.
     *
     *  - `to_address` валидируется минимально (string + длина 255).
     *    Доменная проверка формата по каналу (E.164 для SMS, RFC 5322
     *    для email) — отдельная задача, при необходимости добавляется
     *    через custom rule без переписывания остального API.
     *
     *  - `body` ограничен 65535 символами — типичная верхняя граница
     *    для `text` в PostgreSQL; для коротких SMS реальная длина
     *    будет существенно ниже, но единое ограничение упрощает контракт.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'notifications' => ['required', 'array', 'min:1', 'max:1000'],
            'notifications.*.recipient_id' => ['required', 'uuid', 'exists:recipients,id'],
            'notifications.*.channel' => ['required', Rule::enum(ChannelEnum::class)],
            'notifications.*.to_address' => ['required', 'string', 'max:255'],
            'notifications.*.subject' => ['nullable', 'string', 'max:255'],
            'notifications.*.body' => ['required', 'string', 'max:65535'],
            'notifications.*.priority' => ['required', Rule::enum(PriorityEnum::class)],
        ];
    }
}
