=== Tranzly ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/tranzly
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: translation, multilingual, language switcher, languages
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 3.21.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multilingual WordPress, one post per language: linked translations of posts, pages, terms, media, widgets and the site title.

== Description ==

Tranzly rebuilt from the ground up. Each translation is a real WordPress post (or category, or tag) linked to its original, which is the approach themes, page builders and SEO plugins handle best.

= What this release does =

* **AI agents (MCP) and REST.** Claude, Cursor, VS Code and other MCP-compatible AI assistants can translate your site through its own MCP server, signed in as you with an application password and allowed only what you are allowed. Turn it off in Tranzly, AI agents (MCP).
* **One post per language, linked together.** Create the German version of a post, page or custom post type and Tranzly links the two. A new translation starts as a draft copy of the original's title, content, excerpt, featured image, template and categories (or their translations).
* **Categories, tags and custom taxonomies** get linked translations with their own names, descriptions and slugs.
* **Media, widgets, site title and tagline.** Image alt text, captions and titles, widget text and the site title and tagline can each carry a translation per language, shown to visitors of that language.
* **Unlimited languages**, in the free edition as well, added by their WordPress locale code (for example `fr_FR`). The first one is the language your content is written in.
* **Your old Tranzly translations come with you.** On a site that ran Tranzly 2.x, the old language links, languages and DeepL key are imported in the background without changing the old data, and anything that cannot be imported is reported rather than stopping it. `wp tranzly legacy dry-run` shows what it would do first.
* **Machine translation with your own account.** DeepL (Free and Pro keys), AI models with your own key, and with Pro Google Cloud Translation and Microsoft Translator. See the cost before you start.
* **Translate in the background.** Translate a whole post type into several languages as one job that keeps going after you close the browser; a report shows what failed and why, with one-click retry.
* **Words that are never translated** (brand and product names) in every edition; with Pro, preferred translations, tone per language, translation memory (a text already translated is never paid for again), a different engine per language, automatic fallback and monthly spending caps.
* **For developers:** PHP functions (translations, current language, switching language in code), an interface for adding your own translation engine, hooks, a REST API and WP-CLI (`wp tranzly translate --lang=de --post-type=page`). Pro adds Polylang and WPML function compatibility; the Agency plan adds multisite network set-up.
* **Fast:** a translated page adds at most two database queries and sets no cookie, so page caches keep working. This is measured automatically on every change.
* **Security by design.** Every change needs the right permission and a valid request token; API keys are stored encrypted; the old version's "AI translated by Tranzly" link is off.
* **The language in the address: /de/, /fr/.** Translated pages keep their own translated address (/about/ becomes /de/ueber-uns/), including category, tag and product bases. Old addresses and 3.0's `?lang=` links redirect permanently, so no ranking is lost. With Pro, a subdomain (de.example.com) or a separate domain (example.de) per language.
* **Multilingual SEO done right.** Reciprocal hreflang links with x-default, the page's `lang` and right-to-left `dir`, `og:locale`, canonical addresses per language, and every language in your sitemap (WordPress, Yoast SEO, Rank Math, SEOPress or All in One SEO). With Pro, untranslated copies can be kept out of search results, and a per-language SEO audit finds missing descriptions, broken hreflang and duplicate content.
* **Suggest the visitor's language.** A small, dismissible banner offers a reader the page in their own language, written in that language. It never redirects anybody, so search engines see every page at its own address.
* **Search in the visitor's language.** Site search, archives, the blog and category lists show the language being read.
* **Language switchers everywhere.** A block for the block and site editors in five designs with your own colours, a native Page Builder Sandwich element, a menu item, a widget, a shortcode and a floating button that works on any theme. Each links to this page's own translation. With Pro, native Elementor and Bricks widgets (Bricks is tested against a stand-in of its documented element interface).
* **Accessible switchers.** Each switcher is a named navigation landmark, every language is read in its own language, the current one is marked, and the dropdown works from the keyboard. Flags are optional and off by default: a flag is a country, not a language.
* **Translation that keeps your blocks intact.** Posts are translated block by block: only words change, while structure, code, HTML, shortcodes, links and image addresses stay exactly as they were. Tested on every core block.
* **Your corrections are protected.** When a person edits a translation, background jobs and re-translation leave it alone until you unlock it.
* **Translate from the editor.** The block editor's Translations panel, and the admin bar's Translations menu, translate into the languages you tick with one click and link to every version. Products and anything else edited in the classic editor have a Translations box that does the same, and categories and tags have one on their edit screen.
* **Side-by-side editor.** Correct a translation piece by piece next to the original, each piece marked machine or human. With Pro, click any translated text on the page itself and correct it there.
* **Blocks and images per language.** Show any block only in some languages (a German-only offer in the footer), and with Pro use a different image per language.
* **Menus and shared text.** Menus are translated automatically (their links lead to the translated pages), or use a separate menu per language. With Pro, the text in your block theme's headers, footers, templates and patterns too.
* **Page Builder Sandwich, deeply.** Every Page Builder Sandwich block translates, including the text inside repeated items (tabs, cards, price rows), widgets placed on a page, saved sections and synced patterns, which show in the visitor's language.
* **Translation status at a glance.** For every language: what is translated, missing, out of date or corrected by a person, with one click to translate everything missing or out of date in the background.
* **Other page builders (Pro).** Elementor, Beaver Builder, Bricks, Divi (4 and 5), Oxygen and WPBakery: only visible text is translated; every setting, link and layout stays as it was. (Bricks, Divi, Oxygen and WPBakery are tested against stand-ins of their documented storage formats.)
* **WooCommerce products, complete.** A product translates like any other content, in every edition, from its own edit screen: title, description, short description, categories and tags, and the new translation carries the product's price, sale price, SKU, stock, tax settings, gallery and downloads, so it can be bought straight away. With Pro: price and stock kept in step across languages after that, variations, attributes, the shop, cart, checkout and account pages, the checkout and payment texts, and every customer e-mail, sent in the language the order was placed in.
* **Prices in the visitor's currency (Pro).** Per language, per country or chosen by the visitor, with your own rounding, and rates you set or the European Central Bank's, updated daily.
* **SEO fields (Pro).** Titles, descriptions, social titles and focus keywords of Yoast SEO, Rank Math, SEOPress and All in One SEO, for posts and categories; their variables (%%sitename%%, %title%, #site_title) are kept.
* **Custom fields (Pro).** ACF, Meta Box and Pods fields, with a choice per field: translate it, copy it, or leave it empty.
* **Theme and plugin text (Pro).** Read your theme's or a plugin's own words ("Read more", "Add to cart") and translate them in one place.
* **Forms (Pro).** Contact Form 7, WPForms, Gravity Forms and Fluent Forms: labels, buttons, messages and their e-mails, in the visitor's language, with one list of entries. (Gravity Forms is a paid plugin: tested against a stand-in of its documented form data and filters.)
* **Comments and reviews (Pro, optional).** Show the comments and product reviews of every language on every version, optionally translated into the reader's language.
* **Workflow (Pro).** Bulk translate the whole site with an estimate first; translate new and updated content automatically; Translator and Reviewer roles with approval before publishing; XLIFF and CSV export and import; switch from WPML, Polylang or TranslatePress with languages and links kept; and an AI quality check that names what a person should review.
* **A language API for other plugins**: `tranzly_languages()`, `tranzly_current_language()`, `tranzly_language_url()` and `tranzly_get_translation()`, mirrored read-only under `tranzly/v1`; blocks mark translatable attributes with `"role": "content"`.
* **Footprint-free front end.** The switcher uses neutral class names with a short prefix (`zd` by default), no HTML comments or generator tags, and styles served from `wp-content/uploads/<prefix>-assets/` rather than the plugin's folder.

The admin screens stay clearly branded; only what your visitors see is neutral.

= Build from source =

The admin screen and editor script are built from the human-readable sources in `src/` with `@wordpress/scripts`:

`npm ci && npm run build`

== External services ==

= Zinn Digital® hosting-customer discount (only on sites Zinn Digital® hosts) =

On a WordPress site hosted by Zinn Digital®, the plugin's screen shows administrators a card offering hosting customers a personal discount code for the Pro edition. Nothing is sent when the page loads. Only when an administrator presses the card's button does the site send one request to Zinn Digital® at `https://api.zinndigital.com/v1/wp/pro-discount/<site id>`, containing the plugin's slug, the word `issue` and the administrator's WordPress language, signed with the site's own key. The site's address and key come from constants the platform writes into wp-config.php on the sites it hosts; the card reuses only the host and site id of the address, which ends in `/v1/wp/plugin-update/<site id>`, and never sends anything to that address itself; on any other site they do not exist and the card is never shown. The answer is the customer's code and a Freemius checkout link, to which the browser is then sent. Terms: https://zinndigital.com/legal/terms · Privacy policy: https://zinndigital.com/legal/privacy

= Translation services you choose (DeepL, Google Cloud Translation, Microsoft Translator) =

Tranzly translates through the services you set up in Tranzly → Engines, with your own account and key. Nothing is sent to any of them until you save a key and start a translation, and nothing is ever sent to Zinn Digital®. What is sent is the text being translated (post titles, excerpts and content, term names and descriptions), the source and target language, and your key; the answer is the translation.

* DeepL: `https://api.deepl.com` (DeepL API Pro keys) or `https://api-free.deepl.com` (keys ending in `:fx`). Tranzly calls `/v2/translate` to translate, `/v2/languages` to learn which languages your account supports, `/v2/usage` when you check your usage, and `/v2/glossaries` when you use a glossary (Pro). Terms: https://www.deepl.com/pro-license · Privacy policy: https://www.deepl.com/privacy
* Google Cloud Translation (Pro): `https://translation.googleapis.com/language/translate/v2`. Terms: https://cloud.google.com/terms · Privacy policy: https://policies.google.com/privacy
* Microsoft Translator (Pro): `https://api.cognitive.microsofttranslator.com/translate`. Terms: https://azure.microsoft.com/support/legal/ · Privacy policy: https://privacy.microsoft.com/privacystatement

= Exchange rates (European Central Bank), Pro only, off by default =

Only when you choose "European Central Bank" as the source of exchange rates for WooCommerce currencies, Tranzly downloads `https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml` once a day (and when you press "Update the rates now"). The request sends nothing about your site or its visitors. Terms and privacy: https://www.ecb.europa.eu/services/disclaimer/html/index.en.html

= Licensing and updates (Freemius) =

The plugin bundles the Freemius SDK, which handles licences and updates for the Pro edition and, only if you agree, product usage data.

Nothing is sent until you opt in on the screen shown after activation, or activate a licence. When you do, the SDK sends your site's URL, WordPress, PHP and plugin versions, language, and the administrator's name and email address to Freemius, and checks it periodically for licence status and updates. Before connecting it checks that the service is reachable by requesting `https://api.freemius.com/v1/ping.json`, which sends nothing about your site. You can opt out at any time from the plugin's Account page.

* Service: https://freemius.com
* Terms: https://freemius.com/terms/
* Privacy policy: https://freemius.com/privacy/

AI features use the AI provider you choose, with your own API key. Nothing is sent to any AI provider until you add a key under Settings → AI providers and use an AI feature. When you do, the text the feature needs (for example, the content being written or translated) and your key are sent to that one provider, and to no one else. Keys are stored encrypted in your database and are never sent to Zinn Digital®. The "Save and test" button sends one short test request to the provider.

* OpenAI: https://api.openai.com/v1 (terms: https://openai.com/policies/services-agreement/, privacy policy: https://openai.com/policies/privacy-policy/)
* Anthropic (Claude): https://api.anthropic.com/v1 (terms: https://www.anthropic.com/legal/commercial-terms, privacy policy: https://www.anthropic.com/legal/privacy)
* Google Gemini: https://generativelanguage.googleapis.com/v1beta (terms: https://ai.google.dev/gemini-api/terms, privacy policy: https://policies.google.com/privacy)
* Mistral AI: https://api.mistral.ai/v1 (terms: https://legal.mistral.ai/terms/commercial-terms-of-service/, privacy policy: https://legal.mistral.ai/terms/privacy-policy/)
* DeepSeek: https://api.deepseek.com (terms: https://cdn.deepseek.com/policies/en-US/deepseek-open-platform-terms-of-service.html, privacy policy: https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html)
* OpenRouter: https://openrouter.ai/api/v1 (terms: https://openrouter.ai/terms, privacy policy: https://openrouter.ai/privacy)
* A service you run yourself (any OpenAI-compatible address you enter): only that address is contacted.

Recommended models list (off unless you turn it on). If you turn on the daily check for a newer recommended models list under Settings → AI providers, the plugin requests https://api.zinndigital.com/v1/ai-model-catalogue once a day. The request is a plain download: it carries no key, no site address and nothing about your content, and the list is signed so a changed copy is ignored.

* Service: https://zinndigital.com
* Terms: https://zinndigital.com/legal/terms
* Privacy policy: https://zinndigital.com/legal/privacy

= Help and support from Zinn Digital® (off until you use it) =

The plugin's Get help screen can send a support request to Zinn Digital®, the plugin's developer. Nothing is sent until you connect the site or send a request yourself.

* Connecting the site (Get help → Connect) calls `https://api.zinndigital.com/v1/plugin-support/connections` with the email address and name you type, the plugin's name and version, this site's address and title, the WordPress and PHP versions and your language. The answer is a connection token, stored encrypted in your database. The screen checks it with `/v1/plugin-support/connection`; Disconnect deletes it there and here.
* Sending a request calls `https://api.zinndigital.com/v1/plugin-support/tickets` with what you type (subject, message, your name), the plugin's name, your language, and your licence's plan and ids. Only if you tick "Include site details" does it add the site details the screen shows you before sending (site address, WordPress, PHP, theme and plugin versions, a few server settings and the last lines of the PHP error log). Any login you choose to add is sent over HTTPS, stored encrypted by Zinn Digital®, and deleted 30 days after the request is closed.
* Temporary support access, only if you choose it with a request: the plugin creates a WordPress user on your own site with a support role that cannot install, edit or delete plugins or themes, manage users, update WordPress or export content, sends its login with the request, and deletes the user when the time you picked (1, 3 or 7 days) runs out, or sooner if you remove it on the Get help screen.

== Installation ==

1. Upload the `tranzly` folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Open **Tranzly** in the admin menu and add your languages.

== Frequently Asked Questions ==

= Does it translate my content automatically? =

Yes, with a translation service you choose and your own key: DeepL (Free or Pro) or an AI model in the free edition, and Google Cloud Translation or Microsoft Translator with Pro. Add a key under Tranzly → Engines, then translate one post from the editor or a whole post type as a background job. You can also write translations by hand, or keep the text imported from Tranzly 2.x.

= What happens to my Tranzly 2.x translations? =

They are imported in the background the first time the new version runs. The old data is left untouched. To preview or reverse it: `wp tranzly legacy dry-run`, `wp tranzly legacy undo`, and `wp tranzly legacy run` to import again.

= How is the current language chosen? =

From the address. With language folders (the default) /de/… is German and an address with no folder is your default language; with Pro, the subdomain or domain decides. A visitor's browser language never changes the page they get: it only lets Tranzly offer them their language in a small banner, which you can switch off under Tranzly → Addresses and SEO. Sites that prefer the older `?lang=de` style can choose it there; the language then comes from that parameter, then the post being viewed, then a cookie named after the class prefix (`zd_lang` by default), then the first listed language.

= Can I move from WPML, Polylang or TranslatePress? =

Yes, with Pro. Switch the other plugin off, then Tranzly → Workflow → Switch reads its data where it left it: languages, the links between translations (posts, pages and categories) and translated site texts. From TranslatePress, which keeps no translated pages, Tranzly builds each translation from its saved translations. Nothing of the other plugin is changed, and Undo removes exactly what the import added.

= Will translation break my blocks or page builder layouts? =

No. Tranzly sends a translation service only the words of each block and writes the answer back into the same place, so the layout, links, image addresses, code and HTML blocks are untouched and every block still opens in the editor. A text a person corrected is never overwritten by a later machine translation unless you unlock it.

= Does it work with my SEO plugin? =

Yes. Tranzly adds the multilingual parts (hreflang, language and direction, per-language addresses and every language in the sitemap) and leaves schema to your SEO plugin. With Pro, the SEO titles, descriptions, social titles and focus keywords you wrote in Yoast SEO, Rank Math, SEOPress or All in One SEO are translated too. It is tested with WordPress's own sitemap and all four.

= Can an AI assistant translate my site? =

Yes. Tranzly adds WordPress abilities and its own MCP server at `/wp-json/tranzly/v1/mcp` (WordPress 6.9 or later): languages, engines, translating posts, categories, media, menus and site texts, jobs, status, review, SEO addresses and more — everything the Tranzly screens do, with the same permission checks. In Pro, AI agents can also translate the whole site in the background, run the AI quality check, manage the glossary and export files for human translators. Create an application password under Users, Profile, then follow Tranzly, AI agents (MCP). The same actions are available over the REST API at `/wp-json/wp-abilities/v1/abilities`.

== Changelog ==

= 3.21.2 =
* Fix: the licence-activation fix in the previous release no longer adds a background request handler to the free plugin (it now applies only where the licensing service's own "Activate License" handler exists).

= 3.21.1 =
* Fix: on a site that skipped the connection screen, activating a Pro licence key showed an error (a 500 from the licensing service's bundle check), although the licence had been activated. The bundle check now waits until the site is connected.

= 3.21.0 =
* Fix: with WooCommerce active, every WP-CLI command (for example `wp option get`) used about 6 MB more memory than before the AI agents (MCP) release, which could push a site over a 128M limit. The site's MCP server now starts only for `wp mcp-adapter` and on web requests; define `ZINN_MCP_DISABLED` in wp-config.php to turn every Zinn® MCP server off.

= 3.20.0 =
* AI agents (MCP): when the site refuses an action without a written reason, the AI app now gets a readable one (the error, or the HTTP status) instead of "Failed to execute tool".

= 3.19.0 =
* AI agents (MCP) and REST: 76 abilities and the site's own MCP server — languages, engines, translation of posts, terms, media, menus and strings, jobs, status, SEO and more; in Pro, bulk translation, AI quality check, glossary and translator files. On by default for signed-in users with the right permissions; switch in Tranzly, AI agents (MCP).

= 3.18.3 =
* Legacy Tranzly 1.x/2.x sites: the language links the old plugin showed on translated posts stay after (or before) the content, including on a site that never saved its settings, and the import finishes as soon as the update is installed.

= 3.18.2 =
* Legacy Tranzly 1.x sites: translations made by 1.x (tranzly_* post meta) are imported, and a 1.x language-switcher widget keeps a switcher in its place.

= 3.18.1 =
* Support: a reply address that had to be altered to be valid is refused rather than sent as a different address; temporary support access is limited to 3 active logins and to 1, 3 or 7 days.

= 3.18.0 =
* The block inserter offers one Language switcher (the first release's reference block stays for pages that use it, hidden from the list).

= 3.17.0 =
* The plugin's name is Tranzly again in wp-admin (the longer title is the WordPress.org listing's); the site title and widgets list no longer uses a private WordPress function.

= 3.16.0 =
* Translate categories and tags from their edit screen, image text per language in the Media Library, and the site title, tagline and widgets under Menus and shared text. Pick a language by its name. The status list shows only what you may read.

= 3.15.0 =
* The Tranzly menu now sits below Settings; the licensing SDK uses the plugin's own icon, so nothing is fetched before you opt in.

= 3.14.0 =
* Legacy licences: an unlimited legacy licence is Agency everywhere (Tranzly multisite network layer; PBS white label, client review, network); no more error on the plugin screen for a licence within 30 days of its end.

= 3.13.3 =
* Security: js-yaml 5.4.2 in the build toolchain (GHSA-r3ph-w7gj-g6xm, markdownlint-cli dev dependency); no runtime change.

= 3.13.2 =
* Readme: the WordPress.org edition keeps its disclosure of the hosting-customer discount request, and says plainly that the card never contacts the platform's update address.

= 3.13.1 =
* Maintenance: the readme fits the WordPress.org directory's description limit, and the source (including the shared admin screens) is formatted and linted to WordPress's JavaScript standard. No change in behaviour.

= 3.13.0 =
* On a site hosted by Zinn Digital®, the plugin screen offers hosting customers a personal discount code for their first payment of the Pro edition. Nothing is shown on other sites, and nothing is fetched until you press the button.

= 3.12.0 =
* The AI settings can now choose a model for site search (embeddings), and the shared AI layer gains the embeddings API that Zinn® Chat uses. Nothing else changes.

= 3.11.0 =
* Smaller download: the editable translation sources (.po) are no longer shipped; WordPress only ever loads the compiled .mo and .l10n.php files, which are unchanged.

= 3.10.0 =
* Admin screens: the colour-scheme menu shows a tick next to the option that is in use.

= 3.9.0 =
* Admin screens: in dark mode the colour-scheme and help buttons in the header are visible without hovering over them.

= 3.8.0 =
* Fix: a translation job stopped at the exact moment a translation was saved no longer marks it as edited by a person, so the resumed job finishes it and it can be translated again later.

= 3.7.0 =
* Admin screens: screen readers see one page structure (the admin shell no longer adds a second main area inside WordPress's own).
* Admin screens: dark mode themes the settings panels and links, so every label and description is readable.

= 3.5.0 =
* Works with the page builders, shops, SEO plugins, custom fields and forms you already use: Page Builder Sandwich, Elementor, Beaver Builder, Bricks, Divi, Oxygen and WPBakery pages; every WooCommerce product, variation, attribute, category and e-mail; Yoast, Rank Math, SEOPress and All in One SEO fields; ACF, Meta Box and Pods fields; Contact Form 7, WPForms, Gravity Forms and Fluent Forms with their e-mails.
* Theme and plugin text: find the words your theme and plugins print and translate them in place. Pro: product reviews and comments per language, and prices in each language's currency with manual rates or a daily European Central Bank rate.
* Translation status (free): how much of the site is translated, out of date or waiting, per language, with one-click fixes that translate everything missing or out of date.
* Pro: bulk translation with a word and cost estimate first; automatic re-translation when a page changes; translator and reviewer roles with approval before publishing; XLIFF and CSV export and import for translators; switch from WPML, Polylang or TranslatePress keeping every language link; an AI quality check with your own key.

= 3.4.0 =
* Admin screens: the settings use the full width of the page instead of a narrow column.

= 3.3.0 =
* New admin screens: overview, setup wizard, plans and licence, add-ons, and help and support from inside the plugin.

= 3.1.1 =
* Maintenance: the admin, editor and comparison scripts are formatted to the WordPress coding standard (no change in behaviour).

= 3.1.0 =
* AI: the shared AI core can now read images and make images with OpenAI or Google Gemini (used by Page Builder Sandwich). Choose the image model under Settings → AI providers.

= 3.0.11 =
* Translation that keeps your blocks intact: posts are translated block by block, so layouts, links, image addresses, code and HTML blocks are untouched and every block still opens in the editor (tested on every core block).
* A translation a person edited is protected from background jobs and re-translation until you unlock it.
* Translate from the editor: a Translations panel with one-click translation and links to every version, and a Translations menu in the admin bar.
* Side-by-side editor for correcting a translation piece by piece. Pro: click any translated text on the page itself to correct it.
* Show a block only in some languages; Pro: a different image per language.
* Menus are translated (links lead to the translated pages) or a separate menu per language; typed menu labels can be corrected. Pro: block-theme headers, footers, templates and patterns are translated too.
* Fixed: a post containing the same text twice could not be translated when translation memory was on.

= 3.0.10 =
* Plugin Check: every database query the plugin runs is now a single prepared statement (no change in behaviour or speed).

= 3.0.9 =
* Languages in the address: /de/, /fr/ folders with translated slugs and translated category, tag and product words; old addresses redirect permanently. Pro: a subdomain or a separate domain per language.
* Multilingual SEO: reciprocal hreflang with x-default, html lang and right-to-left direction, og:locale, and every language in the sitemap of WordPress, Yoast SEO, Rank Math, SEOPress and All in One SEO. Pro: hide untranslated pages from search engines, and a per-language SEO audit.
* A polite banner offers visitors their own language (never a redirect); site search, archives and menus show the visitor's language.
* Language switchers: a block in five designs, a Page Builder Sandwich element, a menu item, a widget, a shortcode and a floating button; keyboard and screen-reader friendly, flags optional. Pro: Elementor and Bricks widgets.
* Still at most two database queries per translated page, with WooCommerce too.

= 3.0.8 =
* AI core 1.0.2: an optional per-plugin hook on outgoing requests. Tranzly does not use it, so its requests are unchanged.

= 3.0.7 =
* The licensing SDK's notices, opt-in, licence and pricing screens now appear in the site's language (every SDK string routed through the plugin's own translations).

= 3.0.6 =
* A background translation interrupted while it creates a translation no longer leaves an empty draft behind.

= 3.0.5 =
* Translation engines and the background queue: DeepL (Free and Pro keys), AI models with your own key, Google and Microsoft (Pro); jobs that keep running after you close the browser; translation memory, glossary and do-not-translate list; per-language engines, fallback and monthly caps (Pro); a failed-translation report with retry.

= 3.0.4 =
* Translating a post again (or with --force over a hand-edited translation) no longer unpublishes the translation.

= 3.0.3 =
* Developer API: template functions, engine interface, REST routes and WP-CLI (`wp tranzly translate`), generated hooks reference; Polylang/WPML compatibility (Pro); multisite network set-up (Agency); speed benchmark (at most two extra queries per translated page).
* Translations: one post per language, linked (posts, pages, custom post types, categories, tags, custom taxonomies), media text, widgets and site title/tagline; unlimited languages; automatic import of Tranzly 2.x translations with dry run and undo; the DeepL key moved to encrypted storage; permission and request-token checks on every change.

= 3.0.2 =
* AI core: when a provider's plan excludes a model, say so (instead of 'rate limited') and let Save and test fall through to a model the plan includes.

= 3.0.1 =
* Shared AI core: bring your own key for OpenAI, Anthropic, Gemini, Mistral, DeepSeek, OpenRouter or any OpenAI-compatible service; live model lists; signed recommended-models list; setup wizard with a test button; encrypted keys; clear error messages. Pro: usage and cost log, monthly limits, per-role permissions.

= 3.0.0 =
* Rebuilt foundation: language list, language switcher block, language API and REST mirror, footprint-free front-end output layer, settings and About screen.
