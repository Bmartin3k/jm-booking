// Browser test: details -> review contract -> sign -> Stripe. Saves screenshots to /out when that folder is mounted.
const puppeteer = require('puppeteer');
const fs = require('fs');
const sleep = ms => new Promise(r => setTimeout(r, ms));
const shot = async (p, name) => { if (fs.existsSync('/out')) await p.screenshot({ path: `/out/${name}.png`, fullPage: true }); };

(async () => {
  const b = await puppeteer.launch({ args: ['--no-sandbox'] });
  const p = await b.newPage();
  p.on('pageerror', e => console.log('PAGE ERROR', e.message));
  await p.setViewport({ width: 390, height: 900 });
  await p.goto('http://127.0.0.1:18080/book/', { waitUntil: 'networkidle0' });
  await p.click('.jmb-nav[aria-label="Next month"]'); await p.waitForSelector('.jmb-open'); await sleep(300);
  for (const d of await p.$$('.jmb-open')) { if ((await d.evaluate(e => e.getAttribute('aria-label'))).includes('December 12')) { await d.click(); break; } }
  await sleep(300); await p.click('.jmb-slot'); await sleep(300);
  await p.type('input[name=name]', 'Casey Contract'); await p.type('input[name=email]', 'casey@example.com');
  await p.select('select[name=location_pick]', 'Red Bud Isle');
  console.log('button text:', await p.$eval('.jmb-submit', e => e.textContent));
  await p.click('.jmb-submit');
  await p.waitForSelector('.jmb-contract-box'); await sleep(400);
  const txt = await p.$eval('.jmb-contract-box', e => e.innerText);
  console.log('contract shown, has client + location:', txt.includes('Casey Contract') && txt.includes('Red Bud Isle'));
  await shot(p, 'contract-step');
  await p.click('.jmb-sign .jmb-submit'); await sleep(200);
  console.log('unsigned submit message:', await p.$eval('.jmb-sign .jmb-msg', e => e.textContent));
  await p.evaluate(() => [...document.querySelectorAll('.jmb-back')].find(b => b.textContent.includes('Edit your details')).click()); await sleep(200);
  console.log('edit details shows the form again with values kept:', await p.$eval('input[name=name]', e => e.value + ' / visible=' + (e.offsetParent !== null)));
  await p.click('.jmb-submit'); await p.waitForSelector('.jmb-contract-box'); await sleep(300);
  for (const c of await p.$$('.jmb-sign input[type=checkbox]')) await c.click();
  await p.type('input[name=signature]', 'Casey Contract');
  await shot(p, 'contract-signed');
  await Promise.all([p.waitForNavigation(), p.click('.jmb-sign .jmb-submit')]);
  console.log('after signing, on mock Stripe page:', p.url().includes(':18081/pay/'));
  await b.close();
})();
