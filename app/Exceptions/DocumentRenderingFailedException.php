<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Typesetting a PDF needs headless Chrome, which is a fact about the machine
 * rather than about the application. A host without it has to say so plainly
 * instead of throwing a browser stack trace at somebody who only asked for a
 * copy of the week's figures.
 */
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
