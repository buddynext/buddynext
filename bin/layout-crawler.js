// BuddyNext layout crawler: how a surface FITS, not whether it works or reads well.
// Dev tool: bin/ is outside the release allowlist (bin/build-release.sh RUNTIME), so it never ships.
//
// Companion to bin/control-contrast-crawler.js (colour). Same shape, same way to run, same routes.
//
// What it flags, per route x width x mode:
//   page-overflow      the page scrolls sideways (scrollWidth > clientWidth), with the elements that cause it
//   past-container     an element sticks out of the card, section or tile that holds it
//   table-clipped      a table inside a scroll box whose last column (Actions) is cut off or off the edge
//   inner-scroll       a box that scrolls sideways on its own (informational: a table in a scroller is by design)
//   tap-target         at 700px and under, a control smaller than 40px
//   clipped-text       a field, select, button or chip whose text is wider than its box and not ellipsised
//   orphan             a grid or wrapped row whose last line holds one lone item (3 + 1, 5 + 1)
//   button-height      buttons of one size on one row with different heights
//   squeezed-text      a column of text so narrow it wraps a word or two per line (the 77px toast)
//   broken-image       an <img> that failed to load
//   http-<status>      a route that did not answer 200 (the error screen is not measured)
//
// Run through the Playwright MCP, logged in as an admin, on a seeded site:
//   browser_run_code_unsafe  filename = bin/layout-crawler.js   (installs page.context().bncLayout)
//   async (page) => page.context().bncLayout(page, { set: 'front', base: 'http://buddynext.local' })
//   sets: front (BuddyNext layer, as the admin), integration (partner plugins inside BuddyNext: Jetonomy, MediaVerse,
//   Gamification, Eventonomy, Career Board, Listora, Learnomy, and one post page per partner card type), guest (logged
//   out), admin (wp-admin, BuddyNext + Pro pages)
//   opts: { urls: [...], widths: [1280, 390], modes: ['light','dark','rtl'], max: 6 }
//   mode rtl = light with dir="rtl": it shows where the layout uses physical left/right; the words stay English.
// Result: { pages, errors, byKind: { kind: { count, pages, examples: [{ sel, where, detail }] } }, perPage }.
// A finding is a question for a person with eyes, not a verdict: look at the example with a screenshot.
async (page) => {
// BuddyNext layer: the member surfaces BuddyNext itself owns.
const FRONT = [
  '/activity/', '/activity/explore/', '/activity/leaderboard/', '/members/', '/members/varundubey/',
  '/members/varundubey/connections/', '/members/varundubey/edit/', '/members/varundubey/media/', '/members/priya/',
  '/spaces/', '/spaces/mine/', '/spaces/design-critique/', '/spaces/design-critique/about/',
  '/spaces/design-critique/members/', '/spaces/design-critique/files/', '/spaces/trail-runners/', '/messages/', '/notifications/',
  '/me/bookmarks/', '/add-new-post/', '/settings/', '/settings/notifications/', '/settings/privacy/', '/settings/account/',
  '/settings/membership/', '/search/?q=test', '/membership-plans/',
];
// Integration layer: partner plugins drawn inside BuddyNext (their pages in BuddyNext chrome, their tabs on a profile and
// a Space, their cards in the feed). Ids and slugs come from the seeded site; edit them for another site.
const INTEGRATION = [
  // Jetonomy forum
  '/community/', '/community/s/photo-walks/', '/community/s/photo-walks/t/which-lens-for-night-street-photography-on-a-budget/',
  '/members/varundubey/discussions/', '/spaces/design-critique/discussions/',
  // WPMediaVerse
  '/explore-media/', '/collection/public-gallery/', '/spaces/design-critique/media/', '/members/varundubey/media/',
  // WB Gamification
  '/gamification/', '/activity/leaderboard/',
  // Eventonomy
  '/events/', '/event/tuesday-night-track-intervals/', '/members/varundubey/events/', '/spaces/design-critique/events/',
  // WP Career Board
  '/jobs/', '/jobs/product-designer-telehealth/', '/companies/lumen-health/', '/resume/fatima-zahra/', '/members/varundubey/portfolio/',
  // Listora and Learnomy
  '/listing/berg-ski-and-cycle-rentals/', '/members/varundubey/listings/', '/courses/',
  // One post page per partner card type (job, Open to Work, course, listing, badge, document, discussion, event, article)
  '/p/17598/', '/p/17601/', '/p/17781/', '/p/17674/', '/p/17779/', '/p/17610/', '/p/17746/', '/p/17590/', '/p/17771/',
];
const GUEST = ['/', '/activity/', '/members/', '/spaces/', '/explore/', '/search/?q=test', '/login/', '/login/signup/', '/membership-plans/', '/community/', '/jobs/', '/events/', '/explore-media/'];
const ADMIN = [
  'buddynext&tab=home', 'buddynext&tab=license', 'buddynext-members&tab=directory', 'buddynext-members&tab=profile-fields',
  'buddynext-members&tab=avatar-settings', 'buddynext-members&tab=member-types', 'buddynext-members&tab=invites',
  'buddynext-spaces&tab=directory', 'buddynext-moderation&tab=pending', 'buddynext-moderation&tab=reports',
  'buddynext-automation&tab=bulk', 'buddynext-notifications&tab=notifications', 'buddynext-monetization&tab=tiers',
  'buddynext-integration-settings&tab=integration-controls', 'buddynext-settings&tab=general', 'buddynext-platform&tab=integrations',
  'buddynext-platform&tab=tools', 'buddynext-setup', 'buddynextpro-broadcasts', 'buddynextpro-mod-rules',
  'buddynextpro-member-labels', 'buddynextpro-analytics', 'buddynextpro-whitelabel', 'bnpro-subscriptions',
  'bnpro-paywall-settings', 'bnpro-coupons',
].map((p) => '/wp-admin/admin.php?page=' + p);

const IN_PAGE = (opts) => {
  const out = [];
  const W = document.documentElement.clientWidth;
  const narrow = window.innerWidth <= 700;
  const push = (kind, el, detail) => out.push({ kind, sel: pathOf(el), where: whereOf(el), detail: detail || '' });
  function pathOf(e) {
    if (!e || !e.tagName) return '?';
    const cls = String(e.className && e.className.baseVal !== undefined ? e.className.baseVal : e.className || '').split(/\s+/).filter(Boolean).slice(0, 2).join('.');
    return e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (cls ? '.' + cls : '');
  }
  function whereOf(e) {
    const host = e.closest('.bn-post-card, .bn-companion-card, .bn-settings-section, .bn-card, .bn-stat-grid, .bn-members-toolbar, .bn-toast, .bn-modal__panel, [role="dialog"], nav, header, main, form');
    return host && host !== e ? pathOf(host) : '';
  }
  const SKIP = '#wpadminbar, #adminmenumain, #adminmenuwrap, #wpfooter, [hidden], [aria-hidden="true"], .screen-reader-text, .bn-sr-only, script, style, noscript, template, .bn-cookie-banner, .bn-toast-container, .bn-visually-hidden, .visually-hidden, .leaflet-container';
  const visible = (e) => {
    if (e.closest(SKIP)) return false;
    const b = e.getBoundingClientRect();
    if (b.width <= 0 || b.height <= 0) return false;
    const cs = getComputedStyle(e);
    return cs.visibility !== 'hidden' && cs.display !== 'none' && cs.opacity !== '0';
  };
  const clipsX = (cs) => /(auto|scroll|hidden|clip)/.test(cs.overflowX);
  const insideClip = (e, stop) => {
    for (let p = e.parentElement; p && p !== stop && p !== document.body; p = p.parentElement) {
      if (clipsX(getComputedStyle(p))) return true;
    }
    return false;
  };
  const all = Array.from(document.querySelectorAll('body *')).filter(visible);

  // page-overflow
  const de = document.documentElement;
  if (de.scrollWidth > W + 1) {
    const causes = all.filter((e) => {
      const b = e.getBoundingClientRect();
      return b.right > W + 1 && getComputedStyle(e).position !== 'fixed' && !insideClip(e, null);
    });
    const tops = causes.filter((e) => !causes.includes(e.parentElement)).slice(0, 3);
    push('page-overflow', de, de.scrollWidth + ' > ' + W + (tops.length ? ' by ' + tops.map(pathOf).join(', ') : ''));
  }

  // past-container
  const CARD = '.bn-post-card, .bn-companion-card, .bn-settings-section, .bn-card, .bn-stat, .bn-fam-header, .bn-modal__panel';
  const past = [];
  all.forEach((e) => {
    const c = e.parentElement && e.parentElement.closest(CARD);
    if (!c || c === e || getComputedStyle(e).position === 'absolute' || getComputedStyle(e).position === 'fixed') return;
    if (e.closest('[class*="menu"], [class*="popover"], [class*="tooltip"], [class*="dropdown"]')) return;
    const cb = c.getBoundingClientRect();
    const b = e.getBoundingClientRect();
    if (insideClip(e, c)) return;
    if (b.right > cb.right + 1.5 || b.left < cb.left - 1.5) past.push(e);
  });
  past.filter((e) => !past.includes(e.parentElement)).slice(0, 6).forEach((e) => {
    const c = e.parentElement.closest(CARD).getBoundingClientRect();
    const b = e.getBoundingClientRect();
    push('past-container', e, Math.round(Math.max(b.right - c.right, c.left - b.left)) + 'px');
  });

  // tables in a scroll box: is the last column reachable?
  document.querySelectorAll('table').forEach((t) => {
    if (!visible(t)) return;
    const wrap = t.parentElement && t.closest('.bn-ss-body, .bn-table-wrap__scroll, .bn-table-scroll, .bn-settings-section');
    if (!wrap) return;
    const wb = wrap.getBoundingClientRect();
    const tb = t.getBoundingClientRect();
    if (wrap.scrollWidth > wrap.clientWidth + 2 && /(auto|scroll)/.test(getComputedStyle(wrap).overflowX)) {
      const last = t.querySelector('tbody tr td:last-child, thead th:last-child');
      const lb = last ? last.getBoundingClientRect() : null;
      if (lb && lb.right > wb.right + 1 && !wrap.matches('.bn-table-wrap__scroll, .bn-table-scroll')) push('table-clipped', t, 'last column ends ' + Math.round(lb.right - wb.right) + 'px past the card (' + Math.round(tb.width) + ' in ' + Math.round(wb.width) + ')');
      else push('inner-scroll', t, Math.round(wrap.scrollWidth) + ' in ' + Math.round(wrap.clientWidth));
    }
  });

  // tap-target
  if (narrow) {
    const seen = {};
    all.filter((e) => e.matches('button, [role="button"], a.bn-btn, select, summary, input[type="checkbox"], input[type="radio"]')).forEach((e) => {
      const b = e.getBoundingClientRect();
      const box = e.matches('input[type="checkbox"], input[type="radio"]') && e.closest('label') ? e.closest('label').getBoundingClientRect() : b;
      if (Math.min(box.width, box.height) >= 40 - 0.5) return;
      // A switch can keep its look and grow only its touch area with an absolutely placed ::after.
      const after = getComputedStyle(e, '::after');
      if (after.content !== 'none' && after.position === 'absolute' && parseFloat(after.width) >= 40 - 0.5 && parseFloat(after.height) >= 40 - 0.5) return;
      const k = pathOf(e) + ' ' + Math.round(box.width) + 'x' + Math.round(box.height);
      if (seen[k]) { seen[k].n++; return; }
      seen[k] = { n: 1 };
      push('tap-target', e, Math.round(box.width) + 'x' + Math.round(box.height));
    });
    Object.keys(seen).forEach((k) => { const r = out.find((o) => o.kind === 'tap-target' && (o.sel + ' ' + o.detail) === k); if (r) r.detail += ' x' + seen[k].n; });
  }

  // clipped-text
  const cv = document.createElement('canvas').getContext('2d');
  const textW = (e, txt) => { const cs = getComputedStyle(e); cv.font = cs.fontStyle + ' ' + cs.fontWeight + ' ' + cs.fontSize + ' ' + cs.fontFamily; return cv.measureText(txt).width; };
  all.filter((e) => e.matches('input[placeholder]:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), select')).forEach((e) => {
    const cs = getComputedStyle(e);
    const inner = e.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight) - (e.tagName === 'SELECT' ? 22 : 0);
    // text-overflow: ellipsis is the sanctioned way for a field to be shorter than its text: it shows there is more.
    if (cs.textOverflow === 'ellipsis') return;
    if (e.tagName === 'SELECT') {
      const o = e.options[e.selectedIndex];
      if (o && textW(e, o.text) > inner + 1) push('clipped-text', e, '"' + o.text.slice(0, 30) + '" ' + Math.round(textW(e, o.text)) + ' > ' + Math.round(inner));
    } else if (!e.value && e.placeholder && textW(e, e.placeholder) > inner + 1) {
      push('clipped-text', e, 'placeholder "' + e.placeholder.slice(0, 30) + '" ' + Math.round(textW(e, e.placeholder)) + ' > ' + Math.round(inner));
    }
  });
  all.filter((e) => e.matches('button, a.bn-btn, [class*="chip"], [class*="badge"], [class*="pill"], th, label')).forEach((e) => {
    const cs = getComputedStyle(e);
    if (e.scrollWidth > e.clientWidth + 1 && clipsX(cs) && cs.textOverflow !== 'ellipsis' && !/auto|scroll/.test(cs.overflowX)) push('clipped-text', e, (e.textContent || '').trim().slice(0, 30) + ' ' + e.scrollWidth + ' > ' + e.clientWidth);
  });

  // orphan: the last line of a wrapped grid or flex row holds one lone item
  const orphaned = new Set();
  all.forEach((c) => {
    const cs = getComputedStyle(c);
    if (!/grid|flex/.test(cs.display) || (cs.display.indexOf('flex') > -1 && cs.flexWrap === 'nowrap')) return;
    const kids = Array.from(c.children).filter(visible).filter((k) => getComputedStyle(k).position !== 'absolute');
    if (kids.length < 4 || kids.length > 14) return;
    const cls = (k) => String(k.className || '').split(/\s+/)[0];
    if (!kids.every((k) => cls(k) === cls(kids[0]) && cls(k))) return;
    const rows = {};
    kids.forEach((k) => { const y = Math.round(k.getBoundingClientRect().top / 4); rows[y] = (rows[y] || 0) + 1; });
    const counts = Object.keys(rows).map(Number).sort((a, b) => a - b).map((y) => rows[y]);
    // 2 + 2 + 1 is what an odd count looks like in a two-column grid; 3 + 1 and 5 + 1 are the orphans, and so is a lone
    // last item that was stretched to the full row.
    const last = kids[kids.length - 1].getBoundingClientRect().width;
    const first = kids[0].getBoundingClientRect().width;
    // In a CSS grid a lone last cell is just an odd count (10 events in 3 columns); only a flex row's lone item, or a
    // stretched one, is an orphan.
    const isGrid = cs.display.indexOf('grid') > -1;
    if (counts.length >= 2 && counts[counts.length - 1] === 1 && (isGrid ? last > first * 1.5 : counts[0] >= 3) && !orphaned.has(c)) { orphaned.add(c); push('orphan', c, counts.join(' + ')); }
  });

  // button-height: same size, same row, different heights
  const groups = new Map();
  all.filter((e) => e.matches('button.bn-btn, a.bn-btn, button[class*="btn"]')).forEach((e) => {
    const p = e.parentElement;
    if (!groups.has(p)) groups.set(p, []);
    groups.get(p).push(e);
  });
  groups.forEach((btns, p) => {
    if (btns.length < 2) return;
    const by = {};
    btns.forEach((b) => { const r = b.getBoundingClientRect(); const k = (b.getAttribute('data-size') || 'md') + '@' + Math.round(r.top / 6); (by[k] = by[k] || []).push(Math.round(r.height)); });
    Object.keys(by).forEach((k) => { const hs = by[k]; if (hs.length > 1 && Math.max.apply(null, hs) - Math.min.apply(null, hs) > 3) push('button-height', p, hs.join('/') + 'px'); });
  });

  // squeezed-text: a narrow column that wraps a word or two per line
  all.filter((e) => e.children.length === 0 || e.matches('p, span, div')).forEach((e) => {
    const txt = (e.textContent || '').trim();
    if (txt.length < 24 || e.children.length > 2) return;
    const b = e.getBoundingClientRect();
    const lh = parseFloat(getComputedStyle(e).lineHeight) || 18;
    const lines = Math.round(b.height / lh);
    if (b.width < 130 && lines >= 4 && txt.length / lines < 9 && getComputedStyle(e).display !== 'inline') push('squeezed-text', e, Math.round(b.width) + 'px, ' + lines + ' lines');
  });

  // broken-image
  all.filter((e) => e.tagName === 'IMG').forEach((im) => { if (im.complete && im.naturalWidth === 0 && im.currentSrc) push('broken-image', im, im.currentSrc.slice(-40)); });
  return out;
};

const bncLayout = async (page, opts = {}) => {
  const BASE = opts.base || 'http://buddynext.local';
  const set = opts.set || 'front';
  const urls = opts.urls || ({ admin: ADMIN, guest: GUEST, integration: INTEGRATION }[set] || FRONT);
  const modes = opts.modes || (set === 'admin' ? ['light'] : ['light', 'dark', 'rtl']);
  const widths = opts.widths || (set === 'admin' ? [1280, 1024, 390] : [1280, 390]);
  const max = opts.max || 6;
  if (set === 'guest') await page.context().clearCookies();
  else await page.goto(BASE + '/?autologin=1', { waitUntil: 'load' });
  const byKind = {};
  const perPage = [];
  const errors = [];
  let pages = 0;
  for (const width of widths) {
    await page.setViewportSize({ width, height: width > 800 ? 900 : 844 });
    for (const path of urls) {
      for (const mode of modes) {
        const url = set === 'admin' || path.indexOf('autologin') > -1 ? path : path;
        let resp;
        try {
          const setMode = (m) => { try { localStorage.setItem('bn_theme', m === 'dark' ? 'dark' : 'light'); localStorage.setItem('bx-color-mode', m === 'dark' ? 'dark' : 'light'); } catch (e) {} };
          if (!page.url().startsWith(BASE)) await page.goto(BASE + '/');
          await page.evaluate(setMode, mode);
          resp = await page.goto(BASE + url, { waitUntil: 'load', timeout: 30000 });
          await page.waitForTimeout(900);
          if (mode === 'rtl') await page.evaluate(() => { document.documentElement.dir = 'rtl'; document.documentElement.lang = 'ar'; });
          await page.addStyleTag({ content: '*,*::before,*::after{transition:none!important;animation:none!important;caret-color:transparent!important}' });
          await page.evaluate(() => { document.querySelectorAll('details').forEach((d) => { d.open = true; }); });
          await page.waitForTimeout(300);
        } catch (e) { errors.push({ path, mode, width, error: String(e).slice(0, 80) }); continue; }
        pages++;
        const status = resp ? resp.status() : 0;
        const rec = { path, mode, width, status, kinds: {} };
        if (status !== 200) { rec.kinds['http-' + status] = 1; byKind['http-' + status] = byKind['http-' + status] || { count: 0, pages: new Set(), examples: [] }; byKind['http-' + status].count++; byKind['http-' + status].pages.add(path); perPage.push(rec); continue; }
        let found = [];
        try { found = await page.evaluate(IN_PAGE, {}); } catch (e) { errors.push({ path, mode, width, error: 'analyse ' + String(e).slice(0, 60) }); continue; }
        found.forEach((f) => {
          rec.kinds[f.kind] = (rec.kinds[f.kind] || 0) + 1;
          const k = (byKind[f.kind] = byKind[f.kind] || { count: 0, pages: new Set(), examples: [], bySel: {} });
          k.count++;
          k.pages.add(path);
          const key = f.sel;
          if (!k.bySel[key]) { k.bySel[key] = { sel: f.sel, where: f.where, detail: f.detail, at: [] }; k.examples.push(k.bySel[key]); }
          if (k.bySel[key].at.length < 3) k.bySel[key].at.push(width + (mode === 'light' ? '' : ' ' + mode) + ' ' + path);
        });
        if (Object.keys(rec.kinds).length) perPage.push(rec);
      }
    }
  }
  const res = { pages, errors: errors.slice(0, 10), byKind: {}, perPage: perPage.slice(0, 120).map((r) => r.path + ' ' + r.width + (r.mode === 'light' ? '' : ' ' + r.mode) + ' ' + JSON.stringify(r.kinds)) };
  Object.keys(byKind).forEach((kind) => {
    const k = byKind[kind];
    res.byKind[kind] = { count: k.count, pages: k.pages.size, examples: k.examples.slice(0, max).map((x) => ({ sel: x.sel, where: x.where, detail: x.detail, at: x.at })) };
  });
  return res;
};
page.context().bncLayout = bncLayout;
return 'bncLayout installed';
}
