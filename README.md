# Laravel Uploads

<p align="center">
  <img src="assets/logo/logo.png" alt="Laravel Uploads Logo" width="180">
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-10%20to%2013-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/Built%20With-Laravel%20Storage-0F172A?style=for-the-badge" alt="Laravel Storage">
</p>

> A Laravel package for upload storage, secure file URLs, model-based URL fields, inline preview support, cleanup tools, and browser-focused image optimization.

## Overview

Laravel Uploads is built to keep file handling simple inside Laravel apps.

It gives you:

- one upload API with an optional custom folder path
- facade usage with `Uploads::upload(...)`
- helper usage with `GhostCompiler()->upload(...)`
- storage through Laravel `Storage`
- default file storage inside `LaravelUploads`
- database tracking for uploaded files
- database tracking for generated links
- model integration through one trait
- clean URL fields like `avatar`
- browser preview support
- forced download support with `?download=1`
- image optimization with AVIF, WEBP, and original-file fallback
- optional aspect-ratio-safe resizing
- expired link cleanup command

## Default Storage

By default, files are stored under:

```text
storage/app/private/LaravelUploads
```

If you pass a custom path like:

```php
Uploads::upload('demo/image', $request->file('avatar'));
```

the file is stored under:

```text
storage/app/private/LaravelUploads/demo/image
```

## Installation

### Install from Packagist

```bash
composer require ghostcompiler/laravel-uploads
```

## Package Install Command

Publish config and package migrations:

```bash
php artisan ghost:laravel-uploads
```

If the files already exist, the command asks before overwriting them.

Overwrite without prompts:

```bash
php artisan ghost:laravel-uploads --force
```

Run migrations:

```bash
php artisan migrate
```

## Database Tables

The package manages two tables:

### `laravel_uploads_uploads`

Stores:

- storage disk
- visibility metadata
- file path
- original file name
- mime type
- extension
- size
- metadata

### `laravel_uploads_links`

Stores:

- upload reference
- generated token
- expiry time
- last accessed time

## Model Setup

Your own model still needs upload ID columns like `avatar_id`, `resume_id`, or `document_id`.

Example migration:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('avatar_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_id');
        });
    }
};
```

Add the trait to your model:

```php
use GhostCompiler\LaravelUploads\Concerns\LaravelUploads;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use LaravelUploads;

    protected $uploadable = [
        'avatar_id' => [
            'name' => 'avatar',
            'type' => 'public',
            'id' => 'hide',
            'expiry' => 60,
        ],
    ];
}
```

## Usage

### Upload with the facade

```php
use GhostCompiler\LaravelUploads\Facades\Uploads;

$upload = Uploads::upload($request->file('avatar'));
```

### Upload into a custom folder

```php
use GhostCompiler\LaravelUploads\Facades\Uploads;

$upload = Uploads::upload('demo/image', $request->file('avatar'));
```

### Upload with the helper

```php
$upload = GhostCompiler()->upload($request->file('avatar'));
```

### Upload with the helper and a path

```php
$upload = GhostCompiler()->upload('demo/image', $request->file('avatar'));
```

### Upload multiple files

```php
use GhostCompiler\LaravelUploads\Facades\Uploads;

$uploads = Uploads::uploadMany($request->file('documents'), 'documents');
```

### Allow specific excluded extensions

```php
use GhostCompiler\LaravelUploads\Facades\Uploads;

$upload = Uploads::upload($request->file('script'), ['sh', 'rb']);
```

Critical extensions configured in `validation.never_allowed_extensions`, such as `php`, `phar`, and `phtml`, cannot be allowed with this override.

### Save the upload ID on a model

```php
$user->avatar_id = $upload->id;
$user->save();
```

### Read the file URL from the model

```php
$user->avatar;
```

### Remove a file

```php
Uploads::remove($user->avatar_id);
```

Or:

```php
GhostCompiler()->remove($user->avatar_id);
```

### Example controller

```php
use App\Models\User;
use GhostCompiler\LaravelUploads\Facades\Uploads;
use Illuminate\Http\Request;

class ApiController
{
    public function uploadAvatar(Request $request)
    {
        $user = auth()->user();

        if ($request->hasFile('avatar')) {
            $upload = Uploads::upload('superadmin.com', $request->file('avatar'));
            $user->avatar_id = $upload->id;
            $user->save();
        }

        return response()->json([
            'user' => $user,
        ]);
    }
}
```

## Response Shape

If `avatar_id` is mapped like:

```php
'avatar_id' => [
    'name' => 'avatar',
]
```

then:

- `avatar_id` stays in the database
- `avatar` becomes the returned URL field

Example API response:

```json
{
  "id": "019d810d-1499-7192-9f99-4a67c5ad350b",
  "name": "Ghost Compiler",
  "email": "ghost@example.com",
  "avatar": "https://your-app.test/_laravel-uploads/file/your-token"
}
```

## Preview and Download Behavior

Previewable files open directly in the browser.
Other files download automatically.

Preview currently supports:

- `image/avif`
- `image/jpeg`
- `image/png`
- `image/gif`
- `image/webp`
- `image/svg+xml`
- `application/pdf`
- `text/plain`

Example preview URL:

```text
https://your-app.test/_laravel-uploads/file/your-token
```

Force download:

```text
https://your-app.test/_laravel-uploads/file/your-token?download=1
```

## Image Optimization

The package can optimize uploaded images globally.

When enabled:

- supported images try AVIF first
- if AVIF is unavailable, the package falls back to WEBP
- if neither conversion path works, the original file is stored
- resizing keeps the original aspect ratio
- images are never upscaled
- browser delivery becomes lighter and faster

Supported input image types:

- `image/jpeg`
- `image/png`
- `image/webp`

### Important note

If this config is disabled, no image conversion happens:

```php
'image_optimization' => [
    'enabled' => false,
]
```

To actually optimize images, enable it:

```php
'image_optimization' => [
    'enabled' => true,
    'quality' => 75,
    'convert_to_avif' => true,
    'max_width' => 1600,
    'max_height' => null,
]
```

In that example:

- width is capped at `1600`
- height is calculated automatically from the original aspect ratio

## Cleanup Command

Delete expired generated links:

```bash
php artisan ghost:laravel-uploads-clean
```

Preview how many expired links would be removed:

```bash
php artisan ghost:laravel-uploads-clean --dry-run
```

## Config Guide

For detailed validation, excluded-extension overrides, local path repository setup, and security-sensitive configuration, see [DEVELOPER.md](DEVELOPER.md).

Published config file:

```php
return [
    'disk' => 'local',

    'base_path' => 'LaravelUploads',

    'defaults' => [
        'type' => 'private',
        'id' => 'hide',
        'expiry' => 60,
    ],

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

    'preview_mime_types' => [
        'image/avif',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
    ],

    'delete_files_with_model' => false,

    'route' => [
        'prefix' => '_laravel-uploads',
        'name' => 'laravel-uploads.show',
        'middleware' => ['web'],
    ],
];
```

### Config key reference

#### `disk`

Laravel disk used for storing package files.

#### `base_path`

Base folder inside the selected Laravel disk.
Paths are normalized as relative disk paths, and unsafe segments like `..` are rejected.

#### `defaults.type`

Default upload visibility metadata used by the package.

#### `defaults.id`

Controls whether the raw upload ID field should remain visible.

#### `defaults.expiry`

Default link expiry in minutes.

#### `validation.max_size`

Maximum upload size in bytes. Set to `null` to disable the package-level size check.

#### `validation.allowed_mime_types`

Optional mime allowlist. Leave empty to allow all non-excluded mime types.

#### `validation.allowed_extensions`

Optional extension allowlist. Leave empty to allow all non-excluded extensions.

#### `validation.excluded_mime_types`

Mime types blocked by default. See [DEVELOPER.md](DEVELOPER.md) for per-upload excluded-extension overrides.

#### `validation.excluded_extensions`

Extensions blocked by default. This list should include executable or script-like extensions such as `php`, `phtml`, `phar`, and `sh`.

#### `validation.never_allowed_extensions`

Extensions that cannot be bypassed by per-upload overrides.

#### `image_optimization.enabled`

Enable or disable global image optimization.

#### `image_optimization.quality`

Compression quality from `1` to `100`.

#### `image_optimization.convert_to_avif`

If enabled, supported images try AVIF first and automatically fall back to WEBP.

#### `image_optimization.max_width`

Optional maximum width for optimized images.
If set by itself, height is calculated automatically from the original aspect ratio.

#### `image_optimization.max_height`

Optional maximum height for optimized images.
If set by itself, width is calculated automatically from the original aspect ratio.

#### `image_optimization.max_input_width`

Maximum source image width allowed before optimization. This helps reject oversized image payloads before GD or Imagick allocates large image resources.

#### `image_optimization.max_input_height`

Maximum source image height allowed before optimization.

#### `image_optimization.max_input_pixels`

Maximum source image pixel count allowed before optimization.

#### `image_optimization.max_output_pixels`

Maximum optimized image pixel count allowed before allocating a resized GD or Imagick image resource.

#### `preview_mime_types`

List of mime types that should open inline in the browser instead of downloading.
SVG is not previewed inline by the package because inline SVG can execute script in the application origin.

#### `delete_files_with_model`

If enabled, deleting the model also deletes the stored file and related upload record.

#### `route.prefix`

URL prefix for generated file links.

#### `route.name`

Laravel route name used internally by the package.

#### `route.middleware`

Middleware applied to generated file serving routes.

## Local Development

### Use this package inside a local Laravel app

1. Add a Composer path repository in the Laravel app.
2. Require `ghostcompiler/laravel-uploads`.
3. Run:

```bash
composer update ghostcompiler/laravel-uploads
php artisan package:discover
php artisan ghost:laravel-uploads
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
php artisan ghost:laravel-uploads
```

Overwrite existing published files without prompts:

```bash
php artisan ghost:laravel-uploads --force
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

This package now includes a PHPUnit + Testbench scaffold.

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
- image optimization only applies to supported images
- AVIF is tried first
- WEBP is used as the main fallback format
- resizing keeps the original aspect ratio
- GD is used first and `Imagick` is used as a fallback encoder when available

## Contribution Checklist

Before opening a pull request, please check:

- code is clean and readable
- syntax checks pass
- tests pass
- new config options are documented
- README is updated when behavior changes
- no unrelated files are included
