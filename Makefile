# =============================================================================
# Anonym Mail — Makefile
# =============================================================================

.PHONY: all up down init init-db tls tor admin healthcheck test backup clean help

# Default target
.DEFAULT_GOAL := help

# Load environment
-include .env
export

# =============================================================================
# MAIN TARGETS
# =============================================================================

all: init up tls tor admin healthcheck ## Full installation from scratch
	@echo "✓ Installation complete"
	@echo "  Web:   https://$(PRIMARY_DOMAIN)"
	@echo "  Admin: https://$(ADMIN_HOST)"

up: ## Start all containers
	docker compose up -d
	@echo "✓ Services started"

down: ## Stop all containers
	docker compose down
	@echo "✓ Services stopped"

restart: down up ## Restart all containers

logs: ## Show container logs
	docker compose logs -f

# =============================================================================
# INITIALIZATION
# =============================================================================

init: check-env init-dirs init-keys init-db ## First-time setup
	@echo "✓ Initialization complete"

check-env:
	@test -f .env || (echo "ERROR: .env file not found. Copy .env.example to .env" && exit 1)

init-dirs: ## Create required directories
	@mkdir -p certs dkim maildata backups
	@mkdir -p tor/www tor/mail
	@chmod 700 dkim tor/www tor/mail
	@echo "✓ Directories created"

init-keys: ## Generate cryptographic keys
	@./scripts/generate-dkim.sh $(PRIMARY_DOMAIN)
	@./scripts/generate-canary-key.sh
	@echo "✓ Keys generated"

init-db: ## Run database migrations
	@./scripts/init-db.sh
	@echo "✓ Database initialized"

# =============================================================================
# TLS CERTIFICATES
# =============================================================================

tls: ## Generate/renew TLS certificates (acme.sh DNS-01)
	@./scripts/renew-tls.sh
	@echo "✓ TLS certificates ready"

tls-test: ## Test TLS configuration
	@docker run --rm -it drwetter/testssl.sh https://$(PRIMARY_DOMAIN)

# =============================================================================
# TOR
# =============================================================================

tor: ## Start Tor hidden services
	@docker compose up -d tor
	@sleep 5
	@./scripts/show-onion.sh
	@echo "✓ Tor hidden services active"

# =============================================================================
# ADMIN
# =============================================================================

admin: ## Create admin user (interactive)
	@./scripts/create-admin.sh

admin-totp-reset: ## Reset admin TOTP
	@./scripts/reset-admin-totp.sh

# =============================================================================
# HEALTH & MONITORING
# =============================================================================

healthcheck: ## Run health checks
	@./scripts/healthcheck.sh

status: ## Show service status
	@docker compose ps
	@echo ""
	@./scripts/healthcheck.sh --brief

# =============================================================================
# TESTING
# =============================================================================

test: test-unit test-integration test-privacy ## Run all tests
	@echo "✓ All tests passed"

test-unit: ## Run PHPUnit tests
	@docker compose exec php vendor/bin/phpunit --testsuite unit

test-integration: ## Run integration tests
	@docker compose -f docker-compose.test.yml up -d
	@docker compose -f docker-compose.test.yml exec php vendor/bin/phpunit --testsuite integration
	@docker compose -f docker-compose.test.yml down

test-privacy: ## Check no IPs in logs
	@./tests/privacy.sh

lint: ## Run linters
	@docker compose exec php vendor/bin/phpstan analyse
	@docker compose exec php vendor/bin/phpcs

lint-fix: ## Fix linting issues
	@docker compose exec php vendor/bin/phpcbf

# =============================================================================
# BACKUP & MAINTENANCE
# =============================================================================

backup: ## Backup database and maildata
	@./scripts/backup.sh
	@echo "✓ Backup complete"

restore: ## Restore from backup (BACKUP_FILE=path required)
	@./scripts/restore.sh $(BACKUP_FILE)

purge-logs: ## Purge all logs
	@./scripts/purge-logs.sh
	@echo "✓ Logs purged"

rotate-dkim: ## Rotate DKIM keys
	@./scripts/rotate-dkim.sh $(PRIMARY_DOMAIN)
	@echo "✓ DKIM rotated. Update DNS records."

delete-expired: ## Delete users with expired deletion requests
	@./scripts/delete-expired-users.sh

sign-canary: ## Sign warrant canary
	@./scripts/sign-canary.sh

admin-certs: ## Generate mTLS certs for admin panel
	@./scripts/generate-admin-certs.sh

# =============================================================================
# CLEANUP
# =============================================================================

clean: ## Remove containers and volumes
	docker compose down -v --remove-orphans
	@echo "✓ Cleaned"

clean-all: clean ## Remove everything including data
	@rm -rf certs/* dkim/* maildata/* tor/www/* tor/mail/*
	@echo "✓ All data removed"

# =============================================================================
# DEVELOPMENT
# =============================================================================

dev: ## Start in development mode
	APP_ENV=development APP_DEBUG=true docker compose up

shell-php: ## Shell into PHP container
	@docker compose exec php sh

shell-db: ## Shell into PostgreSQL
	@docker compose exec postgres psql -U $(DB_USER) $(DB_NAME)

shell-redis: ## Shell into Redis
	@docker compose exec redis redis-cli

composer-install: ## Install PHP dependencies
	@docker compose exec php composer install

composer-update: ## Update PHP dependencies
	@docker compose exec php composer update

# =============================================================================
# HELP
# =============================================================================

help: ## Show this help
	@echo "Anonym Mail — Makefile targets"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'
