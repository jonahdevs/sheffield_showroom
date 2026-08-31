<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A shuffle that cannot go ahead, and the reason phrased for the person
 * holding the phone.
 *
 * Every one of these is a refusal rather than a fault: the turn was already
 * taken, the QR has run out, the campaign is over, or the drawer is empty.
 * None of them is worth a stack trace, and none should be reported - which is
 * why the reason is a case on this class rather than free text, so the screen
 * can choose a state to draw from it and the log can stay quiet.
 *
 * The messages are deliberately incurious. A customer does not need to be told
 * which of their friends already used the code.
 */
class ShuffleUnavailableException extends RuntimeException
{
    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** The token names nothing, or names something that is not a turn. */
    public static function unknown(): self
    {
        return new self(
            'unknown',
            'This reward link is not one we recognise.',
        );
    }

    /** Somebody already shuffled it - usually this same person, refreshing. */
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

    /** The campaign is paused, finished, or outside its dates. */
    public static function campaignClosed(): self
    {
        return new self(
            'campaign_closed',
            'This promotion has ended.',
        );
    }

    /**
     * Every unit is gone.
     *
     * Nothing is spent when this happens - the turn stays pending, because the
     * customer did nothing wrong and should keep it if stock is added back.
     */
    public static function poolEmpty(): self
    {
        return new self(
            'pool_empty',
            'There are no rewards left in this promotion.',
        );
    }
}
