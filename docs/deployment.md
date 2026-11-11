# Развёртывание и эксплуатация

Нужны только Docker, Docker Compose, `make`, `curl` и `openssl`. PHP и Node на хосте не требуются: всё работает в контейнерах (php-fpm, nginx, MariaDB).

## Первый запуск

```sh
make init       # .env со случайными паролями БД (режим 600); не перезаписывает существующий
make up         # сборка и запуск; nginx слушает только 127.0.0.1:3000
make install    # composer install и npm ci внутри контейнеров
docker compose restart php   # миграции и демо-данные (при первом запуске vendor/ ещё не было)
make build      # сборка фронтенда в frontend/dist
```

Проверка: `curl -fsS http://127.0.0.1:3000/api/health` отвечает `{"status":"ok"}`.

Миграции и демо-данные применяются при старте php-контейнера, но только в `APP_ENV=demo` и только в пустую БД.

## Учётные записи

Аккаунты созданы без пароля, войти нельзя, пока не выпущены учётные данные:

```sh
docker compose exec php composer demo:credentials:rotate
```

- Вывод: строки `логин<TAB>пароль`. Пароли случайные, от 20 символов, показываются один раз и нигде не хранятся.
- Каждый запуск выпускает новые пароли и завершает все сессии.
- Команда работает только из CLI и только при `APP_ENV=demo`. Не добавляйте `-e APP_ENV=demo`.
- Смены пароля в интерфейсе нет: ротация — единственный способ.
- Для входа по обычному HTTP (локально) задайте `SESSION_COOKIE_SECURE=0` в `.env`.

## Команды

| Команда | Действие |
|---|---|
| `make check` | Все проверки: конфиги, `php -l`, CS Fixer, PHPStan, PHPUnit, ESLint, Prettier, Stylelint, `tsc`, i18n, Jest |
| `make e2e CONFIRM=yes` | **Разрушающая.** Сбрасывает демо-данные, **меняет пароли всех аккаунтов**, запускает Playwright |
| `make demo-reset` | Заменяет задачи демо-сценарием, не трогает пользователей и сессии |
| `make down` / `make down ARGS=-v` | Остановить / остановить и удалить БД и загрузки |
| `make db-shell` | Консоль MariaDB |
| `make help` | Все цели |

Не запускайте `make e2e` на демо, которым пользуются проверяющие.

## За reverse proxy

Контейнерный nginx слушает `127.0.0.1:3000`. Внешний прокси (TLS) должен:

- передавать `Host`, `X-Forwarded-Proto` и **перезаписывать** `X-Forwarded-For` на `$remote_addr`, а не дополнять его: иначе клиент подделает IP, а лимит входов будет общим для всех;
- ставить `client_max_body_size 1m` везде и ~110m только для `location = /api/files` (вместе с `proxy_request_buffering off` и увеличенными таймаутами);
- не сжимать JSON-ответы: в них CSRF-токен (атака BREACH).

Приложение доверяет `X-Forwarded-*` только из сети Docker (`TRUSTED_PROXIES=172.16.0.0/12`), подсеть проекта закреплена в `docker-compose.yml`.

## Загрузки и резервные копии

- Лимит файла задаёт `MAX_UPLOAD_BYTES` (99 МБ). При смене поднимите `client_max_body_size` в обоих nginx (лимит + 10 МБ).
- Состояние лежит в двух томах: `creative_db_data` и `creative_uploads`. Копия БД: `docker compose exec db sh -c 'exec mariadb-dump --single-transaction "$MARIADB_DATABASE"' > backup.sql` (внутри хеши паролей).
- Общей квоты на загрузки нет. Размер тома: `docker compose exec php du -sh /var/uploads`.
