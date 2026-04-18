# QForge

QForge is a smart question paper generation platform that combines a Laravel backend, a modern frontend, and a Python processing service for document extraction, OCR, parsing, and AI-assisted workflows.

It is built as a monorepo so the full system can be developed, run, and versioned together.

---

## Project Structure

```text
Q-Forge/
├── code/                  # Laravel application
├── frontend/              # Vue or React frontend
├── python-service/        # Python API/service for OCR, parsing, AI
├── build/                 # Dockerfiles, nginx, supervisor, configs
├── docker-compose.yml
├── Makefile
├── .env                   # Docker environment
├── .env.example
└── README.md
````


## Architecture Overview

The system is split into three main application parts:

### Laravel (`code/`)

Laravel is the main application layer and source of truth. It handles:

* authentication and authorization
* database and business logic
* question bank management
* blueprint management
* generated paper history
* API endpoints for the frontend
* communication with the Python service

### Frontend (`frontend/`)

The frontend is the user-facing application. It handles:

* dashboards
* forms and flows
* blueprint builder
* paper generation screens
* question bank UI
* admin and teacher experiences

### Python Service (`python-service/`)

The Python service handles processing and AI-oriented tasks such as:

* PDF text extraction
* OCR
* parsing extracted questions
* similarity / duplicate support
* AI-assisted question generation

---

## Communication Flow

Typical application flow:

1. User interacts with the frontend
2. Frontend calls Laravel API
3. Laravel stores and manages data
4. Laravel calls Python service when processing is needed
5. Python returns structured results to Laravel
6. Laravel saves results and returns final response to frontend

### Rule of ownership

* Laravel owns the main database
* Python processes data but does not own the main application state
* Frontend only interacts with Laravel, not Python directly

---

## Requirements

You need:

* Docker
* Docker Compose
* GNU Make

Optional but useful:

* VS Code
* Dev Containers extension
* Postman / Insomnia
* Git

---

## Environment Files

This repo uses multiple environment files.

### 1. Root `.env`

Used by Docker Compose for container names, ports, versions, and build configuration.

### 2. `code/.env`

Used by Laravel.

### 3. `frontend/.env`

Used by the frontend app.

### 4. `python-service/.env`

Used by the Python service.

---

## Getting Started

### 1. Clone the repository

```bash
git clone <your-repository-url>
cd Q-Forge
```

### 2. Copy environment files

```bash
cp .env.example .env
cp code/.env.example code/.env
cp frontend/.env.example frontend/.env
cp python-service/.env.example python-service/.env
```

### 3. Build and start containers

```bash
make up
```

If running for the first time or after major Docker changes:

```bash
make rebuild
```

### 4. Install Laravel dependencies

```bash
make composer-install
```

### 5. Generate Laravel app key

```bash
make key
```

### 6. Run migrations

```bash
make migrate
```

### 7. Install frontend dependencies

```bash
make npm-install
```

### 8. Verify Python service

```bash
make python-health
```

---

## Default Local URLs

Depending on your root `.env`, the local URLs are typically:

* App / Frontend through nginx: `http://localhost:8040`
* Laravel API: `http://localhost:8040/api`
* Python service direct: `http://localhost:8000`
* Mailpit dashboard: `http://localhost:8025`

Examples:

* `http://localhost:8040`
* `http://localhost:8040/api`
* `http://localhost:8000/health`

---

## Common Commands

### Docker

```bash
make up
make down
make restart
make build
make rebuild
make ps
make logs
```

### Logs

```bash
make logs-app
make logs-web
make logs-worker
make logs-frontend
make logs-python
```

### Shell Access

```bash
make sh-app
make sh-worker
make sh-frontend
make sh-python
make sh-db
```

### Laravel

```bash
make composer-install
make composer-update
make key
make migrate
make fresh
make seed
make rollback
make clear
make optimize
make queue-restart
make art cmd="route:list"
```

### Frontend

```bash
make npm-install
make front cmd="dev"
make front cmd="build"
```

### Python

```bash
make pip
make python-health
make py cmd="python -V"
```

---

## Service-to-Service Communication

Laravel should communicate with Python using the internal Docker service name.

Example Laravel config:

```env
PYTHON_SERVICE_URL=http://qforge_python:8000
```

Inside Docker:

* use `qforge_python:8000`
* do not use `localhost:8000` from Laravel container

Host machine access:

* Laravel API: `http://localhost:8040/api`
* Python direct: `http://localhost:8000`

---

## Horizon

If Laravel Horizon is enabled, it is typically available at:

```text
http://localhost:8040/horizon
```

Make sure:

* Redis is configured
* Horizon is installed in Laravel
* worker container runs `php artisan horizon`
* nginx forwards `/horizon` to Laravel

---

## Development Notes

### Frontend packages

Install frontend packages in the frontend container or in the `frontend/` directory only.

Example:

```bash
sudo docker compose exec qforge_frontend npm install axios
```

### Python packages

Install Python packages through `requirements.txt`.

### Laravel config cache

If environment/config changes are not reflecting:

```bash
make clear
```

or:

```bash
make art cmd="optimize:clear"
```

---

## Troubleshooting

### Laravel cannot talk to Python

Check from inside the Laravel container:

```bash
curl http://qforge_python:8000/health
```

### Frontend cannot resolve a package

Check inside the frontend container:

```bash
sudo docker compose exec qforge_frontend npm list <package-name>
```

### Containers keep restarting

Inspect logs:

```bash
make logs-app
make logs-web
make logs-worker
make logs-python
```

### Docker without sudo does not work

Your Docker context or permissions may be wrong. Check:

```bash
docker context ls
docker context use default
```

If needed:

```bash
sudo usermod -aG docker $USER
newgrp docker
```

---

## Git Strategy

This project uses a monorepo approach because Laravel, frontend, and Python are all parts of a single product and evolve together.

Benefits of this structure:

* easier local setup
* easier coordinated development
* simpler Docker/devops management
* cleaner feature-level commits across services

---

## Recommended Commit Style

Suggested prefixes:

* `feat:` new feature
* `fix:` bug fix
* `refactor:` internal cleanup
* `docs:` documentation
* `chore:` maintenance / tooling / Docker
* `test:` tests

Examples:

```text
feat: add blueprint builder API and frontend form
fix: correct python health check URL in Laravel config
chore: update docker worker and nginx config
```

---

## Future Improvements

Possible additions later:

* CI pipeline for Laravel, frontend, and Python tests
* production Docker setup
* devcontainer support
* shared API schemas between Laravel and Python
* OpenAPI documentation for Python service
* typed frontend API client

---

## License

Add your preferred license here.

````

---

## A few small files you should also keep

These are useful too:

### `frontend/.env.example`

```env
VITE_API_BASE_URL=/api
````

### `python-service/.env.example`

```env
LARAVEL_API_URL=http://qforge_web
SHARED_STORAGE_PATH=/shared-storage
PYTHONUNBUFFERED=1
```

### root `.env.example`

```env
APP_NAME=QForge

CONTAINER_NAME_PREFIX=qforge_
NETWORK=qforge_local

PROJECT_PATH=/var/www
USERID=1000

PHP_FPM_VERSION=8.5-fpm-bookworm
MYSQL_VERSION=8.4

NGINX_HTTP_PORT=8040
FRONTEND_PORT=5173
PYTHON_PORT=8000

HOST_DB_PORT=3307
FORWARD_REDIS_PORT=6379
FORWARD_MAILPIT_PORT=1025
FORWARD_MAILPIT_DASHBOARD_PORT=8025

MYSQL_DATABASE=qforge_db
MYSQL_ROOT_PASSWORD=root
MYSQL_USER=qforge
MYSQL_USER_PASSWORD=secret
```
