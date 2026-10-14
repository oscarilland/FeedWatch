# AGENTS.md - coding rules

FeedWatch was built with AI coding agents (Claude Code and Codex). These are the rules they worked to in this codebase; `CLAUDE.md` imports this file, so every tool follows one set. People follow the same rules.

The design work behind the code (requirements with acceptance criteria, decision records, and approved build plans) was done in a private working repository. No change to the code was made without an approved plan, and every design choice was made by the author, not the agent.

## Stack

- PHP 8.4, with no framework. The production host defaults to an older PHP, so the root `.htaccess` keeps a handler line that runs this folder on PHP 8.4.
- MariaDB 10.5 through PDO. Every connection sets `sql_mode = 'TRADITIONAL'` and `time_zone = '+00:00'`, because the host's defaults are lenient and in a European time zone.
- HTML, CSS and JavaScript modules in the browser, with no build step. Third-party browser libraries are copied into `public/assets/vendor/<name>-<version>/` with their licence file.
- Composer for PSR-4 autoloading and development tools only (PHPUnit, PHPStan).
- Deployed to shared hosting under a sub-folder. No daemons, no websockets and nothing that needs a long-running process.

## Layout

| Path | Purpose | Rule |
|---|---|---|
| `public/` | Web root | Only `index.php`, assets and `.htaccess`. No secrets or logic |
| `app/Domain/` | Exchanges, feeds, messages, gaps, incidents | Plain objects. No database, HTTP or global state |
| `app/Simulation/` | Message generation and chaos state | Pure logic: time and randomness come in as parameters |
| `app/Detection/` | Gap detection, A/B arbitration, failover, latency stats | Pure logic, tested exhaustively |
| `app/Http/` | Router, controllers, JSON responses, auth, CSRF | Thin: validate input, call the domain, shape output |
| `app/Infrastructure/` | PDO database, config, migrations, clock | The only place that touches the database or the environment |
| `bin/` | Command-line entry points for cron | Thin wrappers around `app/` |
| `database/migrations/` | `NNN_description.sql` | Never edit a migration once deployed; add a new one |
| `config/` | Config loader | `config.local.php` is generated at deploy time and never committed |
| `tests/` | `Unit/` and `Integration/` | Every behaviour change comes with tests |

## Code rules

1. `declare(strict_types=1);` in every PHP file. Type every parameter, property and return value.
2. Namespace `FeedWatch\` maps to `app/`. One class per file. PSR-12 style.
3. **Database:** prepared statements with bound parameters, always. Never put a variable inside an SQL string. Table and column names come from code, never from input.
4. **Output:** escape everything shown in HTML with the shared escaping helper. JSON responses use `json_encode` with `JSON_THROW_ON_ERROR`.
5. **Input:** validate type, range and length at the HTTP layer. Reject what isn't expected; don't try to fix it.
6. **Writes:** every request that changes state needs a logged-in admin and a valid CSRF token.
7. **Time and randomness:** inject a `Clock` and a seeded random source, never call `time()` or `random_int()` inside `Domain`, `Simulation` or `Detection`. This keeps the logic deterministic and testable.
8. **Configuration:** read settings through the config loader. Never hard-code hostnames, paths, credentials or the base URL. All URLs go through the configured base path.
9. **Errors:** log details on the server and return a generic message to the client. Never show stack traces or SQL errors in a response.
10. Keep it readable over clever. The author has to explain every line, so add a short comment where the *why* isn't obvious, not where the code already says *what*.

## Frontend rules

- Mobile first. Layout works from 360px wide up to large desktop screens, with no horizontal scrolling.
- Colours, spacing and type come from CSS custom properties defined in one tokens file (`public/assets/css/tokens.css`).
- Status must never be shown by colour alone. Pair it with an icon, a label or a shape.
- Respect `prefers-reduced-motion` and `prefers-color-scheme`.
- No inline scripts or styles, so the Content Security Policy can stay strict.
- The dashboard must still work when the network drops for a few seconds: show "stale" rather than breaking.

## Tests

- Detection and simulation logic: unit tests for normal flow, duplicates, out-of-order messages, gaps on one feed, gaps on both feeds, a silent feed and recovery.
- Database code: integration tests against MariaDB 10.5, never SQLite, so behaviour matches production.
- Name tests after the behaviour, and cite the acceptance criterion where there is one, for example `test_AC2_gap_on_both_feeds_is_data_loss`.
- `composer check` runs PHP lint, PHPUnit and PHPStan at level 9.

## Licences

Every third-party library keeps its licence file next to its vendored copy. The project itself is MIT licensed (see `LICENSE`).
