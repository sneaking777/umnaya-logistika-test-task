<?php

declare(strict_types=1);

namespace App\Messaging\Rabbit;

use Exception;
use Illuminate\Contracts\Config\Repository as Config;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Throwable;

/**
 * Низкоуровневая обёртка над AMQP-соединением для RabbitMQ.
 *
 * Скрывает внутри себя пару `AMQPStreamConnection` + `AMQPChannel`:
 *
 *  - **Lazy-инициализация**: TCP-сокет к брокеру не открывается
 *    в конструкторе; первое подключение и declare канала происходят
 *    при первом вызове `channel()`. Это означает, что HTTP-запросы,
 *    которым RabbitMQ не нужен (history-эндпоинт, healthcheck и т.п.),
 *    не платят за соединение.
 *
 *  - **Один canonical channel**: держим одно соединение и один channel
 *    на жизненный цикл инстанса. Если channel или connection
 *    закрылись (брокер перезагрузился, тайм-аут, ошибка предыдущего
 *    publish'а), следующий `channel()` прозрачно их пересоздаст —
 *    верхним слоям retry-логики этим заниматься не нужно.
 *
 *  - **Config через DI**: параметры подключения берутся из
 *    `config('messaging.rabbitmq')` через инжектированный
 *    `Repository`, а не через глобальный `config()`-хелпер. Это
 *    упрощает тестирование (контейнер можно подменить in-memory'ным
 *    репозиторием) и не привязывает класс к фасадам Laravel.
 *
 * Heartbeat, publisher confirms, TLS, persistent socket и таймауты
 * в этой версии не настраиваются — это намеренное упрощение для
 * test-task. Добавление любой из этих опций — точечная правка
 * `openConnection()` без изменения публичного контракта.
 *
 * Срок жизни инстанса:
 *
 *  - в HTTP-запросах — на длину запроса (`scoped` биндинг
 *    в ServiceProvider'е), соединение закроется при разрушении
 *    container'а;
 *  - в long-running Consumer'е — на всё время работы воркера,
 *    `close()` вызывается в shutdown-хуке.
 */
class Connection
{
    /**
     * Активное AMQP-соединение или `null`, если ещё не открыто либо
     * было закрыто. Лениво создаётся в `openConnection()`.
     */
    private ?AMQPStreamConnection $connection = null;

    /**
     * Открытый AMQP-channel поверх `$connection`, или `null`, если ещё
     * не создан либо закрыт. Лениво создаётся в `channel()`.
     */
    private ?AMQPChannel $channel = null;

    /**
     * @param Config $config Laravel-репозиторий конфигов; используется секция `messaging.rabbitmq.*`.
     */
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Возвращает живой AMQP-channel. При первом вызове или после того,
     * как канал был закрыт, открывает соединение и создаёт новый
     * channel. Семантика идемпотентная: каждый последующий вызов
     * отдаёт тот же объект, пока он не закрылся.
     *
     * @return AMQPChannel Открытый channel, готовый для declare/publish/consume.
     *
     * @throws Exception При невозможности установить TCP-соединение, AMQP-handshake'а или открыть channel. Конкретный класс — из php-amqplib (AMQPIOException, AMQPProtocolException и т.п.).
     */
    public function channel(): AMQPChannel
    {
        if ($this->channel === null || !$this->channel->is_open()) {
            $this->channel = $this->openConnection()->channel();
        }

        return $this->channel;
    }

    /**
     * Явное закрытие channel'а и соединения. Безопасно при любом
     * состоянии: уже закрытое или невалидное соединение не приводит
     * к исключению наружу — потенциальные ошибки `close()` от
     * php-amqplib глотаются, потому что в shutdown-сценарии они
     * не несут полезной информации.
     *
     * После вызова поля обнуляются, и следующее обращение
     * к `channel()` начнёт жизненный цикл соединения заново.
     */
    public function close(): void
    {
        if ($this->channel !== null) {
            try {
                $this->channel->close();
            } catch (Throwable) {
            }
            $this->channel = null;
        }

        if ($this->connection !== null) {
            try {
                $this->connection->close();
            } catch (Throwable) {
            }
            $this->connection = null;
        }
    }

    /**
     * Возвращает живое `AMQPStreamConnection`. При первом вызове или
     * после потери соединения выполняет TCP+AMQP handshake с брокером,
     * используя параметры из `config('messaging.rabbitmq')`.
     *
     * Не считаем нужным выставлять метод публичным: верхним слоям
     * (Publisher / Consumer) достаточно `channel()`, прямой доступ
     * к connection-объекту нужен только внутренней логике.
     *
     * @return AMQPStreamConnection Открытое соединение с брокером.
     *
     * @throws Exception При сетевом сбое, отказе авторизации или несовместимости версий AMQP.
     */
    private function openConnection(): AMQPStreamConnection
    {
        if ($this->connection === null || !$this->connection->isConnected()) {
            $this->connection = new AMQPStreamConnection(
                host: (string) $this->config->get('messaging.rabbitmq.host'),
                port: (int) $this->config->get('messaging.rabbitmq.port'),
                user: (string) $this->config->get('messaging.rabbitmq.user'),
                password: (string) $this->config->get('messaging.rabbitmq.password'),
                vhost: (string) $this->config->get('messaging.rabbitmq.vhost'),
            );
        }

        return $this->connection;
    }
}
