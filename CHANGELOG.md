# Changelog

All notable changes to `raintyyek/laravel-ocr` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
See [VERSIONING.md](VERSIONING.md) for the release policy and roadmap.

## [Unreleased]

_Changes landed on `main` but not yet tagged._

## [0.1.0] - Unreleased (first tag)

Initial public release. **Pre-1.0: the API may change between minor versions.**

### Added
- Driver-based OCR abstraction with a single `OcrEngine` contract.
- **Google Cloud Vision** engine (`document` / `text` modes, language hints).
- **AWS Textract** engine (`DetectDocumentText`, in-place S3 reads).
- Normalized result DTOs: `OcrResult`, `TextBlock`, `BoundingBox`, `Point`,
  with 0.0–1.0 confidence and geometry.
- `ImageSource` inputs: path, bytes, base64, URL, S3, and Laravel Storage disks.
- Persistence: `OcrCall` Eloquent model + migration (status, logs, result, cost,
  timings), on the app's own DB connection.
- Cost calculation via a configurable pricing table (`CostCalculator`,
  `CostEstimate`).
- Scheduling: run inline, on a queue (`ProcessOcrCall` job), or via cron
  (`ocr:process-pending` command + optional scheduler auto-registration).
- `Ocr` facade, `OcrManager` (driver resolution), `OcrService` (orchestration),
  deferred-free service provider with config + migration publishing.
- S3 reads through Laravel's `config/filesystems.php` disks (no duplicated creds).

[Unreleased]: https://example.com/raintyyek/laravel-ocr/compare/v0.1.0...HEAD
[0.1.0]: https://example.com/raintyyek/laravel-ocr/releases/tag/v0.1.0
