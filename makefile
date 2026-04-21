SHELL := /bin/bash

APP_SERVICE = qforge_app
WORKER_SERVICE = qforge_worker
WEB_SERVICE = qforge_web
FRONTEND_SERVICE = qforge_frontend
PYTHON_SERVICE = qforge_python
DB_SERVICE = qforge_db
REDIS_SERVICE = qforge_redis

DOCKER_COMPOSE = sudo docker compose

.PHONY: help up down restart build rebuild stop ps logs logs-app logs-worker logs-frontend logs-python \
	sh-app sh-worker sh-frontend sh-python sh-db \
	composer composer-install composer-update \
	artisan key migrate fresh seed rollback optimize clear cache route-clear config-clear view-clear queue-restart \
	npm npm-install npm-dev npm-build \
	pip python-health \
	test pint phpstan \
	permissions fresh-start prune

help:
	@echo ""
	@echo "QForge Make Commands"
	@echo ""
	@echo "Docker"
	@echo "  make up               Start all containers"
	@echo "  make down             Stop all containers"
	@echo "  make stop             Alias for down"
	@echo "  make restart          Restart all containers"
	@echo "  make build            Build containers"
	@echo "  make rebuild          Rebuild containers without cache"
	@echo "  make ps               Show running containers"
	@echo "  make logs             Show logs from all containers"
	@echo "  make logs-app         Show Laravel app logs"
	@echo "  make logs-worker      Show worker logs"
	@echo "  make logs-frontend    Show frontend logs"
	@echo "  make logs-python      Show Python logs"
	@echo ""
	@echo "Shell"
	@echo "  make sh-app           Open shell in Laravel app container"
	@echo "  make sh-worker        Open shell in worker container"
	@echo "  make sh-frontend      Open shell in frontend container"
	@echo "  make sh-python        Open shell in Python container"
	@echo "  make sh-db            Open MySQL shell"
	@echo ""
	@echo "Laravel"
	@echo "  make composer-install Install PHP dependencies"
	@echo "  make composer-update  Update PHP dependencies"
	@echo "  make key              Generate Laravel app key"
	@echo "  make migrate          Run migrations"
	@echo "  make fresh            Fresh migrate"
	@echo "  make seed             Run seeders"
	@echo "  make rollback         Rollback migrations"
	@echo "  make optimize         Optimize Laravel"
	@echo "  make clear            Clear Laravel caches"
	@echo "  make queue-restart    Restart Laravel queue workers"
	@echo ""
	@echo "Frontend"
	@echo "  make npm-install      Install frontend dependencies"
	@echo "  make npm-dev          Run frontend dev server"
	@echo "  make npm-build        Build frontend"
	@echo ""
	@echo "Python"
	@echo "  make pip              Install Python dependencies"
	@echo "  make python-health    Check Python service health"
	@echo ""
	@echo "Quality"
	@echo "  make test             Run Laravel tests"
	@echo "  make pint             Run Laravel Pint"
	@echo "  make phpstan          Run PHPStan"
	@echo ""
	@echo "Utilities"
	@echo "  make permissions      Fix Laravel storage/bootstrap permissions"
	@echo "  make fresh-start      Full rebuild + migrate + seed"
	@echo "  make prune            Remove unused Docker data"
	@echo ""

up:
	$(DOCKER_COMPOSE) up -d

down:
	$(DOCKER_COMPOSE) down

stop: down

restart:
	$(DOCKER_COMPOSE) down
	$(DOCKER_COMPOSE) up -d

build:
	$(DOCKER_COMPOSE) build

rebuild:
	$(DOCKER_COMPOSE) build --no-cache
	$(DOCKER_COMPOSE) up -d

ps:
	$(DOCKER_COMPOSE) ps

logs:
	$(DOCKER_COMPOSE) logs -f

logs-app:
	$(DOCKER_COMPOSE) logs -f $(APP_SERVICE)

logs-worker:
	$(DOCKER_COMPOSE) logs -f $(WORKER_SERVICE)

logs-frontend:
	$(DOCKER_COMPOSE) logs -f $(FRONTEND_SERVICE)

logs-python:
	$(DOCKER_COMPOSE) logs -f $(PYTHON_SERVICE)

logs-web:
	$(DOCKER_COMPOSE) logs -f $(WEB_SERVICE)

sh-app:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) bash

sh-worker:
	$(DOCKER_COMPOSE) exec $(WORKER_SERVICE) bash

sh-frontend:
	$(DOCKER_COMPOSE) exec $(FRONTEND_SERVICE) sh

sh-python:
	$(DOCKER_COMPOSE) exec $(PYTHON_SERVICE) sh

sh-db:
	$(DOCKER_COMPOSE) exec $(DB_SERVICE) mysql -uroot -p

restart-frontend:
	$(DOCKER_COMPOSE) restart $(FRONTEND_SERVICE)

composer:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) composer

composer-install:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) composer install

composer-update:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) composer update

artisan:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) php artisan

key:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) php artisan key:generate

migrate:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) php artisan migrate

fresh:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) php artisan migrate:fresh

seed:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) php artisan db:seed

rollback:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) php artisan migrate:rollback

optimize:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) php artisan optimize

clear:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) php artisan optimize:clear

cache:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) php artisan cache:clear

route-clear:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) php artisan route:clear

config-clear:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) php artisan config:clear

view-clear:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) php artisan view:clear

queue-restart:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) php artisan queue:restart

npm:
	$(DOCKER_COMPOSE) exec $(FRONTEND_SERVICE) npm

npm-install:
	$(DOCKER_COMPOSE) exec $(FRONTEND_SERVICE) npm install

npm-dev:
	$(DOCKER_COMPOSE) exec $(FRONTEND_SERVICE) npm run dev

npm-build:
	$(DOCKER_COMPOSE) exec $(FRONTEND_SERVICE) npm run build

pip:
	$(DOCKER_COMPOSE) exec $(PYTHON_SERVICE) pip install -r requirements.txt

python-health:
	curl http://localhost:8000/health

test:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) php artisan test

pint:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) ./vendor/bin/pint

phpstan:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) ./vendor/bin/phpstan analyse

permissions:
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) sh -c "chmod -R 775 storage bootstrap/cache"

fresh-start:
	$(DOCKER_COMPOSE) down -v
	$(DOCKER_COMPOSE) up -d --build
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) composer install
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) php artisan key:generate
	$(DOCKER_COMPOSE) exec $(APP_SERVICE) php artisan migrate --seed
	$(DOCKER_COMPOSE) exec $(FRONTEND_SERVICE) npm install

prune:
	docker system prune -af