# Known limitations

Last reviewed: 2026-09-12

## Release blockers

- Authentication and tenant-scoped authorization are not implemented. The current local build is a single-workspace demo and must not be exposed as a multi-tenant service.
- The running local environment uses SQLite because no PostgreSQL server is available. Production must use PostgreSQL before deployment.
- Candid and Brave Search credentials are not configured. Discovery therefore depends on free public APIs and Bing RSS, which cannot reliably supply historical private-foundation grant amounts.
- PDF guideline extraction is not implemented. Opportunities whose eligibility exists only in PDFs remain `needs review`.
- The crawler validates public IPs and every redirect target, caps redirects, pages, concurrency, and elapsed time, but does not yet parse `robots.txt` or persist per-domain crawl schedules.
- Node/npm is unavailable in the current environment, so `npm run build` could not be executed. Current screens use directly served CSS and were browser-tested.

## Product limitations

- Feedback is persisted, but is not yet incorporated into future ranking.
- Application workspaces assemble stored profile facts and flag missing content; AI drafting and document uploads are not implemented.
- Opportunity status, deadline, eligibility, and award range remain unknown unless explicitly present in verified structured evidence.
- CRM enrichment supports configured HubSpot data only; no licensed people-data provider is configured.

These limitations should be resolved before describing the application as production-ready.
