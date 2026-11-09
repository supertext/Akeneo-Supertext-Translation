#!/usr/bin/env node
/**
 * Records the install and configuration movie (MP4 only, no GIF) from a FRESH demo whose bundle
 * talks to the stand-in API (stand-in.mjs), like movie.mjs. An administrator installs the bundle
 * (terminal: Composer, bundles.php and routes, cache and front-end build), enters an API key under
 * System → Supertext, sees the key check, confirms the German locale's Supertext language and shows
 * where editors find "Translate with Supertext". See "Demo movie" in docs/DEVELOPER.md.
 *
 *   BASE_URL=http://127.0.0.1:8090 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… PRODUCT_UUID=<uuid of praline-box-16> npm run movie:install
 *
 * Run it before movie.mjs on a fresh demo without SUPERTEXT_API_KEY: it saves the made-up key
 * st-demo-0000-0000-0000 (the stand-in accepts any key), which the demo movie then uses.
 * MOVIE_VOICE=0 makes a silent movie. MOVIE_OUT: where the MP4 goes (default
 * tests/docs/movie/out/Akeneo-Supertext-Translation-install.mp4, git-ignored). Needs ffmpeg.
 */
import { chromium } from 'playwright'
import { fileURLToPath } from 'node:url'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { MovieKit } from './movie/movie-kit.mjs'

const B = (process.env.BASE_URL || 'http://127.0.0.1:8090').replace(/\/$/, '')
const here = (p) => fileURLToPath(new URL(p, import.meta.url))
const out = process.env.MOVIE_OUT || here('movie/out/Akeneo-Supertext-Translation-install.mp4')
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`) })()
const FAKE_KEY = 'st-demo-0000-0000-0000'
const LIVE_API = 'https://api.supertext.com/v1/'

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const context = await browser.newContext({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' })
// The settings page shows the API address: show the live API instead of the local stand-in (as screenshots.mjs does).
await context.route('**/supertext/rest/settings', async (route) => {
  const response = await route.fetch()
  const body = await response.json()
  await route.fulfill({ response, json: { ...body, api_url_from_environment: false, effective_url: LIVE_API, environment: 'live' } })
})
// Belt and braces: no local address in any frame.
await context.addInitScript(() => {
  const fix = (root) => {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT)
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      if (/127\.0\.0\.1|localhost/.test(n.textContent)) n.textContent = n.textContent.replace(/https?:\/\/(127\.0\.0\.1|localhost)[^\s)]*/g, 'https://api.supertext.com/v1/')
    }
  }
  new MutationObserver(() => document.body && fix(document.body)).observe(document, { subtree: true, childList: true, characterData: true })
})
const page = await context.newPage()
page.on('pageerror', (e) => console.error('page error:', e.message))

// Sign in as the demo administrator (not recorded): configuration is an administrator's task.
await page.goto(`${B}/user/login`)
await page.fill('input[name=_username]', need('DEMO_ADMIN_EMAIL'))
await page.fill('input[name=_password]', need('DEMO_ADMIN_PASSWORD'))
await page.click('[name=_submit]')
await page.waitForURL((url) => !url.pathname.startsWith('/user/login'))
await page.waitForLoadState('networkidle')

/** Akeneo scrolls inside its main column: bring an element into view, smoothly. */
const scrollTo = async (locator, block = 'start') => {
  await locator.evaluate((el, block) => el.scrollIntoView({ block, behavior: 'smooth' }), block)
  await page.waitForTimeout(900)
}

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'akeneo-movie-install-'))
await page.goto('about:blank')
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') })

/** Opens an Akeneo screen without recording the loading. */
const open = async (hash, ready) => {
  kit.pause()
  await page.goto(`${B}/#${hash}`)
  await page.waitForLoadState('networkidle')
  await ready()
  await page.addStyleTag({ content: '*{caret-color:transparent!important}' })
  await page.waitForTimeout(1200)
  kit.resume()
  await kit.sync()
}

// 1. Terminal: the commands from docs/INSTALLATION.md (output shortened).
await kit.say('First, add the Supertext repository to your Akeneo project with Composer.', {
  caption: 'Add the repository with <b>Composer</b>',
  during: () => kit.terminal({ title: 'my-pim', lines: [
    { cmd: 'composer config repositories.supertext vcs https://github.com/Supertext/Akeneo-Supertext-Translation' },
  ] }),
  after: 0,
})
await kit.say('Then require the bundle. Composer installs it.', {
  caption: 'Require the bundle',
  during: () => kit.terminal({ title: 'my-pim', lines: [
    { cmd: 'composer require supertext/akeneo-supertext-translation:dev-main' },
    { out: 'Package operations: 1 install, 0 updates, 0 removals' },
    { out: '  - Installing supertext/akeneo-supertext-translation (dev-main)' },
    { out: 'Generating optimized autoload files' },
  ] }),
})
await kit.say('Register the bundle in your config folder, in bundles dot P H P. Then add a routes file, so Akeneo finds its pages.', {
  caption: 'Register the bundle and its routes',
  during: () => kit.terminal({ title: 'my-pim', lines: [
    { cmd: 'cat config/bundles.php' },
    { out: 'return [' },
    { out: "    Supertext\\AkeneoTranslationBundle\\SupertextTranslationBundle::class => ['all' => true]," },
    { out: '];' },
    { cmd: 'cat config/routes/supertext_translation.yml' },
    { out: 'supertext_translation:' },
    { out: "    resource: '@SupertextTranslationBundle/Resources/config/routing.yml'" },
  ] }),
})
await kit.say('Then rebuild the cache, install the assets, and rebuild the front end with Yarn, so the new screens appear in Akeneo.', {
  caption: 'Rebuild the cache, the assets and the front end',
  during: () => kit.terminal({ title: 'my-pim', lines: [
    { cmd: 'rm -rf var/cache && bin/console cache:warmup' },
    { out: ' [OK] Cache for the "prod" environment (debug=false) was successfully warmed.' },
    { cmd: 'bin/console pim:installer:assets --clean' },
    { out: ' [OK] All assets were successfully installed.' },
    { cmd: 'yarn run less && yarn run webpack && yarn run update-extensions' },
    { out: 'webpack 5.75.0 compiled with 3 warnings' },
    { out: 'Updating form extensions.json' },
  ] }),
})

// 2. Locales come from the channels (Akeneo's own setup).
await open('/configuration/channel/ecommerce/edit', () => page.getByText('Locales', { exact: false }).first().waitFor())
await kit.say("Supertext translates into the locales of your channels. Set them up first, under Settings, Channels.", {
  caption: 'Locales: <b>Settings → Channels</b>',
  during: () => kit.moveTo(page.getByText('German (Switzerland)').first()),
})

// 3. System → Supertext.
const form = page.locator('.supertext-settings')
/** Clicks a link and waits for the next screen without recording the loading. */
const go = async (link, ready) => {
  await kit.click(link)
  kit.pause()
  await ready()
  await page.waitForLoadState('networkidle')
  await page.waitForTimeout(500)
  kit.resume()
  await kit.sync()
}
await kit.say('Then open System, and Configuration.', {
  caption: 'Open <b>System → Configuration</b>',
  during: async () => {
    const configuration = page.getByText('Configuration', { exact: true }).first()
    await go(page.locator('a[href="#/system"]').first(), () => configuration.waitFor())
    await go(configuration, () => page.locator('a[href="#/supertext/settings"]').first().waitFor())
  },
  after: 0,
})
await kit.say('Next to it, choose Supertext.', {
  caption: 'Choose <b>Supertext</b>',
  during: () => go(page.locator('a[href="#/supertext/settings"]').first(), () => form.locator('.supertext-languages').waitFor()),
})

// 4. The API key.
const keyField = form.locator('input[type=password]').first()
await kit.say('Generate your API key at supertext.com, Integrations, API. No account yet? Create one at supertext.com.', {
  caption: 'Generate your API key at <b>supertext.com → Integrations → API</b>. No account yet? Create one at supertext.com.',
  during: () => kit.moveTo(form.getByText('supertext.com → Integrations → API').last()),
})
const saveButton = page.locator('.supertext-save-button')
const keyOk = form.getByText('API key checked: the connection to Supertext works.').first()
await kit.say('Paste the key into the API key field, and click Save.', {
  caption: 'Paste it into <b>API key</b> and click <b>Save</b>',
  during: async () => {
    await kit.type(keyField, FAKE_KEY)
    await kit.hold(300)
    await kit.click(saveButton)
    await form.getByText('Settings saved.').waitFor()
    await kit.sync()
  },
})
await kit.say('Supertext checks the key right away. The connection works.', {
  caption: 'The key check: <b>the connection to Supertext works</b>',
  during: async () => {
    await keyOk.waitFor({ timeout: 30_000 })
    await scrollTo(keyOk, 'center')
    await kit.moveTo(keyOk)
  },
})

// 5. Language mapping.
const deRow = form.locator('.supertext-languages tr').filter({ hasText: 'German (Switzerland)' }).first()
await kit.say('Under Languages, pick the Supertext language for each locale. Here: German, Switzerland.', {
  caption: '<b>Languages</b>: the Supertext language for each locale',
  during: async () => {
    await scrollTo(form.locator('.supertext-languages'), 'center')
    await kit.sync()
    const select = deRow.locator('td').nth(1).locator('input').first()
    await kit.click(select)
    await page.waitForTimeout(500)
    await page.keyboard.type('German', { delay: 90 })
    await page.waitForTimeout(600)
    const option = page.locator('div[data-testid="de-CH"][tabindex]').last()
    await kit.moveTo(option)
    await kit.hold(300)
    await kit.click(option)
    await kit.sync()
  },
})
await kit.say('Translation review can be ticked for each locale. Leave it off for now.', {
  caption: '<b>Translation review</b>: optional, per locale',
  during: () => kit.moveTo(deRow.locator('.supertext-review').first()),
})
await kit.say('Click Save.', {
  caption: 'Click <b>Save</b>',
  during: async () => {
    await kit.click(saveButton)
    await form.getByText('Settings saved.').waitFor()
  },
})

// 6. Where editors find it.
await open(`/enrich/product/${need('PRODUCT_UUID')}`, () => page.locator('.AknSecondaryActions.secondary-actions .AknSecondaryActions-button').first().waitFor({ timeout: 60_000 }))
const action = page.locator('.supertext-translate-action')
await kit.say("That's it. Editors now find Translate with Supertext in the menu of every product and product model.", {
  caption: 'Done: <b>Translate with Supertext</b> on every product',
  during: async () => {
    await kit.click(page.locator('.AknSecondaryActions.secondary-actions .AknSecondaryActions-button').first())
    await action.waitFor()
    await kit.moveTo(action)
    await action.hover()
  },
  after: 300,
})
await kit.caption('')

fs.mkdirSync(path.dirname(out), { recursive: true })
await kit.finish(out, { system: 'Akeneo PIM', repo: 'Akeneo-Supertext-Translation', kind: 'install' })
await browser.close()
fs.rmSync(workDir, { recursive: true, force: true })
console.log(`Saved ${out}`)
