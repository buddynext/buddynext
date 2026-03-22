// BuddyNext View Navigator — safe DOM-only version
const VIEWS = [
  // Core Social
  { file: 'home-feed.html',        icon: '📰', name: 'Home Feed',          section: 'Core Social' },
  { file: 'explore-feed.html',     icon: '🔍', name: 'Explore Feed',        section: 'Core Social' },
  { file: 'hashtag-feed.html',     icon: '#️⃣', name: 'Hashtag Feed',        section: 'Core Social' },
  { file: 'user-profile.html',     icon: '👤', name: 'User Profile',        section: 'Core Social' },
  { file: 'edit-profile.html',     icon: '✏️', name: 'Edit Profile',        section: 'Core Social' },
  { file: 'member-directory.html', icon: '👥', name: 'Member Directory',    section: 'Core Social' },
  { file: 'leaderboard.html',      icon: '🏆', name: 'Leaderboard',         section: 'Core Social' },
  { file: 'notifications.html',    icon: '🔔', name: 'Notifications',       section: 'Core Social' },
  { file: 'search-results.html',   icon: '🔎', name: 'Search Results',      section: 'Core Social' },
  // Spaces
  { file: 'spaces-directory.html', icon: '🏘️', name: 'Spaces Directory',    section: 'Spaces' },
  { file: 'space-home.html',       icon: '🏠', name: 'Space Home',          section: 'Spaces' },
  { file: 'space-settings.html',   icon: '⚙️', name: 'Space Settings',      section: 'Spaces' },
  // Messaging (v2)
  { file: 'dm-list.html',          icon: '💬', name: 'DM List',             section: 'Messaging' },
  { file: 'dm-thread.html',        icon: '🗨️', name: 'DM Thread',           section: 'Messaging' },
  { file: 'message-requests.html', icon: '📥', name: 'Message Requests',    section: 'Messaging' },
  // Auth + Onboarding
  { file: 'onboarding.html',       icon: '🚀', name: 'Onboarding Wizard',   section: 'Auth' },
  { file: 'register-login.html',   icon: '📝', name: 'Register / Login',    section: 'Auth' },
  // Admin (wp-admin)
  { file: 'admin-settings.html',       icon: '🛠️', name: 'Admin Settings',          section: 'Admin' },
  { file: 'admin-members.html',        icon: '👤', name: 'Admin Members',           section: 'Admin' },
  { file: 'admin-spaces.html',         icon: '🏘️', name: 'Admin Spaces',            section: 'Admin' },
  { file: 'email-editor.html',         icon: '📧', name: 'Email Editor',            section: 'Admin' },
  { file: 'moderation-queue.html',     icon: '🛡️', name: 'Moderation Queue',        section: 'Admin' },
  { file: 'admin-analytics.html',      icon: '📊', name: 'Analytics',               section: 'Admin' },
  { file: 'admin-nav-manager.html',    icon: '🧭', name: 'Nav Manager',             section: 'Admin' },
  { file: 'admin-integration-hub.html',icon: '🔌', name: 'Integration Hub',         section: 'Admin' },
  { file: 'widgets-blocks.html',       icon: '🧩', name: 'Blocks & Widgets',        section: 'Admin' },
  // Frontend Admin (community-level, not wp-admin)
  { file: 'community-admin.html',  icon: '🛡️', name: 'Community Admin Panel', section: 'Frontend Admin' },
  { file: 'space-moderation.html', icon: '🛡️', name: 'Space Moderation',      section: 'Frontend Admin' },
  // Jetonomy (forum integration — desktop)
  { file: 'forum-listing.html', icon: '💬', name: 'Forum Listing',   section: 'Jetonomy' },
  { file: 'forum-thread.html',  icon: '💬', name: 'Forum Thread',    section: 'Jetonomy' },
  // Mobile (390px phone frames)
  { file: 'mobile-home-feed.html',     icon: '📱', name: 'Home Feed · mobile',      section: 'Mobile' },
  { file: 'mobile-explore-feed.html',  icon: '📱', name: 'Explore Feed · mobile',   section: 'Mobile' },
  { file: 'mobile-notifications.html', icon: '📱', name: 'Notifications · mobile',  section: 'Mobile' },
  { file: 'mobile-user-profile.html',  icon: '📱', name: 'User Profile · mobile',   section: 'Mobile' },
  { file: 'mobile-space-home.html',    icon: '📱', name: 'Space Home · mobile',     section: 'Mobile' },
  { file: 'mobile-dm-thread.html',     icon: '📱', name: 'DM Thread · mobile',      section: 'Mobile' },
  { file: 'mobile-onboarding.html',    icon: '📱', name: 'Onboarding · mobile',     section: 'Mobile' },
  { file: 'mobile-register-login.html',icon: '📱', name: 'Register/Login · mobile', section: 'Mobile' },
  { file: 'mobile-forum-listing.html', icon: '💬', name: 'Forum Listing · Jetonomy', section: 'Mobile' },
  { file: 'mobile-forum-thread.html',  icon: '💬', name: 'Forum Thread · Jetonomy',  section: 'Mobile' },
];

function el(tag, styles, props) {
  const e = document.createElement(tag);
  if (styles) e.style.cssText = styles;
  if (props) Object.assign(e, props);
  return e;
}

function navLink(href, text, extraStyle) {
  const a = el('a',
    'color:#fff;text-decoration:none;padding:10px 14px;display:flex;align-items:center;gap:6px;' +
    'font-size:12px;font-weight:600;white-space:nowrap;background:rgba(255,255,255,0.1);' +
    'border-right:1px solid rgba(255,255,255,0.2);transition:background 0.15s;cursor:pointer;' + (extraStyle || ''),
    { href }
  );
  a.textContent = text;
  a.addEventListener('mouseover', function() { this.style.background = 'rgba(255,255,255,0.22)'; });
  a.addEventListener('mouseout',  function() { this.style.background = 'rgba(255,255,255,0.1)'; });
  return a;
}

(function() {
  const current = window.location.pathname.split('/').pop();
  const idx = VIEWS.findIndex(function(v) { return v.file === current; });
  if (idx === -1) return;

  const view = VIEWS[idx];
  const prev = idx > 0 ? VIEWS[idx - 1] : null;
  const next = idx < VIEWS.length - 1 ? VIEWS[idx + 1] : null;

  // Remove old topbar divs that contain "BuddyNext View Design"
  document.querySelectorAll('.topbar').forEach(function(tb) {
    if (tb.textContent.includes('BuddyNext View Design') || tb.textContent.includes('All Views')) {
      tb.remove();
    }
  });

  // Build nav bar
  var nav = el('div', 'position:sticky;top:0;z-index:9999;background:#0073aa;color:#fff;' +
    'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;' +
    'display:flex;align-items:center;box-shadow:0 2px 8px rgba(0,0,0,0.2);');
  nav.id = 'bn-nav';

  // Hub link
  var hub = navLink('index.html', '🏠 All Views', 'background:transparent;');
  hub.addEventListener('mouseover', function() { this.style.background = 'rgba(255,255,255,0.15)'; });
  hub.addEventListener('mouseout',  function() { this.style.background = 'transparent'; });
  nav.appendChild(hub);

  // Prev button
  if (prev) {
    nav.appendChild(navLink(prev.file, '← ' + prev.icon + ' ' + prev.name));
  } else {
    var dis = el('span', 'padding:10px 14px;font-size:12px;font-weight:600;opacity:0.3;' +
      'border-right:1px solid rgba(255,255,255,0.2);');
    dis.textContent = '← First';
    nav.appendChild(dis);
  }

  // Center: current view info
  var center = el('div', 'flex:1;padding:0 16px;min-width:0;');
  var vname = el('div', 'font-weight:700;font-size:13px;');
  vname.textContent = view.icon + ' ' + view.name;
  var vmeta = el('div', 'font-size:10px;opacity:0.7;margin-top:1px;');
  vmeta.textContent = view.section + ' · View ' + (idx + 1) + ' of ' + VIEWS.length;
  center.appendChild(vname);
  center.appendChild(vmeta);
  nav.appendChild(center);

  // Progress dots
  var dots = el('div', 'display:flex;align-items:center;gap:4px;padding:0 14px;flex-shrink:0;');
  VIEWS.forEach(function(v, i) {
    var dot = el('a',
      'width:8px;height:8px;border-radius:50%;display:inline-block;flex-shrink:0;' +
      'text-decoration:none;transition:transform 0.15s;',
      { href: v.file, title: (i + 1) + '. ' + v.name }
    );
    if (i === idx) {
      dot.style.background = '#fff';
      dot.style.boxShadow = '0 0 0 2px rgba(255,255,255,0.5)';
      dot.style.transform = 'scale(1.3)';
    } else if (i < idx) {
      dot.style.background = 'rgba(255,255,255,0.65)';
    } else {
      dot.style.background = 'rgba(255,255,255,0.22)';
    }
    dots.appendChild(dot);
  });
  nav.appendChild(dots);

  // Next button
  if (next) {
    nav.appendChild(navLink(next.file, next.icon + ' ' + next.name + ' →',
      'border-right:none;border-left:1px solid rgba(255,255,255,0.2);'));
  } else {
    var dis2 = el('span', 'padding:10px 14px;font-size:12px;font-weight:600;opacity:0.3;' +
      'border-left:1px solid rgba(255,255,255,0.2);');
    dis2.textContent = '✓ Last view';
    nav.appendChild(dis2);
  }

  // Style guide link (always visible)
  var sg = navLink('style-guide.html', '🎨 Style Guide',
    'background:rgba(255,255,255,0.08);border-right:none;border-left:1px solid rgba(255,255,255,0.2);font-style:italic;');
  sg.addEventListener('mouseover', function() { this.style.background = 'rgba(255,255,255,0.2)'; });
  sg.addEventListener('mouseout',  function() { this.style.background = 'rgba(255,255,255,0.08)'; });
  nav.appendChild(sg);

  document.body.insertBefore(nav, document.body.firstChild);

  // Keyboard navigation
  document.addEventListener('keydown', function(e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    if (e.key === 'ArrowLeft'  && prev) window.location.href = prev.file;
    if (e.key === 'ArrowRight' && next) window.location.href = next.file;
    if (e.key === 'Escape')             window.location.href = 'index.html';
  });

  // Keyboard hint (fades after 3s)
  var hint = el('div',
    'position:fixed;bottom:16px;right:16px;background:rgba(0,0,0,0.55);color:#fff;' +
    'padding:7px 14px;border-radius:20px;font-size:11px;font-family:sans-serif;' +
    'z-index:9998;pointer-events:none;transition:opacity 1s;');
  hint.textContent = '← → keys to navigate · Esc = all views';
  document.body.appendChild(hint);
  setTimeout(function() { hint.style.opacity = '0'; }, 2800);
  setTimeout(function() { hint.remove(); }, 4000);
})();
