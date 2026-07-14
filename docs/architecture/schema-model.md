# Conservative Schema Model

The schema service emits one `@graph` containing only entities supported by visible page content and stored metadata:

- `Organization` and `WebSite` on the site.
- `WebPage` for the current URL.
- `BlogPosting` for posts and reviews with real author and dates.
- `Person` for a linked, public reviewer profile when present.
- `BreadcrumbList` from the visible hierarchy.
- `Review` and `Product` only when a review has a real product model, a valid score, a scoring version, and completed testing.

The service does not emit unsupported FAQ, HowTo, medical-organization, physician, aggregate-rating, or fabricated review markup. It suppresses its graph when a recognized SEO plugin is active to prevent duplicate metadata. Structured data is descriptive, not proof of expertise or eligibility for rich results.
