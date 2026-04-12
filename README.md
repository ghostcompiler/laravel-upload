# Uploads Manager

<p align="center">
  <img src="assets/logo/logo.png" alt="Uploads Manager Logo" width="180">
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-9%20to%2013-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/Built%20With-Laravel%20Storage-0F172A?style=for-the-badge" alt="Laravel Storage">
</p>

> A Laravel package for upload storage, secure file URLs, model-based URL fields, inline preview support, file removal, and optional image optimization to AVIF.

## Overview

Uploads Manager is built to keep file handling simple inside Laravel apps.

It gives you:

- one upload method for `public` and `private` files
- automatic storage using Laravel `Storage`
- fixed upload folders inside `storage/app/UploadsManager`
- database tracking for uploaded files
- database tracking for generated file links
- model integration through one trait
- hidden raw upload IDs with clean URL fields like `avatar`
- browser preview for supported file types
- forced download support with `?download=1`
- optional image optimization with AVIF conversion

## Folder structure

By default, files are stored here:

```text
storage/app/UploadsManager/public
storage/app/UploadsManager/private
```

## Database tables

The package manages two tables:

### `uploads_manager_uploads`

Stores:

- storage disk
- visibility
- file path
- original file name
- mime type
- extension
- size
- metadata

### `uploads_manager_links`

Stores:

- upload reference
- generated token
- expiry time
- last accessed time

This allows the package to create and manage URLs in a clean way.

## Installation Guide

### Step 1: Install the package

```bash
composer require ghostcompiler/uploads-manager
```

### Step 2: Publish config and migrations

```bash
php artisan uploads-manager:install
```

This publishes:

- `config/uploads-manager.php`
- migration for `uploads_manager_uploads`
- migration for `uploads_manager_links`

### Step 3: Run migrations

```bash
php artisan migrate
```

### Step 4: Add upload ID columns to your own tables

The package stores file metadata in its own tables, but your own models still need a field like `avatar_id`, `resume_id`, or `document_id`.

Example:

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

### Step 5: Add the trait to your model

Import this trait:

```php
use GhostCompiler\UploadsManager\Concerns\UploadsManager;
```

Then use it in your model:

```php
use GhostCompiler\UploadsManager\Concerns\UploadsManager;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use UploadsManager;
}
```

This trait is required for automatic URL field handling.

### Step 6: Define uploadable fields

Now define `protected $uploadable` on the model.

Example:

```php
use GhostCompiler\UploadsManager\Concerns\UploadsManager;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use UploadsManager;

    protected $uploadable = [
        'avatar_id' => [
            'name' => 'avatar',
            'type' => 'public',
            'id' => 'hide',
            'expiry' => 100,
        ],
        'resume_id' => [
            'name' => 'resume',
            'type' => 'private',
            'id' => 'hide',
            'expiry' => 30,
        ],
    ];
}
```

## Quick Usage

### Upload a file

```php
use GhostCompiler\UploadsManager\Facades\Uploads;

$upload = Uploads::upload('public', $request->file('avatar'));

$user->avatar_id = $upload->id;
$user->save();
```

### Read file URL from the model

```php
$user->avatar;
```

If `avatar_id` is mapped with:

```php
'avatar_id' => [
    'name' => 'avatar',
]
```

then:

- `avatar_id` stays in the database
- `avatar` becomes the returned URL field

### Remove a file

```php
Uploads::remove($user->avatar_id);
```

## Full Example

### Example model

```php
<?php

namespace App\Models;

use GhostCompiler\UploadsManager\Concerns\UploadsManager;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'password', 'role', 'avatar_id'])]
#[Hidden(['password'])]
class User extends Authenticatable
{
    use Notifiable, HasUuids, HasApiTokens, UploadsManager;

    protected $casts = [
        'password' => 'hashed',
    ];

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

### Example controller logic

```php
use App\Models\User;
use GhostCompiler\UploadsManager\Facades\Uploads;
use Illuminate\Http\Request;

public function updateProfile(Request $request)
{
    $user = auth()->user();

    if ($request->hasFile('avatar')) {
        $upload = Uploads::upload('public', $request->file('avatar'));
        $user->avatar_id = $upload->id;
    }

    $user->save();

    return response()->json([
        'user' => $user,
    ]);
}
```

### Example API response shape

```json
{
  "id": "019d810d-1499-7192-9f99-4a67c5ad350b",
  "name": "Ghost Compiler",
  "email": "ghost@example.com",
  "avatar": "https://your-app.test/_uploads-manager/file/your-token"
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
https://your-app.test/_uploads-manager/file/your-token
```

If you want to force download for any file:

```text
https://your-app.test/_uploads-manager/file/your-token?download=1
```

## Image Optimization

The package can optimize uploaded images globally.

When enabled:

- resolution stays the same
- file size is reduced
- supported images are converted to AVIF
- browser delivery becomes lighter and faster

Supported input image types:

- `image/jpeg`
- `image/png`
- `image/webp`

If the server cannot encode AVIF, the package falls back to the original uploaded file.

## Config Guide

Published config file:

```php
return [
    'disk' => 'local',

    'base_path' => 'UploadsManager',

    'paths' => [
        'public' => 'public',
        'private' => 'private',
    ],

    'defaults' => [
        'type' => 'private',
        'id' => 'hide',
        'expiry' => 60,
    ],

    'image_optimization' => [
        'enabled' => false,
        'quality' => 75,
        'convert_to_avif' => true,
    ],

    'preview_mime_types' => [
        'image/avif',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'application/pdf',
        'text/plain',
    ],

    'delete_files_with_model' => false,

    'route' => [
        'prefix' => '_uploads-manager',
        'name' => 'uploads-manager.show',
        'middleware' => ['web'],
    ],
];
```

### Config key reference

#### `disk`

Laravel disk used for storing package files.

Example:

```php
'disk' => 'local'
```

#### `base_path`

Base folder inside the selected Laravel disk.

Example:

```php
'base_path' => 'UploadsManager'
```

#### `paths.public`

Subfolder used for public uploads.

Default result:

```text
UploadsManager/public
```

#### `paths.private`

Subfolder used for private uploads.

Default result:

```text
UploadsManager/private
```

#### `defaults.type`

Default upload type for model mapping when not explicitly given.

Allowed values:

- `public`
- `private`

#### `defaults.id`

Controls whether the raw upload ID field should remain visible.

Recommended:

```php
'id' => 'hide'
```

#### `defaults.expiry`

Default link expiry in minutes.

Example:

```php
'expiry' => 60
```

#### `image_optimization.enabled`

Enable or disable global image optimization.

Example:

```php
'enabled' => true
```

#### `image_optimization.quality`

Compression quality from `1` to `100`.

- lower value = smaller file size
- higher value = better visual quality

Recommended starting point:

```php
'quality' => 75
```

#### `image_optimization.convert_to_avif`

If enabled, supported uploaded images are converted to AVIF.

Example:

```php
'convert_to_avif' => true
```

#### `preview_mime_types`

List of mime types that should open inline in the browser instead of downloading.

#### `delete_files_with_model`

If enabled, deleting the model also deletes the stored file and related upload record.

Example:

```php
'delete_files_with_model' => true
```

#### `route.prefix`

URL prefix for generated file links.

Default:

```text
_uploads-manager
```

#### `route.name`

Laravel route name used internally by the package.

#### `route.middleware`

Middleware applied to generated file serving routes.

Default:

```php
['web']
```

## Uploadable Field Options

Each entry in `protected $uploadable` supports:

### `name`

The returned attribute name.

Example:

```php
'name' => 'avatar'
```

This means:

- database field: `avatar_id`
- returned URL field: `avatar`

### `type`

Upload visibility type.

Allowed values:

- `public`
- `private`

### `id`

Controls whether the original DB ID field should be visible in serialized output.

Recommended:

```php
'id' => 'hide'
```

### `expiry`

Custom link expiry for this field in minutes.

Example:

```php
'expiry' => 100
```

## Notes

- `Uploads::upload()` only accepts `public` or `private`
- generated URLs are tracked in the database
- file preview and download behavior are handled automatically
- image optimization only applies to supported image uploads
- AVIF conversion needs GD support with AVIF enabled on the server
- `ext-gd` is suggested for image compression support

## Recommended Setup Example

```php
protected $uploadable = [
    'avatar_id' => [
        'name' => 'avatar',
        'type' => 'public',
        'id' => 'hide',
        'expiry' => 60,
    ],
    'document_id' => [
        'name' => 'document',
        'type' => 'private',
        'id' => 'hide',
        'expiry' => 15,
    ],
];
```

This gives you:

- `$user->avatar` for a public previewable file URL
- `$user->document` for a private file URL
- hidden raw upload ID fields in responses

## Developer Guide

If you want to improve the package or contribute features, this section is for you.

### Local development flow

1. Clone the repository.
2. Create your feature branch.
3. Make your changes.
4. test the package inside a Laravel app.
5. update the README if behavior changes.
6. open a pull request with a clear explanation.

Example:

```bash
git clone <your-repo-url>
cd UploadsManager
git checkout -b feature/your-feature-name
```

### Suggested development setup

Use this package with a local Laravel test project so you can verify:

- uploads work
- generated URLs open correctly
- preview and forced download both work
- model serialization returns the expected URL fields
- image optimization works when enabled

### Before opening a pull request

Please check:

- code is clean and readable
- package behavior is documented
- no unrelated files are changed
- syntax checks pass
- new config options are documented

### Contribution ideas

Some good areas to contribute:

- more file transformation options
- better test coverage
- artisan helpers for adding upload columns
- cleanup tools for expired links
- support for more image drivers

### Support the project

If this package helps you, please support it:

- star the repository
- share it with other Laravel developers
- open issues with clear reproduction steps
- contribute fixes and improvements

### How to report bugs

When opening an issue, include:

- Laravel version
- PHP version
- package version or branch
- database driver
- exact error message
- steps to reproduce

### Pull request notes

A good pull request should include:

- what changed
- why it changed
- how it was tested
- whether README or config docs were updated
