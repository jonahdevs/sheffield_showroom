<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

# Every one of these is a refusal, not a fault: none should be reported, and the
# messages stay incurious — a customer is not told who already used the code.
class ShuffleUnavailableException extends RuntimeException
{
    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unknown(): self
    {
        return new self(
            'unknown',
            'This reward link is not one we recognise.',
        );
    }

    public static function alreadyUsed(): self
    {
        return new self(
            'already_used',
            'This reward has already been claimed.',
        );
    }

    public static function expired(): self
    {
        return new self(
            'expired',
            'This reward link has expired.',
        );
    }

    public static function cancelled(): self
    {
        return new self(
            'cancelled',
            'This reward link is no longer valid.',
        );
    }

    public static function campaignClosed(): self
    {
        return new self(
            'campaign_closed',
            'This promotion has ended.',
        );
    }

    # Nothing is spent: the turn stays pending, so the customer keeps it if
    # stock is added back.
    public static function poolEmpty(): self
    {
        return new self(
            'pool_empty',
            'There are no rewards left in this promotion.',
        );
    }
}
