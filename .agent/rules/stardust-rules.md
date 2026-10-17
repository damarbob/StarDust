---
trigger: always_on
---

# StarDust Rules

Rules that apply only to StarDust. They add to the shared rules in this folder, which `core.md` lists, and they win on a conflict. The sync never touches this file.

## Writing

- The writing guide covers every text a person reads in this repository. That includes `GLOSSARY.md` and `TESTING.md`, the ADRs and other documents in the `SDDPG/` design repo, comments and docblocks in `examples/`, what the `examples/` scripts print, and the output of `bin/stardust --help`.
- `tests/Smoke/Conventions/WritingStyleTest` enforces the dash rule and the heading rule. The dash rule is a per-file ratchet, and `CLAUDE.md` explains how it works. The other rules are checked by review.
- README, GLOSSARY, CHANGELOG and `docs/` never cite an ADR and never name a numbered build phase. See `CLAUDE.md`.

## Commits

- `SDDPG/` is a separate git repository cloned into the project root. A piece of work that changes it and this repository gets one commit message for each.
- The `#` trap here is the design repo's citation style. `blueprints/*.md` cite acceptance criteria as `AC#3`, `AC#8` and `AC#11`, which is right in Markdown and wrong once pasted into a commit, because GitHub links `#8` to issue 8. Write `AC 8`, or spell it out as "acceptance criterion 8". Two commits shipped with `AC#6`, `AC#11` and `AC#14` before anyone noticed.
- Before handing a message over, run `git log -1 --format=%B | grep -n '#[0-9]'`. It should print nothing.

## Changelog

- Versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html), with pre-release labels such as `0.3.0-alpha.1`.
- `CHANGELOG.md` is written at release time only. See `CLAUDE.md`.
- Commit hashes are expected mainly for released versions where an entry maps onto one or two commits. The `0.3.0-alpha.1` section omits them on purpose, because its bullets describe build phases that span many commits.
