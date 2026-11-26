=== Tranzly™ ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/tranzly
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: translation, multilingual, language switcher, ai translation
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 3.27.64
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translate WordPress into unlimited languages with DeepL or AI, with full international SEO: hreflang, translated URLs and sitemaps.

== Description ==

**Tranzly™ takes your WordPress site international, and gets every language found in search.** Each translation is a real post, page, product or term with its own address, linked to the original. Search engines see a properly built multilingual site: reciprocal hreflang tags with x-default, translated slugs (`/about/` becomes `/de/ueber-uns/`), a canonical address, `lang` and `dir` and `og:locale` for each language, and every language in your sitemap.

Because every translation is an ordinary WordPress page in its own language, your theme, page builder, cache and SEO plugin keep working in every language, with nothing extra to configure.

Translate by hand, or one click at a time with DeepL or the AI model you already pay for. Or ask your AI assistant (Claude, Cursor, VS Code or any MCP-compatible client) to do it through Tranzly's built-in MCP server.

**Unlimited languages, in the free edition.** No word limits, no Tranzly account, and no translation proxy in between.

**Free 24/7 support for everyone.** Free and Pro users alike get round-the-clock support from our team as standard, at no cost.

= Highlights =

* **International SEO built in:** hreflang with x-default, translated slugs and category bases, a canonical address per language, multilingual sitemaps for WordPress core, Yoast SEO, Rank Math, SEOPress, All in One SEO and Zinn® SEO, and permanent redirects so no ranking is lost.
* **Unlimited languages** by WordPress locale (`fr_FR`, `de_DE`, `ar`), right-to-left included.
* **Machine translation with your own key:** DeepL (Free and Pro API keys), or an AI model from OpenAI, Anthropic (Claude), Google Gemini, Mistral, DeepSeek, OpenRouter or any OpenAI-compatible server you run. See the cost before you start.
* **MCP server and WordPress Abilities for AI assistants:** more than 50 actions, covering everything the Tranzly screens do (languages, engines, translating posts, terms, media, menus and site texts, jobs, status and review), signed in as you with an application password and allowed only what you are allowed.
* **Block-safe translation:** only the words change. Blocks, HTML, code, shortcodes, links and image addresses stay exactly as they were.
* **Background jobs:** translate a whole post type into several languages while you get on with something else, with a failure report and one-click retry.
* **Native-quality typography:** quotation marks, apostrophes and spacing follow each language's house style (French « », German „…“, Japanese spacing), and AI models follow each WordPress.org translation team's style guide for tone and form of address.
* **Human corrections are protected:** a text a person edited is never overwritten by a later machine translation until you unlock it.
* **Translate from where you work:** the block editor's Translations panel, the admin bar, the classic editor box, the category and tag screens, and a side-by-side editor that marks each piece as machine or human.
* **Translation status at a glance:** what is translated, missing, out of date or corrected by a person, per language, with one click to fill the gaps.
* **Backward compatible with earlier versions:** a site that ran Tranzly 2.x keeps its languages, translation links and DeepL key, imported in the background without changing the old data. `wp tranzly legacy dry-run` shows what will happen first.

= What gets translated (free) =

* Posts, pages and custom post types.
* Categories, tags and custom taxonomies, with their own names, descriptions and slugs.
* **WooCommerce products:** title, descriptions, categories and tags. The translation carries the price, sale price, SKU, stock, tax settings, gallery and downloads, so it can be bought straight away.
* Menus (automatically, or a separate menu per language).
* Media alt text, captions and titles; widgets; the site title and tagline.
* Blocks shown only in chosen languages, for example a German-only offer in the footer.
* **Page Builder Sandwich, in depth:** every block, including tabs, cards and price rows, widgets, saved sections and synced patterns.
* Words you never want translated, such as brand and product names.

= Multilingual SEO =

* Language in the address (`/de/`, `/fr/`) with translated slugs: `/about/` becomes `/de/ueber-uns/`, including category, tag and product bases.
* Reciprocal hreflang with x-default, the page's `lang` and `dir`, `og:locale` and a canonical address per language.
* Every language in your sitemap: WordPress core, Yoast SEO, Rank Math, SEOPress, All in One SEO and Zinn® SEO.
* Old addresses and `?lang=` links redirect permanently, so no ranking is lost.
* A small, dismissible banner offers visitors the page in their own language. It never redirects, so search engines see every page at its own address.
* Site search, archives and category lists show the language being read.

= Language switchers =

* A block for the block and site editors in five designs (list, pills, buttons, dropdown, language codes) with your own colours.
* A menu item, a widget, a shortcode and a floating button that works with any theme.
* A native Page Builder Sandwich element.
* Accessible by design: a named navigation landmark, every language name read in its own language, the current one marked, and full keyboard support. Flags are optional and off by default.

= Fast, secure and light on the front end =

* A translated page adds at most two database queries and, with language folders, sets no cookie, so page caches keep working.
* Every change checks permissions and a request token, and API keys are stored encrypted.
* Neutral front-end markup: short prefixed class names, no generator tags, and switcher styles served from your uploads folder.

= For developers =

* Template functions: `tranzly_languages()`, `tranzly_current_language()`, `tranzly_language_url()` and `tranzly_get_translation()`.
* A REST API (`tranzly/v1`), WP-CLI (`wp tranzly translate --lang=de --post-type=page`), hooks, and an interface for your own translation engine.
* Multisite: every site in a network keeps its own languages and translations.

= Compatibility =

* **WordPress:** 6.8 or later; AI assistants (MCP and Abilities) need 6.9 or later. Block editor, site editor and classic editor.
* **SEO plugins:** Yoast SEO, Rank Math, SEOPress, All in One SEO, Zinn® SEO and WordPress core sitemaps.
* **WooCommerce:** products in the free edition; the full shop with Pro.
* **Page builders:** Page Builder Sandwich (free and Pro); Elementor, Beaver Builder, Bricks, Divi 4 and 5, Oxygen and WPBakery (Pro).
* **Themes and other plugins:** works with most well-built WordPress themes and plugins, block themes and classic themes alike, because translations are ordinary WordPress content. The plugins named here are the ones we test against by name.
* **Custom fields (Pro):** ACF, Meta Box and Pods.
* **Forms (Pro):** Contact Form 7, WPForms, Gravity Forms and Fluent Forms.
* **Directories and payments (Pro):** GeoDirectory, GetPaid and UsersWP.
* **Moving from another plugin (Pro):** WPML, Polylang and TranslatePress, with languages and links kept.
* **Caching:** works with page caches; with language folders (the default) no cookie is set.

= Tranzly Pro =

Everything listed above is free, with no time limit, no word limit and no locked buttons. Tranzly Pro is a separate paid upgrade for sites that need more. It is sold through Freemius from https://zinndigital.com/wordpress-plugins/tranzly and adds:

* **More engines and smarter routing:** Google Cloud Translation and Microsoft Translator, a different engine or AI model for each language, automatic fallback, monthly spending caps per provider, and translation memory so a text already translated is never paid for twice.
* **More AI assistant tools:** bulk translation of the whole site, the AI quality check, glossary management and translator file export over MCP.
* **The full WooCommerce shop:** variations, attributes, price and stock kept in step across languages, shop, cart, checkout and account pages, payment texts and every customer e-mail in the language of the order, plus prices in the visitor's currency.
* **Every page builder:** Elementor, Beaver Builder, Bricks, Divi, Oxygen and WPBakery, plus native Elementor and Bricks switcher widgets.
* **SEO fields:** titles, descriptions, social titles and focus keywords of Yoast SEO, Rank Math, SEOPress and All in One SEO, a per-language SEO audit, and noindex for untranslated copies.
* **A subdomain or domain per language** (`de.example.com`, `example.de`).
* **Custom fields, forms, theme and plugin texts, block theme templates and patterns, and images per language.**
* **Comments and reviews** shared across languages, optionally translated.
* **Workflow:** automatic translation of new and updated content, Translator and Reviewer roles with approval before publishing, XLIFF and CSV export and import, and an AI quality check.
* **Visual editing:** click any translated text on the page and correct it there.
* **Agency plan:** multisite network set-up with network-wide default languages.

The free plugin never shows upgrade notices on other admin screens, and nothing in it stops working if you never upgrade.

== External services ==

= AI apps you connect (MCP sign-in) =

Only when an AI app such as Claude or ChatGPT starts connecting to the site's MCP server does the site fetch that app's public OAuth client metadata from the address the app gives, for example `https://claude.ai/oauth/mcp-oauth-client-metadata` or `https://chatgpt.com/oauth/client.json`. No site content is sent. Anthropic's and OpenAI's terms and privacy policies are listed under AI features below.

= Zinn Digital® hosting-customer discount (only on sites Zinn Digital® hosts) =

On a WordPress site hosted by Zinn Digital®, the plugin's screen shows administrators a card offering hosting customers a personal discount code for the Pro edition. Nothing is sent when the page loads. Only when an administrator presses the card's button does the site send one request to Zinn Digital® at `https://api.zinndigital.com/v1/wp/pro-discount/<site id>`, containing the plugin's slug, the word `issue` and the administrator's WordPress language, signed with the site's own key. The site's address and key come from constants the platform writes into wp-config.php on the sites it hosts; the card reuses only the host and site id of the address, which ends in `/v1/wp/plugin-update/<site id>`, and never sends anything to that address itself; on any other site they do not exist and the card is never shown. The answer is the customer's code and a Freemius checkout link, to which the browser is then sent. Terms: https://zinndigital.com/legal/terms · Privacy policy: https://zinndigital.com/legal/privacy

= Translation services you choose (DeepL, Google Cloud Translation, Microsoft Translator) =

Tranzly translates through the services you set up in Tranzly → Engines, with your own account and key. Nothing is sent to any of them until you save a key and start a translation, and nothing is ever sent to Zinn Digital®. What is sent is the text being translated (post titles, excerpts and content, term names and descriptions), the source and target language, and your key; the answer is the translation.

* DeepL: `https://api.deepl.com` (paid DeepL API keys) or `https://api-free.deepl.com` (keys ending in `:fx`). Tranzly calls `/v2/translate` to translate, `/v2/languages` to learn which languages your account supports, `/v2/usage` when you check your usage, and `/v2/glossaries` when you use a glossary (Pro). Terms: https://www.deepl.com/pro-license · Privacy policy: https://www.deepl.com/privacy
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

Recommended models list (off until ticked). The daily check under Settings → AI providers requests the signed list from https://api.zinndigital.com/v1/ai-model-catalogue, sending no key, site address or content. Connecting to Zinn® credits does not turn it on (in Pro it does).

* Service: https://zinndigital.com
* Terms: https://zinndigital.com/legal/terms
* Privacy policy: https://zinndigital.com/legal/privacy

= Help and support from Zinn Digital® (off until you use it) =

The plugin's Get help screen can send a support request to Zinn Digital®, the plugin's developer. Nothing is sent until you connect the site or send a request yourself.

* Connecting the site (Get help → Connect) calls `https://api.zinndigital.com/v1/plugin-support/connections` with the email address and name you type, the plugin's name and version, this site's address and title, the WordPress and PHP versions and your language. The answer is a connection token, stored encrypted in your database. The screen checks it with `/v1/plugin-support/connection`; Disconnect deletes it there and here.
* Sending a request calls `https://api.zinndigital.com/v1/plugin-support/tickets` with what you type (subject, message, your name), the plugin's name, your language, and your licence's plan and ids. Only if you tick "Include site details" does it add the site details the screen shows you before sending (site address, WordPress, PHP, theme and plugin versions, a few server settings and the last lines of the PHP error log).

== Installation ==

1. Upload the `tranzly` folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Open **Tranzly** in the admin menu and add your languages.

== Frequently Asked Questions ==

= Is support included? =

Yes. Everyone, free and Pro, gets free 24/7 support from the Zinn Digital® team as standard. Use the plugin's Get help screen or the support forum here.

= Is the free version limited? =

No. Languages, words and posts are unlimited, and every feature in the free list works without a licence or a Tranzly account. You pay only your translation service (DeepL or an AI provider), with your own key, and only if you use machine translation. Pro adds features; it never unlocks something in the free plugin.

= Can an AI assistant translate my site? =

Yes. Tranzly adds WordPress abilities and its own MCP server at `/wp-json/tranzly/v1/mcp` (WordPress 6.9 or later): languages, engines, translating posts, categories, media, menus and site texts, jobs, status, review, SEO addresses and more — everything the Tranzly screens do, with the same permission checks. In Pro, AI agents can also translate the whole site in the background, run the AI quality check, manage the glossary and export files for human translators. Create an application password under Users, Profile, then follow Tranzly, AI agents (MCP). The same actions are available over the REST API at `/wp-json/wp-abilities/v1/abilities`. You can switch it off under Tranzly, AI agents (MCP).

= Does it translate my content automatically? =

Yes, with a translation service you choose and your own key: DeepL (Free or Pro) or an AI model in the free edition, and Google Cloud Translation or Microsoft Translator with Pro. Add a key under Tranzly → Engines, then translate one post from the editor or a whole post type as a background job. You can also write translations by hand, or keep the text imported from Tranzly 2.x.

= Which AI providers can I use? =

OpenAI, Anthropic (Claude), Google Gemini, Mistral AI, DeepSeek, OpenRouter, or any OpenAI-compatible server you run yourself, each with your own API key. With Pro you can choose a different provider and model for each language.

= Is it backward compatible with earlier versions of Tranzly? =

Yes. On a site that ran Tranzly 2.x, the languages, translation links and DeepL key are imported in the background the first time the current version runs, and the old data is left untouched. Anything that cannot be imported is reported rather than stopping the import. To preview or reverse it: `wp tranzly legacy dry-run`, `wp tranzly legacy undo`, and `wp tranzly legacy run` to import again.

= Does it work with WooCommerce? =

Yes. In the free edition a product translates like any other content, from its own edit screen, and the translation carries the price, SKU, stock, tax settings, gallery and downloads. Pro adds variations, attributes, the shop, cart, checkout and account pages, customer e-mails in the order's language, and prices in the visitor's currency.

= Will translation break my blocks or page builder layouts? =

No. Tranzly sends a translation service only the words of each block and writes the answer back into the same place, so the layout, links, image addresses, code and HTML blocks are untouched and every block still opens in the editor. A text a person corrected is never overwritten by a later machine translation unless you unlock it.

= Does it work with my SEO plugin? =

Yes. Tranzly adds the multilingual parts (hreflang, language and direction, per-language addresses and every language in the sitemap) and leaves schema to your SEO plugin. With Pro, the SEO titles, descriptions, social titles and focus keywords you wrote in Yoast SEO, Rank Math, SEOPress or All in One SEO are translated too. It is tested with WordPress's own sitemap and all four.

= How is the current language chosen? =

From the address. With language folders (the default) /de/… is German and an address with no folder is your default language; with Pro, the subdomain or domain decides. A visitor's browser language never changes the page they get: it only lets Tranzly offer them their language in a small banner, which you can switch off under Tranzly → Addresses and SEO. Sites that prefer the older `?lang=de` style can choose it there; the language then comes from that parameter, then the post being viewed, then a cookie named after the class prefix (`zd_lang` by default), then the first listed language.

= Can I move from WPML, Polylang or TranslatePress? =

Yes, with Pro. Switch the other plugin off, then Tranzly → Workflow → Switch reads its data where it left it: languages, the links between translations (posts, pages and categories) and translated site texts. Nothing of the other plugin is changed, and Undo removes exactly what the import added.

= Does it slow my site down? =

No. A translated page adds at most two database queries and, with language folders (the default), sets no cookie, so page caches keep serving it.

= How do I build the JavaScript from source? =

The admin screen and editor script are built from the human-readable sources in `src/` with `@wordpress/scripts`. The `package.json` and `package-lock.json` that pin the build tools ship in the plugin folder; run this there:

`npm ci && npm run build`

The same source and build tools are public at https://github.com/Zinn-Digital/tranzly

== Changelog ==

= 3.27.64 =
* New directory listing: the plugin is now named Tranzly™ – AI Translation & Multilingual SEO, with a rewritten description, FAQ and compatibility list. No code changes.

= 3.27.63 =
* Translations refreshed for the latest screen texts in every language.

= 3.27.62 =
* Dependency security update (Renovate).

= 3.27.61 =
* WordPress.org review fixes: nothing is written into or removed from the must-use, plugins or themes folders by free code without your action, add-ons hook in through actions, and translated text is filtered for every role.

= 3.27.60 =
* An AI provider key saved by an older version is re-sealed from the admin screens only, never during a visitor's request; small admin screen fixes.

= 3.27.59 =
* Dependency security update (Renovate).

= 3.27.58 =
* WordPress.org review fixes and a shared admin kit update (diagnostics and promotions panels).
* Shared AI and MCP code update: stored AI keys are sealed with a random key for each site, a private AI address needs an explicit setting in wp-config.php, and the WordPress.org version no longer carries the MCP sign-in.

= 3.27.57 =
* Maintenance release.

= 3.27.56 =
* Dependency security update (Renovate).

= 3.27.55 =
* Dependency security update (Renovate).

= 3.27.54 =
* Dependency security update (Renovate).

The full history of every release is in changelog.txt, in the plugin's folder.
