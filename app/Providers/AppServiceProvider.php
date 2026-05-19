<?php

declare(strict_types=1);

namespace App\Providers;

use App\Messaging\Contracts\PublisherInterface;
use App\Messaging\Rabbit\Connection;
use App\Messaging\Rabbit\RabbitPublisher;
use App\Messaging\Rabbit\Topology;
use Illuminate\Support\ServiceProvider;

/**
 * Основной сервис-провайдер приложения.
 *
 * Единственная задача на текущий момент — регистрация DI-биндингов
 * для messaging-стека: связь интерфейса `PublisherInterface` с продовой
 * реализацией `RabbitPublisher` и фиксация общего lifecycle для
 * `Connection`, `Topology` и Publisher'а.
 *
 * Все три биндинга — `scoped`, что важно для корректной работы кэша
 * declare'а в `Topology` и переиспользования AMQP-соединения внутри
 * одного scope (HTTP-запрос под FPM или один цикл long-running
 * consumer'а). Подробное обоснование выбора — в class-level PHPDoc
 * соответствующих классов.
 *
 * @package App\Providers
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Регистрирует биндинги messaging-стека в DI-контейнере.
     *
     * Три привязки:
     *
     *  - `Connection` (`scoped`) — обёртка над AMQP-соединением;
     *    один инстанс на scope, чтобы переиспользовать TCP-соединение
     *    и AMQP-channel в рамках запроса или цикла consumer'а.
     *
     *  - `Topology`   (`scoped`) — декларация exchange/queue/binding;
     *    хранит внутренний кэш-флаг `$declared`, который имеет смысл
     *    только при том же инстансе на весь scope, иначе declare
     *    выполнялся бы повторно на каждый publish.
     *
     *  - `PublisherInterface → RabbitPublisher` (`scoped`) — связь
     *    контракта публикации с продовой реализацией. Интерфейсный
     *    биндинг позволяет в интеграционных тестах подменять Publisher
     *    на in-memory-вариант без правок вызывающего кода.
     *
     * `scoped` выбран сознательно вместо `singleton`: в long-running
     * consumer'е можно через `Container::forgetScopedInstances()`
     * вручную сбросить state цепочки (например, после фатальной ошибки),
     * не пересоздавая весь процесс. Под FPM в одном HTTP-запросе
     * разницы между `scoped` и `singleton` нет — контейнер всё равно
     * пересоздаётся между запросами.
     */
    public function register(): void
    {
        $this->app->scoped(Connection::class);
        $this->app->scoped(Topology::class);
        $this->app->scoped(PublisherInterface::class, RabbitPublisher::class);
    }

    /**
     * Хук бутстрапа сервисов; вызывается после регистрации всех провайдеров.
     *
     * Сейчас пуст: в проекте нет policy, кастомных Blade-директив,
     * рантайм-конфигурации моделей и других вещей, требующих boot-фазы.
     * Оставлен как точка расширения — добавление сюда нового кода
     * не требует правок остальной инфраструктуры приложения.
     */
    public function boot(): void
    {
    }
}
