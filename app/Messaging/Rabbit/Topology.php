<?php

declare(strict_types=1);

namespace App\Messaging\Rabbit;

use Illuminate\Contracts\Config\Repository as Config;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Декларация RabbitMQ-топологии notification-стека.
 *
 * Отвечает за три AMQP-команды, которые задают «обстановку» брокера —
 * то, во что Publisher публикует и из чего Consumer читает:
 *
 *  1. `exchange_declare` — direct-exchange `notifications`;
 *  2. `queue_declare`    — одна priority-queue `notifications`
 *     с аргументом `x-max-priority` (вариант A — приоритезация
 *     transactional/marketing через AMQP message priority внутри
 *     одной очереди, не через две раздельные очереди high/low);
 *  3. `queue_bind`       — binding очереди к exchange по routing-key.
 *
 * Все три сущности декларируются с `durable: true` и `auto_delete: false`,
 * то есть переживают перезагрузку брокера и не удаляются при отключении
 * последнего consumer'а.
 *
 * Разделение ответственности:
 *
 *  - `Connection` знает «как подключиться к брокеру»;
 *  - `Topology`   знает «какие сущности должны существовать в брокере»;
 *  - `Channel` передаётся снаружи методом `ensureDeclared` — Topology
 *    не держит на него ссылку и не управляет его жизненным циклом.
 *
 * Идемпотентность гарантируется на двух уровнях:
 *
 *  - сам RabbitMQ принимает повторные declare с теми же параметрами
 *    без ошибки (declare с расходящимися параметрами падает с 406
 *    PRECONDITION_FAILED — намеренное поведение, защищает от случайной
 *    подмены конфигурации);
 *  - локально храним флаг `$declared`, чтобы избежать лишних сетевых
 *    обменов при повторных publish'ах в одном процессе.
 *
 * Все параметры топологии берутся из `config('messaging.notifications')` —
 * имя exchange, очереди, routing-key и максимальный приоритет
 * настраиваются через `.env` без перекомпиляции.
 */
class Topology
{
    /**
     * Кэш-флаг «топология уже задекларирована в этом процессе».
     * После первого успешного `ensureDeclared` ставится в `true`,
     * последующие вызовы становятся no-op до перезапуска процесса.
     */
    private bool $declared = false;

    /**
     * @param Config $config Laravel-репозиторий конфигов; используется секция `messaging.notifications.*`.
     */
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Гарантирует, что exchange, очередь и binding объявлены в брокере.
     *
     * Семантика идемпотентная: первый вызов выполняет три AMQP-команды,
     * последующие — мгновенно возвращаются благодаря кэш-флагу
     * `$declared`. Метод не закрывает channel и не управляет его
     * жизненным циклом — это забота вызывающей стороны (Publisher
     * или Consumer).
     *
     * @param AMQPChannel $channel Открытый AMQP-channel, на котором выполняются declare-команды.
     */
    public function ensureDeclared(AMQPChannel $channel): void
    {
        if ($this->declared) {
            return;
        }

        $exchange = (string) $this->config->get('messaging.notifications.exchange');
        $queue = (string) $this->config->get('messaging.notifications.queue');
        $routingKey = (string) $this->config->get('messaging.notifications.routing_key');
        $maxPriority = (int) $this->config->get('messaging.notifications.max_priority');

        $channel->exchange_declare(
            exchange: $exchange,
            type: 'direct',
            durable: true,
            auto_delete: false,
        );

        $channel->queue_declare(
            queue: $queue,
            durable: true,
            auto_delete: false,
            arguments: new AMQPTable(['x-max-priority' => $maxPriority]),
        );

        $channel->queue_bind(
            queue: $queue,
            exchange: $exchange,
            routing_key: $routingKey,
        );

        $this->declared = true;
    }
}
