# 02 — PHPUnit Integration

> Test coverage strategy for PHPiggy. Interfaces from `docs/01` are a prerequisite — mocking concrete classes is brittle; mocking interfaces is the correct approach.

---

## 1. Setup

### Install PHPUnit

```bash
docker compose exec app composer require --dev phpunit/phpunit ^11
```

### `phpunit.xml` configuration

Place in project root:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

### Directory structure

```
tests/
├── Unit/
│   ├── Framework/
│   │   ├── ValidatorTest.php
│   │   └── Rules/
│   │       ├── RequiredRuleTest.php
│   │       ├── EmailRuleTest.php
│   │       ├── MinRuleTest.php
│   │       └── ...
│   └── App/
│       ├── Services/
│       │   ├── UserServiceTest.php
│       │   ├── TransactionServiceTest.php
│       │   ├── ReceiptServiceTest.php
│       │   └── ValidatorServiceTest.php
│       └── Middleware/
│           └── CsrfGuardMiddlewareTest.php
└── Integration/
    └── (future — requires test database)
```

---

## 2. Prioritised Test List

Priority is based on **risk** (what breaks silently if wrong) and **complexity** (most logic lives here).

### Priority 1 — Validation Rules (zero dependencies, pure functions)

These are the easiest tests to write and give immediate value. Each rule implements `RuleInterface` with two pure methods.

| Class | What to test |
|-------|-------------|
| `RequiredRule` | Empty string fails, whitespace-only fails, non-empty passes |
| `EmailRule` | Valid emails pass, malformed emails fail, missing `@` fails |
| `MinRule` | Value below minimum fails, equal passes, above passes; missing param throws |
| `LengthMaxRule` | String at limit fails (uses `<` not `<=`), below passes |
| `MatchRule` | Matching fields pass, non-matching fails |
| `InRule` | Value in allowed list passes, outside fails |
| `DateFormatRule` | Correct format passes, wrong format fails |
| `NumericRule` | Integers and decimals pass, strings fail |
| `UrlRule` | Valid URLs pass, non-URLs fail |

### Priority 2 — `Validator` (orchestration logic)

Tests that the `Validator` class correctly aggregates rule errors and throws `ValidationException` with the right structure.

| Scenario | Expected behaviour |
|----------|--------------------|
| All rules pass | No exception thrown |
| One field fails one rule | `ValidationException` with `errors['field'][0]` = rule message |
| Multiple fields fail | All errors collected before throwing |
| Rule with params (`min:18`) | Params correctly split and passed to rule |

### Priority 3 — `UserService` (authentication logic, highest security risk)

Depends on `DatabaseInterface` and `StatementInterface` mocks.

| Method | Scenarios to test |
|--------|-------------------|
| `isEmailTaken()` | Count > 0 throws `ValidationException`; count = 0 passes |
| `create()` | Calls `password_hash`, calls `db->query()` with hashed password, sets `$_SESSION['user']` |
| `login()` | Correct credentials pass; wrong password throws; non-existent user throws |
| `logout()` | Calls `session_destroy()`; sets cookie with past expiry |

### Priority 4 — `TransactionService` (business logic, data access patterns)

| Method | Scenarios to test |
|--------|-------------------|
| `create()` | Correct SQL params including formatted date |
| `getUserTransactions()` | Returns combined transactions + receipts array; correct pagination params passed |
| `getUserTransaction()` | Returns `false` for wrong user ID (ownership check) |
| `update()` | Correct `user_id` filter in WHERE clause (prevents cross-user edit) |
| `delete()` | Correct `user_id` filter in WHERE clause (prevents cross-user delete) |

### Priority 5 — `CsrfGuardMiddleware`

| Scenario | Expected behaviour |
|----------|-------------------|
| GET request | `$next` called, no token check |
| POST with matching token | `$next` called, token unset from session |
| POST with missing token | Redirects (or equivalent) |
| POST with mismatched token | Redirects |

---

## 3. How Interfaces Enable Mocking

Without interfaces, mocking `UserService` requires the concrete class:

```php
// Brittle — PHPUnit must construct the real class, including PDO
$mock = $this->createMock(UserService::class);
```

With `UserServiceInterface`:

```php
// Clean — PHPUnit creates a test double implementing the interface signature
$mock = $this->createMock(UserServiceInterface::class);
$mock->method('login')->willThrowException(new ValidationException(['password' => ['Invalid']]));
```

For services under test (not mocked), inject mock dependencies:

```php
$mockStatement = $this->createMock(StatementInterface::class);
$mockStatement->method('count')->willReturn(0);

$mockDb = $this->createMock(DatabaseInterface::class);
$mockDb->method('query')->willReturn($mockStatement);

$service = new UserService($mockDb);
// Now test UserService logic without any database
```

---

## 4. Example Test: `UserService::isEmailTaken()`

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services;

use PHPUnit\Framework\TestCase;
use App\Services\UserService;
use Framework\Contracts\DatabaseInterface;
use Framework\Contracts\StatementInterface;
use Framework\Exceptions\ValidationException;

class UserServiceTest extends TestCase
{
    private DatabaseInterface $mockDb;
    private StatementInterface $mockStatement;
    private UserService $service;

    protected function setUp(): void
    {
        $this->mockStatement = $this->createMock(StatementInterface::class);
        $this->mockDb = $this->createMock(DatabaseInterface::class);
        $this->mockDb->method('query')->willReturn($this->mockStatement);

        $this->service = new UserService($this->mockDb);
    }

    public function test_isEmailTaken_throws_when_email_already_exists(): void
    {
        $this->mockStatement->method('count')->willReturn(1);

        $this->expectException(ValidationException::class);

        $this->service->isEmailTaken('taken@example.com');
    }

    public function test_isEmailTaken_passes_when_email_is_available(): void
    {
        $this->mockStatement->method('count')->willReturn(0);

        // No exception expected
        $this->service->isEmailTaken('available@example.com');

        $this->addToAssertionCount(1); // explicit: assert no exception
    }

    public function test_login_throws_on_wrong_password(): void
    {
        $hashedPassword = password_hash('correctpassword', PASSWORD_BCRYPT);

        $this->mockStatement->method('find')->willReturn([
            'id' => 1,
            'email' => 'user@example.com',
            'password' => $hashedPassword,
        ]);

        $this->expectException(ValidationException::class);

        $this->service->login(['email' => 'user@example.com', 'password' => 'wrongpassword']);
    }

    public function test_login_throws_when_user_not_found(): void
    {
        $this->mockStatement->method('find')->willReturn(false);

        $this->expectException(ValidationException::class);

        $this->service->login(['email' => 'nobody@example.com', 'password' => 'any']);
    }
}
```

### Running tests inside Docker

```bash
docker compose exec app ./vendor/bin/phpunit
docker compose exec app ./vendor/bin/phpunit --testsuite Unit
docker compose exec app ./vendor/bin/phpunit tests/Unit/App/Services/UserServiceTest.php
```

---

## 5. Notes on Testing Superglobals

Several methods still interact with superglobals (`$_SESSION`, `$_SERVER`). These require extra handling in tests:

- Set `$_SESSION` values directly before calling the method under test
- For `$_SERVER['HTTP_REFERER']`, set it in `$_SERVER` before the test
- Consider wrapping superglobal access in a thin abstraction layer in a future refactor (e.g., a `SessionStore` class) to make this cleaner

---

## 6. Implementation Checklist

### Setup
- [ ] Add `phpunit/phpunit ^11` to `require-dev` in `composer.json`
- [ ] Run `docker compose exec app composer install` to install it
- [ ] Create `phpunit.xml` in project root with configuration above
- [ ] Create `tests/Unit/Framework/Rules/` directory structure
- [ ] Create `tests/Unit/App/Services/` directory structure

### Validation Rule Tests
- [ ] Write `tests/Unit/Framework/Rules/RequiredRuleTest.php`
- [ ] Write `tests/Unit/Framework/Rules/EmailRuleTest.php`
- [ ] Write `tests/Unit/Framework/Rules/MinRuleTest.php`
- [ ] Write `tests/Unit/Framework/Rules/LengthMaxRuleTest.php`
- [ ] Write `tests/Unit/Framework/Rules/MatchRuleTest.php`
- [ ] Write `tests/Unit/Framework/Rules/InRuleTest.php`
- [ ] Write `tests/Unit/Framework/Rules/DateFormatRuleTest.php`
- [ ] Write `tests/Unit/Framework/Rules/NumericRuleTest.php`
- [ ] Write `tests/Unit/Framework/Rules/UrlRuleTest.php`
- [ ] Write `tests/Unit/Framework/ValidatorTest.php`

### Service Tests (require interfaces from docs/01 to be complete first)
- [ ] Write `tests/Unit/App/Services/UserServiceTest.php` (isEmailTaken, create, login, logout)
- [ ] Write `tests/Unit/App/Services/TransactionServiceTest.php` (ownership checks, pagination)
- [ ] Write `tests/Unit/App/Services/ReceiptServiceTest.php` (validateFile scenarios)
- [ ] Write `tests/Unit/App/Services/ValidatorServiceTest.php`

### Middleware Tests
- [ ] Write `tests/Unit/App/Middleware/CsrfGuardMiddlewareTest.php`

### CI Integration
- [ ] Confirm `./vendor/bin/phpunit` runs cleanly in Docker with exit code 0
- [ ] All tests green before proceeding to docs/03
