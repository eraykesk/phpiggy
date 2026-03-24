# 00 — Architecture Overview

> Current state of the PHPiggy codebase as it exists. All observations are grounded in the actual source files.

---

## 1. Request Lifecycle

```
Browser Request
    │
    ▼
public/index.php
    ├── include src/App/functions.php          (dd, e, redirectTo helpers)
    └── include src/App/bootsrap.php           (returns configured App instance)
            ├── require vendor/autoload.php
            ├── Dotenv::createImmutable()->load()   (populates $_ENV from .env)
            ├── new App(container-definitions.php)  (creates Router + Container)
            ├── registerRoutes($app)                (Routes.php — adds routes to Router)
            └── registerMiddleware($app)            (Middleware.php — adds global middleware)
    │
    ▼
App::run()
    ├── parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)
    └── Router::dispatch($path, $method, $container)
            ├── Method override: validate $_POST['_METHOD'] against [PUT,PATCH,DELETE]
            ├── Normalize path (trim slashes, collapse doubles)
            ├── Loop routes → regex match on regexPath
            ├── Extract named URL parameters ({transaction} → $params['transaction'])
            ├── Build middleware chain (closure wrapping, see §3)
            └── $action()   — executes outermost closure
```

### Middleware Execution Order

Global middleware is registered in `Config/Middleware.php` in this order:

```
1. CsrfGuardMiddleware
2. CsrfTokenMiddleware
3. TemplateDataMiddleware
4. ValidationExceptionMiddleware
5. FlashMiddleware
6. SessionMiddleware
```

The Router wraps closures sequentially, so the **last registered runs first** (outermost layer). Actual execution order at runtime:

```
SessionMiddleware          ← starts session; everything below needs it
  FlashMiddleware          ← reads $_SESSION['errors'] and oldFormData
    ValidationExceptionMiddleware  ← catches ValidationException, writes to session
      TemplateDataMiddleware       ← injects $title global into template engine
        CsrfTokenMiddleware        ← generates/persists CSRF token in session
          CsrfGuardMiddleware      ← validates token on POST/PATCH/DELETE
            [Route middleware]     ← AuthRequiredMiddleware or GuestOnlyMiddleware
              Controller action    ← innermost — actual business logic
```

This ordering is intentional and correct: the session must be active before anything can read from it.

---

## 2. Dependency Injection Container

**Two resolution paths exist side-by-side:**

### `Container::get(string $id)` — explicit definitions
Used for infrastructure classes that need configuration (Database needs `$_ENV` values, TemplateEngine needs a base path).

```php
// container-definitions.php defines factories keyed by FQCN
Database::class => fn() => new Database($_ENV['DB_DRIVER'], [...], $_ENV['DB_USER'], $_ENV['DB_PASS'])
```

Results are cached in `$this->resolved` after first instantiation — **singleton behaviour per request**.

### `Container::resolve(string $className)` — reflection-based autowiring
Used for controllers and middleware. Uses `ReflectionClass` to inspect the constructor, then recursively calls `get()` for each type-hinted parameter.

**Constraint:** Only works with classes that have type-hinted constructor parameters of non-builtin types. Scalar types (`string`, `int`) cannot be autowired — they must come from explicit definitions.

**Concrete classes hardcoded as keys:** Currently, container definitions map `ConcreteClass::class → factory`. Controllers type-hint against concrete classes. This will change when interfaces are introduced (see `docs/01`).

---

## 3. Router: Middleware Pipeline Detail

The pipeline is built using PHP arrow functions (`fn()`), which capture outer-scope variables **by value** at creation time:

```php
$action = fn() => $controllerInstance->{$function}($params);  // seed

foreach ($allMiddleware as $middleware) {
    $middlewareInstance = $container->resolve($middleware);
    $action = fn() => $middlewareInstance->process($action);   // wraps previous $action
}

$action();  // triggers outermost → innermost
```

Because arrow functions capture `$action` and `$middlewareInstance` by value at each iteration, each closure correctly holds a reference to the *previous* closure, not the variable name. This is what makes the chain correct.

**`$allMiddleware` composition:**
```php
$allMiddleware = [...$route['middlewares'], ...$this->middlewares];
```
Route-specific middleware (`AuthRequiredMiddleware`) is prepended, global middleware appended. After wrapping, global middleware (SessionMiddleware) ends up outermost and runs first.

---

## 4. Database / Statement Layer

After the code review refactor, the layer is split into two responsibilities:

```
Database                        Statement
────────────────────────        ────────────────────────────
PDO connection lifecycle        Single query result wrapper
query(sql, params): Statement   find(): array|false
id(): string|false              findAll(): array
                                count(): int|string|false
```

`Database::query()` creates a **local** `PDOStatement`, executes it immediately, and returns `new Statement($stmt)`. There is no shared state between queries on the `Database` object — each call produces its own isolated `Statement`. The previous design used `private PDOStatement $stmt` on the `Database` object, which was vulnerable to silent overwrites in multi-query methods.

`Database::id()` remains on the connection object because `lastInsertId()` is a property of the PDO connection, not of any individual statement.

---

## 5. Validation System

`Validator` (Framework) + `ValidatorService` (App) implement a two-layer validation strategy:

```
ValidatorService::validateRegister($_POST)
    └── Validator::validate($data, $fields)
            └── foreach rule string → RuleInterface::validate() + getMessage()
                    └── throws ValidationException(['field' => ['message', ...]])
                            └── caught by ValidationExceptionMiddleware
                                    └── stored in $_SESSION['errors']
                                    └── redirect to referer
                                    └── FlashMiddleware reads on next request
```

Validation rules are registered by alias string (`'required'`, `'email'`, `'min:18'`) and implement `RuleInterface`. Rule parameters are passed with colon notation: `'min:18'` → `$params = ['18']`.

---

## 6. Current Strengths

| Area | Detail |
|------|--------|
| SQL safety | All queries use PDO prepared statements — no string interpolation of user data |
| Password security | bcrypt with cost 12 (`PASSWORD_BCRYPT`) |
| Session fixation | `session_regenerate_id()` called on both register and login |
| Logout hygiene | `session_destroy()` + explicit cookie invalidation via `setcookie()` with past expiry |
| CSRF | Single-use tokens (unset after verification), token injected as hidden field |
| XSS | `e()` helper wraps `htmlspecialchars()`, used consistently in all views |
| Strict types | `declare(strict_types=1)` in every Framework and App file |
| Separation | Controllers are thin — business logic lives in Services |
| Extensibility | `RuleInterface` makes adding validation rules trivial |

---

## 7. Current Structural Weaknesses

| Issue | Location | Impact |
|-------|----------|--------|
| Services read `$_GET` directly | `TransactionService::getUserTransactions` reads `$_GET['s']` | Same problem as the `$_SESSION` issue resolved in the code review — breaks testability |
| No interfaces on services | All DI bindings use concrete classes | Cannot swap implementations or mock in tests without changing container definitions |
| `TemplateEngine::render()` uses `extract()` | `TemplateEngine.php:12` | Any key in `$data` becomes a local variable — naming collisions possible |
| Client-provided MIME type trusted | `ReceiptService::validateFile()` checks `$file['type']` | MIME type from `$_FILES` is browser-supplied, not verified server-side. Should use `finfo_file()` |
| `SessionException` is never caught | `SessionMiddleware` throws it | If headers are already sent, this produces an uncaught exception with a 500 |
| `ValidationExceptionMiddleware` trusts `HTTP_REFERER` | `ValidationExceptionMiddleware.php` | Referer header can be absent or spoofed; should fall back to a safe URL |
| No return types on App-layer methods | Controllers, some middleware | Inconsistent with Framework layer which has return types |
| No test coverage | Entire codebase | Zero automated verification of behaviour |
| `$_GET` read inside service | `TransactionService` | Services should not read superglobals |
