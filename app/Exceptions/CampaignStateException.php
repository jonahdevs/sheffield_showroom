<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class CampaignStateException extends RuntimeException
{
    public static function alreadyPublished(): self
    {
        return new self(
            'This campaign has already been published. Its reward quantities are fixed inventory now.',
        );
    }

    public static function nothingToPublish(): self
    {
        return new self(
            'Add at least one reward with a quantity before publishing.',
        );
    }

    # Only one campaign runs at a time: two would leave eligibility guessing
    # which promotion a purchase was measured against.
    public static function anotherIsActive(string $name): self
    {
        return new self(
            "{$name} is already running. Pause or complete it before starting another.",
        );
    }

    public static function notPublished(): self
    {
        return new self(
            'This campaign has not been published yet, so it has no rewards to hand out.',
        );
    }

    public static function closed(): self
    {
        return new self(
            'This campaign is over. Its results stay on file, but it cannot be reopened.',
        );
    }
}
