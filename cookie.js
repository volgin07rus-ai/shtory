// Плашка про cookie и Яндекс Метрику (только боевой сайт, подключает build.py).
// На главной появляется после загрузчика, «Хорошо» запоминается в браузере.
(() => {
  const KEY = 'shtorme-cookie-ok';
  try { if (localStorage.getItem(KEY)) return; } catch (_) { /* хранилище недоступно: покажем плашку */ }

  const style = document.createElement('style');
  style.textContent = `
.ck{position:fixed;left:24px;bottom:calc(24px + env(safe-area-inset-bottom));z-index:460;display:flex;align-items:center;gap:14px 22px;max-width:480px;padding:18px 18px 18px 22px;border:1px solid rgba(42,33,30,.12);border-radius:3px;background:#F6F3EE;box-shadow:0 18px 40px -18px rgba(55,27,28,.38),0 2px 6px rgba(55,27,28,.06);font:400 13.5px/1.5 'Inter',system-ui,-apple-system,'Segoe UI',sans-serif;color:#4A3F3B;opacity:0;transform:translateY(16px);transition:opacity .7s cubic-bezier(.16,1,.3,1),transform .7s cubic-bezier(.16,1,.3,1)}
.ck.is-in{opacity:1;transform:none}
.ck p{margin:0}
.ck a{color:#6B2D33;text-decoration:underline;text-decoration-color:rgba(107,45,51,.4);text-underline-offset:.2em}
.ck button{flex:none;min-height:44px;padding:0 22px;border:0;border-radius:2px;background:#6B2D33;color:#F4EEE6;font:600 11px/1 'Inter',system-ui,sans-serif;letter-spacing:.14em;text-transform:uppercase;cursor:pointer;transition:background-color .3s cubic-bezier(.16,1,.3,1),transform .2s cubic-bezier(.16,1,.3,1)}
.ck button:active{transform:scale(.97)}
.ck a:focus-visible,.ck button:focus-visible{outline:2px solid #6B2D33;outline-offset:3px}
@media (hover:hover) and (pointer:fine){.ck button:hover{background:#4E2627}.ck a:hover{text-decoration-color:#6B2D33}}
@media (max-width:560px){.ck{left:16px;right:16px;bottom:calc(16px + env(safe-area-inset-bottom));max-width:none;flex-wrap:wrap;padding:16px 16px 16px 18px}}`;
  document.head.appendChild(style);

  let shown = false;
  const show = () => {
    if (shown) return; shown = true;
    const el = document.createElement('div');
    el.className = 'ck';
    el.setAttribute('role', 'region');
    el.setAttribute('aria-label', 'Уведомление о cookie');
    el.innerHTML = '<p>Сайт использует cookie и Яндекс Метрику, чтобы мы понимали, как сделать его удобнее. Подробнее в <a href="privacy.html">политике конфиденциальности</a></p><button type="button">Хорошо</button>';
    document.body.appendChild(el);
    requestAnimationFrame(() => requestAnimationFrame(() => el.classList.add('is-in')));
    el.querySelector('button').addEventListener('click', () => {
      try { localStorage.setItem(KEY, '1'); } catch (_) { /* не запомнили: покажем при следующем визите */ }
      el.classList.remove('is-in');
      setTimeout(() => el.remove(), 700);
    });
  };

  // На главной ждём, пока загрузчик закончится (классы is-loading и is-locked сняты), чтобы не перебивать анимацию
  const root = document.documentElement;
  const busy = () => root.classList.contains('is-loading') || root.classList.contains('is-locked');
  const later = () => setTimeout(show, 1200);
  if (!busy()) return later();
  const mo = new MutationObserver(() => { if (!busy()) { mo.disconnect(); later(); } });
  mo.observe(root, { attributes: true, attributeFilter: ['class'] });
  setTimeout(() => { mo.disconnect(); later(); }, 12000);    // страховка, если загрузчик завис
})();
