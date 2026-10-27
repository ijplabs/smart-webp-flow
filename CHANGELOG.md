# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-10-27

### Added
- Native WordPress automatic update notifications powered by LiteSpace Platform Hub.
- Secure tokenized S3 downloads via RustFS storage integration.
- Instant license activation and domain binding verification.

### Changed
- Optimized GD and Imagick compression algorithms for 15% better compression efficiency.
- Enhanced bulk converter chunk worker with mutex locking mechanism.

### Fixed
- Resolved transient caching issue on multisite WordPress setups.
- Fixed content migration regex for relative URL replacement.

## [1.0.0] - 2026-10-25

### Added
- Initial production release of Smart WebP Flow.
- Automatic WebP conversion upon media upload.
- Background chunked bulk media processing runner.
- Zero-downtime post content reference migration.
