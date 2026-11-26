'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const filename = process.env.SUZU_ENTERPRISE_SCRIPT ||
  path.join(__dirname, '../../theme/Xboard/assets/suzu-enterprise.js');
const script = fs.readFileSync(filename, 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
const success = data => ({ status: 'success', data });

function theme() {
  const cards = new Map();
  const events = new Map();
  const requests = [];
  const location = { hash: '#/dashboard', origin: 'https://test.example', href: 'https://test.example/#/dashboard' };
  let token = 'account-a';
  let observe;

  function element() {
    const children = new Map();
    return {
      innerHTML: '',
      remove() { cards.delete(this.id); },
      querySelector(selector) {
        if (!children.has(selector)) children.set(selector, element());
        return children.get(selector);
      },
      addEventListener(name, handler) { this[name] = handler; },
    };
  }

  const app = {
    insertBefore(card) { cards.set(card.id, card); },
  };
  const window = {
    fetch(url, options) {
      if (url === '/spa') return Promise.resolve();
      return new Promise(resolve => {
        requests.push({
          url, options,
          respond(data) { resolve({ json: () => Promise.resolve(success(data)) }); },
        });
      });
    },
    addEventListener(name, handler) { events.set(name, handler); },
  };

  vm.runInNewContext(script, {
    window, location, URL, AbortController,
    localStorage: {
      getItem(key) {
        assert.equal(key, 'VUE_NAIVE_ACCESS_TOKEN');
        return JSON.stringify({ value: token });
      },
    },
    document: {
      readyState: 'complete',
      body: {},
      createElement: element,
      getElementById: id => id === 'app' ? app : cards.get(id),
    },
    MutationObserver: class {
      constructor(callback) {
        const previous = observe;
        observe = () => { if (previous) previous(); callback(); };
      }
      observe() {}
    },
  });

  function mutate() { observe(); }
  function navigate(hash) {
    location.hash = hash;
    events.get('hashchange')?.();
  }
  function changeToken(next) {
    token = next;
    events.get('storage')?.();
  }
  async function nextRequest(endpoint, data) {
    const request = requests.find(item => !item.answered && item.url.endsWith(endpoint));
    assert.ok(request, `no pending request for ${endpoint}`);
    request.answered = true;
    request.respond(data);
    await flush();
  }
  function spaActivity() {
    window.fetch('/spa', { headers: { Authorization: token } });
    mutate();
  }
  return { cards, requests, mutate, navigate, changeToken, nextRequest, spaActivity };
}

const catalog = { plans: [], regions: [{ code: 'HK', name: '香港' }] };
const enterprise = name => ({ has_enterprise: true, plan_name: name });

async function pendingFulfillment() {
  const page = theme();
  page.spaActivity();
  await page.nextRequest('/enterprise/lines', { has_enterprise: false });
  await page.nextRequest('/business/machine-regions', catalog);
  await page.nextRequest('/business/status', {
    has_business: false, can_rotate: false, cooldown_seconds: 3600,
    machine: { fulfillment_id: 17, region_name: '香港', status: 'PROCURING', awaiting_choice: true },
  });

  const card = page.cards.get('suzu-biz-card');
  assert.ok(card, 'An SLA-overdue first-time machine customer needs a fulfillment card');
  assert.match(card.innerHTML, /id="suzu-biz-wait"/);
  assert.match(card.innerHTML, /id="suzu-biz-switch"/);
  assert.doesNotMatch(card.innerHTML, /更换端口/);

  card.querySelector('#suzu-biz-wait').click();
  const choice = page.requests.at(-1);
  assert.equal(choice.url, '/api/v1/user/business/choice');
  assert.deepEqual(JSON.parse(choice.options.body), { fulfillment_id: 17, choice: 'WAIT_ORIGINAL' });
}

async function sessionLifecycle() {
  const page = theme();
  page.spaActivity();
  await page.nextRequest('/enterprise/lines', enterprise('Account A'));
  await page.nextRequest('/business/machine-regions', catalog);
  await page.nextRequest('/business/status', { has_business: true, can_rotate: true });
  assert.match(page.cards.get('suzu-ent-card').innerHTML, /Account A/);

  const initialCalls = page.requests.length;
  page.mutate();
  page.mutate();
  assert.equal(page.requests.length, initialCalls, 'DOM churn must not refetch member data');

  page.cards.get('suzu-biz-card').querySelector('#suzu-biz-rotate').click();
  await page.nextRequest('/business/rotate-port', {});
  assert.equal(page.cards.size, 0);
  await page.nextRequest('/enterprise/lines', { ...enterprise('Account A'), primary: { entry_port: 22001 } });
  await page.nextRequest('/business/machine-regions', catalog);
  await page.nextRequest('/business/status', { has_business: true, cooldown_seconds: 7200 });
  assert.match(page.cards.get('suzu-ent-card').innerHTML, /22001/);
  assert.match(page.cards.get('suzu-biz-card').innerHTML, /冷却中/);

  page.navigate('#/profile');
  assert.equal(page.cards.size, 0);
  page.navigate('#/dashboard');
  const stale = page.requests.slice(-3);
  page.changeToken('account-b');
  assert.equal(page.cards.size, 0);
  assert.ok(stale.every(request => request.options.signal.aborted));
  for (const request of stale) {
    request.answered = true;
    request.respond(enterprise('Stale account A'));
  }
  await flush();
  assert.equal(page.cards.size, 0, 'Late responses from the previous account must be ignored');

  await page.nextRequest('/enterprise/lines', enterprise('Account B'));
  await page.nextRequest('/business/machine-regions', catalog);
  await page.nextRequest('/business/status', { has_business: false });
  assert.match(page.cards.get('suzu-ent-card').innerHTML, /Account B/);
  page.changeToken(null);
  assert.equal(page.cards.size, 0);
}

const cases = { pendingFulfillment, sessionLifecycle };
(async () => {
  for (const name of process.argv.slice(2).length ? process.argv.slice(2) : Object.keys(cases)) {
    await cases[name]();
    process.stdout.write(`PASS ${name}\n`);
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
