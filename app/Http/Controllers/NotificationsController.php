<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\NotificationStatusEnum;
use App\Http\Requests\Notifications\BulkSendRequest;
use App\Messaging\Contracts\PublisherInterface;
use App\Models\Notification;
use App\Models\RecipientContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * HTTP-контроллер массовой рассылки уведомлений.
 *
 * Обслуживает эндпоинт `POST /api/v1/notifications/bulk`, который
 * принимает batch из 1..1000 сообщений (см. `BulkSendRequest`),
 * персистирует их в БД и публикует в брокер для асинхронной доставки.
 *
 * Алгоритм обработки одного запроса:
 *
 *  1. Laravel-DI резолвит `BulkSendRequest` — это автоматически
 *     выполняет валидацию payload'а до входа в метод; некорректный
 *     запрос отдаёт 422, в action-метод управление не передаётся.
 *
 *  2. Внутри одной БД-транзакции для каждого элемента batch'а:
 *
 *     - находим или создаём `RecipientContact` по тройке
 *       `(recipient_id, channel, value=to_address)` через
 *       `firstOrCreate`. UNIQUE-индекс из миграции защищает
 *       от дублирования контактов; сценарий «первая отправка
 *       на новый контакт» обрабатывается прозрачно — клиент
 *       не обязан заранее регистрировать каналы;
 *
 *     - создаём `Notification` со снапшотом `channel` и `to_address`
 *       из найденного/созданного контакта, статус `QUEUED`. Снапшот
 *       нужен, чтобы история отправки оставалась корректной даже после
 *       удаления или подмены контакта.
 *
 *  3. **После COMMIT'а** транзакции — последовательно публикуем каждое
 *     уведомление через `PublisherInterface`. Это принципиальное
 *     решение: если бы публикация шла внутри транзакции, при её откате
 *     в RabbitMQ остались бы «сиротские» сообщения, на которые в БД
 *     нет соответствующих записей.
 *
 *     Обратный сценарий — публикация падает после успешного COMMIT'а —
 *     оставляет в БД зомби-`queued` записи без сообщения в брокере.
 *     Воркер их не подхватит, и клиент с `Idempotency-Key` получит 500.
 *     В проде эту проблему решает outbox pattern (отдельная таблица
 *     исходящих сообщений + relay-процесс), но для тестового задания
 *     такой компромисс приемлем.
 *
 *  4. Возвращаем `202 Accepted` с массивом `{id, status}` каждой
 *     созданной записи. Статус 202 семантически точнее, чем 201,
 *     потому что фактическая доставка — асинхронная и в момент
 *     ответа ещё не выполнена.
 *
 * Идемпотентность входящих запросов (повторный POST с тем же
 * `Idempotency-Key`) обеспечивается отдельным middleware, который
 * перехватывает запрос ДО входа в контроллер и при попадании
 * в кэш возвращает сохранённый ранее ответ без повторного выполнения
 * этого метода.
 */
class NotificationsController extends Controller
{
    /**
     * Обработка `POST /api/v1/notifications/bulk` — массовая рассылка.
     *
     * @param BulkSendRequest $request Валидированный запрос с массивом сообщений.
     * @param PublisherInterface $publisher Контракт публикации; конкретная реализация резолвится из DI-контейнера.
     *
     * @return JsonResponse `202 Accepted` с массивом `{id, status}` созданных уведомлений.
     * @throws Throwable
     */
    public function bulk(BulkSendRequest $request, PublisherInterface $publisher): JsonResponse
    {
        $created = DB::transaction(function () use ($request): array {
            $items = [];

            foreach ($request->validated('notifications') as $payload) {
                $contact = RecipientContact::firstOrCreate([
                    'recipient_id' => $payload['recipient_id'],
                    'channel' => $payload['channel'],
                    'value' => $payload['to_address'],
                ]);

                $items[] = Notification::create([
                    'recipient_id' => $contact->recipient_id,
                    'recipient_contact_id' => $contact->id,
                    'channel' => $contact->channel,
                    'to_address' => $contact->value,
                    'subject' => $payload['subject'] ?? null,
                    'body' => $payload['body'],
                    'priority' => $payload['priority'],
                    'status' => NotificationStatusEnum::QUEUED,
                ]);
            }

            return $items;
        });

        foreach ($created as $notification) {
            $publisher->publish($notification);
        }

        return response()->json([
            'data' => array_map(
                static fn (Notification $n): array => [
                    'id' => $n->id,
                    'status' => $n->status->value,
                ],
                $created,
            ),
        ], 202);
    }
}
