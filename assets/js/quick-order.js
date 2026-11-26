/**
 * Pryviko storefront UI — validate paste + add to WooCommerce cart via REST.
 * Compatible with classic cart and Cart/Checkout Blocks (session cart).
 * v1.1: tabs, share links, last order, Pro CSV/lists upgrade UX.
 */
(function () {
  'use strict';

  function cfg(root) {
    var localized = window.PryvikoQuickOrder || {};
    var fromDom = root
      ? {
          restUrl: root.getAttribute('data-pryviko-rest-url') || '',
          nonce: root.getAttribute('data-pryviko-nonce') || '',
          cartUrl: root.getAttribute('data-pryviko-cart-url') || '',
          isPro: root.getAttribute('data-pryviko-is-pro') === '1',
          upgradeUrl: root.getAttribute('data-pryviko-upgrade-url') || '',
          isLoggedIn: root.getAttribute('data-pryviko-logged-in') === '1',
        }
      : {};
    return {
      restUrl: localized.restUrl || fromDom.restUrl || '/wp-json/pryviko/v1',
      nonce: localized.nonce || fromDom.nonce || '',
      cartUrl: localized.cartUrl || fromDom.cartUrl || '/cart/',
      isPro: typeof localized.isPro === 'boolean' ? localized.isPro : !!fromDom.isPro,
      upgradeUrl: localized.upgradeUrl || fromDom.upgradeUrl || '',
      isLoggedIn:
        typeof localized.isLoggedIn === 'boolean' ? localized.isLoggedIn : !!fromDom.isLoggedIn,
      i18n: localized.i18n || {},
    };
  }

  function t(key, fallback, root) {
    var i18n = cfg(root).i18n || {};
    return i18n[key] || fallback;
  }

  function setStatus(root, message, kind) {
    var el = root.querySelector('.pryviko-quick-order__status');
    if (!el) return;
    el.textContent = message || '';
    el.className = 'pryviko-quick-order__status' + (kind ? ' is-' + kind : '');
  }

  function showToast(root, message) {
    var el = root.querySelector('[data-pryviko-toast]');
    if (!el) return;
    el.hidden = false;
    el.textContent = message;
    clearTimeout(el._pryvikoToast);
    el._pryvikoToast = setTimeout(function () {
      el.hidden = true;
      el.textContent = '';
    }, 2200);
  }

  function renderResults(root, lines) {
    var box = root.querySelector('.pryviko-quick-order__results');
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
      '<table class="pryviko-quick-order__table">' +
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

  function api(path, options, root) {
    var c = cfg(root);
    var headers = options.headers || {};
    if (!(options.body instanceof FormData)) {
      headers['Content-Type'] = headers['Content-Type'] || 'application/json';
    }
    if (c.nonce) {
      headers['X-WP-Nonce'] = c.nonce;
    }
    return fetch(String(c.restUrl).replace(/\/$/, '') + path, {
      method: options.method || 'GET',
      credentials: 'same-origin',
      headers: headers,
      body: options.body,
    }).then(function (res) {
      var ctype = res.headers.get('content-type') || '';
      if (ctype.indexOf('text/csv') !== -1) {
        return res.text().then(function (text) {
          if (!res.ok) throw new Error(t('error', 'Something went wrong.', root));
          return text;
        });
      }
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

  function post(path, body, root) {
    return api(path, { method: 'POST', body: JSON.stringify(body) }, root);
  }

  function pasteValue(root) {
    var ta = root.querySelector('.pryviko-quick-order__paste');
    return ta ? String(ta.value || '').trim() : '';
  }

  function setPasteValue(root, text) {
    var ta = root.querySelector('.pryviko-quick-order__paste');
    if (ta) ta.value = text;
  }

  function linesToPaste(lines) {
    return (lines || [])
      .map(function (l) {
        return (l.sku || '') + ',' + (l.qty || 1);
      })
      .join('\n');
  }

  function poValue(root) {
    var el = root.querySelector('[data-pryviko-po]');
    return el ? String(el.value || '').trim() : '';
  }

  function setPoValue(root, value) {
    var el = root.querySelector('[data-pryviko-po]');
    if (el) el.value = value || '';
  }

  function encodeShare(lines) {
    return (lines || [])
      .filter(function (l) {
        return l && l.ok && l.sku;
      })
      .map(function (l) {
        return encodeURIComponent(l.sku) + ':' + encodeURIComponent(String(l.qty || 1));
      })
      .join(',');
  }

  function decodeShareParam(raw) {
    var out = [];
    String(raw || '')
      .split(',')
      .forEach(function (chunk) {
        chunk = decodeURIComponent(chunk.trim());
        if (!chunk) return;
        var parts = chunk.split(':');
        var sku = (parts[0] || '').trim();
        var qty = parseInt(parts[1] || '1', 10);
        if (!sku || !qty || qty < 1) return;
        out.push({ sku: sku, qty: qty });
      });
    return out;
  }

  function onValidate(root) {
    var payload = pasteValue(root);
    if (!payload) {
      setStatus(root, t('empty', 'Paste at least one SKU line.', root), 'warn');
      return Promise.resolve(null);
    }
    setStatus(root, t('validating', 'Validating SKUs…', root), 'busy');
    return post('/validate', { payload: payload }, root)
      .then(function (data) {
        renderResults(root, data.lines || []);
        var valid = data.valid || 0;
        var count = data.count || 0;
        setStatus(
          root,
          valid + ' / ' + count + ' SKUs ready.',
          valid === count && count > 0 ? 'ok' : 'warn'
        );
        root._pryvikoLastLines = data.lines || [];
        return data;
      })
      .catch(function (err) {
        setStatus(root, err.message || t('error', 'Something went wrong.', root), 'fail');
        return null;
      });
  }

  function onAdd(root) {
    var payload = pasteValue(root);
    if (!payload) {
      setStatus(root, t('empty', 'Paste at least one SKU line.', root), 'warn');
      return;
    }
    setStatus(root, t('adding', 'Adding to cart…', root), 'busy');
    var body = { payload: payload, add_to_cart: true };
    if (cfg(root).isPro) {
      body.po_number = poValue(root);
    }
    post('/quick-order', body, root)
      .then(function (data) {
        var lines = [].concat(data.added || [], data.failed || []);
        renderResults(root, lines);
        root._pryvikoLastLines = lines;
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
          new CustomEvent('wc-blocks_added_to_cart', { detail: { pryviko: true } })
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

  function onShare(root) {
    var lines = root._pryvikoLastLines || [];
    var valid = lines.filter(function (l) {
      return l && l.ok && l.sku;
    });
    if (!valid.length) {
      // Fall back: validate first then share.
      onValidate(root).then(function (data) {
        if (!data) return;
        doCopyShare(root, data.lines || []);
      });
      return;
    }
    doCopyShare(root, valid);
  }

  function doCopyShare(root, lines) {
    var encoded = encodeShare(lines);
    if (!encoded) {
      setStatus(root, t('empty', 'Paste at least one SKU line.', root), 'warn');
      return;
    }
    var url = new URL(window.location.href);
    url.searchParams.set('pryviko', encoded);
    if (cfg(root).isPro) {
      var po = poValue(root);
      if (po) url.searchParams.set('po', po);
      else url.searchParams.delete('po');
    }
    var text = url.toString();
    var done = function () {
      showToast(root, t('linkCopied', 'Order link copied', root));
      setStatus(root, t('linkCopied', 'Order link copied', root), 'ok');
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(function () {
        fallbackCopy(text);
        done();
      });
    } else {
      fallbackCopy(text);
      done();
    }
  }

  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand('copy');
    } catch (e) {}
    document.body.removeChild(ta);
  }

  function consumeShareQuery(root) {
    try {
      var params = new URLSearchParams(window.location.search);
      var raw = params.get('pryviko');
      if (!raw) return;
      var rows = decodeShareParam(raw);
      if (!rows.length) return;
      setPasteValue(root, linesToPaste(rows));
      if (cfg(root).isPro && params.get('po')) {
        setPoValue(root, params.get('po'));
      }
      switchTab(root, 'paste');
      onValidate(root);
    } catch (e) {}
  }

  function onLastOrder(root) {
    setStatus(root, t('validating', 'Validating SKUs…', root), 'busy');
    api('/last-order', { method: 'GET' }, root)
      .then(function (data) {
        if (!data.ok || !(data.lines || []).length) {
          setStatus(root, (data && data.message) || 'No previous orders found.', 'warn');
          return null;
        }
        setPasteValue(root, linesToPaste(data.lines));
        switchTab(root, 'paste');
        return onValidate(root);
      })
      .catch(function (err) {
        setStatus(root, err.message || t('error', 'Something went wrong.', root), 'fail');
      });
  }

  function switchTab(root, name) {
    var c = cfg(root);
    root.querySelectorAll('.pryviko-quick-order__tab').forEach(function (tab) {
      var active = tab.getAttribute('data-pryviko-tab') === name;
      tab.classList.toggle('is-active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    root.querySelectorAll('[data-pryviko-panel]').forEach(function (panel) {
      var active = panel.getAttribute('data-pryviko-panel') === name;
      panel.hidden = !active;
      panel.classList.toggle('is-active', active);
    });

    if ((name === 'csv' || name === 'lists') && !c.isPro) {
      var panel = root.querySelector('[data-pryviko-panel="' + name + '"]');
      if (panel) {
        var card = panel.querySelector('[data-pryviko-upgrade-card]');
        if (card) card.hidden = false;
      }
    }

    if (name === 'lists' && c.isPro && c.isLoggedIn) {
      refreshLists(root);
    }
  }

  function uploadCsv(root, file) {
    if (!cfg(root).isPro) return;
    var fd = new FormData();
    fd.append('file', file);
    setStatus(root, t('validating', 'Validating SKUs…', root), 'busy');
    api('/csv-parse', { method: 'POST', body: fd, headers: {} }, root)
      .then(function (data) {
        renderResults(root, data.lines || []);
        root._pryvikoLastLines = data.lines || [];
        setPasteValue(root, linesToPaste(data.lines || []));
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

  function downloadSampleCsv(root) {
    api('/sample-csv', { method: 'GET' }, root)
      .then(function (text) {
        var blob = new Blob([text], { type: 'text/csv;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'pryviko-sample-order.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(a.href);
      })
      .catch(function (err) {
        setStatus(root, err.message || t('error', 'Something went wrong.', root), 'fail');
      });
  }

  function refreshLists(root) {
    api('/lists', { method: 'GET' }, root)
      .then(function (data) {
        var ul = root.querySelector('[data-pryviko-list-items]');
        if (!ul) return;
        var lists = data.lists || [];
        if (!lists.length) {
          ul.innerHTML = '<li class="pryviko-quick-order__list-empty">No saved lists yet.</li>';
          return;
        }
        ul.innerHTML = lists
          .map(function (list) {
            return (
              '<li data-list-id="' +
              escapeHtml(list.id) +
              '">' +
              '<span>' +
              escapeHtml(list.name) +
              ' (' +
              (list.lines || []).length +
              ')</span>' +
              '<span class="pryviko-quick-order__list-btns">' +
              '<button type="button" data-pryviko-list-load>Load</button>' +
              '<button type="button" data-pryviko-list-delete>Delete</button>' +
              '</span></li>'
            );
          })
          .join('');
      })
      .catch(function () {});
  }

  function saveList(root) {
    var nameEl = root.querySelector('[data-pryviko-list-name]');
    var name = nameEl ? String(nameEl.value || '').trim() : '';
    var lines = (root._pryvikoLastLines || []).filter(function (l) {
      return l && l.sku;
    });
    if (!name) {
      setStatus(root, 'Enter a list name.', 'warn');
      return;
    }
    if (!lines.length) {
      setStatus(root, 'Validate SKUs before saving a list.', 'warn');
      return;
    }
    post(
      '/lists',
      {
        name: name,
        lines: lines.map(function (l) {
          return { sku: l.sku, qty: l.qty || 1, note: l.note || '' };
        }),
      },
      root
    )
      .then(function () {
        if (nameEl) nameEl.value = '';
        setStatus(root, 'List saved.', 'ok');
        refreshLists(root);
      })
      .catch(function (err) {
        setStatus(root, err.message || t('error', 'Something went wrong.', root), 'fail');
      });
  }

  function bind(root) {
    if (root.getAttribute('data-pryviko-bound') === '1') return;
    root.setAttribute('data-pryviko-bound', '1');

    var validateBtn = root.querySelector('.pryviko-quick-order__btn--validate');
    var addBtn = root.querySelector('.pryviko-quick-order__btn--add');
    if (validateBtn) validateBtn.addEventListener('click', function () { onValidate(root); });
    if (addBtn) addBtn.addEventListener('click', function () { onAdd(root); });

    var shareBtn = root.querySelector('[data-pryviko-share]');
    if (shareBtn) shareBtn.addEventListener('click', function () { onShare(root); });

    var lastBtn = root.querySelector('[data-pryviko-last-order]');
    if (lastBtn) lastBtn.addEventListener('click', function () { onLastOrder(root); });

    root.querySelectorAll('.pryviko-quick-order__tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        switchTab(root, tab.getAttribute('data-pryviko-tab'));
      });
    });

    var drop = root.querySelector('[data-pryviko-dropzone]');
    var fileInput = root.querySelector('[data-pryviko-csv-input]');
    if (drop && fileInput) {
      drop.addEventListener('dragover', function (e) {
        e.preventDefault();
        drop.classList.add('is-drag');
      });
      drop.addEventListener('dragleave', function () {
        drop.classList.remove('is-drag');
      });
      drop.addEventListener('drop', function (e) {
        e.preventDefault();
        drop.classList.remove('is-drag');
        var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (f) uploadCsv(root, f);
      });
      fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files[0]) uploadCsv(root, fileInput.files[0]);
      });
    }

    var sample = root.querySelector('[data-pryviko-sample-csv]');
    if (sample) {
      sample.addEventListener('click', function (e) {
        e.preventDefault();
        downloadSampleCsv(root);
      });
    }

    var saveBtn = root.querySelector('[data-pryviko-list-save]');
    if (saveBtn) saveBtn.addEventListener('click', function () { saveList(root); });

    var listUl = root.querySelector('[data-pryviko-list-items]');
    if (listUl) {
      listUl.addEventListener('click', function (e) {
        var tEl = e.target;
        if (!tEl || !tEl.getAttribute) return;
        var li = tEl.closest('[data-list-id]');
        if (!li) return;
        var id = li.getAttribute('data-list-id');
        if (tEl.getAttribute('data-pryviko-list-load') !== null) {
          api('/lists', { method: 'GET' }, root).then(function (data) {
            var list = (data.lists || []).find(function (l) {
              return l.id === id;
            });
            if (!list) return;
            setPasteValue(root, linesToPaste(list.lines || []));
            switchTab(root, 'paste');
            onValidate(root);
          });
        }
        if (tEl.getAttribute('data-pryviko-list-delete') !== null) {
          api(
            '/lists?id=' + encodeURIComponent(id),
            { method: 'DELETE', body: JSON.stringify({ id: id }), headers: { 'Content-Type': 'application/json' } },
            root
          ).then(function () {
            refreshLists(root);
            setStatus(root, 'List deleted.', 'ok');
          });
        }
      });
    }

    consumeShareQuery(root);
  }

  function boot() {
    document.querySelectorAll('[data-pryviko-root]').forEach(bind);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
