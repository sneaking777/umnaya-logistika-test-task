<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Приоритет уведомления.
 *
 * Backed string enum: значения backing совпадают с тем, что хранится в БД
 * в поле `notifications.priority` (см. миграцию 2026_05_18_120002).
 * Перечень закрыт; добавление нового приоритета требует одновременных
 * правок в моделях, валидации запросов и маппинге RabbitMQ-priority
 * в Publisher'е.
 *
 * Семантика и порядок:
 *
 *  - TRANSACTIONAL — операционные сообщения (OTP, чеки, статусы заказа);
 *    должны обходить marketing-поток в очереди. В Publisher'е маппятся
 *    в более высокий numeric priority AMQP-сообщения, чем MARKETING.
 *  - MARKETING — массовые промо-рассылки; уступают приоритет
 *    транзакционным.
 *
 * Сам маппинг «приоритет → numeric AMQP priority (0..255)» намеренно
 * не размещён в enum'е: он использует переменные окружения
 * (`NOTIFICATIONS_QUEUE_MAX_PRIORITY`), относится к инфраструктуре
 * брокера и логически принадлежит Publisher'у. Это удерживает enum
 * чистым доменным словарём, не знающим о RabbitMQ.
 *
 * Используется тремя сторонами:
 *
 *  - Eloquent-моделью Notification — как cast атрибута
 *    (`'priority' => PriorityEnum::class`);
 *  - валидацией HTTP-запросов на массовую рассылку (FormRequest,
 *    правило `Rule::enum(PriorityEnum::class)`);
 *  - Publisher'ом RabbitMQ — для выбора routing-key и числового
 *    приоритета сообщения.
 */
enum PriorityEnum: string
{
    /**
     * Транзакционные уведомления: OTP, подтверждения операций, статусы
     * заказа и т.п. Обрабатываются раньше маркетинговых за счёт более
     * высокого numeric AMQP priority в Publisher'е.
     */
    case TRANSACTIONAL = 'transactional';

    /**
     * Маркетинговые уведомления: массовые промо-рассылки, новостные
     * дайджесты. Уступают приоритет транзакционным сообщениям.
     */
    case MARKETING = 'marketing';
}
