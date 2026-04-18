# Quality Report

## Status

Laravel Uploads is currently maintained as a tested package for Laravel 10 through 13 on PHP 8.1+.

## Current Quality Baseline

- automated PHPUnit coverage is included
- Testbench is used for package-level Laravel integration tests
- GitHub Actions runs tests on push and pull request
- package metadata is validated with `composer validate --strict`
- upload flow, URL generation, controller delivery, cleanup commands, trait behavior, and resize logic are covered by tests

## Supported Runtime Matrix

| Component | Supported |
| --------- | --------- |
| PHP       | 8.1+      |
| Laravel   | 10.x-13.x |

## Quality Checks

The package is expected to pass these checks before release:

- `composer validate --strict`
- `composer test`

## Maintenance Notes

- Laravel 9 support was removed from CI because current dependency resolution is blocked by active security advisories in that ecosystem line
- new behavioral changes should include matching test coverage
- documentation should be updated when configuration, runtime support, or public API behavior changes
