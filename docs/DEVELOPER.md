# Developer guide — Supertext Translation for Akeneo PIM

Architecture, the Supertext API protocol, local development, tests, the demo, screenshots and releases.

## Architecture

A Symfony bundle for Akeneo PIM Community Edition 2026 (Symfony 5.4, PHP 8.3). Composer package `supertext/akeneo-supertext-translation`, namespace `Supertext\AkeneoTranslationBundle`, bundle `SupertextTranslationBundle` (so its assets are published under `public/bundles/supertexttranslation/`).

| Path | Purpose |
| --- | --- |
| `lib/supertext-sdk/` | The [Supertext SDK for PHP](https://github.com/Supertext/Supertext-API-SDK-and-Guide/tree/main/sdk/php), copied (see *Supertext SDK*): the API protocol (upload, polling, download, delete), packing values into HTML documents, Supertext's language list (`Languages`), HTTP 429 retries, curl transport, the API key check (`Client::checkApiKey()`). Namespace `Supertext\Sdk\V1` (API v1; `Supertext\Sdk\Http` for the transport). |
| `lib/supertext-plugin-health/` | [Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health) for PHP, copied (see *Supertext Plugin Health*): the Commando Center reporter (`InstallationReporter`, `ReporterState`, `KeyCheck`, the ping answer). Namespace `Supertext\PluginHealth`. |
| `src/Api/SupertextClient.php` | The bundle's layer over the SDK: environments (Live, Staging, Testing, custom), key normalization, `translateTexts()` and `validateApiKey()`; `forReview()` for locales with *Translation review*. No Akeneo classes. |
| `src/Api/SupertextException.php` | Errors with the UI key (`supertext_translation.error.<key>`); `fromSdk()` maps the SDK's error codes to the keys. |
| `src/Settings/Settings.php`, `SettingsStorage.php`, `SettingsRepository.php` | Settings from System → Supertext (one JSON row in `pim_configuration`, code `supertext_translation`), with `SUPERTEXT_API_KEY`, `SUPERTEXT_API_URL` and `SUPERTEXT_VERSION_REPORTS` taking precedence; the language mapping (`languageCode()`, `sourceCode()`) and *Translation review* per locale (`review()`, `reviewUrl()` from `SUPERTEXT_REVIEW_URL`); the site URL. The reporter's state is a second row (`supertext_translation_reporter`). `SettingsStorage` is the interface the unit tests replace. |
| `src/Reporting/Reporting.php`, `ReportTransport.php` | Commando Center: Plugin Health's `InstallationReporter` with Akeneo's site URL and versions and the SDK's key check, the stored state, deferred actions, the ping answer; `ReportTransport` sends reports through an SDK transport (tests). No Akeneo or Symfony classes. |
| `src/EventListener/ReportingSubscriber.php` | The public ping route (`kernel.request`, priority 512, before routing and the firewall) and the deferred reporter actions (`kernel.terminate`, `console.terminate`). |
| `src/Translation/Planner.php`, `TextUnit.php` | Which source values go into which target locale (channels, locale-specific attributes, existing text). No Akeneo classes. |
| `src/Translation/EntityTranslator.php` | Collects a product's or product model's text values, translates them per target locale and applies them with Akeneo's updater, validator and saver. |
| `src/Controller/TranslateController.php` | Internal API for the dialog: `GET /supertext/rest/{product\|product_model}/{id}` (languages, preview) and `POST …/translate`. |
| `src/Controller/TranslationFileController.php` | Internal API for *Export for translation* / *Import translation*: `POST /supertext/rest/{product\|product_model}/{id}/export` (JSON → the file as an attachment) and `POST /supertext/rest/import` (multipart: `file`, `include_changed`, `overwrite` → a summary per job). See *Translation files*. |
| `src/Translation/TranslationFileGlue.php`, `TranslationFileStore.php`, `TranslationFileError.php` | Export/import with the SDK's `TranslationFiles`, no Akeneo or Symfony classes (unit-tested with a fake store): jobs, reference, language codes, checks per imported job, counts. |
| `src/Translation/AkeneoTranslationFileStore.php` | The Akeneo side: entities by UUID/id, the ACLs, `EntityTranslator::fileUnits()` and `importTexts()`. |
| `src/Controller/SettingsController.php` | Internal API for System → Supertext: `GET`/`POST /supertext/rest/settings`, `POST …/settings/test`. Never returns the key. |
| `src/Command/TranslateCommand.php`, `CheckCommand.php` | `supertext:translate`, `supertext:check`. |
| `src/Resources/config/services.yml`, `routing.yml` | Services (explicit Akeneo service ids) and routes (exposed to FOSJsRouting for the front end). |
| `src/Resources/config/form_extensions/supertext.yml` | Akeneo form extensions: the entry in the "…" menu (`secondary-actions`) of the product and product model edit forms, and the System menu item. |
| `src/Resources/config/requirejs.yml` | Module aliases and the controller for the route `supertext_translation_settings_index`. |
| `src/Resources/public/js/` | Front end (TypeScript/React, built by Akeneo's webpack): `product/translate-action.tsx` (Backbone view for the menu entry), `product/TranslateModal.tsx`, `product/files-action.tsx` and `product/FilesModal.tsx` (*Export for translation* / *Import translation*), `settings/` (System → Supertext), `api.ts`. |
| `src/Resources/translations/jsmessages.{en_US,de_DE,fr_FR,it_IT}.yml` | UI strings (English, German, French, Italian), loaded by Akeneo's `oro/translator` in the user's UI locale. Console messages and logs are English. |

How Akeneo picks up the front end: `bin/console pim:installer:assets` copies `Resources/public` to `public/bundles/supertexttranslation/`, dumps the routes and translations; `yarn run webpack` compiles every `.ts`/`.tsx` under `public/bundles` (with Akeneo's `transpileOnly` TypeScript loader); `yarn run update-extensions` merges all bundles' `form_extensions/*.yml` into `public/js/extensions.json`. That's why installing or updating the bundle needs a front-end rebuild.

**Saving:** the translator calls `pim_catalog.updater.product` (or `…product_model`) with standard-format values, validates with `pim_catalog.validator.product` and saves with `pim_catalog.saver.product` (or `…product_model`), the same path Akeneo's imports use. Versioning (History tab), completeness and the Elasticsearch index follow from that. Violations that appear after applying one language put that language's previous values back, so the other languages can still be saved; violations that existed before are ignored.

**Interface languages:** every text the UI shows comes from the `jsmessages` files. The internal API answers errors with an English `message` (also used by the console and logs) plus a `key`, `params` and an untranslated `detail` (what Supertext, cURL or Akeneo's validator said); `api.ts` → `localize()` shows `supertext_translation.error.<key>` in the user's language with the detail in brackets, and falls back to `message` for an unknown key. `SupertextException` carries the key (keep `src/Api` free of Symfony's translator), as do the per-locale results of `EntityTranslator::translate()` and the validation errors of `Settings::update()`. `tests/unit/TranslationFilesTest.php` checks that all four files have the same keys and placeholders and that every key the PHP code sends has an English text.

**Permissions:** translating checks Akeneo's existing ACLs `pim_enrich_product_edit_attributes` / `pim_enrich_product_model_edit_attributes` (in the form extensions and in the controller); the settings check `oro_config_system`. The bundle adds no ACL of its own, because a new ACL is denied to every role until someone grants it.

### Field rules

Keep these in sync with *What is translated* in [USER_GUIDE.md](USER_GUIDE.md#what-is-translated) and `EntityTranslator`:

- Attribute types `pim_catalog_text` and `pim_catalog_textarea`, localizable only. Text areas with the rich-text editor (`wysiwyg_enabled`) go as HTML, all others as plain text (escaped, line breaks as `<br>`).
- Only the entity's own values (`getValuesForVariation()`): a variant's inherited values belong to its product model.
- Each value in the source locale is one `data-st-id` element, so a whole rich text (all its paragraphs and lists) is translated as one unit with its inline formatting.
- Scopable values go into the locales of their channel; non-scopable ones into any activated locale; locale-specific attributes only into their locales (`Planner`).
- A target value with text (after removing tags and whitespace) is kept unless *overwrite* is set.
- Plain-text translations longer than the attribute's *max characters* are skipped with a message; Akeneo's validator catches the rest.

### Translation files (XLIFF and HTML)

*Export for translation* and *Import translation* use the SDK's `TranslationFiles` (SDK 0.5.0, `Supertext\Sdk\Files`; integration guide → *Translation files: XLIFF and HTML*). Nothing calls the API.

- **Segments:** `EntityTranslator::fileUnits()` — the units `translate()` would send for the target locale with *overwrite* (`Planner::plan(…, overwrite: true)`), keyed by `TextUnit::key()` (`<attribute>|<channel or empty>`), rich text as HTML, plain text as text.
- **Job metadata:** `plugin` = `Akeneo-Supertext-Translation`; `reference` = `<product|product_model>:<uuid or id>:<source locale>:<target locale>` (e.g. `product:0a1b…:en_US:de_CH`; the source locale is part of it because Akeneo has no fixed source language and the import has to collect the same locale's values again; anything else is *not found*); `sourceLanguage` = `Settings::sourceCode()`, `targetLanguage` = `Settings::languageCodeOrNull()` (the mapping, an exact match, the SDK default), else the locale with `-` for `_` (`en_IE` → `en-IE`), so unmapped locales don't block an export. File names `akeneo-<id>-<target code>.xlf` / `.html`, `akeneo-<id>.xlf` for several locales.
- **Import:** the controller refuses uploads over 10 MB (`TranslationFileGlue::MAX_UPLOAD_BYTES`, also PHP's own `UPLOAD_ERR_INI_SIZE`) before reading and parses the temporary upload in memory; `TranslationFiles::read()`; per job: `plugin` must be ours (*other system*), the reference must parse, the user must have the type's ACL (`forbidden_<type>`), the entity must exist (*not found*), both locales must be activated (*no language*); then `importTranslations()` with `fileUnits()` collected again. `ok` texts (and `source_changed` with *include changed*) go to `EntityTranslator::importTexts()`: `Planner::plan()` with the editor's *overwrite* (values with text are kept and counted as `existing`), then `applyTexts()` — the write-back API translations use (empty and too-long texts skipped, Akeneo's updater, previous values back on violations) — and the saver. `untranslated`, `invalid_markup` and `missing` are only counted. Errors carry a `key` (`supertext_translation.error.file_*`) like the other server messages.

## Supertext API protocol

Shared with the WordPress plugin and every other Supertext plugin:

1. `POST {base}translate/ai/file`: multipart with `file` (part `Content-Type` exactly `text/html`, no charset, or the API answers 415), `target_lang` (a Supertext target code, e.g. `de-CH` or `es`), optional `source_lang` (bare language, e.g. `en`); see *Language codes*, optional `politeness` (`more`/`less`). Returns `{file_id}`.
2. `GET …/{file_id}/status` until `done` (`error`, `limit_exceeded`, `deleted` are terminal).
3. `GET …/{file_id}/translation` returns the translated HTML.
4. `DELETE …/{file_id}` (files also expire after 24 h).

Auth header: `Authorization: Supertext-Auth-Key <key>`. The header name must be `Authorization` (`Authentication` gets 403). Supertext shows the key with the prefix, so the client strips a pasted `Supertext-Auth-Key ` and always sends exactly one. Base URLs: `https://api.supertext.com/v1/` (live), `https://api.staging.supertext.com/v1/`, `https://api.testing.supertext.com/v1/`. `GET features` is the cost-free key check (after saving the settings, *Test connection*, `supertext:check`; see *Commando Center*).

**Rate limit:** the API limits requests per second per key (HTTP 429). The SDK retries a 429 up to 4 times, waiting for `Retry-After` if sent, otherwise 1, 2, 4 and 8 seconds plus jitter. Target locales are translated one after the other.

The protocol, the HTML packing (one `<div data-st-id="N">` per value: plain text escaped with line breaks as `<br>`, rich text as is; documents below 900,000 characters) and the error codes are the SDK's; see its [integration guide](https://github.com/Supertext/Supertext-API-SDK-and-Guide/blob/main/docs/integration-guide.md).

Locale codes: see *Language codes*.

**User-Agent:** `supertext-sdk-php/<SDK version> Akeneo-Supertext-Translation/<version>` (the bundle's version as in the reports, `dev` without one), so Supertext and the review service see which release calls.

**Translation review** (`languages.<locale>.review`, a checkbox per locale under System → Supertext → Languages, off by default): `EntityTranslator` takes `SupertextClient::forReview(Settings::review($target))` for each target locale, a second SDK client with the same key, timeout, transport and User-Agent whose base URL is `Settings::reviewUrl()` (`SUPERTEXT_REVIEW_URL`, else `SupertextClient::REVIEW_URL`, the translation proxy of [Supertext Localization Review](https://github.com/Supertext/Localization-Review-Supertext-Translation)), created once per request. The SDK's `translateTexts()` uploads, polls, downloads and deletes with one client, so a job is always collected by the client that submitted it. The proxy speaks the same API v1, forwards to the live Supertext API with the plugin's key, stores every segment per key and serves reviewers' corrections on the next upload, so nothing else changes in the bundle. `SUPERTEXT_REVIEW_URL` set but empty (or the same URL as the API) means `forReview()` always returns the direct client and the page's checkboxes are read-only. The key check (`Reporting`, its own SDK client), the reports and translation files never use the review service. Tests: `SupertextClientTest` (a review locale's upload, status, download and delete go to the review URL with the same key and User-Agent, other locales and the key check directly, no second client without a review URL or with the API's URL) and `SettingsTest` (saving the checkbox, off by default, the default URL, `SUPERTEXT_REVIEW_URL` and empty = off).

## Language codes

Supertext accepts exactly the codes of its `GET features` (the SDK's `Languages::TARGETS` / `SOURCES`, October 2026): targets are regional only for German, English, French, Italian and Portuguese (`de-CH`, `en-US`, `pt-BR`), have a script for Chinese and Serbian (`zh-Hans`, `sr-Latn`), a dialect for Swiss German (`gsw-u-sd-chzh`, `gsw-u-sd-chbe`) and are **bare for all others** (`es`, `nl`, `sl`, `ja`, …); sources are always bare (`en`). Anything else is HTTP 404 `INVALID_LANGUAGE_PAIR`, including the "default regions" earlier versions added (`es-ES`, `sl-SI`, `zh-CN`). Politeness exists for every target except `en-GB`, `en-US`, `gsw-u-sd-*`, `rm`, `sq`, `sr-Cyrl`, `sr-Latn` (otherwise HTTP 400 `FEATURE_ERROR` → `unsupported_feature`).

**Mapping, exact match, default.** `Settings::languageCode()` / `sourceCode()` resolve an Akeneo locale with the SDK's `Languages::target($locale, $mapping)` / `Languages::source($locale, $mapping)`, using one mapping for both (`Settings::mapping()`: the *Supertext language* per locale under System → Supertext → Languages, stored as `languages.<locale>.code`):

1. The mapping's code, if the locale has one (sent as it is, so a language Supertext adds before the SDK lists it works; as source in its source form: a `de_DE` mapped to `de-CH` is sent as `de`).
2. Else a Supertext code equal to the locale apart from case and `_`/`-` (`de_CH` → `de-CH`; as source the first subtag: `en_US` → `en`).
3. Else, for targets, the SDK's default (`Languages::defaultTarget()`, SDK 0.4.0): bare `de`/`en`/`fr`/`it`/`pt` → `de-DE`/`en-US`/`fr-FR`/`it-IT`/`pt-PT`, `zh`/`zh_CN`/`zh_SG` → `zh-Hans`, `zh_TW`/`zh_HK`/`zh_MO` → `zh-Hant`, `sr` → `sr-Latn`, `sr_Latn_RS` → `sr-Latn`, a region of a bare target → the bare target (`es_ES` → `es`, `nb_NO` → `nb`). Nothing else is guessed (`en_IE`, `de_LI`, `fr_BE`, `sr_RS`, `gsw`).
4. Else `unsupported_language` (SDK error, mapped to the UI key `unsupported_language` with `{{ language }}`, which names the drop-down), and nothing is sent. The translate dialog greys out such locales (`unmapped` in the context answer: `languageCodeOrNull()` is null), the settings page shows *— Choose —* with *No default for this locale*, `supertext:check` lists them.

The settings page (`SettingsPage.tsx`, Akeneo's `SelectInput`) has one drop-down per activated locale with `Settings::targetOptions($uiLocale)`: the SDK's `Languages::targetOptions()` with names from PHP intl (`Locale::getDisplayName`; the two Swiss German subdivisions are named after their city, since ICU says `sd=chzh`), sorted by that name with `Collator`, shown as "Name (code)". `SettingsController` preselects the saved code, else `Settings::suggestedLanguageCode()` (`Languages::suggestTarget()`: exact match, else default), else nothing (*— Choose —*). Saving (`Settings::update()`) accepts only codes from the list (normalized to Supertext's spelling: `ES` → `es`, `fr_ch` → `fr-CH`) or the code already saved for that locale (so codes saved by versions before 0.4.0 that the list doesn't have survive; the page adds them as an extra option), and stores the chosen code of every locale, including the preselected one. Values stored by earlier versions have the same shape (`languages.<locale>.code`), so there is no migration step; locales they left unmapped now get the default. The *Form of address* select is shown only where the chosen target has `politeness`; `update()` drops *Formal*/*Informal* for other targets, and `Settings::politeness()` returns `''` for them, so an old value never causes `unsupported_feature`. There is no environment or config override for the mapping. `SupertextClient` sends the codes it is given unchanged. The stand-in (`tests/docs/stand-in.mjs`) accepts only Supertext's codes, like the real API.

## Commando Center (version reports, API key check, ping)

Every Supertext plugin implements the center's *Report protocol* (Commando-Center-Supertext-Translation → `docs/DEVELOPER.md`); the protocol itself is [Supertext Plugin Health](#supertext-plugin-health)'s `InstallationReporter` (`reportsEnabled()`, `pingResponse()`, `ReporterState`), with the SDK's `checkApiKey()` and `Protocol::apiKeyFingerprint()` as its key checker (`CallableKeyChecker`) and `Supertext::SDK_VERSION` as `sdk_version`. `src/Reporting/Reporting.php` adds what Akeneo knows:

- **Reporter options:** plugin `Akeneo-Supertext-Translation` (the GitHub repository name) with `Settings::version()` (Composer, from the Git tag); system `Akeneo PIM` with the version of `akeneo/pim-community-dev` from Composer; runtime PHP (Plugin Health's default); site URL `AKENEO_PIM_URL`, else the request's scheme, host and base path (`Settings::siteUrl()`); callback `<site URL>/supertext/ping`; the key check goes to the configured API (`Settings::baseUrl()`), the report to `SUPERTEXT_REPORT_URL` or Plugin Health's default. Without a test transport, the key check uses the SDK's curl transport and the report Plugin Health's.
- **Enabled:** `InstallationReporter::reportsEnabled($settings->versionReports(), SUPERTEXT_VERSION_REPORTS)`: the variable wins (read from `$_SERVER`/`$_ENV`/`getenv`, since Symfony's Dotenv doesn't `putenv`), else the setting `version_reports`, else on. Without a site URL (a command without `AKENEO_PIM_URL`) the reporter is built disabled, so only the key check runs.
- **When:**

  | Where | Action |
  | --- | --- |
  | `SettingsController::saveAction` (after storing) | `afterSettingsSaved`, synchronously: its `check` is returned in the payload (`key_check`) and shown on the page |
  | `SettingsController::testAction` (*Test connection*) | `testConnection` with the key typed in the form (else the saved one) against the API chosen in the form |
  | `SettingsController::getAction`, `TranslateController` (context and translate), `supertext:translate` | `onRequest`, deferred |
  | a translation result with key `auth_failed` (401/403) | `authenticationFailed` with the HTTP status, deferred (`Reporting::deferAfterTranslation()`) |
  | `supertext:check` | `testConnection`, then `reportCheck` (a `check` report) |

  Deferred actions run in `ReportingSubscriber` on `kernel.terminate` (after the response is sent; with PHP-FPM the connection is already closed) or `console.terminate`. Front-end page views never run the reporter.
- **State:** Plugin Health's `ReporterState` as JSON (the same JSON the SDK 0.1–0.2 reporter stored, so installations keep their history: no new `installed` report after the switch) in `pim_configuration`, code `supertext_translation_reporter` (`Settings::reporterState()` / `saveReporterState()`, which swallow database errors). It holds the versions last reported, the key status last reported, the time of the last failed report and the latest key check (status, time, HTTP status, Supertext's error code and a SHA-256 fingerprint of the key, kept locally and never sent). Never the key.
- **Never breaks anything:** Plugin Health never throws; `Reporting::run()` catches everything else (storage included) and returns null.
- **Ping:** `ReportingSubscriber::onRequest` answers `GET /supertext/ping` on the main request at `kernel.request` priority 512, i.e. before the router (32), the session (128) and Akeneo's firewall (8), so it needs no login, opens no session and has no CSRF token. It sends `Reporting::ping()` = `InstallationReporter::pingResponse()` (200 with the challenge, 400 for a malformed challenge, 404 while reports are off) as status, headers and body; Symfony's response adds `private` to `Cache-Control: no-store`. Other methods fall through to Akeneo (login redirect).
- **Settings page:** `version_reports` (checkbox; read-only with the variable's value when `SUPERTEXT_VERSION_REPORTS` is set), `key_check` (shown as `keycheck.<status>` in the four languages, with the time and, in the text, links to supertext.com → Integrations → API and the signup), `ping_url`.

## Local development

The quickest setup is the demo image (below), which builds Akeneo with the bundle. To work on the bundle in your own Akeneo project, add this repository as a Composer path repository:

```bash
composer config repositories.supertext '{"type": "path", "url": "../Akeneo-Supertext-Translation", "options": {"symlink": true}}'
composer require supertext/akeneo-supertext-translation:@dev
# register the bundle and the routes (docs/INSTALLATION.md), then:
bin/console pim:installer:assets --symlink --clean && yarn run webpack-watch   # front end
yarn run update-extensions                                                     # after changing form_extensions/*.yml
```

PHP changes need `rm -rf var/cache`; translation changes need `bin/console pim:installer:assets`.

## Tests

```bash
phpunit                                                     # unit tests (src/Api with the SDK, Settings, Reporting, Planner, interface translations, export/import glue)
find src tests demo/project/supertext-demo -name '*.php' -print0 | xargs -0 -n1 php -l
```

The unit tests need only PHPUnit 12 (no Akeneo install). `tests/unit/SettingsTest.php` covers the language mapping (exact match, SDK defaults, no guessing without a default, mapping for target and source, politeness only where offered, saving and validation, localized target names) and the reporting setting, *Translation review* per locale and `SUPERTEXT_REVIEW_URL`; `tests/unit/ReportingTest.php` the reporter glue with a fake transport for Supertext and the center (when each action reports, failures and the one-hour backoff, reports off, state without the key, state stored by the SDK 0.2 reporter, ping answers); `tests/unit/FileExportImportTest.php` the export/import glue with a fake store (reference round trip, the translator's keys and the language mapping in the jobs, other plugin / unknown reference / missing locale / no permission, `ok` written, `source_changed` only with the option, existing values kept unless overwriting, untranslated / invalid / missing not written, files over 10 MB refused); `tests/unit/SupertextClientTest.php` the bundle's client over the SDK (environments, key prefix, error keys, Translation review); `tests/Support/MemoryStorage.php` replaces the database. `tests/demo-check.sh` is the end-to-end test: it starts the demo image twice against MySQL and Elasticsearch with the stand-in API, checks the demo accounts (created once, passwords never logged), translates the sample product and product model with `supertext:translate`, checking the stored values, calls the public ping route and checks that the stand-in's fake Commando Center received an `installed` and a `check` report (`SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report`), without the API key.

**CI** (`.github/workflows/ci.yml`): lint and unit tests on every push; the `phpstan` job runs PHPStan (see *Code quality and security checks*); the `demo` job builds the demo image (about 20 minutes: Composer, Yarn and Akeneo's webpack build) and runs `tests/demo-check.sh` against MySQL 8.4 and Elasticsearch 8.17 service containers. `.github/workflows/checks.yml` and `links.yml` lint the workflows and check the links. `.github/workflows/e2e.yml` runs the end-to-end test against the real Supertext API and regenerates the docs screenshots (see *End-to-end tests*).

## End-to-end tests

The unit tests and `tests/demo-check.sh` use fakes. `.github/workflows/e2e.yml` also tests against the **real Supertext API**, so changes on Supertext's side (language codes, regional variants, formatting, rate limits) show up. It runs on every push to `main` that touches the bundle, the demo or the tests, on pull requests that change the E2E test or workflow, every Monday (05:03 UTC), and by hand (*Actions → E2E → Run workflow*). It builds the demo image once (Docker layer cache in GitHub Actions) and starts it twice, each with its own database (`AKENEO_DB_NAME=akeneo_live` / `akeneo_docs`), its own Elasticsearch service (ports 9200 / 9201, so the search indexes don't mix) and demo accounts made up for the run:

1. **Real API** (repository or organization secret `SUPERTEXT_API_KEY`; without it these steps are skipped with a warning). Version reports are off (`SUPERTEXT_VERSION_REPORTS=0`), so CI runs don't appear in the Commando Center.
   - `tests/e2e/features.mjs` asks the live `GET features` (cost-free, no plugin or SDK code) whether every pair the demo translates (`en` → `de-CH`, `fr-CH`, `it-CH`, i.e. the locales `en_US` → `de_CH`, `fr_CH`, `it_CH` as the bundle sends them) is offered; a missing pair fails the run. Whether the plugin's own language list matches the API is the SDK's job (its own tests).
   - `tests/e2e/live.mjs` (Playwright): the demo editor (`DEMO_EDITOR_*`, role *Catalog manager*) logs in and translates the sample product `praline-box-16` and the product model `dark-chocolate-bar` through Akeneo's UI (edit form → "…" → *Translate with Supertext*, every activated locale ticked). The test checks the dialog's request and answer (every locale sent and `translated`), that no error is shown, and then the database (`raw_values`): every English value has a value in each target locale, at least 80 % of them changed (a single word may stay the same), no `data-st-id`/placeholder is left, plain-text values stay plain text, bold, links (with their target), paragraphs, list items and line breaks are kept, the text is in the target language (common words of each language counted), `de_CH` has no "ß", the product stays enabled or disabled as it was, the variants keep their values (they inherit the texts) and no product or product model is created. Translating again with *Overwrite existing translations* must update the same products in place. Each run translates the two samples twice into three languages (around 4,000 characters on the key's account).
2. **Stand-in API**: the docs screenshots (see *Docs screenshots*; the demo gets no `SUPERTEXT_API_KEY`, and `SUPERTEXT_REPORT_URL` points at the stand-in), so the images don't change with each machine translation. If `docs/images/` changed, a second job pushes the branch `automation/docs-screenshots` and opens (or updates) the pull request "Docs: regenerate screenshots" (only for runs on `main`). This needs *Settings → Actions → General → Allow GitHub Actions to create and approve pull requests*.

If a run fails, the artifact `e2e-debug` has the dialog and result screenshots (`tests/e2e/artifacts/`), the demo containers' logs, Akeneo's application logs and the stand-in log. Locally:

```bash
cd tests/e2e && npm install && npx playwright install chromium
# a fresh demo (new database and Elasticsearch) with a real SUPERTEXT_API_KEY, e.g. on port 8081 with AKENEO_DB_NAME=akeneo_live
SUPERTEXT_API_KEY=… E2E_SOURCE=en E2E_TARGETS=de-CH,fr-CH,it-CH node features.mjs
AKENEO_URL=http://127.0.0.1:8081 DATABASE_URL=mysql://root:root@127.0.0.1:3306/akeneo_live \
  EDITOR_EMAIL=… EDITOR_PASSWORD=… npm run live
```

`live.mjs` also passes against the stand-in API (it returns real translations for the samples), which is how to try changes to the test without a key.

## Supertext SDK

The bundle uses the [Supertext SDK for PHP](https://github.com/Supertext/Supertext-API-SDK-and-Guide/tree/main/sdk/php) for everything that talks to Supertext. The SDK isn't on Packagist yet, so `lib/supertext-sdk/` holds a copy of its `sdk/php/src` and `autoload.php` (namespace `Supertext\Sdk`, unchanged), mapped in the bundle's `composer.json` (`autoload.psr-4`). `lib/supertext-sdk/COMMIT` records the SDK commit, `VERSION` its version (0.5.0), `README.md` says where it comes from.

- **Never edit `lib/supertext-sdk/` by hand.** Fix the SDK repository (all five SDKs, see its `CLAUDE.md`), then update the copy: `scripts/vendor-sdk.sh [commit-or-branch]` (default `main`; `SDK_DIR=/path/to/clone scripts/vendor-sdk.sh` uses a local clone as it is). It replaces the folder entirely. Run the tests and commit `lib/` together with any bundle changes.
- CI checks that the copy matches the recorded commit (it runs `scripts/vendor-sdk.sh` with the commit in `COMMIT` and fails if `lib/supertext-sdk/` changes).
- Once the SDK is on Packagist: require `supertext/supertext-sdk`, delete `lib/` and the script, and remove the `Supertext\Sdk\` line from `autoload.psr-4`. No code changes are needed (same namespace).
- The SDK provides the client and the API key check; since SDK 0.3.0 the Commando Center reporter is in [Supertext Plugin Health](#supertext-plugin-health).
- The bundle uses the SDK's API v1 namespace `Supertext\Sdk\V1` (`Client`, `Segment`, `Languages`, `ApiKeyCheck`, `Protocol`, `SupertextException`); a later API version would come as `V2` next to it. Since SDK 0.2.0 there is no `LanguageCodes` (default regions) any more: see *Language codes*.

## Supertext Plugin Health

[Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health) is the dependency-free library every Supertext plugin uses for the version reports, the report of the API key check's result and the answer of the ping endpoint (see *Commando Center*); the key check itself is the SDK's. It lives in the Commando Center's repository (`plugin-health/`) and isn't on Packagist, so `lib/supertext-plugin-health/` holds a copy of its `plugin-health/php/src` (namespace `Supertext\PluginHealth`, unchanged like the SDK's), mapped in the bundle's `composer.json` (`autoload.psr-4`), plus a Composer-free `autoload.php` for the tests and PHPStan. `COMMIT` and `VERSION` record where it comes from.

- **Never edit `lib/supertext-plugin-health/` by hand.** Fix it in Commando-Center-Supertext-Translation/plugin-health (all five languages, see its README), then update the copy: `scripts/vendor-plugin-health.sh <tag>` (e.g. `plugin-health-v0.1.0`; default `main`; `PLUGIN_HEALTH_DIR=/path/to/clone` uses a local clone as it is). It replaces the folder entirely.
- CI checks that the copy matches the recorded commit, like the SDK's.

## Demo (Railway)

The demo is a container built from `demo/Dockerfile`: PHP 8.3 with Apache, Akeneo PIM Community Edition 2026.5 (minimal catalog) with this bundle, the locales English (`en_US`), German, French and Italian (Switzerland) (`de_CH`, `fr_CH`, `it_CH`) on the *E-commerce* channel, an English sample product and a sample product model with two variants. On Railway it needs three services: the demo (this repository, `railway.json` → `demo/Dockerfile`), MySQL (shared, the demo uses its own database `AKENEO_DB_NAME`) and Elasticsearch 8.

It runs in the Railway project `supertext-cms-demos-php`, region EU West (Amsterdam): service **Akeneo** at <https://akeneo-production.up.railway.app/> (log in with the `DEMO_*` e-mail address), the shared **MySQL-8** service (`MYSQL_URL` built from `${{MySQL-8.MYSQL_ROOT_PASSWORD}}` and `mysql8.railway.internal`, database `akeneo`) and the service **Elasticsearch** (`elasticsearch.railway.internal:9200`). The `DEMO_*` and `SUPERTEXT_API_KEY` variables reference the Contao demo's (`${{Contao.DEMO_ADMIN_EMAIL}}` and so on), so the demos share their accounts; `APP_SECRET` is generated by Railway (`${{secret(48)}}`).

**Deploys:** Railway builds `main` of this repository. If a push doesn't start a deployment, check that Railway's GitHub app has access to the repository, or redeploy the service.

**What's in `demo/`:**

| Path | Purpose |
| --- | --- |
| `Dockerfile` | Stages: PHP 8.3 + Apache and Akeneo's extensions; Composer install of `demo/project/composer.json` (Akeneo 2026.5) and Yarn install of Akeneo's front-end packages; the bundle (Composer path repository), the demo's config and the front-end build; the runtime image. A change to the bundle only repeats the last build stage. |
| `docker/env.sh` | Turns `MYSQL_URL` into Akeneo's `APP_DATABASE_*` (database `AKENEO_DB_NAME`, default `akeneo`) and sets `APP_INDEX_HOSTS`, `AKENEO_PIM_URL` |
| `docker/entrypoint.sh` | Every start: database, Akeneo install with the minimal catalog on the first start, search index rebuild if Elasticsearch lost it, `supertext:demo-setup`, the job queue worker, Apache on `$PORT` |
| `docker/console.sh` | `demo-console …`: `bin/console` as `www-data` with the demo's settings (`docker exec demo demo-console supertext:check`) |
| `project/` | Overlay on the Akeneo standard project: `composer.json`, `config/bundles.php`, `config/routes/supertext_translation.yml`, `config/services/supertext_demo.yml` and `supertext-demo/DemoSetupCommand.php` |
| `.env.example` | The variables below |

**Demo setup** (`supertext:demo-setup`, every start, only adds what is missing): the four locales on the *E-commerce* channel, the attribute group *Marketing* with *Name* (text), *Short description* (text area), *Description* (rich text, per channel) and *Weight* (simple select), the family *Chocolate*, the family variant *Chocolate by weight*, the sample product `praline-box-16`, the product model `dark-chocolate-bar` with the variants `dark-chocolate-bar-100g` and `-400g`, and the demo accounts.

**Service variables:**

| Variable | |
| --- | --- |
| `MYSQL_URL` | `${{MySQL.MYSQL_URL}}`; the database `AKENEO_DB_NAME` (default `akeneo`) is created on that server if missing |
| `APP_INDEX_HOSTS` | Elasticsearch `host:port`, e.g. `${{Elasticsearch.RAILWAY_PRIVATE_DOMAIN}}:9200` |
| `APP_SECRET` | Random secret (keep it stable) |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | Administrator (role *Administrator*, all user groups) |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | Editor for automated tests and screenshots: role *Catalog manager*, group *Redactor*. Akeneo has no "editor" role; *Catalog manager* is the closest built-in one and may edit products and product models in every locale (the Community Edition has no locale permissions). |
| `SUPERTEXT_API_KEY` | Optional: without it, enter the key under System → Supertext |
| `SUPERTEXT_API_URL` | Optional, e.g. a stand-in API (to try the review service, tick *Translation review* for a locale instead) |
| `SUPERTEXT_REVIEW_URL` | Optional: another review service than Supertext's for locales with *Translation review* on (unset: Supertext's; set but empty: review off) |
| `SUPERTEXT_VERSION_REPORTS` | Optional: `0` turns the version reports off (on by default; the public demo reports like any installation) |
| `SUPERTEXT_REPORT_URL` | Tests only: a fake Commando Center, e.g. the stand-in's `http://127.0.0.1:8765/report` |
| `PORT` | Port Apache listens on (Railway sets it) |

**Elasticsearch service:** image `docker.elastic.co/elasticsearch/elasticsearch:8.17.0` with `discovery.type=single-node`, `xpack.security.enabled=false`, `indices.id_field_data.enabled=true` and `ES_JAVA_OPTS=-Xms512m -Xmx512m`. Without a volume the index is lost on restart; the entrypoint then recreates it and re-indexes the products.

**Demo accounts:** on every start the setup creates the `DEMO_ADMIN` and `DEMO_EDITOR` accounts if no account with that e-mail address exists. Existing accounts are never changed (change passwords in Akeneo). Log in with the e-mail address (the username is its part before the `@`). Akeneo requires passwords of 8 to 4096 characters; otherwise the account is skipped with a warning naming the variable, and the demo still starts. Passwords are never logged.

**No installer screen:** Akeneo has no web installer; the entrypoint installs the minimal catalog, which has no users, so the `DEMO_*` accounts are the only ways in.

**Run it locally:**

```bash
docker network create akeneo
docker run -d --name mysql --network akeneo -e MYSQL_ROOT_PASSWORD=root mysql:8.4
docker run -d --name elasticsearch --network akeneo -e discovery.type=single-node -e xpack.security.enabled=false \
  -e indices.id_field_data.enabled=true -e ES_JAVA_OPTS="-Xms512m -Xmx512m" docker.elastic.co/elasticsearch/elasticsearch:8.17.10
docker build -f demo/Dockerfile -t supertext-akeneo-demo .
docker run --rm --name demo --network akeneo -p 8080:8080 \
  -e MYSQL_URL=mysql://root:root@mysql:3306/railway -e APP_INDEX_HOSTS=elasticsearch:9200 -e APP_SECRET=dev \
  -e DEMO_ADMIN_EMAIL=you@example.com -e DEMO_ADMIN_PASSWORD='choose-one' supertext-akeneo-demo
# http://localhost:8080 (the first start installs Akeneo: a few minutes)
```

## Docs screenshots

The images in `docs/images/` are generated by `tests/docs/screenshots.mjs` (Playwright) from a freshly set-up demo (no translations yet) whose bundle talks to `tests/docs/stand-in.mjs`. The stand-in returns German, French and Italian for the sample product and product model (`samples.json`). Regenerate them whenever a screen they show changes:

```bash
cd tests/docs && npm install && npx playwright install chromium
npm run stand-in &
# a fresh demo with SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/, SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report,
# no SUPERTEXT_API_KEY and DEMO_* set, served on :8090
BASE_URL=http://127.0.0.1:8090 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… \
  PRODUCT_UUID=<uuid of praline-box-16> npm run screenshots
```

The script saves a placeholder API key as the administrator first, shows the live API address instead of the stand-in's on the settings page and a fixed time in the key check message, so the images only change when the UI does. The stand-in also answers version reports (`POST /report`, 202), so screenshots never report to the live Commando Center. The E2E workflow (see *End-to-end tests*) runs the script against a fresh demo and opens a pull request when the images changed.

## Demo movie

`docs/images/demo.gif` (at the top of the README) and two MP4 movies are recorded from a fresh demo against the stand-in, with the shared kit in `tests/docs/movie/` (Playwright screencast + ffmpeg; Supertext intro and outro cards, captions, a visible cursor; waiting time is cut). Set up the demo and the stand-in as for the screenshots (no `SUPERTEXT_API_KEY`), then record the install movie first: it saves the made-up key `st-demo-0000-0000-0000`, which the demo movie uses.

```bash
cd tests/docs && pip install piper-tts   # the voice; ffmpeg must be installed too
BASE_URL=http://127.0.0.1:8090 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… PRODUCT_UUID=<uuid of praline-box-16> npm run movie:install
BASE_URL=http://127.0.0.1:8090 DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… PRODUCT_UUID=<uuid of praline-box-16> npm run movie
```

- **Demo movie** (`movie.mjs`, about 45 s): the editor opens the sample product in English, chooses **… → Translate with Supertext**, keeps German and French, clicks **Translate** and checks the German version (locale switcher). Writes `docs/images/demo.gif` (800 px, 12 fps, silent; keep it under 3 MB) and `tests/docs/movie/out/Akeneo-Supertext-Translation.mp4`.
- **Install and configuration movie** (`movie-install.mjs`, about 90 s, MP4 only): the commands from `docs/INSTALLATION.md` in a terminal (Composer, `bundles.php` and routes, cache, assets and front-end build), the channel's locales, **System → Supertext** with the API key, the automatic key check, the German locale's *Supertext language*, *Translation review* and where editors find *Translate with Supertext*. Writes `tests/docs/movie/out/Akeneo-Supertext-Translation-install.mp4`. The settings page shows the live API address instead of the stand-in's.
- The MP4s are not committed (`tests/docs/movie/out/` is git-ignored; `MOVIE_OUT` writes elsewhere). Re-record when the screens they show change.
- **Voice:** Piper (`pip install piper-tts`), voice "alba", downloaded once (63 MB, checked by SHA-256) into `tests/docs/movie/voice-cache/` (git-ignored). `MOVIE_VOICE=0` makes silent movies. Voice: Piper voice “alba” (en_GB), trained on a dataset from the University of Edinburgh's Centre for Speech Technology Research, CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/)
- The fonts in `tests/docs/movie/assets/fonts/` are Inter (SIL Open Font License, `Inter-LICENSE.txt`).

## Code quality and security checks

- **Checks** (`.github/workflows/checks.yml`): actionlint and zizmor lint the workflows on every push and pull request; dependency review fails a pull request that adds a package with a known vulnerability (moderate or worse). Third-party actions are pinned to commit SHAs (Dependabot keeps them current). Run the linters locally with `pip install actionlint-py zizmor`, then `actionlint` and `zizmor .github/workflows`.
- **Links** (`.github/workflows/links.yml`): lychee checks the links in all Markdown files weekly and whenever docs change on `main`. Broken links open (or update) the issue "Broken links in the docs". Links that can't work from CI (local addresses, pages behind a login, placeholders) are excluded in `.lycheeignore`.
- **PHPStan** (job `phpstan` in `ci.yml`, configuration in `phpstan.neon`): level 5 on `src/`. PHPStan needs Akeneo's classes, so the job installs Akeneo PIM from `demo/project/composer.json` (without scripts) and loads its autoloader through `tests/phpstan-bootstrap.php`. Locally, point `AKENEO_VENDOR` at the `vendor/` folder of any Akeneo PIM 2026 project: `AKENEO_VENDOR=/path/to/pim/vendor phpstan analyse` (PHPStan 2.x, e.g. the `phpstan.phar` from its GitHub releases). Existing findings that aren't fixed yet are listed in `phpstan-baseline.neon` (regenerate with `phpstan analyse --generate-baseline` after fixing some); new code must not add any.
- **GitHub settings** (set by Remy's setup script, not in the repo): secret scanning with push protection (a push containing a known token format is rejected; findings under *Security → Secret scanning*) and CodeQL default setup (findings under *Security → Code scanning* and as pull request comments). CodeQL doesn't cover PHP, which is why this repo runs PHPStan.
- `.github/workflows/support-triage.yml`: support requests from the Supertext integrations website arrive as issues labelled `support` (contact details stay in the Supertext CMS). Claude classifies each one: answers questions (marked `<!-- supertext:reply -->`, emailed to the sender by the CMS) and closes them, fixes bugs as pull requests for review (never merged automatically), labels feature requests `feature-request`. Only people with triage rights can add labels, so outsiders can't start it. Needs the organisation secret `ANTHROPIC_API_KEY`; the workflow is the same in every Supertext repo.

Before starting work in this repo, look at its open findings: code scanning alerts, secret scanning alerts, Dependabot pull requests and the issue "Broken links in the docs".

## Dependency updates

Dependabot (`.github/dependabot.yml`) opens weekly pull requests: minor and patch updates grouped into one, GitHub Actions in another, each major update on its own.

`.github/workflows/dependabot-merge.yml` merges a minor or patch package update by itself once every other check on its pull request has passed (it waits up to 85 minutes and leaves the pull request alone if a check fails). GitHub Actions updates and majors stay for a person: the workflow's built-in token can't merge changes to workflow files, and a major needs a decision. Merges by the workflow don't start CI on `main`; the weekly health check looks at `main`.

## Releasing

Releases are published by `.github/workflows/release.yml` when the version is officially bumped; nobody tags or creates releases by hand.

1. Move the *Unreleased* entries in `CHANGELOG.md` under a new `## X.Y.Z — YYYY-MM-DD` section, and keep an empty *Unreleased* above it.
2. There is no version field to change: Composer takes the version from the Git tag the workflow creates (don't add `version` to `composer.json`). System → Supertext and `supertext:check` read it at runtime with `Composer\InstalledVersions` (`Settings::version()`) and link X.Y.Z versions to their GitHub release.
3. Push to `main`. The workflow tags `vX.Y.Z` and creates the GitHub release with the CHANGELOG section as notes (0.x versions as pre-releases). A push that adds no new version does nothing, and a version that is already released is skipped. After fixing a failed run, start it again with *Run workflow* on the *Release* workflow.

Planned: submit the package to Packagist.

## Conventions

- PSR-12, PHP 8.3, strict types; keep `src/Api/`, `src/Reporting/Reporting.php`, `src/Settings/Settings.php` and `src/Translation/Planner.php` free of Akeneo and Symfony classes (the unit tests run without Akeneo).
- Supertext protocol changes go into the SDK repository, never into `lib/supertext-sdk/` (see *Supertext SDK*); report protocol changes into Plugin Health, never into `lib/supertext-plugin-health/` (see *Supertext Plugin Health*).
- New settings: `src/Settings/Settings.php` (`update()` validates), `SettingsController::payload()`, `settings/SettingsPage.tsx`, all four `jsmessages` files **and** the settings table in [INSTALLATION.md](INSTALLATION.md#settings).
- UI strings in `jsmessages.en_US.yml`, `jsmessages.de_DE.yml`, `jsmessages.fr_FR.yml` and `jsmessages.it_IT.yml`: every new string in all four (formal address: Sie, vous, Lei; Akeneo's own terms in each language, e.g. fr *locale*, *canal*, *modèle de produit*, it *impostazione locale*, *canale*, *modello di prodotto*; never translate "Supertext", placeholders or URLs). Quote YAML values that contain a colon followed by a space (French: a no-break space before `?` and `:`). New server messages get a `key` with an `error.*` text in all four files.
- The bundle name, the route names and the form extension codes are part of users' installations; renaming them is a breaking change.
- Keep the three docs in `docs/` current with every change (see `CLAUDE.md`).

## Known limitations / roadmap

- Export for translation / import translation: one product or product model per export from the edit form (no grid mass action yet), and the demo check doesn't run an export → import round trip (it would need a logged-in session).
- One product or product model at a time from the edit form, inside the editor's request (one locale after the other, up to *Timeout* each). Bulk translation from the product grid (a mass action running as a background job) is planned; until then, use `supertext:translate` with several identifiers.
- Only text and text area attributes. Not yet: table attributes, attribute option labels, asset and reference entity texts (Enterprise Edition), category and attribute labels.
- Community Edition only; Enterprise Edition's locale and category permissions are not checked yet.
- Not on Packagist yet.
- Re-translating with *Overwrite existing translations* replaces manual changes; no change detection. A translation memory and reviewers' corrections come from the review service when a locale has *Translation review* on; corrections reach Akeneo only with the next translation (no push).
- Human (professional) translation orders are not supported yet (the WordPress plugin has them).
