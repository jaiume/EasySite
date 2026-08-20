# Project Setup Guidelines

Strict project setup manual for AI agents creating PHP applications for this environment. These guidelines are the default standard. Agents may suggest alternatives, but must propose them clearly and obtain confirmation before deviating.

---

## Purpose

This document exists to prevent sloppy project setup.

It is for AI agents that are:

- scaffolding a new project
- proposing a project structure
- selecting implementation options
- deciding auth, database, testing, or frontend approaches
- creating or updating supporting documentation

This is not a base project template. It is a setup manual that should guide project decisions before code is generated.

---

## Agent Operating Rules

Agents must:

- treat this document as the default standard
- ask critical setup questions before generating code
- use menu-style questions with recommended defaults
- confirm even obvious inferred decisions before proceeding
- propose deviations rather than silently taking them
- get explicit permission before changing stack, structure, auth approach, frontend approach, or config approach
- keep architecture consistent once selected
- keep controllers light and business logic out of the HTTP layer
- maintain project documentation as part of setup, not as an afterthought

Agents may:

- suggest a better option when it fits the project well
- infer minor details when they are obvious
- use either static or injected utility/config access when that choice makes sense for the project

Agents must not:

- silently swap frameworks or libraries
- assume auth without asking
- assume database choice without asking
- mix page and API controller responsibilities
- trust old documentation without validating it against the current project state

---

## Default Platform Assumptions

Unless the user confirms otherwise, agents should assume:

- hosting target is HestiaCP
- application is a PHP 8.3 project
- backend framework is Slim 4
- dependency injection uses PHP-DI
- frontend styling uses the latest stable Bootstrap major/minor available at setup time
- configuration uses `config.ini`
- database is MariaDB unless the user chooses something else
- SQLite may be appropriate for some small projects, but must be asked about rather than assumed
- web root is `public/`
- deployment is Apache/Nginx behind HestiaCP
- Docker is not part of the default setup
- cron jobs are acceptable
- background workers, queues, or long-running processes require explicit confirmation

Twig guidance:

- use Twig only for server-rendered pages when it adds value
- skip Twig for API-only projects
- do not include Twig by default when the project does not need rendered pages

---

## Project Discovery Workflow

Before generating code, agents must ask a structured setup questionnaire.

The questionnaire should:

- be menu-based rather than open-ended
- mark a recommended default where appropriate
- ask critical questions first
- confirm inferred choices before implementation
- allow the user to choose something outside the menu if needed

### Required Setup Questions

Agents should ask questions that determine:

- project type
- hosting/deployment assumptions
- database choice
- frontend approach
- whether server-rendered pages are needed
- whether API endpoints are needed
- whether a separate mobile API is needed
- authentication approach
- roles and permissions model
- email requirements
- testing approach
- documentation expectations

### Project Type Menu

Agents should determine project type using a menu such as:

- brochure/site
- admin tool
- web application
- API-only backend
- web application with separate mobile API backend

If the answer is unclear, the agent should recommend the closest option and ask for confirmation.

---

## Recommended Architecture

### Request Flow

```text
Request -> Middleware -> Controller -> Service -> DAO -> Database
                                |
                                +-> Twig View (server-rendered pages only)
```

### Layer Responsibilities

| Layer | Responsibility |
|-------|----------------|
| Controllers | HTTP concerns only: request parsing, calling services, response shaping |
| Services | business rules, orchestration, validation, transactions, structured results |
| DAOs | data access only, SQL only, persistence details |
| Middleware | cross-cutting concerns such as auth, logging, and request guards |
| Views | presentation only, no business logic |

### Controller Separation

Controllers must be separated by purpose:

- `src/Controllers/Web/` for page controllers
- `src/Controllers/Api/` for general API controllers
- `src/Controllers/MobileApi/` for mobile-specific API controllers

Rules:

- web controllers return HTML responses
- API controllers return JSON responses
- mobile API controllers are always separate when a mobile app exists
- private controller helper methods may use arrays internally
- controllers should not contain business logic

---

## Standard Project Structure

Use this as the default structure, adjusting only with approval:

```text
project/
|-- bin/
|-- config/
|   |-- config.ini
|   |-- config.ini.example
|   |-- container.php
|   `-- routes.php
|-- docs/
|   |-- db/
|   |   |-- current_schema.sql
|   |   `-- migrations/
|   `-- project-decisions.md
|-- public/
|   |-- index.php
|   |-- css/
|   |-- js/
|   `-- images/
|-- src/
|   |-- Controllers/
|   |   |-- Api/
|   |   |-- MobileApi/
|   |   `-- Web/
|   |-- DAO/
|   |-- Middleware/
|   |-- Services/
|   `-- Support/
|-- templates/
|-- var/
|   |-- cache/
|   `-- logs/
|-- composer.json
`-- README.md
```

Notes:

- `templates/` is needed only when server-rendered pages are in scope
- `docs/project-decisions.md` should record chosen setup options
- `docs/db/current_schema.sql` is a reference file, not a source of truth

---

## Database Documentation Rules

AI agents may and should maintain database documentation under `docs/db/`.

Required rules:

- store schema reference in `docs/db/current_schema.sql`
- store migrations in `docs/db/migrations/`
- include the creation date in migration filenames
- keep migration names descriptive
- regularly sanity check `docs/db/current_schema.sql` against the development database
- do not blindly trust `current_schema.sql` if the live dev database disagrees

Recommended migration naming pattern:

```text
YYYY-MM-DD_short_description.sql
```

Example:

```text
2026-03-09_create_users_table.sql
```

---

## Configuration Rules

Default configuration approach:

- use `config/config.ini` for real configuration
- commit `config/config.ini.example`
- do not commit the real `config/config.ini`
- document required keys in `config.ini.example`

Agents may choose static or injected config access depending on the project, but the choice should remain consistent within the codebase.

Suggested `config.ini` sections:

- `[app]`
- `[database]`
- `[auth]`
- `[mail]`
- `[logging]`
- `[security]`

Rules:

- no real secrets in version control
- no placeholder secrets presented as real values
- all required config keys should exist in the example file
- security-sensitive config should be clearly labeled

### Example `config.ini.example`

```ini
[app]
name = "Application Name"
debug = true
base_url = "https://example.com"

[database]
driver = "mariadb"
host = "localhost"
port = 3306
name = "database_name"
user = "db_user"
pass = "db_password"
charset = "utf8mb4"

[mail]
smtp_host = "mail.example.com"
smtp_port = 587
smtp_encryption = "tls"
smtp_user = "app@example.com"
smtp_pass = "mail_password"
from_email = "app@example.com"
from_name = "Application Name"
```

---

## Frontend Rules

Default frontend direction:

- use the latest stable Bootstrap major/minor available at setup time
- keep layouts responsive by default
- create one responsive page rather than separate desktop/mobile versions unless explicitly required

JavaScript guidance:

- small page-specific inline snippets are acceptable
- repeated JavaScript must be abstracted into shared files or modules
- avoid duplicating the same logic across pages

Twig guidance:

- use Twig only when server-rendered pages are part of the project
- do not add Twig to API-only builds
- keep Twig templates presentation-focused

---

## Authentication Menu

Authentication must be chosen during project setup. Agents must not assume a default auth model without asking.

The auth menu should include:

- none
- config-based single user admin
- username and password
- email link
- passkey

Agents should:

- recommend a primary auth option
- allow optional add-on auth methods
- confirm any suggested alternative with the user

When email links are used, the link should be to a non rendering callback that then allows the intiating page to proceed (App proceeds in original tab, and call back tab closes)

### Auth Option Notes

#### None

Use only when the project is intentionally public or authentication is out of scope.

#### Config-Based Single User Admin

This means:

- credentials live in `config.ini`
- there is a single admin account
- no user table is required
- it is suitable only for small/internal/admin-focused projects

#### Username And Password

Use when normal user accounts are required.

#### Email Link

This means passwordless magic-link login. The preferred flow is:

- the user requests a login link
- the link performs a silent callback
- the current page waits for the callback result
- no extra tab or success page should be opened unless explicitly requested

#### Passkey

Passkey is an available option and may be paired with other auth methods when appropriate.

### Security Choice Menu

Security decisions should also be asked per project, including:

- CSRF strategy
- rate limiting
- password hashing needs
- session or token policy
- secure cookie requirements
- account lockout or abuse protections

---

## Error Handling Standard

Projects should use centralized structured error handling.

Goals:

- handled errors are logged
- users receive safe error messages
- API consumers receive structured JSON errors
- web pages receive friendly error responses
- detail level changes appropriately between development and production

### Standard Service Result Shape

Services should return a consistent structure for handled outcomes:

```json
{
  "success": true,
  "message": "Human-readable summary",
  "data": {},
  "error": null
}
```

On failure:

```json
{
  "success": false,
  "message": "User-safe failure summary",
  "data": null,
  "error": {
    "code": "VALIDATION_ERROR",
    "details": []
  }
}
```

Guidance:

- services may return structured arrays for expected outcomes
- centralized error handling should manage unexpected failures
- controllers must translate service results into proper HTTP responses
- controllers must not dump raw exceptions to users

---

## Testing Menu

Testing approach must be chosen during setup rather than assumed.

The menu should include:

- no automated tests
- smoke/manual checklist only
- unit tests for services
- integration tests for routes and database behavior
- mixed unit and integration coverage

Agents should recommend a testing approach based on project size and risk, then confirm it.

---

## Documentation Rules

Agents should maintain the `docs/` folder as the project evolves.

Recommended contents:

- `docs/project-decisions.md`
- `docs/db/current_schema.sql`
- `docs/db/migrations/`
- any setup notes needed for deployment or project-specific decisions

Documentation should capture:

- selected menus and confirmed decisions
- approved deviations from the default standard
- auth choices
- deployment assumptions
- testing choices
- notable constraints

---

## Anti-Patterns

Agents must avoid these unless explicitly approved:

- no business logic in routes
- no SQL in controllers
- no database access in views
- no mixing web and API controller responsibilities
- no secrets committed to version control
- no real `config.ini` committed
- no framework swaps without permission
- no frontend framework swaps without permission
- no auth implementation chosen without user confirmation
- no duplicated JavaScript across pages when it should be shared
- no blind trust in `docs/db/current_schema.sql`
- no introducing optional infrastructure such as queues or workers without approval

### Why No Secrets Committed

Secrets must never be committed because:

- repositories persist leaked values
- shared repos increase accidental exposure risk
- rotating exposed credentials creates unnecessary cleanup work
- example config files already provide the correct place to document required keys

---

## Setup Checklist For Agents

Before scaffolding a project:

- [ ] Ask the setup questionnaire
- [ ] Recommend defaults where appropriate
- [ ] Confirm inferred choices
- [ ] Confirm any proposed deviations
- [ ] Record decisions in `docs/project-decisions.md`
- [ ] Choose and document the database strategy
- [ ] Choose and document auth strategy
- [ ] Choose and document testing strategy
- [ ] Confirm whether Twig is required
- [ ] Confirm whether a separate mobile API is required
- [ ] Create `config.ini.example`
- [ ] Add `config.ini` to version control ignore rules if applicable
- [ ] Create `docs/db/` documentation structure if the project has a database

When adding a new feature:

- [ ] Decide whether it affects web, API, mobile API, or multiple layers
- [ ] Add or update DAO logic
- [ ] Add or update service logic
- [ ] Add or update the correct controller type
- [ ] Add or update migrations when schema changes
- [ ] Update `docs/db/current_schema.sql` after validating against the dev database
- [ ] Update project documentation where decisions or behavior changed
- [ ] Add or update tests according to the chosen testing strategy

---

## Technology Baseline

Default baseline unless otherwise confirmed:

| Area | Standard |
|------|----------|
| PHP | 8.3 |
| Framework | Slim 4 |
| Dependency Injection | PHP-DI |
| Frontend CSS | Latest stable Bootstrap |
| Templates | Twig when server-rendered pages justify it |
| Database | MariaDB by default, SQLite by explicit choice |
| Configuration | `config.ini` |
| Email | PHPMailer or equivalent only if required by project |

---

## Final Rule

If an agent wants to do something meaningfully different from this manual, it should recommend the alternative, explain why it may be better, and wait for confirmation before proceeding.

---

## Appendix: Illustrative Reference Code

These examples are illustrative reference implementations, not mandatory drop-in requirements. Agents may adapt them to project needs, but should stay aligned with the rules in this document and confirm meaningful deviations.

### Reference ConfigService

This example shows a simple static config reader for `config.ini`. A project may instead use an injected config service if that better fits the chosen architecture.

```php
<?php

namespace App\Services;

class ConfigService
{
    private static ?array $config = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$config === null) {
            self::$config = parse_ini_file(BASE_DIR . '/config/config.ini', true, INI_SCANNER_TYPED);
        }

        if (str_contains($key, '.')) {
            [$section, $name] = explode('.', $key, 2);
            return self::$config[$section][$name] ?? $default;
        }

        foreach (self::$config as $section) {
            if (array_key_exists($key, $section)) {
                return $section[$key];
            }
        }

        return $default;
    }
}
```

### Reference UtilityService

This example shows a lightweight utility service with email sending and base URL helpers. Utility helpers may be static or injected, but repeated infrastructure logic should be kept out of controllers.

```php
<?php

namespace App\Services;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

class UtilityService
{
    public function sendEmail(string $to, string $subject, string $body, bool $isHtml = true): array
    {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = (string) ConfigService::get('mail.smtp_host');
            $mail->SMTPAuth = true;
            $mail->Username = (string) ConfigService::get('mail.smtp_user');
            $mail->Password = (string) ConfigService::get('mail.smtp_pass');
            $mail->Port = (int) ConfigService::get('mail.smtp_port', 587);

            $encryption = (string) ConfigService::get('mail.smtp_encryption', 'tls');
            if ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            }

            $mail->setFrom(
                (string) ConfigService::get('mail.from_email'),
                (string) ConfigService::get('mail.from_name')
            );
            $mail->addAddress($to);
            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->send();

            return [
                'success' => true,
                'message' => 'Email sent successfully.',
                'data' => null,
                'error' => null,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to send email.',
                'data' => null,
                'error' => [
                    'code' => 'EMAIL_SEND_FAILED',
                    'details' => [$e->getMessage()],
                ],
            ];
        }
    }

    public function getBaseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = rtrim(str_replace('/public', '', dirname($scriptPath)), '/\\');

        return $basePath === ''
            ? sprintf('%s://%s/', $scheme, $host)
            : sprintf('%s://%s%s/', $scheme, $host, $basePath);
    }
}
```
