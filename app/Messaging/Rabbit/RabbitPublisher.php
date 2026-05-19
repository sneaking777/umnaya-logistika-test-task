<?php

declare(strict_types=1);

namespace App\Messaging\Rabbit;

use App\Enums\PriorityEnum;
use App\Messaging\Contracts\PublisherInterface;
use App\Models\Notification;
use Exception;
use Illuminate\Contracts\Config\Repository as Config;
use JsonException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Продовая реализация {@see PublisherInterface} поверх RabbitMQ через php-amqplib.
 *
 * Делит работу с двумя соседями:
 *
 *  - `Connection` отдаёт живой AMQP-channel (лениво открывает соединение
 *    и переоткрывает его при разрыве);
 *  - `Topology` гарантирует, что exchange/queue/binding объявлены —
 *    декларация идемпотентна и кэшируется флагом внутри `Topology`,
 *    поэтому повторные `publish()` в одном процессе не платят за declare.
 *
 * Решения, специфичные для этой реализации:
 *
 *  - **Минимальный payload** — в тело сообщения уходит только
 *    `{"id": "<uuid>"}`. Consumer всё равно идёт в БД за полной записью
 *    (нужна для exactly-once проверки статуса в транзакции), а минимизация
 *    payload'а защищает от schema drift и упрощает дальнейшее изменение
 *    модели `Notification` без миграции in-flight сообщений.
 *
 *  - **`delivery_mode = PERSISTENT`** в паре с `durable`-очередью
 *    (см. `Topology`) — сообщения переживают рестарт брокера.
 *
 *  - **Маппинг приоритетов** живёт здесь, а не в `PriorityEnum`:
 *    TRANSACTIONAL → `max_priority` из config (10 по умолчанию),
 *    MARKETING → 1. Это позволяет менять числовой диапазон через
 *    `NOTIFICATIONS_QUEUE_MAX_PRIORITY` без правок в домене.
 *
 *  - **`message_id`** проставляется UUID'ом уведомления — полезно
 *    для трейсинга в RabbitMQ Management UI и для возможной
 *    server-side дедупликации в будущем.
 *
 * Класс помечен `readonly`: ни одна из трёх зависимостей не меняется
 * после конструирования. Биндинг в DI-контейнере — `scoped` (см.
 * `AppServiceProvider`), consistent с биндингами `Connection` и `Topology`:
 *
 *  - в HTTP-запросе контейнер всё равно пересоздаётся на каждый
 *    запрос FPM — Publisher живёт ровно длину одного запроса;
 *  - в long-running consumer'е (`notifications:consume`) scoped-инстанс
 *    живёт всё время процесса, благодаря чему `Connection` держит
 *    одно AMQP-соединение, а `Topology` — кэш-флаг declare'а.
 *
 * @implements PublisherInterface
 * @package    App\Messaging\Rabbit
 */
readonly class RabbitPublisher implements PublisherInterface
{
    /**
     * @param Connection $connection Обёртка над AMQP-соединением; отдаёт живой channel.
     * @param Topology   $topology   Декларация exchange/queue/binding; вызывается перед каждым publish, но реально работает только при первом обращении.
     * @param Config     $config     Laravel-репозиторий конфигов; используются ключи `messaging.notifications.{exchange,routing_key,max_priority}`.
     */
    public function __construct(
        private Connection $connection,
        private Topology   $topology,
        private Config     $config,
    )
    {
    }

    /**
     * @inheritDoc
     * @throws JsonException
     * @throws Exception
     */
    public function publish(Notification $notification): void
    {
        $channel = $this->connection->channel();
        $this->topology->ensureDeclared($channel);

        $exchange = (string) $this->config->get('messaging.notifications.exchange');
        $routingKey = (string) $this->config->get('messaging.notifications.routing_key');
        $maxPriority = (int) $this->config->get('messaging.notifications.max_priority');

        $message = new AMQPMessage(
            json_encode(['id' => $notification->id], JSON_THROW_ON_ERROR),
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'priority' => $this->mapPriority($notification->priority, $maxPriority),
                'message_id' => $notification->id,
            ]
        );

        $channel->basic_publish($message, $exchange, $routingKey);
    }

    /**
     * Переводит доменный приоритет в числовой AMQP-priority в диапазоне
     * `1..max_priority`, который RabbitMQ использует для сортировки
     * внутри priority-queue.
     *
     * Стратегия — крайности диапазона: TRANSACTIONAL получает максимум
     * (по умолчанию 10), MARKETING — минимум (1). Промежуточные значения
     * не используются осознанно: при двух классах достаточно бинарного
     * разделения, а оставленный «зазор» в 2..9 упрощает будущее
     * добавление новых приоритетов без миграции in-flight сообщений.
     *
     * `match` exhaustive: при добавлении нового case в `PriorityEnum`
     * без обновления этого метода PHP бросит `UnhandledMatchError`
     * на первом же publish — лучше, чем тихий фолбэк в неверную ветку.
     *
     * @param PriorityEnum $priority    Доменный приоритет уведомления.
     * @param int          $maxPriority Верхняя граница диапазона из `messaging.notifications.max_priority`.
     *
     * @return int Числовой приоритет AMQP-сообщения для `basic_publish`.
     */
    private function mapPriority(PriorityEnum $priority, int $maxPriority): int
    {
        return match ($priority) {
            PriorityEnum::TRANSACTIONAL => $maxPriority,
            PriorityEnum::MARKETING => 1
        };
    }
}
