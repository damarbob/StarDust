# AGENTS.md

Guidance for AI coding agents working in this repository.

**This file is a pointer, not a copy.** The canonical guidance lives in
[CLAUDE.md](CLAUDE.md); keeping one source and pointing the rest at it is what
stops several agent-facing files from drifting apart. Do not duplicate content
here.

## Read these first

- **[CLAUDE.md](CLAUDE.md)** — the architecture map and conventions for new
  code. Schema invariants live in `src/Bootstrap/CLAUDE.md` and test conventions
  in `tests/Smoke/CLAUDE.md`. Most other packages also carry their own
  `CLAUDE.md` beside the code; read the relevant one before changing anything
  non-trivial in that package.
- **[CONTRIBUTING.md](CONTRIBUTING.md)** — requirements, setup, and the three
  commands to run before pushing.
- **[.agent/rules/](.agent/rules/)** — attribution, commit message, changelog
  and writing rules, shared with other repositories. Start with
  [core.md](.agent/rules/core.md), which lists them, and read
  [stardust-rules.md](.agent/rules/stardust-rules.md) for what holds only here.
  Never edit a shared copy, because the next sync replaces it.

## The short version

- **Design authority is the `SDDPG/` design repo** — a separate repository
  (`github.com/damarbob/SDDPG`) cloned into the project root, never tracked by
  this one. See `CONTRIBUTING.md` for setup. Every `ADR NNNN` reference in
  this codebase resolves to `SDDPG/adrs/`. Search there before treating a design
  question as open — most already have a ruling, and the record wins over any
  doc that disagrees with it.
- **MySQL 8.0.13+ or MariaDB 10.11+, detected not configured.** MariaDB older
  than 10.11, and MySQL/Percona older than 8.0.13, are rejected and a CI job asserts the suite
  fails against a below-floor MariaDB.
- **PHP 8.1 is the floor**, even though CI also tests up to 8.4.
- **Run the three checks** in CONTRIBUTING.md before claiming a change is done.
  Several conventions are enforced by tests and will tell you when you break them.
- **Human-read text has a writing style.** Docs, ADRs, code comments, commit
  messages, CLI output and exception messages never use an em or en dash, and
  never put a colon in a heading. Read the guide before writing any of them.
  Only agent-facing files like this one are exempt.
- **Don't commit unless asked.** Propose the commit message and let the maintainer
  commit.
