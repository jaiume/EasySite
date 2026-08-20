# Ops notes

Hestia domain: `cp.dev.tashincconsulting.com`
App root: `/home/Tash/web/cp.dev.tashincconsulting.com/private`
Document root: `public_html/`

## Install

```bash
cd /home/Tash/web/cp.dev.tashincconsulting.com/private
php composer.phar install
cp config/config.ini.example config/config.ini
# set [auth] username and password
# set [openrouter] api_key (also available on /cp/settings)
chmod 600 config/config.ini
```

## OpenRouter

Set a monthly key limit of $10 in the OpenRouter dashboard to match `monthly_spend_cap`.

## Vhost (do not put auth files in public_html)

- HTTP auth on `/cp` and `/staging` via Hestia includes (`apache2.ssl.conf_*` / `nginx.ssl.conf_*`), not `.htpasswd` in the site tree.
- `X-Robots-Tag: noindex` on `/staging` only. Live `/` stays indexable.
- Nginx: `X-Accel-Buffering: no` for `/cp/api/chat` (see `nginx.ssl.conf_sse` if present).
- Optional later: staging-only FPM pool with `open_basedir` limited to `public_html/staging`.

## Smoke checklist

1. Open `/cp/login`, sign in.
2. Change a headline in chat; confirm `/staging/` iframe updates.
3. Publish (type PUBLISH); confirm `/` matches.
4. Rollback (type ROLLBACK); confirm previous live content.
5. Confirm `/cp` still works after publish.
6. Hit spend cap (or set cap to 0 temporarily) and confirm a clear failure, no OpenRouter key in page source.
