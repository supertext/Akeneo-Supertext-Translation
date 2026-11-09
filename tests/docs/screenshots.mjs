#!/usr/bin/env node
/**
 * Regenerates docs/images from a freshly set-up demo (no translations yet) whose bundle talks
 * to stand-in.mjs (SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/, no SUPERTEXT_API_KEY).
 * See docs/DEVELOPER.md → Docs screenshots.
 *
 *   BASE_URL (default http://127.0.0.1:8090)
 *   DEMO_ADMIN_EMAIL / DEMO_ADMIN_PASSWORD     settings, channel (administrator)
 *   DEMO_EDITOR_EMAIL / DEMO_EDITOR_PASSWORD   translating (catalog manager)
 *   PRODUCT_UUID                               the uuid of the sample product praline-box-16
 */
import { chromium } from 'playwright'

const B = process.env.BASE_URL || 'http://127.0.0.1:8090'
const OUT = new URL('../../docs/images/', import.meta.url).pathname
const LIVE_API = 'https://api.supertext.com/v1/'
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`) })()

const browser = await chromium.launch()

async function session(email, password) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 860 }, deviceScaleFactor: 1, locale: 'en-US' })
  // The settings page shows the API address; show the live API instead of the local stand-in.
  await context.route('**/supertext/rest/settings', async (route) => {
    const response = await route.fetch()
    const body = await response.json()
    await route.fulfill({ response, json: { ...body, api_url_from_environment: false, effective_url: LIVE_API, environment: 'live' } })
  })
  const page = await context.newPage()
  page.on('pageerror', (e) => console.error('page error:', e.message))
  await page.goto(`${B}/user/login`)
  await page.fill('input[name=_username]', email)
  await page.fill('input[name=_password]', password)
  await page.click('[name=_submit]')
  await page.waitForURL((url) => !url.pathname.startsWith('/user/login'))
  await page.waitForLoadState('networkidle')
  return page
}

async function go(page, hash) {
  await page.goto(`${B}/#${hash}`)
  await page.waitForLoadState('networkidle')
  await page.addStyleTag({ content: '*{caret-color:transparent!important}' })
  await page.waitForTimeout(1500)
}

/** Screenshot of an element (or a box) with a margin. */
async function shot(page, target, name, margin = 12) {
  const r = typeof target.boundingBox === 'function' ? await target.boundingBox() : target
  await page.screenshot({ path: OUT + name, clip: { x: Math.max(0, r.x - margin), y: Math.max(0, r.y - margin), width: r.width + 2 * margin, height: r.height + 2 * margin } })
  console.log('wrote', name)
}

/** The key check shows when it ran: a fixed time, so the images only change when the UI does. */
async function fixedTime(page) {
  await page.locator('.supertext-key-check').evaluateAll((els) => els.forEach((el) => {
    el.innerHTML = el.innerHTML.replace(/\d{1,2}\/\d{1,2}\/\d{4},? \d{1,2}:\d{2}(:\d{2})?\s?[AP]M/g, '10/16/2026, 9:30:00 AM')
  }))
}

const header = (page) => page.locator('.AknTitleContainer, .AknDefault-mainContent header').first()

async function openMenu(page) {
  const button = page.locator('.AknSecondaryActions.secondary-actions .AknSecondaryActions-button').first()
  await button.waitFor({ timeout: 60_000 })
  await button.click()
  await page.locator('.supertext-translate-action').waitFor()
  await page.waitForTimeout(300)
}

async function openDialog(page) {
  await openMenu(page)
  await page.locator('.supertext-translate-action').click()
  await page.locator('.supertext-translate-modal .supertext-targets, .supertext-translate-modal [role=alert]').first().waitFor()
  await page.waitForTimeout(800)
}

/** The dialog's title, content and buttons. */
async function dialogBox(page) {
  const modal = page.locator('.supertext-translate-modal')
  const title = await modal.getByText('Supertext', { exact: true }).first().boundingBox()
  const button = await modal.locator('.supertext-translate-button, .supertext-close-button').first().boundingBox()
  const x = 320
  return { x, y: title.y - 20, width: 1280 - 2 * x, height: button.y + button.height - title.y + 40 }
}

// --- Administrator: API key, settings and locales (first: the editor needs the key) ----------------------------------------------------------
{
  const page = await session(need('DEMO_ADMIN_EMAIL'), need('DEMO_ADMIN_PASSWORD'))

  // Save a (stand-in) API key the way an administrator would.
  await go(page, '/supertext/settings')
  const form = page.locator('.supertext-settings')
  await form.locator('.supertext-languages').waitFor()
  await form.locator('input[type=password]').fill('st-demo-key-0000')
  await page.locator('.supertext-save-button').click()
  await form.getByText('Settings saved.').waitFor()
  await page.waitForTimeout(500)
  await fixedTime(page)
  await page.screenshot({ path: OUT + '06-settings.png', clip: { x: 80, y: 0, width: 1200, height: 860 } })
  console.log('wrote 06-settings.png')
  await page.locator('.supertext-test-button').click()
  await form.locator('.supertext-key-check').getByText(/connection to Supertext works/).waitFor()
  await fixedTime(page)
  const top = await form.boundingBox()
  await page.screenshot({ path: OUT + '07-test-connection.png', clip: { x: 80, y: 0, width: 1200, height: top.y + 70 } })
  console.log('wrote 07-test-connection.png')

  // The System menu entry
  await go(page, '/supertext/settings')
  const item = page.locator('.AknColumn-navigationLink, a').filter({ hasText: /^Supertext$/ }).first()
  const n = await item.boundingBox()
  await shot(page, { x: 80, y: 0, width: 300, height: n.y + n.height + 120 }, '08-system-menu.png', 0)

  // The channel's locales (Settings → Channels → E-commerce)
  await go(page, '/configuration/channel/ecommerce/edit')
  await page.getByText('Locales', { exact: false }).first().waitFor()
  await page.waitForTimeout(1500)
  await page.screenshot({ path: OUT + '09-channel-locales.png', clip: { x: 80, y: 0, width: 1200, height: 660 } })
  console.log('wrote 09-channel-locales.png')
}

// --- Editor: translate ------------------------------------------------------------------------
{
  const page = await session(need('DEMO_EDITOR_EMAIL'), need('DEMO_EDITOR_PASSWORD'))
  const uuid = need('PRODUCT_UUID')
  await go(page, `/enrich/product/${uuid}`)

  await openMenu(page)
  const menu = page.locator('.AknDropdown-menu').filter({ has: page.locator('.supertext-translate-action') }).first()
  const m = await menu.boundingBox()
  await shot(page, { x: 380, y: 0, width: 900, height: m.y + m.height + 20 }, '01-translate-menu.png', 0)

  await page.locator('.supertext-translate-action').click()
  await page.locator('.supertext-translate-modal .supertext-targets').waitFor()
  await page.waitForTimeout(800)
  await shot(page, await dialogBox(page), '02-translate-dialog.png', 0)

  await page.locator('.supertext-translate-button').click()
  await page.locator('.supertext-results').waitFor({ timeout: 120_000 })
  await page.waitForTimeout(300)
  await shot(page, await dialogBox(page), '03-translated.png', 0)
  await page.locator('.supertext-close-button').click()
  await page.waitForTimeout(3000)

  // The German version of the product: switch the catalog locale in the header.
  await page.locator('.AknLocaleSwitcher, .locale-switcher').first().click()
  await page.locator('.AknDropdown-menu').getByText(/German/).first().click()
  await page.waitForLoadState('networkidle')
  await page.waitForTimeout(2000)
  await page.screenshot({ path: OUT + '04-german-product.png', clip: { x: 380, y: 0, width: 900, height: 820 } })
  console.log('wrote 04-german-product.png')

  // Every language has its own text now: overwriting shows the warning.
  await page.locator('.AknLocaleSwitcher, .locale-switcher').first().click()
  await page.locator('.AknDropdown-menu').getByText(/English/).first().click()
  await page.waitForTimeout(1500)
  await openDialog(page)
  await page.locator('.supertext-translate-modal').getByText('Overwrite existing translations').click()
  for (const name of ['German (Switzerland)', 'French (Switzerland)']) {
    await page.locator('.supertext-targets').getByText(name).click()
  }
  await page.locator('.supertext-overwrite-warning').waitFor()
  await page.waitForTimeout(300)
  await shot(page, await dialogBox(page), '05-overwrite-warning.png', 0)
  await page.keyboard.press('Escape')
}

await browser.close()
