# Security Hardening

Use unique named accounts, MFA where supported, least-privilege roles, strong generated passwords, HTTPS, secure cookies, restricted file modifications, current dependencies, a WAF/rate limits, and off-site backups. Never commit `.env`, database dumps, access tokens, private keys, or production exports.

Review capabilities after plugin changes. Validate nonces and object authorization on every write. Escape public output. Keep private CPTs non-public and exclude them from search and sitemaps. Treat analytics and correction forms as untrusted input. Document exceptions and time-box them.
