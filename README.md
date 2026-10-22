# Open Web Analytics for WordPress

Adds Open Web Analytics (OWA) tracking to WordPress sites and multisite networks.

- OWA 1.x (API key) and 2.x (application password and tracking bundle, beta)
- Multisite network mode for OWA 2.x: one server and password for the network, an Observation Profile and tracking switch per site
- Fires `owa_wp_tag_changed` when a site's tag changes, so page caches can purge; Proxy Cache Purge does

Install it from [WordPress.org](https://wordpress.org/plugins/open-web-analytics/). Setup and options are on the [wiki](https://github.com/open-web-analytics/owa-wordpress-plugin/wiki).

## Requirements

- WordPress 5.2+
- PHP 8.2+
- An OWA server, 1.14 or later

## Development

Requires Composer and Node 24.

```sh
composer install
npm ci
npm run build       # the settings pages, into build/ (not committed)
composer test       # PHP unit tests
npm run test:unit   # JavaScript unit tests
```

CI also runs integration tests against OWA 1.14 and 2.x, and browser tests (Playwright) on single-site and multisite WordPress. See `.github/workflows/ci.yml`.

Publishing a GitHub release deploys to WordPress.org.

## Donate

Open Web Analytics is free. If you need support, please [donate](http://paypal.me/openwebanalytics).

## License

[GNU GPL, version 2](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html). © [Peter Adams](http://peteradams.org).
