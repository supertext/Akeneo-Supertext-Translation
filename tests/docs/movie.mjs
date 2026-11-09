#!/usr/bin/env node
/**
 * Records the demo movie (docs/images/demo.gif and an MP4) from a FRESH demo whose bundle talks to
 * the stand-in API (stand-in.mjs), like screenshots.mjs. The editor opens the sample product in
 * English, translates it with Supertext into German and French and checks the German version.
 * See "Demo movie" in docs/DEVELOPER.md.
 *
 *   BASE_URL=http://127.0.0.1:8090 DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… PRODUCT_UUID=<uuid of praline-box-16> npm run movie
 *
 * Voice: Piper (pip install piper-tts), voice "alba" downloaded once into movie/voice-cache/
 * (git-ignored); MOVIE_VOICE=0 makes a silent movie. MOVIE_OUT: where the MP4 goes (default
 * tests/docs/movie/out/Akeneo-Supertext-Translation.mp4, git-ignored; the MP4 is not committed). Needs ffmpeg.
 */
import { chromium } from 'playwright'
import { fileURLToPath } from 'node:url'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { MovieKit } from './movie/movie-kit.mjs'

const B = (process.env.BASE_URL || 'http://127.0.0.1:8090').replace(/\/$/, '')
const here = (p) => fileURLToPath(new URL(p, import.meta.url))
const out = process.env.MOVIE_OUT || here('movie/out/Akeneo-Supertext-Translation.mp4')
const gif = here('../../docs/images/demo.gif')
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`) })()

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' })
page.on('pageerror', (e) => console.error('page error:', e.message))

// Sign in as the demo editor (not recorded).
await page.goto(`${B}/user/login`)
await page.fill('input[name=_username]', need('DEMO_EDITOR_EMAIL'))
await page.fill('input[name=_password]', need('DEMO_EDITOR_PASSWORD'))
await page.click('[name=_submit]')
await page.waitForURL((url) => !url.pathname.startsWith('/user/login'))
await page.waitForLoadState('networkidle')

const productUrl = `${B}/#/enrich/product/${need('PRODUCT_UUID')}`
const nameField = () => page.locator('.field-container, .AknFieldContainer').filter({ hasText: 'Name' }).first()
const localeSwitcher = () => page.locator('.AknTitleContainer .AknLocaleSwitcher, .AknTitleContainer .locale-switcher').first()
const actionsButton = () => page.locator('.AknSecondaryActions.secondary-actions .AknSecondaryActions-button').first()

await page.goto(productUrl)
await page.waitForLoadState('networkidle')
await actionsButton().waitFor({ timeout: 60_000 })
await page.addStyleTag({ content: '*{caret-color:transparent!important}' })
await page.waitForTimeout(1500)

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'akeneo-movie-'))
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') })

// 1. The English product.
await kit.say("Here's a product in English, in Akeneo PIM.", {
  caption: 'A product in English',
  during: () => kit.moveTo(nameField()),
})

// 2. The product's menu → Translate with Supertext.
const action = page.locator('.supertext-translate-action')
await kit.say('Open the menu at the top, and choose Translate with Supertext.', {
  caption: 'Open the menu and choose <b>Translate with Supertext</b>',
  during: async () => {
    await kit.click(actionsButton())
    await action.waitFor()
    await kit.hold(300)
    await kit.click(action)
    await page.locator('.supertext-translate-modal .supertext-targets').waitFor()
    await kit.sync()
  },
})

// 3. Choose the languages and start.
const modal = page.locator('.supertext-translate-modal')
const targets = modal.locator('.supertext-targets')
await kit.say('Keep German and French, and untick Italian.', {
  caption: 'Choose the languages: <b>German</b> and <b>French</b>',
  during: async () => {
    await kit.hold(600)
    await kit.click(targets.getByText('Italian (Switzerland)'))
  },
})
const translate = modal.locator('.supertext-translate-button')
await kit.say('Then click Translate.', { caption: 'Click <b>Translate</b>', during: () => kit.moveTo(translate), after: 0 })

// The stand-in answers at once: send the request only after the progress state has been seen.
let release
const released = new Promise((resolve) => (release = resolve))
await page.route(/\/supertext\/rest\/[^/]+\/[^/]+\/translate/, async (route) => {
  if (route.request().method() === 'POST') await released
  await route.continue()
})
await page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y])
const done = modal.locator('.supertext-results').waitFor({ timeout: 300_000 })
await kit.say('Supertext translates the content.', {
  caption: 'Supertext translates the content…',
  during: async () => {
    await translate.click({ noWaitAfter: true })
    await kit.hold(1300)
    release()
  },
})
kit.pause()
await done
await page.waitForTimeout(300)
kit.resume()
await kit.sync()

// 4. The result.
await kit.say('A few seconds later, German and French are filled in.', {
  caption: 'Done: German and French are filled in',
  during: () => kit.moveTo(modal.locator('.supertext-results')),
})
await kit.say("Close the dialog, and switch the product to German to check it.", {
  caption: 'Switch the locale to <b>German</b> to check it',
  during: async () => {
    await kit.click(modal.locator('.supertext-close-button'))
    await modal.waitFor({ state: 'detached' })
    await page.waitForLoadState('networkidle')
    await kit.sync()
    await kit.click(localeSwitcher())
    const german = page.locator('.AknDropdown-menu').getByText(/German/).first()
    await german.waitFor()
    await kit.click(german)
    await page.waitForLoadState('networkidle')
    await kit.hold(600)
    await kit.sync()
  },
})
await kit.say('The German version is ready. Review it, and publish it as usual.', {
  caption: 'The German version is ready – review it and publish',
  during: () => kit.moveTo(nameField()),
})
await kit.caption('')

fs.mkdirSync(path.dirname(out), { recursive: true })
await kit.finish(out, { system: 'Akeneo PIM', repo: 'Akeneo-Supertext-Translation', gif })
await browser.close()
fs.rmSync(workDir, { recursive: true, force: true })
console.log(`Saved ${out} and ${gif} (${(fs.statSync(gif).size / 1e6).toFixed(2)} MB)`)
