// BuddyNext control-contrast crawler (WCAG 1.4.11 non-text 3:1, 1.4.3 text 4.5:1).
// Dev tool: bin/ is outside the release allowlist (bin/build-release.sh RUNTIME), so it never ships.
//
// Scope: every input, select, textarea, button, summary, [role=button|tab|switch|menuitem|checkbox|radio|option]
// and every <a> whose class names a control (btn, button, link, cta, chip, tab, toggle, pill), inside
// .bn-app, the admin wrappers (#wpbody-content on BuddyNext pages), any block or shortcode output
// (wp-block-buddynext*, bn-*), and the dialogs and popovers the ACTIONS below open. States: default,
// hover, focus-visible, active. Front: light + dark (bn_theme and the host theme's bx-color-mode in localStorage),
// 1280 + 390. Admin: light, 1280 + 390.
//
// Run through the Playwright MCP, logged in as an admin, on a site seeded with
// `wp buddynext qa-fixtures seed --profile=all` (the member qa_fx_buyer and the space
// qa-fx-succession with two members, and the block and shortcode pages /qa-fx-block-page/ and
// /qa-fx-shortcode-page/ come from it):
//   browser_run_code_unsafe  filename = bin/control-contrast-crawler.js   (installs page.context().bncCrawl)
//   async (page) => page.context().bncCrawl(page, { set: 'front', base: 'http://localhost:8912' })
//   async (page) => page.context().bncCrawl(page, { set: 'admin', base: 'http://localhost:8912' })
// A path "url#key" runs ACTIONS[key] (clicks, in order) before measuring; an opener that is not
// on the page is listed under openerMissing, never silently skipped. EXEMPT controls are counted
// apart from violations (owner decision: the composer prompt stays borderless in 1.3.0).
// A one-sided rule under a control that is not a button, [role=button] or field (list-row separator,
// tab underline) is a divider, not a boundary, and is judged by its text. An icon-only button is judged
// by its icon (3:1 against its fill, "icon"); every one is listed under iconOnly with its lowest
// ratio. A button with neither text nor icon, a transparent border and a fill under 3:1 fails as
// "transparent-edge". A summary or [aria-expanded] control's chevron (svg, drawn
// ::before/::after, or visible ::marker) must reach 3:1; one with no indicator is listed under
// noIndicator. A page that does not answer 200 fails as http-<status>. Sets: front and admin run
// as the admin; guest runs logged out; onboarding runs as qa_fx_onboarding (the edge-seed member
// saved mid-onboarding) on each wizard step. Not covered: the member "Delete account" prompt.

async (page) => {
const FRONT = [
  '/activity/', '/activity/#poll', '/activity/#privacy', '/activity/#schedule', '/activity/#emoji',
  '/activity/#reaction', '/activity/#share', '/activity/#postmenu', '/activity/#report',
  '/members/', '/members/#cardmenu', '/members/#block', '/members/#report', '/members/admin/',
  '/members/qa_fx_buyer/', '/members/qa_fx_buyer/#more', '/spaces/', '/spaces/open-discussion/',
  '/spaces/open-discussion/#notif', '/spaces/qa-fx-succession/members/', '/spaces/qa-fx-succession/members/#spacemenu',
  '/messages/', '/notifications/', '/settings/', '/settings/notifications/', '/settings/privacy/',
  '/settings/account/', '/settings/membership/', '/search/?q=test', '/explore/',
  '/membership-plans/', '/qa-fx-block-page/', '/qa-fx-shortcode-page/',
  '/members/admin/edit/', '/spaces/open-discussion/settings/',
];

// Logged out: what a visitor sees first (guest banner, directories, the auth hub).
const GUEST = ['/', '/activity/', '/members/', '/spaces/', '/explore/', '/search/?q=test', '/login/', '/login/signup/'];

// A fixture member who has not finished onboarding, on each wizard step.
// ?redo=1&_step=N opens a step directly, so a run never depends on saved progress.
const ONBOARDING = [1, 2, 3, 4, 5].map((n) => '/onboarding/?redo=1&_step=' + n);

const ADMIN = [
  'buddynext&tab=home', 'buddynext&tab=license', 'buddynext-members&tab=directory', 'buddynext-spaces&tab=directory',
  'buddynext-moderation&tab=pending', 'buddynext-automation&tab=bulk', 'buddynext-notifications&tab=notifications', 'buddynext-monetization&tab=tiers',
  'buddynext-integration-settings&tab=integration-controls', 'buddynext-settings&tab=general', 'buddynext-platform&tab=integrations',
  'buddynext-setup', 'buddynextpro-broadcasts', 'buddynextpro-mod-rules', 'buddynextpro-member-labels', 'buddynextpro-analytics',
  'buddynextpro-whitelabel', 'buddynext-platform&tab=tools', 'buddynext-platform&tab=tools#removedata',
  'bnpro-subscriptions', 'bnpro-paywall-settings', 'bnpro-coupons',
].map((p) => '/wp-admin/admin.php?page=' + p);

const click = (s) => `[data-wp-on--click="actions.${s}"]`;
const ACTIONS = {
  poll: [click('togglePoll')],
  privacy: ['button.bn-composer__privacy-chip'],
  schedule: [click('toggleSchedule')],
  emoji: ['.bn-composer .bn-emoji-trigger, .bn-emoji-trigger'],
  reaction: [click('toggleReactionPicker')],
  share: [click('openShare')],
  postmenu: [click('toggleOptionsMenu')],
  report: [click('toggleOptionsMenu'), click('reportPost')],
  cardmenu: [click('toggleCardMenu')],
  block: [click('toggleCardMenu'), click('openBlock')],
  more: [click('toggleMoreMenu')],
  notif: ['[data-bn-notif-trigger]'],
  spacemenu: [click('toggleMenu')],
  removedata: [{ check: '[name=delete_data]' }, '[data-bn-confirm-when]'],
};
// Who each set runs as: the admin session, a visitor, or a member mid-onboarding.
const AS = { front: null, admin: null, guest: 'guest', onboarding: 'qa_fx_onboarding' };
// Report on a member card is the second menu item of the same menu as Block.
ACTIONS['members-report'] = [click('toggleCardMenu'), click('openReport')];
// Borderless by design, each with its reason:
// - the composer prompt: owner decision, stays borderless in 1.3.0.
// - the profile-edit "Who can see this field" select: a compact ghost select whose
//   chevron measures 5.5:1 in light and dark (>= 3:1) and whose value text is 9:1,
//   with a visible field label; it gains the control edge on hover and focus.
const EXEMPT = ['textarea.bn-composer__prompt', 'select.bn-ep-field-vis__select'];

// Runs inside the page. Returns one record per tagged control.
const IN_PAGE = (args) => {
  const { scope, tag } = args;
  const cv = document.createElement('canvas');
  cv.width = cv.height = 1;
  const cx = cv.getContext('2d', { willReadFrequently: true });
  const over = (base, color, alpha = 1) => {
    cx.globalAlpha = 1;
    cx.fillStyle = `rgb(${base[0]},${base[1]},${base[2]})`;
    cx.fillRect(0, 0, 1, 1);
    cx.fillStyle = 'rgba(0,0,0,0)';
    cx.fillStyle = color;
    cx.globalAlpha = alpha;
    cx.fillRect(0, 0, 1, 1);
    cx.globalAlpha = 1;
    const d = cx.getImageData(0, 0, 1, 1).data;
    return [d[0], d[1], d[2]];
  };
  const alphaOf = (color) => {
    cx.clearRect(0, 0, 1, 1);
    cx.fillStyle = 'rgba(0,0,0,0)';
    cx.fillStyle = color;
    cx.fillRect(0, 0, 1, 1);
    return cx.getImageData(0, 0, 1, 1).data[3] / 255;
  };
  const lum = (c) => {
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
  };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return Math.round(((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)) * 100) / 100; };
  const hex = (c) => '#' + c.map((v) => v.toString(16).padStart(2, '0')).join('');
  const opacityOf = (el) => { let o = 1; for (let n = el; n && n.nodeType === 1; n = n.parentElement) o *= parseFloat(getComputedStyle(n).opacity); return o; };
  // Background stack from <html> down to (and optionally including) el.
  const bgUnder = (el, includeSelf) => {
    const chain = [];
    for (let n = includeSelf ? el : el.parentElement; n && n.nodeType === 1; n = n.parentElement) chain.unshift(n);
    let c = [255, 255, 255];
    for (const n of chain) {
      const bg = getComputedStyle(n).backgroundColor;
      if (alphaOf(bg) > 0) c = over(c, bg, n === el ? 1 : 1);
    }
    return c;
  };
  const sel = (el) => {
    const cls = [...el.classList].filter((c) => !c.startsWith('data-')).slice(0, 4).map((c) => '.' + c).join('');
    const p = el.parentElement;
    const pc = p ? (p.id ? '#' + p.id : [...p.classList].slice(0, 2).map((c) => '.' + c).join('')) : '';
    const t = el.tagName.toLowerCase() + (el.type && el.tagName === 'INPUT' ? `[type=${el.type}]` : '') + (el.id ? '#' + el.id : '') + cls;
    return (pc ? pc + ' > ' : '') + t;
  };
  const label = (el) => (el.getAttribute('aria-label') || el.innerText || el.value || el.placeholder || '').trim().replace(/\s+/g, ' ').slice(0, 40);

  if (tag) {
    document.querySelectorAll('[data-bnc]').forEach((n) => n.removeAttribute('data-bnc'));
    const Q = 'input:not([type=hidden]), select, textarea, button, summary, .button, [role=button], [role=tab], [role=switch], [role=menuitem], [role=menuitemradio], [role=menuitemcheckbox], [role=checkbox], [role=radio], [role=option], '
      + ['btn', 'button', 'link', 'cta', 'chip', 'tab', 'toggle', 'pill'].map((k) => `a[class*="${k}"]`).join(', ');
    const inScope = (el) => {
      if (scope === 'admin') {
        const w = el.closest('#wpbody-content');
        if (!w || el.closest('#screen-meta, #screen-meta-links')) return false; // WP core screen chrome
        const notice = el.closest('.notice, .updated, .error, .update-nag');
        return !notice || /bn-|buddynext/.test(notice.className);
      }
      if (el.closest('.bn-app, [class*="bn-admin"], [class*="wp-block-buddynext"]')) return true;
      for (let n = el; n && n !== document.body; n = n.parentElement) {
        if ([...n.classList].some((c) => c.startsWith('bn-'))) return true; // shortcodes, dialogs, popovers
      }
      return false;
    };
    let i = 0;
    for (const el of document.querySelectorAll(Q)) {
      if (!inScope(el)) continue;
      if (!el.checkVisibility({ opacityProperty: true, visibilityProperty: true })) continue;
      const r = el.getBoundingClientRect();
      if (r.width < 4 || r.height < 4) continue;
      const cs = getComputedStyle(el);
      if (el.tagName === 'INPUT' && /^(checkbox|radio)$/.test(el.type) && cs.appearance !== 'none') continue; // UA-drawn
      if (el.tagName === 'INPUT' && /^(range|color|file|image)$/.test(el.type)) continue;
      // Visually hidden pattern (clip / 1px).
      if (cs.clip === 'rect(0px, 0px, 0px, 0px)' || cs.clipPath === 'inset(50%)') continue;
      el.setAttribute('data-bnc', String(i++));
    }
    return i;
  }

  const out = [];
  for (const el of document.querySelectorAll('[data-bnc]')) {
    const cs = getComputedStyle(el);
    const op = opacityOf(el);
    const surf = bgUnder(el, false);
    const isField = /^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName) && !/^(button|submit|reset)$/.test(el.type || '');
    const fillA = alphaOf(cs.backgroundColor);
    const fill = fillA > 0 ? over(surf, cs.backgroundColor, op) : surf;
    const fillR = ratio(fill, surf);
    const parts = [];
    if (fillA > 0) parts.push({ k: 'fill', c: cs.backgroundColor, r: fillR });
    for (const s of ['top', 'right', 'bottom', 'left']) {
      const w = parseFloat(cs[`border-${s}-width`] || cs.getPropertyValue(`border-${s}-width`));
      const st = cs.getPropertyValue(`border-${s}-style`);
      const col = cs.getPropertyValue(`border-${s}-color`);
      if (w > 0 && st !== 'none' && st !== 'hidden' && alphaOf(col) > 0) {
        parts.push({ k: 'border-' + s, c: col, r: ratio(over(surf, col, op * alphaOf(col) / alphaOf(col)), surf), w });
      }
    }
    const bs = cs.boxShadow;
    if (bs && bs !== 'none') {
      // Split on commas that are not inside parentheses.
      const shadows = bs.split(/,(?![^(]*\))/);
      for (const sh of shadows) {
        const m = sh.match(/(rgba?\([^)]*\)|color\([^)]*\)|oklab\([^)]*\)|oklch\([^)]*\)|#[0-9a-f]+)/i);
        if (!m) continue;
        const nums = sh.replace(m[0], '').replace('inset', '').trim().split(/\s+/).map(parseFloat);
        const [x = 0, y = 0, blur = 0, spread = 0] = nums;
        if (spread > 0 || ((x || y) && blur === 0)) {
          const inset = /inset/.test(sh);
          const base = inset ? fill : surf;
          parts.push({ k: inset ? 'inset-shadow' : 'shadow-ring', c: m[0], r: ratio(over(base, m[0], op), inset ? fill : surf) });
        }
      }
    }
    const ow = parseFloat(cs.outlineWidth);
    if (ow > 0 && cs.outlineStyle !== 'none' && alphaOf(cs.outlineColor) > 0) {
      parts.push({ k: 'outline', c: cs.outlineColor, r: ratio(over(surf, cs.outlineColor, op), surf) });
    }
    // A field that draws nothing itself may sit in a wrapper that draws the
    // box (search pills). Use the nearest of two ancestors that has a border
    // and hugs the field.
    if (isField && parts.length === 0) {
      const fr = el.getBoundingClientRect();
      for (let a = el.parentElement, d = 0; a && d < 2; a = a.parentElement, d++) {
        const acs = getComputedStyle(a);
        const ar = a.getBoundingClientRect();
        if (ar.height > fr.height * 2 + 24) break;
        const asurf = bgUnder(a, false);
        for (const s of ['top', 'right', 'bottom', 'left']) {
          const w = parseFloat(acs.getPropertyValue(`border-${s}-width`));
          const col = acs.getPropertyValue(`border-${s}-color`);
          if (w > 0 && !/none|hidden/.test(acs.getPropertyValue(`border-${s}-style`)) && alphaOf(col) > 0) parts.push({ k: 'wrap-border-' + s, c: col, r: ratio(over(asurf, col, op), asurf) });
        }
        if (parts.length) break;
      }
    }
    const best = parts.reduce((m, p) => Math.max(m, p.r), 0);
    const drawsEdge = parts.some((p) => p.k !== 'fill');
    // A rule on ONE side only (a list-row separator, a tab underline) divides rows; it is
    // not the control's boundary. Such a control is identified by its text, like a link.
    // Never for a button or field: a lone bottom border there IS its only edge.
    const buttonLike = el.tagName === 'BUTTON' || el.getAttribute('role') === 'button' || el.tagName === 'INPUT';
    const sides = parts.filter((p) => p.k.startsWith('border-'));
    const dividerOnly = !isField && !buttonLike && sides.length === 1 && parts.every((p) => p.k.startsWith('border-') || (p.k === 'fill' && p.r < 1.1));
    // An icon-only button (no text of its own) whose border slot is transparent and whose
    // fill does not reach 3:1 has no visible shape: its icon alone must carry 1.4.11.
    const hiddenEdge = ['top', 'right', 'bottom', 'left'].some((s) => parseFloat(cs.getPropertyValue(`border-${s}-width`)) > 0 && alphaOf(cs.getPropertyValue(`border-${s}-color`)) === 0);
    let nonText = 'pass';
    if (dividerOnly && sides[0].r < 3) nonText = 'divider';
    else if (parts.length === 0) nonText = isField ? 'fail-no-boundary' : 'text-only';
    else if (best < 3) nonText = (drawsEdge || isField) ? 'fail' : 'fill-only';

    // Disclosure indicator (summary, [aria-expanded]): the chevron is a graphical object
    // (3:1 against the control's fill). Read a child svg, a drawn ::before/::after, or a
    // visible ::marker. None found is reported, not failed.
    let indicator = null;
    if (el.tagName === 'SUMMARY' || el.hasAttribute('aria-expanded')) {
      const cands = [];
      const svg = el.querySelector('svg');
      if (svg && svg.checkVisibility()) { const scs = getComputedStyle(svg); cands.push(scs.stroke && scs.stroke !== 'none' && !/url/.test(scs.stroke) ? scs.stroke : scs.color); }
      for (const pe of ['::before', '::after', '::marker']) {
        const pcs = getComputedStyle(el, pe);
        if (pe === '::marker' ? (el.tagName !== 'SUMMARY' || cs.display !== 'list-item' || pcs.content === 'none') : (!pcs.content || pcs.content === 'none' || pcs.display === 'none')) continue;
        const bw = ['top', 'right', 'bottom', 'left'].find((s) => parseFloat(pcs.getPropertyValue(`border-${s}-width`)) > 0 && alphaOf(pcs.getPropertyValue(`border-${s}-color`)) > 0);
        cands.push(bw ? pcs.getPropertyValue(`border-${bw}-color`) : (alphaOf(pcs.backgroundColor) > 0 && pcs.content === '""' ? pcs.backgroundColor : pcs.color));
      }
      indicator = cands.length ? cands.map((c) => ratio(over(fill, c, op), fill)).reduce((m, r) => Math.max(m, r), 0) : 'none';
      if (typeof indicator === 'number' && indicator < 3 && nonText === 'pass') nonText = 'fail-indicator';
    }

    // Text runs.
    const texts = [];
    if (!isField) {
      const seen = new Set();
      const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
      let n;
      while ((n = walker.nextNode())) {
        if (!n.textContent.trim()) continue;
        const pe = n.parentElement;
        if (!pe || seen.has(pe)) continue;
        if (!pe.checkVisibility({ opacityProperty: true, visibilityProperty: true })) continue;
        const pr = pe.getBoundingClientRect();
        const pcsc = getComputedStyle(pe);
        if (pr.width <= 2 || pr.height <= 2 || pcsc.clip === 'rect(0px, 0px, 0px, 0px)' || pcsc.clipPath === 'inset(50%)') continue; // screen-reader-only text
        seen.add(pe);
        const pcs = getComputedStyle(pe);
        const bg = bgUnder(pe, true);
        const fg = over(bg, pcs.color, opacityOf(pe));
        const size = parseFloat(pcs.fontSize);
        const bold = parseInt(pcs.fontWeight, 10) >= 700;
        const large = size >= 24 || (bold && size >= 18.66);
        texts.push({ r: ratio(fg, bg), need: large ? 3 : 4.5, fg: hex(fg), bg: hex(bg), text: n.textContent.trim().slice(0, 30) });
      }
      if (el.tagName === 'INPUT' && el.value) {
        const fg = over(fill, cs.color, op);
        texts.push({ r: ratio(fg, fill), need: 4.5, fg: hex(fg), bg: hex(fill), text: el.value.slice(0, 30) });
      }
    }
    // Icon-only button (no visible text): WCAG 1.4.11 treats the icon as the graphical object,
    // so it must reach 3:1 against the fill; its ratio is reported for every such button. With no
    // icon and no visible edge (transparent border, fill under 3:1), nothing shows the control.
    let icon = null;
    if (buttonLike && !isField && !texts.length && !(el.value || '').trim()) {
      const svg = el.querySelector('svg');
      if (svg && svg.checkVisibility()) {
        const scs = getComputedStyle(svg);
        const c = scs.stroke && scs.stroke !== 'none' && !/url/.test(scs.stroke) ? scs.stroke : (scs.fill && scs.fill !== 'none' && !/url/.test(scs.fill) ? scs.fill : scs.color);
        icon = ratio(over(fill, c, op * parseFloat(scs.opacity || 1)), fill);
      }
      if (nonText !== 'fail-indicator') {
        if (icon !== null && icon < 3) nonText = 'fail-icon';
        else if (icon === null && hiddenEdge && best < 3) nonText = 'fail-transparent-edge';
      }
    }
    const worstText = texts.reduce((m, t) => (!m || t.r / t.need < m.r / m.need ? t : m), null);
    out.push({
      i: +el.getAttribute('data-bnc'),
      exempt: args.exempt.some((s) => el.matches(s)),
      sel: sel(el),
      label: label(el),
      disabled: el.disabled === true || el.getAttribute('aria-disabled') === 'true' || el.classList.contains('disabled'),
      surf: hex(surf),
      fill: hex(fill),
      parts: parts.map((p) => `${p.k}:${p.r}`).join(' '),
      best,
      nonText,
      indicator,
      icon,
      text: worstText,
      textFail: !!(worstText && worstText.r < worstText.need),
    });
  }
  return out;
};

const bncCrawl = async (page, opts = {}) => {
  const BASE = opts.base || 'http://localhost:8912';
  const set = opts.set || 'front';
  const urls = opts.urls || ({ admin: ADMIN, guest: GUEST, onboarding: ONBOARDING }[set] || FRONT);
  const modes = opts.modes || (set === 'admin' ? ['light'] : ['light', 'dark']);
  const as = AS[set] || null;
  // Switch session: a visitor has no cookies; a member logs in through ?autologin.
  if (as) {
    await page.context().clearCookies();
    if (as !== 'guest') await page.goto(BASE + '/?autologin=' + as, { waitUntil: 'load' });
  }
  const widths = opts.widths || [1280, 390];
  const states = opts.states || ['default', 'hover', 'focus-visible', 'active'];
  const cdp = await page.context().newCDPSession(page);
  const violations = [];
  const disabledInfo = [];
  const fillOnly = [];
  const noIndicator = new Set();
  const iconOnly = {};
  const exempt = new Set();
  const openerMissing = [];
  const pages = [];
  let controls = 0;
  for (const width of widths) {
    await page.setViewportSize({ width, height: width > 800 ? 900 : 844 });
    for (const path of urls) {
      for (const mode of modes) {
        let resp;
        const [url, act] = path.split('#');
        try {
          // BuddyNext's own switch, and the host's (Reign / BuddyX store bx-color-mode; an explicit
          // host mode wins over bn_theme), so dark is dark on every theme.
          const setMode = (m) => { try { localStorage.setItem('bn_theme', m); localStorage.setItem('bx-color-mode', m); } catch (e) {} };
          if (!page.url().startsWith(BASE)) await page.goto(BASE + '/');
          await page.evaluate(setMode, mode);
          resp = await page.goto(BASE + url, { waitUntil: 'load', timeout: 30000 });
        } catch (e) { pages.push({ path, mode, width, error: String(e).slice(0, 80) }); continue; }
        // A 403/404/500 measures the error screen, not the surface: fail it.
        if (resp && resp.status() !== 200) { pages.push({ path, mode, width, status: resp.status() }); violations.push({ path, mode, width, state: 'load', sel: path, label: 'HTTP ' + resp.status(), kind: 'http-' + resp.status(), rule: [] }); continue; }
        if (act) {
          const key = ACTIONS[url.replace(/\/$/, '').split('/').pop() + '-' + act] ? url.replace(/\/$/, '').split('/').pop() + '-' + act : act;
          let ok = !!ACTIONS[key];
          for (const step of ACTIONS[key] || []) {
            try {
              const loc = page.locator(typeof step === 'string' ? step : step.check).filter({ visible: true }).first();
              if (typeof step === 'string') await loc.click({ timeout: 5000 }); else await loc.check({ timeout: 5000 });
              await page.waitForTimeout(250);
            } catch (e) { ok = false; break; }
          }
          if (!ok) { openerMissing.push(`${path} ${mode} ${width}`); continue; }
        }
        // A route may redirect client-side after load (/search/ -> /activity/search/): settle once.
        const prep = async () => {
          await page.addStyleTag({ content: '*,*::before,*::after{transition:none!important;animation:none!important;caret-color:transparent!important}' });
          await page.evaluate(() => { document.querySelectorAll('details').forEach((d) => { d.open = true; }); });
          await page.waitForTimeout(150);
          return page.evaluate(IN_PAGE, { scope: set, tag: true, exempt: EXEMPT });
        };
        let n;
        try { n = await prep(); } catch (e) { await page.waitForLoadState('load'); await page.waitForTimeout(500); n = await prep(); }
        const finalUrl = page.url();
        controls += n;
        pages.push({ path, final: finalUrl.replace(BASE, ''), status: resp && resp.status(), mode, width, controls: n });
        if (!n) continue;
        const sheets = {};
        const onSheet = (e) => { sheets[e.header.styleSheetId] = e.header.sourceURL || '(inline)'; };
        cdp.on('CSS.styleSheetAdded', onSheet);
        await cdp.send('DOM.enable');
        await cdp.send('CSS.enable');
        const { root } = await cdp.send('DOM.getDocument', { depth: -1 });
        const { nodeIds } = await cdp.send('DOM.querySelectorAll', { nodeId: root.nodeId, selector: '[data-bnc]' });
        // getDocument re-sends styleSheetAdded only on CSS.enable; give it a tick.
        await page.waitForTimeout(50);
        for (const state of states) {
          if (state !== 'default') await Promise.all(nodeIds.map((nodeId) => cdp.send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: [state] }).catch(() => {})));
          let recs = [];
          try { recs = await page.evaluate(IN_PAGE, { scope: set, tag: false, exempt: EXEMPT }); } catch (e) { pages.push({ path, mode, width, state, error: String(e).slice(0, 80) }); }
          for (const r of recs) {
            if (r.disabled) {
              if (state === 'default') disabledInfo.push({ path, mode, width, sel: r.sel, label: r.label, nonText: r.nonText, best: r.best, text: r.text && r.text.r });
              continue;
            }
            if (r.exempt) { exempt.add(r.sel); continue; }
            if (r.nonText === 'fill-only') fillOnly.push({ sel: r.sel, label: r.label, state, mode, width, best: r.best, path });
            const bad = [];
            if (/^fail/.test(r.nonText)) bad.push(r.nonText === 'fail' || r.nonText === 'fail-no-boundary' ? 'nonText' : r.nonText.replace('fail-', ''));
            if (state === 'default' && r.indicator === 'none') noIndicator.add(`${r.sel} "${r.label}"`);
            if (r.icon !== null) { const k = r.sel; iconOnly[k] = Math.min(iconOnly[k] ?? 99, r.icon); }
            if (r.textFail) bad.push('text');
            if (!bad.length) continue;
            const v = { path, mode, width, state, sel: r.sel, label: r.label, kind: bad.join('+'), icon: r.icon, surf: r.surf, fill: r.fill, parts: r.parts, best: r.best, text: r.text, rule: [] };
            // Winning rule for the failing property.
            try {
              const ms = await cdp.send('CSS.getMatchedStylesForNode', { nodeId: nodeIds[r.i] });
              const want = bad.every((k) => k === 'text')
                ? ['color', 'background-color', 'background']
                : ['border-color', 'border', 'border-top-color', 'border-top', 'border-inline-start-color', 'border-width', 'border-style', 'background-color', 'background', 'box-shadow'];
              const hits = [];
              for (const m of ms.matchedCSSRules || []) {
                const props = (m.rule.style.cssProperties || []).filter((p) => want.includes(p.name) && p.value && !p.disabled && p.implicit !== true);
                if (!props.length || m.rule.origin !== 'regular') continue;
                hits.push({ sheet: (sheets[m.rule.styleSheetId] || m.rule.styleSheetId).replace(BASE, '').replace(/\?.*$/, ''), line: m.rule.style.range ? m.rule.style.range.startLine + 1 : null, selector: m.rule.selectorList.text.slice(0, 120), decl: [...new Set(props.map((p) => `${p.name}: ${p.value.replace(/\s*!important/, '')}${p.important ? ' !important' : ''}`))].join('; ').slice(0, 200), important: props.some((p) => p.important) });
              }
              // Cascade order: last wins, !important beats normal.
              const imp = hits.filter((h) => h.important);
              v.rule = (imp.length ? imp : hits).slice(-2);
            } catch (e) { v.rule = [{ error: String(e).slice(0, 60) }]; }
            violations.push(v);
          }
          if (state !== 'default') await Promise.all(nodeIds.map((nodeId) => cdp.send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: [] }).catch(() => {})));
        }
        cdp.off('CSS.styleSheetAdded', onSheet);
        await cdp.send('CSS.disable');
      }
    }
  }
  await cdp.detach();
  // Back to the admin session for the next run.
  if (as) { await page.context().clearCookies(); await page.goto(BASE + '/?autologin=1', { waitUntil: 'load' }); }
  // Collapse identical (selector, kind, state, mode) across pages/widths.
  const groups = {};
  for (const v of violations) {
    const key = [v.sel, v.kind, v.state, v.mode].join('|');
    if (!groups[key]) groups[key] = { ...v, pages: new Set(), widths: new Set() };
    groups[key].pages.add(v.path);
    groups[key].widths.add(v.width);
  }
  const table = Object.values(groups).map((g) => ({ sel: g.sel, label: g.label, kind: g.kind, icon: g.icon, state: g.state, mode: g.mode, widths: [...g.widths].join('/'), pages: [...g.pages].slice(0, 3).join(' ') + (g.pages.size > 3 ? ` (+${g.pages.size - 3})` : ''), surf: g.surf, fill: g.fill, parts: g.parts, text: g.text ? `${g.text.r} (${g.text.fg} on ${g.text.bg}) "${g.text.text}"` : '', rule: g.rule }));
  const fo = {};
  for (const f of fillOnly) { const k = f.sel + '|' + f.state + '|' + f.mode; if (!fo[k] || f.best < fo[k].best) fo[k] = f; }
  return { fillOnly: Object.values(fo).map((f) => `${f.state}/${f.mode} ${f.sel} "${f.label}" fill ${f.best}`), set, modes, widths, states, pagesLoaded: pages.length, controlsMeasured: controls, violationCount: violations.length, exempt: [...exempt], openerMissing, noIndicator: [...noIndicator], iconOnly: Object.entries(iconOnly).map(([k, r]) => `${k} icon ${r}`), uniqueViolations: table.length, table, disabled: opts.showDisabled ? disabledInfo : disabledInfo.length, pages: opts.showPages ? pages : undefined };
};

// Kept on the browser context so later calls can run it without re-sending this file.
page.context().bncCrawl = bncCrawl;
return 'bncCrawl installed';
}
