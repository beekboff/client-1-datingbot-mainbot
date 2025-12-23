## Dating Bot: сервис обработки сообщений Telegram через RabbitMQ

Этот сервис принимает апдейты Telegram из RabbitMQ, обрабатывает команды/колбэки, хранит пользователей в MySQL и отправляет сообщения в Telegram через HTTP API.

### Требования
- PHP >= 8.2
- MySQL 8+ (или совместимый)
- RabbitMQ 3.12+
- Composer
- (опционально) Docker для запуска контейнера с PHP (см. docker/Dockerfile)

### Поддержка нескольких ботов
Сервис поддерживает работу с несколькими ботами одновременно. Изоляция данных обеспечивается на уровне:
- **Базы данных**: для каждого бота используется отдельная БД с именем `{bot_id}_dating_bot` (например, `123_dating_bot`).
- **RabbitMQ**: для каждого бота используется отдельный virtual host (`vhost`), имя которого совпадает с `{bot_id}`.

### Переменные окружения и конфигурация
Конфигурация теперь поддерживает несколько токенов. Основные настройки рекомендуется выносить в `config/common/params-local.php`.

Пример `config/common/params-local.php`:
```php
return [
    'db' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=dating_bot;charset=utf8mb4', // dbname будет заменено на {bot_id}_dating_bot
        'user' => 'root',
        'pass' => 'password',
    ],
    'rabbitmq' => [
        'host' => '127.0.0.1',
        'port' => 5672,
        'user' => 'guest',
        'pass' => 'guest',
    ],
    'telegram' => [
        'bots' => [
            '123' => [
                'token' => '123456:ABC...',
            ],
            '456' => [
                'token' => '456789:XYZ...',
            ],
        ],
        'log_chat_id' => '...', // Чат для логов
    ],
];
```

Также поддерживаются классические переменные окружения (используются как значения по умолчанию):
- `TELEGRAM_BOT_TOKEN` — токен бота по умолчанию
- `TELEGRAM_LOG_CHAT_ID` — ID чата для логов
- `RABBITMQ_HOST`, `RABBITMQ_PORT`, `RABBITMQ_USER`, `RABBITMQ_PASS`
- `DB_DSN`, `DB_USER`, `DB_PASS`
- `APP_ENV` (`dev`/`prod`)
- `BOT_ID` — можно задать глобально через ENV, если запускается только один бот.

URL для создания анкеты настраивается в `config/common/params.php` → `app.profileCreateUrl`.

### Установка
```bash
composer install
composer dump-autoload
```

### Миграции БД
Используется механизм Yii3 через консольное приложение (`./yii migrate:*`). 
**Важно**: миграции нужно запускать для каждой базы данных бота отдельно, указывая `BOT_ID`.

Примеры команд:
```bash
# Применить миграции для бота 123 (БД 123_dating_bot)
BOT_ID=123 ./yii migrate:up

# Применить миграции для бота 456 (БД 456_dating_bot)
BOT_ID=456 ./yii migrate:up
```

Миграции создают таблицу `users` со столбцами:
- `user_id` BIGINT PK
- `language` VARCHAR(8)
- `looking_for` VARCHAR(16)
- `status` TINYINT (1 — активен, 0 — заблокирован)
- `last_push` DATETIME
- `created_at`, `updated_at` DATETIME

Также добавлена таблица дедупликации входящих апдейтов Telegram:
- `telegram_processed_updates` с полями:
  - `update_id` BIGINT PK — уникальный идентификатор апдейта из Telegram
  - `created_at` DATETIME — дата и время первой обработки
  - индекс по `created_at` для быстрого очищения

Диспетчер `src/Telegram/UpdateDispatcher.php` проверяет `update_id` и пропускает апдейты, которые уже были обработаны ранее.

### Настройка RabbitMQ топологии
Выполните для каждого бота (создаст обменник/очереди в соответствующем vhost):
```bash
./yii app:setup 123
./yii app:setup 456
```

Будут задекларированы:
- Очередь входящих апдейтов Telegram: `tg_got_data`
- Обменник `tg.direct`
- Очередь `tg.profile_prompt` (для отложенной отправки сообщения «Создайте анкету»)
- Очередь задержки `tg.profile_prompt.delay` с DLX на `tg.direct` и routing-key `tg.profile_prompt` (TTL используется на уровне сообщения)

### Запуск консьюмеров
Для каждого бота нужно запустить свои процессы консьюмеров, передавая `bot_id` аргументом:

1) Консьюмер входящих апдейтов из Telegram (из RabbitMQ):
```bash
./yii rabbit:consume-updates 123
```
2) Консьюмер отложенных уведомлений «создайте свою анкету»:
```bash
./yii rabbit:consume-profile-prompt 123
```
3) Консьюмер подготовленных пуш-сообщений:
```bash
./yii rabbit:consume-pushes 123
```

### Как сервис обрабатывает события
1) Команда `/start`
   - Региструет пользователя (если новый) в таблице `users` (через yiisoft/db-mysql Query/Command).
   - Отправляет сообщение «Кого вам найти?» с двумя колбэк-кнопками: «я ищу женщину», «я ищу мужчину».

2) Колбэк выбора предпочтений (`{"action":"set_preference","data":{"looking_for":"woman|man"}}`)
   - Сохраняет выбор в БД.
   - Публикует отложенное сообщение в RabbitMQ (TTL 15 минут) в очередь `tg.profile_prompt.delay`.
   - Через 15 минут второй консьюмер отправляет пользователю сообщение «Создайте свою анкету…» с кнопками: внешняя ссылка «создать анкету», колбэк «смотреть анкеты».

Поддерживается мультиязычность (en по умолчанию, ru, es). Файлы локализации: `resources/i18n/{en,ru,es}.php`.

### Формат входящих сообщений в очередь `tg_got_data`
Из вашего внешнего вебхука/сервиса положите в очередь JSON следующего вида:
```json
{
  "date": 1733660000,
  "data": {}
}
```

Где `data` — это объект обновления из Telegram Bot API (message/callback_query и т.д.).

### Структура колбэк-кнопок
Все callback_data — JSON одной структуры:
```json
{
  "action": "set_preference",
  "data": { "looking_for": "woman" }
}
```
или
```json
{
  "action": "set_preference",
  "data": { "looking_for": "man" }
}
```

### Отправка сообщений в Telegram
Сервис `TelegramApi` делает HTTP-запросы в Telegram Bot API напрямую. Токен выбирается автоматически на основе текущего `bot_id` из конфигурации `params['telegram']['bots']`. Если токен для конкретного `bot_id` не найден, используется значение по умолчанию из `params['telegram']['token']` (или ENV `TELEGRAM_BOT_TOKEN`).
Поддерживаются `sendMessage` и `sendPhoto`, inline-клавиатуры и внешние ссылки.

### Логирование ошибок в Telegram
- Цель `App\Infrastructure\Logging\TelegramLogTarget` отправляет записи в Telegram. По умолчанию подключены уровни: `error|critical|alert|emergency` (и могут включать `warning/notice`, см. код цели).
- Цель включается автоматически, если задан `TELEGRAM_LOG_CHAT_ID` (среда `APP_ENV` значения не ограничивает отправку).
- Для логов используется токен текущего бота или отдельный токен, если указан `log_bot_token` (ENV `TELEGRAM_LOG_BOT_TOKEN`).
- Настройка в `params-local.php`:
  ```php
  'telegram' => [
      'bots' => [...],
      'log_chat_id' => '123456789',
      'log_bot_token' => '...', // Опционально
  ],
  ```
  2) Убедитесь, что лог‑бот имеет право писать в указанный чат (для групп/каналов добавьте бота и выдайте права).
  3) Ошибки сервиса будут дублироваться в указанный чат. Для быстрого теста можно временно залогировать `logger->error('TEST')`.

### Локализация
- Файлы: `resources/i18n/en.php`, `ru.php`, `es.php`.
- Если язык пользователя не поддерживается, используется английский.

### Быстрый старт (локально)
1. Настройте `config/common/params-local.php` (база, RabbitMQ, токены ботов).
2. `composer install && composer dump-autoload`
3. Примените миграции для нужных ботов: `BOT_ID=123 ./yii migrate:up`
4. Настройте топологию: `./yii app:setup 123`
5. Запустите консьюмеры для каждого бота в отдельных терминалах:
   - `./yii rabbit:consume-updates 123`
   - `./yii rabbit:consume-profile-prompt 123`
   - `./yii rabbit:consume-pushes 123`
6. Настройте ваш внешний вебхук Telegram, чтобы он клал апдейты в очередь `tg_got_data` соответствующего vhost (имя vhost = `bot_id`).

### Docker
В репозитории есть `docker/Dockerfile` на базе `dunglas/frankenphp`. 
Примерный порядок:
```bash
docker build -t dating-bot-php -f docker/Dockerfile .
# Запуск контейнера и проброс env/секретов — на ваше усмотрение
```

### Расширение функциональности
- Директория хэндлеров Telegram: `src/Telegram/Handlers/*`.
- Диспетчер обновлений: `src/Telegram/UpdateDispatcher.php`.
- Клавиатуры: `src/Telegram/KeyboardFactory.php`.
- Репозиторий пользователей: `src/User/UserRepository.php` (использует yiisoft/db-mysql Query/Command API).

### Очереди и задержки
Для отложенных сообщений используется очередь `tg.profile_prompt.delay` с TTL на уровне сообщения и DLX на 
обменник `tg.direct` с routing key `tg.profile_prompt`.

### Ночной скрипт очистки дедупликации
Чтобы таблицы `telegram_processed_updates` не разрастались бесконечно, предусмотрена команда очистки для каждого бота:
```bash
./yii tg:cleanup-processed-updates 123 --days=2
```
По умолчанию хранится 2 суток (можно изменить через опцию `--days`).

### Сброс дневных лимитов пушей
Для корректной работы лимита пушей (например, 3 сообщения в день) нужно каждую полночь сбрасывать счетчики:
```bash
./yii push:reset-daily-counter 123
```

Пример cron (каждую ночь в 03:30 и 00:01):
```
# Очистка дедупликации
30 3 * * * /usr/bin/php /path/to/project/yii tg:cleanup-processed-updates 123 --days=2 >> /var/log/tg_cleanup_123.log 2>&1
30 3 * * * /usr/bin/php /path/to/project/yii tg:cleanup-processed-updates 456 --days=2 >> /var/log/tg_cleanup_456.log 2>&1

# Сброс счетчиков пушей
01 0 * * * /usr/bin/php /path/to/project/yii push:reset-daily-counter 123 >> /var/log/push_reset_123.log 2>&1
01 0 * * * /usr/bin/php /path/to/project/yii push:reset-daily-counter 456 >> /var/log/push_reset_456.log 2>&1
```

### Примечание
Реализация покроет текущие сценарии (/start, выбор предпочтений, отложенная выдача «создание анкеты»). 
Другие хэндлеры (лайки/дизлайки, выдача анкет и т.п.) можно добавлять аналогично в `UpdateDispatcher` и через новые классы-хэндлеры.
