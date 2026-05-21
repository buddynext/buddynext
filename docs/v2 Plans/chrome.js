/* ════════════════════════════════════════════════════════════════
   BUDDYNEXT 2026 — APP CHROME
   Single source of truth for the rail, theme-header simulation,
   page-action-bar, and mobile bottom tab bar. Every page imports
   this and calls renderChrome({ active: 'home' }) on load.
   ════════════════════════════════════════════════════════════════ */

const RAIL = [
  { section: 'You', items: [
    { id: 'home',          label: 'Home',          href: 'home-feed.html',          icon: 'home',     dot: false },
    { id: 'explore',       label: 'Explore',       href: 'explore-feed.html',       icon: 'compass' },
    { id: 'notifications', label: 'Notifications', href: 'notifications.html',      icon: 'bell',     meta: '12' },
    { id: 'messages',      label: 'Messages',      href: 'dm-list.html',            icon: 'message',  meta: '3'  },
    { id: 'profile',       label: 'Profile',       href: 'user-profile.html',       icon: 'user' },
  ]},
  { section: 'Spaces', items: [
    { id: 'spaces',     label: 'All spaces',     href: 'spaces-directory.html', icon: 'spaces' },
    { id: 'space-design', label: 'Design',       href: 'space-home.html',       icon: 'space-dot', dot: true },
    { id: 'space-eng',    label: 'Engineering',  href: 'space-home.html',       icon: 'space-dot' },
    { id: 'space-product',label: 'Product',      href: 'space-home.html',       icon: 'space-dot', dot: true },
    { id: 'space-create', label: 'Create space', href: 'spaces-directory.html', icon: 'plus',  muted: true },
  ]},
  { section: 'Discover', items: [
    { id: 'members',     label: 'Members',     href: 'member-directory.html', icon: 'users' },
    { id: 'leaderboard', label: 'Leaderboard', href: 'leaderboard.html',      icon: 'trophy' },
    { id: 'forum',       label: 'Forum',       href: 'forum-listing.html',    icon: 'forum' },
  ]},
];

const RAIL_FOOT = [
  { id: 'admin',    label: 'Community admin', href: 'community-admin.html', icon: 'shield' },
  { id: 'settings', label: 'Settings',        href: 'edit-profile.html',    icon: 'cog' },
];

const TABBAR = [
  { id: 'home',          label: 'Home',     href: 'home-feed.html',     icon: 'home' },
  { id: 'explore',       label: 'Explore',  href: 'explore-feed.html',  icon: 'compass' },
  { id: 'create',        label: 'Create',   href: 'home-feed.html',     icon: 'plus-square' },
  { id: 'notifications', label: 'Activity', href: 'notifications.html', icon: 'bell' },
  { id: 'profile',       label: 'You',      href: 'user-profile.html',  icon: 'user' },
];

const ICONS = {
  home:        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 11l9-8 9 8M5 9v11a1 1 0 001 1h4v-7h4v7h4a1 1 0 001-1V9"/></svg>',
  compass:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M16 8l-2 6-6 2 2-6 6-2z"/></svg>',
  bell:        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M9 21a3 3 0 006 0"/></svg>',
  message:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 11.5a8.4 8.4 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.4 8.4 0 01-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.4 8.4 0 013.8-.9h.5a8.5 8.5 0 018 8v.5z"/></svg>',
  user:        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
  spaces:      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
  'space-dot': '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="4"/></svg>',
  plus:        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>',
  'plus-square':'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M12 8v8M8 12h8"/></svg>',
  users:       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>',
  trophy:      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M8 21h8M12 17v4M7 4h10v6a5 5 0 01-10 0V4zM21 5h-4M3 5h4M21 5a3 3 0 01-3 3M3 5a3 3 0 003 3"/></svg>',
  forum:       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
  shield:      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
  cog:         '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33h0a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82v0a1.65 1.65 0 001.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>',
  search:      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>',
  sparkle:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2l1.5 5.5L19 9l-5.5 1.5L12 16l-1.5-5.5L5 9l5.5-1.5L12 2zM19 14l.75 2.75L22 17.5l-2.25.75L19 21l-.75-2.75L16 17.5l2.25-.75L19 14z"/></svg>',
  'panel-left':'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/></svg>',
  sun:         '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>',
  moon:        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>',
};

function icon(name) { return ICONS[name] || ''; }

function renderChrome(opts = {}) {
  const active = opts.active || '';
  const pageTitle = opts.title || '';
  const themeHeaderText = opts.themeBrand || 'Acme Studio';
  const showThemeHeader = opts.showThemeHeader !== false;

  const themeHeader = showThemeHeader ? `
    <div class="bn-theme-header" aria-label="Theme-owned header (not BuddyNext)">
      <span class="bn-theme-header-tag">Theme header</span>
      <span class="bn-theme-header-brand">${themeHeaderText}</span>
      <nav class="bn-theme-header-nav">
        <a href="#">Home</a>
        <a href="#">About</a>
        <a href="#">Blog</a>
        <a href="#">Shop</a>
      </nav>
      <span class="bn-theme-header-spacer"></span>
      <span style="font-size:12px;color:var(--bn-ink-4);">Customer's WordPress theme renders this — we don't touch it.</span>
    </div>` : '';

  const railSections = RAIL.map(sec => `
    <div class="bn-rail-section">
      <div class="bn-rail-section-label">${sec.section}</div>
      ${sec.items.map(it => railItem(it, active)).join('')}
    </div>
  `).join('');

  const railFoot = RAIL_FOOT.map(it => railItem(it, active)).join('');

  const tabbar = TABBAR.map(it => `
    <a class="bn-tabbar-item" href="${it.href}" ${active === it.id ? 'aria-current="page"' : ''}>
      ${icon(it.icon)}
      <span>${it.label}</span>
    </a>
  `).join('');

  const pagebarTitle = pageTitle ? `<div class="bn-pagebar-title">${pageTitle}</div>` : '';
  const pagebarActions = opts.actions || '';

  return `
    ${themeHeader}
    <aside class="bn-rail" aria-label="BuddyNext navigation">
      <div class="bn-rail-brand">
        <span class="bn-rail-brand-mark">b</span>
        <span class="bn-rail-label">buddy<b>·</b>next</span>
      </div>
      ${railSections}
      <div class="bn-rail-foot">${railFoot}</div>
    </aside>
    <main class="bn-content">
      <div class="bn-pagebar">
        ${pagebarTitle}
        <span class="bn-pagebar-spacer"></span>
        <button class="bn-pagebar-search" onclick="document.dispatchEvent(new CustomEvent('bn-search'))">
          ${icon('search')}
          <span>Search…</span>
          <span class="bn-pagebar-search-spacer"></span>
          <span class="bn-kbd">⌘</span><span class="bn-kbd">K</span>
        </button>
        <button class="bn-pagebar-iconbtn" aria-label="Notifications" onclick="location.href='notifications.html'">
          ${icon('bell')}
          <span class="badge-count">12</span>
        </button>
        <button class="bn-pagebar-iconbtn" aria-label="Toggle theme" onclick="bnToggleTheme()">
          ${icon('moon')}
        </button>
        <button class="bn-pagebar-iconbtn" aria-label="Toggle rail" onclick="bnToggleRail()">
          ${icon('panel-left')}
        </button>
        ${pagebarActions}
      </div>
      <div class="bn-page" data-layout="${opts.layout || 'feed'}">
        ${opts.body || '<!-- page body goes here -->'}
      </div>
    </main>
    <nav class="bn-tabbar" aria-label="Mobile navigation">
      <div class="bn-tabbar-inner">${tabbar}</div>
    </nav>
  `;
}

function railItem(it, active) {
  const isActive = it.id === active;
  const meta = it.meta ? `<span class="bn-rail-meta">${it.meta}</span>` : '';
  const dot  = it.dot ? `<span class="bn-rail-dot"></span>` : '';
  return `
    <a class="bn-rail-item" href="${it.href}" ${isActive ? 'aria-current="page"' : ''} ${it.muted ? 'style="color:var(--bn-ink-3)"' : ''}>
      <span class="bn-rail-icon">${icon(it.icon)}</span>
      <span class="bn-rail-label">${it.label}</span>
      ${meta}${dot}
    </a>
  `;
}

// ─── Theme + rail toggles ────────────────────────────────────────
function bnToggleTheme() {
  const r = document.documentElement;
  const next = r.dataset.bnTheme === 'dark' ? 'light' : 'dark';
  r.dataset.bnTheme = next;
  localStorage.setItem('bn-theme', next);
}

function bnToggleRail() {
  const r = document.documentElement;
  const w = window.innerWidth;
  const defaultExpanded = w > 1280;
  const cur = r.dataset.bnRail || (defaultExpanded ? 'expanded' : 'collapsed');
  r.dataset.bnRail = cur === 'expanded' ? 'collapsed' : 'expanded';
  localStorage.setItem('bn-rail', r.dataset.bnRail);
}

// ─── Boot ────────────────────────────────────────────────────────
function bnBootChrome() {
  const stored = localStorage.getItem('bn-theme');
  if (stored) document.documentElement.dataset.bnTheme = stored;
  const railStored = localStorage.getItem('bn-rail');
  if (railStored) document.documentElement.dataset.bnRail = railStored;
  const dens = localStorage.getItem('bn-density');
  if (dens) document.documentElement.dataset.bnDensity = dens;
  const accent = localStorage.getItem('bn-accent-hue');
  if (accent) document.documentElement.style.setProperty('--bn-accent-hue', accent);
  const radius = localStorage.getItem('bn-radius');
  if (radius) document.documentElement.style.setProperty('--bn-radius-scale', radius);
}

// ─── Tweaks panel ────────────────────────────────────────────────
function bnInjectTweaks() {
  if (document.getElementById('bn-tweaks-style')) return;
  const css = document.createElement('style');
  css.id = 'bn-tweaks-style';
  css.textContent = `
    #bn-tweaks {
      position: fixed; right: 16px; bottom: 16px;
      width: 296px; z-index: 9999;
      background: var(--bn-surface); color: var(--bn-ink);
      border: 1px solid var(--bn-line);
      border-radius: var(--bn-r-lg);
      box-shadow: var(--bn-shadow-pop);
      font-family: var(--bn-font-ui); font-size: var(--bn-text-sm);
      display: none;
    }
    #bn-tweaks[data-open="true"] { display: block; }
    .bn-tw-hd {
      padding: var(--bn-s3) var(--bn-s4);
      display: flex; align-items: center; justify-content: space-between;
      border-bottom: 1px solid var(--bn-line-faint);
    }
    .bn-tw-title {
      font-family: var(--bn-font-display); font-weight: 600;
      font-size: var(--bn-text-sm);
    }
    .bn-tw-x {
      width: 24px; height: 24px; border: none; background: transparent;
      color: var(--bn-ink-3); cursor: pointer; border-radius: var(--bn-r-sm);
      font-size: 16px;
    }
    .bn-tw-x:hover { background: var(--bn-sunken); color: var(--bn-ink); }
    .bn-tw-bd { padding: var(--bn-s3) var(--bn-s4); display: flex; flex-direction: column; gap: var(--bn-s3); max-height: 60vh; overflow: auto; }
    .bn-tw-section {
      font-family: var(--bn-font-display);
      font-size: 10px; font-weight: 700;
      text-transform: uppercase; letter-spacing: 0.08em;
      color: var(--bn-ink-3); margin-top: var(--bn-s2);
    }
    .bn-tw-section:first-child { margin-top: 0; }
    .bn-tw-row { display: flex; flex-direction: column; gap: 6px; }
    .bn-tw-label { font-size: var(--bn-text-xs); color: var(--bn-ink-2); display: flex; justify-content: space-between; }
    .bn-tw-label code { font-family: var(--bn-font-mono); color: var(--bn-ink-3); font-size: 10px; }
    .bn-tw-seg {
      display: flex; background: var(--bn-sunken);
      border-radius: var(--bn-r-sm); padding: 2px;
    }
    .bn-tw-seg button {
      flex: 1; border: none; background: transparent; padding: 5px 8px;
      font-size: 11px; font-family: var(--bn-font-ui); color: var(--bn-ink-2);
      border-radius: 4px; cursor: pointer;
    }
    .bn-tw-seg button[aria-pressed="true"] {
      background: var(--bn-surface); color: var(--bn-ink);
      box-shadow: 0 1px 2px rgba(0,0,0,0.06); font-weight: 600;
    }
    .bn-tw-swatches { display: flex; gap: 6px; }
    .bn-tw-swatch {
      width: 28px; height: 28px; border-radius: 50%;
      cursor: pointer; border: 2px solid transparent;
      transition: transform 0.1s;
    }
    .bn-tw-swatch:hover { transform: scale(1.1); }
    .bn-tw-swatch[aria-pressed="true"] { border-color: var(--bn-ink); }
    .bn-tw-slider {
      -webkit-appearance: none; appearance: none;
      width: 100%; height: 4px; background: var(--bn-sunken);
      border-radius: 2px; outline: none;
    }
    .bn-tw-slider::-webkit-slider-thumb {
      -webkit-appearance: none; appearance: none;
      width: 14px; height: 14px; border-radius: 50%;
      background: var(--bn-accent); cursor: pointer;
    }
    .bn-tw-fab {
      position: fixed; right: 16px; bottom: 16px; z-index: 9998;
      width: 44px; height: 44px; border-radius: 50%;
      background: var(--bn-ink); color: var(--bn-surface);
      border: none; cursor: pointer;
      box-shadow: var(--bn-shadow-pop);
      display: flex; align-items: center; justify-content: center;
      font-size: 18px;
    }
    .bn-tw-fab[data-hidden="true"] { display: none; }
    .bn-tw-foot {
      padding: var(--bn-s3) var(--bn-s4);
      border-top: 1px solid var(--bn-line-faint);
      display: flex; gap: var(--bn-s2);
    }
    .bn-tw-foot button {
      flex: 1; padding: 6px 10px;
      border: 1px solid var(--bn-line);
      background: var(--bn-surface);
      border-radius: var(--bn-r-sm);
      font-size: 11px; font-family: var(--bn-font-ui);
      color: var(--bn-ink-2); cursor: pointer;
    }
    .bn-tw-foot button:hover { color: var(--bn-ink); border-color: var(--bn-ink-3); }
  `;
  document.head.appendChild(css);

  const panel = document.createElement('div');
  panel.id = 'bn-tweaks';
  panel.innerHTML = `
    <div class="bn-tw-hd">
      <div class="bn-tw-title">Tweaks</div>
      <button class="bn-tw-x" id="bn-tw-close" aria-label="Close">×</button>
    </div>
    <div class="bn-tw-bd">

      <div class="bn-tw-section">Theme</div>
      <div class="bn-tw-row">
        <label class="bn-tw-label">Mode</label>
        <div class="bn-tw-seg" data-tw="theme">
          <button data-v="light">Light</button>
          <button data-v="dark">Dark</button>
        </div>
      </div>

      <div class="bn-tw-row">
        <label class="bn-tw-label">Accent <code>--bn-accent</code></label>
        <div class="bn-tw-swatches" data-tw="accent">
          <span class="bn-tw-swatch" data-v="250" style="background: oklch(60% 0.13 250);"></span>
          <span class="bn-tw-swatch" data-v="30" style="background: oklch(60% 0.13 30);"></span>
          <span class="bn-tw-swatch" data-v="150" style="background: oklch(60% 0.13 150);"></span>
          <span class="bn-tw-swatch" data-v="320" style="background: oklch(60% 0.13 320);"></span>
          <span class="bn-tw-swatch" data-v="60" style="background: oklch(60% 0.13 60);"></span>
          <span class="bn-tw-swatch" data-v="0" style="background: oklch(60% 0.13 0);"></span>
        </div>
      </div>

      <div class="bn-tw-section">Layout</div>
      <div class="bn-tw-row">
        <label class="bn-tw-label">Density <code>data-bn-density</code></label>
        <div class="bn-tw-seg" data-tw="density">
          <button data-v="compact">Compact</button>
          <button data-v="comfortable">Comfortable</button>
          <button data-v="spacious">Spacious</button>
        </div>
      </div>

      <div class="bn-tw-row">
        <label class="bn-tw-label">Rail <code>data-bn-rail</code></label>
        <div class="bn-tw-seg" data-tw="rail">
          <button data-v="expanded">Expanded</button>
          <button data-v="collapsed">Icons</button>
        </div>
      </div>

      <div class="bn-tw-row">
        <label class="bn-tw-label">Corner radius <span data-tw-out="radius">1.0×</span></label>
        <input type="range" class="bn-tw-slider" data-tw="radius" min="0" max="2" step="0.25" value="1"/>
      </div>

    </div>
    <div class="bn-tw-foot">
      <button data-tw-action="reset">Reset to defaults</button>
    </div>
  `;
  document.body.appendChild(panel);

  const fab = document.createElement('button');
  fab.className = 'bn-tw-fab';
  fab.title = 'Open Tweaks';
  fab.innerHTML = '⚙';
  fab.id = 'bn-tw-fab';
  fab.addEventListener('click', () => bnTweaksOpen(true));
  document.body.appendChild(fab);

  const root = document.documentElement;

  function syncUI() {
    const theme = root.dataset.bnTheme || 'light';
    const dens = root.dataset.bnDensity || 'comfortable';
    const rail = root.dataset.bnRail || 'expanded';
    const hue = getComputedStyle(root).getPropertyValue('--bn-accent-hue').trim() || '250';
    const rad = localStorage.getItem('bn-radius') || '1';
    panel.querySelectorAll('[data-tw="theme"] button').forEach(b => b.setAttribute('aria-pressed', b.dataset.v === theme));
    panel.querySelectorAll('[data-tw="density"] button').forEach(b => b.setAttribute('aria-pressed', b.dataset.v === dens));
    panel.querySelectorAll('[data-tw="rail"] button').forEach(b => b.setAttribute('aria-pressed', b.dataset.v === rail));
    panel.querySelectorAll('[data-tw="accent"] .bn-tw-swatch').forEach(s => s.setAttribute('aria-pressed', s.dataset.v === hue));
    const slider = panel.querySelector('[data-tw="radius"]');
    if (slider) { slider.value = rad; panel.querySelector('[data-tw-out="radius"]').textContent = parseFloat(rad).toFixed(2) + '×'; }
  }

  panel.querySelectorAll('[data-tw="theme"] button').forEach(b => b.addEventListener('click', () => {
    root.dataset.bnTheme = b.dataset.v;
    localStorage.setItem('bn-theme', b.dataset.v);
    syncUI();
  }));
  panel.querySelectorAll('[data-tw="density"] button').forEach(b => b.addEventListener('click', () => {
    root.dataset.bnDensity = b.dataset.v;
    localStorage.setItem('bn-density', b.dataset.v);
    syncUI();
  }));
  panel.querySelectorAll('[data-tw="rail"] button').forEach(b => b.addEventListener('click', () => {
    root.dataset.bnRail = b.dataset.v;
    localStorage.setItem('bn-rail', b.dataset.v);
    syncUI();
  }));
  panel.querySelectorAll('[data-tw="accent"] .bn-tw-swatch').forEach(s => s.addEventListener('click', () => {
    root.style.setProperty('--bn-accent-hue', s.dataset.v);
    localStorage.setItem('bn-accent-hue', s.dataset.v);
    syncUI();
  }));
  const slider = panel.querySelector('[data-tw="radius"]');
  slider.addEventListener('input', () => {
    root.style.setProperty('--bn-radius-scale', slider.value);
    localStorage.setItem('bn-radius', slider.value);
    panel.querySelector('[data-tw-out="radius"]').textContent = parseFloat(slider.value).toFixed(2) + '×';
  });
  panel.querySelector('#bn-tw-close').addEventListener('click', () => bnTweaksOpen(false));
  panel.querySelector('[data-tw-action="reset"]').addEventListener('click', () => {
    ['bn-theme','bn-density','bn-rail','bn-accent-hue','bn-radius'].forEach(k => localStorage.removeItem(k));
    root.dataset.bnTheme = 'light'; root.dataset.bnDensity = 'comfortable'; root.dataset.bnRail = 'expanded';
    root.style.removeProperty('--bn-accent-hue'); root.style.removeProperty('--bn-radius-scale');
    syncUI();
  });

  syncUI();

  // Host edit-mode protocol
  window.addEventListener('message', (e) => {
    const t = e.data && e.data.type;
    if (t === '__activate_edit_mode') bnTweaksOpen(true);
    if (t === '__deactivate_edit_mode') bnTweaksOpen(false);
  });
  try { window.parent.postMessage({ type: '__edit_mode_available' }, '*'); } catch(_){}
}

function bnTweaksOpen(open) {
  const panel = document.getElementById('bn-tweaks');
  const fab = document.getElementById('bn-tw-fab');
  if (panel) panel.dataset.open = String(open);
  if (fab) fab.dataset.hidden = String(open);
  if (!open) try { window.parent.postMessage({ type: '__edit_mode_dismissed' }, '*'); } catch(_){}
}

if (typeof window !== 'undefined') {
  bnBootChrome();
  window.bnToggleTheme = bnToggleTheme;
  window.bnToggleRail = bnToggleRail;
  window.renderChrome = renderChrome;
  window.bnTweaksOpen = bnTweaksOpen;
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bnInjectTweaks);
  } else {
    bnInjectTweaks();
  }
}
