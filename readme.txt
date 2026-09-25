=== Tranzly ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/tranzly
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: translation, multilingual, language switcher, languages
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 3.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The foundation release of the rebuilt Tranzly: your site's language list, a language switcher block, and a language API for other plugins.

== Description ==

This is the first release of Tranzly rebuilt from the ground up. It contains the foundations the translation features are built on, and nothing that pretends to be more than that: it does not translate content yet.

= What this release does =

* **A list of your site's languages.** Add languages by their WordPress locale code (for example `fr_FR`). The first one is the language your content is written in.
* **A language switcher block** that links to the current page in each listed language, using a `lang` query parameter. It appears once two or more languages are listed.
* **A language API for other plugins**: `tranzly_languages()`, `tranzly_current_language()`, `tranzly_language_url()`, `tranzly_get_translation()` and `tranzly_translatable_attributes()`, with a read-only REST mirror under `tranzly/v1`. Blocks mark which attributes are translatable with `"role": "content"` in their block.json.
* **Footprint-free front end.** The switcher's markup uses neutral class names that start with a short prefix (`zd` unless you change it), with no HTML comments or generator tags, and its styles are served from `wp-content/uploads/<prefix>-assets/` rather than the plugin's folder (inline if that folder cannot be written).
* **Settings and About screen** under the Tranzly menu, including the beta-update status for licensed installations.

The admin screens stay clearly branded; only what your visitors see is neutral.

= Build from source =

The admin screen and editor script are built from the human-readable sources in `src/` with `@wordpress/scripts`:

`npm ci && npm run build`

== External services ==

The plugin bundles the Freemius SDK, which handles licences and updates for the Pro edition and, only if you agree, product usage data.

Nothing is sent until you opt in on the screen shown after activation, or activate a licence. When you do, the SDK sends your site's URL, WordPress, PHP and plugin versions, language, and the administrator's name and email address to Freemius, and checks it periodically for licence status and updates. Before connecting it checks that the service is reachable by requesting `https://api.freemius.com/v1/ping.json`, which sends nothing about your site. You can opt out at any time from the plugin's Account page.

* Service: https://freemius.com
* Terms: https://freemius.com/terms/
* Privacy policy: https://freemius.com/privacy/

== Installation ==

1. Upload the `tranzly` folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Open **Tranzly** in the admin menu and add your languages.

== Frequently Asked Questions ==

= Does this release translate my content? =

No. It stores your languages, tells other plugins which language a visitor is viewing, and lets visitors switch. Translation arrives in later releases.

= How is the current language chosen? =

From the `lang` query parameter when it names a listed language, then from a cookie named after the class prefix (`zd_lang` by default), then the first listed language.

== Changelog ==

= 3.0.0 =
* Rebuilt foundation: language list, language switcher block, language API and REST mirror, footprint-free front-end output layer, settings and About screen.
