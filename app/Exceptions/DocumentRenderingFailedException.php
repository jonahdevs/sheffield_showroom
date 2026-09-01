<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class DocumentRenderingFailedException extends RuntimeException
{
    public function __construct(public readonly string $document, ?Throwable $previous = null)
    {
        parent::__construct(
            "Could not render the {$document} PDF. The renderer needs headless Chrome on this host.",
            previous: $previous,
        );
    }
}
