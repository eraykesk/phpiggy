# 03 — REST API Layer

> Designing the JSON API that will serve the Vue.js frontend. Existing controllers and services are extended, not replaced.

---

## 1. Authentication Strategy: JWT vs Session

### The question
The current app uses PHP sessions (cookie-based). Should the API use sessions or JWT tokens?

### Analysis

| Concern | Session | JWT |
|---------|---------|-----|
| Vue SPA origin | Same origin works; cross-origin needs `credentials: include` + CORS config | Bearer token in `Authorization` header — no CORS credential complexity |
| Statefulness | Server must store session state (PHP session files or Redis) | Stateless — server validates signature only |
| Logout | Simple: `session_destroy()` | Requires token blacklist or short expiry + refresh token |
| Implementation complexity | Already exists in the codebase | New: token generation, signing, validation middleware |
| Security surface | Session fixation risk (already mitigated), CSRF required | No CSRF needed for API (not cookie-based); token theft risk if stored in localStorage |
| Revocation | Immediate | Requires blacklist for immediate revocation |
| Scaling | Requires sticky sessions or shared session store | Stateless — scales horizontally |

### Recommendation: **JWT**

**Reason:** The Vue.js SPA is a separate compilation artifact (built by Vite, served as static assets). Even if hosted on the same domain, treating the frontend as a distinct client from day one is the correct architectural decision. JWT tokens passed in `Authorization: Bearer <token>` headers require zero cookie/CORS credential configuration, work identically from a browser or a mobile app, and are the industry standard for SPA-to-API communication.

**Token storage:** Store JWT in `localStorage` for simplicity during development. For production hardening, use `httpOnly` cookies for the refresh token and keep the short-lived access token in memory — but this is a later concern.

**Token lifetime:**
- Access token: 15 minutes
- Refresh token: 7 days (stored in database for revocation support)

### Library

```bash
docker compose exec app composer require firebase/php-jwt
```

`firebase/php-jwt` is the standard PHP JWT library — well-maintained, PSR-4 compatible, minimal.

---

## 2. API Endpoint Design

All API routes are prefixed with `/api/v1/` for versioning. Resources are plural nouns. HTTP verbs carry the action meaning.

### Authentication

| Method | Endpoint | Description | Auth required |
|--------|----------|-------------|---------------|
| `POST` | `/api/v1/auth/register` | Create user account | No |
| `POST` | `/api/v1/auth/login` | Returns access + refresh tokens | No |
| `POST` | `/api/v1/auth/logout` | Invalidates refresh token | Yes |
| `POST` | `/api/v1/auth/refresh` | Exchange refresh token for new access token | No (token in body) |

### Transactions

| Method | Endpoint | Description | Auth required |
|--------|----------|-------------|---------------|
| `GET` | `/api/v1/transactions` | Paginated list with optional search | Yes |
| `POST` | `/api/v1/transactions` | Create new transaction | Yes |
| `GET` | `/api/v1/transactions/{id}` | Get single transaction | Yes |
| `PUT` | `/api/v1/transactions/{id}` | Update transaction | Yes |
| `DELETE` | `/api/v1/transactions/{id}` | Delete transaction | Yes |

Query parameters for `GET /transactions`: `?page=1&limit=10&s=searchterm`

### Receipts

| Method | Endpoint | Description | Auth required |
|--------|----------|-------------|---------------|
| `POST` | `/api/v1/transactions/{id}/receipts` | Upload receipt | Yes |
| `GET` | `/api/v1/transactions/{id}/receipts/{receiptId}` | Download receipt file | Yes |
| `DELETE` | `/api/v1/transactions/{id}/receipts/{receiptId}` | Delete receipt | Yes |

Note: No `_METHOD` override needed — Vue uses real `PUT` and `DELETE` HTTP methods via `fetch`/Axios, not HTML form submissions.

---

## 3. JSON Response Structure

All API responses use a consistent envelope:

### Success response
```json
{
  "data": { ... },
  "meta": {
    "page": 1,
    "lastPage": 5,
    "total": 14
  }
}
```

For single resources, `meta` is omitted. For lists, `meta` includes pagination.

### Error response
```json
{
  "error": {
    "code": 422,
    "message": "Validation failed",
    "fields": {
      "email": ["This field is required"],
      "amount": ["Must be numeric"]
    }
  }
}
```

`fields` is only present for validation errors (422). For 401, 403, 404, 500 — only `code` and `message`.

### HTTP status codes used

| Scenario | Code |
|----------|------|
| Successful GET / successful DELETE | 200 |
| Resource created (POST) | 201 |
| Validation error | 422 |
| Unauthenticated | 401 |
| Authenticated but not authorized (wrong user's resource) | 403 |
| Resource not found | 404 |
| Server error | 500 |

---

## 4. How Existing Code Is Extended

### Strategy: Parallel API controllers, shared services

The existing `TransactionController`, `AuthController` etc. handle HTML responses (redirects, template renders). Do not modify them — the web interface stays functional during migration.

Create a new namespace `App\Controllers\Api\` with dedicated API controllers:

```
src/App/Controllers/
├── AuthController.php           ← existing, untouched
├── TransactionController.php    ← existing, untouched
├── ReceiptController.php        ← existing, untouched
└── Api/
    ├── AuthApiController.php    ← new
    ├── TransactionApiController.php  ← new
    └── ReceiptApiController.php      ← new
```

API controllers inject the same service interfaces as the web controllers. The services contain all business logic — the API controller just formats the response as JSON instead of rendering a template.

Example pattern:

```php
class TransactionApiController
{
    public function __construct(
        private TransactionServiceInterface $transactionService
    ) {}

    public function index(): void
    {
        $userId = $this->getAuthenticatedUserId(); // from JWT middleware
        $page = (int) ($_GET['page'] ?? 1);
        $limit = (int) ($_GET['limit'] ?? 10);
        $offset = ($page - 1) * $limit;

        [$transactions, $total] = $this->transactionService->getUserTransactions(
            $limit, $offset, $userId, $_GET['s'] ?? ''
        );

        $this->json([
            'data' => $transactions,
            'meta' => [
                'page' => $page,
                'lastPage' => (int) ceil($total / $limit),
                'total' => $total,
            ]
        ]);
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
```

### New routes registration

Add API routes to `Config/Routes.php` alongside existing routes:

```php
// API routes — no CSRF middleware (JWT auth instead)
$app->get('/api/v1/transactions', [TransactionApiController::class, 'index'])
    ->add(JwtAuthMiddleware::class);
$app->post('/api/v1/transactions', [TransactionApiController::class, 'create'])
    ->add(JwtAuthMiddleware::class);
// ...
```

### New `JwtAuthMiddleware`

Replaces `AuthRequiredMiddleware` for API routes:

```php
class JwtAuthMiddleware implements MiddlewareInterface
{
    public function process(callable $next): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!str_starts_with($header, 'Bearer ')) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => ['code' => 401, 'message' => 'Unauthenticated']]);
            exit;
        }

        $token = substr($header, 7);

        try {
            $payload = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
            $_REQUEST['auth_user_id'] = $payload->sub; // pass to controller
        } catch (\Exception $e) {
            http_response_code(401);
            echo json_encode(['error' => ['code' => 401, 'message' => 'Invalid token']]);
            exit;
        }

        $next();
    }
}
```

### `.env` additions

```
JWT_SECRET=your-32-char-minimum-secret-here
JWT_TTL=900
JWT_REFRESH_TTL=604800
```

---

## 5. CORS Configuration

Vue dev server runs on `localhost:5173` (Vite default). The PHP API runs on `localhost:8080`. CORS headers must be set for cross-origin requests during development.

Add an Apache `Header` directive in `public/.htaccess` or a `CorsMiddleware`:

```php
class CorsMiddleware implements MiddlewareInterface
{
    public function process(callable $next): void
    {
        $allowedOrigins = explode(',', $_ENV['ALLOWED_ORIGINS'] ?? 'http://localhost:5173');
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (in_array($origin, $allowedOrigins)) {
            header("Access-Control-Allow-Origin: {$origin}");
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Credentials: false');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        $next();
    }
}
```

---

## 6. Implementation Checklist

### Prerequisite
- [ ] Interfaces from `docs/01` implemented
- [ ] `firebase/php-jwt` installed via Composer

### Environment
- [ ] Add `JWT_SECRET`, `JWT_TTL`, `JWT_REFRESH_TTL`, `ALLOWED_ORIGINS` to `.env` and `.env.example`

### Infrastructure
- [ ] Create `src/App/Controllers/Api/` directory
- [ ] Create `src/App/Middleware/JwtAuthMiddleware.php`
- [ ] Create `src/App/Middleware/CorsMiddleware.php`
- [ ] Add `CorsMiddleware` to global middleware in `Config/Middleware.php` (must run before routing)
- [ ] Create a `refresh_tokens` table in `database.sql` for refresh token storage

### Auth API
- [ ] Create `src/App/Controllers/Api/AuthApiController.php` (register, login, logout, refresh)
- [ ] Add JWT token generation to `UserService` or a new `TokenService`
- [ ] Register `/api/v1/auth/*` routes in `Config/Routes.php`

### Transactions API
- [ ] Create `src/App/Controllers/Api/TransactionApiController.php` (index, show, create, update, destroy)
- [ ] Register `/api/v1/transactions` routes with `JwtAuthMiddleware`

### Receipts API
- [ ] Create `src/App/Controllers/Api/ReceiptApiController.php` (upload, download, delete)
- [ ] Register `/api/v1/transactions/{id}/receipts` routes with `JwtAuthMiddleware`

### Verification
- [ ] Test all endpoints with `curl` or Postman before connecting Vue
- [ ] Confirm 401 returned for missing/invalid JWT
- [ ] Confirm 403 returned when accessing another user's transaction
- [ ] Confirm validation errors return 422 with `fields` structure
- [ ] Confirm CORS preflight (`OPTIONS`) returns 204 with correct headers
