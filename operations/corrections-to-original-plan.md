# Corrections Applied to the Original Plan

1. **WordPress baseline:** This starter pins WordPress 7.0.1 with PHP 8.3 rather than the report’s WordPress 6.4/PHP 8.1 example. Update only after staging tests.
2. **A/B testing:** Google Optimize ended on 30 September 2023. Use a maintained third-party platform, server-side feature flags, or controlled landing-page experiments measured in GA4.
3. **Ad-network thresholds:** “Apply to Mediavine at about 10k visits” is not current. Recheck eligibility before applying; Journey is the lower-traffic path.
4. **Structured data:** JSON-LD helps search engines understand entities but does not prove expertise or guarantee rankings. FAQ rich results are restricted.
5. **Health claims:** Supplement, fasting, cold exposure, sauna, red-light, and nootropic pages require medical review for material claims.
6. **Affiliate density:** Do not mechanically add affiliate links to every article. Add them only when they help a relevant reader decision.
7. **Revenue figures:** Treat the report’s table as a scenario, not a forecast. Rebuild it from actual CTR, conversion, commission, RPM, refunds, and costs.
8. **Deployment choice:** Managed WordPress and self-hosted Docker are separate operating models. This package supports local/staging Docker and portable `wp-content`.
9. **Product-version freshness:** Verify device versions, features, prices, and policies on the assignment and publication dates.
10. **Medical/legal scope:** Market availability does not establish safety, efficacy, or lawful claims.

## Official references
- https://wordpress.org/documentation/wordpress-version/version-7-0-1/
- https://hub.docker.com/_/wordpress
- https://support.google.com/analytics/answer/12979939
- https://developers.google.com/search/docs/appearance/structured-data/sd-policies
- https://developers.google.com/search/blog/2023/08/howto-faq-changes
- https://developers.google.com/search/docs/fundamentals/creating-helpful-content
- https://www.mediavine.com/mediavine-requirements/
- https://www.fda.gov/food/dietary-supplements
