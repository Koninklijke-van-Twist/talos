# Talos

OData-lezingen gaan via Mímir wanneer `$mimirApi` in `web/auth.php` staat.

```php
$mimirApi  = 'mimir_…';
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
```

With `$mimirApi` set, OData fetches and company discovery (`odata_mimir_companies_as_rows` / `fetchAvailableCompanyContext`) try Mímir first. If that call fails (connection/timeout, non-2xx, invalid JSON, or a Mímir error payload), Talos fetches the same data on the legacy Business Central path (`$baseUrl`, `$auth` / `$auth_list`, `$environment`, local odata file cache) and skips Mímir for the rest of that PHP request. Keep those BC credentials in `auth.php` next to `$mimirApi`; if they are absent the original Mímir error is raised. Without `$mimirApi` the existing BC path is unchanged.

Live pages load `auth.php` through `web/content/bootstrap.php` (`index.php`, billing stream, row inspect, department access). The hourly warmer (`web/hourly.php`, formerly nightly) and `web/sendreminders.php` load `auth.php` themselves, including when cron starts them with `php` (CLI keeps the long Mímir timeout; web requests use about 90s). Copy `web/auth_TEMPLATE.php` when filling in `auth.php`.
