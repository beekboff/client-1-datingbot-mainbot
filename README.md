## Dating Bot: сервис обработки сообщений Telegram через RabbitMQ

Этот сервис принимает апдейты Telegram из RabbitMQ, обрабатывает команды/колбэки, хранит пользователей в MySQL и отправляет сообщения в Telegram через HTTP API.

### Требования
- PHP >= 8.2
- MySQL 8+ (или совместимый)
- RabbitMQ 3.12+
- Composer
- (опционально) Docker для запуска контейнера с PHP (см. docker/Dockerfile)

### Переменные окружения
Переменные можно задать через `.env` в корне проекта (загружается автоматически при старте) либо как реальные переменные окружения. Также часть значений можно переопределить в `config/common/params.php`.

- `TELEGRAM_BOT_TOKEN` — токен основного бота (с которым взаимодействуют пользователи)
- `TELEGRAM_BOT_TOKEN_LOG` (или `TELEGRAM_LOG_BOT_TOKEN`) — отдельный токен бота для отправки логов в Telegram
- `TELEGRAM_LOG_CHAT_ID` — ID чата/канала, куда слать ошибки/алерты (личный чат или группа/канал, где лог‑бот имеет право писать)
- `RABBITMQ_HOST` (по умолчанию 127.0.0.1)
- `RABBITMQ_PORT` (по умолчанию 5672)
- `RABBITMQ_USER` (по умолчанию guest)
- `RABBITMQ_PASS` (по умолчанию guest)
- `RABBITMQ_VHOST` (по умолчанию /)
- `DB_DSN` (пример: `mysql:host=127.0.0.1;dbname=dating_bot;charset=utf8mb4`)
- `DB_USER` (по умолчанию root)
- `DB_PASS` (по умолчанию пусто)
- `APP_ENV` (`dev`/`prod`)

URL для создания анкеты настраивается в `config/common/params.php` → `app.profileCreateUrl`.

### Установка
```bash
composer install
composer dump-autoload
```

### Миграции БД
Используется «стоковый» механизм Yii3 через консольное приложение (`./yii migrate:*`).

Настройка:
- Подключение к БД берётся из переменных окружения `DB_DSN`, `DB_USER`, `DB_PASS` либо из `config/common/params.php` → `db`.
- Пространство имён миграций — `App\Migrations` (см. `config/common/params.php` → `yiisoft/db-migration`).

Примеры команд:
```bash
# Экспортируйте переменные окружения при необходимости
export DB_DSN="mysql:host=127.0.0.1;dbname=dating_bot;charset=utf8mb4"
export DB_USER="root"
export DB_PASS=""

# Показать новые миграции
./yii migrate:new

# Применить миграции
./yii migrate:up

# Посмотреть историю
./yii migrate:history
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
Выполните (создаст обменник/очереди и т.д.):
```bash
./yii app:setup
```

Будут задекларированы:
- Очередь входящих апдейтов Telegram: `tg_got_data`
- Обменник `tg.direct`
- Очередь `tg.profile_prompt` (для отложенной отправки сообщения «Создайте анкету»)
- Очередь задержки `tg.profile_prompt.delay` с DLX на `tg.direct` и routing-key `tg.profile_prompt` (TTL используется на уровне сообщения)

### Запуск консьюмеров
Нужны два процесса:
1) Консьюмер входящих апдейтов из Telegram (из RabbitMQ):
```bash
./yii rabbit:consume-updates
```
2) Консьюмер отложенных уведомлений «создайте свою анкету»:
```bash
./yii rabbit:consume-profile-prompt
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
Сервис `TelegramApi` делает HTTP-запросы в Telegram Bot API напрямую. Базовый URL и токен берутся из env/params. 
Поддерживаются `sendMessage` и `sendPhoto`, inline-клавиатуры и внешние ссылки.

### Логирование ошибок в Telegram
- Цель `App\Infrastructure\Logging\TelegramLogTarget` отправляет записи в Telegram. По умолчанию подключены уровни: `error|critical|alert|emergency` (и могут включать `warning/notice`, см. код цели).
- Цель включается автоматически, если задан `TELEGRAM_LOG_CHAT_ID` (среда `APP_ENV` значения не ограничивает отправку).
- Для логов используется отдельный токен, если указан `TELEGRAM_BOT_TOKEN_LOG` (или `TELEGRAM_LOG_BOT_TOKEN`). Иначе используется основной `TELEGRAM_BOT_TOKEN`.
- Настройка:
  1) В `.env`:
     ```bash
     TELEGRAM_BOT_TOKEN=123456:ABCDEF              # основной бот
     TELEGRAM_BOT_TOKEN_LOG=987654:ZYXWV           # отдельный лог-бот (опционально)
     TELEGRAM_LOG_CHAT_ID=123456789                # чат для логов
     APP_ENV=prod                                  # опционально
     ```
  2) Убедитесь, что лог‑бот имеет право писать в указанный чат (для групп/каналов добавьте бота и выдайте права).
  3) Ошибки сервиса будут дублироваться в указанный чат. Для быстрого теста можно временно залогировать `logger->error('TEST')`.

### Локализация
- Файлы: `resources/i18n/en.php`, `ru.php`, `es.php`.
- Если язык пользователя не поддерживается, используется английский.

### Быстрый старт (локально)
1. Настройте `.env` (или экспортируйте переменные окружения): `TELEGRAM_BOT_TOKEN`, `DB_*`, `RABBITMQ_*`.
   - Для телеграм‑логов добавьте: `TELEGRAM_LOG_CHAT_ID` и, при наличии, `TELEGRAM_BOT_TOKEN_LOG`.
2. `composer install && composer dump-autoload`
3. `./yii migrate:up`
4. `./yii app:setup`
5. Запустите консьюмеры в отдельных терминалах:
   - `./yii rabbit:consume-updates`
   - `./yii rabbit:consume-profile-prompt`
6. Настройте ваш внешний вебхук Telegram, чтобы он клал апдейты в очередь `tg_got_data` в формате выше.

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
Чтобы таблица `telegram_processed_updates` не разрасталась бесконечно, предусмотрена команда очистки:
```bash
./yii tg:cleanup-processed-updates --days=2
```
По умолчанию хранится 2 суток (можно изменить через опцию `--days`).

Пример cron (каждую ночь в 03:30):
```
30 3 * * * /usr/bin/php /path/to/project/yii tg:cleanup-processed-updates --days=2 >> /var/log/tg_cleanup.log 2>&1
```

### Примечание
Реализация покроет текущие сценарии (/start, выбор предпочтений, отложенная выдача «создание анкеты»). 
Другие хэндлеры (лайки/дизлайки, выдача анкет и т.п.) можно добавлять аналогично в `UpdateDispatcher` и через новые классы-хэндлеры.
