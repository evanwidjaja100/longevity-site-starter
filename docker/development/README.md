# Development container notes

The root `compose.yaml` is the supported local reference. Keep editor code in `wp-content`, database/uploads in named volumes, and environment values in an untracked `.env`. Run `scripts/validate-env.sh .env` before `docker compose up`.
