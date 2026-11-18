(function () {
	'use strict';
	var cfg = window.JMB || {};
	var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
	var DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

	function el(tag, attrs, kids) {
		var n = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') n.textContent = attrs[k];
			else if (k.slice(0, 2) === 'on') n.addEventListener(k.slice(2), attrs[k]);
			else if (attrs[k] !== false && attrs[k] != null) n.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
		});
		(kids || []).forEach(function (c) { if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return n;
	}
	/* A random key kept in this browser: only the browser that started a checkout may start over for the same email. */
	var ckMemo = '';
	function clientKey() {
		if (ckMemo) return ckMemo;
		var k = '';
		try { k = window.localStorage.getItem('jmb_ck') || ''; } catch (e) {}
		if (!/^[A-Za-z0-9]{32}$/.test(k)) {
			var abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', r = new Uint32Array(32);
			window.crypto.getRandomValues(r);
			k = '';
			for (var i = 0; i < 32; i++) k += abc[r[i] % 62];
			try { window.localStorage.setItem('jmb_ck', k); } catch (e) {}
		}
		return (ckMemo = k);
	}
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function ym(y, m) { return y + '-' + pad(m + 1); }
	function prettyDate(ymd) {
		var p = ymd.split('-').map(Number);
		var d = new Date(p[0], p[1] - 1, p[2]);
		return d.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
	}

	function init(root) {
		var cal = root.querySelector('.jmb-cal');
		var panel = root.querySelector('.jmb-panel');
		var now = new Date();
		var view = { y: now.getFullYear(), m: now.getMonth() };
		var data = null;
		var selected = null;

		function load() {
			cal.setAttribute('aria-busy', 'true');
			fetch(cfg.api + 'availability?month=' + ym(view.y, view.m), { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (j) { data = j; render(); })
				.catch(function () { cal.textContent = 'Could not load the calendar. Please refresh the page.'; })
				.finally(function () { cal.removeAttribute('aria-busy'); });
		}

		function shift(delta) {
			view.m += delta;
			if (view.m < 0) { view.m = 11; view.y--; }
			if (view.m > 11) { view.m = 0; view.y++; }
			selected = null;
			panel.hidden = true;
			load();
		}

		function render() {
			var cur = ym(view.y, view.m);
			var minYm = data.today.slice(0, 7);
			var maxYm = data.max.slice(0, 7);
			var head = el('div', { class: 'jmb-head' }, [
				el('button', { type: 'button', class: 'jmb-nav', 'aria-label': 'Previous month', disabled: cur <= minYm, onclick: function () { shift(-1); } }, ['‹']),
				el('h3', { text: MONTHS[view.m] + ' ' + view.y }),
				el('button', { type: 'button', class: 'jmb-nav', 'aria-label': 'Next month', disabled: cur >= maxYm, onclick: function () { shift(1); } }, ['›'])
			]);
			var ws = parseInt(cfg.weekStart, 10) || 0;
			var grid = el('div', { class: 'jmb-grid', role: 'grid' });
			for (var i = 0; i < 7; i++) grid.appendChild(el('div', { class: 'jmb-dow', text: DOW[(i + ws) % 7] }));
			var first = new Date(view.y, view.m, 1);
			var lead = (first.getDay() - ws + 7) % 7;
			for (i = 0; i < lead; i++) grid.appendChild(el('div', { class: 'jmb-day jmb-empty' }));
			var days = new Date(view.y, view.m + 1, 0).getDate();
			var any = false;
			for (var d = 1; d <= days; d++) {
				var key = cur + '-' + pad(d);
				var slots = data.days[key] || [];
				var hasFull = slots.some(function (s) { return s.kind === 'full'; });
				var hasMini = slots.some(function (s) { return s.kind === 'mini'; });
				var open = slots.length > 0;
				if (open) any = true;
				var cls = 'jmb-day' + (open ? ' jmb-open' : ' jmb-closed') + (hasMini && !hasFull ? ' jmb-mini' : '') + (key === selected ? ' jmb-sel' : '');
				var label = prettyDate(key) + (open ? (hasMini && !hasFull ? ', mini session available' : ', available') : ', unavailable');
				grid.appendChild(el('button', {
					type: 'button', class: cls, disabled: !open, 'aria-label': label, 'aria-pressed': key === selected ? 'true' : 'false',
					onclick: (function (k) { return function () { pick(k); }; })(key)
				}, [el('span', { class: 'jmb-num', text: String(d) }), hasMini && !hasFull ? el('span', { class: 'jmb-tag', text: 'Mini' }) : null]));
			}
			var legend = el('div', { class: 'jmb-legend' }, [
				el('span', {}, [el('i', { class: 'jmb-sw jmb-sw-open' }), 'Available']),
				el('span', {}, [el('i', { class: 'jmb-sw jmb-sw-mini' }), 'Mini session']),
				el('span', {}, [el('i', { class: 'jmb-sw jmb-sw-closed' }), 'Unavailable'])
			]);
			cal.innerHTML = '';
			cal.appendChild(head);
			cal.appendChild(grid);
			cal.appendChild(legend);
			if (!any) cal.appendChild(el('p', { class: 'jmb-none', text: 'No open dates this month. Try the next month.' }));
		}

		function pick(key) {
			selected = key;
			render();
			var slots = data.days[key] || [];
			panel.innerHTML = '';
			panel.appendChild(el('h3', { text: prettyDate(key) }));
			var list = el('div', { class: 'jmb-slots' });
			slots.forEach(function (s) {
				list.appendChild(el('button', { type: 'button', class: 'jmb-slot jmb-slot-' + s.kind, onclick: function () { form(s); } }, [
					el('strong', { text: s.label }),
					el('span', { text: s.time }),
					s.price ? el('span', { class: 'jmb-price', text: s.price }) : null,
					s.charge ? el('span', { class: 'jmb-charge', text: s.charge }) : null,
					s.location ? el('span', { class: 'jmb-loc', text: 'Location: ' + s.location }) : null,
					s.note ? el('small', { text: s.note }) : null
				]));
			});
			panel.appendChild(list);
			panel.hidden = false;
			panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}

		function field(label, input) {
			return el('label', { class: 'jmb-field' }, [el('span', { text: label }), input]);
		}

		var OTHER = '__other__';
		var NOT_SURE = 'Not sure yet – help me choose';

		function locationField(f) {
			var groups = (cfg.locations || []).filter(function (g) { return g.items && g.items.length; });
			var label = 'Where would you like your session?';
			if (!groups.length) {
				f.appendChild(field(label, el('input', { name: 'location', required: true, maxlength: 200, placeholder: 'Park, neighborhood or address' })));
				return;
			}
			var sel = el('select', { name: 'location_pick', required: true }, [el('option', { value: '', text: 'Choose a location' })]);
			groups.forEach(function (g) {
				var parent = g.group ? el('optgroup', { label: g.group }) : sel;
				g.items.forEach(function (it) { parent.appendChild(el('option', { value: it, text: it })); });
				if (parent !== sel) sel.appendChild(parent);
			});
			var more = el('optgroup', { label: 'Other' }, [
				el('option', { value: NOT_SURE, text: NOT_SURE }),
				el('option', { value: OTHER, text: 'Somewhere else…' })
			]);
			sel.appendChild(more);
			var other = el('input', { name: 'location_other', maxlength: 200, placeholder: 'Park, neighborhood or address' });
			var otherWrap = field('Where?', other);
			otherWrap.hidden = true;
			sel.addEventListener('change', function () {
				otherWrap.hidden = sel.value !== OTHER;
				if (!otherWrap.hidden) other.focus();
			});
			f.appendChild(field(label, sel));
			f.appendChild(otherWrap);
		}

		function form(s) {
			panel.innerHTML = '';
			var back = el('button', { type: 'button', class: 'jmb-back', onclick: function () { pick(s.date); } }, ['‹ Back to times']);
			if (!data.online || !s.charge) {
				panel.appendChild(back);
				panel.appendChild(el('p', { class: 'jmb-msg', text: 'Online booking is not open yet. Please contact us to book this time.' }));
				panel.hidden = false;
				return;
			}
			var msg = el('p', { class: 'jmb-msg', role: 'alert' });
			var f = el('form', { class: 'jmb-form', novalidate: true });
			f.appendChild(el('p', { class: 'jmb-summary' }, [
				el('strong', { text: s.label }), ' · ' + prettyDate(s.date) + ' · ' + s.time + (s.price ? ' · ' + s.price : '') + (s.location ? ' · ' + s.location : '')
			]));
			f.appendChild(field('Your name', el('input', { name: 'name', required: true, autocomplete: 'name', maxlength: 120 })));
			f.appendChild(field('Email', el('input', { name: 'email', type: 'email', required: true, autocomplete: 'email' })));
			f.appendChild(field('Phone', el('input', { name: 'phone', type: 'tel', autocomplete: 'tel', maxlength: 40 })));
			if (s.kind === 'full') locationField(f);
			f.appendChild(field('Anything else?', el('textarea', { name: 'notes', rows: 3, maxlength: 2000 })));
			f.appendChild(el('input', { name: 'website', class: 'jmb-hp', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' }));
			var ts = null, tsId = null;
			if (cfg.turnstile) {
				ts = el('div', { class: 'jmb-turnstile' });
				f.appendChild(ts);
			}
			f.appendChild(el('p', { class: 'jmb-fine', text: 'Next you pay the ' + s.charge.replace(/ due now$/, '') + ' by card on Stripe’s secure page. Your time is held for ' + data.checkout_minutes + ' minutes while you pay and is booked as soon as payment goes through.' }));
			var btn = el('button', { type: 'submit', class: 'jmb-submit', text: 'Continue to payment' });
			f.appendChild(btn);
			f.appendChild(msg);
			if (data.contract) btn.textContent = 'Review and sign the contract';
			var idleText = btn.textContent;

			function turnstileToken() { return ts && window.turnstile && tsId !== null ? window.turnstile.getResponse(tsId) : ''; }

			function collect() {
				var body = { kind: s.kind, date: s.date, start: s.start, return_url: location.origin + location.pathname, client_key: clientKey() };
				Array.prototype.forEach.call(f.elements, function (x) { if (x.name) body[x.name] = x.value.trim(); });
				if ('location_pick' in body) {
					body.location = body.location_pick === OTHER ? body.location_other : body.location_pick;
					delete body.location_pick;
					delete body.location_other;
				}
				return body;
			}

			function post(path, body) {
				return fetch(cfg.api + path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
					.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j || {} }; }); });
			}

			/* Book and go to Stripe. onFail(code, message) puts the right step back. */
			function book(body, button, out, onFail) {
				if (ts) body.cf_turnstile = turnstileToken();
				button.disabled = true;
				button.textContent = 'Opening payment…';
				out.textContent = '';
				post('book', body).then(function (res) {
					if (!res.ok) { var e = new Error(res.j.message || 'Something went wrong.'); e.code = res.j.code; throw e; }
					if (!res.j.checkout_url) throw new Error('Payment could not be started. Please try again.');
					window.location.assign(res.j.checkout_url);
				}).catch(function (err) {
					if (ts && window.turnstile && tsId !== null) window.turnstile.reset(tsId);
					onFail(err.code, err.message);
					load();
				});
			}

			/* Step 2: the contract with their details filled in; they tick both boxes and type their name. */
			function showContract(body, note) {
				btn.disabled = true;
				btn.textContent = 'Loading the contract…';
				msg.textContent = '';
				post('contract', body).then(function (res) {
					btn.disabled = false;
					btn.textContent = idleText;
					if (!res.ok) { msg.textContent = res.j.message || 'The contract could not be loaded. Please try again.'; return; }
					var c = res.j;
					var step = el('form', { class: 'jmb-form jmb-sign', novalidate: true });
					var edit = el('button', { type: 'button', class: 'jmb-back' }, ['‹ Edit your details']);
					edit.addEventListener('click', function () { step.remove(); edit.remove(); f.hidden = false; back.hidden = false; });
					var box = el('div', { class: 'jmb-contract-box', tabindex: '0', role: 'region', 'aria-label': c.title });
					c.blocks.forEach(function (b) { box.appendChild(el(b[0] === 'h' ? 'h4' : 'p', { text: b[1] })); });
					var esign = el('input', { type: 'checkbox', name: 'agree_esign', value: '1' });
					var agree = el('input', { type: 'checkbox', name: 'agree_terms', value: '1' });
					var sig = el('input', { name: 'signature', required: true, autocomplete: 'name', maxlength: 120, class: 'jmb-sig-input', placeholder: 'Your full name' });
					var out = el('p', { class: 'jmb-msg', role: 'alert', text: note || '' });
					var go = el('button', { type: 'submit', class: 'jmb-submit', text: 'Sign and continue to payment' });
					step.appendChild(el('p', { class: 'jmb-summary' }, [el('strong', { text: 'Please read and sign' }), ' · ' + s.label + ', ' + prettyDate(s.date) + ', ' + s.time]));
					step.appendChild(box);
					step.appendChild(el('label', { class: 'jmb-check' }, [esign, el('span', { text: c.consents.esign })]));
					step.appendChild(el('label', { class: 'jmb-check' }, [agree, el('span', { text: c.consents.agree })]));
					step.appendChild(field('Type your full name to sign', sig));
					step.appendChild(el('p', { class: 'jmb-fine', text: 'A copy of the signed contract is emailed to you once your payment goes through.' }));
					step.appendChild(go);
					step.appendChild(out);
					step.addEventListener('submit', function (e) {
						e.preventDefault();
						if (!esign.checked || !agree.checked) { out.textContent = 'Please tick both boxes to sign.'; return; }
						if (sig.value.trim().length < 2) { out.textContent = 'Please type your full name to sign.'; sig.focus(); return; }
						var full = Object.assign(collect(), { contract_hash: c.hash, signature: sig.value.trim(), agree_esign: 1, agree_terms: 1 });
						book(full, go, out, function (code, text) {
							if (code === 'jmb_contract_changed') { step.remove(); edit.remove(); showContract(collect(), text); return; }
							out.textContent = text;
							go.disabled = false;
							go.textContent = 'Sign and continue to payment';
						});
					});
					f.hidden = true;
					back.hidden = true;
					panel.appendChild(edit);
					panel.appendChild(step);
					edit.focus();
					panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
				}).catch(function () {
					btn.disabled = false;
					btn.textContent = idleText;
					msg.textContent = 'The contract could not be loaded. Please try again.';
				});
			}

			f.addEventListener('submit', function (e) {
				e.preventDefault();
				var body = collect();
				if (ts && !turnstileToken()) { msg.textContent = 'Please complete the "verify you are human" check.'; return; }
				if (!body.name || !body.email || (s.kind === 'full' && !body.location)) { msg.textContent = 'Please fill in your name, email' + (s.kind === 'full' ? ' and location.' : '.'); return; }
				if (data.contract) { showContract(body); return; }
				book(body, btn, msg, function (code, text) {
					msg.textContent = text;
					btn.disabled = false;
					btn.textContent = idleText;
				});
			});
			panel.appendChild(back);
			panel.appendChild(f);
			panel.hidden = false;
			if (ts) {
				(function render(tries) {
					if (window.turnstile) tsId = window.turnstile.render(ts, { sitekey: cfg.turnstile });
					else if (tries < 50) setTimeout(function () { render(tries + 1); }, 200);
				})(0);
			}
			f.querySelector('input').focus();
		}

		/* Coming back from Stripe Checkout: ?jmb=paid|cancel&jmb_ref=<token> */
		function handleReturn() {
			var q = new URLSearchParams(location.search);
			var kind = q.get('jmb'), ref = q.get('jmb_ref');
			if (!kind || !/^[A-Za-z0-9]{32}$/.test(ref || '')) return;
			q.delete('jmb'); q.delete('jmb_ref');
			history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : '') + location.hash);
			var box = el('div', { class: 'jmb-done', role: 'status' });
			panel.innerHTML = '';
			panel.appendChild(box);
			panel.hidden = false;
			function show(title, text) { box.innerHTML = ''; box.appendChild(el('h3', { text: title })); if (text) box.appendChild(el('p', { text: text })); }
			if (kind === 'cancel') {
				show('Payment cancelled', 'Nothing was charged and the time has been released. You can pick a time again below.');
				fetch(cfg.api + 'cancel', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ref: ref }) })
					.finally(load);
				return;
			}
			show('Finishing your booking…', 'Waiting for the payment confirmation from Stripe.');
			var tries = 0;
			(function poll() {
				fetch(cfg.api + 'status?ref=' + encodeURIComponent(ref), { credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (j) {
						if (j.status === 'confirmed') {
							show('You’re booked!', 'Your ' + j.summary + ' is confirmed' + (j.paid ? ' and your ' + j.paid + ' payment was received' : '') + '. A confirmation email is on its way.');
							load();
						} else if (j.status === 'pending' && ++tries < 20) {
							setTimeout(poll, 1500);
						} else if (j.status === 'pending') {
							show('Payment received, still confirming', 'Stripe is taking a little longer than usual. You will get a confirmation email shortly; no need to book again.');
						} else {
							show('We could not confirm this booking', 'If you were charged, please contact us and we will sort it out right away.');
						}
					})
					.catch(function () { if (++tries < 20) setTimeout(poll, 1500); });
			})();
		}

		handleReturn();
		load();
	}

	document.querySelectorAll('[data-jmb]').forEach(init);
})();
