// Общий ползунок прокрутки вместо браузерного: тонкая направляющая и бордовая «бусина» на ней.
// Тянется мышью, клик по дорожке прокручивает к месту, дружит с Lenis (window.__lenis). На телефоне его нет.
(() => {
  const root = document.documentElement;
  const style = document.createElement('style');
  style.textContent = `
html{scrollbar-width:none}
html::-webkit-scrollbar{display:none}
.sb{position:fixed;top:8px;bottom:8px;right:3px;z-index:450;width:18px;display:flex;justify-content:center;opacity:0;pointer-events:none;transition:opacity .6s cubic-bezier(.16,1,.3,1)}
.sb.is-on{opacity:1;pointer-events:auto}
.sb::before{content:'';position:absolute;top:0;bottom:0;width:1px;background:rgba(107,45,51,.16);transition:background-color .4s}
.sb-th{position:absolute;top:0;width:6px;min-height:44px;border-radius:6px;background:#6B2D33;box-shadow:0 0 0 1px rgba(239,235,228,.55);opacity:.62;cursor:grab;will-change:transform;transition:width .35s cubic-bezier(.16,1,.3,1),opacity .4s}
.sb.is-active .sb-th,.sb:hover .sb-th,.sb.is-drag .sb-th{opacity:1}
.sb:hover::before,.sb.is-drag::before{background:rgba(107,45,51,.3)}
.sb:hover .sb-th,.sb.is-drag .sb-th{width:9px}
.sb-drag,.sb-drag *{user-select:none !important;cursor:grabbing !important}
@media (hover:none) and (pointer:coarse){.sb{display:none}}
@media (prefers-reduced-motion:reduce){.sb,.sb *,.sb::before{transition:none !important}}`;
  document.head.appendChild(style);
  if (matchMedia('(hover:none) and (pointer:coarse)').matches) return;   // телефон и планшет: листают пальцем, ползунок не нужен

  const sb = document.createElement('div');
  sb.className = 'sb';
  sb.setAttribute('aria-hidden', 'true');
  const th = document.createElement('div');
  th.className = 'sb-th';
  sb.appendChild(th);
  document.body.appendChild(sb);

  let trackH = 0, thumbH = 0, max = 0, idle;
  const measure = () => {
    trackH = sb.clientHeight;
    const docH = root.scrollHeight;
    max = Math.max(0, docH - innerHeight);
    thumbH = Math.max(36, trackH * innerHeight / docH);
    th.style.height = thumbH + 'px';
    update();
  };
  const update = () => {
    sb.classList.toggle('is-on', !root.classList.contains('is-locked') && max > 0);
    const y = max ? Math.min(1, scrollY / max) * (trackH - thumbH) : 0;
    th.style.transform = `translateY(${y}px)`;
  };
  const scrollToY = (y, immediate) => {
    y = Math.max(0, Math.min(max, y));
    if (window.__lenis) window.__lenis.scrollTo(y, { immediate });
    else scrollTo({ top: y, behavior: immediate ? 'auto' : 'smooth' });
  };

  addEventListener('scroll', () => {
    update();
    sb.classList.add('is-active');
    clearTimeout(idle);
    idle = setTimeout(() => sb.classList.remove('is-active'), 900);
  }, { passive: true });
  addEventListener('resize', measure);
  new ResizeObserver(measure).observe(document.body);
  new MutationObserver(update).observe(root, { attributes: true, attributeFilter: ['class'] });

  // Перетаскивание ползунка
  th.addEventListener('pointerdown', e => {
    e.preventDefault();
    th.setPointerCapture(e.pointerId);
    const y0 = e.clientY, s0 = scrollY, k = max / Math.max(1, trackH - thumbH);
    sb.classList.add('is-drag'); root.classList.add('sb-drag');
    const move = ev => scrollToY(s0 + (ev.clientY - y0) * k, true);
    const up = () => {
      th.removeEventListener('pointermove', move);
      sb.classList.remove('is-drag'); root.classList.remove('sb-drag');
    };
    th.addEventListener('pointermove', move);
    th.addEventListener('pointerup', up, { once: true });
    th.addEventListener('pointercancel', up, { once: true });
  });
  // Клик по дорожке: плавно туда, где кликнули
  sb.addEventListener('pointerdown', e => {
    if (e.target === th) return;
    const r = sb.getBoundingClientRect();
    scrollToY((e.clientY - r.top - thumbH / 2) / Math.max(1, trackH - thumbH) * max, false);
  });

  measure();
})();
