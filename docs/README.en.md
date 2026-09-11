# Accommodation Booking API

Language: [Українська](../README.md) | **English**

REST API for asynchronous accommodation offer imports, finding the cheapest current offer for each property, and safe reservations. Built as a Laravel Developer take-home assignment. No frontend is included.

## Requirements and environment

- PHP 8.2+ with extensions required by Composer dependencies, `pdo_mysql`, and `redis` (phpredis).
- Laravel; the exact locked version is recorded in `composer.lock`. The assignment requires Laravel 11 or 12. Check the installed version with `php artisan --version`.
- Composer, Git, MySQL 8+, and an accessible Redis Server.
- Docker Engine / Docker Desktop with Docker Compose for the MySQL setup below.

Verified environment: Windows, XAMPP PHP 8.2.12, Docker MySQL 8.4.11, and local Redis. PHP/Laravel and the queue worker run on the host; only MySQL runs in Docker. XAMPP Apache and MariaDB are not required for this setup. Node.js is not required to test the API.

## Installation

Replace `<REPOSITORY_URL>` with this repository’s clone URL from GitHub’s **Code** button.

```bash
git clone <REPOSITORY_URL> laravel-accommodation-booking-api
cd laravel-accommodation-booking-api
composer install
```

Copy the environment file using **one** command for your shell: PowerShell first, Bash second.

```powershell
Copy-Item .env.example .env
```

```bash
cp .env.example .env
```

Check these settings in `.env`. Fill in the empty `DB_PASSWORD` with your own local password **before the first Compose startup**.

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

Redis must be running at the configured address. Set `REDIS_PASSWORD` if authentication is required. Do not commit `.env` or `.env.testing`; example files contain no passwords or application keys.

```bash
php artisan key:generate
php artisan config:clear
docker compose -f compose.mysql.yml up -d --wait
php artisan migrate --seed
```

`SupplierSeeder` creates `supplier-a` and `supplier-b`. `ExchangeRateSeeder` adds demo exchange rates; see `database/seeders/ExchangeRateSeeder.php` for exact values. The seeders do not populate an accommodation catalog: offers enter through the import API.

### Databases

| Purpose           | Database                     | Address from the host |
| ----------------- | ---------------------------- | --------------------- |
| Local application | `accommodation_booking`      | `127.0.0.1:3307`      |
| Automated tests   | `accommodation_booking_test` | `127.0.0.1:3307`      |

`MYSQL_DATABASE` creates the main database. `docker/mysql/init/01-create-testing-database.sql` creates the test database during initial setup on an empty volume. Laravel migrations create the tables; the SQL file does not change afterwards. Data is stored in a named Docker volume.

If the volume existed before the init file was added, create the test database manually in the MySQL client using `CREATE DATABASE IF NOT EXISTS accommodation_booking_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`.

Changing `DB_PASSWORD` in `.env` does not change an already initialized MySQL password. To use your own MySQL 8+ server, skip Compose and configure its host, port, and both databases. If PHP runs in a container on the same Docker network, use the service name `mysql` and port `3306` instead.

## Running the application

Terminal 1 — HTTP server:

```bash
php artisan serve --host=127.0.0.1 --port=8002
```

Terminal 2 — queue worker:

```bash
php artisan queue:work redis --queue=imports --tries=3 --verbose
```

The API is available at `http://127.0.0.1:8002/api`. After `DONE`, the worker keeps waiting for new jobs. Press `Ctrl+C` to stop the server or worker. Restart the worker after changing Job code or configuration. To stop MySQL: `docker compose -f compose.mysql.yml stop`; preserve the volume if you need the data.

## API

Send `Accept: application/json`, and also `Content-Type: application/json` for POST requests.
| Method | Endpoint | Success |
| --- | --- | --- |
| POST | `/api/imports` | `202 Accepted` |
| GET | `/api/imports/{import}` | `200 OK` |
| GET | `/api/properties` | `200 OK` |
| POST | `/api/offers/{offer}/reservations` | `201 Created`; identical retry: `200 OK` |

Validation errors return `422`; a missing import or offer returns `404`; reservation conflicts return `409`.

### PowerShell walkthrough

Run the blocks sequentially in the same PowerShell terminal from the project root. Variables are not shared between terminals. Copy only the code, without `PS ...>` or `>>` prompts. Assigning a variable does not print its value.

#### 1. Import

Dates are calculated relative to the execution date. This request creates demo data in the main local database.

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

Expect `202` and `data.id`. The initial status is `pending`. To observe it, submit the import before starting the worker, then start the worker in another terminal. The response may include additional metadata.

#### 2. Status

```powershell
Invoke-RestMethod -Uri "$baseUrl/api/imports/$importId" -Method Get -Headers $headers | ConvertTo-Json -Depth 10
```

After the Job finishes, expect `completed`, `total_offers: 1`, `processed_offers: 1`, `error: null`, and a populated `completed_at`. The endpoint returns the ID, supplier slug, external import ID, status, counters, and timestamps. The input payload and internal exception text are not exposed. Timestamps are serialized in UTC and may include fractional seconds.

#### 3. Search

```powershell
$searchUrl = "$baseUrl/api/properties?city=Barcelona&check_in=$checkIn&check_out=$checkOut&guests=2&per_page=100"
$search = Invoke-RestMethod -Uri $searchUrl -Headers $headers
$search | ConvertTo-Json -Depth 10
$property = $search.data | Where-Object { $_.code -eq $propertyCode } | Select-Object -First 1
$offerId = $property.best_offer.id
if (-not $offerId) { throw "Demo offer not found. Check import status, rates and pagination." }
```

Expect `Demo Apartment` with a `best_offer` price of `"100.00"`, currency `EUR`, and `available_units: 2`. If the property is not on the first page, follow `links.next`.

Required parameters: `check_in`, `check_out` in `YYYY-MM-DD` format, and `guests >= 1`; check-out must follow check-in. `city` is optional. Pagination: `page` starts at 1; `per_page` ranges from 1 to 100 and defaults to 15. The response includes `data`, `links.next`, `links.prev`, and `meta.per_page`. Omit `city` to search across cities.

#### 4. Reservation and retry

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

Expect `201` and the created reservation, including its ID, offer_id, contact details, client_reference, and the saved dates, price, and currency. Stock decreases to 1. Repeat without changing `$reservationJson`:

```powershell
$retryResponse = Invoke-WebRequest -Uri $reservationUrl -Method Post -Headers $headers -ContentType "application/json" -Body $reservationJson -UseBasicParsing
$retryResponse.StatusCode
$retryResponse.Content
$search = Invoke-RestMethod -Uri $searchUrl -Headers $headers
$search.data | Where-Object { $_.code -eq $propertyCode } | ConvertTo-Json -Depth 10
```

Expect `200`, the same reservation ID, and stock still equal to 1. A new `client_reference` represents a new reservation. Once stock is exhausted, the offer disappears from search, but an identical retry still returns the existing reservation.

## Automated tests

Create the local test environment file using PowerShell or Bash:

```powershell
Copy-Item .env.testing.example .env.testing
```

```bash
cp .env.testing.example .env.testing
```

Use a separate database. Fill in the same MySQL server password used in `.env`:

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

Ensure `phpunit.xml` does not override the host, port, or database name with different values. Tests using `RefreshDatabase` may rebuild tables: do not connect them to the main database.

```bash
php artisan key:generate --env=testing
php artisan config:clear
php artisan migrate --env=testing
php artisan test
```

Latest confirmed run on MySQL 8.4.11: **55 tests, 392 assertions**. Coverage includes import validation and idempotency, Job processing, batch rollback, statuses, SQL search, currency comparison, pagination, reservations, retries, and preservation of reservation terms.

Automated tests use database isolation, factories, seeders, and a fake queue where dispatch is being tested. Real Redis queue processing is checked separately using the HTTP walkthrough above. The last-unit test is sequential; a separate test using parallel processes is not included.

## Structure and implementation decisions

- `Supplier` has many imports and offers; `Property` has many offers; `OfferImport` relates to offers currently associated with that import; `Offer` has many reservations.
- Form Requests validate input; API Resources shape responses. `ProcessOfferImport` processes imports, `PropertySearchQuery` builds the SQL search, and `CreateReservation` handles reservations. Controllers coordinate these operations.
- A database unique index protects `supplier_id + external_import_id`. A repeated import returns the existing record without overwriting its payload or dispatching another Job. The Job receives the import ID; the offers array is stored in the JSON `payload` column.
- Properties are found or created using `property.code`. Offers are updated using `supplier_id + external_id`; another import can update their data. Completed imports are not processed again.
- The batch is processed within a transaction: an error rolls back offer changes. After retries are exhausted, the Job’s `failed()` callback marks the import as `failed`. Because processing shares a transaction, the intermediate `processing` state and counter may not be visible to other connections before commit.
- Search first filters out mismatched dates, insufficient capacity, zero stock, and expired offers. SQL `ROW_NUMBER()` selects one offer per property by USD price, then offer ID. Overall sorting uses USD price and property ID; pagination also runs in the database.

### Last-unit reservation protection

`CreateReservation` starts a transaction and reads the offer again by ID using `lockForUpdate()`. It checks availability and stock, creates the reservation, and decrements stock within that transaction. A second concurrent request for the same offer waits for the lock and then sees the updated stock. If only one unit remains, only one new request can reserve it. A reservation creation error also rolls back the stock decrement.

`client_reference` is globally unique. An identical retry returns the existing reservation with `200`; reuse for another offer or different contact details returns `409`. Dates, price, and currency are copied into the reservation, so later imports cannot change its saved terms.

## Assumptions and limitations

- Price is stored as `DECIMAL(10,2)` in major currency units for the entire specified stay. This is an implementation assumption; the API returns price as a string to preserve decimal precision.
- `rate_to_usd` means USD per one currency unit. Seeded rates are illustrative and are not updated automatically. Comparisons use USD; responses and reservations retain the original price and currency. Offers without a positive rate are excluded.
- One reservation consumes one unit. Customer accounts, payments, and cancellations are not implemented.
- A new import replaces `available_units` with the supplier’s value. Reconciliation between supplier stock and local reservations is outside this implementation.
- `sent_at` records when the supplier generated the batch. Rejecting stale batches based on that timestamp is not implemented.
- The MySQL write and Redis dispatch are not one atomic operation; a transactional outbox and automatic recovery from a failure between them are not implemented.

## Troubleshooting

- `GET /api/imports/` returns `405`: include an ID, such as `/api/imports/1`.
- An import stays `pending`: check Redis and a worker started with `--queue=imports`.
- Empty search results: check exact dates, guests, city, stock, `expires_at`, exchange rates, and pagination.
- XAMPP port `3306` conflict: this Compose setup publishes MySQL on host port `3307`; check `.env`.
- After changing `.env`, run `php artisan config:clear` and restart the server and worker.
