<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware идемпотентности для POST-эндпоинтов API.
 *
 * Дедуплицирует повторные запросы с одинаковым заголовком `Idempotency-Key`:
 * первый запрос с парой `(key, scope)` обрабатывается обычным путём,
 * его ответ кэшируется в Redis (быстрый путь) и параллельно в таблице
 * `idempotency_keys` (ground-truth fallback), повторный запрос с тем же
 * ключом получает сохранённый ответ без повторного выполнения бизнес-логики.
 *
 * Поток обработки:
 *
 *  1. Если заголовка нет — middleware прозрачен (`return $next($request)`).
 *     Это совместимо с healthcheck'ами и тестами, которые ключ не шлют.
 *
 *  2. Длина ключа ≤ 255 — под `varchar(255)` в `idempotency_keys.key`;
 *     PG считает символы, поэтому используем `mb_strlen`.
 *
 *  3. Считаем SHA-256 от raw request body — `payload_hash`. Защищает
 *     от reuse одного ключа с разным payload'ом: при mismatch отдаём
 *     `409 Conflict` (IETF idempotency draft).
 *
 *  4. Быстрый путь: `Redis::get` по ключу `<prefix>:<scope>:<key>`.
 *     Cache hit + hash совпал → отдаём кэш с заголовком
 *     `Idempotent-Replayed: true`.
 *
 *  5. Cache miss → fallback в БД по `(key, scope)`. Hit → регидрируем
 *     Redis с `hash: null` (в существующей схеме нет колонки
 *     `payload_hash`, проверка пропускается на fallback-пути) и отдаём
 *     сохранённый ответ.
 *
 *  6. Полный miss → пропускаем запрос в контроллер, после получения
 *     ответа кэшируем (Redis SETEX + INSERT в БД) ТОЛЬКО если статус
 *     `< 500` и тело — валидный JSON-array. 5xx не кэшируется намеренно:
 *     транзиентные ошибки должны иметь шанс пройти на ретрае.
 *
 * Race condition на первичной обработке: два параллельных запроса
 * с одним ключом проходят все проверки, оба попадают в контроллер,
 * оба пытаются `INSERT` в `idempotency_keys`. UNIQUE(key, scope)
 * гарантирует, что один из них упадёт с PG SQLSTATE `23505` — ловим
 * этот конкретный код и тихо игнорируем (победитель уже всё записал).
 * Любые другие `QueryException` пробрасываем наружу.
 *
 * Идемпотентность защиты payload_hash:
 *
 *  - Redis-путь — строгая проверка, mismatch → 409.
 *  - DB-fallback — без проверки (нет колонки `payload_hash` в схеме).
 *    Это сознательный компромисс: основная защита живёт в Redis,
 *    DB — best-effort fallback на случай его потери. Если позже
 *    понадобится строгая защита и на fallback-пути, добавим колонку
 *    отдельной миграцией.
 *
 * Класс `readonly`: единственная зависимость (`Config`) не меняется
 * после конструирования. Биндинг middleware'а — по умолчанию transient,
 * Laravel создаёт его на каждый запрос; это допустимо, потому что
 * Redis и БД-запросы внутри не используют состояние класса.
 *
 * @package App\Http\Middleware
 */
readonly class EnsureIdempotency
{
    /**
     * @param Config $config Laravel-репозиторий конфигов; используются ключи `idempotency.ttl_seconds` и `idempotency.redis_prefix`.
     */
    public function __construct(private Config $config)
    {
    }

    /**
     * Точка входа middleware. Полный поток описан в class-level PHPDoc;
     * метод последовательно: валидирует заголовок → проверяет Redis →
     * проверяет БД → пропускает запрос дальше → кэширует ответ.
     *
     * @param Request $request HTTP-запрос; читаем `Idempotency-Key`-заголовок и raw body для расчёта payload-hash.
     * @param Closure $next    Следующее звено pipeline'а; вызывается только при cache miss.
     * @param string  $scope   Имя API-эндпоинта (например, `notifications.bulk`), приходит параметром middleware на роуте; защищает от коллизии ключа между разными эндпоинтами.
     *
     * @return Response Либо ответ из кэша с заголовком `Idempotent-Replayed: true`, либо результат `$next($request)`, либо синтетический 400/409/500 на нарушение протокола.
     *
     * @throws JsonException При сбое `json_encode` во время записи envelope'а в Redis (практически невозможно для наших структур).
     */
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || $key === '') {
            return $next($request);
        }

        if (mb_strlen($key) > 255) {
            return response()->json(['message' => 'Idempotency-Key не должен превышать 255 символов.'], 400);
        }

        $payloadHash = hash('sha256', $request->getContent());
        $redisKey = $this->redisKey($scope, $key);

        $cached = Redis::get($redisKey);
        if ($cached !== null && $cached !== false) {
            return $this->replayFromRedis((string)$cached, $payloadHash);
        }

        $row = IdempotencyKey::query()
            ->where('key', $key)
            ->where('scope', $scope)
            ->first();

        if ($row !== null) {
            $this->rehydrateRedis($redisKey, $row);

            return response()
                ->json($row->response_body, $row->response_status)
                ->header('Idempotent-Replayed', 'true');
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 500) {
            return $response;
        }

        $body = $this->decodeBody($response);
        if ($body === null) {
            return $response;
        }

        $this->persist($key, $scope, $payloadHash, $response->getStatusCode(), $body, $redisKey);

        return $response;
    }

    /**
     * Обрабатывает cache hit из Redis: декодирует envelope, проверяет
     * payload-hash и возвращает закэшированный ответ либо synthetic 409/500.
     *
     * @param string $cached     Сырая строка из Redis — JSON-envelope `{hash, status, body}`.
     * @param string $payloadHash SHA-256 текущего request body; сравнивается с `hash` из envelope'а.
     *
     * @return JsonResponse Закэшированный ответ с заголовком `Idempotent-Replayed: true`, либо `409 Conflict` при mismatch payload'а, либо `500` при битом JSON в кэше.
     */
    private function replayFromRedis(string $cached, string $payloadHash): JsonResponse
    {
        try {
            $data = json_decode($cached, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(
                ['message' => 'Кэш идемпотентности повреждён.'],
                500,
            );
        }

        if ($data['hash'] !== null && $data['hash'] !== $payloadHash) {
            return response()->json(
                ['message' => 'Idempotency-Key переиспользован с другим телом запроса.'],
                409,
            );
        }

        return response()
            ->json($data['body'], (int)$data['status'])
            ->header('Idempotent-Replayed', 'true');
    }

    /**
     * Восстанавливает запись в Redis по найденной строке из БД на
     * fallback-пути. В envelope попадает `hash: null` — на следующем
     * Redis-cache-hit `replayFromRedis()` пропустит сравнение хэша,
     * потому что в существующей схеме `idempotency_keys` колонки
     * `payload_hash` нет.
     *
     * TTL вычисляется как `expires_at - now()` с floor'ом в 1 секунду —
     * страховка от Redis-ошибки, если запись в БД уже подходит к концу
     * (Redis отвергает нулевой или отрицательный TTL).
     *
     * @param string         $redisKey Полный ключ Redis (`<prefix>:<scope>:<key>`).
     * @param IdempotencyKey $row      Запись из БД-таблицы, найденная по `(key, scope)`.
     *
     * @throws JsonException При сбое `json_encode` envelope'а (практически невозможно для наших структур).
     */
    private function rehydrateRedis(string $redisKey, IdempotencyKey $row): void
    {
        $ttl = max(1, (int)Carbon::now()->diffInSeconds($row->expires_at));

        Redis::setex($redisKey, $ttl, json_encode([
            'hash' => null,
            'status' => $row->response_status,
            'body' => $row->response_body,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Сохраняет ответ контроллера в обоих хранилищах: сначала Redis
     * (быстрый путь), затем INSERT в `idempotency_keys` (ground-truth).
     *
     * Race-condition двух параллельных первичных запросов с одним
     * `(key, scope)` ловится UNIQUE-индексом БД: проигравший INSERT
     * падает с PG SQLSTATE `23505`, мы тихо игнорируем (победитель
     * уже всё записал). Любые другие `QueryException` пробрасываем —
     * это могут быть deadlock, connection lost и т.п., которые
     * заслуживают 500-ответа.
     *
     * Что НЕ сохраняем (отсечено в `handle()` до вызова этого метода):
     *
     *  - 5xx ответы — должны иметь шанс пройти на ретрае;
     *  - не-JSON или не-array тела — `response_body` имеет тип `jsonb`
     *    и cast `'array'`, иначе упадёт на INSERT.
     *
     * @param string $key         Значение заголовка `Idempotency-Key`.
     * @param string $scope       Имя API-эндпоинта.
     * @param string $payloadHash SHA-256 raw request body; сохраняется в Redis envelope для будущей валидации mismatch'а.
     * @param int    $status      HTTP-статус ответа контроллера.
     * @param array<int|string, mixed> $body Декодированный JSON-array тела ответа.
     * @param string $redisKey    Полный ключ Redis, вычислен заранее в `handle()`.
     *
     * @throws JsonException При сбое `json_encode` envelope'а (практически невозможно для наших структур).
     */
    private function persist(
        string $key,
        string $scope,
        string $payloadHash,
        int    $status,
        array  $body,
        string $redisKey,
    ): void
    {
        $ttl = (int)$this->config->get('idempotency.ttl_seconds');

        Redis::setex($redisKey, $ttl, json_encode([
            'hash' => $payloadHash,
            'status' => $status,
            'body' => $body,
        ], JSON_THROW_ON_ERROR));

        try {
            IdempotencyKey::create([
                'key' => $key,
                'scope' => $scope,
                'response_status' => $status,
                'response_body' => $body,
                'expires_at' => Carbon::now()->addSeconds($ttl),
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) !== '23505') {
                throw $e;
            }
        }
    }

    /**
     * Безопасно декодирует тело ответа из JSON в массив.
     *
     * Возвращает `null` (что для вызывающей стороны означает «не кэшируй
     * этот ответ»), если тело пустое, не строка, не валидный JSON или
     * декодилось во что-то отличное от массива. Это страховка: колонка
     * `idempotency_keys.response_body` имеет тип `jsonb NOT NULL` и cast
     * `'array'` — попытка сохранить `null` или скаляр упала бы на INSERT.
     *
     * @param Response $response Ответ контроллера; ожидается `JsonResponse`, но метод defensive — не падает на чём угодно.
     *
     * @return array<int|string, mixed>|null Декодированный массив либо `null`, если ответ не пригоден для кэширования.
     */
    private function decodeBody(Response $response): ?array
    {
        $content = $response->getContent();
        if (!is_string($content) || $content === '') {
            return null;
        }

        try {
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Формирует полный ключ для Redis из префикса, scope'а и пользовательского
     * `Idempotency-Key`. Формат `<prefix>:<scope>:<key>` — конвенция Laravel
     * cache namespace'ов; двоеточие — стандартный разделитель в Redis
     * Management UI, не требует экранирования.
     *
     * @param string $scope Имя API-эндпоинта.
     * @param string $key   Значение заголовка `Idempotency-Key`.
     *
     * @return string Полный ключ Redis, готовый для `Redis::get`/`Redis::setex`.
     */
    private function redisKey(string $scope, string $key): string
    {
        $prefix = (string)$this->config->get('idempotency.redis_prefix');
        return "$prefix:$scope:$key";
    }
}
