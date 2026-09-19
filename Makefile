# Developer entry points. Every PHP command runs in a throwaway container (see dev/php/Dockerfile).
SHELL := /bin/bash
PHP_IMAGE ?= osirfb-php
PHP ?= 8.3
DC := docker compose --project-directory dev -f dev/docker-compose.yml
RUN_PHP = docker run --rm -v "$(CURDIR)":/app -w /app --cpus 2 --memory 2g $(PHP_IMAGE):$(PHP)

.PHONY: help image vendor fossbilling test test-all-php analyse cs cs-fix env certs up install e2e doctor down clean dist check

help:
	@grep -E '^[a-z-]+:' Makefile | cut -d: -f1 | tr '\n' ' '; echo

image:
	docker build -q -t $(PHP_IMAGE):$(PHP) --build-arg PHP_VERSION=$(PHP) dev/php >/dev/null

vendor: image
	$(RUN_PHP) composer install --no-interaction --no-progress

fossbilling:
	dev/scripts/fetch-fossbilling.sh

test: vendor fossbilling
	$(RUN_PHP) vendor/bin/phpunit

test-all-php:
	for v in 8.3 8.4 8.5; do $(MAKE) --no-print-directory PHP=$$v image && docker run --rm -v "$(CURDIR)":/app -w /app $(PHP_IMAGE):$$v vendor/bin/phpunit || exit 1; done

analyse: vendor fossbilling
	$(RUN_PHP) vendor/bin/phpstan analyse --no-progress --memory-limit=1G

cs: vendor
	$(RUN_PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: vendor
	$(RUN_PHP) vendor/bin/php-cs-fixer fix

check: test analyse cs

env:
	dev/scripts/gen-env.sh

certs:
	dev/scripts/gen-certs.sh

up: env certs image
	mkdir -p dev/.mock-state
	$(DC) up -d

install:
	timeout 180 bash -c 'until curl -fsS -o /dev/null http://127.0.0.1:18480/install/install.php 2>/dev/null || $(DC) exec -T fossbilling test -f /var/www/html/config.php; do sleep 3; done'
	dev/scripts/install-fossbilling.sh

e2e:
	$(DC) --profile e2e run --rm e2e

doctor:
	$(DC) exec -T -u www-data fossbilling php library/Registrar/Adapter/Osir/bin/osir-doctor.php

down:
	$(DC) down

clean:
	$(DC) --profile e2e down -v
	rm -rf dev/.certs dev/.mock-state dev/.env dev/.admin-api-token

dist:
	dev/scripts/build-release.sh

dist-trial:
	ALLOW_DIRTY=1 dev/scripts/build-release.sh
