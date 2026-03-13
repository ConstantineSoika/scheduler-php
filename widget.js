(function () {
  'use strict';

  var script     = document.currentScript;
  var sel        = script.getAttribute('data-container')  || '#scheduling-widget';
  var apiBase    = script.getAttribute('data-api-url')    || '';
  var operatorId = script.getAttribute('data-operator-id') || '';
  var container  = document.querySelector(sel);

  if (!container) {
    console.error('[Widget] Container not found:', sel);
    return;
  }

  // ── Config (loaded from /api/widget/config, with defaults) ──────────────────
  var cfg = {
    primaryColor:    '#2863E0',
    headerGradient:  true,
    fontFamily:      'system',
    borderRadius:    12,
    viewMode:        'month',
    smsEnabled:      false,
    bgColor:         '#ffffff',
    textColor:       '#1C1C28',
    shadowIntensity: 'default',
  };

  // ── Payment config (loaded from /api/payment/config) ────────────────────────
  var paymentCfg = {
    testMode:             false,
    stripeEnabled:        false,
    stripePublishableKey: '',
    liqpayEnabled:        false,
    liqpayPublicKey:      '',
    googlePayEnabled:     false,
    googlePayMerchantId:  '',
    currency:             'USD',
  };

  // Stripe instance (set after Stripe.js loads)
  var _stripe      = null;
  var _cardElement = null;

  // ── Apply CSS custom properties from config ──────────────────────────────────
  function applyCfg() {
    var fontMap = {
      'system':      "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
      'inter':       "'Inter', system-ui, sans-serif",
      'roboto':      "'Roboto', system-ui, sans-serif",
      'opensans':    "'Open Sans', system-ui, sans-serif",
      'poppins':     "'Poppins', system-ui, sans-serif",
      'nunito':      "'Nunito', system-ui, sans-serif",
      'lato':        "'Lato', system-ui, sans-serif",
      'montserrat':  "'Montserrat', system-ui, sans-serif",
      'dmSans':      "'DM Sans', system-ui, sans-serif",
      'playfair':    "'Playfair Display', Georgia, serif",
      'georgia':     "Georgia, 'Times New Roman', serif",
    };
    var font = fontMap[cfg.fontFamily] || fontMap['system'];
    var r    = cfg.borderRadius + 'px';
    var rSm  = Math.max(4,  cfg.borderRadius - 4)  + 'px';
    var rXl  = Math.min(24, cfg.borderRadius + 6)  + 'px';

    // Derive darker/lighter shades from primary hex
    var hex = cfg.primaryColor.replace('#', '');
    var ri  = parseInt(hex.substr(0, 2), 16);
    var gi  = parseInt(hex.substr(2, 2), 16);
    var bi  = parseInt(hex.substr(4, 2), 16);
    var rgb = ri + ',' + gi + ',' + bi;

    function clamp(v) { return Math.max(0, Math.min(255, v)); }
    var darker = '#' +
      clamp(ri - 28).toString(16).padStart(2, '0') +
      clamp(gi - 28).toString(16).padStart(2, '0') +
      clamp(bi - 28).toString(16).padStart(2, '0');

    var hdrBg = cfg.headerGradient
      ? 'linear-gradient(135deg,' + cfg.primaryColor + ' 0%,#7F5AF0 100%)'
      : cfg.primaryColor;

    var shadowMap = {
      none:    'none',
      default: '0 6px 32px rgba(28,28,40,.11)',
      strong:  '0 12px 48px rgba(28,28,40,.22)',
    };
    var shadow = shadowMap[cfg.shadowIntensity] || shadowMap.default;

    var el = container.querySelector('.spw') || container;
    el.style.setProperty('--spw-primary',    cfg.primaryColor);
    el.style.setProperty('--spw-primary-rgb', rgb);
    el.style.setProperty('--spw-primary-dk', darker);
    el.style.setProperty('--spw-primary-lt', 'rgba(' + rgb + ',0.10)');
    el.style.setProperty('--spw-hdr-bg',     hdrBg);
    el.style.setProperty('--spw-font',       font);
    el.style.setProperty('--spw-r',          r);
    el.style.setProperty('--spw-r-sm',       rSm);
    el.style.setProperty('--spw-r-xl',       rXl);
    el.style.setProperty('--spw-bg',         cfg.bgColor     || '#ffffff');
    el.style.setProperty('--spw-text',       cfg.textColor   || '#1C1C28');
    el.style.setProperty('--spw-shadow',     shadow);
  }

  // ── CSS ─────────────────────────────────────────────────────────────────────
  var CSS = [
    // Widget shell
    '.spw{font-family:var(--spw-font,-apple-system,sans-serif);max-width:480px;background:var(--spw-bg,#fff);border:1px solid #E0E5F2;border-radius:var(--spw-r-xl,18px);overflow:hidden;box-shadow:var(--spw-shadow,0 6px 32px rgba(28,28,40,.11));color:var(--spw-text,#1C1C28)}',

    // Header
    '.spw-hdr{background:var(--spw-hdr-bg,#2863E0);color:#fff;padding:16px 20px;display:flex;align-items:center;gap:10px;min-height:58px}',
    '.spw-hdr h2{margin:0;font-size:15px;font-weight:700;flex:1;letter-spacing:-.01em}',
    '.spw-hdr-icon{font-size:20px;opacity:.9}',
    '.spw-back{background:rgba(255,255,255,.18);border:none;color:#fff;cursor:pointer;width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;transition:background .15s;flex-shrink:0}',
    '.spw-back:hover{background:rgba(255,255,255,.32)}',

    // Body
    '.spw-body{padding:20px}',

    // Summary pill
    '.spw-sum{font-size:12px;color:#6B7A99;margin-bottom:14px;background:#F4F6FF;border-radius:var(--spw-r-sm,8px);padding:8px 12px;line-height:1.6;border:1px solid #E0E5F2}',
    '.spw-sum strong{color:#1C1C28}',

    // ── Month calendar ──
    '.spw-cal-nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}',
    '.spw-cal-nav button{background:#F4F6FF;border:1px solid #E0E5F2;border-radius:var(--spw-r-sm,8px);width:32px;height:32px;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;color:#6B7A99;transition:all .12s}',
    '.spw-cal-nav button:hover{background:var(--spw-primary-lt);border-color:var(--spw-primary,#2863E0);color:var(--spw-primary,#2863E0)}',
    '.spw-cal-title{font-weight:700;font-size:14px;color:#1C1C28;letter-spacing:-.01em}',
    '.spw-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px;text-align:center}',
    '.spw-dow{font-size:10px;font-weight:700;color:#94A3B8;padding:4px 0;text-transform:uppercase;letter-spacing:.06em}',
    '.spw-day{padding:7px 2px;border-radius:var(--spw-r-sm,8px);font-size:13px;color:#CBD5E1;cursor:default;transition:all .12s;line-height:1}',
    '.spw-avail{color:#1C1C28;cursor:pointer;font-weight:500}',
    '.spw-avail:hover{background:var(--spw-primary-lt);color:var(--spw-primary,#2863E0)}',
    '.spw-sel-day{background:var(--spw-primary,#2863E0)!important;color:#fff!important;font-weight:700}',
    '.spw-today{font-weight:700;color:var(--spw-primary,#2863E0)}',

    // ── Week strip ──
    '.spw-week-strip{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-bottom:6px}',
    '.spw-wday{display:flex;flex-direction:column;align-items:center;gap:4px;padding:6px 2px;border-radius:var(--spw-r-sm,8px);cursor:default;transition:all .12s}',
    '.spw-wday-lbl{font-size:10px;font-weight:700;color:#94A3B8;text-transform:uppercase;letter-spacing:.06em}',
    '.spw-wday-num{font-size:14px;font-weight:500;color:#CBD5E1;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;transition:all .12s}',
    '.spw-wday-avail{cursor:pointer}',
    '.spw-wday-avail .spw-wday-num{color:#1C1C28;font-weight:600}',
    '.spw-wday-avail:hover .spw-wday-num{background:var(--spw-primary-lt);color:var(--spw-primary)}',
    '.spw-wday-sel .spw-wday-num{background:var(--spw-primary)!important;color:#fff!important}',
    '.spw-wday-today .spw-wday-lbl{color:var(--spw-primary)}',
    '.spw-wday-today .spw-wday-num{font-weight:700;color:var(--spw-primary)}',

    // No slots / loading
    '.spw-no-slots{text-align:center;color:#94A3B8;font-size:13px;padding:24px 0;margin:8px 0 0}',
    '.spw-loading{text-align:center;color:#94A3B8;font-size:13px;padding:20px 0;animation:spw-pulse 1.2s ease-in-out infinite}',
    '@keyframes spw-pulse{0%,100%{opacity:1}50%{opacity:.35}}',

    // Workers
    '.spw-workers{display:flex;flex-direction:column;gap:8px}',
    '.spw-wcard{display:flex;align-items:center;gap:14px;padding:12px 14px;border:1.5px solid #E0E5F2;border-radius:var(--spw-r,12px);cursor:pointer;transition:all .15s;background:#fff}',
    '.spw-wcard:hover{border-color:var(--spw-primary,#2863E0);box-shadow:0 0 0 3px var(--spw-primary-lt)}',
    '.spw-av{width:44px;height:44px;border-radius:50%;background:var(--spw-primary-lt,#dbeafe);color:var(--spw-primary,#2863E0);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:17px;flex-shrink:0}',
    '.spw-wi h3{margin:0 0 2px;font-size:13px;font-weight:600;color:#1C1C28}',
    '.spw-wi p{margin:0;font-size:12px;color:#6B7A99}',
    '.spw-wi-arrow{margin-left:auto;color:#CBD5E1;font-size:18px}',

    // Slots
    '.spw-slots{display:grid;grid-template-columns:repeat(3,1fr);gap:7px}',
    '.spw-slot{padding:9px 0;border:1.5px solid #E0E5F2;border-radius:var(--spw-r-sm,8px);background:#fff;cursor:pointer;font-size:13px;font-weight:600;color:#1C1C28;text-align:center;transition:all .12s}',
    '.spw-slot:hover{border-color:var(--spw-primary,#2863E0);background:var(--spw-primary-lt);color:var(--spw-primary)}',

    // Form
    '.spw-form label{display:block;font-size:11px;font-weight:700;color:#6B7A99;margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em}',
    '.spw-field{margin-bottom:12px}',
    '.spw-form input,.spw-form textarea{width:100%;box-sizing:border-box;padding:9px 12px;border:1.5px solid #E0E5F2;border-radius:var(--spw-r-sm,8px);font-size:13px;font-family:var(--spw-font,inherit);outline:none;transition:border-color .12s,box-shadow .12s;color:#1C1C28;background:#fff}',
    '.spw-form input:focus,.spw-form textarea:focus{border-color:var(--spw-primary,#2863E0);box-shadow:0 0 0 3px var(--spw-primary-lt)}',
    '.spw-form textarea{resize:vertical;min-height:64px}',
    '.spw-btn{width:100%;padding:11px;background:var(--spw-primary,#2863E0);color:#fff;border:none;border-radius:var(--spw-r-sm,8px);font-size:14px;font-weight:700;cursor:pointer;margin-top:4px;transition:background .12s;letter-spacing:.01em}',
    '.spw-btn:hover{background:var(--spw-primary-dk,#1E4FC2)}',
    '.spw-btn:disabled{background:#94A3B8;cursor:not-allowed}',
    '.spw-err{color:#EF4444;font-size:12px;margin:6px 0 0;min-height:16px}',

    // Confirmed
    '.spw-ok{text-align:center;padding:12px 0}',
    '.spw-ok-icon{width:64px;height:64px;border-radius:50%;background:var(--spw-primary-lt);color:var(--spw-primary);font-size:26px;display:flex;align-items:center;justify-content:center;margin:0 auto 16px}',
    '.spw-ok h3{margin:0 0 8px;font-size:18px;font-weight:700;color:#1C1C28;letter-spacing:-.01em}',
    '.spw-ok p{margin:0 0 4px;color:#6B7A99;font-size:13px;line-height:1.6}',
    '.spw-new{margin-top:18px;padding:9px 22px;background:#F4F6FF;border:1.5px solid #E0E5F2;border-radius:var(--spw-r-sm,8px);cursor:pointer;font-size:13px;font-weight:600;color:#1C1C28;transition:all .12s}',
    '.spw-new:hover{border-color:var(--spw-primary);color:var(--spw-primary);background:var(--spw-primary-lt)}',

    // Payment step
    '.spw-payment{padding:4px 0}',
    '.spw-price-badge{text-align:center;margin-bottom:18px}',
    '.spw-price-amount{font-size:28px;font-weight:800;color:#1C1C28;letter-spacing:-.02em}',
    '.spw-price-label{font-size:12px;color:#6B7A99;margin-top:2px}',
    '.spw-pay-methods{display:flex;flex-direction:column;gap:10px;margin-bottom:14px}',
    '.spw-pay-btn{width:100%;padding:12px;border:1.5px solid #E0E5F2;border-radius:var(--spw-r-sm,8px);background:#fff;cursor:pointer;font-size:14px;font-weight:600;color:#1C1C28;text-align:center;transition:all .12s;display:flex;align-items:center;justify-content:center;gap:8px}',
    '.spw-pay-btn:hover{border-color:var(--spw-primary,#2863E0);box-shadow:0 0 0 3px var(--spw-primary-lt)}',
    '.spw-pay-btn:disabled{opacity:.55;cursor:not-allowed}',
    '.spw-pay-btn-primary{background:var(--spw-primary,#2863E0);color:#fff;border-color:var(--spw-primary,#2863E0)}',
    '.spw-pay-btn-primary:hover{background:var(--spw-primary-dk,#1E4FC2);border-color:var(--spw-primary-dk,#1E4FC2)}',
    '.spw-pay-btn-test{background:#fffbeb;color:#92400e;border-color:#fef08a}',
    '.spw-pay-btn-test:hover{background:#fef9c3;border-color:#fde047}',
    '.spw-stripe-wrap{margin-bottom:12px}',
    '.spw-stripe-el{padding:10px 12px;border:1.5px solid #E0E5F2;border-radius:var(--spw-r-sm,8px);background:#fff;min-height:42px;transition:border-color .12s}',
    '.spw-stripe-el.focused{border-color:var(--spw-primary,#2863E0);box-shadow:0 0 0 3px var(--spw-primary-lt)}',

    // Verify / OTP step
    '.spw-verify{text-align:center;padding:4px 0}',
    '.spw-verify-phone{font-size:13px;color:#6B7A99;margin-bottom:18px}',
    '.spw-verify-phone strong{color:#1C1C28}',
    '.spw-otp-wrap{display:flex;justify-content:center;margin-bottom:16px}',
    '.spw-otp{width:120px;text-align:center;font-size:26px;font-weight:700;letter-spacing:8px;padding:10px 8px;border:2px solid #E0E5F2;border-radius:var(--spw-r-sm,8px);outline:none;color:#1C1C28;font-family:monospace;transition:border-color .12s}',
    '.spw-otp:focus{border-color:var(--spw-primary,#2863E0);box-shadow:0 0 0 3px var(--spw-primary-lt)}',
    '.spw-call-link{display:inline-block;margin-top:12px;font-size:12px;color:var(--spw-primary,#2863E0);cursor:pointer;text-decoration:underline;text-underline-offset:2px;background:none;border:none;padding:0}',
    '.spw-call-link:hover{opacity:.8}',
    '.spw-resend{display:inline-block;margin-top:6px;font-size:12px;color:#94A3B8;cursor:pointer;background:none;border:none;padding:0;text-decoration:underline;text-underline-offset:2px}',
  ].join('');

  if (!document.getElementById('spw-styles')) {
    var style = document.createElement('style');
    style.id  = 'spw-styles';
    style.textContent = CSS;
    document.head.appendChild(style);
  }

  // ── Constants ─────────────────────────────────────────────────────────────
  var MONTHS = ['January','February','March','April','May','June',
                'July','August','September','October','November','December'];
  var DOWS   = ['Mo','Tu','We','Th','Fr','Sa','Su'];

  // ── State ─────────────────────────────────────────────────────────────────
  var s = {
    step:        'loading',  // loading | calendar | week | workers | slots | form | payment | verify | confirmed
    year:        0,
    month:       0,
    weekBase:    null,       // Date object: Monday of displayed week (week mode)
    availDates:  [],
    date:        null,
    workers:     null,
    worker:      null,
    slots:       null,
    slot:        null,
    booking:     null,
    pendingId:   null,       // OTP session token
    verifyPhone: null,       // formatted phone shown on verify screen
    formData:    null,       // cached form values while user is on verify step
  };

  // ── DOM helpers ────────────────────────────────────────────────────────────
  function el(tag, cls, txt) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (txt != null) e.textContent = txt;
    return e;
  }

  function fmtDate(d) {
    var p = d.split('-');
    return MONTHS[+p[1] - 1] + ' ' + +p[2] + ', ' + p[0];
  }

  function dateToStr(d) {
    return d.getFullYear() + '-' +
      String(d.getMonth() + 1).padStart(2, '0') + '-' +
      String(d.getDate()).padStart(2, '0');
  }

  function weekMonday(from) {
    var d   = new Date(from);
    var day = d.getDay();
    var diff = (day === 0 ? -6 : 1 - day);
    d.setDate(d.getDate() + diff);
    d.setHours(0, 0, 0, 0);
    return d;
  }

  // ── API ────────────────────────────────────────────────────────────────────
  function apiUrl(path) {
    // Automatically append operatorId to every GET request so the server
    // knows which operator's data to return.
    if (!operatorId) return apiBase + path;
    var sep = path.indexOf('?') >= 0 ? '&' : '?';
    return apiBase + path + sep + 'operatorId=' + encodeURIComponent(operatorId);
  }
  function get(path) {
    return fetch(apiUrl(path)).then(function(r){ return r.json(); });
  }
  function post(path, body) {
    return fetch(apiBase + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }).then(function(r){ return r.json(); });
  }

  // ── Render ─────────────────────────────────────────────────────────────────
  var TITLES = {
    loading:   'Book an Appointment',
    calendar:  'Book an Appointment',
    week:      'Book an Appointment',
    workers:   'Choose a Specialist',
    slots:     'Choose a Time',
    form:      'Your Details',
    payment:   'Complete Payment',
    verify:    'Confirm Your Phone',
    confirmed: 'Booking Confirmed',
  };

  function render() {
    container.innerHTML = '';
    var w = el('div', 'spw');
    w.appendChild(renderHeader());
    var b = el('div', 'spw-body');
    if      (s.step === 'loading')   b.appendChild(renderLoading());
    else if (s.step === 'calendar')  b.appendChild(renderCalendar());
    else if (s.step === 'week')      b.appendChild(renderWeek());
    else if (s.step === 'workers')   b.appendChild(renderWorkers());
    else if (s.step === 'slots')     b.appendChild(renderSlots());
    else if (s.step === 'form')      b.appendChild(renderForm());
    else if (s.step === 'payment')   b.appendChild(renderPayment());
    else if (s.step === 'verify')    b.appendChild(renderVerify());
    else if (s.step === 'confirmed') b.appendChild(renderConfirmed());
    w.appendChild(b);
    container.appendChild(w);
    applyCfg();
  }

  function renderLoading() {
    var d = el('div');
    d.appendChild(el('p', 'spw-loading', 'Loading…'));
    return d;
  }

  function renderHeader() {
    var hdr  = el('div', 'spw-hdr');
    var back = ['workers','slots','form','payment','verify'].indexOf(s.step) >= 0;
    if (back) {
      var b = el('button', 'spw-back', '←');
      b.title   = 'Back';
      b.onclick = goBack;
      hdr.appendChild(b);
    } else {
      var icon = el('span', 'spw-hdr-icon', '📅');
      hdr.appendChild(icon);
    }
    hdr.appendChild(el('h2', '', TITLES[s.step] || 'Book an Appointment'));
    return hdr;
  }

  function goBack() {
    var calStep = cfg.viewMode === 'week' ? 'week' : 'calendar';
    var prev = { workers: calStep, slots: 'workers', form: 'slots', payment: 'form', verify: 'form' };
    s.step = prev[s.step] || calStep;
    render();
  }

  // ── Phone helpers ────────────────────────────────────────────────────────────
  function normalizeUkrPhone(phone) {
    var d = phone.replace(/\D/g, '');
    if (d.length === 9)                        d = '380' + d;
    else if (d.length === 10 && d[0] === '0')  d = '38'  + d;
    else if (d.length === 11 && d.substr(0,2) === '80') d = '3' + d;
    return (d.length === 12 && d.substr(0,3) === '380') ? d : null;
  }

  function isUkrPhone(phone) {
    return !!normalizeUkrPhone(phone);
  }

  // ── Month calendar ─────────────────────────────────────────────────────────
  function renderCalendar() {
    var wrap = el('div');
    var nav  = el('div', 'spw-cal-nav');
    var prev = el('button', '', '‹');
    prev.title   = 'Previous month';
    prev.onclick = function() {
      s.month--;
      if (s.month < 0) { s.month = 11; s.year--; }
      loadDates();
    };
    var next = el('button', '', '›');
    next.title   = 'Next month';
    next.onclick = function() {
      s.month++;
      if (s.month > 11) { s.month = 0; s.year++; }
      loadDates();
    };
    nav.appendChild(prev);
    nav.appendChild(el('span', 'spw-cal-title', MONTHS[s.month] + ' ' + s.year));
    nav.appendChild(next);
    wrap.appendChild(nav);

    var grid  = el('div', 'spw-cal-grid');
    DOWS.forEach(function(d) { grid.appendChild(el('div', 'spw-dow', d)); });

    var today    = new Date();
    var firstDay = new Date(s.year, s.month, 1);
    var offset   = (firstDay.getDay() + 6) % 7;
    var days     = new Date(s.year, s.month + 1, 0).getDate();

    for (var i = 0; i < offset; i++) grid.appendChild(el('div', 'spw-day'));

    for (var d = 1; d <= days; d++) {
      var ds    = s.year + '-' +
                  String(s.month + 1).padStart(2, '0') + '-' +
                  String(d).padStart(2, '0');
      var avail = s.availDates.indexOf(ds) >= 0;
      var isTod = today.getFullYear() === s.year &&
                  today.getMonth()    === s.month &&
                  today.getDate()     === d;
      var cls = 'spw-day';
      if (avail)     cls += ' spw-avail';
      if (isTod)     cls += ' spw-today';
      if (ds === s.date) cls += ' spw-sel-day';
      var dayEl = el('div', cls, String(d));
      if (avail) {
        (function(ds_) { dayEl.onclick = function(){ selectDate(ds_); }; })(ds);
      }
      grid.appendChild(dayEl);
    }
    wrap.appendChild(grid);
    if (s.availDates.length === 0) {
      wrap.appendChild(el('p', 'spw-no-slots', 'No available slots this month.'));
    }
    return wrap;
  }

  function loadDates() {
    s.availDates = [];
    render();
    var month = s.year + '-' + String(s.month + 1).padStart(2, '0');
    get('/api/widget/dates?month=' + month).then(function(dates) {
      s.availDates = dates;
      render();
    });
  }

  // ── Week strip ─────────────────────────────────────────────────────────────
  function renderWeek() {
    var wrap = el('div');
    var mon  = s.weekBase;

    // Build 7-day array
    var days = [];
    for (var i = 0; i < 7; i++) {
      var d = new Date(mon);
      d.setDate(mon.getDate() + i);
      days.push(d);
    }

    // Nav
    var nav  = el('div', 'spw-cal-nav');
    var prev = el('button', '', '‹');
    prev.title   = 'Previous week';
    prev.onclick = function() {
      var nb = new Date(s.weekBase);
      nb.setDate(nb.getDate() - 7);
      s.weekBase = nb;
      loadWeekDates();
    };
    var next = el('button', '', '›');
    next.title   = 'Next week';
    next.onclick = function() {
      var nb = new Date(s.weekBase);
      nb.setDate(nb.getDate() + 7);
      s.weekBase = nb;
      loadWeekDates();
    };

    // Week label
    var endDay  = days[6];
    var startLbl = MONTHS[mon.getMonth()].substr(0, 3) + ' ' + mon.getDate();
    var endLbl   = (mon.getMonth() !== endDay.getMonth()
                      ? MONTHS[endDay.getMonth()].substr(0, 3) + ' ' : '') +
                   endDay.getDate() + ', ' + endDay.getFullYear();
    nav.appendChild(prev);
    nav.appendChild(el('span', 'spw-cal-title', startLbl + ' – ' + endLbl));
    nav.appendChild(next);
    wrap.appendChild(nav);

    var today = new Date();
    today.setHours(0, 0, 0, 0);

    var strip = el('div', 'spw-week-strip');
    days.forEach(function(d) {
      var ds    = dateToStr(d);
      var avail = s.availDates.indexOf(ds) >= 0;
      var isTod = d.getTime() === today.getTime();
      var cls   = 'spw-wday';
      if (avail)        cls += ' spw-wday-avail';
      if (isTod)        cls += ' spw-wday-today';
      if (ds === s.date) cls += ' spw-wday-sel';

      var cell = el('div', cls);
      cell.appendChild(el('span', 'spw-wday-lbl', DOWS[days.indexOf(d)]));
      cell.appendChild(el('span', 'spw-wday-num', String(d.getDate())));

      if (avail) {
        (function(ds_) { cell.onclick = function(){ selectDate(ds_); }; })(ds);
      }
      strip.appendChild(cell);
    });
    wrap.appendChild(strip);

    if (s.availDates.length === 0) {
      wrap.appendChild(el('p', 'spw-no-slots', 'No available slots this week.'));
    }
    return wrap;
  }

  function loadWeekDates() {
    s.availDates = [];
    render();
    // Collect unique months spanned by the week
    var months = {};
    for (var i = 0; i < 7; i++) {
      var d = new Date(s.weekBase);
      d.setDate(s.weekBase.getDate() + i);
      var mk = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
      months[mk] = true;
    }
    var keys      = Object.keys(months);
    var remaining = keys.length;
    keys.forEach(function(mk) {
      get('/api/widget/dates?month=' + mk).then(function(dates) {
        s.availDates = s.availDates.concat(dates);
        remaining--;
        if (remaining === 0) render();
      });
    });
  }

  function selectDate(date) {
    s.date    = date;
    s.step    = 'workers';
    s.workers = null;
    render();
    get('/api/widget/workers?date=' + date).then(function(workers) {
      s.workers = workers;
      render();
    });
  }

  // ── Workers ────────────────────────────────────────────────────────────────
  function renderWorkers() {
    var wrap = el('div');
    var sum  = el('p', 'spw-sum');
    sum.innerHTML = '📅 <strong>' + fmtDate(s.date) + '</strong> — pick a specialist';
    wrap.appendChild(sum);

    if (s.workers === null) {
      wrap.appendChild(el('p', 'spw-loading', 'Loading…'));
      return wrap;
    }
    if (s.workers.length === 0) {
      wrap.appendChild(el('p', 'spw-no-slots', 'No specialists available for this date.'));
      return wrap;
    }

    var list = el('div', 'spw-workers');
    s.workers.forEach(function(w) {
      var card   = el('div', 'spw-wcard');
      var avatar = el('div', 'spw-av', w.name.charAt(0).toUpperCase());
      var info   = el('div', 'spw-wi');
      info.appendChild(el('h3', '', w.name));
      info.appendChild(el('p', '', w.title || ''));
      card.appendChild(avatar);
      card.appendChild(info);
      card.appendChild(el('span', 'spw-wi-arrow', '›'));
      (function(worker) { card.onclick = function(){ selectWorker(worker); }; })(w);
      list.appendChild(card);
    });
    wrap.appendChild(list);
    return wrap;
  }

  function selectWorker(worker) {
    s.worker = worker;
    s.step   = 'slots';
    s.slots  = null;
    render();
    get('/api/widget/slots?workerId=' + worker.id + '&date=' + s.date)
      .then(function(slots) { s.slots = slots; render(); });
  }

  // ── Slots ──────────────────────────────────────────────────────────────────
  function renderSlots() {
    var wrap = el('div');
    var sum  = el('p', 'spw-sum');
    sum.innerHTML = '📅 <strong>' + fmtDate(s.date) + '</strong>' +
                    ' with <strong>' + s.worker.name + '</strong>';
    wrap.appendChild(sum);

    if (s.slots === null) {
      wrap.appendChild(el('p', 'spw-loading', 'Loading…'));
      return wrap;
    }
    if (s.slots.length === 0) {
      wrap.appendChild(el('p', 'spw-no-slots', 'No slots available.'));
      return wrap;
    }

    var grid = el('div', 'spw-slots');
    s.slots.forEach(function(slot) {
      var btn = el('button', 'spw-slot', slot.start_time);
      (function(sl) {
        btn.onclick = function(){ s.slot = sl; s.step = 'form'; render(); };
      })(slot);
      grid.appendChild(btn);
    });
    wrap.appendChild(grid);
    return wrap;
  }

  // ── Form ───────────────────────────────────────────────────────────────────
  function renderForm() {
    var wrap = el('div');
    var sum  = el('p', 'spw-sum');
    sum.innerHTML = '📅 <strong>' + fmtDate(s.date) + '</strong>' +
                    ' at <strong>' + s.slot.start_time + '</strong>' +
                    ' with <strong>' + s.worker.name + '</strong>';
    wrap.appendChild(sum);

    var form = el('form', 'spw-form');
    form.onsubmit = submitBooking;

    function field(lbl, name, type, req) {
      var div = el('div', 'spw-field');
      var l   = el('label', '', lbl);
      l.htmlFor = 'spw-' + name;
      var inp;
      if (type === 'textarea') {
        inp = document.createElement('textarea');
      } else {
        inp = document.createElement('input');
        inp.type = type;
      }
      inp.id   = 'spw-' + name;
      inp.name = name;
      if (req) inp.required = true;
      div.appendChild(l);
      div.appendChild(inp);
      return div;
    }

    form.appendChild(field('Full Name *', 'name',  'text',     true));
    form.appendChild(field('Email *',     'email', 'email',    true));
    form.appendChild(field('Phone',       'phone', 'tel',      false));
    form.appendChild(field('Notes',       'notes', 'textarea', false));

    var errEl = el('p', 'spw-err');
    errEl.id  = 'spw-ferr';
    form.appendChild(errEl);

    var btn = el('button', 'spw-btn', 'Confirm Booking');
    btn.type = 'submit';
    form.appendChild(btn);
    wrap.appendChild(form);
    return wrap;
  }

  function submitBooking(e) {
    e.preventDefault();
    var form  = e.target;
    var btn   = form.querySelector('.spw-btn');
    var errEl = document.getElementById('spw-ferr');

    var data = {
      availabilityId: s.slot.id,
      visitorName:    form.querySelector('[name=name]').value,
      visitorEmail:   form.querySelector('[name=email]').value,
      visitorPhone:   form.querySelector('[name=phone]').value,
      notes:          form.querySelector('[name=notes]').value,
    };

    // If payment required → go to payment step first
    var price = parseFloat(s.worker.booking_price) || 0;
    if (s.worker.payment_required && price > 0) {
      s.formData = data;
      s.step     = 'payment';
      render();
      return;
    }

    // If SMS is enabled and the phone looks Ukrainian → go through OTP flow
    if (cfg.smsEnabled && data.visitorPhone && isUkrPhone(data.visitorPhone)) {
      btn.disabled    = true;
      btn.textContent = 'Sending code…';
      errEl.textContent = '';
      sendCode(data, btn, errEl);
      return;
    }

    // Otherwise direct booking
    btn.disabled    = true;
    btn.textContent = 'Booking…';
    errEl.textContent = '';

    post('/api/widget/bookings', data).then(function(res) {
      if (res.error) {
        errEl.textContent   = res.error;
        btn.disabled        = false;
        btn.textContent     = 'Confirm Booking';
      } else {
        s.booking = res;
        s.step    = 'confirmed';
        render();
      }
    }).catch(function() {
      errEl.textContent   = 'Something went wrong. Please try again.';
      btn.disabled        = false;
      btn.textContent     = 'Confirm Booking';
    });
  }

  function sendCode(data, btn, errEl) {
    post('/api/widget/send-code', data).then(function(res) {
      if (res.error) {
        if (errEl) { errEl.textContent = res.error; }
        if (btn)   { btn.disabled = false; btn.textContent = 'Confirm Booking'; }
      } else {
        s.pendingId   = res.pendingId;
        s.verifyPhone = res.phone;
        s.formData    = data;
        s.step        = 'verify';
        render();
      }
    }).catch(function() {
      if (errEl) errEl.textContent = 'Failed to send SMS. Please try again.';
      if (btn)   { btn.disabled = false; btn.textContent = 'Confirm Booking'; }
    });
  }

  // ── Verify / OTP step ───────────────────────────────────────────────────────
  function renderVerify() {
    var wrap = el('div', 'spw-verify');

    var info = el('p', 'spw-verify-phone');
    info.innerHTML = 'Code sent to <strong>' + (s.verifyPhone || '') + '</strong>';
    wrap.appendChild(info);

    var otpWrap = el('div', 'spw-otp-wrap');
    var otp     = document.createElement('input');
    otp.type        = 'text';
    otp.inputMode   = 'numeric';
    otp.pattern     = '[0-9]*';
    otp.maxLength   = 4;
    otp.className   = 'spw-otp';
    otp.id          = 'spw-otp';
    otp.placeholder = '····';
    otp.autocomplete = 'one-time-code';
    otpWrap.appendChild(otp);
    wrap.appendChild(otpWrap);

    var errEl = el('p', 'spw-err');
    errEl.id  = 'spw-verr';
    wrap.appendChild(errEl);

    var btn = el('button', 'spw-btn', 'Verify & Book');
    btn.type = 'button';
    btn.onclick = function() { submitVerify(otp, btn, errEl); };
    wrap.appendChild(btn);

    var actions = el('div', '', '');
    actions.style.cssText = 'text-align:center;margin-top:4px';

    var callBtn = el('button', 'spw-call-link', '📞 Request a phone call instead');
    callBtn.onclick = function() { requestCall(btn, errEl); };
    actions.appendChild(callBtn);

    var br = document.createElement('br');
    actions.appendChild(br);

    var resendBtn = el('button', 'spw-resend', 'Resend code');
    resendBtn.onclick = function() {
      resendBtn.disabled  = true;
      resendBtn.textContent = 'Sending…';
      sendCode(s.formData, null, errEl);
    };
    actions.appendChild(resendBtn);

    wrap.appendChild(actions);

    // Auto-focus & auto-submit on 4 digits
    setTimeout(function() {
      var inp = document.getElementById('spw-otp');
      if (inp) {
        inp.focus();
        inp.addEventListener('input', function() {
          this.value = this.value.replace(/\D/g, '').substr(0, 4);
          if (this.value.length === 4) submitVerify(this, btn, document.getElementById('spw-verr'));
        });
      }
    }, 50);

    return wrap;
  }

  function submitVerify(otpEl, btn, errEl) {
    var code = (typeof otpEl === 'string') ? otpEl : otpEl.value.trim();
    if (code.length !== 4) {
      errEl.textContent = 'Enter the 4-digit code.';
      return;
    }
    btn.disabled    = true;
    btn.textContent = 'Verifying…';
    errEl.textContent = '';

    post('/api/widget/verify-code', {
      pendingId: s.pendingId,
      code:      code,
    }).then(function(res) {
      if (res.error) {
        errEl.textContent   = res.error;
        btn.disabled        = false;
        btn.textContent     = 'Verify & Book';
      } else {
        s.booking = res;
        s.step    = 'confirmed';
        render();
      }
    }).catch(function() {
      errEl.textContent   = 'Something went wrong. Please try again.';
      btn.disabled        = false;
      btn.textContent     = 'Verify & Book';
    });
  }

  function requestCall(btn, errEl) {
    btn.disabled    = true;
    btn.textContent = 'Requesting…';
    errEl.textContent = '';

    post('/api/widget/request-call', {
      pendingId: s.pendingId,
    }).then(function(res) {
      if (res.error) {
        errEl.textContent   = res.error;
        btn.disabled        = false;
        btn.textContent     = 'Verify & Book';
      } else {
        s.booking = res;
        s.step    = 'confirmed';
        render();
      }
    }).catch(function() {
      errEl.textContent   = 'Something went wrong. Please try again.';
      btn.disabled        = false;
      btn.textContent     = 'Verify & Book';
    });
  }

  // ── Payment step ───────────────────────────────────────────────────────────
  function renderPayment() {
    var wrap  = el('div', 'spw-payment');
    var price = parseFloat(s.worker.booking_price) || 0;
    var cur   = paymentCfg.currency || 'USD';

    // Summary pill
    var sum = el('p', 'spw-sum');
    sum.innerHTML = '📅 <strong>' + fmtDate(s.date) + '</strong>' +
                    ' at <strong>' + s.slot.start_time + '</strong>' +
                    ' with <strong>' + s.worker.name + '</strong>';
    wrap.appendChild(sum);

    // Price badge
    var badge = el('div', 'spw-price-badge');
    badge.appendChild(el('div', 'spw-price-amount', cur + ' ' + price.toFixed(2)));
    badge.appendChild(el('div', 'spw-price-label', 'Due now to confirm your booking'));
    wrap.appendChild(badge);

    var methods = el('div', 'spw-pay-methods');
    var errEl   = el('p', 'spw-err');
    errEl.id    = 'spw-perr';

    // ── Test mode ─────────────────────────────────────────────────────────
    if (paymentCfg.testMode) {
      var testBtn = el('button', 'spw-pay-btn spw-pay-btn-test',
                       '🧪 Pay ' + price.toFixed(2) + ' ' + cur + ' (Test Mode)');
      testBtn.type = 'button';
      testBtn.onclick = (function(tb) {
        return function() {
          tb.disabled    = true;
          tb.textContent = 'Processing…';
          errEl.textContent = '';
          doBooking('test_mode', price, cur, tb, errEl);
        };
      })(testBtn);
      methods.appendChild(testBtn);
    }

    // ── Stripe card ───────────────────────────────────────────────────────
    if (!paymentCfg.testMode && paymentCfg.stripeEnabled && paymentCfg.stripePublishableKey) {
      var stripeWrap = el('div', 'spw-stripe-wrap');
      var stripeDiv  = el('div', 'spw-stripe-el');
      stripeDiv.id   = 'spw-stripe-element';
      stripeWrap.appendChild(stripeDiv);
      methods.appendChild(stripeWrap);

      var stripeBtn = el('button', 'spw-pay-btn spw-pay-btn-primary',
                         '💳 Pay with Card · ' + price.toFixed(2) + ' ' + cur);
      stripeBtn.type = 'button';
      stripeBtn.id   = 'spw-stripe-pay-btn';
      (function(sb, p, c) {
        sb.onclick = function() { submitStripePayment(p, c, sb, errEl); };
      })(stripeBtn, price, cur);
      methods.appendChild(stripeBtn);

      // Mount card element once the DOM node exists
      setTimeout(function() { mountStripeCard(); }, 50);
    }

    // ── LiqPay ────────────────────────────────────────────────────────────
    if (!paymentCfg.testMode && paymentCfg.liqpayEnabled) {
      var liqBtn = el('button', 'spw-pay-btn',
                      '🇺🇦 Pay with LiqPay · ' + price.toFixed(2) + ' ' + cur);
      liqBtn.type = 'button';
      (function(lb, p, c) {
        lb.onclick = function() { submitLiqpay(p, c, lb, errEl); };
      })(liqBtn, price, cur);
      methods.appendChild(liqBtn);
    }

    // ── Google Pay (via Stripe Payment Request) ───────────────────────────
    if (!paymentCfg.testMode && paymentCfg.googlePayEnabled &&
        paymentCfg.stripeEnabled && paymentCfg.stripePublishableKey) {
      var gpayDiv = el('div');
      gpayDiv.id  = 'spw-gpay-btn';
      methods.appendChild(gpayDiv);
      setTimeout(function() { mountGooglePay(price, cur, errEl); }, 60);
    }

    wrap.appendChild(methods);
    wrap.appendChild(errEl);
    return wrap;
  }

  // Dynamically load Stripe.js then call cb()
  function loadStripeJs(cb) {
    if (window.Stripe) { cb(); return; }
    var scr   = document.createElement('script');
    scr.src   = 'https://js.stripe.com/v3/';
    scr.async = true;
    scr.onload = cb;
    document.head.appendChild(scr);
  }

  function mountStripeCard() {
    loadStripeJs(function() {
      var elDiv = document.getElementById('spw-stripe-element');
      if (!elDiv) return;
      try {
        _stripe      = Stripe(paymentCfg.stripePublishableKey);
        var elements = _stripe.elements();
        _cardElement = elements.create('card', {
          style: {
            base: {
              fontSize:    '14px',
              color:       '#1C1C28',
              fontFamily:  'system-ui, -apple-system, sans-serif',
              '::placeholder': { color: '#94A3B8' },
            },
            invalid: { color: '#EF4444' },
          },
        });
        _cardElement.mount('#spw-stripe-element');
        _cardElement.on('focus', function() {
          var el = document.getElementById('spw-stripe-element');
          if (el) el.classList.add('focused');
        });
        _cardElement.on('blur', function() {
          var el = document.getElementById('spw-stripe-element');
          if (el) el.classList.remove('focused');
        });
      } catch (e) {
        console.error('[Widget] Stripe mount failed:', e);
      }
    });
  }

  function submitStripePayment(price, cur, btn, errEl) {
    if (!_stripe || !_cardElement) {
      errEl.textContent = 'Card form not ready. Please wait a moment and try again.';
      return;
    }
    btn.disabled    = true;
    btn.textContent = 'Processing…';
    errEl.textContent = '';

    var label = '💳 Pay with Card · ' + price.toFixed(2) + ' ' + cur;

    post('/api/payment/stripe/intent', {
      amount:   Math.round(price * 100),
      currency: (cur || 'USD').toLowerCase(),
    }).then(function(res) {
      if (res.error) {
        errEl.textContent = res.error;
        btn.disabled = false; btn.textContent = label;
        return Promise.resolve(null);
      }
      return _stripe.confirmCardPayment(res.clientSecret, {
        payment_method: { card: _cardElement },
      });
    }).then(function(result) {
      if (!result) return;
      if (result.error) {
        errEl.textContent = result.error.message;
        btn.disabled = false; btn.textContent = label;
      } else if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
        doBooking(result.paymentIntent.id, price, cur, btn, errEl);
      } else {
        errEl.textContent = 'Payment not completed. Please try again.';
        btn.disabled = false; btn.textContent = label;
      }
    }).catch(function() {
      errEl.textContent = 'Payment failed. Please try again.';
      btn.disabled = false; btn.textContent = label;
    });
  }

  function submitLiqpay(price, cur, btn, errEl) {
    btn.disabled    = true;
    btn.textContent = 'Redirecting to LiqPay…';
    errEl.textContent = '';

    var label = '🇺🇦 Pay with LiqPay · ' + price.toFixed(2) + ' ' + cur;

    post('/api/payment/liqpay/form', {
      amount:      price,
      currency:    cur || 'UAH',
      description: 'Booking: ' + s.worker.name + ' on ' + s.date + ' at ' + s.slot.start_time,
      workerId:    s.worker.id,
      slotId:      s.slot.id,
    }).then(function(res) {
      if (res.error) {
        errEl.textContent = res.error;
        btn.disabled = false; btn.textContent = label;
        return;
      }
      // Build and submit a hidden form to LiqPay checkout
      var lf = document.createElement('form');
      lf.method = 'POST';
      lf.action = 'https://www.liqpay.ua/api/3/checkout';
      lf.target = '_blank';
      ['data', 'signature'].forEach(function(k) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = k; inp.value = res[k];
        lf.appendChild(inp);
      });
      document.body.appendChild(lf);
      lf.submit();
      document.body.removeChild(lf);
      btn.disabled = false; btn.textContent = label;
    }).catch(function() {
      errEl.textContent = 'LiqPay unavailable. Please try again.';
      btn.disabled = false; btn.textContent = label;
    });
  }

  function mountGooglePay(price, cur, errEl) {
    loadStripeJs(function() {
      try {
        if (!_stripe) _stripe = Stripe(paymentCfg.stripePublishableKey);
        var paymentRequest = _stripe.paymentRequest({
          country:  'UA',
          currency: (cur || 'USD').toLowerCase(),
          total:    { label: 'Booking — ' + s.worker.name, amount: Math.round(price * 100) },
          requestPayerName:  false,
          requestPayerEmail: false,
        });
        var gpayContainer = document.getElementById('spw-gpay-btn');
        if (!gpayContainer) return;
        var elements = _stripe.elements();
        var prButton = elements.create('paymentRequestButton', {
          paymentRequest: paymentRequest,
          style: { paymentRequestButton: { height: '44px' } },
        });

        paymentRequest.canMakePayment().then(function(result) {
          if (result && gpayContainer) {
            prButton.mount('#spw-gpay-btn');
          } else {
            if (gpayContainer) gpayContainer.style.display = 'none';
          }
        });

        paymentRequest.on('paymentmethod', function(ev) {
          post('/api/payment/stripe/intent', {
            amount:   Math.round(price * 100),
            currency: (cur || 'USD').toLowerCase(),
          }).then(function(res) {
            if (res.error) { ev.complete('fail'); errEl.textContent = res.error; return null; }
            return _stripe.confirmCardPayment(
              res.clientSecret,
              { payment_method: ev.paymentMethod.id },
              { handleActions: false }
            );
          }).then(function(confirmResult) {
            if (!confirmResult) return;
            if (confirmResult.error) {
              ev.complete('fail');
              errEl.textContent = confirmResult.error.message;
            } else if (confirmResult.paymentIntent.status === 'requires_action') {
              ev.complete('success');
              _stripe.confirmCardPayment(confirmResult.paymentIntent.client_secret)
                .then(function(r) {
                  if (r.error) errEl.textContent = r.error.message;
                  else doBooking(r.paymentIntent.id, price, cur, null, errEl);
                });
            } else {
              ev.complete('success');
              doBooking(confirmResult.paymentIntent.id, price, cur, null, errEl);
            }
          }).catch(function() {
            ev.complete('fail');
            errEl.textContent = 'Payment failed. Please try again.';
          });
        });
      } catch (e) {
        console.error('[Widget] Google Pay mount failed:', e);
      }
    });
  }

  // Send booking to server after successful payment (or test mode)
  function doBooking(paymentId, amountPaid, currency, btn, errEl) {
    var data = {};
    // Copy all formData fields
    var fd = s.formData || {};
    for (var k in fd) { if (Object.prototype.hasOwnProperty.call(fd, k)) data[k] = fd[k]; }
    data.paymentId  = paymentId;
    data.amountPaid = amountPaid;
    data.currency   = currency;

    post('/api/widget/bookings', data).then(function(res) {
      if (res.error) {
        if (errEl) errEl.textContent = res.error;
        if (btn)   { btn.disabled = false; btn.textContent = 'Retry'; }
      } else {
        s.booking = res;
        s.step    = 'confirmed';
        render();
      }
    }).catch(function() {
      if (errEl) errEl.textContent = 'Something went wrong. Please try again.';
      if (btn)   { btn.disabled = false; btn.textContent = 'Retry'; }
    });
  }

  // ── Confirmed ──────────────────────────────────────────────────────────────
  function renderConfirmed() {
    var wrap = el('div', 'spw-ok');
    var icon = el('div', 'spw-ok-icon', '✓');
    wrap.appendChild(icon);
    wrap.appendChild(el('h3', '', 'Booking Confirmed!'));
    var p = el('p', '', 'Your appointment is set for ' +
      fmtDate(s.date) + ' at ' + s.slot.start_time +
      ' with ' + s.worker.name + '.');
    wrap.appendChild(p);
    wrap.appendChild(el('p', '', 'We\'ll see you soon!'));
    var nb = el('button', 'spw-new', '+ Book Another Appointment');
    nb.onclick = function() {
      s.step = cfg.viewMode === 'week' ? 'week' : 'calendar';
      s.date = null; s.worker = null; s.slot = null; s.booking = null;
      if (cfg.viewMode === 'week') loadWeekDates();
      else loadDates();
    };
    wrap.appendChild(nb);
    return wrap;
  }

  // ── Bootstrap ──────────────────────────────────────────────────────────────
  render(); // show spinner immediately

  // Fetch widget config and payment config in parallel
  Promise.all([
    get('/api/widget/config').catch(function() { return {}; }),
    get('/api/payment/config').catch(function() { return {}; }),
  ]).then(function(results) {
    var c = results[0] || {};
    var p = results[1] || {};

    // Apply widget config
    cfg.primaryColor    = c.primaryColor    || cfg.primaryColor;
    cfg.headerGradient  = (c.headerGradient  !== undefined) ? c.headerGradient  : cfg.headerGradient;
    cfg.fontFamily      = c.fontFamily      || cfg.fontFamily;
    cfg.borderRadius    = (c.borderRadius   !== undefined) ? c.borderRadius    : cfg.borderRadius;
    cfg.viewMode        = c.viewMode        || cfg.viewMode;
    cfg.smsEnabled      = !!c.smsEnabled;
    cfg.bgColor         = c.bgColor         || cfg.bgColor;
    cfg.textColor       = c.textColor       || cfg.textColor;
    cfg.shadowIntensity = c.shadowIntensity || cfg.shadowIntensity;

    // Apply payment config
    paymentCfg.testMode             = !!p.testMode;
    paymentCfg.stripeEnabled        = !!p.stripeEnabled;
    paymentCfg.stripePublishableKey = p.stripePublishableKey || '';
    paymentCfg.liqpayEnabled        = !!p.liqpayEnabled;
    paymentCfg.liqpayPublicKey      = p.liqpayPublicKey || '';
    paymentCfg.googlePayEnabled     = !!p.googlePayEnabled;
    paymentCfg.googlePayMerchantId  = p.googlePayMerchantId || '';
    paymentCfg.currency             = p.currency || 'USD';

    // Pre-load Stripe.js in the background if needed (so card mounts instantly later)
    if (paymentCfg.stripeEnabled && paymentCfg.stripePublishableKey) {
      loadStripeJs(function() {});
    }

    var now    = new Date();
    s.year     = now.getFullYear();
    s.month    = now.getMonth();
    s.weekBase = weekMonday(now);

    if (cfg.viewMode === 'week') {
      s.step = 'week';
      loadWeekDates();
    } else {
      s.step = 'calendar';
      loadDates();
    }
  });

})();
