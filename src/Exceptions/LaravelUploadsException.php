<?php

namespace GhostCompiler\LaravelUploads\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class LaravelUploadsException extends RuntimeException implements ShouldntReport
{
}
