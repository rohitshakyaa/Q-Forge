# CLAUDE.md

This file provides guidance to Claude (and similar AI assistants) when working with this repository.

---

## 🧠 Project Overview

**QForge** is a smart question paper generation platform built as a **monorepo** with three main services:

- **Laravel (PHP)** → Core application, API, business logic, database
- **Frontend (Vue/React)** → User interface
- **Python (FastAPI)** → Document processing, OCR, parsing, AI-assisted generation

The system follows a **service-oriented architecture** where Laravel is the central orchestrator.

---

## 🏗️ Architecture Principles

### 1. Laravel is the source of truth
- Owns the database
- Owns business logic
- Owns workflows (question bank, blueprints, generation)
- Handles all communication between services

### 2. Python is a processing service
- Does NOT own business logic
- Does NOT own main database
- Only processes data and returns structured results
- Handles:
  - PDF extraction
  - OCR
  - Parsing
  - AI generation
  - Similarity detection

### 3. Frontend is presentation only
- Talks only to Laravel API
- Never talks directly to Python service
- Handles UI, UX, interactions

---

## 🔁 Communication Rules

### Correct flow:
```text
Frontend → Laravel → Python → Laravel → Frontend
````

### ❌ Never:

* Frontend → Python directly
* Python → Database directly (except optional specialized storage)
* Python controlling workflows

---

## 📁 Repository Structure

```text
Q-Forge/
├── code/                  # Laravel app
├── frontend/              # Vue/React app
├── python-service/        # FastAPI service
├── build/                 # Dockerfiles & configs
├── docker-compose.yml
├── Makefile
├── .env
└── CLAUDE.md
```

---

## 🐳 Docker Environment

The project runs fully inside Docker.

### Services:

* `qforge_app` → Laravel (PHP-FPM)
* `qforge_web` → Nginx (entry point)
* `qforge_worker` → queue + scheduler (Supervisor)
* `qforge_frontend` → Vite dev server (Node 24)
* `qforge_python` → FastAPI service
* `qforge_db` → MySQL
* `qforge_redis` → Redis
* `qforge_mailpit` → Mail testing

---

## 🌐 Networking Rules

### Inside Docker:

Use service names:

* Python: `http://qforge_python:8000`
* DB: `qforge_db`
* Redis: `qforge_redis`

### Outside Docker:

Use localhost ports:

* App: `http://localhost:8040`
* Python: `http://localhost:8000`
* Frontend: `http://localhost:5173`

---

## ⚙️ Laravel Conventions

### Key responsibilities:

* API endpoints
* Validation
* Database interaction
* Business logic
* Queue dispatching
* Communication with Python

### Python communication:

Always use a service layer:

```php
Http::baseUrl(config('services.python.base_url'))
    ->post('/endpoint', [...]);
```

### Config:

```php
// config/services.php
'python' => [
    'base_url' => env('PYTHON_SERVICE_URL'),
],
```

### Important:

* Never hardcode Python URLs
* Always use config

---

## 🧩 Python Service Conventions

### Responsibilities:

* Accept structured input
* Return structured JSON output
* No business logic decisions

### Example response format:

```json
{
  "status": "success",
  "data": {...},
  "errors": []
}
```

### API design:

* RESTful
* Predictable
* Typed (Pydantic models preferred)

---

## 🖥️ Frontend Conventions

* Use API via:

  ```js
  import axios from 'axios'
  ```

* Base URL:

  ```env
  VITE_API_BASE_URL=/api
  ```

* Never call Python directly

---

## 🔄 Queue & Background Jobs

Heavy operations must NOT be synchronous.

Use Laravel queues for:

* PDF processing
* OCR
* AI generation
* large dataset operations

Worker runs via:

```bash
php artisan horizon
```

---

## 📊 Horizon

* URL: `/horizon`
* Requires Redis
* Only runs in worker container

---

## 🧪 Testing Strategy

### Laravel:

* Feature tests for API
* Unit tests for services

### Python:

* Endpoint tests
* parsing validation

### Frontend:

* Component testing (optional)
* API integration testing

---

## 🧠 Development Guidelines

### When implementing new features:

Always ask:

1. Does this belong to Laravel or Python?
2. Is this business logic or processing logic?
3. Should this run synchronously or via queue?

---

### Correct placement examples:

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

### Laravel (`code/.env`)

```env
PYTHON_SERVICE_URL=http://qforge_python:8000
```

### Frontend

```env
VITE_API_BASE_URL=/api
```

### Python

```env
LARAVEL_API_URL=http://qforge_web
```

---

## 🛠️ Commands

Use Makefile:

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

## 🚀 How to Verify System Health

### Python:

```bash
curl http://localhost:8000/health
```

### From Laravel container:

```bash
curl http://qforge_python:8000/health
```

### End-to-end:

```bash
http://localhost:8040/test-python
```

---

## 🧭 Design Philosophy

QForge follows a **hybrid system**:

* Rule-based deterministic logic (Laravel)
* AI-assisted augmentation (Python)

AI is **supportive**, not authoritative.

---

## 📌 Summary for AI Assistants

When generating code or suggestions:

* Respect service boundaries
* Keep Laravel as orchestrator
* Keep Python as processor
* Keep frontend decoupled
* Prefer queues for heavy tasks
* Return structured data always
* Avoid shortcuts that break architecture
