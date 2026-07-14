# Versioning & Release Plan

`raintyyek/laravel-ocr` follows [Semantic Versioning 2.0.0](https://semver.org/):
`MAJOR.MINOR.PATCH`.

| Bump      | Meaning                                                             |
| --------- | ------------------------------------------------------------------ |
| **MAJOR** | Backwards-incompatible API change (removed/renamed public method, changed signature, changed config shape, dropped PHP/Laravel version). |
| **MINOR** | Backwards-compatible feature (new engine, new method, new config key with a default). |
| **PATCH** | Backwards-compatible bug fix, docs, internal refactor.             |

**Public API** = everything a consumer touches: the `Ocr` facade, `OcrService` /
`OcrManager`, the `OcrEngine` contract, DTOs, `ImageSource`, `OcrCall` model,
exceptions, config keys, the migration, and the `ocr:process-pending` command.
Anything marked `@internal` or under `Engines/**` internals is not covered.

## How versions are published

Versions come from **git tags**, not a `version` field in `composer.json`
(intentionally omitted so Composer derives it from tags).

```bash
git tag v0.1.0
git push origin main --tags
```

Tags are prefixed `v` (e.g. `v0.1.0`). Composer maps `v0.1.0` → `0.1.0`.

## Pre-1.0 (0.x) contract

While on `0.x` the API is still stabilizing. Per SemVer, **a 0.x MINOR bump may
contain breaking changes**; PATCH remains fix-only. Consumers should pin:

```json
{ "require": { "raintyyek/laravel-ocr": "^0.1" } }
```

Note Composer's caret is stricter below 1.0: `^0.1` allows `0.1.*` but **not**
`0.2.0`. So each breaking change during 0.x is a MINOR bump (`0.1 → 0.2`), and
consumers opt in by widening the constraint. Non-breaking features/fixes ship as
`0.1.1`, `0.1.2`, … and are picked up automatically.

## Version support matrix

| Package | PHP     | Laravel        |
| ------- | ------- | -------------- |
| `0.x`   | `^8.1`  | `10 / 11 / 12` |

Dropping a PHP or Laravel version is a **breaking** change (MAJOR once at 1.0;
MINOR while on 0.x). Adding support for a newer Laravel is a MINOR/PATCH.

## Roadmap

| Milestone  | Scope                                                                 |
| ---------- | --------------------------------------------------------------------- |
| **0.1.0**  | Initial release: Google + AWS engines, persistence, cost, scheduling, S3. |
| **0.x**    | Stabilize the API from real usage; add PHPUnit coverage; tune cost/pagination; possible small breaking tweaks (each as a MINOR). |
| **1.0.0**  | **Structured financial-document extraction** — invoices, receipts, bills, expenses, payment slips → typed fields (totals, tax, line items, invoice no., due/payment dates, payment ref/method, …). API frozen under the SemVer BC guarantee. See [docs/ROADMAP-1.0.md](docs/ROADMAP-1.0.md). |
| **1.x**    | More extractors (Azure Document Intelligence), async multi-page/PDF, vendor templates, batch — all backwards-compatible MINORs. |
| **2.0.0**  | Reserved for the next unavoidable breaking change (e.g. a required PHP/Laravel bump or a DTO redesign). |

The full 1.0.0 design — field taxonomy, extractor drivers, provider mapping,
normalization/validation, persistence and cost — lives in
[docs/ROADMAP-1.0.md](docs/ROADMAP-1.0.md).

## Path to 1.0.0 (exit criteria)

- [ ] Public API exercised in at least one production integration.
- [ ] PHPUnit + Testbench suite covering both engines (faked) and the scheduling/cost paths.
- [ ] No planned breaking changes on the backlog.
- [ ] README, CHANGELOG, and config docs complete and accurate.

## Release checklist

1. Update [CHANGELOG.md](CHANGELOG.md): move `Unreleased` → the new version + date.
2. Confirm the support matrix and `composer.json` constraints are still correct.
3. `composer validate` and run the test suite / `php -l` across `src`.
4. Commit, then tag: `git tag vX.Y.Z && git push origin main --tags`.
5. (Private repo) consumers get it via a `vcs` repository entry — no Packagist step.

## Branching

- **`main`** — always releasable; every tag is cut from here.
- Short-lived feature branches merge into `main` via PR.
- If a maintained older line is ever needed, cut a `1.x` branch at that time;
  until then, `main` is the single source.
