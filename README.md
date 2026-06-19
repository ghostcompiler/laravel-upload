<p align="center">
  <img src="https://res.cloudinary.com/djgvfl1tv/image/upload/v1780666791/logo_mqnqn4.png" alt="Laravel Uploads Logo" width="180">
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-10%20to%2013-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/Built%20With-Laravel%20Storage-0F172A?style=for-the-badge" alt="Laravel Storage">
</p>

<p align="center">
    <img src="https://img.shields.io/github/stars/ghostcompiler/laravel-upload?style=for-the-badge&logo=github" />
    <img src="https://img.shields.io/packagist/dt/ghostcompiler/laravel-uploads?style=for-the-badge&logo=packagist" />
</p>

Laravel Uploads manages local/cloud file storage, tracks upload metadata, generates secure tokenized preview URLs, integrates with Eloquent models, and supports real-time image optimization. It also features **on-demand proxy streaming** for remote URLs with zero local disk footprint.

---

## Installation

Install the package via Composer:

```bash
composer require ghostcompiler/laravel-uploads
php artisan install:laravel-uploads
php artisan migrate
```

Use `--force` to overwrite any existing assets:

```bash
php artisan install:laravel-uploads --force
```

---

## Basic Usage

### Uploading a File

```php
use GhostCompiler\LaravelUploads\Facades\Uploads;

// Store a file under configured defaults
$upload = Uploads::upload($request->file('avatar'));

// Store to a specific directory inside the storage path
$upload = Uploads::upload('avatars', $request->file('avatar'));
```

### Uploading from a URL (Dynamic Proxy Streaming)

You can pass a remote URL string directly. The package will register the reference in the database, proxying it on-demand to hide the source URL and bypass local disk storage:

```php
$upload = Uploads::upload('https://avatar.example.com/user123.jpg');
```

### Resolving Secure URLs

Retrieve secure tokenized URLs to stream or preview private files:

```php
// Generates a secure routing URL expiring in 15 minutes
$url = Uploads::url($upload, 15);
```

---

## Full Documentation

For detailed guides on configuration settings, Eloquent trait integrations, custom URL resolvers, image optimization pipelines, and Artisan commands, see the:

👉 **[Laravel Uploads Documentation](https://ghostcompiler.github.io/laravel-upload/)**
