# QForge — Conventions & Reference

Detailed conventions for working in this repo. The short, must-obey rules live in
[`../CLAUDE.md`](../CLAUDE.md); this file holds the full reference.

---

## 🐳 Docker Environment

The project runs fully inside Docker. Services:

* `qforge_app` → Laravel (PHP-FPM)
* `qforge_web` → Nginx (entry point)
* `qforge_worker` → queue + scheduler (Supervisor / Horizon)
* `qforge_frontend` → Vite dev server (Node 24)
* `qforge_python` → FastAPI service
* `qforge_ollama` → local LLM (added in M5)
* `qforge_db` → MySQL
* `qforge_redis` → Redis
* `qforge_mailpit` → Mail testing

---

## 🌐 Networking Rules

**Inside Docker** — use service names:

* Python: `http://qforge_python:8000`
* DB: `qforge_db`
* Redis: `qforge_redis`

**Outside Docker** — use localhost ports:

* App: `http://localhost:8040`
* Python: `http://localhost:8000`
* Frontend: `http://localhost:5173`

---

## ⚙️ Laravel Conventions

Key responsibilities: API endpoints, validation, database interaction, business logic, queue
dispatching, communication with Python.

Python communication — always go through a service layer, never hardcode URLs:

```php
Http::baseUrl(config('services.python.base_url'))
    ->post('/endpoint', [...]);
```

```php
// config/services.php
'python' => [
    'base_url' => env('PYTHON_SERVICE_URL'),
],
```

---

## 🧩 Python Service Conventions

* Accept structured input, return structured JSON output, make no business-logic decisions.
* RESTful, predictable, typed (Pydantic models preferred).

Response format:

```json
{
  "status": "success",
  "data": {},
  "errors": []
}
```

---

## 🖥️ Frontend Conventions

* Use the shared axios client (`frontend/src/api/client/axios.ts`); import `axios` only there.
* Base URL: `VITE_API_BASE_URL=/api`.
* Never call Python directly.

---

## 🔄 Queue & Background Jobs

Heavy operations must NOT run synchronously. Use Laravel queues (Redis + Horizon) for:

* PDF processing
* OCR
* AI generation
* large dataset operations

Worker runs via `php artisan horizon` (worker container only).

## 📊 Horizon

* URL: `/horizon` (admin-only)
* Requires Redis

---

## 🧪 Testing Strategy

* **Laravel:** feature tests for API, unit tests for services (the generation algorithm is the gate).
* **Python:** endpoint tests + parsing validation.
* **Frontend:** component testing (optional), API integration testing.

---

## 🧠 Development Guidelines

When implementing a new feature, always ask:

1. Does this belong to Laravel or Python?
2. Is this business logic or processing logic?
3. Should this run synchronously or via queue?

Correct placement:

| Task                      | Location                      |
| ------------------------- | ----------------------------- |
| Blueprint validation      | Laravel                       |
| Question generation rules | Laravel                       |
| PDF parsing               | Python                        |
| OCR                       | Python                        |
| UI interactions           | Frontend                      |
| API validation            | Laravel                       |
| AI generation             | Python (triggered by Laravel) |

---

## ⚠️ Common Mistakes to Avoid

❌ Calling Python directly from frontend
❌ Putting business logic in Python
❌ Using `localhost` inside Docker containers
❌ Mixing frontend/backend responsibilities
❌ Running heavy tasks synchronously
❌ Storing core data in Python

---

## 🔐 Environment Variables

```env
# Laravel (code/.env)
PYTHON_SERVICE_URL=http://qforge_python:8000

# Frontend
VITE_API_BASE_URL=/api

# Python
LARAVEL_API_URL=http://qforge_web
```

---

## 🛠️ Commands

### Running commands inside containers

Docker needs `sudo` on this machine, and project commands (artisan, composer, npm, pytest, etc.)
must run **inside** the relevant container via `docker compose exec` — not on the host. Pattern:

```bash
sudo docker compose exec <service> <command>
```

The host sudo password is **`rohitshakya`** (local dev box). For non-interactive runs, pipe it in:

```bash
echo 'rohitshakya' | sudo -S docker compose ps           # check container status
echo 'rohitshakya' | sudo -S docker compose up -d        # start the stack
```

> ⚠️ This password is documented for local development convenience only. Do not reuse it elsewhere,
> and rotate it (and scrub this note) if the box is ever exposed or the repo is published.

Common examples (service names from the Docker Environment section):

```bash
sudo docker compose exec qforge_app php artisan migrate         # run migrations
sudo docker compose exec qforge_app php artisan test            # run Laravel tests
sudo docker compose exec qforge_app composer install            # PHP deps
sudo docker compose exec qforge_python pytest                   # Python tests
sudo docker compose exec qforge_frontend npm run dev            # frontend
sudo docker compose exec qforge_worker php artisan horizon      # queue worker
```

### Makefile shortcuts

```bash
make up
make rebuild
make logs-app
make logs-python
make sh-app
make sh-frontend
make sh-python
```

---

## 🚀 Verify System Health

```bash
curl http://localhost:8000/health                 # Python (host)
curl http://qforge_python:8000/health              # Python (from Laravel container)
curl http://localhost:8040/api/health              # Laravel API
curl http://localhost:8040/api/test-python         # Laravel -> Python connectivity
# http://localhost:8040/horizon                    # queue dashboard (admin-only)
```

---

## 🧭 Design Philosophy

QForge is a **hybrid system**: rule-based deterministic logic (Laravel) + AI-assisted augmentation
(Python). AI is **supportive, not authoritative** — the algorithm always controls the final paper.
