/**
 * Hidden Categories — page behaviour.
 *
 * Split of responsibility:
 *   hidden.css   does the actual hiding, from attributes this file sets
 *   this file    sets those attributes, and adds the conveniences
 *
 * Everything here is defensive on purpose. A theme that renames a class, a
 * FreshRSS upgrade that moves the tree, or a missing element must leave the
 * page working: every lookup is guarded, nothing throws on absence, and the
 * core hiding (attributes + CSS) does not depend on any of the DOM surgery
 * below. If this file fails to load entirely, hiding still works because the
 * attributes are also set from the inline call at the bottom of the page
 * (see extension.php: injectJsVars sets data-hc-* via the payload we read).
 */
(function () {
	'use strict';

	function payload() {
		var ctx = window.context || {};
		var ext = ctx.extensions || {};
		return ext.hiddenCategories || null;
	}

	// window.context is built by FreshRSS's own main.js, which is loaded
	// `defer async` — so it can execute AFTER this file (async scripts run in
	// load order, not document order). Reading the payload once at load and
	// bailing out when context is not there yet is what made every click and
	// every hiding silently do nothing. So: wait for the context to appear
	// (event, poll, or a short deadline), and only then start.
	//
	// The deadline matters: if main.js never runs (a broken theme), we stop
	// quietly rather than leave a polling loop behind.
	function whenContext(fn) {
		var tries = 0;
		var done = function (HC) {
			if (HC && typeof HC === 'object') { fn(HC); }
		};
		var check = function () {
			var HC = payload();
			if (HC) { done(HC); return true; }
			return false;
		};
		if (check()) { return; }
		document.addEventListener('freshrss:globalContextLoaded', function () {
			if (check()) { return; }
			var t = setInterval(function () {
				if (check() || ++tries > 40) { clearInterval(t); }
			}, 50);
		}, { once: true });
		var t0 = setInterval(function () {
			if (check() || ++tries > 40) { clearInterval(t0); }
		}, 50);
	}

	whenContext(start);

	function start(HC) {

	var cats = Array.isArray(HC.cats) ? HC.cats.map(Number) : [];
	var meta = HC.meta && typeof HC.meta === 'object' ? HC.meta : {};
	var view = HC.view === 'revealed' || HC.view === 'only' ? HC.view : 'hidden';
	var next = HC.next || 'hidden';
	// locked here means "armed AND this session has not unlocked" — the server
	// decides that, so the page cannot disagree with it
	var locked = !!HC.locked;
	var isHidden = {};
	for (var i = 0; i < cats.length; i++) {
		isHidden[cats[i]] = true;
	}

	var root = document.documentElement;

	// ---- 1. the attributes the stylesheet keys off -------------------------
	//
	// Set immediately, before any DOM walk, so the CSS can hide things at first
	// paint. This is the only part of this file the core behaviour depends on.
	root.setAttribute('data-hc-hidden', cats.join(','));
	root.setAttribute('data-hc-view', view);
	root.setAttribute('data-hc-mode', 'hide');

	// ---- the curtain ------------------------------------------------------
	//
	// The shared row above the tree advertises things: an eye, a lock, a pin
	// box, and whatever a sibling extension puts beside them. Collapsing it
	// takes all of that out of sight in one move, so an armed or filtered
	// instance looks like a plain sidebar.
	//
	// Kept in localStorage, not in the extension's own state: it is a display
	// choice for THIS browser — the screen someone else can look at — so
	// flipping it costs no request and no reload. Reading it here, before any
	// control is built, is what stops the row painting open and then
	// collapsing.
	var BAR_KEY = 'hc-bar';

	function readBarCollapsed() {
		try {
			return window.localStorage.getItem(BAR_KEY) === 'closed';
		} catch (e) {
			return false;   // storage disabled (private mode): start open
		}
	}

	function writeBarCollapsed(closed) {
		try {
			window.localStorage.setItem(BAR_KEY, closed ? 'closed' : 'open');
		} catch (e) {
			// a browser that cannot store still toggles; it just forgets
		}
	}

	root.setAttribute('data-hc-bar', readBarCollapsed() ? 'closed' : 'open');

	// ---- 2. per-id rules, injected once ------------------------------------
	//
	// The hiding selectors need one line per hidden category. Generating them
	// here keeps hidden.css static and cacheable, and costs a few bytes even
	// with every category hidden. Both hooks below already exist in stock
	// FreshRSS markup, so no DOM surgery is required for the hiding itself:
	//   sidebar   <li id="c_<catId>">
	//   stream     <div class="flux" data-category="<catId>">
	function markStyles() {
		if (cats.length === 0) { return; }
		if (document.getElementById('hc-rules')) { return; }
		var rules = [];
		for (var n = 0; n < cats.length; n++) {
			var id = cats[n];
			// sidebar: gone only in the 'hidden' view; revealed/only must show it
			rules.push('html[data-hc-mode="hide"][data-hc-view="hidden"] li.tree-folder.category#c_'
				+ id + '{display:none!important}');
		}
		var st = document.createElement('style');
		st.id = 'hc-rules';
		st.textContent = rules.join('\n');
		(document.head || document.documentElement).appendChild(st);
	}

	// Markers for anything the CSS cannot reach by id (a theme that renames the
	// tree classes). Purely additive: failing to find them hides nothing extra.
	function markTree() {
		var nodes = document.querySelectorAll('li.tree-folder.category');
		for (var n = 0; n < nodes.length; n++) {
			var el = nodes[n];
			var id = parseInt(String(el.id || '').replace(/^c_/, ''), 10);
			if (id > 0) {
				el.setAttribute('data-hc-cat', String(id));
				el.setAttribute('data-hc-hidden', isHidden[id] ? '1' : '0');
			} else {
				// Main stream / Important / Favourites / Recently Read and other
				// pseudo-categories have no id of ours. They are never hidden, so
				// they must be marked 0 explicitly or the 'only' view's dimming
				// rule (which selects [data-hc-hidden="0"]) would skip them.
				el.setAttribute('data-hc-hidden', '0');
			}
		}
	}

	function markStream() {
		var nodes = document.querySelectorAll('.flux[data-category]');
		for (var n = 0; n < nodes.length; n++) {
			var el = nodes[n];
			var id = parseInt(el.getAttribute('data-category'), 10);
			if (!id) { continue; }
			var hid = !!isHidden[id];
			el.setAttribute('data-hc-hidden', hid ? '1' : '0');

			// Three states, three stream behaviours — this is the whole point of
			// having three:
			//   hidden   (closed eye)  -> hide articles from hidden categories
			//   revealed (peeking eye) -> MIX: nothing is filtered, hidden and
			//                             visible articles appear together
			//   only     (open eye)    -> hide everything that is NOT hidden
			// 'revealed' previously kept filtering the stream, which made the
			// state look like it did nothing at all.
			var out;
			if (view === 'only') {
				out = !hid;
			} else if (view === 'revealed') {
				out = false;
			} else {
				out = hid;
			}
			el.classList.toggle('hc-stream-out', out);
		}
	}

	function markAll() {
		markStyles();
		markTree();
		markStream();
	}

	// ---- 3. post a change -------------------------------------------------
	//
	// Every control funnels through here, and every one of them hits the same
	// server action. One write path means the sidebar, the menu and the settings
	// page cannot drift apart.
	/**
	 * Where to send the user back to after unlocking. Only our own index paths
	 * are accepted by the server, so this cannot become an open redirect.
	 */
	function backUrl() {
		try {
			var u = new URL(window.location.href);
			return u.pathname + u.search;
		} catch (e) {
			return '/i/';
		}
	}

	function csrfToken() {
		var el = document.querySelector('input[name="_csrf"]');
		return (window.context && window.context.csrf) || (el ? el.value : '');
	}

	function post(extra, done) {
		if (!HC.api) { return; }
		var csrf = csrfToken();
		var body = new URLSearchParams();
		body.append('_csrf', csrf);
		Object.keys(extra).forEach(function (k) { body.append(k, extra[k]); });

		fetch(HC.api, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		}).then(function (r) {
			if (r.ok || r.redirected) {
				if (done) { done(); }
				window.location.reload();
			} else {
				toast('Could not save (HTTP ' + r.status + ')', true);
			}
		}).catch(function () {
			toast('Could not reach the server', true);
		});
	}

	// ---- 4. toast ---------------------------------------------------------
	function toast(msg, bad) {
		var n = document.createElement('div');
		n.textContent = msg;
		n.className = 'hc-toast';
		n.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);'
			+ 'z-index:99999;padding:10px 18px;border-radius:7px;font-size:13px;'
			+ 'background:' + (bad ? '#a83232' : '#333') + ';color:#fff;opacity:0;'
			+ 'transition:opacity .18s ease;box-shadow:0 4px 14px rgba(0,0,0,.35)';
		document.body.appendChild(n);
		requestAnimationFrame(function () { n.style.opacity = '1'; });
		setTimeout(function () {
			n.style.opacity = '0';
			setTimeout(function () { if (n.parentNode) { n.parentNode.removeChild(n); } }, 220);
		}, bad ? 4000 : 2200);
	}

	// ---- 5. the one button: hidden -> revealed -> only -> hidden ----------
	//
	// Deliberately ONE control, in one place, cycling three states. Anything
	// that hides things (settings page, subscription page, right-click) calls
	// the same server action, so this button always reflects reality.
	// Three eye states, one per view. Drawn inline with currentColor so they
	// take the theme's text colour and inherit its size, with no icon-font or
	// image dependency. 16x16 viewBox keeps them crisp at sidebar scale.
	//
	//   closed  -> hidden:   what you hid is gone
	//   peek    -> revealed: hidden ones are back, showing among the rest
	//   open    -> only:     you are looking at just the hidden ones
	var ICONS = {
		hidden:
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"'
			+ ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			+ '<path d="M3 3l18 18"/>'
			+ '<path d="M10.6 6.2A9.7 9.7 0 0 1 12 6c5 0 9 4.5 9 6 0 .8-.9 2.3-2.4 3.6"/>'
			+ '<path d="M6.2 7.7C4.1 9.1 3 10.6 3 12c0 1.5 4 6 9 6 1.4 0 2.7-.3 3.9-.9"/>'
			+ '<path d="M9.9 10.1a3 3 0 0 0 4.2 4.2"/></svg>',
		revealed:
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"'
			+ ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			+ '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"/>'
			+ '<circle cx="12" cy="12" r="2.6"/>'
			+ '<path d="M4 19.5c5-3.5 11-3.5 16 0"/></svg>',
		only:
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"'
			+ ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			+ '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"/>'
			+ '<circle cx="12" cy="12" r="3.2" fill="currentColor" stroke="none"/></svg>'
	};

	var LOCK_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"'
		+ ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
		+ '<rect x="4.5" y="10.5" width="15" height="10" rx="2"/>'
		+ '<path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/></svg>';

	// Key glyph for the unlock submit button. Same inline-SVG convention as
	// LOCK_ICON, so the button reads as a key regardless of emoji support.
	var KEY_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"'
		+ ' stroke-linecap="round" stroke-linejoin="round"'
		+ ' aria-hidden="true" focusable="false" width="1em" height="1em"'
		+ ' style="vertical-align:-0.125em"><circle cx="7.5" cy="15.5" r="4.5"/>'
		+ '<path d="M10.7 12.3 20 3m-3.5 0H20v3.5"/></svg>';

	var LABELS = {
		// label names what the NEXT click does; the icon shows the CURRENT state
		// label and tooltip both name the state you are IN, not where the click goes
		hidden: { text: 'Hidden', title: 'Hidden categories are hidden from the sidebar and stream' },
		revealed: { text: 'Shown', title: 'Everything is showing again \u2014 hidden categories are marked' },
		only: { text: 'Just Hidden', title: 'Only hidden categories are showing' }
	};

	function buildButton() {
		// The bar is SHARED GROUND, not ours: any extension that wants a
		// control above the sidebar tree appends into this one row, and the row
		// sizes its children generically (see .rs-bar-row in the stylesheet).
		// So the id is deliberately neutral and nothing here assumes it owns
		// the row: get-or-create, then append. Alone that leaves one button at
		// full width; with another extension present it splits, and neither
		// side has to know the other exists.
		var bar = document.getElementById('rs-bar');
		if (!bar) {
			var tree = document.querySelector('nav#sidebar .tree') || document.querySelector('.tree');
			if (!tree) { return; }
			bar = document.createElement('div');
			bar.id = 'rs-bar';
			bar.className = 'rs-bar';
			// sits directly above the tree, so it is the first thing in reach
			tree.parentNode.insertBefore(bar, tree);
			var wrap = document.createElement('div');
			wrap.className = 'rs-bar-row';
			bar.appendChild(wrap);
		}
		var btnWrap = bar.querySelector('.rs-bar-row');
		if (!btnWrap) { return; }
		// The bar stays visible if there is ANYTHING to act on: hidden things to
		// reveal, or a passcode (so you can lock before hiding anything). Hiding
		// it when nothing is hidden made the lock unreachable, which is exactly
		// the case "hit lock with nothing hidden" is about.
		var anythingToDo = cats.length > 0 || !!HC.lockAvailable || locked;
		if (!anythingToDo) {
			bar.style.display = 'none';
			return;
		}
		bar.style.display = '';

		// THE SHARED ROW BELONGS TO NOBODY. Other extensions (FavoriteCategories)
		// append their own control into this same .rs-bar-row and keep it alive
		// with an idempotence guard — they draw once and never re-render. So this
		// extension must never clear the row: doing that deletes their button,
		// and their guard means they never put it back.
		//
		// We also must not hand our own controls to the row as a single wrapper.
		// The row sizes every DIRECT child equally (.rs-bar-row > * { flex: 1 1 0 }),
		// so a wrapper would claim one share and then split it again between the
		// eye and the lock — leaving our eye narrower than a sibling's single
		// button. Instead the eye and lock sit in the row as its own direct
		// children, so they take their shares from the row itself.
		//
		// Removing any of our previous controls happens by id, never by clearing
		// the row — a sibling's button must survive untouched.
		var removeOwn = function (id) {
			var el = document.getElementById(id);
			if (el && el.parentNode) { el.parentNode.removeChild(el); }
		};
		removeOwn('hc-bar-toggle');
		removeOwn('hc-unlock-form');
		removeOwn('hc-view-btn');
		removeOwn('hc-lock-btn');

		// ---- the collapse switch -------------------------------------------
		// Its OWN line above the row, not a child of it. The row is shared
		// ground with a fixed width, so any control added to it takes space
		// from every other control — and the pin field in the locked state is
		// the one that cannot spare it (measured: it lost 14px, leaving 6
		// digits nowhere to sit). Standing above the row costs a strip of
		// height instead and leaves the row exactly as wide as it was.
		//
		// Collapsed, this is the only thing left drawn in the bar: the
		// stylesheet hides the bar's other children generically, so a sibling
		// extension's button goes with ours and its code is never touched.
		var tog = document.createElement('button');
		tog.type = 'button';
		tog.id = 'hc-bar-toggle';
		tog.className = 'hc-bar-toggle';
		var drawToggle = function () {
			var closed = root.getAttribute('data-hc-bar') === 'closed';
			tog.innerHTML = '<span class="hc-caret" aria-hidden="true">'
				+ (closed ? '\u25B8' : '\u25BE') + '</span>';
			tog.title = closed ? 'Show the sidebar controls' : 'Hide the sidebar controls';
			tog.setAttribute('aria-label', tog.title);
			tog.setAttribute('aria-expanded', closed ? 'false' : 'true');
		};
		tog.addEventListener('click', function (ev) {
			ev.preventDefault();
			ev.stopPropagation();
			var closed = root.getAttribute('data-hc-bar') !== 'closed';
			root.setAttribute('data-hc-bar', closed ? 'closed' : 'open');
			writeBarCollapsed(closed);
			drawToggle();
		});
		drawToggle();
		// first child of the bar, so it is the top-left corner of the sidebar
		// whatever else any extension has put in there
		bar.insertBefore(tog, bar.firstChild);

		// ---- placement: this extension's control sits FIRST in the row ------
		// Appending would make our position depend on which extension's script
		// happened to run last, so the eye landed to the right of the favorites
		// star whenever FavoriteCategories drew after us — and the locked
		// unlock form did the same, for the same reason. There is no ordering
		// logic in either extension; it is pure execution order.
		//
		// Insert before the first sibling we do not own instead. That fixes our
		// side to the left of any other extension's control without naming it,
		// in both the locked and unlocked states, and leaves siblings' order
		// among themselves exactly as it was.
		var isOurs = function (el) {
			return el.id === 'hc-view-btn' || el.id === 'hc-lock-btn'
				|| el.id === 'hc-unlock-form';
		};
		var placeOwn = function (node) {
			var ref = null;
			for (var k = 0; k < btnWrap.children.length; k++) {
				var kid = btnWrap.children[k];
				if (!isOurs(kid)) { ref = kid; break; }
			}
			btnWrap.insertBefore(node, ref);
		};

		// The eye button is created INSIDE the unlocked branch below, because the
		// locked branch replaces this row's contents with a form. Looking it up
		// here (as this code used to) returned null whenever the previous render
		// had been the locked one, and the function bailed out before drawing the
		// unlock form — the lock rendered as an empty row on every reload.

		// ---- locked: the unlock form replaces our own controls --------------
		if (locked) {
			var form = document.createElement('form');
			form.className = 'hc-unlock';
			form.id = 'hc-unlock-form';
			form.method = 'post';
			form.action = HC.api || '';
			// A fresh seed for the passcode field on every render. Locking
			// reloads the page, so every time you lock this box is a NEW input
			// as far as the browser is concerned: the id and the autocomplete
			// token both change, and a browser or password manager that keys
			// autofill off either one finds no match and leaves it empty.
			// Without this the field could come back pre-filled from a save
			// prompt, and one click of the key would unlock it — the lock
			// would be decorative.
			// The server reads it by name, so the name stays 'code'.
			var seed = 'hc-code-' + Date.now().toString(36)
				+ '-' + Math.random().toString(36).slice(2, 8);
			form.innerHTML = '<span class="hc-ico hc-lock-ico">' + LOCK_ICON + '</span>'
				+ '<input type="hidden" name="_csrf" value="' + csrfToken() + '" />'
				+ '<input type="hidden" name="act" value="unlock" />'
				+ '<input type="hidden" name="back" value="' + backUrl() + '" />'
				+ '<input type="password" name="code" class="hc-code"'
				+ ' id="' + seed + '" autocomplete="new-password" spellcheck="false"'
				+ ' inputmode="numeric" pattern="[0-9]*" maxlength="6"'
				+ ' data-lpignore="true" data-1p-ignore data-form-type="other"'
				+ ' placeholder="pin" aria-label="Pin, up to 6 digits" />'
				+ '<button type="submit" class="hc-unlock-btn" aria-label="Unlock" title="Unlock">' + KEY_ICON + '</button>';
			form.addEventListener('submit', function (ev) {
				var f = form.querySelector('.hc-code');
				if (!f || f.value === '') { ev.preventDefault(); return; }
			});
			// a direct child of the row, so the row sizes it like any sibling button
			placeOwn(form);
			var fld = form.querySelector('.hc-code');
			if (fld) {
				// a browser that fills anyway is overruled: clear it after any
				// autofill has had its go, so what you see is what you must type
				fld.value = '';
				// digits only, six at most — maxlength/pattern are hints a paste
				// or a password manager can bypass, so strip here as well
				fld.addEventListener('input', function () {
					var clean = fld.value.replace(/[^0-9]/g, '').slice(0, 6);
					if (clean !== fld.value) { fld.value = clean; }
				});
				try { fld.focus(); } catch (e) {}
			}
			return;
		}

		// ---- unlocked: eye + lock button -----------------------------------
		var b = document.createElement('button');
		b.type = 'button';
		b.id = 'hc-view-btn';
		b.className = 'hc-view-btn';
		b.addEventListener('click', function (ev) {
			ev.preventDefault();
			post({ act: 'view', to: next });
		});
		if (cats.length === 0) {
			// nothing hidden yet: the eye would be a no-op, so only the lock is
			// offered (it hides whatever is in the hidden set, which is nothing)
			if (HC.lockAvailable) {
				var lk0 = document.createElement('button');
				lk0.type = 'button';
				lk0.id = 'hc-lock-btn';
				lk0.className = 'hc-lock-btn hc-lock-btn-wide';
				lk0.title = 'Lock hidden categories (nothing is hidden yet)';
				lk0.innerHTML = LOCK_ICON + '<span class="hc-txt">Lock</span>';
				lk0.addEventListener('click', function (ev) {
					ev.preventDefault();
					post({ act: 'lock' });
				});
				placeOwn(lk0);
			}
			return;
		}
		var l = LABELS[view] || LABELS.hidden;
		// icon = current state, label = destination; the label is split into
		// one span per word so it can wrap inside a narrow shared row
		// ("Only" / "hidden") instead of clipping. Same machinery as the
		// star button in the sibling extension.
		var words = l.text.split(' ');
		var txt = '<span class="hc-txt">'
			+ '<span class="hc-w">' + words[0] + '</span>'
			+ (words.length > 1 ? ' <span class="hc-w">' + words.slice(1).join(' ') + '</span>' : '')
			+ '</span>';
		b.innerHTML = '<span class="hc-ico">' + (ICONS[view] || ICONS.hidden) + '</span>' + txt;
		b.title = l.title;
		b.setAttribute('data-hc-state', view);
		b.setAttribute('aria-label', l.text + ' \u2014 ' + l.title);
		// The eye and the lock are separate direct children of the row, so the
		// row gives each its own share. placeOwn (not appendChild) keeps the
		// pair on the left of any sibling control regardless of which
		// extension's script ran first.
		placeOwn(b);

		// only offer the lock when a passcode exists, so the button can never
		// lead to a dead end
		if (HC.lockAvailable) {
			var lk = document.createElement('button');
			lk.type = 'button';
			lk.id = 'hc-lock-btn';
			lk.className = 'hc-lock-btn';
			lk.title = 'Lock: hide everything and require the passcode';
			lk.setAttribute('aria-label', 'Lock hidden categories');
			lk.innerHTML = LOCK_ICON;
			lk.addEventListener('click', function (ev) {
				ev.preventDefault();
				post({ act: 'lock' });
			});
			placeOwn(lk);
		}
	}

	// ---- 6. the sidebar "Hidden (N)" section ------------------------------
	//
	// Doubles as the list of what is hidden and the fastest way to unhide one
	// thing. Rendered in every view, because "reveal" should not cost a page.
	function buildHiddenSection() {
		if (cats.length === 0) { return; }
		// while locked the list is the thing being protected, so it is not
		// rendered at all — not merely collapsed
		if (locked) { return; }
		var tree = document.querySelector('nav#sidebar .tree') || document.querySelector('.tree');
		if (!tree) { return; }
		if (document.getElementById('hc-hidden-tree')) { return; }

		var li = document.createElement('li');
		li.id = 'hc-hidden-tree';
		li.className = 'tree-folder category hc-tree';

		var title = document.createElement('a');
		title.className = 'tree-folder-title';
		title.href = '#';
		title.setAttribute('role', 'button');
		title.setAttribute('aria-expanded', view === 'hidden' ? 'false' : 'true');
		title.title = cats.length + ' hidden ' + (cats.length === 1 ? 'category' : 'categories');
		// Three display states from the settings page, stored as `rowmode`:
		//   caret   nothing but the disclosure caret
		//   number  the count on its own
		//   both    rowLabel text, then the count
		// In caret mode a glance at the screen learns nothing. The row itself
		// always stays: it is how you expand the list, and the eye button above
		// is unaffected. The accessible name and tooltip stay descriptive in
		// every mode, so they never disagree with what is drawn.
		var rowMode = HC.rowMode === 'number' || HC.rowMode === 'both' ? HC.rowMode : 'caret';
		var label = typeof HC.rowLabel === 'string' ? HC.rowLabel : 'Hidden';
		var drawLabel = rowMode === 'both' && label !== '';
		var drawNum = rowMode !== 'caret';
		title.innerHTML = '<button class="dropdown-toggle" type="button" title="Expand">'
			+ '<span class="hc-caret">&#9662;</span></button>'
			+ (!drawLabel && !drawNum ? ''
				: '<span class="title">'
					+ (drawLabel ? '<span class="hc-row-label"></span>' : '')
					+ (drawNum ? '<span class="hc-row-num">' + cats.length + '</span>' : '')
					+ '</span>');
		// the label goes in as TEXT, never as markup: it is user-typed and
		// must not be able to inject anything into the page
		var labelEl = title.querySelector('.hc-row-label');
		if (labelEl) { labelEl.textContent = label; }

		var chip = document.createElement('span');
		chip.className = 'hc-chip';
		chip.textContent = HC.mode === 'strict' ? 'enforced' : 'visual';
		chip.title = HC.mode === 'strict'
			? 'Feeds in these categories have FreshRSS\'s "show in its category" visibility.'
			: 'Nothing is written to your feeds; this is filtered in the browser.';
		title.appendChild(chip);

		var ul = document.createElement('ul');
		ul.className = 'tree-folder-items' + (view === 'hidden' ? '' : ' hc-open');
		ul.style.display = view === 'hidden' ? 'none' : '';

		cats.forEach(function (id) {
			var info = meta[id] || {};
			var item = document.createElement('li');
			item.className = 'item hc-item';

			var a = document.createElement('a');
			a.className = 'hc-name';
			a.href = './?get=c_' + id;
			a.textContent = info.name || ('Category ' + id);

			var count = document.createElement('span');
			count.className = 'hc-count';
			count.textContent = info.feeds != null ? info.feeds + ' feeds' : '';

			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'hc-unhide';
			btn.title = 'Unhide this category (it goes back to normal)';
			btn.setAttribute('aria-label', 'Unhide ' + (info.name || ('category ' + id)));
			btn.textContent = '\u00D7';
			btn.addEventListener('click', function (ev) {
				ev.preventDefault();
				ev.stopPropagation();
				post({ act: 'unhide', cat: id });
			});

			item.appendChild(a);
			item.appendChild(count);
			item.appendChild(btn);
			ul.appendChild(item);
		});

		// The row is an <a href="#">; themes and core scripts also bind click and
		// preventDefault, and an inline `display` can be overridden by a CSS
		// `.active` class the theme toggles. So: own the click in CAPTURE phase,
		// stop it reaching anything else, and toggle a class instead of an inline
		// style — the class is what the CSS and the theme both understand.
		var toggle = function (ev) {
			ev.preventDefault();
			ev.stopPropagation();
			var open = !ul.classList.contains('hc-open');
			ul.classList.toggle('hc-open', open);
			title.setAttribute('aria-expanded', open ? 'true' : 'false');
		};
		title.addEventListener('click', toggle, true);
		// the theme's caret button inside the row would otherwise swallow it
		var caret = title.querySelector('.dropdown-toggle');
		if (caret) { caret.addEventListener('click', toggle, true); }

		li.appendChild(title);
		li.appendChild(ul);
		tree.appendChild(li);
	}

	// ---- 7a. the banner: says the stream is filtered, one click out -------
	function buildBanner() {
		if (locked || view !== 'only') { return; }
		var stream = document.getElementById('stream');
		if (!stream || !stream.parentNode) { return; }
		if (document.getElementById('hc-banner')) { return; }

		var bar = document.createElement('div');
		bar.id = 'hc-banner';
		bar.className = 'hc-banner';
		bar.innerHTML = '<span>Showing only your hidden categories \u2014 '
			+ cats.length + ' hidden.</span>';

		var off = document.createElement('button');
		off.type = 'button';
		off.textContent = 'Hide';
		off.addEventListener('click', function () {
			post({ act: 'view', to: 'hidden' });
		});
		bar.appendChild(off);
		stream.parentNode.insertBefore(bar, stream);
	}


	// ---- 7b. subscription management: a Hide button per category box -----
	//
	// subscription/index.phtml renders one div.box per category, and its
	// ul.box-content carries data-cat-id — a stable hook, so the button is a
	// plain injection with no template patch. If that markup ever changes the
	// button simply does not appear; the settings page still hides things.
	function buildSubscriptionButtons() {
		if (!/c=subscription/.test(window.location.search)) { return; }
		var boxes = document.querySelectorAll('div.box');
		for (var n = 0; n < boxes.length; n++) {
			var box = boxes[n];
			if (box.getAttribute('data-hc-done') === '1') { continue; }
			var list = box.querySelector('ul.box-content[data-cat-id]');
			var title = box.querySelector('.box-title');
			if (!list || !title) { continue; }
			var id = parseInt(list.getAttribute('data-cat-id'), 10);
			if (!id) { continue; }
			box.setAttribute('data-hc-done', '1');

			var hid = !!isHidden[id];
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'btn hc-sub-btn' + (hid ? ' hc-sub-on' : '');
			btn.textContent = hid ? 'Show' : 'Hide';
			btn.title = hid
				? 'Unhide this category (it goes back to normal)'
				: 'Hide this category from the sidebar and the stream';
			btn.addEventListener('click', function (ev) {
				ev.preventDefault();
				ev.stopPropagation();
				post({ act: this.hcWasHidden ? 'unhide' : 'hide', cat: this.hcCatId });
			});
			btn.hcCatId = id;
			btn.hcWasHidden = hid;
			title.appendChild(btn);
		}
	}

	// ---- 8. right-click a category -----------------------------------------
	//
	// This item is CONTRIBUTED TO RightClickActions, not provided by us. With
	// RCA installed its context menu is the one that opens; we append our item
	// to it. Without RCA there is no right-click menu on the page at all, so
	// there is nothing to append to and nothing is missing — every other way of
	// hiding (sidebar button, subscription box, settings list) is unaffected.
	//
	// Deliberately NO fallback menu: growing our own would duplicate RCA's UI,
	// drift from its look, and be one more surface to break. If #frss-ctx-menu
	// is absent when the event fires, we do nothing.
	function rcaMenu() {
		return document.getElementById('frss-ctx-menu');
	}

	function catIdFromRow(el) {
		var row = el && el.closest ? el.closest('li.tree-folder.category') : null;
		if (!row || !row.id || row.id.indexOf('c_') !== 0) { return 0; }
		var id = parseInt(row.id.slice(2), 10);
		return id > 0 ? id : 0;
	}

	function attachRightClick() {
		// capture phase: RCA builds its menu synchronously in its own handler,
		// so by the time ours runs (on the same event, after theirs) the menu
		// exists and we can append into it.
		document.addEventListener('contextmenu', function (ev) {
			var row = ev.target && ev.target.closest ? ev.target.closest('.tree-folder-title') : null;
			if (!row) { return; }
			var id = catIdFromRow(row);
			if (!id) { return; }

			// RCA builds its menu in the same tick; give it one microtask so our
			// append cannot land on a menu it is about to replace.
			window.setTimeout(function () {
				var menu = rcaMenu();
				if (!menu || menu.querySelector('.hc-ctx-item')) { return; }

				var hid = !!isHidden[id];
				var info = meta[id] || {};

				var sep = document.createElement('div');
				sep.className = 'hc-ctx-sep';
				sep.style.cssText = 'height:1px;margin:4px 0;background:rgba(128,128,128,.3)';

				var item = document.createElement('div');
				item.className = 'hc-ctx-item';
				item.setAttribute('role', 'menuitem');
				item.style.cssText = 'padding:6px 14px;cursor:pointer;display:flex;gap:8px;align-items:center';
				item.innerHTML = '<span aria-hidden="true">' + (hid ? '\u25CB' : '\u25CF') + '</span>'
					+ '<span>' + (hid ? 'Unhide category' : 'Hide category') + '</span>'
					+ (info.feeds != null ? '<span style="opacity:.5;font-size:.85em;margin-left:auto">'
						+ info.feeds + '</span>' : '');
				item.addEventListener('mouseenter', function () {
					item.style.background = 'rgba(128,128,128,.2)';
				});
				item.addEventListener('mouseleave', function () {
					item.style.background = '';
				});
				item.addEventListener('click', function (ev2) {
					ev2.preventDefault();
					ev2.stopPropagation();
					if (menu && menu.parentNode) { menu.style.display = 'none'; }
					post({ act: hid ? 'unhide' : 'hide', cat: id });
				});

				menu.appendChild(sep);
				menu.appendChild(item);
			}, 0);
		}, true);
	}

	// ---- 9. keep markers correct as the page changes ----------------------
	//
	// Infinite scroll and "load more" append new .flux nodes. One observer that
	// only marks newly added items is cheaper than a polling loop and cannot
	// miss the ones the user actually scrolls to.
	function watch() {
		if (typeof MutationObserver !== 'function') { return; }
		var target = document.getElementById('stream');
		if (!target) { return; }
		var pending = false;
		var obs = new MutationObserver(function () {
			if (pending) { return; }
			pending = true;
			requestAnimationFrame(function () {
				pending = false;
				markStream();
			});
		});
		obs.observe(target, { childList: true, subtree: true });
	}

	function boot() {
		markAll();
		buildButton();
		buildHiddenSection();
		buildBanner();
		buildSubscriptionButtons();
		attachRightClick();
		watch();
	}

	if (document.readyState === 'loading') {
		// attributes are already set above, before first paint; the DOM work
		// waits for the tree to exist
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
	}   // end start(HC)
}());
