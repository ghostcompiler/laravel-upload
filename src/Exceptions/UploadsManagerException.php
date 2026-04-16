<?php

namespace GhostCompiler\UploadsManager\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class UploadsManagerException extends RuntimeException implements ShouldntReport
{
}
