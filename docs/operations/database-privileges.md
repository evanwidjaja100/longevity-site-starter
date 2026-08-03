# Database Privileges

**Owner:** Operations
**Last reviewed:** 2026-07-29

Managed staging and production never carry a database root credential. The
application connects only as its least-privilege user (`WORDPRESS_DB_USER`),
scoped to the single application schema (`WORDPRESS_DB_NAME`).
`scripts/validate-env.sh` rejects `WORDPRESS_DB_ROOT_PASSWORD` when
`WP_ENVIRONMENT_TYPE` is `staging` or `production`; the root credential exists
only in local/CI Docker, where it initializes the disposable database
container (`compose.yaml` passes it exclusively to the `db` service).

## Application user grant set

All grants are scoped to the application schema only. Grant nothing globally.

```sql
GRANT SELECT, INSERT, UPDATE, DELETE,
      CREATE, ALTER, INDEX, DROP,
      CREATE TEMPORARY TABLES, LOCK TABLES, REFERENCES
ON `<WORDPRESS_DB_NAME>`.* TO '<WORDPRESS_DB_USER>'@'<app-host>';
```

Why each grant is required:

| Privilege | Required by |
|---|---|
| `SELECT, INSERT, UPDATE, DELETE` | WordPress core and all `longevity-core` queries |
| `CREATE, ALTER, INDEX` | WordPress core install/upgrade (`dbDelta`) and `longevity-core` custom tables, which self-provision and add columns/keys additively (audit log, approvals, evidence store, invalidation queue, contact idempotency, notification outbox, dependency index, override intent) |
| `DROP` | WordPress core upgrade routines and rollback of temporary structures; `dbDelta` may recreate indexes |
| `CREATE TEMPORARY TABLES, LOCK TABLES` | WordPress core maintenance and backup-compatible tooling |
| `REFERENCES` | Table definitions with key constraints under strict SQL modes on some managed hosts |

Advisory locks (`GET_LOCK`/`RELEASE_LOCK`, used by migrations, the
publication lock, and platform preflight) require no grant — the server only
needs to support them (Oracle MySQL 8.0; MariaDB is not qualified, see
`docs/operations/managed-wordpress-deployment.md`).

Explicitly **not** granted (the application must never hold these):
`SUPER`, `FILE`, `PROCESS`, `GRANT OPTION`, `CREATE USER`, `RELOAD`,
`SHUTDOWN`, `REPLICATION` privileges, and any grant on `mysql.*`,
`sys.*`, `information_schema` beyond implicit read, or other schemas.

## Migrations

`wp longevity migrate` runs additive migrations (CREATE TABLE, additive
ALTER TABLE column/key additions) and works under the application grant set
above. No separate credential is needed for the documented migration path.

## Operator-only elevated credential

If a future operation genuinely exceeds the application grant set (for
example, provisioning the schema and application user, changing character
sets, or a host-directed restore):

1. The operations owner issues a short-lived elevated credential through the
   managed host's control plane, scoped as narrowly as the host allows and
   valid only for the maintenance window.
2. The credential is used interactively by the operator; it is never written
   to `.env.production`, `wp-config.php`, CI variables, or any persistent
   application environment.
3. The credential is revoked (or expires) immediately after the window, and
   the action is recorded in the operations log with date, operator, and
   purpose.

Rollback of this policy never restores a root credential to the WordPress
runtime; correct the deployment tooling or issue a properly scoped migration
credential instead.

## Verification

- `scripts/validate-env.sh .env.production` passes without any root
  credential and fails if one is present.
- `tests/integration/environment-validation.sh` enforces the contract:
  production without root passes, production with root fails, local without
  root fails.
- `wp longevity preflight` verifies server capabilities (version floor,
  advisory locks) without requiring elevated privileges.
