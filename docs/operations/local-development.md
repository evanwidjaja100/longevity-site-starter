# Local Development

**Owner:** Engineering
**Last reviewed:** 2026-07-28

1. Copy `.env.example` to `.env` and replace every placeholder.
2. Run `./scripts/validate-env.sh .env`.
3. Run `docker compose config --quiet`.
4. Start services with `make up`.
5. Bootstrap with `make bootstrap`.
6. Run `make smoke`, `make validate`, and `make test`.

Local HTTP is permitted. Keep search visibility disabled. Do not reuse local credentials or databases in staging or production. The repository mounts only first-party code and structured content needed for development.
