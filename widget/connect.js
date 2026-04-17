/**
 * Moderation Hub - Page Connection Widget
 * 
 * Embed on any admin page to allow connecting Facebook pages without
 * navigating to the full dashboard.
 *
 * Usage:
 *   <script src="https://yourdomain.com/widget/connect.js"
 *           data-hub-url="https://yourdomain.com"
 *           data-token="ADMIN_JWT_HERE">
 *   </script>
 *
 * MIT License
 */
(function () {
  'use strict';

  // ── Config ──────────────────────────────────────────────────────
  const script  = document.currentScript;
  const HUB_URL = (script.getAttribute('data-hub-url') || '').replace(/\/$/, '');
  const TOKEN   = script.getAttribute('data-token') || '';

  if (!HUB_URL || !TOKEN) {
    console.error('[ModerationHub] Missing data-hub-url or data-token attributes.');
    return;
  }

  // ── Styles ──────────────────────────────────────────────────────
  const CSS = `
    #mh-widget {
      font-family: 'Segoe UI', system-ui, sans-serif;
      font-size: 14px;
      color: #1a1a2e;
      max-width: 480px;
    }
    #mh-widget * { box-sizing: border-box; }
    .mh-card {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 2px 8px rgba(0,0,0,.06);
    }
    .mh-title {
      font-size: 16px;
      font-weight: 700;
      margin: 0 0 16px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .mh-title span.dot {
      width: 8px; height: 8px;
      border-radius: 50%;
      background: #22c55e;
      display: inline-block;
    }
    .mh-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 9px 18px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      border: none;
      transition: opacity .15s;
    }
    .mh-btn:hover { opacity: .85; }
    .mh-btn-fb   { background: #1877f2; color: #fff; }
    .mh-btn-conn { background: #0f172a; color: #fff; }
    .mh-btn-sm   { padding: 6px 12px; font-size: 13px; }
    .mh-btn-off  { background: #f1f5f9; color: #64748b; }
    .mh-page-list { list-style: none; margin: 16px 0 0; padding: 0; }
    .mh-page-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 12px;
      border-radius: 8px;
      border: 1px solid #e2e8f0;
      margin-bottom: 8px;
      gap: 12px;
    }
    .mh-page-name { font-weight: 600; flex: 1; }
    .mh-badge {
      font-size: 11px;
      padding: 2px 8px;
      border-radius: 20px;
      font-weight: 600;
    }
    .mh-badge-ok  { background: #dcfce7; color: #166534; }
    .mh-badge-no  { background: #fef9c3; color: #854d0e; }
    .mh-msg {
      margin-top: 12px;
      padding: 10px 14px;
      border-radius: 8px;
      font-size: 13px;
    }
    .mh-msg-ok  { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
    .mh-msg-err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .mh-spinner {
      width: 18px; height: 18px;
      border: 2px solid rgba(255,255,255,.4);
      border-top-color: #fff;
      border-radius: 50%;
      animation: mh-spin .6s linear infinite;
      display: inline-block;
    }
    @keyframes mh-spin { to { transform: rotate(360deg); } }
    .mh-sep { border: none; border-top: 1px solid #e2e8f0; margin: 16px 0; }
  `;

  // ── DOM helpers ─────────────────────────────────────────────────
  function el(tag, attrs = {}, ...children) {
    const e = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => {
      if (k === 'className') e.className = v;
      else if (k.startsWith('on')) e.addEventListener(k.slice(2).toLowerCase(), v);
      else e.setAttribute(k, v);
    });
    children.forEach(c => e.append(typeof c === 'string' ? document.createTextNode(c) : c));
    return e;
  }

  function api(path, method = 'GET', body = null) {
    return fetch(HUB_URL + '/api' + path, {
      method,
      headers: {
        'Authorization': 'Bearer ' + TOKEN,
        'Content-Type': 'application/json',
      },
      body: body ? JSON.stringify(body) : undefined,
    }).then(r => r.json());
  }

  // ── Widget state ────────────────────────────────────────────────
  let state = {
    step: 'idle',   // idle | fb-login | listing | done
    pages: [],
    longToken: '',
    message: null,
    messageType: 'ok',
  };

  let root, pageList, msgBox, fbBtn, spinner;

  // ── Facebook SDK ─────────────────────────────────────────────────
  function loadFBSDK(appId) {
    return new Promise(resolve => {
      if (window.FB) { resolve(window.FB); return; }
      window.fbAsyncInit = () => {
        FB.init({ appId, cookie: true, xfbml: false, version: 'v19.0' });
        resolve(window.FB);
      };
      const s = document.createElement('script');
      s.src = 'https://connect.facebook.net/en_US/sdk.js';
      s.async = true;
      document.head.appendChild(s);
    });
  }

  // ── Render ───────────────────────────────────────────────────────
  function render() {
    root.innerHTML = '';

    const card = el('div', { className: 'mh-card' });

    const title = el('p', { className: 'mh-title' },
      el('span', { className: 'dot' }),
      'Moderation Hub — Connect a Facebook Page'
    );
    card.appendChild(title);

    if (state.step === 'idle') {
      fbBtn = el('button', { className: 'mh-btn mh-btn-fb', onClick: startFBLogin },
        '🔗 Connect with Facebook'
      );
      card.appendChild(fbBtn);
    }

    if (state.step === 'listing' && state.pages.length) {
      card.appendChild(el('p', {}, `${state.pages.length} page(s) found. Select one to connect:`));
      const list = el('ul', { className: 'mh-page-list' });

      state.pages.forEach(page => {
        const badge = page.already_connected
          ? el('span', { className: 'mh-badge mh-badge-ok' }, '✓ Connected')
          : el('span', { className: 'mh-badge mh-badge-no' }, 'Not connected');

        const btn = page.already_connected
          ? el('button', { className: 'mh-btn mh-btn-sm mh-btn-off', disabled: '' }, 'Connected')
          : el('button', {
              className: 'mh-btn mh-btn-sm mh-btn-conn',
              onClick: () => connectPage(page),
            }, 'Connect');

        list.appendChild(el('li', { className: 'mh-page-item' },
          el('span', { className: 'mh-page-name' }, page.name),
          badge,
          btn,
        ));
      });

      card.appendChild(list);
      card.appendChild(el('hr', { className: 'mh-sep' }));
      card.appendChild(el('button', { className: 'mh-btn mh-btn-fb', onClick: startFBLogin },
        '↩ Use a different account'
      ));
    }

    if (state.message) {
      msgBox = el('div', { className: `mh-msg mh-msg-${state.messageType}` }, state.message);
      card.appendChild(msgBox);
    }

    root.appendChild(card);
  }

  // ── Flow ─────────────────────────────────────────────────────────
  async function startFBLogin() {
    // Fetch app ID from hub
    const cfg = await api('/pages').catch(() => null);
    if (!cfg) { showMsg('Cannot reach Moderation Hub.', 'err'); return; }

    const appId = script.getAttribute('data-fb-app-id') || window.__MH_FB_APP_ID;
    if (!appId) {
      showMsg('Add data-fb-app-id attribute to the widget script tag.', 'err');
      return;
    }

    const FB = await loadFBSDK(appId);

    FB.login(response => {
      if (response.status !== 'connected') {
        showMsg('Facebook login cancelled.', 'err');
        return;
      }
      fetchAvailablePages(response.authResponse.accessToken);
    }, { scope: 'pages_show_list,pages_manage_metadata,pages_read_engagement' });
  }

  async function fetchAvailablePages(shortToken) {
    showMsg('Loading pages…', 'ok');
    const data = await api('/pages/available', 'POST', { user_token: shortToken });

    if (data.error) { showMsg(data.error, 'err'); return; }

    state.longToken = data.long_lived_token;
    state.pages     = data.pages || [];
    state.step      = 'listing';
    state.message   = null;
    render();
  }

  async function connectPage(page) {
    showMsg('Connecting…', 'ok');

    // Re-fetch managed pages with page access tokens (need full token)
    const data = await api('/pages/available', 'POST', { user_token: state.longToken });
    const full = (data.pages || []).find(p => p.id === page.id);

    if (!full) { showMsg('Page not found in token scope.', 'err'); return; }

    const result = await api('/pages/connect', 'POST', {
      page_id:          page.id,
      page_name:        page.name,
      page_access_token: full.access_token || state.longToken,
    });

    if (result.error) { showMsg(result.error, 'err'); return; }

    state.message     = result.message;
    state.messageType = 'ok';
    state.step        = 'done';

    // Refresh page list
    const refreshed = await api('/pages/available', 'POST', { user_token: state.longToken });
    state.pages = refreshed.pages || state.pages;
    state.step  = 'listing';
    render();
  }

  function showMsg(text, type = 'ok') {
    state.message     = text;
    state.messageType = type;
    render();
  }

  // ── Mount ────────────────────────────────────────────────────────
  function mount() {
    const style = document.createElement('style');
    style.textContent = CSS;
    document.head.appendChild(style);

    root = document.createElement('div');
    root.id = 'mh-widget';

    // Insert right after the script tag
    script.parentNode.insertBefore(root, script.nextSibling);

    render();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }

})();
