---
trigger: always_on
---

# Commit Message Style Guide

This project follows a specific style for commit messages to ensure history is readable and consistent.

## Structure

```text
<Subject>

<Body>
```

## Subject Line

1. **Imperative Mood**: Use imperative verbs (e.g., "Update", "Fix", "Add", "Remove", "Refactor"), not past tense ("Updated") or present progressive ("Updating").
   - _Good_: "Refactor error handling in Entries controller"
   - _Bad_: "Refactored error handling"
2. **Capitalization**: Capitalize the first letter of the subject line.
3. **No Punctuation**: Do not end the subject line with a period.
4. **Concise**: Keep the subject line short and descriptive.
5. **No Prefixes**: Do not use "conventional commit" prefixes (like `feat:`, `chore:`) unless specifically instructed. Start directly with the verb.
6. **Length**: Limit the subject line to 50 characters where possible, and strictly no more than 72 characters.

## Body

1. **Separation**: Always separate the subject from the body with a blank line.
2. **Detail**: Explain _what_ changed and _why_. Focus on the context.
3. **Formatting**:
   - Use hyphens (`-`) for bullet points if listing multiple changes.
   - Use backticks (`` ` ``) to quote code references, file paths, variable names, or configuration options.
4. **Grammar**: Use complete sentences with proper capitalization and punctuation (end with periods).
5. **No Hard Wrapping**: Do not hard-wrap body text. Write each paragraph and each bullet as a single line and let the viewer soft-wrap it, as in the examples below.
   - **Do not "restore" a 72-column limit.** The classic 50/72 rule exists for `git send-email` workflows, where mailing lists re-wrap anything longer, and for 80-column terminals, where `git log`'s 4-space body indent leaves 76. Neither applies here: this project's history is read on GitHub and in IDE git panes.
   - Those viewers **preserve hard newlines instead of reflowing them**. A pre-wrapped paragraph therefore renders as a stack of short lines frozen at 72 characters, each with full line height, regardless of how wide the window is — looser and more ragged than the paragraph it was meant to be. An unwrapped line flows to fit.

## Examples

### Single Change

```text
Update StarDust dependency to v0.2.0-alpha.2

Update `damarbob/stardust` requirement to `^0.2.0-alpha.2` in composer.json and update the lock file.
```

### Multiple Changes

```text
Remove obsolete models/queries and integrate StarDust

- Deleted `ModelDataModel` and `ModelsModel` classes with their SQL query files.
- Removed EntriesModelGet.sql, EntriesModelGetDeleted.sql, EntryDataModelGet.sql, ModelsModelGet.sql.
- Eliminated entries manager and models manager services to streamline management.
- Updated `composer.json` to include `damarbob/stardust` package.
- Adjusted modules to reference new model namespaces for compatibility.
```

### Complex Change

```text
Enable legacy alias support in controllers and modules

- Enable `withLegacyAlias(true)` in various API and Admin controllers (Entries, Models) to support deprecated column names during migration.
- Update `ModelUserGroupFilter` to properly use the new builder structure for model lookup.
- Enable legacy alias support in `DataComparison` module API to ensure compatibility.
```
