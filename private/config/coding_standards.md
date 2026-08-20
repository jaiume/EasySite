# Coding standards

How to write HTML, CSS, PHP, and JavaScript for the Tash Inc Consulting brochure. Prefer existing files and patterns over new architecture.

Every page and layout **must** be mobile- and tablet-friendly. Desktop-only designs are not acceptable.

## Stack

- HTML, CSS, PHP, and JavaScript are all fine.
- Shared `includes/header.php` and `includes/footer.php`, site CSS in `css/`, site JS in `js/`, images in `images/`.
- No build step, no npm, no Composer on the brochure. Nothing that has to be installed on the server.
- Client-side libraries and frameworks are fine when loaded from a public CDN (for example Bootstrap, Alpine, htmx). Pin a version in the URL. Prefer a `integrity` and `crossorigin` attribute on CDN `<script>` and `<link>` tags.
- Keep each page small. Change one page (or the include/CSS/JS it needs) rather than rewriting the whole site.

## Page shape

- Every public page sets `$title`, `require`s the header, writes the main content, then `require`s the footer.
- Do not duplicate `<html>`, `<head>`, or site chrome on a page.
- New pages the owner requested should be linked from the header nav.

## HTML

- Valid HTML5. Keep `lang` on `<html>` in the header.
- One `<h1>` per page. Use heading levels in order (`h2` then `h3`). Do not skip levels for style.
- Semantic elements: `header`, `nav`, `main`, `footer`; `article`/`section` only when they help. Lists for lists, `blockquote` for quotes.
- Unique `<title>` per page via `$title`. A unique meta description is fine if you add a `$description` variable in the header and escape it.
- Visible link text that makes sense out of context (not “click here”).
- Buttons submit forms; links navigate.

## Accessibility

- Every image needs a meaningful `alt`. Decorative images: `alt=""`.
- Form fields have a `<label for="…">` matching the control `id`. Do not rely on placeholder as the only label.
- Do not remove focus outlines. If you restyle `:focus`, keep a clear visible ring.
- Colour is not the only way to convey meaning.
- Primary controls should be easy to tap on a phone (about 44px).
- A collapsed small-screen menu must be keyboard-operable and have an accessible name.

## Responsive layout (required)

The site must work well on a phone (~390px), a tablet (~768px), and a desktop. Check all three when you change layout or CSS.

- Mobile-first. Default styles for a narrow column, then `min-width` media queries if needed.
- Nav, forms, tables, and grids must remain usable on a phone: wrap, stack, or scroll inside a component — never force the whole page to scroll sideways.
- The current layout is a centred column (`max-width: 52rem`). A wider desktop layout is fine if it still stacks cleanly on tablet and phone.
- Images: `max-width: 100%; height: auto`.
- Keep the `viewport` meta in the header. Do not disable zoom.
- Touch targets stay large enough to tap. Hover-only actions need an equivalent for touch.

## CSS

- Site-specific rules live in `css/site.css` (or extra stylesheets under `css/` if a page truly needs them). Reuse the custom properties (`--ink`, `--muted`, `--paper`, `--accent`) before adding new colours.
- Serif body is the current type. Do not switch to a decorative webfont unless that is the design.
- Prefer classes over IDs. Avoid `!important`. Avoid inline `style=` except rare one-offs.
- A CSS framework from a CDN is allowed. Keep custom rules in `css/` so they stay easy to change.
- This host caches `.css` and `.js` for a very long time. After changing a stylesheet or script, bump the URL with a `?v=` filemtime (see the header/footer) so the preview does not keep the old file.

## PHP

- Escape untrusted strings on output: `htmlspecialchars($value, ENT_QUOTES)`.
- Validate and sanitise form input. Email: `filter_var($email, FILTER_VALIDATE_EMAIL)`.
- No `eval`, no user-controlled `include`/`require` paths, no SQL (this site has no database).
- Contact mail keeps server-side validation. Do not print submitted HTML unescaped.
- PHP 8.2. Pages do not need `declare`; use it only on a new PHP helper.

## Images

- Site images live under `images/`.
- Prefer a reasonable file size. Do not use a multi-megabyte original as a full-width hero when a smaller file will do.
- Do not hotlink third-party hosts for standing site art.

## JavaScript

- Vanilla JS in `js/` is fine. A JS library or framework from a CDN is fine (same pinning and SRI rules as CSS).
- Load page scripts from the footer unless a library must be in `<head>` (for example a CSS framework’s JS that the layout needs immediately).
- Same cache-bust as CSS: `js/nav.js?v=` plus filemtime.
- Do not add a bundler or install step. Do not drop a large unused library “just in case”.
