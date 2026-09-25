# Changelog

## 0.9.12

Rechten, authenticatie en documentatie (backwards compatible: JSON-response, REST-route en beide authenticatiemethodes ongewijzigd):

- Redacteuren (`edit_posts`) kunnen de statusmelding weer opslaan, zoals vóór 0.9.9.4; voorheen werd hun opslag stil genegeerd. Zonder recht volgt nu een duidelijke melding. Instelbaar via filter `status_api_status_capability`.
- Historie exporteren/wissen blijft `manage_options` (filter `status_api_history_manage_capability`); de knoppen worden verborgen voor wie dat recht niet heeft.
- Secret regenereren van een ingetrokken client: knoptekst "Regenereren & heractiveren" en een expliciete waarschuwing in de bevestiging (gedrag ongewijzigd).
- Authenticatie via `api_key`/`api_secret` in de URL is gemarkeerd als verouderd: responses bevatten een `Deprecation` header (RFC 9745) en per client is zichtbaar welke methode recent is gebruikt. Standaard blijft de methode werken; uitzetten kan met filter `status_api_allow_query_auth`.
- Documentatie verduidelijkt: na automatisch verlopen blijft de laatste vervaldatum in `statusExpiry*` staan terwijl `status` `"geen"` is.
- Audit-log voor API clients: aanmaken, migreren, intrekken, secret regenereren, heractiveren en verwijderen worden vastgelegd (wie, wat, wanneer) in een eigen tabel `{prefix}status_api_audit`; zichtbaar in het nieuwe tabblad "Audit-log" onder API Instellingen. Secrets worden nooit gelogd, van de API key alleen de eerste 8 tekens. De statushistorie en CSV-export zijn ongewijzigd.
- Kleurwaarden in de admin worden ge-escaped (`esc_attr`).
- `composer.json`: plugin-update-checker `^5.7` (gelijk aan de al gebruikte versie in `composer.lock`).

## 0.9.11

Robuustheid en onderhoud (backwards compatible: REST-route, JSON-velden, authenticatie, option-namen, klassenamen en `$status_api_plugin` ongewijzigd):

- Code opgesplitst: één klasse per bestand in `includes/`, admin-CSS/JS in `assets/`. `status-api.php` blijft het hoofdbestand.
- Historie-tabel heeft nu een schemaversie (`status_api_db_version`) en wordt ook na updates via de update-checker en op alle sites in een multisite-netwerk automatisch aangemaakt/bijgewerkt. dbDelta-SQL gecorrigeerd.
- `uninstall.php`: verwijdert altijd de cronjob; gegevens (clients, status, historie) alleen als `STATUS_API_DELETE_DATA_ON_UNINSTALL` in `wp-config.php` op `true` staat.
- Deactiveren verwijdert alle geplande cronjobs van de plugin (`wp_clear_scheduled_hook`).
- `status_last_expiry_check` wordt buiten cron nog maximaal eens per 5 minuten geschreven.
- Eén gedeelde historie-instantie in plaats van losse instanties per actie.
- Native datumveld in plaats van jQuery UI; de stylesheet van `code.jquery.com` wordt niet meer geladen (privacy/CSP).
- Plugin-headers `Requires at least: 5.3`, `Requires PHP: 7.4` en `Update URI`.
- Status-response stuurt `Cache-Control: no-store, private` mee (aan te passen met filter `status_api_cache_control`).
- Bevestigingsvraag bij secret regenereren, intrekken en verwijderen; historie toont opgemaakte inhoud in plaats van HTML-tags; `wp_safe_redirect`.
- Testset (`php tests/run.php`) met contracttest op het JSON-formaat; GitHub-workflow draait tests op PHP 7.4 t/m 8.4 en vóór elke release.
- Composer classmap verwijderd (klassen worden via `require_once` geladen).

## 0.9.10

Beveiliging en correctheid (volledig backwards compatible):

- Verwijderde API clients komen niet meer terug via de legacy options (`status_api_key`/`status_api_secret`); migratie draait alleen nog op sites van vóór 0.9.9. Legacy options worden bijgewerkt bij secret-regeneratie en opgeruimd bij verwijderen.
- `last_used_at` wordt apart opgeslagen (`status_api_clients_last_used`) en maximaal eens per 5 minuten bijgewerkt; een API-request kan daardoor geen gelijktijdige intrekking meer overschrijven en er is veel minder database-schrijfverkeer.
- Rate limiting: geldige credentials worden altijd geaccepteerd (geen onterechte 429 achter een gedeelde proxy); nieuwe filters `status_api_client_ip`, `status_api_auth_rate_limit_max_attempts`, `status_api_auth_rate_limit_window` en `status_api_last_used_throttle`.
- Robuustere verwerking van `api_key`/`api_secret` parameters (geen PHP-fout bij array-waarden).
- Vervaldatum en -tijd worden gevalideerd; bij ongeldige invoer wordt niet opgeslagen en blijft de ingevulde tekst behouden.
- Nieuwe response-velden `timestampUtc`, `statusExpiryTimestampUtc` en `statusExpiryIso8601` met correcte tijdzone. Bestaande velden zijn ongewijzigd.
- Documentatie over de Bearer token gecorrigeerd (statische token, behandel als wachtwoord).
- CSV-export beschermd tegen formule-injectie.
- Quotes in titel, inhoud en clientnaam worden niet langer met backslashes opgeslagen (`Storing\'s` → `Storing's`). Bestaande meldingen herstellen zich bij opnieuw opslaan.

## 0.9.9.5

- Hotfix: onopgeloste merge-conflictmarkeringen uit release 0.9.9.4 verwijderd (die veroorzaakten een PHP parse error).
- Versienummer in de plugin-header weer gelijk getrokken met de release-tag.
- Release-workflow controleert nu PHP-syntax, conflictmarkeringen en of het versienummer overeenkomt met de tag.
- Rate limiting op mislukte authenticatiepogingen en extra capability-checks op admin-acties.

## 0.9.9.1

- Compactere API clients UI met verborgen credentials en Dependabot voor Composer updates.

## 0.9.9

- Meerdere Clients mogelijkheid toegevoegd en opschonen van de AdminUI. 

## 0.9.8

- Repo verplaatst naar SURFnet Github omgeving. 

## 0.9.7

- Verbeterde update-detectie via GitHub Releases.

## 0.9.5

- Aanvullingen op de JSON output waardoor output gelijk is en values indien niet aanwezig 'null'.

## 0.9.2

- Eerste publieke versie.

