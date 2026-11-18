/* Vernal Wishes checkout frame. Card data stays in the Wishes iframe. */
(function () {
  var EXPECTED_ORIGIN = 'https://wishes.inc';
  var EMBED_PATH = '/checkout/embed';

  function vernalWishesMessageAllowed(origin, source, frameWindow, expectedOrigin) {
    if (!frameWindow || source !== frameWindow) return false;
    if (typeof origin !== 'string' || origin !== expectedOrigin) return false;
    return true;
  }
  /* END POLICY */

  var cfg = window.VernalWishesInline || {};
  var box = null;
  var frame = null;
  var ret = null;
  var session = null;
  var bound = false;

  function parseHash() {
    var h = (location.hash || '').replace(/^#/, '');
    if (h.indexOf('wishes-pay=') === -1) return null;
    var p = new URLSearchParams(h);
    return { session: p.get('wishes-pay') || '', ret: p.get('ret') || '' };
  }

  function safeToken(value) {
    return /^[A-Za-z0-9_\-]{8,}$/.test(value || '');
  }

  function sameOrigin(url) {
    try {
      return new URL(url, location.href).origin === location.origin;
    } catch (e) {
      return false;
    }
  }

  function unblock() {
    try {
      var $ = window.jQuery;
      if ($) $('form.checkout').removeClass('processing').unblock();
    } catch (e) {}
  }

  function close() {
    if (box) box.remove();
    box = frame = session = ret = null;
    history.replaceState(null, '', location.pathname + location.search);
  }

  function open(state) {
    if (!safeToken(state.session) || !state.ret || !sameOrigin(state.ret)) return;
    if (box && box.isConnected && frame && frame.isConnected && session === state.session && ret === state.ret) return;
    if (box) box.remove();
    box = frame = null;
    session = state.session;
    ret = state.ret;
    unblock();

    var mount = document.getElementById('wishes-inline-mount');
    var visible = mount && mount.offsetParent !== null;
    box = document.createElement('div');
    box.className = 'wishes-inline';
    var panel = box;
    if (!visible) {
      box.style.cssText = 'position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.45);display:flex;align-items:flex-start;justify-content:center;padding:6vh 12px;overflow:auto';
      panel = document.createElement('div');
      panel.style.cssText = 'background:#fff;border-radius:14px;width:100%;max-width:460px;padding:16px;box-shadow:0 20px 50px rgba(0,0,0,.25)';
      box.appendChild(panel);
    } else {
      box.style.cssText = 'margin-top:12px';
    }

    var cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.textContent = 'Cancel and choose another way to pay';
    cancel.style.cssText = 'background:none;border:0;padding:6px 0;margin-top:6px;font-size:13px;text-decoration:underline;cursor:pointer;color:inherit';
    cancel.addEventListener('click', close);

    frame = document.createElement('iframe');
    var q = new URLSearchParams({
      pk: cfg.pk || '',
      session: state.session,
      inline: '1',
      source: location.origin
    });
    frame.src = EXPECTED_ORIGIN + EMBED_PATH + '?' + q.toString();
    frame.title = 'Secure card payment';
    frame.allow = 'payment *';
    frame.style.cssText = 'width:100%;border:0;min-height:320px;display:block;background:transparent';
    panel.appendChild(frame);
    panel.appendChild(cancel);
    if (visible) mount.appendChild(box);
    else document.body.appendChild(box);
    (visible ? box : panel).scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function onMessage(e) {
    if (!vernalWishesMessageAllowed(e.origin, e.source, frame && frame.contentWindow, EXPECTED_ORIGIN)) return;
    var data = e.data || {};
    if (data.type === 'wishes:checkout:resize' && typeof data.height === 'number') {
      frame.style.height = Math.max(320, Math.min(1400, data.height)) + 'px';
      return;
    }
    if (data.type === 'wishes:checkout' && data.payload && data.payload.approved && data.payload.payment_id) {
      var paymentId = String(data.payload.payment_id);
      if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(paymentId)) return;
      var url = new URL(ret, location.href);
      if (url.origin !== location.origin) return;
      url.searchParams.set('wishes_payment', paymentId);
      location.href = url.toString();
    }
  }

  function syncCheckout() {
    var state = parseHash();
    if (state) open(state);
    else if (box) {
      box.remove();
      box = frame = session = ret = null;
    }
  }

  function bind() {
    if (bound) return;
    bound = true;
    window.addEventListener('message', onMessage);
    window.addEventListener('hashchange', syncCheckout);
    if (window.jQuery) {
      window.jQuery(document.body).on('updated_checkout.vernalWishes', syncCheckout);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', syncCheckout);
    else syncCheckout();
  }

  bind();
})();
