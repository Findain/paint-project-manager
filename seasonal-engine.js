/* Paint Project Manager V4.0 · Rev AB seasonal theme engine.
   Theme selection is local-date only. It never calls a date service and never
   writes project, order, Money Hub, or notification data. */
(function (root) {
  'use strict';

  var ASSET_ROOT = 'theme-assets/';
  var ALLOWED = ['fall', 'thanksgiving', 'christmas', 'winter', 'stpatricks'];

  var THEMES = {
    fall: {
      label: 'Fall', available: true, fallback: null,
      assets: {
        splashDesktop: ASSET_ROOT + 'splash-desktop.webp',
        splashMobile: ASSET_ROOT + 'splash-mobile.webp',
        homeDesktop: ASSET_ROOT + 'home-desktop.webp',
        homeMobile: ASSET_ROOT + 'home-mobile.webp'
      },
      home: {
        desk: {
          w: 1536, h: 1024, gap: 55,
          badge: [89.7, 21.7], moneyBadge: [74.0, 65.0],
          box: {
            'new': [12.2, 52.0, 26.0, 21.5],
            money: [61.0, 50.0, 15.8, 26.5],
            orders: [75.5, 9.7, 15.5, 23.0],
            projects: [37.3, 55.0, 18.5, 21.5],
            settings: [79.4, 56.0, 20.0, 23.0]
          }
        },
        mob: {
          w: 1024, h: 1536, gap: 50,
          badge: [90.0, 26.0], moneyBadge: [73.0, 64.0],
          box: {
            'new': [8.5, 49.0, 21.5, 14.5],
            money: [59.5, 54.0, 16.5, 11.5],
            orders: [75.0, 17.2, 16.2, 11.8],
            projects: [40.0, 53.8, 19.0, 11.5],
            settings: [76.5, 54.0, 23.0, 13.0]
          }
        }
      }
    },
    thanksgiving: {
      label: 'Thanksgiving', available: true, fallback: 'fall',
      assets: {
        splashDesktop: ASSET_ROOT + 'splash-desktop.webp',
        splashMobile: ASSET_ROOT + 'splash-mobile.webp',
        homeDesktop: ASSET_ROOT + 'home-desktop.webp',
        homeMobile: ASSET_ROOT + 'home-mobile.webp'
      },
      /* Approved Rev AA maps, retained without changing badge logic. */
      home: {
        desk: {
          w: 1920, h: 1080, badge: [74.77, 39.03], gap: 59,
          moneyBadge: [12.46, 61.2],
          box: {
            'new': [23.0, 33.5, 14.0, 25.5],
            money: [1.3, 50.4, 18.1, 11.6],
            orders: [68.7, 18.3, 12.0, 25.9],
            projects: [65.2, 49.0, 21.5, 21.0],
            settings: [78.8, 71.7, 19.7, 18.2]
          }
        },
        mob: {
          w: 945, h: 2048, badge: [21.75, 39.48], gap: 63,
          moneyBadge: [10.3, 63.77],
          box: {
            'new': [15.46, 43.42, 19.82, 10.21],
            money: [0.46, 59.1, 19.82, 5.09],
            orders: [12.96, 29.44, 17.6, 11.8],
            projects: [76.11, 53.25, 23.89, 5.51],
            settings: [38.8, 77.82, 46.94, 8.21]
          }
        }
      }
    },

    /* These scheduled slots intentionally fall back safely when their artwork
       is not present in the deployed package. Supplying the four matching
       assets and per-image maps completes each slot without changing dates. */
    christmas: { label: 'Christmas', available: false, fallback: 'fall', assets: null, home: null },
    winter: { label: 'Winter', available: false, fallback: 'fall', assets: null, home: null },
    stpatricks: { label: "St. Patrick's Day", available: false, fallback: 'winter', assets: null, home: null }
  };

  function localDateParts(value) {
    var d = value instanceof Date ? value : new Date(value == null ? Date.now() : value);
    if (isNaN(d.getTime())) throw new Error('Invalid date');
    return { year: d.getFullYear(), month: d.getMonth(), day: d.getDate() };
  }

  function localDate(year, month, day) {
    return new Date(year, month, day, 12, 0, 0, 0);
  }

  function fourthThursday(year) {
    var first = localDate(year, 10, 1);
    var firstThursday = 1 + ((4 - first.getDay() + 7) % 7);
    return localDate(year, 10, firstThursday + 21);
  }

  function dayNumber(d) {
    return Math.floor(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()) / 86400000);
  }

  function resolveAutomatic(value) {
    var p = localDateParts(value);
    var today = localDate(p.year, p.month, p.day);
    var thanksgiving = fourthThursday(p.year);
    var thanksgivingStart = new Date(thanksgiving.getTime());
    thanksgivingStart.setDate(thanksgivingStart.getDate() - 7);
    var n = dayNumber(today);
    var t0 = dayNumber(thanksgivingStart);
    var t1 = dayNumber(thanksgiving);

    if (n >= t0 && n <= t1) return 'thanksgiving';
    if (n > t1 && (p.month === 10 || (p.month === 11 && p.day <= 25))) return 'christmas';
    if (p.month === 2 && p.day >= 12 && p.day <= 17) return 'stpatricks';
    if (p.month === 8 || p.month === 9 || (p.month === 10 && n < t0)) return 'fall';
    return 'winter';
  }

  function queryOverride(search) {
    var match = /(?:^|[?&])theme=([^&#]+)/i.exec(search == null ? '' : String(search));
    if (!match) return null;
    var value;
    try { value = decodeURIComponent(match[1]).toLowerCase().replace(/[^a-z]/g, ''); }
    catch (e) { return null; }
    if (value === 'stpatrick' || value === 'stpattys' || value === 'stpatty') value = 'stpatricks';
    return ALLOWED.indexOf(value) >= 0 ? value : null;
  }

  function resolveRequested(value, search) {
    return queryOverride(search) || resolveAutomatic(value);
  }

  function resolveAvailable(name) {
    var seen = {};
    var current = ALLOWED.indexOf(name) >= 0 ? name : 'fall';
    while (current && !seen[current]) {
      seen[current] = true;
      var theme = THEMES[current];
      if (theme && theme.available && !theme.runtimeFailed && theme.assets && theme.home) return current;
      current = theme && theme.fallback;
    }
    return 'fall';
  }

  var state = { requested: null, active: null, timer: 0 };

  function setImage(el, value) {
    if (!el || !value) return;
    el.removeAttribute('srcset');
    el.setAttribute('src', value);
  }

  function applyArtwork(requested, active) {
    if (typeof document === 'undefined') return;
    var theme = THEMES[active];
    var splash = document.getElementById('splash');
    var splashImage = splash && splash.querySelector('picture img');
    var splashSource = splash && splash.querySelector('picture source');
    var homeDesk = document.getElementById('ppmHomeDesk');
    var homeMob = document.getElementById('ppmHomeMob');

    if (splashSource) splashSource.setAttribute('srcset', theme.assets.splashMobile);
    setImage(splashImage, theme.assets.splashDesktop);
    setImage(homeDesk, theme.assets.homeDesktop);
    setImage(homeMob, theme.assets.homeMobile);

    if (splash) splash.setAttribute('aria-label', 'Paint Project Manager ' + theme.label + ' loading screen');
    if (splashImage) splashImage.setAttribute('alt', 'Paint Project Manager ' + theme.label + ' loading screen');

    document.documentElement.setAttribute('data-ppm-theme-requested', requested);
    document.documentElement.setAttribute('data-ppm-theme', active);
    if (requested !== active) document.documentElement.setAttribute('data-ppm-theme-fallback', active);
    else document.documentElement.removeAttribute('data-ppm-theme-fallback');

    root.PPM_HOME_ART = theme.home;
    try { if (typeof root.ppmHomeLayout === 'function') root.ppmHomeLayout(); } catch (e) { }
  }

  function activate(name) {
    var requested = ALLOWED.indexOf(name) >= 0 ? name : 'fall';
    var active = resolveAvailable(requested);
    state.requested = requested;
    state.active = active;
    applyArtwork(requested, active);
    return { requested: requested, active: active, usedFallback: requested !== active };
  }

  function handleAssetFailure() {
    if (!state.active || state.active === 'fall') return;
    THEMES[state.active].runtimeFailed = true;
    activate(state.requested || 'fall');
  }

  function bindAssetFailureFallback() {
    if (typeof document === 'undefined') return;
    ['ppmHomeDesk', 'ppmHomeMob'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el && !el.getAttribute('data-seasonal-error-bound')) {
        el.setAttribute('data-seasonal-error-bound', '1');
        el.addEventListener('error', handleAssetFailure);
      }
    });
    var splashImage = document.querySelector('#splash picture img');
    if (splashImage && !splashImage.getAttribute('data-seasonal-error-bound')) {
      splashImage.setAttribute('data-seasonal-error-bound', '1');
      splashImage.addEventListener('error', handleAssetFailure);
    }
  }

  function nextLocalMidnightDelay() {
    var now = new Date();
    var next = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, 0, 0, 2, 0);
    return Math.max(1000, next.getTime() - now.getTime());
  }

  function refresh(value) {
    var requested = resolveRequested(value, typeof location === 'undefined' ? '' : location.search);
    var result = activate(requested);
    if (state.timer) clearTimeout(state.timer);
    if (typeof setTimeout === 'function') {
      state.timer = setTimeout(function () { refresh(new Date()); }, nextLocalMidnightDelay());
    }
    return result;
  }

  var api = {
    version: 'V4.0 · Rev AB',
    themes: THEMES,
    fourthThursday: fourthThursday,
    resolveAutomatic: resolveAutomatic,
    resolveRequested: resolveRequested,
    resolveAvailable: resolveAvailable,
    activate: activate,
    refresh: refresh,
    getState: function () { return { requested: state.requested, active: state.active }; }
  };
  root.PPMSeasonalThemeEngine = api;

  if (typeof module !== 'undefined' && module.exports) module.exports = api;

  if (typeof document !== 'undefined') {
    bindAssetFailureFallback();
    refresh(new Date());
    root.addEventListener('pageshow', function () { refresh(new Date()); });
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) refresh(new Date());
    });
  }
})(typeof window !== 'undefined' ? window : globalThis);
