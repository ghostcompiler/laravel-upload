<?php

namespace GhostCompiler\UploadsManager\Facades;

use Illuminate\Support\Facades\Facade;

class Uploads extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'uploads-manager';
    }
}
