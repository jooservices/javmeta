/* Zoom viewer for covers: opens on click or Enter/Space on [data-zoom], closes on Esc, backdrop click or the close button. */
let box = null;
let lastFocus = null;

function build() {
  const el = document.createElement('div');
  el.className = 'lb';
  el.hidden = true;
  el.setAttribute('role', 'dialog');
  el.setAttribute('aria-modal', 'true');
  el.setAttribute('aria-label', 'Ảnh bìa');
  el.innerHTML = '<button class="btn lbx" type="button" data-lb-close aria-label="Đóng">✕ Đóng</button><figure><img alt=""><figcaption><span data-lb-cap></span><a target="_blank" rel="noopener noreferrer" data-lb-open>Mở ảnh gốc</a></figcaption></figure>';
  el.addEventListener('click', e => { if (e.target === el || e.target.closest('[data-lb-close]')) closeLightbox(); });
  document.body.appendChild(el);
  return el;
}

export function openLightbox(src, label = '') {
  if (!/^https?:\/\//i.test(src)) return;
  box = box || build();
  lastFocus = document.activeElement;
  const img = box.querySelector('img');
  img.alt = label ? 'Ảnh bìa ' + label : 'Ảnh bìa';
  img.src = src;
  box.querySelector('[data-lb-cap]').textContent = label;
  box.querySelector('[data-lb-open]').href = src;
  box.hidden = false;
  document.body.style.overflow = 'hidden';
  box.querySelector('[data-lb-close]').focus();
}

export function closeLightbox() {
  if (!box || box.hidden) return;
  box.hidden = true;
  box.querySelector('img').removeAttribute('src');
  document.body.style.overflow = '';
  if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
}

export function isLightboxOpen() { return Boolean(box && !box.hidden); }

export function initLightbox() {
  document.addEventListener('click', e => {
    const z = e.target.closest('[data-zoom]');
    if (z) openLightbox(z.dataset.zoom, z.dataset.zoomLabel || '');
  });
  document.addEventListener('keydown', e => {
    if (isLightboxOpen()) {
      if (e.key === 'Escape') { e.preventDefault(); closeLightbox(); }
      else if (e.key === 'Tab') {
        e.preventDefault();
        const items = [...box.querySelectorAll('[data-lb-close], [data-lb-open]')];
        const at = items.indexOf(document.activeElement);
        const next = e.shiftKey ? (at <= 0 ? items.length - 1 : at - 1) : (at === items.length - 1 ? 0 : at + 1);
        items[next].focus();
      }
      return;
    }
    const z = e.target.closest && e.target.closest('[data-zoom]');
    if (z && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openLightbox(z.dataset.zoom, z.dataset.zoomLabel || ''); }
  });
}
