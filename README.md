# Stoke MainWP Ops & Reporting Extension

Prototype MainWP dashboard extension that centralises uptime, Search Console, and reporting metadata for managed sites.

## Features

- Registers a MainWP extension page under **Extensions → Ops & Reporting** with configuration for Uptime Kuma, Google Search Console OAuth credentials, and default Looker Studio URLs.
- Adds an **Ops & Reporting** meta box to the MainWP *Sites → Edit* screen allowing per-site Looker Studio URL, Search Console property mapping, and Uptime Kuma settings.
- Stores configuration in WordPress options (`stoked_mainwp_settings` and `stoked_mainwp_site_meta`).
- Exposes preliminary REST API endpoints for Looker Studio connectors under `/wp-json/stoked-mainwp/v1/`.

## REST Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/wp-json/stoked-mainwp/v1/sites` | List managed sites with stored meta data. |
| `GET` | `/wp-json/stoked-mainwp/v1/sites/<id>/uptime` | Retrieve cached uptime snapshot for a site. |
| `GET` | `/wp-json/stoked-mainwp/v1/sites/<id>/search-console` | Retrieve cached Search Console KPIs (placeholder data until Google integration is completed). |
| `GET` | `/wp-json/stoked-mainwp/v1/rollups/kpis` | Aggregate KPI rollups across managed sites. |

All endpoints require either a logged-in MainWP administrator or a valid `X-Stoke-Connector-Token` header / `token` query parameter. Tokens can be configured or regenerated from the extension settings page.

## Development Notes

- PHP 8.1+ and WordPress 6.4+ are required.
- The current build includes placeholder collectors for Uptime Kuma and Google Search Console. Future iterations will replace the placeholders with real API integrations and caching.
- When running within MainWP, the extension attempts to enrich REST responses with site names and URLs by querying `MainWP_DB::instance()->getWebsiteById( $site_id )` when available.

## Getting Started

1. Install and activate the plugin on your MainWP dashboard site.
2. Visit **MainWP → Extensions → Ops & Reporting** to configure global settings.
3. Edit a managed site to view the new **Ops & Reporting** meta box and store per-site metadata.
4. Access the REST endpoints with your connector token to power Looker Studio data sources.

This repository currently focuses on delivering a functional skeleton that the Stoke team can iterate on while wiring up upstream services and UI polish.
