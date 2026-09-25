# Status API (WordPress plugin)

Deze plugin publiceert een REST API endpoint met status-/storingsinformatie.

## Installatie

- Plaats de plugin in `wp-content/plugins/wp_status_api/`
- Activeer de plugin in WordPress
- Vereist WordPress 5.3+ en PHP 7.4+

## Verwijderen

Bij het verwijderen van de plugin blijven API clients, status en historie standaard bewaard, zodat opnieuw installeren geen integraties breekt. Om bij verwijderen alles op te ruimen, zet in `wp-config.php`:

```php
define('STATUS_API_DELETE_DATA_ON_UNINSTALL', true);
```

## Authenticatie

De API ondersteunt Bearer-token authenticatie. In de admin-instellingenpagina kun je een token genereren op basis van een API key/secret.

## Endpoints

- `GET /wp-json/status-api/v1/status`

### Voorbeeld (curl)

```bash
curl -H "Authorization: Bearer <TOKEN>" \
  "https://example.com/wp-json/status-api/v1/status"
```

## Response (indicatief)

- `title` (string)
- `text` (string)
- `baseURL` (string)
- `status` (string)
- `timestamp` (int)
- `statusExpiryDate` (string|null)
- `statusExpiryTimestamp` (int|null)
- `timestampUtc` (int) — sinds 0.9.10
- `statusExpiryTimestampUtc` (int|null) — sinds 0.9.10
- `statusExpiryIso8601` (string|null) — sinds 0.9.10, bijv. `2025-12-31T23:59:00+01:00`

Let op: `timestamp` en `statusExpiryTimestamp` zijn om historische redenen de lokale sitetijd weergegeven als Unix-timestamp (wijken af van UTC). Gebruik voor nieuwe integraties de `*Utc`/`*Iso8601` velden.

## Filters

- `status_api_client_ip` (string `$ip`, `WP_REST_Request $request`) — bepaal het client-IP voor rate limiting, bijv. achter een vertrouwde reverse proxy. Standaard `REMOTE_ADDR`.
- `status_api_auth_rate_limit_max_attempts` (int, standaard 20) — aantal mislukte pogingen per IP binnen het venster.
- `status_api_auth_rate_limit_window` (int seconden, standaard 900).
- `status_api_last_used_throttle` (int seconden, standaard 300) — hoe vaak "Laatst gebruikt" maximaal wordt bijgewerkt.
- `status_api_cache_control` (string, standaard `no-store, private`) — `Cache-Control` header van de status-response. Lege string = geen header.

Voorbeeld (alleen gebruiken als élk verzoek via je eigen proxy binnenkomt):

```php
add_filter('status_api_client_ip', function ($ip) {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Neem het laatste adres: dat is door je eigen proxy toegevoegd.
        // Het eerste adres kan door de client zelf worden vervalst.
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim(end($parts));
    }
    return $ip;
});
```

## Development

- Composer (optioneel): `composer install`
- Vendor wordt niet gecommit (zie `.gitignore`).
- Tests (geen afhankelijkheden nodig): `php tests/run.php`
- `tests/test-api-response.php` is een contracttest op het JSON-formaat: bestaande velden mogen niet verdwijnen of van type veranderen; nieuwe velden alleen achteraan toevoegen.

### Structuur

- `status-api.php` — hoofdbestand (niet hernoemen: WordPress herkent de plugin aan `wp_status_api/status-api.php`)
- `includes/` — één klasse per bestand
- `assets/` — admin CSS/JS
- `uninstall.php` — opruimen bij verwijderen
- `tests/` — testset (niet in de release-zip)

## Licentie

Proprietary (zie `LICENSE`).

