# Makefile
# Project root — /root_directory/Makefile
#
# All commands run inside the container so your local machine needs only
# Docker and Make. No PHP, no Composer, no Node required on the host.
#
# Usage:
#   make up             start all services
#   make test           run full test suite inside container
#   make test-unit      run only pure domain unit tests (fast, no DB)
#   make shell          open a shell inside php-fpm container

# PHPFPM    := eventpulse_phpfpm
PHPFPM    := php-fpm
PHP       := docker compose exec $(PHPFPM) php
PHP_XDEBUG_OFF := docker compose exec -e XDEBUG_MODE=off $(PHPFPM) php
PHPUNIT   := $(PHP_XDEBUG_OFF) vendor/bin/phpunit
ARTISAN   := $(PHP_XDEBUG_OFF) artisan
COMPOSER  := $(PHP_XDEBUG_OFF) /usr/bin/composer

.PHONY: up down build restart shell logs \
        composer-install composer-update \
        test test-unit test-integration test-feature test-filter \
        migrate migrate-fresh cache-clear route-list tinker

## ── Container lifecycle ──────────────────────────────────────────────────────

up:
	docker compose up -d

down:
	docker compose down

# Rebuild images from scratch (no cache). Run after Dockerfile changes.
build:
	docker compose build --no-cache
	docker compose up -d

restart:
	docker compose restart

# Tail logs for all services. Ctrl-C to stop.
logs:
	docker compose logs -f

# Tail logs for a specific service: make logs-php
logs-%:
	docker compose logs -f $*

# Open a shell inside php-fpm (where your app runs)
shell:
	docker compose exec $(PHPFPM) sh

## ── Composer ─────────────────────────────────────────────────────────────────

# Run after cloning or after composer.json changes
composer-install:
	$(COMPOSER) install --working-dir=/var/www/html

composer-update:
	$(COMPOSER) update --working-dir=/var/www/html

## ── Tests ────────────────────────────────────────────────────────────────────

# Full suite — unit + integration + feature
test:
	$(PHPUNIT)

# Pure domain tests only — no DB, no queue, runs in milliseconds.
# Run this constantly during domain development.
test-unit:
	$(PHPUNIT) --testsuite=Unit

# Queue behaviour, repository, adapter tests — needs DB and Redis
test-integration:
	$(PHPUNIT) --testsuite=Integration

# Full HTTP round-trips — needs DB, queue, full Laravel bootstrap
test-feature:
	$(PHPUNIT) --testsuite=Feature

test-domain:
	${PHPUNIT} --testsuite=Domain

# Filter by class or method name:  make test-filter f=NotificationTest
test-filter:
	$(PHPUNIT) --filter=$(f)

## ── Laravel Artisan ──────────────────────────────────────────────────────────

migrate:
	$(ARTISAN) migrate

migrate-fresh:
	$(ARTISAN) migrate:fresh --seed

cache-clear:
	$(ARTISAN) cache:clear
	$(ARTISAN) config:clear
	$(ARTISAN) route:clear

route-list:
	$(ARTISAN) route:list

tinker:
	docker compose exec $(PHPFPM) php artisan tinker