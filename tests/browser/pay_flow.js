const puppeteer = require('puppeteer');
const crypto = require('crypto');
const sleep = ms => new Promise(r => setTimeout(r, ms));
const WHSEC = 'whsec_testsecret123';

async function webhook(sid, bid) {
  const payload = JSON.stringify({ id: 'evt_ui', type: 'checkout.session.completed', data: { object: {
    id: sid, client_reference_id: String(bid), metadata: { booking_id: String(bid) }, payment_status: 'paid', payment_intent: 'pi_ui_1', amount_total: 15000 } } });
  const t = Math.floor(Date.now() / 1000);
  const sig = crypto.createHmac('sha256', WHSEC).update(t + '.' + payload).digest('hex');
  const r = await fetch('http://127.0.0.1:18080/?rest_route=/jmb/v1/stripe', { method: 'POST', body: payload, headers: { 'Content-Type': 'application/json', 'Stripe-Signature': `t=${t},v1=${sig}` } });
  return r.status;
}

(async () => {
  const b = await puppeteer.launch({ args: ['--no-sandbox'] });
  const p = await b.newPage();
  p.on('pageerror', e => console.log('PAGE ERROR', e.message));
  await p.setViewport({ width: 390, height: 1500 });

  async function openForm(label) {
    await p.goto('http://127.0.0.1:18080/book/', { waitUntil: 'networkidle0' });
    await p.click('.jmb-nav[aria-label="Next month"]'); await p.waitForSelector('.jmb-open'); await sleep(300);
    for (const d of await p.$$('.jmb-open')) { if ((await d.evaluate(e => e.getAttribute('aria-label'))).includes(label)) { await d.click(); break; } }
    await sleep(300);
        await p.click('.jmb-slot'); await sleep(300);
  }

  // 1) pay flow
  await openForm('December 26');
  await p.type('input[name=name]', 'Ui Payer'); await p.type('input[name=email]', 'uipay@example.com');
  await p.select('select[name=location_pick]', 'Red Bud Isle');
  await Promise.all([p.waitForNavigation(), p.click('.jmb-submit')]);
  console.log('on mock Stripe page:', p.url().includes(':18081/pay/'), '|', await p.$eval('#amount', e => e.textContent));
  const sid = p.url().split('/pay/')[1];
  const sessions = await (await fetch('http://127.0.0.1:18081/sessions.json')).json();
  const bid = sessions[sid].params.client_reference_id;
  await Promise.all([p.waitForNavigation(), p.click('#pay')]);
  await sleep(1500);
  console.log('before webhook:', await p.$eval('.jmb-done h3', e => e.textContent));
  console.log('webhook HTTP', await webhook(sid, bid));
  await sleep(3000);
  console.log('after webhook:', await p.$eval('.jmb-done', e => e.innerText.replace(/\n+/g, ' | ')));
  console.log('url cleaned:', !p.url().includes('jmb_ref'));

  // 2) back/cancel flow
  console.log('Dec 26 mini now offered:', await p.evaluate(async () => {
    const j = await (await fetch('/?rest_route=/jmb/v1/availability&month=2026-12')).json();
    return JSON.stringify(j.days['2026-12-26'].map(s => s.kind + ' ' + s.time + ' @ ' + s.location)) + ' / Dec 27: ' + JSON.stringify(j.days['2026-12-27']);
  }));
  await p.goto('http://127.0.0.1:18080/book/', { waitUntil: 'networkidle0' });
  await openForm('December 12');
  await p.type('input[name=name]', 'Ui Backer'); await p.type('input[name=email]', 'uiback@example.com');
  await p.select('select[name=location_pick]', 'Mayfield Park');
  await Promise.all([p.waitForNavigation(), p.click('.jmb-submit')]);
  await Promise.all([p.waitForNavigation(), p.click('#back')]);
  await sleep(2000);
  console.log('after back:', await p.$eval('.jmb-done', e => e.innerText.replace(/\n+/g, ' | ')));
  await b.close();
})();
