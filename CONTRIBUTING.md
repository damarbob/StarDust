# Contributing to StarDust

Thanks for taking a look. This page is the short version: what you need, what to
run, and which conventions check themselves so you don't have to memorise them.

## Requirements

- **PHP 8.1 or newer.** 8.1 is the floor, and CI runs the test suite on 8.1, 8.2,
  8.3, and 8.4. Do not use syntax newer than 8.1 — it will compile on your machine
  and fail on the oldest matrix job.
- **MySQL 8.0.13+ or Percona 8.0.13+.** The floor is non-negotiable; the schema
  registry depends on functional partial unique indexes introduced in 8.0.13.
- **MariaDB is actively rejected**, and a CI job exists specifically to assert that
  the suite *fails* against it. That is a feature, not a bug — see the README's
  Requirements section for why.
- Composer, and Node (only if you want to run the markdown linter locally).

## Setup

```bash
composer install
cp phpunit.xml.dist phpunit.xml    # gitignored — put your DB credentials here
```

Point the credentials at a **throwaway database**. The bootstrap tests drop every
StarDust table between runs.

The suite skips rather than fails when no credentials are configured, so a fresh
clone still runs green.

## Before you push

Three commands, identical to what CI runs:

```bash
vendor/bin/phpstan analyse
npx --yes markdownlint-cli2@0.23.2 "*.md" "src/**/*.md" ".agent/**/*.md"
vendor/bin/phpunit --testsuite Smoke
```

CI runs four jobs: PHPStan, markdownlint, the suite across the full PHP matrix,
and the MariaDB rejection check.

Two notes on static analysis. PHPStan runs at level 6 over `src/` and `bin/`, and
it is pinned to analyse the whole supported PHP range rather than your local
runtime. A small baseline file absorbs some pre-existing findings — **never add to
it to silence something you just wrote.** The baseline shrinks as files are
touched; growing it inverts the arrangement.

## Conventions that check themselves

Most of this project's conventions are enforced by tests, so you will find out
immediately rather than in review. You do not need to memorise them.

| Enforced by | What it protects |
| :-- | :-- |
| `Conventions/BootstrapperTableAllowlistTest` | The five test table-drop allowlists match the tables the bootstrapper creates |
| `Conventions/ConfigAppendOnlyTest` | `Config`'s constructor is append-only; reordering silently rebinds positional callers |
| `Conventions/FinalClassGuardTest` | Every class under `src/` is `final` — this engine composes rather than inherits |
| `Conventions/DocsConsistencyTest` | The README stays free of internal design-record citations, and the version constant matches the changelog |
| `EventVocabularyTest` | Structured-log event names stay inside the documented closed vocabulary |
| `Slot/IndexedSlotPredicateTest` | The "is this slot indexed?" predicate has exactly one definition |

If one of these fails, read the failure message before changing the test — each
one explains the failure mode it is protecting against.

## Conventions that don't

Two things are still on you, both documented under [.agent/rules/](.agent/rules/):

- **Commit messages** — imperative subject, no conventional-commit prefix, body
  explaining what and why. See `commit-style-guide.md`.
- **Changelog entries** — Keep a Changelog structure and category headers. See
  `changelog-guide.md`.

## Where to look next

- **[TESTING.md](TESTING.md)** — what the smoke suite proves, phase by phase.
- **[CLAUDE.md](CLAUDE.md)** — architecture, schema invariants, and test
  conventions in depth. Most subsystems also carry their own `CLAUDE.md` next to
  the code.
- **[CHANGELOG.md](CHANGELOG.md)** — release history.
- **`SDDPG/`** — the design repo. It is the authority on every design decision;
  where a doc and a design record disagree, the design record wins.
