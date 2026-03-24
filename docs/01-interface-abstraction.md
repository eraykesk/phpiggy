# 01 — Interface Abstraction

> Introduces interfaces for all service and infrastructure classes to decouple the application layer from concrete implementations and enable test mocking.

---

## 1. Why This Step Comes First

The current DI container definitions map concrete class names to factories:

```php
// container-definitions.php — current
Database::class => fn() => new Database(...),
UserService::class => fn(Container $c) => new UserService($c->get(Database::class)),
```

Controllers type-hint against these concrete classes:

```php
// AuthController — current
public function __construct(
    private TemplateEngine $view,        // concrete
    private ValidatorService $validator, // concrete
    private UserService $userService     // concrete
)
```

This means:
- You cannot substitute a test double without modifying container definitions
- PHPUnit cannot mock a class in isolation without the concrete class existing and its dependencies being satisfied
- Any change to a concrete class signature forces changes in every class that depends on it

Interfaces break this coupling. Once controllers depend on `UserServiceInterface`, the container can resolve to either the real `UserService` or a `FakeUserService` in tests.

---

## 2. Interfaces to Create

### 2.1 Framework Layer

#### `Framework\Contracts\DatabaseInterface`
```php
interface DatabaseInterface
{
    public function query(string $query, array $params = []): StatementInterface;
    public function id(): string|false;
}
```

#### `Framework\Contracts\StatementInterface`
```php
interface StatementInterface
{
    public function find(): array|false;
    public function findAll(): array;
    public function count(): int|string|false;
}
```

#### `Framework\Contracts\TemplateEngineInterface`
```php
interface TemplateEngineInterface
{
    public function render(string $template, array $data = []): string;
    public function resolve(string $path): string;
    public function addGlobal(string $key, mixed $value): void;
}
```

---

### 2.2 App Service Layer

All four service classes get interfaces in `App\Contracts\`:

#### `App\Contracts\UserServiceInterface`
Methods derived from `UserService`:
```php
interface UserServiceInterface
{
    public function isEmailTaken(string $email): void;
    public function create(array $formData): void;
    public function login(array $formData): void;
    public function logout(): void;
}
```

#### `App\Contracts\TransactionServiceInterface`
Methods derived from `TransactionService`:
```php
interface TransactionServiceInterface
{
    public function create(array $formData, int $userId): void;
    public function getUserTransactions(int $length, int $offset, int $userId): array;
    public function getUserTransaction(string $id, int $userId): array|false;
    public function update(array $formData, int $id, int $userId): void;
    public function delete(int $id, int $userId): void;
}
```

#### `App\Contracts\ReceiptServiceInterface`
```php
interface ReceiptServiceInterface
{
    public function validateFile(?array $file): void;
    public function upload(array $file, int $transaction): void;
    public function getReceipt(string $id): array|false;
    public function read(array $receipt): void;
    public function delete(array $receipt): void;
}
```

#### `App\Contracts\ValidatorServiceInterface`
```php
interface ValidatorServiceInterface
{
    public function validateRegister(array $formData): void;
    public function validateLogin(array $formData): void;
    public function validateTransaction(array $formData): void;
}
```

---

## 3. Container Binding Changes

Once interfaces exist, `container-definitions.php` binds the **interface** to the **concrete class**:

```php
// container-definitions.php — after
use App\Contracts\{UserServiceInterface, TransactionServiceInterface, ReceiptServiceInterface, ValidatorServiceInterface};
use Framework\Contracts\{DatabaseInterface, TemplateEngineInterface};

return [
    TemplateEngineInterface::class => fn() => new TemplateEngine(Paths::VIEW),
    ValidatorServiceInterface::class => fn() => new ValidatorService(),
    DatabaseInterface::class => fn() => new Database(
        $_ENV['DB_DRIVER'],
        ['host' => $_ENV['DB_HOST'], 'port' => $_ENV['DB_PORT'], 'dbname' => $_ENV['DB_NAME']],
        $_ENV['DB_USER'],
        $_ENV['DB_PASS']
    ),
    UserServiceInterface::class => fn(Container $c) => new UserService($c->get(DatabaseInterface::class)),
    TransactionServiceInterface::class => fn(Container $c) => new TransactionService($c->get(DatabaseInterface::class)),
    ReceiptServiceInterface::class => fn(Container $c) => new ReceiptService($c->get(DatabaseInterface::class)),
];
```

Controllers update their constructor type hints to use the interface:

```php
// AuthController — after
public function __construct(
    private TemplateEngineInterface $view,
    private ValidatorServiceInterface $validatorService,
    private UserServiceInterface $userService
)
```

The `Container::resolve()` method resolves dependencies by their type hint string. As long as the interface FQCN is registered in `definitions`, autowiring works without further changes.

---

## 4. Concrete Classes Implement Their Interface

Each service class declaration adds `implements`:

```php
class UserService implements UserServiceInterface { ... }
class TransactionService implements TransactionServiceInterface { ... }
class ReceiptService implements ReceiptServiceInterface { ... }
class ValidatorService implements ValidatorServiceInterface { ... }
class Database implements DatabaseInterface { ... }
class Statement implements StatementInterface { ... }
class TemplateEngine implements TemplateEngineInterface { ... }
```

PHP will throw a fatal error at load time if a method in the interface is missing or has an incompatible signature — this is the compile-time safety net.

---

## 5. Impact on Testability

With interfaces in place, a PHPUnit test can do:

```php
$mockDb = $this->createMock(DatabaseInterface::class);
$mockDb->method('query')->willReturn($mockStatement);

$service = new UserService($mockDb);
```

No real database connection required. The test exercises `UserService` logic in complete isolation. This was impossible before interfaces existed because `UserService` constructor type-hinted `Database` (the concrete class backed by a live PDO connection).

---

## 6. Additional Issue Identified: `$_GET` in Service

`TransactionService::getUserTransactions()` reads `$_GET['s']` directly (search term). This should be passed as a parameter, mirroring the `$_SESSION` fix already applied:

```php
// Change signature
public function getUserTransactions(int $length, int $offset, int $userId, string $searchTerm = ''): array

// HomeController passes it
$this->transactionService->getUserTransactions($length, $offset, (int) $_SESSION['user'], $_GET['s'] ?? '');
```

This should be done as part of the interface abstraction step since it affects the interface method signature.

---

## 7. Implementation Checklist

### Framework Contracts
- [ ] Create `src/Framework/Contracts/DatabaseInterface.php`
- [ ] Create `src/Framework/Contracts/StatementInterface.php`
- [ ] Create `src/Framework/Contracts/TemplateEngineInterface.php`
- [ ] Add `implements DatabaseInterface` to `Database`
- [ ] Add `implements StatementInterface` to `Statement`
- [ ] Add `implements TemplateEngineInterface` to `TemplateEngine`
- [ ] Add return type `string` to `TemplateEngine::render()`

### App Contracts
- [ ] Create `src/App/Contracts/UserServiceInterface.php`
- [ ] Create `src/App/Contracts/TransactionServiceInterface.php`
- [ ] Create `src/App/Contracts/ReceiptServiceInterface.php`
- [ ] Create `src/App/Contracts/ValidatorServiceInterface.php`
- [ ] Add `implements UserServiceInterface` to `UserService`
- [ ] Add `implements TransactionServiceInterface` to `TransactionService`
- [ ] Add `implements ReceiptServiceInterface` to `ReceiptService`
- [ ] Add `implements ValidatorServiceInterface` to `ValidatorService`

### Fix `$_GET` in Service
- [ ] Add `string $searchTerm = ''` parameter to `TransactionServiceInterface::getUserTransactions()`
- [ ] Update `TransactionService::getUserTransactions()` to use `$searchTerm` parameter instead of `$_GET['s']`
- [ ] Update `HomeController::home()` to pass `$_GET['s'] ?? ''`

### Container & Controllers
- [ ] Update `container-definitions.php` to bind interface keys to concrete factories
- [ ] Update `AuthController` constructor type hints to use interfaces
- [ ] Update `TransactionController` constructor type hints to use interfaces
- [ ] Update `ReceiptController` constructor type hints to use interfaces
- [ ] Update `HomeController` constructor type hints to use interfaces
- [ ] Update middleware that inject `TemplateEngine` to use `TemplateEngineInterface`

### Verification
- [ ] Run `docker compose up` — app loads without errors
- [ ] Register a user, create a transaction, upload a receipt — all paths still work
- [ ] Confirm container resolves correctly by checking no `ContainerException` is thrown
