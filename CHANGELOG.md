# Changelog

All notable changes to Laravel Uploads are documented here.

## [Unreleased]

## [1.1.6] - 2026-04-27

- Fixed a time-sensitive cache registry test so it passes consistently on Laravel 10.

## [1.1.5] - 2026-04-27

- Changed the default `cache.registry_ttl` to `60` minutes.

## [1.1.4] - 2026-04-27

- Changed the generated URL cache-key registry to expire instead of being stored forever.
- Added `cache.registry_ttl` configuration for the internal generated URL cache-key registry.

## [1.1.3] - 2026-04-27

- Refactored upload manager internals into focused service concerns for path safety, validation, and URL generation.
- Added this changelog and linked it from the README.

## [1.1.2] - 2026-04-27

- Made uploadable model URL exposure enabled by default.
- Added `expose => false` as the per-field opt-out for JSON/array serialization.
- Updated documentation and tests for the new exposure default.

## [1.1.1] - 2026-04-27

- Updated security guidance for current 1.x behavior.
- Clarified that SVG inline preview is blocked by the controller.
- Documented operational responsibilities for upload limits, throttling, cleanup scheduling, `expose`, and tenant public URL resolvers.
- Kept ServBay and build-machine documentation intact.

## [1.1.0] - 2026-04-27

- Added tenant/CDN-aware public URL resolver support.
- Public uploads now return disk/resolver URLs without creating private token rows.
- Private uploads continue using expiring package token URLs.
- Added favicon upload support through `Uploads::upload(..., ['favicon' => true])`.
- Added model uploadable value hooks, including field-specific hooks.
- Improved multiple uploadable value resolution.
- Renamed the install command to `php artisan install:laravel-uploads`.
- Shortened README while keeping core usage, security notes, and ServBay documentation.
- Removed tracked `.DS_Store` files.

## [1.0.9] - 2026-04-27

- Hardened upload path validation and disk containment checks.
- Switched validation decisions to server-side MIME detection.
- Made model serialization safer through configurable upload URL exposure.
- Added image and favicon processing safety checks.
- Improved file streaming to avoid loading downloads fully into PHP memory.
- Prevented upload metadata persistence when storage writes fail.

[Unreleased]: https://github.com/ghostcompiler/laravel-upload/compare/v1.1.4...HEAD
[1.1.4]: https://github.com/ghostcompiler/laravel-upload/compare/v1.1.3...v1.1.4
[1.1.3]: https://github.com/ghostcompiler/laravel-upload/compare/v1.1.2...v1.1.3
[1.1.2]: https://github.com/ghostcompiler/laravel-upload/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/ghostcompiler/laravel-upload/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/ghostcompiler/laravel-upload/compare/v1.0.9...v1.1.0
[1.0.9]: https://github.com/ghostcompiler/laravel-upload/releases/tag/v1.0.9
