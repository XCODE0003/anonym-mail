# Промт для Claude Code: Анонимный email-сервис на PHP (функциональный клон cock.li с собственным дизайном)

> Инструкция для заказчика: скопируй всё ниже и отправь Claude Code целиком. В начале добавь: `Прочитай ТЗ полностью. НЕ пиши код, пока не задашь все уточняющие вопросы из §12. Работай итеративно — после каждого из шагов §10 жди моего "ok".`
>
> Референс-клон (изучи структуру перед стартом): `/Users/nikita/Documents/projects/anonym-mail-service/` — это HTML-слепок cock.li, на котором видно все страницы, формы, поля, навигацию и тексты. Функциональность должна быть **один-в-один**, но дизайн — **полностью свой**, минималистичный и современный.

---

## 0. Контекст и цели

Строим **self-hosted анонимный email-хостинг** по образцу cock.li: приватные ящики с мультидоменом, без KYC, без телефона, без email recovery, работает в clearnet и Tor. Стек — **PHP 8.3 + Postfix + Dovecot + Rspamd + PostgreSQL + nginx**.

**Железные принципы:**

- **NO JS** на публичных и кабинетных страницах. 100% HTML + CSS + серверный рендер. Никаких fetch/XHR/wasm/alpine. Допускается единственный инлайн-`<script>` как в оригинале — и только если он **опционален** (сайт работает полностью без него).
- **NO LOGS** — IP/UA/Referrer не сохраняются в БД и не пишутся в файлы. nginx `access_log off`, Postfix/Dovecot настроены на подавление метаданных клиента. (Допускается кратковременный in-memory счётчик для fail2ban, без persist.)
- **Мультидомен** — пользователь выбирает домен при регистрации. Домены конфигурятся через админку.
- **Honey-pot + CSRF double-token + server-side image CAPTCHA** — как в референсе.
- **Warrant canary + Transparency archive** — как в референсе.
- **Параллельный .onion** (v3) — раздельные hidden service'ы для www и для mail (SMTP/IMAP/POP).
- Полная установка по `make` на чистой Ubuntu 24.04 VPS за ≤30 минут.

---

## 1. Страницы публичного сайта (паритет с референсом)

Сохраняем **тот же набор страниц и те же поля форм**, что в клоне в `/Users/nikita/Documents/projects/anonym-mail-service/`, но с новым дизайном и без доменов cock.li (домены — из нашей БД).

| Маршрут | Файл | Содержимое |
|---|---|---|
| `/` | `index.php` | Лендинг: hero, announcement-блок (из БД), "How can I trust you?" (editable в админке), Server Info (IMAP/POP/SMTP хосты, onion-адреса), ссылки |
| `/register.php` | `register.php` | Форма регистрации |
| `/login` | `login.php` | Редирект/линк на webmail, + форма для входа в «кабинет настроек» |
| `/changepass.php` | `changepass.php` | Смена пароля (email, old_password, password, password_again, CAPTCHA) |
| `/delete.php` | `delete.php` | Удаление аккаунта (email, password, CAPTCHA, `delete_confirm` чекбокс) |
| `/unblock.php` | `unblock.php` | Разблокировка SMTP (см. §3) |
| `/contact.php` | `contact.php` | Список `official-*@domain` адресов + GPG-ключи |
| `/abuse.php` | `abuse.php` | Инструкции по репорту абьюза |
| `/terms.php` | `terms.php` | TOS (редактируется в админке, markdown) |
| `/privacy.php` | `privacy.php` | Privacy Policy (редактируется в админке) |
| `/canary.asc.txt` | статический | PGP-signed warrant canary, обновляется админом |
| `/log.txt` | статический | Changelog проекта |
| `/transparency/` | `transparency/index.php` | Листинг папок с повестками (директория на диске, админ кладёт PDF/скан) |
| `/gpg/master.asc.txt` и `/gpg/*.asc.txt` | статика | PGP-ключи команды |
| `/webmail` | редирект | На `mail.<domain>` (см. §4) |

**Общая навигация** (на каждой странице): Home, Webmail, Contact, Unblock SMTP, Change Password, Register. **Футер** (на каждой странице): Site Log, Warrant Canary, Transparency, Terms, Privacy, Report Abuse.

---

## 2. Форма регистрации — точная спецификация

Берём поля **ровно как в референсе** (`register.php.html`):

```html
<form method="POST" action="/register.php">
  <input type="hidden" name="csrf"       value="{csrf_token}">
  <input type="hidden" name="csrf_valid" value="{csrf_token}">  <!-- двойной токен -->

  <!-- username -->
  <input type="text" name="username" required>
  @
  <select name="domain" required>
    {foreach $domains}<option>{$name}</option>{/foreach}
  </select>

  <!-- пароль -->
  <input type="password" name="password" required>
  <!-- HONEY-POT: поле "password_confirm" скрыто через CSS (width:0;height:0;opacity:0);
       боты его заполняют — отклоняем регистрацию -->
  <input type="password" name="password_confirm">
  <!-- настоящий второй пароль называется password_confinm (именно с опечаткой, как в референсе) -->
  <input type="password" name="password_confinm" required>

  <!-- CAPTCHA -->
  <input type="hidden" name="captcha_key" value="{captcha_key}">
  <img src="/captcha.php?k={captcha_key}" alt="captcha">
  <input type="text" name="captcha_solution" required>

  <!-- согласия -->
  <label><input type="checkbox" name="tos_agree" required> I agree to TOS</label>
  <label><input type="checkbox" name="news_subscribe"> Subscribe to news</label>

  <button type="submit">Register</button>
</form>
```

**Серверная логика:**
1. Сверка `csrf == csrf_valid == session['csrf']`, иначе 400.
2. Если `password_confirm` не пустой → silent 200 «OK» (honey-pot, боту не показываем что его поймали).
3. `captcha_key` → ищем в Redis (TTL 10 мин) правильный ответ, сверяем `captcha_solution` case-insensitive, удаляем ключ.
4. `username` — regex `^[a-z0-9._-]{3,32}$`, не в `reserved_names` (postmaster, admin, abuse, root, support, hostmaster, webmaster, noreply, official, official-*, mailer-daemon, nobody).
5. `domain` — существует в `domains` и `active=true` и `allow_registration=true`.
6. `password` — минимум 10 символов, совпадает с `password_confinm`.
7. `tos_agree` — обязательный.
8. Проверка уникальности `(local_part, domain_id)`.
9. Хэш `{ARGON2ID}` — Dovecot-совместимый, через `password_hash(..., PASSWORD_ARGON2ID, ['memory_cost'=>65536,'time_cost'=>3,'threads'=>2])`.
10. INSERT в `users`. Создание maildir делает Dovecot lazily при первом логине.
11. Новый пользователь получает `smtp_blocked=true` (см. §3).
12. Страница «Аккаунт создан» с напоминанием IMAP/SMTP-настроек и что пароль не восстанавливается.

**В БД НЕ сохраняем:** IP, User-Agent, Referrer, Accept-Language, timestamps с точностью выше даты. Поле `created_at` — только `DATE` (не timestamptz).

---

## 3. Разблокировка SMTP (unblock.php) — ключевая фича референса

Цитата из референса: «cock.li blocks new accounts from sending mail until they allow their browser to complete a proof-of-work challenge which takes a few minutes of CPU time.»

**Реализация на PHP (без JS):**

1. Новый аккаунт имеет `smtp_blocked=true`. Postfix проверяет этот флаг через `check_policy_service` и отказывает 550 с текстом «unblock your SMTP at https://<domain>/unblock.php».
2. Страница `/unblock.php` имеет **две формы**:
   - **POST**: `email` + `password` → проверяет логин, генерирует **Argon2-proof-of-work challenge** (нужно найти nonce такой, что `argon2id(seed||nonce, salt)` начинается с N нулевых бит). Сложность задаётся админкой (по умолчанию 22 бита ≈ 1-3 минуты CPU). Challenge сохраняется в Redis с TTL 30 мин, юзеру показывается страница с челленджем и полем для ввода решения.
   - **GET**: `?email=...&unblock_code=...` — юзер вводит готовый unblock code (который он получил после решения PoW), сервер снимает `smtp_blocked`.
3. Поскольку **JS запрещён**, юзеру выдаётся скачиваемый CLI-скрипт (`unblock-solver.sh`, shell + openssl) и инструкция: «запусти на своём компе, получи код, вставь его в поле». Это и есть «browser to complete proof-of-work» из референса, но без браузерного JS.
4. Rate-limit на `/unblock.php` — 5 попыток/час на `email` (счётчик в Redis, без IP).

---

## 4. Почтовый стек

### 4.1. Postfix
- Virtual mailboxes через **Postgres lookup**: `virtual_mailbox_domains`, `virtual_mailbox_maps`, `virtual_alias_maps`.
- SMTP submission: 465 (implicit TLS), 587 (STARTTLS). Обязательный SASL через Dovecot.
- Порт 25: приём почты, STARTTLS optional.
- **Privacy header_checks** (обязательно):
  ```
  /^Received:/              IGNORE
  /^User-Agent:/            IGNORE
  /^X-Originating-IP:/      IGNORE
  /^X-Mailer:/              IGNORE
  /^X-Forwarded-For:/       IGNORE
  /^X-Source-IP:/           IGNORE
  /^Authentication-Results:/ IGNORE
  ```
- `smtpd_banner = $myhostname ESMTP` (без версии).
- `smtp_tls_security_level = may`, `smtpd_tls_security_level = may` (входящий TLS не форсим — иначе потеряем письма).
- Policy service на PHP/Python для проверки `smtp_blocked` и rate-limit на отправку.
- `maillog_file = /dev/null` (или syslog + drop-в-null фильтр).

### 4.2. Dovecot
- Auth через Postgres (`passdb sql`, `userdb sql`).
- Формат пароля: `{ARGON2ID}$argon2id$v=19$...`.
- Scheme: `ARGON2ID`.
- IMAPS 993, POP3S 995, managesieve 4190 (для фильтров).
- Quota plugin: `quota = maildir:User quota`, default 1 GB (из колонки `users.quota_bytes`).
- `auth_verbose=no`, `auth_debug=no`, `mail_debug=no`, `log_path=/dev/null`.
- Sieve для фильтров и vacation-autoresponder.

### 4.3. Rspamd
- Вход/выход фильтрация спама.
- DKIM signing на submission — ключи из таблицы `dkim_keys` (селектор поднимается из БД динамически).
- Redis как backend.

### 4.4. Webmail
- Отдельный поддомен `mail.<primary-domain>`.
- **Два варианта** — Claude Code пусть предложит, я выберу:
  - **(A)** Форк **Roundcube 1.6** с кастомным skin под наш дизайн, `assets_dir` только локально, **jQuery оставляем минимально** (Roundcube фактически требует JS — но функционирует и с отключённым; проверим fallback). Это даёт полноценный webmail.
  - **(B)** Написать собственный **plain-HTML webmail на PHP** (IMAP-клиент через `php-imap`), полностью без JS, минимум функций: список папок, inbox, читать, написать, ответить, переслать, аттачменты, поиск, папки. Похоже на `cock-mail` из референса, но серверный. Это честный NO-JS, но больше работы.
- Предпочтение: **(B)** для полного NO-JS паритета. Название: `<brand>-mail`.
- Санитизация HTML-писем: `ezyang/htmlpurifier` strict preset, внешние картинки заменяются на placeholder с линком «Load image from external server» (который ведёт на server-side proxy с `?url=...&sig=HMAC`, чтобы нельзя было подменить).

### 4.5. XMPP (опционально — см. §12)
- Prosody с auth через Postgres (тот же `users`).
- Домены `xmpp.<domain>`.
- Включается флагом в `.env`.

---

## 5. Админ-панель

Отдельный host `admin.<domain>` (или path `/admin` за HTTP Basic). Защита:
- TLS client certificate (mTLS) — **обязательно**.
- Плюс Basic Auth.
- IP allowlist в nginx (админ задаёт в `.env`).
- 2FA (TOTP) на PHP-логине.

Все действия логируются в `admin_audit` (без IP — только admin username, action, target, дата без времени суток).

**Разделы:**

1. **Dashboard**
   - Кол-во аккаунтов (всего / по доменам).
   - Активных DKIM-ключей.
   - Заполненность диска maildir.
   - Аптайм postfix/dovecot/rspamd/nginx.
   - Текущая CAPTCHA-сложность, PoW-сложность.
   - Все метрики — **агрегаты без персональных данных**.

2. **Domains**
   - Список: `name`, `active`, `allow_registration`, `mx_configured` (auto-check).
   - Добавить: форма `name` + чекбоксы + кнопка «Показать DNS-записи» (генерирует MX/SPF/DMARC/DKIM/autoconfig).
   - Удалить (только если 0 пользователей).
   - Деактивация новых регистраций.

3. **Users**
   - Поиск `local_part@domain`.
   - Просмотр карточки: адрес, квота, `smtp_blocked`, `frozen`, дата регистрации. **Без доступа к содержимому писем.**
   - Заморозить/разморозить.
   - Сбросить пароль (генерит временный `xxxx-xxxx-xxxx`, показывает ОДИН раз).
   - Удалить ящик (с подтверждением + 30-дневной отложенной очисткой, как в референсе privacy.php).
   - Ручной unblock SMTP.

4. **Reserved Names**
   - Таблица зарезервированных local_part.
   - Add/Remove.

5. **DKIM Keys**
   - По каждому домену: селектор, дата создания, active.
   - Кнопка «Rotate»: генерит новый ключ с новым селектором (`mailYYYYMM`), показывает DNS TXT, старый активен ещё 7 дней.

6. **Announcements**
   - Текст баннера на лендинге (markdown), active true/false.

7. **TOS / Privacy / How can I trust you / Abuse / Contact**
   - Markdown-редакторы (plain `<textarea>`, без JS).

8. **Warrant Canary**
   - Поле для вставки PGP-подписанного текста, обновляется раз в квартал.
   - Проверка GPG-подписи через `gpg --verify` (предупреждение если не проверяется).

9. **Transparency**
   - Загрузка папки со сканами повесток (имя папки `YYYY-MM-DD-<slug>`), листинг публикуется на `/transparency/`.

10. **Abuse Queue** (опционально)
    - Прием писем, поступающих на `abuse@<domain>`, в специальный read-only ящик, просмотр из админки (без редактирования, без ответа).

11. **Unblock / CAPTCHA Controls**
    - PoW сложность (bits).
    - CAPTCHA: включить/выключить/сложность.
    - Ручная блокировка/разблокировка домена для регистрации.

12. **System Log**
    - Только **агрегаты**: «за последние 24ч: регистраций 42, SMTP-коннектов 1231, отказов 12, спам-блоков 88». Без IP и адресов.

13. **Admin Accounts**
    - Добавить админа, сбросить TOTP, audit log.

---

## 6. База данных (PostgreSQL 16)

```sql
CREATE EXTENSION IF NOT EXISTS citext;

CREATE TABLE domains (
  id                 SERIAL PRIMARY KEY,
  name               CITEXT UNIQUE NOT NULL,
  active             BOOLEAN NOT NULL DEFAULT true,
  allow_registration BOOLEAN NOT NULL DEFAULT true,
  created_at         DATE NOT NULL DEFAULT CURRENT_DATE
);

CREATE TABLE users (
  id             BIGSERIAL PRIMARY KEY,
  local_part     CITEXT NOT NULL,
  domain_id      INT NOT NULL REFERENCES domains(id) ON DELETE RESTRICT,
  password_hash  TEXT NOT NULL,
  quota_bytes    BIGINT NOT NULL DEFAULT 1073741824,
  smtp_blocked   BOOLEAN NOT NULL DEFAULT true,
  frozen         BOOLEAN NOT NULL DEFAULT false,
  delete_after   DATE,                                 -- для отложенного удаления (30 дней)
  created_at     DATE NOT NULL DEFAULT CURRENT_DATE,
  UNIQUE (local_part, domain_id)
);

CREATE INDEX users_email_lookup ON users(local_part, domain_id);

CREATE TABLE reserved_names (
  local_part CITEXT PRIMARY KEY
);

-- Предзаполнить: postmaster, admin, abuse, root, support, hostmaster,
-- webmaster, noreply, mailer-daemon, nobody, ssl-admin, official,
-- 'official-%' (wildcard через триггер)

CREATE TABLE admin_users (
  username       TEXT PRIMARY KEY,
  password_hash  TEXT NOT NULL,
  totp_secret    TEXT NOT NULL,
  created_at     DATE NOT NULL DEFAULT CURRENT_DATE
);

CREATE TABLE admin_audit (
  id              BIGSERIAL PRIMARY KEY,
  admin_username  TEXT NOT NULL,
  action          TEXT NOT NULL,
  target          TEXT,
  at              DATE NOT NULL DEFAULT CURRENT_DATE    -- только дата, без часов
);

CREATE TABLE announcements (
  id          SERIAL PRIMARY KEY,
  body        TEXT NOT NULL,
  active      BOOLEAN NOT NULL DEFAULT true,
  created_at  DATE NOT NULL DEFAULT CURRENT_DATE
);

CREATE TABLE dkim_keys (
  id           SERIAL PRIMARY KEY,
  domain_id    INT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  selector     TEXT NOT NULL,
  private_key  TEXT NOT NULL,
  active       BOOLEAN NOT NULL DEFAULT true,
  created_at   DATE NOT NULL DEFAULT CURRENT_DATE
);

CREATE TABLE content_blocks (
  key        TEXT PRIMARY KEY,    -- 'tos', 'privacy', 'trust', 'abuse', 'contact', 'trust_question'
  body_md    TEXT NOT NULL,
  updated_at DATE NOT NULL DEFAULT CURRENT_DATE
);

CREATE TABLE canary (
  id             SERIAL PRIMARY KEY,
  body_signed    TEXT NOT NULL,   -- PGP-signed plaintext
  published_at   DATE NOT NULL DEFAULT CURRENT_DATE
);
```

---

## 7. Дизайн — минималистичный и современный

**НЕ копируем** красный-белый cock.li-дизайн. Делаем **свой**, с претензией на 2025.

### Принципы
- Типографика: системные шрифты (`-apple-system, Segoe UI, sans-serif`). Монопрайс: `ui-monospace, SF Mono, Menlo, monospace`.
- Темы: **Dark (default) + Light**, через `prefers-color-scheme` + форма-переключатель (cookie, не localStorage).
- Палитра dark:
  - BG `#0a0a0a`, Surface `#141414`, Border `#1f1f1f`
  - Text `#ededed`, Muted `#8f8f8f`
  - Accent `#9ae66e` (spring green)
  - Danger `#ff6b6b`
- Палитра light: инверсия, accent `#2a7a2a`.
- Радиусы 8px. Без теней. Border 1px.
- Анимации: только `transition: 80ms ease` на hover.
- Ширина контента: 720px (формы/текст), 1100px (webmail, админка).
- Все иконки — **inline SVG**, локально (Lucide, под MIT).
- Никаких Google Fonts, CDN, трекеров.

### Страницы — макет
- **Hero** лендинга: большой логотип/wordmark, slogan в 1-2 строки, 3 буллета («NO JS · NO LOGS · .onion»), кнопка `Register`, кнопка `Webmail`.
- **Announcement**: узкая полоса под хедером, фон accent.
- **Формы**: одна колонка 480px, label сверху, инпут ниже, ошибки красным под инпутом, submit — primary button full-width.
- **Webmail**: three-pane (folders / messages / content) или two-pane для мобилы. Server-rendered, без JS.
- **Админка**: sidebar-nav слева, контент справа.

### HTML-правила
- Семантика: `<main>`, `<nav>`, `<article>`, `<form>`.
- `<label for=...>` на каждом инпуте.
- Фокус-outline 2px accent.
- Все страницы <100kb без картинок.
- Работает с отключёнными картинками.

### CSS-архитектура
- Один `site.css` (≤15kb gzipped) — на лендинг, формы, контент.
- Отдельный `webmail.css` — для webmail.
- Отдельный `admin.css` — для админки.
- Без Tailwind, без препроцессоров. Чистый CSS с CSS-переменными.

---

## 8. Безопасность и privacy-харденинг

### HTTP заголовки (nginx)
```
Strict-Transport-Security: max-age=63072000; includeSubDomains; preload
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'
# Если полностью обходимся без JS — script-src 'none'
Referrer-Policy: no-referrer
Permissions-Policy: interest-cohort=(), geolocation=(), camera=(), microphone=()
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
```

### nginx
- `access_log off;` глобально, на всех vhost.
- `error_log /var/log/nginx/error.log crit;` — без `$remote_addr`.
- Логформат переопределён: `log_format privacy '$time_iso8601 $status $request_method';` (без IP/UA).
- gzip включён.
- Brotli — по желанию.

### PHP
- PHP-FPM, `opcache` on.
- Сессии — в Redis (`session.save_handler=redis`), `session.cookie_secure=1`, `session.cookie_httponly=1`, `session.cookie_samesite=Strict`.
- Composer, но **минимум зависимостей**: `ezyang/htmlpurifier`, `robthree/twofactorauth` (TOTP), `phpmailer` (или нативный `mail()`), `phpgangsta/googleauthenticator`.
- Никаких тяжёлых фреймворков — **чистый PHP с роутером 50 строк**, или **Slim 4**. По умолчанию — Slim 4.

### CSRF
- Двойной токен (`csrf` и `csrf_valid`) — как в референсе.
- В сессии хранится `csrf_token`, генерится `random_bytes(16)` → hex.
- Проверка: `hash_equals($_SESSION['csrf'], $_POST['csrf']) && $_POST['csrf'] === $_POST['csrf_valid']`.

### CAPTCHA
- Серверная, PNG рендерится через GD:
  - 5-6 символов `[a-z0-9]` без неоднозначных (0/o, 1/l, i).
  - Жирный, с шумом, распределёнными линиями.
  - В Redis `captcha:<key> = <answer>` TTL 10 мин.
  - Ключ генерится при GET-е формы и кладётся в hidden input.

### Honey-pot
- Поле `password_confirm` в форме регистрации (и других формах при желании) — скрыто CSS'ом. Если заполнено — silent drop с фейковым «OK».

### fail2ban
- Jails: nginx-forbidden, postfix-sasl, dovecot.
- `findtime=600 bantime=3600 maxretry=5`.
- **ban action = iptables DROP без записи IP в persistent log**. Источник — текущая сессия syslog, файлы не копим.

### DNS
- Локальный **unbound** слушает 127.0.0.1:53. Postfix и остальное резолвят через него.
- DNSSEC включён.
- Recursive, без DoH upstream.

### Tor Hidden Service
- Контейнер `tor` с двумя HiddenServiceDir:
  - `www.onion` → проксирует на nginx (80/443) с www-vhost.
  - `mail.onion` → проксирует на Postfix 465 + Dovecot 993 + Prosody (если XMPP).
- Onion v3, stealth auth — **выключен** (публичный сервис).
- Для `.onion` — либо self-signed (и инструкция для клиентов с отпечатком), либо **Harica DV** (платно — опциональный шаг в `make tor-cert`).
- Раздельные vhost'ы в nginx для clearnet и onion — **не редиректить** между ними.

### Log purge
- Cron: раз в час `truncate /var/log/nginx/error.log`, `truncate /var/log/mail.log`, если там есть что-то кроме уровня ERROR. (Альтернатива: rotate с удалением без архивации.)

---

## 9. DNS / DKIM / SPF / DMARC / MTA-STS / PTR

Для каждого домена в БД генерятся записи (админка показывает готовый блок):

```
@        A     <ip>
@        AAAA  <ipv6>
@        MX 10 mail.<domain>
mail     A     <ip>
mail     AAAA  <ipv6>

@         TXT "v=spf1 mx -all"
_dmarc    TXT "v=DMARC1; p=reject; rua=mailto:dmarc@<domain>; adkim=s; aspf=s"
<sel>._domainkey TXT "v=DKIM1; k=rsa; p=<pubkey>"
_mta-sts  TXT "v=STSv1; id=<epoch>"
mta-sts   CNAME mta-sts.<primary>     # опционально

_smtp._tls TXT "v=TLSRPTv1; rua=mailto:tlsrpt@<domain>"
```

PTR настраивается у хостера (инструкция в `docs/DNS.md`).

---

## 10. Шаги для Claude Code

Выполняй итеративно, **после каждого шага — коммит и ждёшь `ok`**.

0. **Прочитай ТЗ полностью.** Прочитай все файлы в `/Users/nikita/Documents/projects/anonym-mail-service/` чтобы понять референсный UX/формы. Задай все вопросы из §12 одним списком.

1. **Репозиторий и скелет.** `git init`, structure ниже, `.gitignore`, `README.md` с оглавлением, `.env.example`, пустой `docker-compose.yml`.

2. **База.** Миграции PostgreSQL (см. §6). Seeder `reserved_names`. Скрипт `make init-db`.

3. **PHP-приложение — ядро.** Slim 4 router, DI-контейнер, Redis для сессий, шаблонизатор (Twig), базовый layout + minimal CSS по §7.

4. **Формы и страницы.** Реализовать всё из §1 и §2: register/login/changepass/delete/unblock/contact/abuse/terms/privacy/transparency/canary/log/index. CSRF + honey-pot + CAPTCHA (GD PNG).

5. **PoW-unblock.** §3 полностью. CLI-скрипт `unblock-solver.sh`. Redis challenge store. Postfix policy-сервис на PHP (через unix socket) для проверки `smtp_blocked` и rate-limit.

6. **Postfix + Dovecot + Postgres.** Конфиги в `config/postfix/*`, `config/dovecot/*`. Virtual users из БД. ARGON2ID passdb. header_checks по §4.1. Логи в `/dev/null` или фильтр.

7. **Rspamd + DKIM.** Signing из `dkim_keys`. Ротация `scripts/rotate-dkim.sh`.

8. **Webmail (B-вариант — собственный PHP NO-JS webmail).** Модули: auth (IMAP-login), folders, inbox, read, compose, reply, forward, search, settings, logout. HTML-санитайзер для HTML-писем. External image proxy.

9. **Админ-панель.** Все разделы §5. mTLS + Basic + TOTP. Audit log.

10. **nginx + TLS.** Vhosts: `www.<domain>`, `mail.<domain>`, `admin.<domain>`, onion-vhosts. HSTS/CSP/headers. `acme.sh` DNS-01 wildcard.

11. **Tor hidden service.** Контейнер, сервисы, инструкция в `docs/TOR.md`.

12. **Scripts + Makefile.** `make init`, `make up`, `make tls`, `make tor`, `make admin`, `make healthcheck`, `make test`, `make backup` (опционально).

13. **Тесты.**
    - PHPUnit на формы, CSRF, honey-pot, CAPTCHA, валидаторы.
    - Интеграционный тест через `docker-compose.test.yml`: регистрация → IMAP login → SMTP unblock → отправка письма в mock-receiver → приём входящего.
    - `tests/privacy.sh` — скрипт проверки что в логах **нет IP** после 20 регистраций.
    - `testssl.sh` target = A+.
    - `mail-tester` — ≥9/10 (ручная проверка в README).

14. **Документация.** `docs/DEPLOY.md`, `docs/DNS.md`, `docs/TOR.md`, `docs/ADMIN.md`, `docs/SECURITY.md`, `docs/FAQ.md`.

15. **Финальная приёмка** по §11.

### Структура репозитория

```
mailservice/
├── README.md
├── Makefile
├── docker-compose.yml
├── docker-compose.test.yml
├── .env.example
├── .gitignore
├── app/
│   ├── composer.json
│   ├── public/             # document root: index.php, captcha.php, assets/
│   │   ├── index.php
│   │   ├── register.php
│   │   ├── changepass.php
│   │   ├── delete.php
│   │   ├── unblock.php
│   │   ├── contact.php
│   │   ├── abuse.php
│   │   ├── terms.php
│   │   ├── privacy.php
│   │   ├── captcha.php
│   │   ├── transparency/index.php
│   │   └── assets/{css,img,fonts}
│   ├── src/
│   │   ├── Http/           # middleware, router
│   │   ├── Domain/         # entities, services
│   │   ├── Auth/
│   │   ├── Captcha/
│   │   ├── Pow/
│   │   ├── Admin/
│   │   └── Webmail/
│   ├── templates/          # twig
│   └── migrations/
├── webmail/                # если отдельный vhost — сюда
├── admin/                  # отдельный document root админки
├── config/
│   ├── postfix/
│   ├── dovecot/
│   ├── rspamd/
│   ├── nginx/
│   ├── tor/
│   ├── unbound/
│   └── php-fpm/
├── scripts/
│   ├── init-db.sh
│   ├── generate-dkim.sh
│   ├── rotate-dkim.sh
│   ├── renew-tls.sh
│   ├── purge-logs.sh
│   ├── unblock-solver.sh
│   └── healthcheck.sh
├── tests/
│   ├── phpunit/
│   ├── integration/
│   └── privacy.sh
└── docs/
```

### Требования к коду
- PHP 8.3 strict types во всех файлах (`declare(strict_types=1);`).
- PSR-12.
- PHPStan level 8 без warnings.
- Psalm (опционально).
- `phpcs`/`phpcbf`.
- Все секреты — только в `.env`, **не** в репозитории.
- Никаких inline-скриптов в шаблонах, кроме опционального блока из референса.
- Коммиты conventional: `feat:`, `fix:`, `docs:`, `test:`, `chore:`.

---

## 11. Приёмка

Готово, когда:

- ✅ На чистой Ubuntu 24.04 VPS ставится за ≤30 мин по `make all`.
- ✅ Все страницы §1 работают, формы принимают данные через `curl` / `lynx`, **JS отключён в браузере — всё работает**.
- ✅ В `/var/log/**` и `docker logs` после 20 регистраций и 20 SMTP-сессий **нет IP** клиентов.
- ✅ `testssl.sh` → A+ на 443/465/993.
- ✅ mail-tester.org → ≥ 9/10.
- ✅ Onion-версия функционально идентична clearnet (регистрация, логин, webmail, IMAP, SMTP).
- ✅ Админ добавляет новый домен за ≤3 клика + DNS, показывается готовый DNS-блок.
- ✅ Warrant canary публикуется и валидируется `gpg --verify`.
- ✅ Unblock-PoW challenge решается `unblock-solver.sh` без JS.
- ✅ Honey-pot ловит бота (тест через `curl -F password_confirm=x`).
- ✅ Все 15 пунктов `docs/SECURITY.md` — зелёные.

---

## 12. Открытые вопросы (ответь ДО кода)

1. **Домены на старте** — сколько и какие? (нужен список — я его подставлю в seeder)
2. **Название бренда** (для футера, wordmark, `<brand>-mail`)?
3. **VPS-провайдер и IP** — будешь хостить сам, или нужен рекомендации (Njalla / 1984 / BuyVM / Hetzner)?
4. **Квота** по умолчанию — 1GB / 5GB / unlimited?
5. **Webmail** — вариант (A) Roundcube кастомизированный или (B) собственный PHP NO-JS webmail? Рекомендую (B).
6. **XMPP** — включаем Prosody или только email?
7. **Язык интерфейса** — EN, RU, оба? i18n-ready?
8. **Warrant canary** — свой PGP-мастер-ключ дашь или генерировать новый?
9. **Transparency-архив** — публиковать пустым или с заглушкой «No orders received»?
10. **Tor onion-домен** — один (www+mail на одном) или два раздельных (как в референсе)?
11. **Регистрация входящих `+tag` адресов** (`user+anything@domain`) — разрешать?
12. **Catch-all ящики** для отдельных пользователей — нужно?
13. **Автоудаление старых писем** — политика (бесконечно / N дней / выбор пользователя)?
14. **Отложенное удаление аккаунта** — 30 дней как в референсе или сразу?
15. **Максимальный размер аттачмента** — 25MB / 50MB / 100MB?

---

## 13. Чего НЕ делать

- Никаких платежей, крипто-шлюзов, донат-страниц с суммами. (Статическая страница `/donate` допустима **только** если админ вручную впишет свои адреса в content block — по умолчанию страница скрыта.)
- Никакой аналитики, пикселей, Google, Cloudflare, Recaptcha.
- Никаких JS-фреймворков / SPA.
- Никаких email-recovery / SMS / OAuth.
- Никаких внешних шрифтов / картинок / CDN.
- Не хранить IP / UA / Referrer нигде.
- Не форкать cock.li код напрямую — пишем своё.

---

*Конец ТЗ. Если что-то неоднозначно — спроси списком, не додумывай. После §12 дождись ответов, затем начинай §10.1.*
