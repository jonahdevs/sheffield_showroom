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

    # Only completing is final. Cancelling calls a campaign off and leaves the
    # pool standing, so `CampaignService::activate()` will take it back.
    public static function completed(): self
    {
        return new self(
            'This campaign has been completed. Its results stay on file, but a finished campaign cannot be reopened.',
        );
    }

    public static function closed(): self
    {
        return new self(
            'This campaign is not running. A cancelled one can be restarted; a completed one is finished for good.',
        );
    }
}
