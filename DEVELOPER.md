# Developer Guide

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
    'quality' => 75,
    'convert_to_avif' => true,
    'max_width' => null,
    'max_height' => null,
    'max_input_width' => 8000,
    'max_input_height' => 8000,
    'max_input_pixels' => 40000000,
    'max_output_pixels' => 16000000,
],
```

Set a max input dimension or pixel value to protect the PHP process from oversized image payloads.

## Path Safety

Upload paths must be relative disk paths. The package rejects:

- absolute paths
- Windows drive paths
- `..` traversal segments
- empty path segments
- control characters

This applies to upload paths, read paths, and delete paths.

## Preview Safety

SVG is not included in the default `preview_mime_types` list because inline SVG can execute script in the application origin. SVG files will download by default unless the application explicitly opts into previewing them.

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
