# 04 — Vue.js Integration

> Replacing the PHP template views with a Vue 3 SPA served by the PHP backend.

---

## 1. Architecture Decision: SPA vs Nuxt.js SSR vs Islands

### Options evaluated

**Option A: Nuxt.js (SSR)**
- Node.js server renders Vue pages on the server, hydrates on client
- Pros: SEO, fast first paint for public pages
- Cons: Requires a running Node.js process alongside PHP; added infrastructure complexity; PHPiggy is entirely behind authentication — zero public-facing pages benefit from SSR

**Option B: Vue 3 SPA with Vite (Islands / Progressive)**
- Replace individual PHP views one at a time; mount Vue components into specific DOM nodes
- Pros: Incremental migration; low risk; PHP views remain functional during transition
- Cons: Multiple entry points to maintain; shared state between islands is awkward

**Option C: Vue 3 Full SPA with Vite (Recommended)**
- Single Vue app handles all routing; PHP serves only the API and the compiled `index.html`
- Pros: Clean separation; single routing layer (Vue Router); natural fit for Pinia state; standard SPA architecture employers recognise
- Cons: Full migration required before Vue handles any page; PHP templates stay alive in parallel during transition

### Recommendation: **Option C — Vue 3 Full SPA with Vite**

**Justification:**
1. PHPiggy has no public pages — every route requires authentication. SSR's SEO benefit is irrelevant.
2. The PHP template layer is straightforward (no complex server-side logic in views — views only output HTML). A clean cut is practical.
3. Vite is the current standard build tool for Vue 3. Fast HMR, first-class TypeScript support, small bundle output.
4. A full SPA produces a single `dist/` folder. Apache can serve `dist/index.html` as the entry point for all frontend routes.
5. This architecture is what you would encounter in a professional job — a PHP (or any language) API backend + a separate SPA frontend.

---

## 2. Vue Project Structure

The Vue app lives in a `frontend/` directory at the project root, alongside `src/` and `public/`:

```
phpiggy/
├── frontend/                       ← Vue 3 + Vite project
│   ├── index.html
│   ├── vite.config.ts
│   ├── tsconfig.json
│   ├── package.json
│   ├── src/
│   │   ├── main.ts                 ← mounts App.vue, registers router + pinia
│   │   ├── App.vue                 ← root component, <RouterView />
│   │   ├── router/
│   │   │   └── index.ts            ← Vue Router routes, navigation guards
│   │   ├── stores/
│   │   │   ├── auth.ts             ← Pinia: user, token, login/logout actions
│   │   │   └── transactions.ts     ← Pinia: transaction list, pagination, search
│   │   ├── api/
│   │   │   ├── client.ts           ← Axios instance, base URL, interceptors
│   │   │   ├── auth.ts             ← register, login, logout, refresh calls
│   │   │   ├── transactions.ts     ← CRUD calls
│   │   │   └── receipts.ts         ← upload, download, delete calls
│   │   ├── components/
│   │   │   ├── layout/
│   │   │   │   ├── AppHeader.vue
│   │   │   │   └── AppFooter.vue
│   │   │   ├── transactions/
│   │   │   │   ├── TransactionTable.vue
│   │   │   │   ├── TransactionRow.vue
│   │   │   │   ├── TransactionForm.vue     ← shared create/edit form
│   │   │   │   └── TransactionSearch.vue
│   │   │   ├── receipts/
│   │   │   │   ├── ReceiptUpload.vue
│   │   │   │   └── ReceiptItem.vue
│   │   │   └── ui/
│   │   │       ├── BaseButton.vue
│   │   │       ├── BaseInput.vue
│   │   │       └── FormError.vue
│   │   └── views/
│   │       ├── LoginView.vue
│   │       ├── RegisterView.vue
│   │       ├── HomeView.vue            ← transaction list + pagination
│   │       ├── TransactionCreateView.vue
│   │       ├── TransactionEditView.vue
│   │       └── AboutView.vue
│   └── dist/                           ← built output (gitignored)
├── public/                             ← PHP web root
│   ├── .htaccess
│   ├── index.php                       ← PHP entry point (API requests)
│   └── assets/                         ← Vue built assets go here (Vite output dir)
└── src/                                ← PHP source
```

### Vite output configuration

`frontend/vite.config.ts` should output to `public/assets/` so Apache serves built assets without extra config:

```typescript
export default defineConfig({
  build: {
    outDir: '../public/assets',
    emptyOutDir: true,
  }
})
```

The `index.html` entry point moves to `public/index.html` (replacing the PHP one for SPA routing) — or Apache is configured to serve `public/assets/index.html` for non-API routes.

---

## 3. Apache Routing for SPA

The `.htaccess` needs two rules: API requests go to `index.php`, all other requests serve the Vue `index.html`:

```apache
RewriteEngine On

# API requests → PHP
RewriteCond %{REQUEST_URI} ^/api
RewriteRule ^ /index.php [L]

# Static assets → serve directly
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^ - [L]

# Everything else → Vue SPA index.html
RewriteRule ^ /assets/index.html [L]
```

---

## 4. API Communication

### Axios instance (`frontend/src/api/client.ts`)

```typescript
import axios from 'axios'
import { useAuthStore } from '@/stores/auth'

const client = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1',
  headers: { 'Content-Type': 'application/json' },
})

// Attach JWT to every request
client.interceptors.request.use((config) => {
  const auth = useAuthStore()
  if (auth.token) {
    config.headers.Authorization = `Bearer ${auth.token}`
  }
  return config
})

// Handle 401 globally — redirect to login
client.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      const auth = useAuthStore()
      auth.logout()
    }
    return Promise.reject(error)
  }
)

export default client
```

The `VITE_API_BASE_URL` environment variable allows switching between development (`localhost:8080`) and production without code changes.

### `.env` for Vue dev

```
VITE_API_BASE_URL=http://localhost:8080/api/v1
```

---

## 5. State Management: Pinia

**Pinia** is the official Vue 3 state management library (replaced Vuex). Simpler API, better TypeScript support, no mutations boilerplate.

### Auth store (`frontend/src/stores/auth.ts`)

```typescript
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { login as apiLogin, logout as apiLogout } from '@/api/auth'

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem('token'))
  const isAuthenticated = computed(() => !!token.value)

  async function login(email: string, password: string) {
    const { data } = await apiLogin(email, password)
    token.value = data.data.accessToken
    localStorage.setItem('token', token.value)
  }

  function logout() {
    token.value = null
    localStorage.removeItem('token')
  }

  return { token, isAuthenticated, login, logout }
})
```

### Transactions store (`frontend/src/stores/transactions.ts`)

Manages the list, pagination state, and search term. Components call store actions; the store calls the API module.

---

## 6. Navigation Guards

Vue Router guards protect authenticated routes — mirrors what `AuthRequiredMiddleware` does on the PHP side:

```typescript
router.beforeEach((to) => {
  const auth = useAuthStore()
  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login' }
  }
  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { name: 'home' }
  }
})
```

---

## 7. Migration Order

Replace views in this order — from simplest to most complex:

| Phase | Views to replace | Why this order |
|-------|-----------------|----------------|
| 1 | `LoginView`, `RegisterView` | No data fetching — just forms posting to API |
| 2 | `AboutView` | Static content — trivial |
| 3 | `TransactionCreateView` | Single form POST — no pre-fetched data |
| 4 | `HomeView` (transaction list) | Requires GET + pagination + search |
| 5 | `TransactionEditView` | Requires GET single + PUT |
| 6 | Receipt upload/download | File handling — most complex |

At each phase, the corresponding PHP view can remain in place as a fallback until the Vue equivalent is verified.

---

## 8. Development Workflow

Run both servers simultaneously during development:

```bash
# Terminal 1 — PHP API
docker compose up

# Terminal 2 — Vue dev server with HMR
cd frontend && npm run dev
```

Vue dev server proxies API calls to `localhost:8080`. Production: `npm run build` outputs to `public/assets/`.

---

## 9. Implementation Checklist

### Bootstrap
- [ ] `cd frontend && npm create vue@latest .` (select: TypeScript, Vue Router, Pinia, ESLint)
- [ ] Install Axios: `npm install axios`
- [ ] Install Tailwind CSS for Vue: `npm install -D tailwindcss @tailwindcss/vite`
- [ ] Configure `vite.config.ts` output directory to `../public/assets`
- [ ] Create `.env` with `VITE_API_BASE_URL`
- [ ] Update `public/.htaccess` with SPA routing rules

### API Client
- [ ] Create `src/api/client.ts` with Axios instance + interceptors
- [ ] Create `src/api/auth.ts`
- [ ] Create `src/api/transactions.ts`
- [ ] Create `src/api/receipts.ts`

### Stores
- [ ] Create `src/stores/auth.ts` with login/logout/token persistence
- [ ] Create `src/stores/transactions.ts`

### Router
- [ ] Configure `src/router/index.ts` with all routes and meta flags
- [ ] Add `beforeEach` navigation guard

### Views — Phase 1 (auth)
- [ ] Build `LoginView.vue` — calls `authStore.login()`, redirects to home
- [ ] Build `RegisterView.vue` — posts to register endpoint, handles 422 field errors
- [ ] Test: login with wrong credentials shows error; login with correct redirects to home

### Views — Phase 2 & 3 (static + create)
- [ ] Build `AboutView.vue`
- [ ] Build `TransactionCreateView.vue`

### Views — Phase 4 & 5 (list + edit)
- [ ] Build `HomeView.vue` with search, pagination, transaction table
- [ ] Build `TransactionEditView.vue`

### Views — Phase 6 (receipts)
- [ ] Build `ReceiptUpload.vue` with `multipart/form-data` POST
- [ ] Build receipt download link and delete button in `TransactionRow.vue`

### Production Build
- [ ] `npm run build` outputs to `public/assets/`
- [ ] `docker compose up` serves both API and built Vue app from Apache
- [ ] Verify SPA routing: navigating directly to `/transaction` in browser works (Apache rewrites to `index.html`)
