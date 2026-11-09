#!/usr/bin/env node
/**
 * End-to-end test against the REAL Supertext API: a fresh demo (nothing translated yet) with a
 * real SUPERTEXT_API_KEY. The editor (DEMO_EDITOR_*, role Catalog manager) translates the sample
 * product and product model into every other activated locale through Akeneo's own UI ("…" menu →
 * Translate with Supertext), then the database is checked: every English value has a value in
 * each target locale, the values are really in the target language (de_CH without "ß"),
 * formatting, links and line breaks survived, nothing of the HTML protocol is left, nothing was
 * enabled or created, and translating again (with "Overwrite existing translations") updates the
 * same products in place. See "End-to-end tests" in docs/DEVELOPER.md.
 *
 *   AKENEO_URL=http://127.0.0.1:8081 DATABASE_URL=mysql://root:root@127.0.0.1:3306/akeneo_live \
 *   EDITOR_EMAIL=… EDITOR_PASSWORD=… node tests/e2e/live.mjs
 */
import { chromium } from 'playwright';
import mysql from 'mysql2/promise';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const B = (process.env.AKENEO_URL ?? 'http://127.0.0.1:8081').replace(/\/$/, '');
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`); })();
const artifacts = fileURLToPath(new URL('./artifacts/', import.meta.url));
mkdirSync(artifacts, { recursive: true });

const SOURCE = 'en_US';

const failures = [];
function check(name, ok, detail = '') {
	console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${detail ? ` — ${detail}` : ''}`);
	if (!ok) failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
	return ok;
}

// Common words, to tell the target languages apart. Lower case, whole words.
const STOPWORDS = {
	en: ['the', 'and', 'is', 'are', 'with', 'for', 'you', 'your', 'our', 'of', 'to', 'we', 'who', 'what', 'from', 'every', 'in', 'at', 'by'],
	de: ['der', 'die', 'das', 'und', 'ist', 'sind', 'mit', 'für', 'sie', 'ihre', 'ihr', 'unser', 'unsere', 'unserer', 'wir', 'eine', 'ein', 'den', 'zu', 'von', 'was', 'aus', 'jede', 'jeden', 'bei', 'bis'],
	fr: ['le', 'les', 'et', 'est', 'sont', 'avec', 'pour', 'vous', 'votre', 'vos', 'notre', 'nos', 'nous', 'des', 'une', 'un', 'du', 'de', 'qui', 'que', 'au', 'la', 'dans', 'entre'],
	it: ['il', 'lo', 'gli', 'e', 'è', 'sono', 'con', 'per', 'lei', 'sua', 'suo', 'nostro', 'nostra', 'nostri', 'noi', 'una', 'un', 'del', 'della', 'dei', 'di', 'che', 'chi', 'nel', 'ogni', 'tra'],
};
const words = (text) => text.toLowerCase().match(/[\p{L}]+/gu) ?? [];
const score = (text, lang) => words(text).filter((w) => STOPWORDS[lang].includes(w)).length;
const plain = (v) => String(v ?? '').replace(/<[^>]+>/g, ' ');
const count = (haystack, needle) => String(haystack ?? '').split(needle).length - 1;

const LEFTOVERS = ['data-st-id', 'data-st-ph', 'st-ph-'];
// What the sample content has (rich text: bold, a link, a list; plain text: a line break).
const KEPT = [['bold', '<strong'], ['links', '<a '], ['link targets', 'href="https://www.supertext.com"'], ['paragraphs', '<p>'], ['list items', '<li>'], ['line breaks', '\n']];

const db = await mysql.createConnection(need('DATABASE_URL'));
const q = async (sql, params = []) => (await db.query(sql, params))[0];
const json = (v) => (typeof v === 'string' ? JSON.parse(v) : v ?? {});

/** Products and product models with their values ({attribute: {channel: {locale: data}}}). */
async function snapshot() {
	const products = await q("SELECT BIN_TO_UUID(uuid) AS id, identifier AS code, is_enabled, product_model_id, raw_values FROM pim_catalog_product ORDER BY identifier");
	const models = await q('SELECT id, code, raw_values FROM pim_catalog_product_model ORDER BY code');
	return [
		...products.map((p) => ({ kind: 'product', id: String(p.id), code: p.code, enabled: Boolean(p.is_enabled), parent: p.product_model_id, values: json(p.raw_values) })),
		...models.map((m) => ({ kind: 'product_model', id: String(m.id), code: m.code, values: json(m.raw_values) })),
	];
}

/** The source values of an entity: [{attribute, channel, data}] in en_US. */
function sourceValues(entity) {
	const out = [];
	for (const [attribute, channels] of Object.entries(entity.values)) {
		for (const [channel, locales] of Object.entries(channels ?? {})) {
			if (locales && typeof locales[SOURCE] === 'string' && plain(locales[SOURCE]).trim()) out.push({ attribute, channel, data: locales[SOURCE] });
		}
	}
	return out;
}
const valueIn = (entity, attribute, channel, locale) => entity?.values?.[attribute]?.[channel]?.[locale];

// --- Setup -------------------------------------------------------------------------------
const locales = (await q("SELECT code FROM pim_catalog_locale WHERE is_activated = 1 ORDER BY code")).map((r) => r.code);
const targets = locales.filter((l) => l !== SOURCE);
const before = await snapshot();
const sources = before.filter((e) => sourceValues(e).length > 0);
console.log(`Activated locales: ${locales.join(', ')}; source items: ${sources.map((e) => `${e.kind} ${e.code} (${sourceValues(e).length} values)`).join(', ')}`);
check('demo has target locales', targets.length > 0, targets.join(', '));
check('demo has sample content in en_US', sources.length > 0);
check('fresh demo (nothing translated yet)', sources.every((e) => sourceValues(e).every((v) => targets.every((t) => !valueIn(e, v.attribute, v.channel, t)))));

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const page = await browser.newPage({ viewport: { width: 1280, height: 1000 }, locale: 'en-US' });
page.setDefaultTimeout(60_000);
const pageErrors = [];
page.on('pageerror', (e) => pageErrors.push(e.message));

/** Error messages Akeneo or the dialog shows (the overwrite warning is expected, not an error). */
async function shownErrors() {
	const texts = [];
	for (const el of await page.locator('.supertext-translate-modal [role=alert], .AknFlash--error, .AknMessageBox--error').all()) {
		if (await el.evaluate((n) => Boolean(n.closest('.supertext-overwrite-warning')))) continue;
		texts.push((await el.innerText()).replace(/\s+/g, ' ').trim());
	}
	return texts.filter(Boolean);
}

/** Opens the entity's edit form, "…" → Translate with Supertext, translates into every target locale. */
async function translateEntity(label, entity, overwrite) {
	const tag = `${label}: ${entity.kind} ${entity.code}`;
	await page.goto(`${B}/#/enrich/${entity.kind === 'product' ? 'product' : 'product-model'}/${entity.id}`);
	await page.waitForLoadState('networkidle');
	const menu = page.locator('.AknSecondaryActions.secondary-actions .AknSecondaryActions-button').first();
	await menu.waitFor({ timeout: 120_000 });
	await menu.click();
	const action = page.locator('.supertext-translate-action');
	const shown = await action.first().waitFor({ timeout: 30_000 }).then(() => true, () => false);
	if (!check(`${tag}: "Translate with Supertext" in the menu`, shown)) {
		await page.screenshot({ path: `${artifacts}${label}-${entity.code}-menu.png` });
		return null;
	}
	await page.waitForTimeout(300);
	await action.first().click();
	const modal = page.locator('.supertext-translate-modal');
	await modal.locator('.supertext-targets, [role=alert]').first().waitFor();
	await page.waitForTimeout(500);
	const errorsBefore = await shownErrors();
	check(`${tag}: dialog without errors`, errorsBefore.length === 0, errorsBefore.join(' | '));

	if (overwrite) await modal.getByText('Overwrite existing translations').click();
	// Tick every target locale that isn't ticked yet (the dialog preselects those without text).
	for (const row of await modal.locator('.supertext-targets > *').all()) {
		const box = row.locator('[role=checkbox]').first();
		if ((await box.count()) && (await box.getAttribute('aria-checked')) !== 'true') await box.click();
	}
	await page.screenshot({ path: `${artifacts}${label}-${entity.code}-dialog.png` });

	const started = Date.now();
	const [response] = await Promise.all([
		page.waitForResponse((r) => r.request().method() === 'POST' && /\/supertext\/rest\/(product|product_model)\/[^/]+\/translate$/.test(new URL(r.url()).pathname), { timeout: 900_000 }),
		modal.locator('.supertext-translate-button').click(),
	]);
	const sent = JSON.parse(response.request().postData() ?? '{}');
	const body = await response.json().catch(() => ({}));
	console.log(`${tag}: ${Math.round((Date.now() - started) / 1000)} s, HTTP ${response.status()}`);
	check(`${tag}: sent every target locale`, targets.every((t) => (sent.to ?? []).includes(t)), `to=${JSON.stringify(sent.to)} overwrite=${sent.overwrite}`);
	check(`${tag}: translate request succeeded`, response.ok(), body.message ?? '');
	for (const t of targets) {
		const r = body.results?.[t];
		check(`${tag}: ${t} translated`, r?.status === 'translated' && r.translated > 0, r ? `${r.status}${r.message ? `: ${r.message}` : ''}${r.detail ? ` (${r.detail})` : ''}` : 'no result');
	}

	await modal.locator('.supertext-results, [role=alert]').first().waitFor();
	await page.waitForTimeout(300);
	await page.screenshot({ path: `${artifacts}${label}-${entity.code}-result.png` });
	const errorsAfter = await shownErrors();
	check(`${tag}: no error shown`, errorsAfter.length === 0, errorsAfter.join(' | '));
	const close = modal.locator('.supertext-close-button');
	if (await close.count()) await close.click();
	await page.waitForTimeout(2000);
	return body;
}

// --- Log in as the editor ----------------------------------------------------------------
await page.goto(`${B}/user/login`);
await page.fill('input[name=_username]', need('EDITOR_EMAIL'));
await page.fill('input[name=_password]', need('EDITOR_PASSWORD'));
await page.click('[name=_submit]');
await page.waitForURL((url) => !url.pathname.startsWith('/user/login'), { timeout: 60_000 }).catch(() => {});
if (!check('editor can log in (DEMO_EDITOR_*)', !page.url().includes('/user/login'))) {
	await page.screenshot({ path: `${artifacts}login.png` });
	throw new Error(failures.join('\n'));
}
await page.waitForLoadState('networkidle');

// --- First translation -------------------------------------------------------------------
for (const entity of sources) await translateEntity('first-run', entity, false);
const first = await snapshot();
const byKey = (list) => new Map(list.map((e) => [`${e.kind}:${e.id}`, e]));
const firstMap = byKey(first);

const texts = Object.fromEntries(targets.map((t) => [t, '']));
for (const s of sources) {
	const t = firstMap.get(`${s.kind}:${s.id}`);
	const tag = `${s.kind} ${s.code}`;
	if (s.kind === 'product') check(`${tag}: still ${s.enabled ? 'enabled' : 'disabled'} (translating doesn't change it)`, t.enabled === s.enabled);
	for (const target of targets) {
		let fields = 0;
		let changed = 0;
		for (const v of sourceValues(s)) {
			const field = `${tag} ${target}: ${v.attribute}${v.channel === '<all_channels>' ? '' : ` (${v.channel})`}`;
			const out = valueIn(t, v.attribute, v.channel, target);
			fields++;
			if (!check(`${field} filled`, typeof out === 'string' && plain(out).trim() !== '', JSON.stringify(out))) continue;
			if (out !== v.data) changed++;
			else console.log(`note ${field} unchanged: ${JSON.stringify(out)}`);
			texts[target] += ` ${plain(out)}`;
			const left = LEFTOVERS.filter((l) => out.includes(l));
			check(`${field} has no protocol leftovers`, left.length === 0, left.join(', '));
			if (!v.data.includes('<')) check(`${field} stays plain text`, !out.includes('<'), out);
			for (const [what, needle] of KEPT) {
				if (count(v.data, needle)) check(`${field} keeps its ${what}`, count(out, needle) === count(v.data, needle), `${count(out, needle)}/${count(v.data, needle)}`);
			}
		}
		check(`${tag} ${target}: texts were changed by the translation`, fields > 0 && changed / fields >= 0.8, `${changed}/${fields} fields`);
	}
}
for (const target of targets) {
	const lang = target.split(/[-_]/)[0].toLowerCase();
	if (STOPWORDS[lang]) {
		const scores = Object.fromEntries(Object.keys(STOPWORDS).map((l) => [l, score(texts[target], l)]));
		const best = Object.entries(scores).sort((a, b) => b[1] - a[1])[0][0];
		check(`${target}: text is in the target language`, best === lang, JSON.stringify(scores));
	}
	if (/^de[-_]ch$/i.test(target)) check(`${target}: Swiss German spelling (no "ß")`, !texts[target].includes('ß'));
}
// Variants inherit their texts from the product model: nothing is written to them.
for (const variant of first.filter((e) => e.kind === 'product' && e.parent)) {
	const original = before.find((e) => e.kind === 'product' && e.id === variant.id);
	check(`product ${variant.code} (variant): values unchanged`, JSON.stringify(variant.values) === JSON.stringify(original?.values));
}
check('no products or product models created', first.length === before.length, `${before.length} → ${first.length}`);

// --- Translating again updates in place ---------------------------------------------------
for (const entity of sources) await translateEntity('second-run', entity, true);
const second = await snapshot();
const secondMap = byKey(second);
check('translating again creates no products or product models', second.length === first.length && second.every((e) => firstMap.has(`${e.kind}:${e.id}`)), `${first.length} → ${second.length}`);
for (const s of sources) {
	const tag = `${s.kind} ${s.code}`;
	const t = secondMap.get(`${s.kind}:${s.id}`);
	const filled = (e) => targets.flatMap((target) => sourceValues(s).map((v) => valueIn(e, v.attribute, v.channel, target))).filter((x) => typeof x === 'string' && x.trim()).length;
	check(`${tag}: translating again updates the same values in place`, Boolean(t) && filled(t) === filled(firstMap.get(`${s.kind}:${s.id}`)) && filled(t) === targets.length * sourceValues(s).length, `${filled(firstMap.get(`${s.kind}:${s.id}`))} → ${t ? filled(t) : 0}`);
	if (s.kind === 'product') check(`${tag}: still ${s.enabled ? 'enabled' : 'disabled'} after translating again`, t?.enabled === s.enabled);
}

check('no JavaScript errors in the UI', pageErrors.length === 0, pageErrors.join(' | '));

await browser.close();
await db.end();

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed:\n- ${failures.join('\n- ')}`);
	process.exit(1);
}
console.log('\nAll end-to-end checks passed.');
