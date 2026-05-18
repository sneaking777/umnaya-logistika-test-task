<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | RabbitMQ — параметры подключения
    |--------------------------------------------------------------------------
    |
    | Используются обёрткой App\Messaging\Rabbit\Connection при создании
    | соединения через php-amqplib. Все значения переопределяются
    | окружением (см. .env.example) — это позволяет не пересобирать
    | образ при смене кредов или хоста брокера.
    |
    */

    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', 'rabbitmq'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications topology
    |--------------------------------------------------------------------------
    |
    | Одна priority-queue с `x-max-priority`. Publisher выставляет AMQP
    | message-priority по PriorityEnum (transactional → 10, marketing → 1),
    | RabbitMQ сортирует сообщения внутри очереди. Один consumer-loop
    | вычитывает их в порядке приоритета.
    |
    | `prefetch` намеренно держим близким к 1: при большом prefetch
    | RabbitMQ заранее раздаёт consumer'у пачку низкоприоритетных
    | сообщений, и они «обгоняют» только что прилетевшее транзакционное —
    | приоритет на уровне очереди в этом случае работает плохо.
    |
    */

    'notifications' => [
        'exchange' => env('NOTIFICATIONS_EXCHANGE', 'notifications'),
        'queue' => env('NOTIFICATIONS_QUEUE', 'notifications'),
        'routing_key' => env('NOTIFICATIONS_ROUTING_KEY', 'notifications'),
        'max_priority' => (int) env('NOTIFICATIONS_QUEUE_MAX_PRIORITY', 10),
        'prefetch' => (int) env('NOTIFICATIONS_PREFETCH', 1),
    ],

];
