=== Tranzly ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/tranzly
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: translation, multilingual, language switcher, languages
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 3.0.8
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multilingual WordPress, one post per language: linked translations of posts, pages, terms, media, widgets and the site title.

== Description ==

Tranzly rebuilt from the ground up. Each translation is a real WordPress post (or category, or tag) linked to its original, which is the approach themes, page builders and SEO plugins handle best. This release holds the translations; the translation engines (machine translation with your own key) arrive in the next release.

= What this release does =

* **One post per language, linked together.** Create the German version of a post, page or custom post type and Tranzly links the two: each knows the other, and a visitor reading one is in its language. A new translation starts as a draft copy of the original's title, content, excerpt, featured image and template, and keeps its categories (or their translations, where they exist). Nothing else is copied.
* **Categories, tags and custom taxonomies** get linked translations with their own names, descriptions and slugs.
* **Media, widgets, site title and tagline.** Image alt text, captions and titles, widget text and the site title and tagline can each carry a translation per language, shown to visitors of that language.
* **Unlimited languages**, in the free edition as well.
* **Your old Tranzly translations come with you.** On a site that ran Tranzly 2.x, the first load of this version imports the old language links in the background, adds their languages to your list, and moves your DeepL key into encrypted storage. It never changes the old data, so it can be undone, and anything it could not import (a link to a deleted post, two posts claiming the same language) is reported rather than stopping it. `wp tranzly legacy dry-run` shows what it would do first.
* **Machine translation with your own account.** DeepL (Free and Pro keys), AI models with your own key, and with Pro Google Cloud Translation and Microsoft Translator. See the cost before you start.
* **Translate in the background.** Translate a whole post type into several languages as one job; it keeps going after you close the browser, and a report shows exactly what failed and why, with one click to retry with another engine or to translate it by hand.
* **Words that are never translated** (brand and product names) in every edition; with Pro, preferred translations, tone per language, translation memory (a text already translated is never paid for again), a different engine per language, automatic fallback and monthly spending caps.
* **For developers:** PHP functions (translations, current language, switching language in code), an interface for adding your own translation engine, hooks, a REST API and WP-CLI (`wp tranzly translate --lang=de --post-type=page`). Pro adds Polylang and WPML function compatibility; the Agency plan adds multisite network set-up.
* **Fast:** a translated page adds at most two database queries and sets no cookie, so page caches keep working. This is measured automatically on every change.
* **Security by design.** Every change needs the right permission and a valid request token; API keys are stored encrypted; the old version's "AI translated by Tranzly" link is off.
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

= Translation services you choose (DeepL, Google Cloud Translation, Microsoft Translator) =

Tranzly translates through the services you set up in Tranzly → Engines, with your own account and key. Nothing is sent to any of them until you save a key and start a translation, and nothing is ever sent to Zinn Digital®. What is sent is the text being translated (post titles, excerpts and content, term names and descriptions), the source and target language, and your key; the answer is the translation.

* DeepL: `https://api.deepl.com` (DeepL API Pro keys) or `https://api-free.deepl.com` (keys ending in `:fx`). Tranzly calls `/v2/translate` to translate, `/v2/languages` to learn which languages your account supports, `/v2/usage` when you check your usage, and `/v2/glossaries` when you use a glossary (Pro). Terms: https://www.deepl.com/pro-license · Privacy policy: https://www.deepl.com/privacy
* Google Cloud Translation (Pro): `https://translation.googleapis.com/language/translate/v2`. Terms: https://cloud.google.com/terms · Privacy policy: https://policies.google.com/privacy
* Microsoft Translator (Pro): `https://api.cognitive.microsofttranslator.com/translate`. Terms: https://azure.microsoft.com/support/legal/ · Privacy policy: https://privacy.microsoft.com/privacystatement

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

From the `lang` query parameter when it names a listed language, then from the language of the post being viewed, then from a cookie named after the class prefix (`zd_lang` by default), then the first listed language.

== Changelog ==

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
