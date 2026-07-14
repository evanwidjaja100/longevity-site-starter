# Backup and Restore

Back up the database and uploaded media off site, encrypted, and with retention appropriate to editorial and legal needs. First-party code should also be recoverable from version control and release artifacts.

The example backup script creates a database dump and archive but is not a production backup service. The restore example refuses a production environment and requires explicit confirmation. At least quarterly, restore to an isolated staging environment, verify users, private records, media, permalink behavior, readiness gates, and a representative article. Record the result and recovery time.
