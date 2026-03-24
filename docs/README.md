# PHPiggy — Development Roadmap

This folder documents the planned evolution of PHPiggy from a server-rendered PHP application into a PHP REST API + Vue 3 SPA architecture.

Each document covers one phase. Phases must be completed in order — each one is a prerequisite for the next.

---

## Phase Dependency Chain

```
00-architecture-overview    (no dependencies — read first)
        │
        ▼
01-interface-abstraction    (prerequisite for everything below)
        │
        ├──────────────────────┐
        ▼                      ▼
02-phpunit-integration    03-rest-api-layer
        │                      │
        └──────────┬───────────┘
                   ▼
          04-vuejs-integration
                   │
                   ▼
           05-ci-pipeline      (can start pipeline setup after 01 + 02)
```

**Why this order:**
- Interfaces (01) must exist before tests (02) can mock correctly and before the API (03) can bind against contracts
- Tests (02) and the API layer (03) can proceed in parallel once interfaces are done
- Vue (04) depends on the API being built and tested
- CI (05) needs PHPUnit (02) and Vue build scripts (04) to have something meaningful to run; basic pipeline setup can start after 01

---

## Documents

| File | Phase | Description | Status |
|------|-------|-------------|--------|
| [00-architecture-overview.md](00-architecture-overview.md) | Reference | Current codebase — request lifecycle, DI container, router pipeline, Database/Statement layer, strengths and weaknesses | Complete |
| [01-interface-abstraction.md](01-interface-abstraction.md) | Phase 1 | Introduce interfaces for all services and infrastructure; update DI bindings; fix `$_GET` in service layer | `[ ]` |
| [02-phpunit-integration.md](02-phpunit-integration.md) | Phase 2 | PHPUnit setup, test priority list, example test, mocking strategy | `[ ]` |
| [03-rest-api-layer.md](03-rest-api-layer.md) | Phase 3 | JWT auth, REST endpoint design, API controllers, JSON response conventions, CORS | `[ ]` |
| [04-vuejs-integration.md](04-vuejs-integration.md) | Phase 4 | Vue 3 SPA with Vite, Pinia state management, Axios API client, view migration order | `[ ]` |
| [05-ci-pipeline.md](05-ci-pipeline.md) | Phase 5 | GitHub Actions: PHP lint + test + Vue build in Docker | `[ ]` |

---

## Overall Progress

- [ ] **Phase 1** — Interface abstraction complete; all services implement contracts; container bindings updated
- [ ] **Phase 2** — PHPUnit installed; validation rule tests passing; service unit tests passing
- [ ] **Phase 3** — JWT auth working; all REST endpoints returning correct JSON; Postman collection verified
- [ ] **Phase 4** — Vue SPA built and served by Apache; all views migrated; PHP templates no longer the primary UI
- [ ] **Phase 5** — CI pipeline passing on `main` and `develop`; branch protection enabled

---

## Key Technical Decisions (summary)

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Frontend architecture | Vue 3 SPA + Vite | PHPiggy is entirely behind auth — SSR/SEO irrelevant; clean separation of concerns |
| State management | Pinia | Official Vue 3 standard; simpler than Vuex; better TypeScript support |
| API auth | JWT (access + refresh tokens) | SPA client; stateless; works cross-origin without cookie/CORS complexity |
| JWT library | `firebase/php-jwt` | Standard PHP JWT library; well-maintained |
| Test strategy | Unit tests first, integration later | Services are the high-value layer; interfaces enable mocking without a real database |
| CI environment | Docker (same as dev) | No environment drift between local, CI, and production |
| PHP code style | PSR-12 via PHP_CodeSniffer | Project already uses PSR-4 and strict types; PSR-12 is the natural complement |
