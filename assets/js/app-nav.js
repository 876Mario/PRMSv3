/**
 * DGC PIAMS — Navigation UX helpers
 * - Shows a modern loading overlay while navigating between pages
 *   (this app uses classic full-page loads, not a SPA router).
 * - Persists the sidebar scroll position across page loads so the
 *   sidebar stays where the user left it when a link is clicked.
 */
(function () {
  'use strict';

  // Shared sessionStorage key for the sidebar scroll position. Exposed on
  // window so the early inline restore script in includes/header.php (which
  // must run before this file loads, to avoid a visible scroll-position
  // flash) and this file both read/write the same key.
  var SIDEBAR_SCROLL_KEY = window.PIAMS_SIDEBAR_SCROLL_KEY || 'piams.sidebarScrollTop';
  window.PIAMS_SIDEBAR_SCROLL_KEY = SIDEBAR_SCROLL_KEY;

  // Safety-net timeout: hides the loader if a navigation is cancelled or
  // never completes (e.g. the browser blocks it or the user stays on page).
  var LOADER_TIMEOUT_MS = 8000;

  /* ── Page loading overlay ─────────────────────────────────── */
  var loader = document.getElementById('pageLoader');
  var loaderBar = document.getElementById('pageLoaderBar');
  var loaderTimer = null;

  function showLoader() {
    if (loader) loader.classList.add('is-active');
    if (loaderBar) {
      loaderBar.classList.remove('is-done');
      // Reading offsetWidth forces the browser to flush pending style
      // changes (reflow) so the width transition below restarts cleanly,
      // instead of being skipped because the class was already applied.
      void loaderBar.offsetWidth;
      loaderBar.classList.add('is-active');
    }
  }

  function hideLoader() {
    if (loader) loader.classList.remove('is-active');
    if (loaderBar) {
      loaderBar.classList.add('is-done');
      loaderBar.classList.remove('is-active');
    }
    if (loaderTimer) {
      clearTimeout(loaderTimer);
      loaderTimer = null;
    }
  }

  // Hide the loader once the new page has fully rendered.
  window.addEventListener('pageshow', hideLoader);
  document.addEventListener('DOMContentLoaded', hideLoader);

  function isSamePageAnchorLink(url) {
    return url.pathname === window.location.pathname &&
      url.search === window.location.search &&
      !!url.hash;
  }

  function isSameOriginNavigation(link) {
    if (!link || !link.href) return false;
    if (link.target && link.target !== '' && link.target !== '_self') return false;
    if (link.hasAttribute('download')) return false;
    if (link.dataset && (link.dataset.bsToggle || link.dataset.noLoader !== undefined)) return false;
    var href = link.getAttribute('href') || '';
    if (href === '' || href.charAt(0) === '#') return false;
    try {
      var url = new URL(link.href, window.location.href);
      // Only allow standard http(s) navigations; reject javascript:, data:,
      // mailto:, tel:, vbscript: and any other non-navigable scheme.
      if (url.protocol !== 'http:' && url.protocol !== 'https:') return false;
      if (url.origin !== window.location.origin) return false;
      // Same-page anchor navigation shouldn't show the loader.
      if (isSamePageAnchorLink(url)) return false;
    } catch (e) {
      return false;
    }
    return true;
  }

  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var link = e.target.closest('a[href]');
    if (isSameOriginNavigation(link)) {
      showLoader();
      // Safety net in case navigation is cancelled or takes too long.
      loaderTimer = setTimeout(hideLoader, LOADER_TIMEOUT_MS);
    }
  });

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset && form.dataset.noLoader !== undefined) return;
    showLoader();
    loaderTimer = setTimeout(hideLoader, LOADER_TIMEOUT_MS);
  });

  window.addEventListener('beforeunload', showLoader);

  /* ── Sidebar scroll position persistence ──────────────────── */
  var sidebar = document.getElementById('sidebarMenu');
  var saveTimer = null;
  if (sidebar) {
    function persistScroll() {
      sessionStorage.setItem(SIDEBAR_SCROLL_KEY, String(sidebar.scrollTop));
    }
    sidebar.addEventListener('scroll', function () {
      if (saveTimer) clearTimeout(saveTimer);
      saveTimer = setTimeout(persistScroll, 100);
    }, { passive: true });

    sidebar.addEventListener('click', persistScroll, true);
    window.addEventListener('pagehide', persistScroll);
    window.addEventListener('beforeunload', persistScroll);
  }
})();
