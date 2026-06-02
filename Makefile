.PHONY: help up down build logs ps shell worker test test-unit test-integration \
        migrate migrate-fresh lint stan cs cs-fix cache-clear

# ─── defaults ────────────────────────────────────────────────────────────────
PHP     = php
CONSOLE = $(PHP) bin/console
PHPUNIT = $(PHP) bin/phpunit
STAN    = vendor/bin/phpstan
CS      = vendor/bin/php-cs-fixer
DC      = docker compose -f docker-compose.yml

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
	  awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

# ─── docker ──────────────────────────────────────────────────────────────────
up: ## Start all services in the background
	$(DC) up -d

down: ## Stop and remove containers
	$(DC) down

build: ## Rebuild Docker images
	$(DC) build --no-cache

logs: ## Tail logs for all services
	$(DC) logs -f

ps: ## Show running containers
	$(DC) ps

shell: ## Open a shell in the app container
	$(DC) exec app sh

worker: ## Tail worker logs
	$(DC) logs -f worker

# ─── database ────────────────────────────────────────────────────────────────
migrate: ## Run pending migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

migrate-fresh: ## Drop schema and re-run all migrations from scratch
	$(CONSOLE) doctrine:schema:drop --force --full-database
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

db-diff: ## Generate a migration from current entity state
	$(CONSOLE) doctrine:migrations:diff

# ─── testing ─────────────────────────────────────────────────────────────────
test: ## Run the full test suite
	$(PHPUNIT) --testdox

test-unit: ## Run only unit tests
	$(PHPUNIT) --testdox tests/Unit

test-integration: ## Run only integration tests
	$(PHPUNIT) --testdox tests/Integration

test-handler: ## Run only handler tests
	$(PHPUNIT) --testdox tests/Handler

test-coverage: ## Generate HTML coverage report in var/coverage
	XDEBUG_MODE=coverage $(PHPUNIT) --coverage-html var/coverage

# ─── code quality ────────────────────────────────────────────────────────────
stan: ## Run PHPStan static analysis
	$(STAN) analyse --configuration=phpstan.dist.neon --no-progress

cs: ## Check coding standards (dry-run)
	$(CS) fix --dry-run --diff --config=.php-cs-fixer.dist.php

cs-fix: ## Auto-fix coding standards
	$(CS) fix --config=.php-cs-fixer.dist.php

lint: ## Run all linters: cs + stan
	$(MAKE) cs
	$(MAKE) stan

# ─── misc ─────────────────────────────────────────────────────────────────────
cache-clear: ## Clear the Symfony cache
	$(CONSOLE) cache:clear

install: ## Fresh composer install
	composer install

ci: test lint ## Run everything CI runs locally
