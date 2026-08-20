# Project decisions

Recorded 2026-08-19 for the control-site agent harness on `cp.dev.tashincconsulting.com`.

## Confirmed

- Project type: admin tool plus a file-based brochure on one Hestia domain
- PHP 8.2 (this vhost), not 8.3
- Slim 4 + PHP-DI + Composer for `/cp` only; `/` and `/staging` are dumb HTML/PHP files
- Config: `private/config/config.ini` (Hestia `open_basedir`)
- No database; jsonl/JSON under `private/var/`
- Auth: config.ini single user (session + CSRF) plus planned vhost HTTP auth on `/cp` and `/staging`
- `/cp` UI: Bootstrap 5.3.8 CDN + Twig
- No email, mobile API, Docker, or queues
- Tests: PHPUnit for PathGuard, UrlGuard, Publish excludes; manual smoke checklist
- Agent transport: SSE long request
- HTTP tools: public https with SSRF guards; crawls same host + path prefix
- Images always saved under `staging/images/`
- Live OpenRouter catalog for chat and image models; session remembers last pick
- Default login until rotated: username `admin`, password `changeme`

## Deviations from PROJECT_SETUP_GUIDELINES.md

- PHP 8.2 instead of 8.3
- `public_html/` instead of `public/`
- App code under `private/` instead of domain-root `src/` / `config/`
- No MariaDB
- Slim does not own the brochure
