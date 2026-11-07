# User guide — Supertext Translation for Akeneo PIM

For editors: translate products and product models into your other locales with Supertext AI, then review the result in Akeneo as usual.

The Supertext dialog and its messages follow your Akeneo interface language (English, German, French or Italian; set it as *UI locale* under *My settings*). The messages below are the English texts.

## Translate a product

1. Open the product (Products → click the product) and save any changes first.
2. Click the **…** button next to *Save* and choose **Translate with Supertext**.

   ![The product's "…" menu with Translate with Supertext](images/01-translate-menu.png)

3. Choose the language to translate **from** (your current catalog locale is preselected) and tick the languages to translate **into**. Languages without their own text yet are ticked already; next to each language you see how many values will be translated.

   ![The Translate with Supertext dialog: from English into German, French and Italian (Switzerland)](images/02-translate-dialog.png)

4. Click **Translate**. Supertext translates all text values of the product in one go per language; this usually takes a few seconds per language. The dialog then shows the result for each language:

   ![The result: each language translated, three values each](images/03-translated.png)

5. Click **Done**. The product reloads with the new values.

If the form has unsaved changes when you open the dialog, Akeneo asks first: the form is reloaded after translating, so unsaved changes would be lost.

## Review the translation

Switch the **Locale** in the product header to see the translated values, and edit them like any other value:

![The product in German (Switzerland): name, short description and the rich-text description translated](images/04-german-product.png)

The translation is saved as a normal change of the product: the **History** tab shows it with your name, and completeness and the search index are updated as for any edit.

## Translate again or update a translation

Languages that already have their own text are **kept**: the dialog shows *Has its own text* and leaves them unticked, and translating them again changes nothing. To replace them, for example after the English text changed, tick **Overwrite existing translations** and the languages. The dialog warns which languages will be overwritten:

![Overwrite existing translations ticked: the warning names German and French](images/05-overwrite-warning.png)

Only the values that the source language has text for are written. A value that is empty in the source language is left alone in the other languages.

**Translation review:** if your administrator turned on *Translation review* for a locale, reviewers (your colleagues or Supertext linguists) check and correct the translations on the website. Their corrections come into Akeneo the next time you translate the product into that locale: after a review, translate it again with *Overwrite existing translations*. Texts reviewed once keep their corrected wording in later translations.

## Translate with a file (XLIFF or HTML)

Instead of translating with Supertext right away, you can export a product's texts as a file, have it translated elsewhere — in a translation tool (Trados, memoQ, Phrase …), by a translator, or by uploading it to Supertext's web app — and import the translated file again. Both entries are in the same **…** menu as *Translate with Supertext*, on products and product models.

**Export for translation**

1. Open the product and choose **… → Export for translation**.
2. Choose the language to **translate from**, the **File format** and the locales to **translate into**:
   - **XLIFF 1.2 (for translation tools)** — the better choice for translation tools, which protect the formatting. Several locales go into one file.
   - **HTML (one language per file)** — for Supertext's web app and anyone who translates HTML. Translators must leave the file's head and the `data-st-id` attributes alone.
3. Click **Download file**: `akeneo-<id>-<language>.xlf` (`akeneo-<id>.xlf` for several locales, `.html` for HTML).

The file holds the same values *Translate with Supertext* would send with *Overwrite existing translations* (see *What is translated*). A product without text in the source language gives *Nothing to translate.*

**Import translation**

1. Open any product and choose **… → Import translation** (the file says which products it belongs to; one file can hold several locales).
2. Choose the **Translated file (XLIFF or HTML)** (at most 10 MB).
3. Tick **Also import texts whose source changed since the export** only if you want those too, and **Overwrite existing translations** to replace values that already have text. Click **Import translation**.

The texts are saved exactly like a translation from Supertext: Akeneo checks them, the History tab shows the change, and values that already have their own text are kept unless you ticked *Overwrite existing translations*. Then a summary per product and locale:

| Line | Meaning |
| --- | --- |
| Imported | Saved as the locale's value. |
| Kept, already has its own text | The value already had text and *Overwrite existing translations* wasn't ticked. |
| Skipped, source changed since the export | Someone changed the text in the source language after the export, so the translation may be out of date. Not saved unless you tick *Also import texts whose source changed since the export*. |
| Not translated in the file | The translator left the text empty. |
| Formatting changed, not imported | The translation broke or added formatting (tags) the original doesn't have. |
| Not in the file | Values added after the export. Export again to translate them. |

An HTML file imported unchanged saves the original text as the translation (HTML can't mark a text as untranslated), so only import HTML files that have been translated.

| Message | What it means |
| --- | --- |
| This file isn't a valid XLIFF or HTML translation file. | The file is damaged or isn't XLIFF or HTML. |
| This file wasn't exported by the Supertext bundle, or its header was removed. | Use a file from *Export for translation*; in HTML files the head must stay as it was. |
| This file belongs to another system (…). | The file comes from another Supertext plugin (another CMS or PIM). |
| The product this file was exported from no longer exists. | The product or product model was deleted after the export. |
| The PIM has no activated locale …. | The locale was deactivated after the export. |
| The file is too large (at most 10 MB). | Export fewer locales per file. |
| You are not allowed to edit this product. | You need the same permission as for translating. |

## Product models and variants

Product models have the same **Translate with Supertext** entry. Translate the product model to translate the texts its variants share (in Akeneo, a variant shows these values from its model). On a variant product, the dialog translates the values that belong to the variant itself; if all its texts come from the product model, it says so.

## What is translated

| Translated | Not translated |
| --- | --- |
| Localizable *Text* attributes (for example the name) | Attributes that aren't localizable (the same value for all locales) |
| Localizable *Text area* attributes, plain or rich text: headings, bold and italic text, links and lists are kept, and whole sentences are translated | Identifiers, options (simple and multi select), numbers, dates, measurements, prices, files, images, assets, tables and reference data |
| Values per channel (scopable attributes): each channel's text, into the locales of that channel | Attribute labels, option labels, family and category names (translate them under Settings) |
| | Values of a variant that come from its product model (translate the product model) |

Texts go to Supertext as one HTML document per language, and the translated values are checked by Akeneo before they are saved (for example the maximum length of a text attribute).

## Messages

| Message | What it means |
| --- | --- |
| No Supertext API key is configured. | An administrator has to enter the key under System → Supertext first. No Supertext account yet? Create one at supertext.com. The API key is generated at supertext.com → Integrations → API (requires the Admin role). |
| Authentication failed. Please check the Supertext API key. | The saved key is wrong or was revoked. Ask an administrator. |
| There is no text to translate in … | The product has no text values in the language you translate from. Choose another language. |
| Not used by the channels of these values | The product's texts belong to channels that don't have this locale. Ask an administrator to add the locale to the channel. |
| No Supertext language: an administrator can choose one for this locale under System → Supertext (Languages). | Supertext has no default for this locale (e.g. `en_IE`, `de_LI`), so it can't be translated. Ask an administrator to choose its Supertext language. |
| The language … has no Supertext language. Ask your administrator to choose one … | The same, for the language you translate from or into. Nothing was sent to Supertext. |
| Supertext offers no formal or informal tone for this language. … | The locale's form of address is set to *Formal* or *Informal*, but Supertext doesn't offer that for this language (e.g. English). Ask an administrator to set it to *Default*. |
| Supertext does not support this language pair (… → …). | Supertext can't translate between these two languages (for example two variants of the same language). Choose another language, or ask an administrator to check the language mapping. |
| kept, it already has its own text | Nothing was changed in that language. Tick *Overwrite existing translations* to replace it. |
| Akeneo did not accept the translation: … | A translated value broke one of Akeneo's rules (for example it is too long). That language was not changed; the others were saved. |
| Too many requests to Supertext. | Supertext limits requests per second. Wait a moment and try again. |
| Timed out waiting for the Supertext translation. | Translate fewer languages at once, or ask an administrator to raise the timeout. |
| Your Supertext translation limit is exceeded. | The Supertext subscription needs an upgrade. |
| You are not allowed to edit this product. | You need the permission to edit the product's attributes. |

## Support

Questions, problems or ideas: use the [Supertext support form](https://integrations.supertext.com/en/support?platform=akeneo-pim). Mention the version of Akeneo PIM and of the plugin, what you did and what you expected; an error message helps a lot. You get an answer by email, in your language.
