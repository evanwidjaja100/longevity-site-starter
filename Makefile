SHELL := /bin/sh

.PHONY: validate lint test test-php test-content test-e2e docker-config up down bootstrap smoke clean manifest

validate:
	./scripts/validate.sh

lint: validate
	@if command -v composer >/dev/null 2>&1; then composer lint; else echo "Composer unavailable; PHP coding-standard lint deferred."; fi
	@if command -v npm >/dev/null 2>&1 && [ -d node_modules ]; then npm run lint; else echo "npm dependencies unavailable; front-end lint deferred."; fi

test: test-php test-content

test-php:
	@if command -v composer >/dev/null 2>&1 && [ -f vendor/bin/phpunit ]; then composer phpunit; else php tests/php/run-unit-tests.php; fi

test-content:
	python3 scripts/validate-content.py
	python3 scripts/validate-internal-links.py
	python3 scripts/validate-freshness.py --no-fail

test-e2e:
	@if [ -d node_modules ]; then npm run test:e2e; else echo "Install npm dependencies before E2E tests."; exit 1; fi

docker-config:
	docker compose config --quiet

up:
	docker compose up -d

down:
	docker compose down

bootstrap:
	docker compose run --rm --entrypoint sh wpcli /scripts/bootstrap.sh

smoke:
	./scripts/smoke-test.sh

manifest:
	./scripts/regenerate-manifest.sh

clean:
	rm -rf build coverage test-results playwright-report .phpunit.cache
