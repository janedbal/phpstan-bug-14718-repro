# PHPStan 2.2 — trait `@phpstan-ignore` ignored + wrong editor URL

Minimal reproduction for **[phpstan/phpstan#14718](https://github.com/phpstan/phpstan/issues/14718)** ("Some `@phpstan-ignore` are ignored in traits since v2.2.0").

Since 2.2.0, an error reported inside a `trait` is attributed to the **trait file** (correct) but
its underlying `filePath` still points at the **using class's file**. Two things break as a result:

1. **`@phpstan-ignore` no longer suppresses the error** (the regression reported in #14718).
2. **The editor URL points to the wrong file** — the using-class file glued to the trait's line number.

Both worked correctly in 2.1.x.

## Reproduce

```
composer install
vendor/bin/phpstan analyse --no-progress -c phpstan.neon
```

`trait.php` carries a `// @phpstan-ignore staticMethod.alreadyNarrowedType` on the offending line,
so a correct PHPStan should report **0 errors**.

## Actual output (2.2.0) — buggy

```
 Line   trait.php
 11     Call to static method A::isArray() with array<mixed> will always evaluate to true.
        🪪  staticMethod.alreadyNarrowedType
        ✏️  editor://.../user.php:11
 [ERROR] Found 1 error
```

- The inline ignore on `trait.php:11` is **not** honored.
- The ✏️ editor link opens `user.php:11`, but the error is at `trait.php:11` (and `user.php` has only 3 lines).

## Expected output

`0 errors` (the inline ignore suppresses it). With the ignore removed, the ✏️ link should point to
`trait.php:11`, as it does in 2.1.x.

## Regression range

| PHPStan | `@phpstan-ignore` honored | Editor URL (ignore removed) |
| ------- | ------------------------- | --------------------------- |
| 2.0.6   | ✅ yes                    | `trait.php` ✅              |
| 2.1.17  | ✅ yes                    | `trait.php` ✅              |
| 2.2.0   | ❌ no                     | `user.php` ❌              |

JSON output (`--error-format=json`) attributes the error to `trait.php:11` correctly in all versions —
only the table formatter's editor URL and the inline-ignore matching are affected.

## Root cause

The error is collapsed into a single "report directly in the trait" error via the 2.2 dedup path
(`ConstantConditionInTraitCollector` / `ConstantConditionInTraitRule`), which calls
`Error::removeTraitContext()`:

```php
public function removeTraitContext(): self
{
    // file      -> traitFilePath (trait.php)          ✅ moved to the trait file
    // filePath  -> $this->filePath (UNCHANGED)        ❌ still the using-class file (user.php)
    // traitFilePath -> null
    return new self($this->message, $this->traitFilePath, $this->line, $this->canBeIgnored,
        $this->filePath, null, ...);
}
```

`file` is moved to the trait file, but `filePath` is left pointing at the using-class file. Then:

- `AnalyserResultFinalizer` looks up line-ignores by `getFilePath()` → `user.php`, so the ignore
  registered for the trait never matches → error not suppressed (#14718).
- `TableErrorFormatter` builds the editor URL from `getTraitFilePath() ?? getFilePath()`
  → `null ?? user.php` → wrong file.

Likely fix: `removeTraitContext()` should also set `filePath` to `$this->traitFilePath`, the way
`changeFilePath()` keeps `file` and `filePath` in sync.

## Affected files

- `trait.php` — trait `T` with the ignored error on line 11
- `user.php` — `class C { use T; }`
- `phpstan.neon` — level 8, `editorUrl`, both files in `paths`
