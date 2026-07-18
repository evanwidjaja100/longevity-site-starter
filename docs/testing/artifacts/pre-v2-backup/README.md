# Pre-v2 Backup Evidence

**Date:** 2026-07-18
**Operator:** Implementation Agent (automated)

## Files

| File | Description | Size |
|---|---|---|
| `database-2026-07-18.sql` | Full MySQL dump (mysqldump, all tables) | ~2.4 MB |
| `wp-content-2026-07-18.tgz` | Compressed wp-content/ archive | ~13.4 MB |

## Backup Procedure

```bash
# Database
docker exec longevity-site-db-1 mysqldump -u longevity_app -p$PASSWORD longevity > database-2026-07-18.sql

# Filesystem (wp-content only — core is managed by Docker image)
docker exec longevity-site-wordpress-1 tar czf /tmp/wp-content-backup.tgz -C /var/www/html wp-content/
docker cp longevity-site-wordpress-1:/tmp/wp-content-backup.tgz ./wp-content-2026-07-18.tgz
```

## Restore Verification

To restore into a disposable environment:

```bash
# 1. Start fresh WordPress + MySQL containers
docker compose up -d db wordpress
# Wait for healthy

# 2. Import database
docker exec -i longevity-site-db-1 mysql -u longevity_app -p$PASSWORD longevity < database-2026-07-18.sql

# 3. Restore files
docker cp wp-content-2026-07-18.tgz longevity-site-wordpress-1:/tmp/
docker exec longevity-site-wordpress-1 tar xzf /tmp/wp-content-backup.tgz -C /var/www/html/

# 4. Verify
# - Homepage loads at http://localhost:8080/
# - Admin login at /wp-admin/
# - Health endpoint responds
# - Test posts/reviews visible
```

## Verification Checklist

| Check | Status |
|---|---|
| Database dump completes without errors | ✅ |
| Dump contains all WordPress tables | ✅ |
| Dump contains content (pages, posts, reviews) | ✅ |
| Dump contains custom post types | ✅ |
| wp-content archive completes | ✅ |
| Archive contains theme + mu-plugins + uploads | ✅ |
| No secrets committed in plaintext | ✅ (credentials in .env only, excluded by .gitignore) |

## Notes

- WordPress core is managed by Docker image (`wordpress:7.0.1-php8.3-apache`), not backed up
- Docker volumes (`db_data`, `wordpress_data`) are managed by Docker; this is a logical backup
- For production, offsite encrypted backups and retention policies are required per P9.5
