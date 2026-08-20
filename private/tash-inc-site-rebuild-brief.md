# Builder brief: Tash Inc site rebuild

**For:** rebuild of the Tash Inc Consulting website  
**Host:** existing Hestia CP server (no new hosting)  
**Owner:** non-technical. Daily use is a chat box on the control site, then a Publish button.

## Goal

Replace the current Joomla site (Helix + SP Page Builder) with a file-based site the owner can change in English. She previews on staging, then publishes to live. She does not use Git, Joomla, MCP, ChatGPT Developer Mode, or Claude connectors.

## Constraints

- Stay on the current Hestia box. No extra hosting product (no Webflow, Squarespace, WordPress host).
- OpenRouter API usage is the only expected extra cost. Cap it.
- Do not put a page builder or CMS database in the new site.
- ChatGPT Plus (her $20 app) is unrelated. Do not depend on it.

## Three sites (same Hestia user)

All three domains/subdomains must belong to the **same Hestia account** so PHP can read staging and write live.

| Host | Role |
|---|---|
| `staging.tashincconsulting.com` | Draft site. The agent may edit files here only. |
| `tashincconsulting.com` | Live site. Never edited by the agent. Updated only by Publish. |
| `cp.tashincconsulting.com` | Control app: login, chat agent, image upload, Publish, Rollback, logs. |

Suggested disk layout (adjust to the actual Hestia user):

```
/home/<user>/web/staging.tashincconsulting.com/public_html/   # draft site
/home/<user>/web/tashincconsulting.com/public_html/           # live site
/home/<user>/web/cp.tashincconsulting.com/public_html/        # control UI only
/home/<user>/web/cp.tashincconsulting.com/data/               # chats, logs, backups (not web-root)
/home/<user>/conf/                                            # OpenRouter key, secrets (not web-root)
```

HTTP password / vhost auth for **staging** and **cp** must live in the Hestia vhost config, **not** in `public_html`. Auth files inside the web root would be copied onto live.

Add `X-Robots-Tag: noindex` on the staging vhost (not a `robots.txt` in the files). Live must stay indexable.

## Public site format

Brochure site as HTML/PHP with a shared header/footer include, one CSS file, images in a folder. No build step. Hestia serves it as PHP/static.

Keep pages small so the model rewrites one page, not the whole site. Contact form: small PHP mailer using the existing Hestia mailbox. Bookings/payments: embeds (e.g. Calendly, Stripe Payment Links), do not rebuild those systems.

Preserve current public URLs or add redirects. Inventory the existing Joomla pages, forms, embeds, and SEO URLs before cutover. Keep Joomla as a backup folder until the new live site has been stable for a few weeks.

## Control site: two products

### 1) Agent harness (not MCP)

A logged-in chat UI. Server-side loop:

1. Send conversation + tool definitions to OpenRouter (`POST /api/v1/chat/completions`, OpenAI-shaped `tools`).
2. If the model returns `tool_calls`, execute them, append results, repeat.
3. Stop when the model returns a normal assistant message, or after **15** tool rounds.
4. Stream or show assistant text in the UI. Staging preview iframe beside the chat. **Stop** button cancels the loop.

Do **not** implement MCP, Custom GPTs, or ChatGPT connectors.

**Tools (staging only):**

| Tool | Behaviour |
|---|---|
| `list_dir` | One folder; optional depth; cap entry count. Default depth 1. |
| `read_file` | Text files only; size cap (e.g. 200 KB). Binaries: name/size only, no bytes. |
| `write_file` | Whole-file replace. Write temp then rename. Keep previous copy. |
| `search` | Grep across html/css/php/js/md/txt. |
| `mkdir` / `rename` / `delete` | Staging tree only. |

No `publish`, no `exec`, no shell, no production paths.

Every path: resolve `realpath`, must stay under staging `public_html`. Reject `..`, symlink escape, the cp app, and live.

**System prompt (baked in, not user-editable):** brand voice, colours, fonts, “only edit staging”, “never remove the contact form”, “do not invent pages unless asked”.

**Images:** upload form on the cp UI into `staging/.../images/`. The agent lists the filename and points HTML at it. Do not send JPEG/PNG through `write_file`.

**OpenRouter**

- API key on the server only (outside `public_html`). Never in frontend JS.
- Pin 1–2 models. Default something strong at HTML (e.g. `anthropic/claude-sonnet-5`). No 300-model picker.
- Set a **per-key monthly spend cap** (suggest $10). Show spend in the cp UI.
- Log every tool call: time, tool, path, bytes, estimated cost.

### 2) Publish / Rollback (human only)

Publish is a POST form with CSRF + session auth. The agent cannot call it.

On Publish:

1. Snapshot live `public_html` to `data/backups/<timestamp>/`.
2. Keep the last N snapshots (e.g. 10).
3. `rsync -a --delete` staging → live.
4. Do not copy vhost auth, cp app files, or backup dirs.

Rollback restores the previous snapshot to live. Confirm step (“type PUBLISH”).

Staging PHP-FPM: `open_basedir` limited to the staging tree so a hostile file the model writes cannot reach live. The cp PHP pool is the one that may read staging and write live.

## What not to build

- Joomla, WordPress, Grav, or any page builder
- Git-based deploy as the owner workflow
- MCP server / ChatGPT Developer Mode integration
- Agent tool that deploys to live
- Node/Docker unless you already need it; PHP on Hestia is enough for the tool loop
- Storing secrets in the repo or in `public_html`

## Acceptance checks

- Owner logs into cp, asks to change a headline, sees it on staging, clicks Publish, sees it on live.
- Agent cannot write a file under the live docroot or the cp app.
- Publish without login fails. Staging is not indexed.
- Rollback restores the previous live snapshot.
- OpenRouter key is not visible in page source. Hitting the monthly cap fails closed with a clear message.
- Existing important URLs still work or redirect.

## Open points (confirm with owner)

- Exact Hestia username and whether SSL/subdomains already exist
- Page inventory: how many unique layouts, blog vs brochure, shop/bookings
- Who holds the OpenRouter account and the $10 cap
- Brand colours/fonts/voice for the system prompt
- Whether the live domain stays `tashincconsulting.com` and cp/staging names above
