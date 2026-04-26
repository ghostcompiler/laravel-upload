# Developer Guide

## Development And Build Environment

This package was developed using **ServBay** as the local development environment.

### Development Tool Used

- Local development tool: `ServBay`
- Website: [www.servbay.com](https://www.servbay.com/)

### ServBay your development friend

<p>
  <img src="assets/servbay/servbay-icon-blue-512.png" alt="ServBay Icon" width="96">
</p>

### Testing And Build Machine

- Tested on: `Mac M4`
- Built on: `Mac M4`

This guide covers package-level configuration, validation, and security-sensitive upload behavior.

## Local Path Repository Installs

Because this package is published on Packagist, its `composer.json` does not declare a fixed version. When using a local path repository with a stable constraint such as `^1.0`, add a Composer path version override in the consuming app:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "/Users/ghostcompiler/Desktop/GhostCompiler/laravel-upload",
            "options": {
                "versions": {
                    "ghostcompiler/laravel-uploads": "1.0.2"
                }
            }
        }
    ],
    "require": {
        "ghostcompiler/laravel-uploads": "^1.0"
    }
}
```

When using a local path repository, run this in the consuming app:

```bash
composer update ghostcompiler/laravel-uploads
php artisan optimize:clear
```

## Local Development

### Use this package inside a local Laravel app

1. Add a Composer path repository in the Laravel app.
2. Require `ghostcompiler/laravel-uploads`.
3. Run:

```bash
composer update ghostcompiler/laravel-uploads
php artisan package:discover
php artisan install:laravel-uploads
php artisan migrate
```

### Pull latest package changes into the Laravel app

When you make changes in this package repo and want your Laravel app to use them:

```bash
composer update ghostcompiler/laravel-uploads
php artisan package:discover
```

If you changed helper autoloading or package metadata, this is also useful:

```bash
composer dump-autoload
```

If you changed the published config or migration stubs:

```bash
php artisan install:laravel-uploads
```

Overwrite existing published files without prompts:

```bash
php artisan install:laravel-uploads --force
```

### Recommended pull / push workflow

Inside the package repo:

```bash
git pull
```

Make your changes, then:

```bash
git add .
git commit -m "Update Laravel Uploads"
git push
```

Inside the Laravel app using the local path repository:

```bash
composer update ghostcompiler/laravel-uploads
php artisan package:discover
```

## Testing

This package includes a PHPUnit + Testbench scaffold.

### Install dev dependencies

Inside the package repo:

```bash
composer install
```

### Run tests

```bash
composer test
```

### Current test coverage

- cached URL reuse until expiry
- cache invalidation when uploads are deleted
- cache-disabled fallback behavior
- resize dimension calculation
- aspect-ratio preservation
- no upscaling behavior
- expired link cleanup command
- dry-run cleanup reporting

### Suggested manual testing in a Laravel app

Use a real Laravel test project and verify:

- upload works with `Uploads::upload(...)`
- upload works with `GhostCompiler()->upload(...)`
- custom folder uploads work
- excluded file validation blocks dangerous extensions
- explicit excluded-extension upload override works only when requested
- model serialization returns URL fields correctly
- cached URL reuse prevents repeated link generation on refresh
- preview URL opens supported file types
- SVG files download instead of opening inline by default
- `?download=1` forces download
- AVIF conversion works when supported
- WEBP fallback works when AVIF is unavailable
- resize limits preserve the original aspect ratio
- oversized image dimensions are rejected before optimization
- cleanup command removes only expired links

## Notes

- `Uploads::upload($file)` stores files in the configured `base_path`
- `Uploads::upload('demo/image', $file)` stores files inside `base_path/demo/image`
- `GhostCompiler()->upload($file)` uses the same Laravel Uploads service directly
- upload paths must stay relative and cannot contain traversal segments
- generated URLs are tracked in the database
- generated URLs are cached by upload ID and expiry when `cache.enabled` is `true`
- image optimization only applies to supported images
- AVIF is tried first
- WEBP is used as the main fallback format
- resizing keeps the original aspect ratio
- GD is used first and `Imagick` is used as a fallback encoder when available

## Upload Validation

Package validation is controlled by `config/laravel-uploads.php`.

```php
'validation' => [
    'max_size' => 10 * 1024 * 1024,

    'allowed_mime_types' => [],
    'allowed_extensions' => [],

    'excluded_mime_types' => [
        'application/x-httpd-php',
        'application/x-php',
        'text/x-php',
    ],
    'excluded_extensions' => [
        'cgi',
        'phar',
        'php',
        'php3',
        'php4',
        'php5',
        'phtml',
        'pl',
        'py',
        'rb',
        'sh',
    ],
    'never_allowed_extensions' => [
        'phar',
        'php',
        'php3',
        'php4',
        'php5',
        'phtml',
    ],
],
```

Empty `allowed_mime_types` and `allowed_extensions` lists mean all non-excluded files are accepted.

## Explicit Excluded Extension Override

Excluded files stay blocked by default. If your application has already performed its own authorization and validation, you can allow one or more excluded extensions for one upload call.

```php
use GhostCompiler\LaravelUploads\Facades\Uploads;

$upload = Uploads::upload($request->file('script'), 'sh');
```

Allow multiple excluded extensions for one upload call:

```php
$upload = Uploads::upload($request->file('script'), ['sh', 'rb']);
```

The same extension-specific override works with custom paths:

```php
$upload = Uploads::upload('trusted/path', $request->file('script'), 'sh');
```

Only the specified extension is allowed. For example, passing `'sh'` does not allow `rb`, `phar`, or any other excluded extension.

Critical extensions listed in `validation.never_allowed_extensions`, such as `php`, `phar`, and `phtml`, cannot be bypassed with per-upload overrides.

## Multiple File Uploads

Use `uploadMany()` when handling a request field containing multiple uploaded files.

```php
use GhostCompiler\LaravelUploads\Facades\Uploads;

$uploads = Uploads::uploadMany($request->file('documents'), 'documents');
```

The third argument accepts the same extension override rules:

```php
$uploads = Uploads::uploadMany($request->file('scripts'), 'trusted/scripts', ['sh', 'rb']);
```

## Image Processing Safety

Image optimization can reject oversized source images before GD or Imagick processes them.

```php
'image_optimization' => [
    'enabled' => false,
    'strict' => false,
    'quality' => 75,
    'convert_to_avif' => true,
    'max_width' => null,
    'max_height' => null,
    'max_input_width' => 8000,
    'max_input_height' => 8000,
    'max_input_pixels' => 12000000,
    'max_output_pixels' => 4000000,
],
```

Set a max input dimension or pixel value to protect the PHP process from oversized image payloads.
Set `strict` to `true` when an image upload should fail instead of storing the original file after AVIF/WEBP conversion fails.

## Path Safety

Upload paths must be relative disk paths. The package rejects:

- absolute paths
- Windows drive paths
- `..` traversal segments
- empty path segments
- control characters

This applies to upload paths, read paths, and delete paths.

## Preview Safety

SVG is not included in the default `preview_mime_types` list because inline SVG can execute script in the application origin. The package controller also blocks inline SVG preview even if `image/svg+xml` is added to the preview list, so SVG files download by default.

```php
'preview_mime_types' => [
    'image/avif',
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'application/pdf',
    'text/plain',
],
```

## Operational Security Checklist

- Keep `validation.max_size` strict for the application.
- Use conservative image pixel limits when optimization is enabled.
- Enable `image_optimization.strict` when storing original images after conversion failure is not acceptable.
- Use Laravel throttling, queue worker limits, web server upload limits, or a WAF for high-volume upload endpoints.
- Schedule `php artisan ghost:laravel-uploads-clean` to remove expired private URL tokens.
- Use `expose => true` only for upload fields that are safe to return in API responses.
- Use `Uploads::resolvePublicUrlsUsing(...)` or `urls.public_resolver` for multi-tenant public upload URLs.
