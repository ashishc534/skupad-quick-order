/**
 * SkuPad storefront UI — validate paste + add to WooCommerce cart via REST.
 * Compatible with classic cart and Cart/Checkout Blocks (session cart).
 */
(function () {
  'use strict';

  function cfg(root) {
    var localized = window.SkuPadQuickOrder || {};
    var fromDom = root
      ? {
          restUrl: root.getAttribute('data-skupad-rest-url') || '',
          nonce: root.getAttribute('data-skupad-nonce') || '',
          cartUrl: root.getAttribute('data-skupad-cart-url') || '',
        }
      : {};
    // Prefer localized script config; fall back to data-* when block themes
    // render the shortcode before wp_localize_script can attach.
    return {
      restUrl: localized.restUrl || fromDom.restUrl || '/wp-json/skupad/v1',
      nonce: localized.nonce || fromDom.nonce || '',
      cartUrl: localized.cartUrl || fromDom.cartUrl || '/cart/',
      i18n: localized.i18n || {},
    };
  }

  function t(key, fallback, root) {
    var i18n = cfg(root).i18n || {};
    return i18n[key] || fallback;
  }

  function setStatus(root, message, kind) {
    var el = root.querySelector('.skupad-quick-order__status');
    if (!el) return;
    el.textContent = message || '';
    el.className = 'skupad-quick-order__status' + (kind ? ' is-' + kind : '');
  }

  function renderResults(root, lines) {
    var box = root.querySelector('.skupad-quick-order__results');
    if (!box) return;
    if (!lines || !lines.length) {
      box.hidden = true;
      box.innerHTML = '';
      return;
    }
    var rows = lines
      .map(function (line) {
        var cls = line.ok ? 'is-ok' : 'is-fail';
        return (
          '<tr class="' +
          cls +
          '">' +
          '<td>' +
          escapeHtml(line.sku || '') +
          '</td>' +
          '<td>' +
          escapeHtml(String(line.qty || '')) +
          '</td>' +
          '<td>' +
          escapeHtml(line.name || '—') +
          '</td>' +
          '<td>' +
          escapeHtml(line.price !== undefined && line.price !== '' ? String(line.price) : '—') +
          '</td>' +
          '<td>' +
          escapeHtml(line.message || '') +
          '</td>' +
          '</tr>'
        );
      })
      .join('');
    box.hidden = false;
    box.innerHTML =
      '<table class="skupad-quick-order__table">' +
      '<thead><tr><th>SKU</th><th>Qty</th><th>Product</th><th>Price</th><th>Status</th></tr></thead>' +
      '<tbody>' +
      rows +
      '</tbody></table>';
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function post(path, body, root) {
    var c = cfg(root);
    var headers = {
      'Content-Type': 'application/json',
    };
    // Never send an empty X-WP-Nonce: for logged-in users WP treats it as
    // present-but-invalid → rest_cookie_invalid_nonce ("Cookie check failed").
    if (c.nonce) {
      headers['X-WP-Nonce'] = c.nonce;
    }
    return fetch(String(c.restUrl).replace(/\/$/, '') + path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers,
      body: JSON.stringify(body),
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok) {
          var msg =
            (data && (data.message || (data.data && data.data.message))) ||
            t('error', 'Something went wrong. Please try again.', root);
          throw new Error(msg);
        }
        return data;
      });
    });
  }

  function pasteValue(root) {
    var ta = root.querySelector('.skupad-quick-order__paste');
    return ta ? String(ta.value || '').trim() : '';
  }

  function onValidate(root) {
    var payload = pasteValue(root);
    if (!payload) {
      setStatus(root, t('empty', 'Paste at least one SKU line.', root), 'warn');
      return;
    }
    setStatus(root, t('validating', 'Validating SKUs…', root), 'busy');
    post('/validate', { payload: payload }, root)
      .then(function (data) {
        renderResults(root, data.lines || []);
        var valid = data.valid || 0;
        var count = data.count || 0;
        setStatus(
          root,
          valid + ' / ' + count + ' SKUs ready.',
          valid === count && count > 0 ? 'ok' : 'warn'
        );
      })
      .catch(function (err) {
        setStatus(root, err.message || t('error', 'Something went wrong.', root), 'fail');
      });
  }

  function onAdd(root) {
    var payload = pasteValue(root);
    if (!payload) {
      setStatus(root, t('empty', 'Paste at least one SKU line.', root), 'warn');
      return;
    }
    setStatus(root, t('adding', 'Adding to cart…', root), 'busy');
    post('/quick-order', { payload: payload, add_to_cart: true }, root)
      .then(function (data) {
        var lines = [].concat(data.added || [], data.failed || []);
        renderResults(root, lines);
        if (data.ok) {
          setStatus(
            root,
            t('done', 'Added to cart.', root) +
              (data.cart_count ? ' (' + data.cart_count + ' items)' : ''),
            'ok'
          );
        } else {
          setStatus(
            root,
            (data.added || []).length +
              ' added, ' +
              (data.failed || []).length +
              ' failed.',
            'warn'
          );
        }
        document.body.dispatchEvent(
          new CustomEvent('wc-blocks_added_to_cart', { detail: { skupad: true } })
        );
        if (typeof jQuery !== 'undefined') {
          jQuery(document.body).trigger('wc_fragment_refresh');
          jQuery(document.body).trigger('added_to_cart');
        }
      })
      .catch(function (err) {
        setStatus(root, err.message || t('error', 'Something went wrong.', root), 'fail');
      });
  }

  function bind(root) {
    if (root.getAttribute('data-skupad-bound') === '1') return;
    root.setAttribute('data-skupad-bound', '1');
    var validateBtn = root.querySelector('.skupad-quick-order__btn--validate');
    var addBtn = root.querySelector('.skupad-quick-order__btn--add');
    if (validateBtn) validateBtn.addEventListener('click', function () { onValidate(root); });
    if (addBtn) addBtn.addEventListener('click', function () { onAdd(root); });
  }

  function boot() {
    document.querySelectorAll('[data-skupad-root]').forEach(bind);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
