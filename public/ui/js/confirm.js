import { esc } from './util.js';

/** In-page confirm using the shared overlay. Resolves true/false; Escape and overlay click cancel. */
export function askConfirm({ title, body, confirmLabel = 'Xác nhận', cancelLabel = 'Huỷ', danger = false } = {}) {
  return new Promise(resolve => {
    const ov = document.getElementById?.('ov');
    const dlg = document.getElementById?.('dlg');
    if (!ov || !dlg) { resolve(false); return; }
    const previous = { html: dlg.innerHTML, modal: dlg.getAttribute('aria-modal'), labelled: dlg.getAttribute('aria-labelledby') };
    dlg.setAttribute('role', 'dialog');
    dlg.setAttribute('aria-modal', 'true');
    dlg.setAttribute('aria-labelledby', 'confirm-title');
    dlg.innerHTML = `<h2 id="confirm-title" style="margin:0 0 8px">${esc(title)}</h2>
      <p id="confirm-body" style="margin:0 0 16px;color:var(--mute)">${esc(body)}</p>
      <div class="btn-row">
        <button type="button" class="btn" data-confirm-no>${esc(cancelLabel)}</button>
        <button type="button" class="btn ${danger ? '' : 'pri'}" data-confirm-yes>${esc(confirmLabel)}</button>
      </div>`;
    ov.classList.add('on');
    const yes = dlg.querySelector('[data-confirm-yes]');
    const no = dlg.querySelector('[data-confirm-no]');
    let settled = false;
    const finish = ok => {
      if (settled) return;
      settled = true;
      ov.removeEventListener('click', onOv);
      document.removeEventListener('keydown', onKey);
      ov.classList.remove('on');
      dlg.innerHTML = previous.html;
      if (previous.modal == null) dlg.removeAttribute('aria-modal'); else dlg.setAttribute('aria-modal', previous.modal);
      if (previous.labelled == null) dlg.removeAttribute('aria-labelledby'); else dlg.setAttribute('aria-labelledby', previous.labelled);
      resolve(ok);
    };
    const onOv = e => { if (e.target.id === 'ov') finish(false); };
    const onKey = e => {
      if (e.key === 'Escape') { e.preventDefault(); finish(false); }
      if (e.key === 'Tab') {
        e.preventDefault();
        (document.activeElement === yes ? no : yes).focus();
      }
    };
    yes.addEventListener('click', () => finish(true));
    no.addEventListener('click', () => finish(false));
    ov.addEventListener('click', onOv);
    document.addEventListener('keydown', onKey);
    yes.focus();
  });
}
