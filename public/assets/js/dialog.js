/* LVChat — Discord-style web chat (PHP + SQLite)
 * Copyright (C) LVChat contributors
 * SPDX-License-Identifier: AGPL-3.0-only
 *
 * LVCDialog — a small styled-modal replacement for window.confirm/prompt/alert
 * on pages that don't ship the chat app's own ui* dialog system (admin pages,
 * the user profile page). Native window.prompt() is unsupported in the Electron
 * desktop client (it returns null immediately), which silently broke admin
 * flows there (ban reasons, the legal-page editor's link/image URLs). All three
 * native dialogs route through this one styled modal instead.
 *
 * Promise-based API:
 *   LVCDialog.confirm(message)          -> Promise<boolean>
 *   LVCDialog.prompt(message, initial)  -> Promise<string|null>
 *   LVCDialog.alert(message)            -> Promise<void>
 *
 * Forms carrying data-confirm="message" have their submit intercepted and shown
 * in the confirm modal first. The inline `onsubmit="return confirm()"` pattern
 * can't be made async, so those handlers are replaced with the attribute.
 */
(function () {
  if (window.LVCDialog) return;
  var modal = null;
  var resolver = null;

  function style() {
    if (document.getElementById('lvc-dialog-style')) return;
    var el = document.createElement('style');
    el.id = 'lvc-dialog-style';
    el.textContent = '#lvc-dialog{position:fixed;inset:0;z-index:1200;display:none;align-items:center;justify-content:center;padding:16px;}\n'
      + '#lvc-dialog .lvc-dialog-backdrop{position:absolute;inset:0;background:rgba(0,0,0,0.55);}\n'
      + '#lvc-dialog .lvc-dialog-box{position:relative;width:min(92vw,420px);max-height:90vh;overflow:auto;}\n'
      + '#lvc-dialog .lvc-dialog-message{white-space:pre-wrap;}\n'
      + '#lvc-dialog .lvc-dialog-input{display:none;}\n';
    (document.head || document.documentElement).appendChild(el);
  }

  function ensure() {
    if (modal) return modal;
    style();
    modal = document.createElement('div');
    modal.id = 'lvc-dialog';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.innerHTML =
      '<div class="lvc-dialog-backdrop"></div>' +
      '<div class="lvc-dialog-box card p-6 shadow-2xl">' +
        '<div id="lvc-dialog-title" class="lvc-dialog-title text-lg font-bold text-white mb-2"></div>' +
        '<div class="lvc-dialog-message text-sm text-discord-400 mb-3"></div>' +
        '<input class="lvc-dialog-input input w-full mb-3" type="text" autocomplete="off" spellcheck="false">' +
        '<div class="flex gap-2 justify-end">' +
          '<button type="button" class="lvc-dialog-cancel btn-ghost">Cancel</button>' +
          '<button type="button" class="lvc-dialog-ok btn-primary">OK</button>' +
        '</div>' +
      '</div>';
    modal.setAttribute('aria-labelledby', 'lvc-dialog-title');
    document.body.appendChild(modal);
    modal.querySelector('.lvc-dialog-backdrop').addEventListener('click', settleFalse);
    modal.querySelector('.lvc-dialog-cancel').addEventListener('click', settleFalse);
    modal.querySelector('.lvc-dialog-ok').addEventListener('click', function () {
      var input = modal.querySelector('.lvc-dialog-input');
      settle(input.style.display !== 'none' ? input.value : true);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { settleFalse(); return; }
      // Focus trap: cycle Tab between the interactive elements inside the dialog.
      if (e.key === 'Tab' && modal && modal.style.display === 'flex') {
        var input = modal.querySelector('.lvc-dialog-input');
        var ok = modal.querySelector('.lvc-dialog-ok');
        var cancel = modal.querySelector('.lvc-dialog-cancel');
        var focusable = [cancel, ok];
        if (input.style.display !== 'none') focusable.unshift(input);
        var first = focusable[0], last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    });
    return modal;
  }

  function settleFalse() {
    settle(false);
  }

  function settle(value) {
    if (!modal) return;
    modal.style.display = 'none';
    if (resolver) {
      var r = resolver;
      resolver = null;
      r(value);
    }
  }

  function show(opts) {
    return new Promise(function (resolve) {
      resolver = resolve;
      var m = ensure();
      var title = m.querySelector('.lvc-dialog-title');
      var message = m.querySelector('.lvc-dialog-message');
      var input = m.querySelector('.lvc-dialog-input');
      title.textContent = opts.title || '';
      message.textContent = opts.message || '';
      message.style.display = opts.message ? '' : 'none';
      input.style.display = opts.input ? '' : 'none';
      input.value = opts.initial || '';
      input.type = opts.password ? 'password' : 'text';
      m.querySelector('.lvc-dialog-ok').textContent = opts.okLabel || 'OK';
      var cancel = m.querySelector('.lvc-dialog-cancel');
      cancel.textContent = opts.cancelLabel || 'Cancel';
      cancel.style.display = opts.cancel === false ? 'none' : '';
      m.style.display = 'flex';
      if (opts.input) setTimeout(function () { try { input.focus(); input.select(); } catch (e) {} }, 30);
    });
  }

  window.LVCDialog = {
    confirm: function (message, opts) {
      return show(Object.assign({ title: 'Confirm', message: message, okLabel: 'Yes', cancel: true }, opts || {}))
        .then(function (v) { return v === true; });
    },
    prompt: function (message, initial, opts) {
      return show(Object.assign({ title: message, input: true, initial: initial || '', okLabel: 'OK', cancel: true }, opts || {}))
        .then(function (v) { return v === null || v === false ? null : String(v); });
    },
    alert: function (message, opts) {
      return show(Object.assign({ title: message, okLabel: 'OK', cancel: false }, opts || {}))
        .then(function () {});
    },
    /* Confirm an admin action button, then submit its form while preserving the
     * button's name/value (a programmatic form.submit() would drop them, which
     * is what routes /admin/action). */
    confirmSubmit: function (button, message) {
      return window.LVCDialog.confirm(message).then(function (ok) {
        if (!ok) return false;
        var h = document.createElement('input');
        h.type = 'hidden';
        h.name = button.name;
        h.value = button.value;
        button.form.appendChild(h);
        button.form.submit();
        return true;
      });
    }
  };

  // Intercept form submits carrying data-confirm="message".
  document.addEventListener('submit', function (e) {
    var form = e.target;
    var msg = form && form.dataset ? form.dataset.confirm : null;
    if (!msg) return;
    e.preventDefault();
    window.LVCDialog.confirm(msg).then(function (ok) { if (ok) form.submit(); });
  }, true);
})();
