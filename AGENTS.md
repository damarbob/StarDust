# AGENTS.md

Guidance for AI coding agents working in this repository.

**This file is a pointer, not a copy.** The canonical guidance lives in
[CLAUDE.md](CLAUDE.md); keeping one source and pointing the rest at it is what
stops several agent-facing files from drifting apart. Do not duplicate content
here.

## Read these first

- **[CLAUDE.md](CLAUDE.md)** — architecture, schema invariants, test conventions,
  and build discipline. Most subsystems also carry their own `CLAUDE.md` beside
  the code (`src/Chronicler/CLAUDE.md`, `src/Watcher/CLAUDE.md`, and so on);
  read the relevant one before changing anything non-trivial in that package.
- **[CONTRIBUTING.md](CONTRIBUTING.md)** — requirements, setup, and the three
  commands to run before pushing.
- **[.agent/rules/](.agent/rules/)** — commit message and changelog style.

## The short version

- **Design authority is the `SDDPG/` design repo.** Every `ADR NNNN` reference in
  this codebase resolves to `SDDPG/adrs/`. Search there before treating a design
  question as open — most already have a ruling, and the record wins over any
  doc that disagrees with it.
- **MySQL 8.0.13+ only.** MariaDB is deliberately rejected, and a CI job asserts
  the suite fails against it.
- **PHP 8.1 is the floor**, even though CI also tests up to 8.4.
- **Run the three checks** in CONTRIBUTING.md before claiming a change is done.
  Several conventions are enforced by tests and will tell you when you break them.
- **Don't commit unless asked.** Propose the commit message and let the maintainer
  commit.
