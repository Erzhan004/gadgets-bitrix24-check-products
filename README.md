# Bitrix24: проверка товара в сделке (чистый PHP)

Сверяет товар, который упаковщик привязал к сделке, с текстом заказа Kaspi из пользовательского поля сделки.
Сравниваются бренд, модель, RAM, память (1 TB = 1024 GB), цвет (RU/EN), SIM и количество. IMEI в скобках игнорируется.
Модель: «бренд + модель» приводится к нижнему регистру без скобок, пробелов и разделителей (`Apple iPhone 17 Pro Max` → `appleiphone17promax`)
и совпадает, если одна строка содержит другую. Память, RAM, цвет и SIM проверяются отдельно: при разной памяти товар не совпадает.
SIM сравнивается, только если указана и в Kaspi, и в Bitrix.
Без AI, без БД, без очередей. Зависимости: Guzzle, Monolog, phpdotenv.

## Как работает

1. Сделка изменилась → Bitrix24 шлёт исходящий вебхук `ONCRMDEALUPDATE` на `POST /api/bitrix/events/deal-update`.
2. Скрипт берёт сделку (`crm.deal.get`) и товары (`crm.deal.productrows.get`), считает SHA-256 по `PRODUCT_ID`, `PRODUCT_NAME`, `QUANTITY`.
3. Hash равен сохранённому в `UF_CRM_PRODUCTS_HASH` → ничего не делаем (сделку **не** обновляем, иначе будет бесконечный цикл).
4. Hash пустой или другой → полная проверка, в сделку пишутся **только** статус (`OK`/`ERROR`) и новый hash.
5. При `ERROR` — комментарий в таймлайн и уведомление ответственному + `BITRIX_NOTIFY_USER_IDS`.
6. Подробности (что с чем сравнивали, какая ошибка) — в `storage/logs/app.log`.

Пустое Kaspi-поле → пропуск, hash не сохраняется (проверка запустится, когда поле заполнят).
Одновременные события по одной сделке отсекаются файловой блокировкой (`storage/locks`).

## Эндпоинты

| Метод | Путь | Доступ |
|---|---|---|
| POST | `/api/bitrix/events/deal-update` | `auth[application_token]` = `BITRIX_EVENT_APPLICATION_TOKEN` или `?token=` = `BITRIX_CALLBACK_TOKEN` |
| POST | `/api/bitrix/check-product` | `token` / заголовок `X-Bitrix-Token` / `Bearer` = `BITRIX_CALLBACK_TOKEN`; `deal_id` (можно `DEAL_123`, `document_id`) |
| GET | `/api/debug/bitrix/deal/{id}` | только при `APP_ENV=local` |

`check-product` — ручная/роботная проверка без сравнения hash.

## Поля сделки в Bitrix24

Создайте заранее (тип «Строка»):

- поле с текстом заказа Kaspi → `BITRIX_KASPI_PRODUCT_FIELD`;
- «Статус проверки» → `BITRIX_PRODUCT_CHECK_STATUS_FIELD`;
- «Hash товаров» → `BITRIX_PRODUCTS_HASH_FIELD`.

**Важно:** hash-поле должно существовать до включения исходящего вебхука. Bitrix молча игнорирует неизвестные поля — hash не сохранится, и каждое обновление статуса будет вызывать новое событие.

## Установка локально

```bash
composer install
cp .env.example .env   # заполнить
vendor/bin/phpunit
php -S 127.0.0.1:8000 -t public public/index.php
```

## Развёртывание на Hoster.kz (Plesk)

1. **PHP**: «Настройки PHP» домена → 8.2+ (FPM), расширения `mbstring`, `json`, `curl`.
2. **Файлы**: загрузите проект в папку домена, например `httpdocs/bitrix-check` (через Git в Plesk или архивом). Папку `vendor` можно собрать локально (`composer install --no-dev -o`) и загрузить вместе с проектом, если на хостинге нет Composer.
3. **Корень документов**: «Хостинг и DNS → Настройки хостинга → Корневая папка документов» = `httpdocs/bitrix-check/public`.
   Так `.env`, `vendor`, `storage` недоступны из браузера. Можно вынести на поддомен, например `check.example.kz`.
4. **.env**: создайте из `.env.example` в корне проекта (не в `public`), `APP_ENV=production`.
5. **Права**: `storage/logs` и `storage/locks` должны быть доступны на запись (обычно уже так).
6. **SSL**: включите Let's Encrypt — Bitrix шлёт вебхук на `https://`.
7. **Bitrix24**:
   - Входящий вебхук (права `crm`, `im`) → URL в `BITRIX_WEBHOOK_URL`.
   - Исходящий вебхук: событие «Обновление сделки» (`ONCRMDEALUPDATE`), URL
     `https://check.example.kz/api/bitrix/events/deal-update`; выданный «Код авторизации» → `BITRIX_EVENT_APPLICATION_TOKEN`.
8. Проверка: измените товар в тестовой сделке и посмотрите `storage/logs/app.log`.

Cron и очереди не нужны: проверка выполняется прямо в запросе вебхука (обычно 1–3 секунды).

## Безопасность

- Секреты только в `.env`; `.env` в `.gitignore` и закрыт в `.htaccess`.
- URL вебхука вырезается из логов и ответов (`[redacted-webhook]`).
- Токены сравниваются через `hash_equals`.

## Структура

```
public/index.php            точка входа
src/Application.php         сборка зависимостей, Guzzle с повторами
src/Http/Kernel.php         маршруты, токены, коды ответов
src/Services/               парсер, сравнение, hash, Bitrix API, уведомления, сценарий проверки
src/Support/                Settings (.env), DealLock (flock)
config/products.php         словарь цветов и брендов
```
