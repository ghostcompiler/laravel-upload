# Security Policy

## Supported Versions

Security updates are currently provided for the actively maintained major line below.

| Version | Supported |
| ------- | --------- |
| 1.x     | Yes       |
| < 1.0   | No        |

## Reporting a Vulnerability

If you believe you have found a security vulnerability in Laravel Uploads, please report it privately and do not open a public GitHub issue.

Please include:

- a clear description of the issue
- affected version or commit
- reproduction steps or a proof of concept
- the expected impact
- any suggested remediation if you have one

Please send reports through one of these private channels:

- GitHub Security Advisories for this repository, if enabled
- a private email to the maintainer address used for this project

Expected response process:

- initial acknowledgment within 5 business days
- triage and severity review after reproduction
- status updates when there is meaningful progress
- a coordinated fix and release if the report is accepted

If the report is accepted, the issue will be fixed in a supported release line and disclosed after a patch is available. If the report is declined, you will receive a short explanation so the decision is clear.

Please avoid posting exploit details publicly until a fix has been released.

## Security Notes For 1.x

Laravel Uploads is designed with defense-in-depth protections, but upload security still depends on application configuration and infrastructure limits.

### Package Protections

- Upload paths are normalized and reject absolute paths, Windows drive paths, traversal segments such as `..`, empty segments, and control characters.
- Stored upload paths are checked against the configured base directory before read/delete operations.
- Critical executable extensions such as `php`, `phar`, and `phtml` are always blocked and cannot be allowed by per-upload overrides.
- Upload validation uses server-side MIME detection for allow/block decisions.
- Private uploads use expiring package tokens instead of direct public file paths.
- Public uploads return disk or configured tenant/CDN URLs directly and do not create private token rows.
- SVG files are never previewed inline by the package controller, even if `image/svg+xml` is added to `preview_mime_types`.
- Model URL serialization is enabled by default and can be disabled per field with `expose => false`.

### Application Responsibilities

- Keep `validation.max_size` aligned with your app and server upload limits.
- Keep image limits such as `max_input_width`, `max_input_height`, `max_input_pixels`, and `max_output_pixels` conservative when image optimization is enabled.
- Use Laravel throttling, queues, web server request limits, or a WAF to reduce upload-based DoS risk.
- Schedule `php artisan ghost:laravel-uploads-clean` so expired private URL tokens do not grow indefinitely.
- Set `expose => false` for upload fields that should not be included in API responses.
- For multi-tenant public uploads, configure `urls.public_resolver` or `Uploads::resolvePublicUrlsUsing(...)` so public URLs use the correct tenant domain.

### Image Optimization Behavior

When `image_optimization.strict` is `false`, failed AVIF/WEBP conversion may fall back to storing the original file after safety checks. This is intentional for compatibility. Enable `strict` when your application requires conversion to succeed.
