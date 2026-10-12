#!/usr/bin/env node
/** Real browser verification against the private HTTPS fixture in http_test.py. */
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFile, mkdtemp, rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';

const require = createRequire(new URL('../../../frontend/package.json', import.meta.url));
const {chromium, expect: baseExpect} = require('@playwright/test');
const expect = baseExpect.configure({timeout: 20000});
const base = process.env.MW_TEST_BASE;
const username = process.env.MW_TEST_USERNAME;
const password = process.env.MW_TEST_PASSWORD;
assert(base && username && password, 'Provide the private fixture URL and test login via MW_TEST_BASE, MW_TEST_USERNAME, MW_TEST_PASSWORD');
const origin = new URL(base);
assert(origin.protocol === 'https:' && ['localhost', '127.0.0.1', '[::1]'].includes(origin.hostname),
  'This fixture-only runner accepts local HTTPS URLs');
const url = new URL('/csvstudio/', origin).href;
const artifacts = await mkdtemp(join(tmpdir(), 'mw-php-browser-'));
const browser = await chromium.launch({executablePath: process.env.MW_CHROMIUM_PATH || '/usr/bin/chromium', headless: true});
// Only the local test fixture uses a self-signed certificate.
const context = await browser.newContext({ignoreHTTPSErrors: true, acceptDownloads: true, viewport: {width: 1440, height: 1000}});
const page = await context.newPage();
page.setDefaultTimeout(20000);
// Chromium omits multipart file entries from the DevTools request body. Observe
// the actual FormData fields, while forwarding each append unchanged to native code.
await page.addInitScript(() => {
  window.__mwBrowserMultipartFields = [];
  const append = FormData.prototype.append;
  FormData.prototype.append = function (...args) {
    const result = append.apply(this, args);
    window.__mwBrowserMultipartFields.push({name: args[0], filename: args[1] instanceof File ? args[1].name : null});
    return result;
  };
});
const errors = [];
const jobs = new Map();
const jobStates = new Map();
const pending = [];
const views = new Set();
const evidence = [];
let multipartUploads = 0;
let cooperativeSteps = 0;
let passed = false;

function apiPath(requestUrl) {
  const address = new URL(requestUrl);
  return address.pathname === '/csvstudio/api.php' ? address.searchParams.get('path') || '' : '';
}
page.on('pageerror', error => errors.push('JavaScript: ' + error.message));
page.on('console', message => {
  if (message.type() !== 'error') return;
  const location = message.location().url;
  // The fixture's host document root intentionally has no favicon outside CSV Studio.
  if (location && new URL(location).origin === origin.origin && new URL(location).pathname === '/favicon.ico'
    && /Failed to load resource.*404/.test(message.text())) return;
  errors.push('Console: ' + message.text());
});
page.on('requestfailed', request => {
  // A top-level reload may abort its previous navigation; application requests must finish.
  if (request.failure()?.errorText !== 'net::ERR_ABORTED') errors.push('Request: ' + new URL(request.url()).pathname + ' ' + request.failure()?.errorText);
});
page.on('request', request => {
  const path = apiPath(request.url());
  if (/^\/jobs\/[^/]+\/step$/.test(path) && request.method() === 'POST') cooperativeSteps++;
  if (path === '/uploads' && request.method() === 'POST') {
    if (!/^multipart\/form-data; boundary=/.test(request.headers()['content-type'] || '')) errors.push('Upload did not use multipart/form-data');
    multipartUploads++;
  }
});
page.on('response', response => {
  const address = new URL(response.url());
  if (address.origin === origin.origin && address.pathname.startsWith('/csvstudio/') && response.status() >= 400) {
    errors.push('HTTP ' + response.status() + ': ' + address.pathname + ' ' + apiPath(response.url()));
  }
  if (/^\/jobs(?:\/|$)/.test(apiPath(response.url())) || ['/imports', '/exports'].includes(apiPath(response.url())) || /\/validate$/.test(apiPath(response.url()))) {
    pending.push(response.json().then(body => {
      for (const job of Array.isArray(body) ? body : [body]) {
        if (job && typeof job.id === 'string' && typeof job.state === 'string' && typeof job.kind === 'string') {
          jobs.set(job.id, job);
          if (!jobStates.has(job.kind)) jobStates.set(job.kind, new Set());
          jobStates.get(job.kind).add(job.state);
        }
      }
    }).catch(error => errors.push('Job response: ' + error.message)));
  }
});

async function login() {
  await page.goto(url);
  await expect(page.getByRole('heading', {name: 'Accedi a CSV Studio', exact: true})).toBeVisible();
  await page.getByLabel('Nome utente', {exact: true}).fill(username);
  await page.getByLabel('Password', {exact: true}).fill(password);
  await page.getByRole('button', {name: 'Accedi', exact: true}).click();
  await expect(page.getByRole('navigation', {name: 'Navigazione principale'})).toBeVisible();
  await expect(page.getByRole('button', {name: 'Esci', exact: true})).toBeVisible();
}
async function visit(name, title) {
  await page.getByRole('button', {name, exact: true}).click();
  await expect(page.getByRole('heading', {name: title, exact: true})).toBeVisible();
  views.add(name);
  await expect(page.locator('.alert.error')).toHaveCount(0);
}
async function downloadedCsv() {
  const waiting = page.waitForEvent('download');
  await page.getByRole('link', {name: 'Scarica CSV', exact: true}).first().click();
  const download = await waiting;
  assert.equal(await download.failure(), null);
  const contents = await readFile(await download.path());
  assert.deepEqual([...contents.subarray(0, 3)], [0xef, 0xbb, 0xbf], 'CSV download keeps its UTF-8 BOM');
  assert.match(contents.toString('utf8'), /Custom label \(SKU\)/);
  return contents.toString('utf8');
}
async function completedJob(kind, previousIds) {
  await expect.poll(() => [...jobs.values()].find(job => job.kind === kind && job.state === 'completed' && !previousIds.has(job.id)),
    {timeout: 40000, message: kind + ' job must complete through browser cooperative polling'}).toBeTruthy();
}

try {
  await login();
  evidence.push('independent HTTPS form login');
  await expect(page.getByLabel('Lavorazione', {exact: true}).locator('option')).toHaveCount(2);
  const fixtureImportId = await page.getByLabel('Lavorazione', {exact: true}).locator('option').nth(1).getAttribute('value');
  await page.getByLabel('Lavorazione', {exact: true}).selectOption(fixtureImportId);
  await visit('Importazione', 'Dai file al tuo catalogo');
  await expect(page.getByText('fornitore.csv', {exact: true})).toBeVisible();
  const help = page.getByRole('button', {name: 'Aiuto: Destinazione', exact: true});
  await help.hover();
  await expect(page.getByRole('tooltip')).toContainText('eBay.it crea un’inserzione per modello compatibile');
  await help.click();
  await help.press('Escape');
  await expect(help).toHaveAttribute('aria-expanded', 'false');
  await page.mouse.move(1100, 900);
  await page.getByLabel('Lavorazione', {exact: true}).focus();
  await help.focus();
  await expect(page.getByRole('tooltip')).toBeVisible();
  await help.press('Escape');
  evidence.push('help tooltip hover, click, keyboard focus and Escape');

  await visit('Prodotti e compatibilità', 'Prodotti e compatibilità');
  await expect(page.getByRole('button', {name: 'Apri 00123', exact: true})).toBeVisible();
  await expect(page.getByRole('button', {name: 'Apri 00456', exact: true})).toBeVisible();
  await page.getByRole('button', {name: 'Apri 00123', exact: true}).click();
  await expect(page.getByLabel('Marca veicolo 1', {exact: true})).toHaveValue('Honda');
  await expect(page.getByLabel('Modello 1', {exact: true})).toHaveValue('CBR 600');
  await page.getByRole('button', {name: 'Chiudi dettagli', exact: true}).click();
  evidence.push('stored products and Honda compatibility');

  await visit('Prezzi', 'Prezzi e margine');
  await expect(page.getByLabel('Costo spedizione IVA esclusa (€)', {exact: true})).toHaveValue('5');
  await page.getByLabel('Ricarico prodotto (%)', {exact: true}).fill('31');
  await page.getByLabel('Ricarico spedizione (%)', {exact: true}).fill('0');
  await page.getByLabel('Commissione (%)', {exact: true}).fill('10');
  await page.getByLabel('Costo per simulazione (€)', {exact: true}).fill('100');
  await expect(page.locator('.price-main')).toHaveText(/188,90\s*€/);
  await expect(page.getByText('Margine sotto 20%', {exact: true})).toBeVisible();
  evidence.push('pricing preview rounds to 188,90 EUR and warns below 20%');

  await visit('Controllo', 'Controlla, correggi, esporta');
  await expect(page.getByRole('button', {name: /Modifica bozza/})).toHaveCount(2);
  await visit('Esportazione', 'CSV pronti da scaricare');
  await expect(page.getByText('1 righe incluse', {exact: true})).toBeVisible();
  const originalCsv = await downloadedCsv();
  assert.match(originalCsv, /Pastiglie freno Honda CBR 600/);
  assert.doesNotMatch(originalCsv, /Filtro aria/);
  await visit('Impostazioni', 'Impostazioni di vendita');
  await expect(page.getByLabel('Spedizione gratuita configurata', {exact: true})).toBeChecked();
  await page.reload();
  await expect(page.getByLabel('Lavorazione', {exact: true})).toHaveValue(fixtureImportId);
  await visit('Esportazione', 'CSV pronti da scaricare');
  assert.equal(await downloadedCsv(), originalCsv, 'Archive download remains byte-identical after reload');
  evidence.push('all six views and persistent immutable archived CSV');

  await visit('Importazione', 'Dai file al tuo catalogo');
  await page.getByLabel('Lavorazione', {exact: true}).selectOption('');
  await page.getByLabel('File del fornitore', {exact: true}).setInputFiles({
    name: 'fornitore-browser.csv', mimeType: 'text/csv',
    buffer: Buffer.from('Codice;Nome;Famiglia;Costo\r\nB00123;Pastiglie freno browser;Pastiglie Freno;10.00\r\nB00456;Filtro aria browser;Filtri Aria;\r\n'),
  });
  await expect(page.getByRole('heading', {name: 'Come leggiamo questi file', exact: true})).toBeVisible();
  assert.deepEqual(await page.evaluate(() => window.__mwBrowserMultipartFields),
    [{name: 'files[]', filename: 'fornitore-browser.csv'}], 'The real UI FormData uses the PHP files[] field');
  await page.getByLabel('Profilo salvato', {exact: true}).selectOption('profile-http');
  await expect(page.getByLabel('Nome profilo', {exact: true})).toHaveValue('Fornitore HTTP');
  await expect(page.getByLabel('Codice fornitore', {exact: true})).toHaveValue('supplier-http');
  await expect(page.getByLabel('Codice prodotto', {exact: true})).toHaveValue('Codice');
  await expect(page.getByLabel('Costo di acquisto IVA esclusa', {exact: true})).toHaveValue('Costo');
  const beforeImport = new Set(jobs.keys());
  await page.getByRole('button', {name: 'Salva profilo e importa', exact: true}).click();
  await expect(page.locator('.progress-panel[aria-live="polite"] progress')).toBeVisible();
  await completedJob('import', beforeImport);
  await expect(page.getByRole('button', {name: 'Apri B00123', exact: true})).toBeVisible();
  await expect(page.getByRole('button', {name: 'Apri B00456', exact: true})).toBeVisible();
  await page.getByRole('button', {name: 'Apri B00123', exact: true}).click();
  await page.getByRole('button', {name: 'Aggiungi modello', exact: true}).click();
  await page.getByLabel('Marca veicolo 1', {exact: true}).fill('Honda');
  await page.getByLabel('Modello 1', {exact: true}).fill('CBR 600');
  await page.getByLabel('Tipo veicolo 1', {exact: true}).selectOption('moto');
  await page.getByRole('button', {name: 'Salva compatibilità', exact: true}).click();
  await expect(page.getByText('Compatibilità salvate', {exact: true})).toBeVisible();
  await page.getByRole('button', {name: 'Chiudi dettagli', exact: true}).click();
  evidence.push('real files[] upload, saved supplier mapping, import job and manual compatibility');

  await visit('Controllo', 'Controlla, correggi, esporta');
  const beforeValidate = new Set(jobs.keys());
  await page.getByRole('button', {name: 'Controlla lotto', exact: true}).click();
  await completedJob('validate', beforeValidate);
  await expect(page.getByRole('button', {name: /Modifica bozza/})).toHaveCount(2);
  await expect(page.locator('.stat.error b')).toHaveText('1');
  await page.getByRole('row').filter({hasText: 'Filtro aria browser'}).getByRole('button', {name: /Modifica bozza/}).click();
  await expect(page.getByRole('dialog').getByText('Imposta il costo di acquisto IVA esclusa.', {exact: true})).toBeVisible();
  await page.getByRole('button', {name: 'Chiudi bozza', exact: true}).click();
  const beforeExport = new Set(jobs.keys());
  await page.getByRole('button', {name: 'Esporta righe valide', exact: true}).click();
  await completedJob('export', beforeExport);
  await expect(page.getByRole('heading', {name: 'CSV pronti da scaricare', exact: true})).toBeVisible();
  await expect(page.getByText('1 righe incluse', {exact: true})).toBeVisible();
  const browserCsv = await downloadedCsv();
  assert.match(browserCsv, /Pastiglie freno browser Honda CBR 600/);
  assert.doesNotMatch(browserCsv, /Filtro aria browser/);
  assert.match(browserCsv, /Honda CBR 600/);
  evidence.push('cooperative eBay validation and partial export excludes missing-cost product');

  const browserImportId = await page.getByLabel('Lavorazione', {exact: true}).inputValue();
  await page.getByRole('button', {name: 'Esci', exact: true}).click();
  await login();
  await expect(page.getByLabel('Lavorazione', {exact: true})).toHaveValue(browserImportId);
  await visit('Esportazione', 'CSV pronti da scaricare');
  assert.equal(await downloadedCsv(), browserCsv, 'Private archive survives logout and independent login');
  evidence.push('logout/login preserves the new working batch and archive');
  await Promise.all(pending);
  assert.equal(multipartUploads, 1, 'One actual browser multipart CSV upload was observed');
  assert(cooperativeSteps >= 3, 'Import, validation and export must advance through browser POST /step requests');
  for (const kind of ['import', 'validate', 'export']) {
    assert(jobStates.get(kind)?.has('queued') || jobStates.get(kind)?.has('running'), kind + ' job must expose its pending progress');
    assert(jobStates.get(kind)?.has('completed'), kind + ' job must expose its completed state');
  }
  assert.deepEqual(errors, [], 'Browser JavaScript, console, requests and application HTTP responses must remain clean');
  passed = true;
  console.log('PASS: FTP browser ' + JSON.stringify({views: [...views], actions: evidence, multipartUploads, cooperativeSteps, jobStates: Object.fromEntries([...jobStates].map(([kind, states]) => [kind, [...states]])), completedJobs: [...jobs.values()].filter(job => job.state === 'completed').length, errors: errors.length}));
} catch (error) {
  await page.screenshot({path: join(artifacts, 'failure.png'), fullPage: true}).catch(() => {});
  console.error('Browser evidence: ' + JSON.stringify({views: [...views], actions: evidence, errors, screenshot: join(artifacts, 'failure.png')}));
  throw error;
} finally {
  await context.close();
  await browser.close();
  if (passed) await rm(artifacts, {recursive: true, force: true});
}
