# Changelog Style Guide

This project follows a specific style for maintaining the `CHANGELOG.md` file to ensure the history is readable and consistent. It adheres to [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Structure

The changelog groups notable changes for each release.

### Version Headers

1. **Format**: Use `## [Version] - YYYY-MM-DD`.
2. **Example**: `## [0.2.0-alpha.3] - 2026-02-22`.

### Categories

Organize changes under these specific category headers:

- `### Added` for new features.
- `### Changed` for changes in existing functionality.
- `### Deprecated` for soon-to-be removed features.
- `### Removed` for now removed features.
- `### Fixed` for any bug fixes.
- `### Security` in case of vulnerabilities.

## Item Formatting

1. **Feature Grouping**: Group related changes under a bolded feature or domain name.
   - Format: `- **Feature Name**: Description of change`
2. **Sub-items**: If a feature has multiple distinct changes, use bulleted sub-items indented by two spaces.

   ```markdown
   - **Advanced Filtering & Search**:
     - Virtual column filtering support in `EntriesManager`...
     - Sorting and ID filtering in model search...
   ```

3. **Commit Hashes**: Optional, and expected mainly for released versions where an entry maps cleanly onto one or two commits. Conclude the entry with the short hash(es) in parentheses when they add traceability.
   - Hashes should be exactly 7 characters.
   - If multiple commits apply, separate them with a comma and a space.
   - Example: `(cb4cb2c, 7942ab7)`
   - Omit them where an entry summarises a whole phase or a long-running branch. The `0.3.0-alpha.1` section does this deliberately: its bullets describe build phases spanning many commits, so per-bullet hashes would be noise rather than traceability.
4. **Language & Grammar**: Start descriptions with active, past-tense verbs (e.g., "Implemented", "Added", "Refactored", "Fixed") or concise descriptive noun phrases (e.g., "Virtual column filtering support"). End descriptions with a period.
5. **Code References**: Use backticks (`` ` ``) to quote code references, class names, method names, or variables (e.g., `EntriesManager`, `PurgeDeletedJob`).

## Example

```markdown
## [0.2.0-alpha.3] - 2026-02-22

### Added

- **Sparse Fieldsets**: Implemented sparse fieldset support in `EntriesManager` and `ModelsManager` to optimize database queries (cb4cb2c, 7942ab7).
- **Advanced Filtering & Search**:
  - Virtual column filtering support in `EntriesManager` using an operator whitelist (a732a06, 0dc2aa7).
  - Sorting and ID filtering in model search (796dfa0).

### Fixed

- **Query Builder Dependencies**: Disabled escaping in select methods for `ModelsBuilder` and ensured proper table aliasing (ecfbaa6, 6f0cfab).
```
