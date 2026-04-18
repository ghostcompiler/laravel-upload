<?php

namespace GhostCompiler\LaravelUploads\Facades;

use Illuminate\Support\Facades\Facade;

class Uploads extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'laravel-uploads';
    }
}
