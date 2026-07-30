# syntax=docker/dockerfile:1
# Local/CI WordPress image. Production is managed-host-only; this pinned base
# does not define or claim the host's runtime image.
# wordpress:7.0.2-php8.3-apache (PRV3-CI-02: satisfies the >= 7.0.2 / PHP 8.3
# platform baseline; wp longevity preflight enforces the floor in ci-setup).
FROM wordpress@sha256:9fac4d47b61186131ffefb5d966f0045d0eea94bfd7bd40cafae29b78a709d1b
