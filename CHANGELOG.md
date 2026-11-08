# Changelog

All notable changes to Supertext Translation for Akeneo PIM.

## Unreleased

### Added
- **Translation review** on/off per locale (System → Supertext → Languages, a checkbox next to the locale's *Supertext language*, off by default): translations into a locale with *Translation review* ticked go through Supertext's review service (Supertext Localization Review), where customers and Supertext linguists correct them on the website with a Chrome extension; corrections come back into Akeneo with the next translation (with *Overwrite existing translations*), and texts translated before keep their (reviewed) wording. Same API key, billing, languages and messages; other locales keep going directly to Supertext. New environment variable `SUPERTEXT_REVIEW_URL` (defaults to Supertext's review service; set but empty turns review off for every locale). Existing installations start with review off. All four interface languages.
- Requests to Supertext name the bundle and its version in the User-Agent (`Akeneo-Supertext-Translation/<version>`, was `Akeneo-Supertext/<version>`).
- **Export for translation (XLIFF 1.2 or HTML) and import of translated files; Supertext SDK 0.5.0.** Two new entries in the "…" menu of the product and product model edit forms: *Export for translation* (language to translate from, file format, target locales; downloads `akeneo-<id>-<language>.xlf` or `.html`) for a translation tool, a translator or Supertext's web app, and *Import translation* (upload, then a summary per product and locale: imported, kept because the value already has its own text, skipped because the source changed since the export, not translated, formatting changed, not in the file). Imported texts are saved like translations from Supertext (existing values kept unless *Overwrite existing translations*, Akeneo's validation and history). Same permissions as translating; files up to 10 MB. In English, German, French and Italian.
- Docs: a demo movie at the top of the README (`docs/images/demo.gif`) and an install and configuration movie (MP4, not committed), recorded from the demo by `tests/docs/movie.mjs` and `tests/docs/movie-install.mjs`, with voice.
- Support: the Supertext support form is linked in the README, the user guide and GitHub's "New issue" page.

### Changed
- Tested with Akeneo PIM Community Edition 2026.5; the demo and CI now install 2026.5 instead of 2026.4.
- **Languages: defaults where they are obvious** (Supertext SDK 0.4.0). A locale nobody mapped now uses its default instead of failing: `es_ES` → `es`, `nl_NL` → `nl`, `zh_CN` → `zh-Hans`, `zh_TW` → `zh-Hant`, bare `de` → `de-DE`. System → Supertext → Languages preselects each locale's drop-down with its exact match or default, shows the names in your interface language, and stores the language you save for every locale. Only locales without a default (e.g. `en_IE`, `de_LI`) show *— Choose —* and can't be translated until a language is chosen. Saved languages are kept as they are.
- The *Form of address* choice is shown only for languages that offer it; a *Formal*/*Informal* saved earlier for another language is no longer sent (it made the translation fail).
- Only languages from Supertext's list can be saved (a code saved by an earlier version is kept).
- Version reports, the API key check result and the reachability ping now use Supertext Plugin Health 0.1.0 (bundled in `lib/supertext-plugin-health/`); Supertext SDK updated to 0.3.0. No change for installations: settings and stored state stay as they are.
- **Languages: no guessing.** Supertext accepts only its own codes (regional for German, English, French, Italian and Portuguese, e.g. `de-CH`; bare for most others, e.g. `es`, `nl`). Each locale is sent as its *Supertext language* from System → Supertext → Languages, else as the Supertext code it equals (`de_CH` → `de-CH`); locales with neither (e.g. `es_ES`, `nl_NL`) must be mapped and otherwise aren't sent (new message "The language … has no Supertext language yet"). The default regions of earlier versions are gone: they were wrong for most languages (`es-ES`, `sl-SI`, `zh-CN` are rejected by Supertext). Check the language table after updating.
- System → Supertext → Languages offers Supertext's languages (name and code) per locale, pre-filled with the exact match, marks locales that aren't mapped, and notes where Supertext offers no formal/informal choice (new message "Supertext offers no formal or informal tone for this language"). The translate dialog greys out locales without a Supertext language.
- *Test connection* and every save now show the result of the automatic API key check with its time (the connection works, the key was rejected, no key, Supertext not reachable, an error), with the links to create an account and generate a key.
- The bundled Supertext SDK is used through its API v1 namespace.
- Talks to Supertext through the [Supertext SDK for PHP](https://github.com/Supertext/Supertext-API-SDK-and-Guide) (bundled in `lib/supertext-sdk/` until it is on Packagist) instead of the bundle's own client. Same behaviour; error messages about the API key now include the links to the Supertext signup and API key pages, and an unsupported language pair gets its own message naming both codes.

### Fixed
- A target language Supertext doesn't know is now reported as an unsupported language pair, naming both codes (was: not found).
- A Supertext language code with a variant under System → Supertext → Languages (e.g. `de_DE_formal`) is sent to Supertext as `de-DE`.

### Added
- **Send version information to Supertext** (System → Supertext, on by default; `SUPERTEXT_VERSION_REPORTS=0` turns it off): the site URL and the versions of the bundle, Akeneo PIM, PHP and the Supertext SDK, whether a key is set and the result of the key check go to Supertext when the settings are saved, after an update and when the key check's result changes. No content, no API key, no personal data.
- Public ping endpoint `GET /supertext/ping` (no login), so Supertext can see that the PIM is reachable; 404 while version reports are off.
- `supertext:check` lists the Supertext language of every locale and sends a version report.
- French and Italian interface (and German where it was missing): the dialog, System → Supertext and all messages from the server (Supertext errors, validation, permissions) follow the user's UI locale.
- First version for Akeneo PIM Community Edition 2026 (tested with 2026.4).
- *Translate with Supertext* in the "…" menu of the product and product model edit forms: translate from one locale into the others, with a preview per locale ("values to translate", "has its own text") and an explicit *Overwrite existing translations* option with a warning.
- Translates localizable text and text area attributes, rich text with its formatting, every channel's value of scopable attributes into that channel's locales, and only a variant's own values.
- Saves through Akeneo's updater, validator and saver (history, completeness and search index stay right); a locale whose translation Akeneo rejects is left unchanged.
- System → Supertext: API key (with or without the `Supertext-Auth-Key` prefix; the page links to the Supertext signup and the API key page, *supertext.com → Integrations → API*, Admin role required), Live/Staging/Testing API or a custom address, timeout, Supertext language code and form of address per locale, *Test connection*. `SUPERTEXT_API_KEY` and `SUPERTEXT_API_URL` take precedence.
- Uses Akeneo's permissions: *Edit attributes of a product / product model* to translate, *System configuration* for the settings.
- Console commands `supertext:translate` and `supertext:check`.
- The installed version on System → Supertext (linked to its GitHub release) and in `supertext:check`.
- Retries when the Supertext API answers HTTP 429 (rate limit).
- English and German interface.
- Demo for Railway (`demo/`) with demo accounts, four locales, a sample product and a sample product model created on every start.

### Fixed
- A *Supertext language code* without a region (e.g. `de`) is now sent as `de-DE` (`fr` → `fr-FR`, `en` → `en-US`, …) and codes such as `de-ch` are normalized to `de-CH`; Supertext rejects target languages without a region. The source language is sent without region, as before.
