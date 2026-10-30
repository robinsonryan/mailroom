# Mailroom

A Laravel package.

Composer name: `robinsonryan/mailroom` — a **library**, not an application.

## Conventions

@.claude/constitution.md
@.claude/imports/package-conventions.md
@.claude/imports/package-quality-gate.md
@.claude/imports/testing-conventions.md
@.claude/imports/php-conventions.md
@.claude/imports/git-conventions.md

> Linking the `laravel-package` stack also drops the inherited Laravel app
> conventions into `.claude/imports/` — `authorization-conventions.md`,
> `frontend-conventions.md`, `pwa-conventions.md`, `ddev-worktrees.md`. They are
> **deliberately not imported above**: they describe Inertia `can` maps, app-shaped
> Vite wiring, and nested app worktrees, none of which exist in a package. Read
> them if a question genuinely calls for one; do not load them by default.
>
> The exception is `frontend-conventions.md` — **import it if this package has a
> frontend half** (today: `yikes`, `four-corners`).

> `.claude/` is a set of **harness symlinks** and is gitignored — a fresh clone has
> none of them and the `@import`s above resolve to nothing. If a convention file is
> missing, restore the link rather than guessing:
> `~/workspace/harness/link.sh project laravel-package $(pwd)`

## The gate

`ddev composer quality` — `lint:check` → `analyze` → `refactor:check` → `test`.
Verify-only: it never rewrites files. Fix with `ddev composer lint` /
`ddev composer refactor` and re-stage.

`.githooks/pre-commit` runs **the whole gate, tests included** — packages are
small enough (13–21 s measured) that the apps' exclude-the-tests compromise does
not apply. It is path-aware, so a docs-only commit skips it. Never bypass with
`--no-verify`; `PACKAGE_SKIP_GATE=1` is a human emergency valve and **agents must
never set it**.

That hook file is a **copy** of the harness's canonical one. Do not edit it here
— edit `$CLAUDE_HARNESS_DIR/core/stacks/laravel-package/hooks/pre-commit` and
re-run that directory's `install.sh`.

The gate never rewrites your source and never stages anything. If this package
**commits a build artifact**, the hook additionally refuses any commit where the
gate itself changed a tracked file — that means the committed artifact no longer
matches its source, and the rebuild would otherwise ship outside your commit.
Stage the rebuilt artifact alongside the source that produced it.

`harness package-check` sweeps every first-party package: the gate, a
`--prefer-lowest` run proving the declared version floor really resolves,
outdated and vulnerability scans, and in-constraint updates behind a re-run of
the gate. It never tags a release. Run it before any app re-resolves its
packages.

Full definition: `imports/package-quality-gate.md`. Skill: `/package-quality`.

## Testing

Pest + Orchestra Testbench against **real Postgres** (DDEV's `db` service,
`testing` database — SQLite cannot express the `uuidv7()` column defaults).

```bash
ddev composer test
ddev exec vendor/bin/pest --filter=SomeTest
```

There is no `ddev artisan` and no `ddev pest` here — those are app commands.

## Releases

Apps consume this package **by tag**, so an untagged fix does not exist for them.
Agents tag and push when the work is releasable (ruled 2026-08-18): the full gate
green on the merge commit, `CHANGELOG.md` updated, a caret-on-zero semver choice
(a behaviour change takes the minor on `0.x`), an annotated tag. Do not tag on a red
gate, open review findings or a contested version; report the options instead. The
full rule is the `package` layer's `releases.md`.

Behavior changes land in `CHANGELOG.md` in the commit that makes them.

## Reference package

`~/dev/php/packages/robinsonryan/hey-you/` is the reference implementation —
service provider shape, Testbench setup, tool configs, table prefixing. Read it
before inventing a variant.

## Quick reference

- **DDEV**: `ddev start`, `ddev ssh`
- **Gate**: `ddev composer quality`
- **Tests**: `ddev composer test`
- **Style fix**: `ddev composer lint`
- **Rector fix**: `ddev composer refactor`
