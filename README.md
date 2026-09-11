# Accommodation Booking API

Мова: **Українська** | [English](docs/README.en.md)

REST API для асинхронного імпорту пропозицій житла від постачальників, пошуку найдешевшої актуальної пропозиції для кожного об’єкта та безпечного бронювання. Проєкт реалізовано як тестове завдання для Laravel Developer. Frontend не передбачений.

## Вимоги та середовище

- PHP 8.2+ з розширеннями, потрібними Composer-залежностям, `pdo_mysql` і `redis` (phpredis).
- Laravel; точна зафіксована версія — у `composer.lock`. Вимога ТЗ: Laravel 11 або 12. Перевірка встановленої версії: `php artisan --version`.
- Composer, Git, MySQL 8+ та доступний Redis Server.
- Docker Engine / Docker Desktop з Docker Compose для наведеного нижче запуску MySQL.

Перевірене середовище: Windows, PHP 8.2.12 з XAMPP, MySQL 8.4.11 у Docker та локальний Redis. PHP/Laravel і queue worker працюють на хості; контейнер використовується лише для MySQL. Apache та MariaDB із XAMPP для цього запуску не потрібні. Node.js не потрібен для перевірки API.

## Встановлення

Замініть `<REPOSITORY_URL>` URL цього репозиторію з кнопки **Code** на GitHub.

```bash
git clone <REPOSITORY_URL> laravel-accommodation-booking-api
cd laravel-accommodation-booking-api
composer install
```

Скопіюйте файл середовища **однією** командою відповідно до вашої оболонки: спочатку наведено PowerShell, потім Bash.

```powershell
Copy-Item .env.example .env
```

```bash
cp .env.example .env
```

У `.env` перевірте такі налаштування. Порожній `DB_PASSWORD` обов’язково заповніть власним локальним паролем **до першого запуску Compose**.

```dotenv
APP_NAME="Accommodation Booking API"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8002

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=accommodation_booking
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
REDIS_DB=0
REDIS_CACHE_DB=1
```

Redis має бути запущений і доступний за вказаною адресою. Якщо він потребує пароль, заповніть `REDIS_PASSWORD`. Файли `.env` та `.env.testing` не комітяться; приклади не містять паролів і ключів застосунку.

```bash
php artisan key:generate
php artisan config:clear
docker compose -f compose.mysql.yml up -d --wait
php artisan migrate --seed
```

`SupplierSeeder` створює `supplier-a` та `supplier-b`. `ExchangeRateSeeder` додає демонстраційні курси валют; точні значення дивіться у `database/seeders/ExchangeRateSeeder.php`. Сидери не створюють каталог житла: пропозиції надходять через API імпорту.

### Бази даних

| Призначення          | База                         | Адреса з хоста   |
| -------------------- | ---------------------------- | ---------------- |
| Локальний застосунок | `accommodation_booking`      | `127.0.0.1:3307` |
| Автотести            | `accommodation_booking_test` | `127.0.0.1:3307` |

`MYSQL_DATABASE` створює основну базу. `docker/mysql/init/01-create-testing-database.sql` створює тестову базу при першій ініціалізації на порожньому томі. Таблиці створюють міграції Laravel; SQL-файл після цього не змінюється. Дані зберігаються в іменованому Docker volume.

Якщо том існував до додавання init-файлу, створіть тестову базу вручну через MySQL-клієнт командою `CREATE DATABASE IF NOT EXISTS accommodation_booking_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`.

Зміна `DB_PASSWORD` у `.env` не змінює пароль уже ініціалізованого MySQL. Для власного MySQL 8+ можна пропустити Compose і налаштувати host, port та обидві бази самостійно. Якщо PHP запускається в контейнері в тій самій мережі, адресою БД буде ім’я сервісу `mysql`, порт — `3306`.

## Запуск

Термінал 1 — HTTP-сервер:

```bash
php artisan serve --host=127.0.0.1 --port=8002
```

Термінал 2 — queue worker:

```bash
php artisan queue:work redis --queue=imports --tries=3 --verbose
```

API доступне за адресою `http://127.0.0.1:8002/api`. Worker продовжує чекати нові завдання після `DONE`. Для зупинки сервера або worker натисніть `Ctrl+C`. Після зміни коду Job чи конфігурації перезапустіть worker. Зупинка MySQL: `docker compose -f compose.mysql.yml stop`; не видаляйте volume, якщо потрібно зберегти дані.

## API

Надсилайте `Accept: application/json`; для POST також `Content-Type: application/json`.
| Method | Endpoint | Success |
| --- | --- | --- |
| POST | `/api/imports` | `202 Accepted` |
| GET | `/api/imports/{import}` | `200 OK` |
| GET | `/api/properties` | `200 OK` |
| POST | `/api/offers/{offer}/reservations` | `201 Created`; identical retry: `200 OK` |

Помилки валідації повертають `422`, відсутній імпорт або пропозиція — `404`, конфлікт бронювання — `409`.

### Демонстрація через PowerShell

Виконуйте блоки послідовно в одному PowerShell-терміналі з кореня проєкту. Змінні не передаються між терміналами. Копіюйте лише код, без префіксів `PS ...>` і `>>`. Присвоєння змінній саме по собі нічого не виводить.

#### 1. Імпорт

Дати обчислюються від дня запуску. Запит створює демонстраційні дані в основній локальній базі.

```powershell
$baseUrl = "http://127.0.0.1:8002"
$headers = @{ Accept = "application/json" }
$runId = [guid]::NewGuid().ToString()
$propertyCode = "DEMO-$runId"
$checkIn = (Get-Date).AddDays(30).ToString("yyyy-MM-dd")
$checkOut = (Get-Date).AddDays(35).ToString("yyyy-MM-dd")
$payload = @{
    supplier = "supplier-a"
    external_import_id = "demo-$runId"
    sent_at = (Get-Date).ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ")
    offers = @(
        @{
            external_id = "offer-$runId"
            property = @{
                code = $propertyCode
                name = "Demo Apartment"
                city = "Barcelona"
            }
            check_in = $checkIn
            check_out = $checkOut
            max_guests = 4
            price = 100
            currency = "EUR"
            available_units = 2
            expires_at = (Get-Date).AddDays(7).ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ")
        }
    )
}
$importJson = $payload | ConvertTo-Json -Depth 10
$importResponse = Invoke-WebRequest -Uri "$baseUrl/api/imports" -Method Post -Headers $headers -ContentType "application/json" -Body $importJson -UseBasicParsing
$importResponse.StatusCode
$importResponse.Content
$importId = ($importResponse.Content | ConvertFrom-Json).data.id
```

Очікується `202` і `data.id`. Початковий статус — `pending`. Для спостереження за ним спочатку надішліть імпорт без запущеного worker, а потім запустіть worker в іншому терміналі. Відповідь може містити додаткові метадані.

#### 2. Статус

```powershell
Invoke-RestMethod -Uri "$baseUrl/api/imports/$importId" -Method Get -Headers $headers | ConvertTo-Json -Depth 10
```

Після завершення Job очікується `completed`, `total_offers: 1`, `processed_offers: 1`, `error: null` та заповнений `completed_at`. Endpoint повертає ID, slug постачальника, зовнішній ID імпорту, статус, лічильники й часові мітки. Вхідний payload і внутрішній текст винятку не розкриваються. Часові мітки серіалізуються в UTC; відповідь може містити дробову частину секунд.

#### 3. Пошук

```powershell
$searchUrl = "$baseUrl/api/properties?city=Barcelona&check_in=$checkIn&check_out=$checkOut&guests=2&per_page=100"
$search = Invoke-RestMethod -Uri $searchUrl -Headers $headers
$search | ConvertTo-Json -Depth 10
$property = $search.data | Where-Object { $_.code -eq $propertyCode } | Select-Object -First 1
$offerId = $property.best_offer.id
if (-not $offerId) { throw "Demo offer not found. Check import status, rates and pagination." }
```

Очікується `Demo Apartment` із `best_offer`: ціна `"100.00"`, валюта `EUR`, залишок `2`. Якщо об’єкт не на першій сторінці, перейдіть за `links.next`.

Обов’язкові параметри: `check_in`, `check_out` у форматі `YYYY-MM-DD` та `guests >= 1`; виїзд пізніше за в’їзд. `city` необов’язкове. Пагінація: `page` від 1, `per_page` від 1 до 100, типово 15. Відповідь містить `data`, `links.next`, `links.prev` і `meta.per_page`. Для пошуку в усіх містах приберіть `city`.

#### 4. Бронювання та повторний запит

```powershell
$reservationJson = @{
    client_reference = "order-$runId"
    customer_name = "Test Customer"
    customer_email = "test@example.com"
} | ConvertTo-Json
$reservationUrl = "$baseUrl/api/offers/$offerId/reservations"
$reservationResponse = Invoke-WebRequest -Uri $reservationUrl -Method Post -Headers $headers -ContentType "application/json" -Body $reservationJson -UseBasicParsing
$reservationResponse.StatusCode
$reservationResponse.Content
```

Очікується `201` і створене бронювання: ID, offer_id, контакти, client_reference та зафіксовані дати, ціна й валюта. Залишок зменшується до 1. Не змінюючи `$reservationJson`, повторіть запит:

```powershell
$retryResponse = Invoke-WebRequest -Uri $reservationUrl -Method Post -Headers $headers -ContentType "application/json" -Body $reservationJson -UseBasicParsing
$retryResponse.StatusCode
$retryResponse.Content
$search = Invoke-RestMethod -Uri $searchUrl -Headers $headers
$search.data | Where-Object { $_.code -eq $propertyCode } | ConvertTo-Json -Depth 10
```

Очікується `200`, той самий ID бронювання та залишок 1. Новий `client_reference` означає нове бронювання. Після вичерпання залишку пропозиція зникає з пошуку, але ідентичний повторний запит усе ще повертає створене бронювання.

## Демонстраційні імпорти

Для перевірки пошуку підготовлено два пакети: **9 об’єктів житла та 11 пропозицій** у Barcelona, Burgas, Rome і London.

- [Пропозиції supplier-a](docs/examples/import-supplier-a.json) — 9 пропозицій.
- [Пропозиції supplier-b](docs/examples/import-supplier-b.json) — 2 альтернативні пропозиції для тих самих об’єктів у Barcelona та London.

У пакетах використовуються різні ціни, валюти, дати проживання, місткість і залишки.

### Надсилання пакетів

Запустіть HTTP-сервер і worker черги `imports`. Наведені команди виконуйте в PowerShell із кореня проєкту.

```powershell
$baseUrl = "http://127.0.0.1:8002"
$headers = @{ Accept = "application/json" }

$bodyA = Get-Content -Raw -Encoding UTF8 docs/examples/import-supplier-a.json
$importA = Invoke-RestMethod -Uri "$baseUrl/api/imports" -Method Post -Headers $headers -ContentType "application/json" -Body $bodyA
$importA | ConvertTo-Json -Depth 10
```

Перевірте статус:

```powershell
Invoke-RestMethod -Uri "$baseUrl/api/imports/$($importA.data.id)" -Headers $headers | ConvertTo-Json -Depth 10
```

Після `completed` надішліть другий пакет:

```powershell
$bodyB = Get-Content -Raw -Encoding UTF8 docs/examples/import-supplier-b.json
$importB = Invoke-RestMethod -Uri "$baseUrl/api/imports" -Method Post -Headers $headers -ContentType "application/json" -Body $bodyB
$importB | ConvertTo-Json -Depth 10
```

```powershell
Invoke-RestMethod -Uri "$baseUrl/api/imports/$($importB.data.id)" -Headers $headers | ConvertTo-Json -Depth 10
```

Обидва імпорти повинні отримати статус `completed`: перший із `processed_offers = 9`, другий — із `processed_offers = 2`.

### Перевірка пошуку

```powershell
$query = "check_in=2026-10-10&check_out=2026-10-15&guests=2"
Invoke-RestMethod -Uri "$baseUrl/api/properties?city=Barcelona&$query" -Headers $headers | ConvertTo-Json -Depth 10
```

Очікувані результати серед демонстраційних об’єктів, до створення бронювань:

| Умови                            | Очікуваний результат                                                      |
| -------------------------------- | ------------------------------------------------------------------------- |
| Barcelona, 10–15 жовтня, 2 гості | `BCN-DEMO-02` за 85 EUR, потім `BCN-DEMO-01` за 100 EUR від supplier-b    |
| Barcelona, 20–25 жовтня, 2 гості | `BCN-DEMO-03` за 160 EUR                                                  |
| Burgas, 10–15 жовтня, 2 гості    | Лише `BRG-DEMO-01` за 65 EUR                                              |
| Burgas, 10–15 жовтня, 1 гість    | `BRG-DEMO-02` за 45 EUR та `BRG-DEMO-01` за 65 EUR                        |
| Rome, 10–15 жовтня, 2 гості      | Лише `ROM-DEMO-01`                                                        |
| London, 10–15 жовтня, 2 гості    | Один `LON-DEMO-01`; вибір між 110 GBP та 140 USD залежить від курсів у БД |
| Без міста, 10–15 жовтня, 2 гості | 5 об’єктів із цього набору                                                |

`LON-DEMO-02` виключається через нульовий залишок. Один об’єкт повертається лише один раз, навіть якщо для нього є пропозиції різних постачальників.

### Пагінація

```powershell
$page1 = Invoke-RestMethod -Uri "$baseUrl/api/properties?$query&per_page=2&page=1" -Headers $headers
$page1 | ConvertTo-Json -Depth 10
```

Наступну сторінку можна отримати за `links.next`, якщо посилання не дорівнює `null`:

```powershell
$page2 = Invoke-RestMethod -Uri $page1.links.next -Headers $headers
$page2 | ConvertTo-Json -Depth 10
```

Якщо інших відповідних записів немає, п’ять об’єктів розподіляються на три сторінки: 2, 2 та 1. На першій сторінці `prev = null`, на останній — `next = null`. `last = null` відповідає використанню `simplePaginate()`, який не визначає загальну кількість сторінок.

### Повторне використання прикладів

- `expires_at` у файлах — `2026-10-01T23:59:59Z`. Для перевірки після цього моменту оновіть строк актуальності.
- За потреби змініть дати проживання одночасно в пакетах і параметрах пошуку.
- Повторне надсилання незміненого пакета повертає існуючий імпорт і не запускає обробку повторно.
- Для нового імпорту зі зміненими даними задайте новий `external_import_id` та актуальний `sent_at`. Збережіть `external_id` пропозицій, якщо потрібно оновити їх.
- Новий імпорт може перезаписати залишки пропозицій; не надсилайте його між кроками перевірки списання залишку.
- Очікувана кількість результатів залежить від інших даних у базі та вже створених бронювань.

## Автоматизовані тести

Створіть локальний файл тестового середовища (PowerShell або Bash):

```powershell
Copy-Item .env.testing.example .env.testing
```

```bash
cp .env.testing.example .env.testing
```

Використовуйте окрему базу. Заповніть той самий пароль сервера MySQL, що й у `.env`:

```dotenv
APP_ENV=testing
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=accommodation_booking_test
DB_USERNAME=root
DB_PASSWORD=
CACHE_STORE=array
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
MAIL_MAILER=log
```

Перевірте, що `phpunit.xml` не перевизначає host, port або назву бази іншими значеннями. Тести з `RefreshDatabase` можуть перебудовувати таблиці — не підключайте їх до основної бази.

```bash
php artisan key:generate --env=testing
php artisan config:clear
php artisan migrate --env=testing
php artisan test
```

Останній підтверджений прогін на MySQL 8.4.11: **55 тестів, 392 перевірки**. Покриття включає валідацію й ідемпотентність імпорту, обробку Job, rollback пакета, статуси, SQL-пошук, валютне порівняння, пагінацію, бронювання, повторні запити та незмінність умов броні.

Автотести використовують ізоляцію БД, factories, seeders і підміну черги там, де перевіряється dispatch. Робота справжньої Redis-черги окремо перевіряється наведеним HTTP-сценарієм. Тест останньої одиниці послідовний; окремого тесту з паралельними процесами немає.

## Структура та технічні рішення

- `Supplier` має багато імпортів і пропозицій; `Property` — багато пропозицій; `OfferImport` — пропозиції поточного зв’язку з імпортом; `Offer` — багато бронювань.
- Form Requests перевіряють запити, API Resources формують відповіді. `ProcessOfferImport` обробляє імпорт, `PropertySearchQuery` формує SQL-пошук, `CreateReservation` виконує бронювання. Контролери координують ці дії.
- Унікальна пара `supplier_id + external_import_id` захищена індексом БД. Повторний імпорт повертає існуючий запис без перезапису payload і без повторного dispatch. Job отримує ID імпорту; масив пропозицій зберігається в JSON-полі `payload`.
- Об’єкт знаходиться або створюється за `property.code`. Пропозиція оновлюється за `supplier_id + external_id`; новий пакет може змінити її дані. Завершений імпорт повторно не обробляється.
- Пакет обробляється транзакційно: помилка відкочує зміни пропозицій. Після вичерпання спроб Job callback `failed()` позначає імпорт як `failed`. Через спільну транзакцію проміжний `processing` і лічильник можуть бути невидимі іншим з’єднанням до commit.
- Пошук спочатку відкидає невідповідні дати, недостатню місткість, нульовий залишок і прострочені пропозиції. SQL `ROW_NUMBER()` обирає один варіант на об’єкт за ціною в USD, потім за ID пропозиції. Загальне сортування — ціна в USD та ID об’єкта; пагінація також у БД.

### Захист останньої одиниці

`CreateReservation` відкриває транзакцію й повторно читає пропозицію за ID з `lockForUpdate()`. Усередині цієї транзакції перевіряються актуальність та залишок, створюється бронювання і зменшується залишок. Другий одночасний запит на ту саму пропозицію чекає на блокування, потім бачить оновлений залишок. Якщо залишилася одна одиниця, лише один новий запит може її забронювати. Помилка створення броні відкочує також списання залишку.

`client_reference` глобально унікальний. Ідентичний повтор повертає існуючу бронь з `200`; повтор із іншою пропозицією або контактами — `409`. Дати, ціна й валюта копіюються в бронювання, тому наступний імпорт не змінює вже зафіксовані умови.

## Прийняті припущення та обмеження

- Ціна — `DECIMAL(10,2)` в основних одиницях валюти за весь зазначений період. Це припущення реалізації; API повертає ціну рядком для збереження десяткової точності.
- `rate_to_usd` означає кількість USD за одну одиницю валюти. Курси сидера демонстраційні, без автоматичного оновлення. Порівняння виконується в USD; відповідь і бронювання зберігають оригінальні ціну та валюту. Пропозиції без додатного курсу виключаються.
- Одне бронювання списує одну одиницю. Облік клієнтів, платежі та скасування не реалізовані.
- Новий імпорт замінює `available_units` значенням постачальника. Узгодження залишку постачальника з локальними бронюваннями — поза межами реалізації.
- `sent_at` — час формування пакета постачальником. Відхилення застарілих пакетів за цим часом не реалізоване.
- Запис у MySQL і dispatch у Redis не є єдиною атомарною операцією; transactional outbox і автоматичне відновлення після збою між ними не реалізовані.

## Усунення типових проблем

- `GET /api/imports/` повертає `405`: передайте ID, наприклад `/api/imports/1`.
- Імпорт лишається `pending`: перевірте Redis та worker з `--queue=imports`.
- Порожня видача: перевірте точні дати, гостей, місто, залишок, `expires_at`, курси валют і сторінку пагінації.
- XAMPP-конфлікт порту `3306`: цей Compose використовує порт хоста `3307`; перевірте налаштування `.env`.
- Після зміни `.env`: виконайте `php artisan config:clear` і перезапустіть сервер та worker.

## Автор

**Радислав Лебедєв** — PHP / Laravel Developer.

Розроблено як тестове завдання для WTG Spain.

- GitHub: [RadLeoOFC](https://github.com/RadLeoOFC)
- Telegram: [@IT_globe](https://t.me/IT_globe)
