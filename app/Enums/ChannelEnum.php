<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Канал доставки уведомления.
 *
 * Backed string enum: значения backing совпадают с тем, что хранится в БД
 * в полях `recipient_contacts.channel` и `notifications.channel`
 * (см. миграции 2026_05_18_120001 и 2026_05_18_120002). Перечень закрыт:
 * добавление нового канала требует одновременных правок в моделях,
 * валидации запросов и инфраструктуре провайдеров.
 *
 * Используется тремя сторонами:
 *
 *  - Eloquent-моделями RecipientContact и Notification — как cast атрибута
 *    (`'channel' => ChannelEnum::class`), что гарантирует домену значение
 *    одного из case'ов на чтении из БД;
 *  - валидацией HTTP-запросов на массовую рассылку (через FormRequest,
 *    правило `Rule::enum(ChannelEnum::class)`);
 *  - инфраструктурой отправки — для выбора провайдера (SMS → SmsProvider,
 *    EMAIL → EmailProvider). Сам маппинг «канал → провайдер» вынесен
 *    в DI-контейнер, чтобы не тянуть инфраструктурные зависимости
 *    в доменный enum.
 */
enum ChannelEnum: string
{
    /**
     * SMS-сообщение. Адрес назначения (`notifications.to_address`) —
     * номер телефона; конкретный формат (E.164 и т.п.) задаётся правилами
     * валидации в FormRequest, а не самим enum'ом.
     */
    case SMS = 'sms';

    /**
     * Email-сообщение. Адрес назначения (`notifications.to_address`) —
     * email-адрес; формат проверяется валидацией FormRequest.
     */
    case EMAIL = 'email';
}
