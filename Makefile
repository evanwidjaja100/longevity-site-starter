SHELL := /bin/sh

.PHONY: validate validate-release quality lint test test-security test-php test-content test-integration test-e2e test-a11y test-cross-browser test-lighthouse test-all release-artifact verify-release-artifact dependency-evidence release-evidence deploy-check docker-config up down bootstrap smoke manifest clean

validate:
	bash scripts/validate.sh
	bash scripts/verify-dependency-state.sh
	php scripts/verify-test-discovery.php

validate-release: validate
	bash scripts/verify-manifest.sh

quality:
	composer validate --strict
	composer install --no-interaction --prefer-dist --no-progress
	npm ci
	composer phpcs
	composer phpstan
	npm run lint

lint: quality

test: test-php test-content

test-security:
	php tests/php/run-unit-tests.php
	php scripts/verify-test-discovery.php
	composer phpunit -- --testsuite "Longevity Core" --filter 'Architecture|Authorization|Credentials|Approval|RestPublicBoundary|PublicationGates|Affiliate|Freshness|SystemReadiness'

test-php:
	composer test
	composer test:fallback

test-content:
	python3 scripts/validate-content.py
	python3 scripts/validate-internal-links.py
	python3 scripts/validate-freshness.py

test-integration:
	@for script in tests/integration/*.sh; do chmod +x "$$script"; "$$script"; done

test-e2e:
	npm run test:e2e -- --project=chromium

test-a11y:
	npm run test:a11y -- --project=chromium

test-cross-browser:
	npx playwright test tests/e2e/critical-cross-browser.spec.js --project=firefox-critical --project=webkit-critical

test-lighthouse:
	npm run test:lighthouse
	npm run test:lighthouse:desktop

test-all: validate quality test-security test test-integration test-e2e test-cross-browser test-lighthouse

release-evidence:
	bash scripts/generate-release-evidence.sh

release-artifact:
	bash scripts/build-release-artifact.sh $(if $(SHA),$(SHA),HEAD)

verify-release-artifact:
	@test -n "$(SHA)" || { echo 'usage: make verify-release-artifact SHA=<full-commit-sha>'; exit 2; }
	bash scripts/verify-release-artifact.sh "build/release/longevity-release-$(SHA).tar.gz" "$(SHA)"
	bash scripts/verify-release-reproducibility.sh "$(SHA)"

dependency-evidence:
	php scripts/generate-dependency-sbom.php

deploy-check: validate docker-config test-security

docker-config:
	docker compose config --quiet

up:
	docker compose up -d

down:
	docker compose down

bootstrap:
	docker compose run --rm --entrypoint sh wpcli /scripts/bootstrap.sh

ci-setup:
	bash scripts/ci-setup.sh

smoke:
	bash scripts/smoke-test.sh

manifest:
	bash scripts/regenerate-manifest.sh

clean:
	rm -rf build coverage test-results playwright-report .phpunit.cache .lighthouseci reports/generated
