"""Боевая сборка сайта shtorme.ru из макета v2.

Запуск: py build.py ../site [--https]
- пути ../img и ../scrollbar.js становятся img/ и scrollbar.js (макет на volgin.site остаётся как был);
- шрифты и скрипты свои (assets/), без Google Fonts и jsDelivr;
- сайт открыт для поисковиков, есть canonical, превью ссылок, значки, разметка организации;
- форма отправляет заявки в send.php;
- --https включает переадресацию на https (только после выпуска SSL-сертификата).
"""
import json, re, shutil, sys
from pathlib import Path

SRC = Path(__file__).resolve().parent
OUT = Path(sys.argv[1]).resolve()
HTTPS = '--https' in sys.argv
SITE = 'https://shtorme.ru'
TITLE = 'Шторме · шторы, текстиль и мягкая мебель на заказ · Москва'
DESC = 'Салон интерьерного текстиля в Москве: портьеры, римские шторы, жалюзи, декоративный текстиль и мягкая мебель на заказ. Работаем с частными клиентами и дизайнерами.'

def rep(s, a, b, n=1):
    assert s.count(a) == n, f'ожидалось {n}: {a[:70]!r}, найдено {s.count(a)}'
    return s.replace(a, b)

FONTS_GOOGLE = ('<link rel="preconnect" href="https://fonts.googleapis.com">\n'
                '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>\n'
                '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Noto+Serif+Display:ital,wdth,wght@0,62.5..100,300..600;1,62.5..100,300..600&display=swap">\n')
FONTS_LOCAL = ('<link rel="preload" href="fonts/inter-normal-cyrillic.woff2" as="font" type="font/woff2" crossorigin>\n'
               '<link rel="preload" href="fonts/noto-serif-display-normal-cyrillic.woff2" as="font" type="font/woff2" crossorigin>\n'
               '<link rel="stylesheet" href="fonts/fonts.css">\n')
ICONS = ('<link rel="icon" href="img/brand-favicon.svg" type="image/svg+xml">\n'
         '<link rel="apple-touch-icon" href="img/brand-apple-touch.png">\n'
         '<meta name="theme-color" content="#EFEBE4">\n')

def common(s, path):
    s = rep(s, '<meta name="robots" content="noindex, nofollow">\n', f'<link rel="canonical" href="{SITE}/{path}">\n' + ICONS)
    s = rep(s, FONTS_GOOGLE, FONTS_LOCAL)
    s = s.replace('../img/', 'img/')
    assert 'googleapis' not in s and 'jsdelivr' not in s and 'unsplash' not in s, path
    return s

# ---------- Главная ----------
s = (SRC / 'v2/index.html').read_text(encoding='utf-8')
s = rep(s, '<title>Шторме · интерьерный текстиль · макет 2</title>', f'<title>{TITLE}</title>')
s = rep(s, '<meta name="description" content="Портьеры, римские шторы, жалюзи, декоративный текстиль и мягкая мебель на заказ. Москва.">',
        f'<meta name="description" content="{DESC}">\n'
        f'<meta property="og:type" content="website">\n<meta property="og:locale" content="ru_RU">\n'
        f'<meta property="og:site_name" content="Шторме">\n<meta property="og:title" content="Шторме · интерьерный текстиль">\n'
        f'<meta property="og:description" content="{DESC}">\n<meta property="og:url" content="{SITE}/">\n'
        f'<meta property="og:image" content="{SITE}/img/brand-og.jpg">\n<meta property="og:image:width" content="1200">\n<meta property="og:image:height" content="630">\n'
        f'<meta name="twitter:card" content="summary_large_image">')
org = {'@context': 'https://schema.org', '@type': 'HomeGoodsStore', 'name': 'Шторме', 'description': DESC, 'url': SITE + '/',
       'image': SITE + '/img/brand-og.jpg', 'logo': SITE + '/img/logo.svg', 'telephone': ['+7 933 922-08-00', '+7 912 322-08-00'],
       'email': 'shtor.me@yandex.ru',
       'address': {'@type': 'PostalAddress', 'addressLocality': 'Москва', 'addressCountry': 'RU'},
       'sameAs': ['https://www.instagram.com/shtor.me/', 'https://t.me/shtormeru']}
s = rep(s, '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lenis@1.3.23/dist/lenis.css">\n', '<link rel="stylesheet" href="vendor/lenis.css">\n')
for a, b in (('https://cdn.jsdelivr.net/npm/gsap@3.14.2/dist/gsap.min.js', 'vendor/gsap.min.js'),
             ('https://cdn.jsdelivr.net/npm/gsap@3.14.2/dist/ScrollTrigger.min.js', 'vendor/ScrollTrigger.min.js'),
             ('https://cdn.jsdelivr.net/npm/lenis@1.3.23/dist/lenis.min.js', 'vendor/lenis.min.js')):
    s = rep(s, a, b)
s = rep(s, '<script src="../scrollbar.js"></script>', '<script src="scrollbar.js"></script>')
s = common(s, '')
s = rep(s, '</head>', '<script type="application/ld+json">' + json.dumps(org, ensure_ascii=False) + '</script>\n</head>')
s = rep(s, '<form id="rqForm" novalidate data-r>', '<form id="rqForm" novalidate data-r data-endpoint="send.php">')
pages = {'index.html': s}
for name in ('privacy.html', 'consent.html'):
    pages[name] = common((SRC / 'v2' / name).read_text(encoding='utf-8'), name)

# ---------- Папка сайта ----------
OUT.mkdir(parents=True, exist_ok=True)
for old in OUT.iterdir():                       # чистим содержимое, а не саму папку: её может держать локальный сервер
    shutil.rmtree(old) if old.is_dir() else old.unlink()
for name, text in pages.items():
    assert 'jsdelivr' not in text and '../' not in text, name
    (OUT / name).write_text(text, encoding='utf-8')

# фото: всё, на что ссылаются страницы, плюс работы из данных (обе версии, полная и уменьшенная)
imgs = set()
for text in pages.values():
    imgs |= set(re.findall(r'img/[\w/.-]+\.(?:jpg|png|svg)', text))
    imgs |= {f'img/{i}{suf}.jpg' for i in re.findall(r"'([a-z]+/[\w-]+)'", text) for suf in ('', '-s')}
imgs |= {'img/brand-og.jpg', 'img/brand-favicon.svg', 'img/brand-apple-touch.png', 'img/logo.svg'}
copied = 0
for rel in sorted(imgs):
    src = SRC / rel
    if src.exists():
        (OUT / rel).parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(src, OUT / rel); copied += 1
shutil.copytree(SRC / 'assets/fonts', OUT / 'fonts')
shutil.copytree(SRC / 'assets/vendor', OUT / 'vendor')
shutil.copy2(SRC / 'scrollbar.js', OUT / 'scrollbar.js')
for f in ('send.php', '404.html'):
    shutil.copy2(SRC / 'server' / f, OUT / f)

(OUT / 'robots.txt').write_text(f'User-agent: *\nAllow: /\nDisallow: /send.php\n\nSitemap: {SITE}/sitemap.xml\n', encoding='utf-8')
(OUT / 'sitemap.xml').write_text('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'
    + ''.join(f'  <url><loc>{SITE}/{p}</loc></url>\n' for p in ('', 'privacy.html', 'consent.html')) + '</urlset>\n', encoding='utf-8')

scheme = 'https' if HTTPS else 'http'
force_https = ('# Всё на https (сертификат выпущен)\n'
               'RewriteCond %{HTTPS} off\nRewriteCond %{HTTP:X-Forwarded-Proto} !https\n'
               'RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]\n\n') if HTTPS else '# https включится после выпуска SSL-сертификата (сборка с --https)\n\n'
(OUT / '.htaccess').write_text(f"""# shtorme.ru
DirectoryIndex index.html
AddDefaultCharset utf-8
Options -Indexes
ErrorDocument 404 /404.html

RewriteEngine On
# Второй домен и www ведут на shtorme.ru
RewriteCond %{{HTTP_HOST}} ^(www\\.)?shtorme\\.online$ [NC,OR]
RewriteCond %{{HTTP_HOST}} ^www\\.shtorme\\.ru$ [NC]
RewriteRule ^ {scheme}://shtorme.ru%{{REQUEST_URI}} [R=301,L]

{force_https}# /index.html открывается как /
RewriteCond %{{THE_REQUEST}} \\s/index\\.html[\\s?] [NC]
RewriteRule ^index\\.html$ / [R=301,L]

# Служебные файлы недоступны снаружи
<FilesMatch "^\\.|\\.(md|py|log|jsonl)$">
  Require all denied
</FilesMatch>

<IfModule mod_headers.c>
  Header set X-Content-Type-Options "nosniff"
  Header set Referrer-Policy "strict-origin-when-cross-origin"
  Header set X-Frame-Options "SAMEORIGIN"
</IfModule>

<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType text/html "access plus 0 seconds"
  ExpiresByType image/jpeg "access plus 30 days"
  ExpiresByType image/png "access plus 30 days"
  ExpiresByType image/svg+xml "access plus 30 days"
  ExpiresByType font/woff2 "access plus 1 year"
  ExpiresByType text/css "access plus 7 days"
  ExpiresByType application/javascript "access plus 7 days"
  ExpiresByType text/javascript "access plus 7 days"
</IfModule>

<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/css application/javascript text/javascript image/svg+xml application/json text/plain
</IfModule>
""", encoding='utf-8')

size = sum(f.stat().st_size for f in OUT.rglob('*') if f.is_file())
print(f'сборка: {OUT} · страниц {len(pages)} · фото {copied} · всего {size / 1e6:.1f} МБ · https={HTTPS}')
