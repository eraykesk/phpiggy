# 05 — CI Pipeline

> GitHub Actions pipeline that lints, tests, and builds on every push. All PHP execution happens inside the Docker environment to match production.

---

## 1. Pipeline Overview

```
Push / Pull Request
        │
        ├── [Job 1] php-lint-and-test
        │       ├── Start Docker Compose (app + db)
        │       ├── Wait for MySQL healthcheck
        │       ├── Run PHP_CodeSniffer (PSR-12)
        │       └── Run PHPUnit
        │
        └── [Job 2] vue-build (runs in parallel with Job 1)
                ├── npm install
                ├── ESLint
                ├── TypeScript check (vue-tsc)
                └── npm run build
```

Jobs run in parallel. A failing lint in either job blocks merge.

---

## 2. GitHub Actions Workflow File

```yaml
# .github/workflows/ci.yml
name: CI

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main, develop]

jobs:
  php-lint-and-test:
    name: PHP — Lint & Test
    runs-on: ubuntu-latest

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Start containers
        run: docker compose up -d --build

      - name: Wait for MySQL to be healthy
        run: |
          echo "Waiting for MySQL..."
          for i in $(seq 1 30); do
            if docker compose exec -T db mysqladmin ping -h localhost -u phpiggy -psecret --silent; then
              echo "MySQL ready"
              break
            fi
            sleep 2
          done

      - name: Install PHP dependencies
        run: docker compose exec -T app composer install --no-interaction --prefer-dist

      - name: PHP CodeSniffer (PSR-12)
        run: docker compose exec -T app ./vendor/bin/phpcs --standard=PSR12 src/

      - name: PHPUnit
        run: docker compose exec -T app ./vendor/bin/phpunit --colors=never

      - name: Stop containers
        if: always()
        run: docker compose down -v

  vue-build:
    name: Vue — Lint & Build
    runs-on: ubuntu-latest

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'
          cache-dependency-path: frontend/package-lock.json

      - name: Install dependencies
        working-directory: frontend
        run: npm ci

      - name: ESLint
        working-directory: frontend
        run: npm run lint

      - name: TypeScript check
        working-directory: frontend
        run: npm run type-check

      - name: Build
        working-directory: frontend
        run: npm run build
```

---

## 3. Running Tests Inside Docker

The key decision is **not** to install PHP on the CI runner. All PHP work goes through `docker compose exec -T`:

- `-T` disables pseudo-TTY allocation (required in CI environments where there is no interactive terminal)
- The Docker image built in CI is identical to the development image — no environment drift

### Why not a separate test database?

The `docker-compose.yml` already provisions MySQL with `database.sql` as an init script. The same setup used in development runs in CI. Integration tests (future) will hit this real database, matching the exact schema.

For unit tests (the current focus), no database is needed — services are tested with mocked `DatabaseInterface`.

---

## 4. PHP CodeSniffer Setup

Install in the project:

```bash
docker compose exec app composer require --dev squizlabs/php_codesniffer
```

Create `phpcs.xml` in project root to configure scope and exclusions:

```xml
<?xml version="1.0"?>
<ruleset name="PHPiggy">
    <description>PSR-12 for PHPiggy</description>
    <rule ref="PSR12"/>
    <file>src/</file>
    <exclude-pattern>src/App/views/*</exclude-pattern>
    <arg name="colors"/>
    <arg value="sp"/>
</ruleset>
```

Views are excluded because they mix PHP and HTML — PSR-12 rules don't apply meaningfully to template files.

---

## 5. Branch Strategy

```
main          ← production-ready code only; protected branch
  │
  └── develop ← integration branch; all features merge here first
        │
        ├── feature/jwt-auth
        ├── feature/api-transactions
        ├── feature/vue-login
        └── fix/receipt-mime-validation
```

### Rules

| Branch | Who pushes | CI runs | Merges to |
|--------|-----------|---------|-----------|
| `feature/*` | Developer directly | On push | `develop` via PR |
| `develop` | PR merge only | On push + PR | `main` via PR |
| `main` | PR merge only | On push + PR | — |

### Pull Request requirements (set in GitHub branch protection)
- At least 1 approving review
- All CI checks must pass
- No direct push to `main` or `develop`

This prevents broken code from reaching `main` and ensures every change goes through at least a lint + test check.

---

## 6. Future Pipeline Additions

Once the project matures, add these stages:

| Stage | Tool | When to add |
|-------|------|-------------|
| Static analysis | PHPStan (level 5+) | After interfaces are complete |
| Security audit | `composer audit` | Immediately — runs in seconds |
| Coverage report | PHPUnit + Coveralls/Codecov | After test suite has meaningful coverage |
| Docker image push | GHCR or Docker Hub | When deploying to a real server |
| Vue unit tests | Vitest + Vue Test Utils | After first Vue components exist |

`composer audit` is worth adding immediately — it checks installed packages against known CVE databases and takes ~2 seconds:

```yaml
- name: Security audit
  run: docker compose exec -T app composer audit
```

---

## 7. Implementation Checklist

### Prerequisites
- [ ] PHPUnit installed and tests passing (docs/02)
- [ ] PHP_CodeSniffer installed (`squizlabs/php_codesniffer`)
- [ ] Vue project bootstrapped with `npm run lint` and `npm run build` scripts (docs/04)

### Repository setup
- [ ] Create `.github/workflows/` directory
- [ ] Create `.github/workflows/ci.yml` with content from §2 above
- [ ] Create `phpcs.xml` in project root
- [ ] Enable branch protection on `main`: require CI pass + 1 review
- [ ] Enable branch protection on `develop`: require CI pass

### Validate
- [ ] Push a branch → both jobs appear in GitHub Actions tab
- [ ] Introduce a PSR-12 violation → `php-lint-and-test` job fails
- [ ] Fix violation → job passes
- [ ] Introduce a failing PHPUnit test → job fails
- [ ] Merge a PR to `develop` → pipeline runs on `develop`

### Optional enhancements
- [ ] Add `composer audit` step to `php-lint-and-test` job
- [ ] Add PHPStan after interfaces are in place
- [ ] Add Vitest step to `vue-build` job once Vue components exist
