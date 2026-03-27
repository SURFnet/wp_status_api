=== Status API ===
Contributors: hannowybrenmook
Tags: status, api, rest-api
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.9.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expose een REST API endpoint met status-/storingsinformatie (met Bearer-token authenticatie).

== Description ==

Status API voegt een endpoint toe waarmee je de huidige status kunt ophalen.

== Installation ==

1. Upload de plugin map naar `/wp-content/plugins/wp_status_api/`
2. Activeer de plugin via het 'Plugins' menu in WordPress

== Frequently Asked Questions ==

= Hoe authenticeer ik? =

Gebruik een Bearer token in de `Authorization` header. De token kun je genereren via de instellingenpagina van de plugin.

== Usage ==

Endpoint:

`GET /wp-json/status-api/v1/status`

Voorbeeld:

`curl -H "Authorization: Bearer <TOKEN>" https://example.com/wp-json/status-api/v1/status`

== Changelog ==

= 0.9.2 =
* Eerste publieke versie.

