<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * An administrator asking a campaign to do something its state does not allow.
 *
 * Separate from `ShuffleUnavailableException` because the audience is
 * different: this is somebody at a desk being told they cannot publish an
 * empty campaign, not a customer at a counter being told the promotion is
 * over. These are worth surfacing as validation errors on the form that
 * caused them.
 */
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

    /**
     * Only one campaign runs at a time. Two would leave eligibility guessing
     * which promotion a purchase was measured against, and a customer holding
     * a reward from the wrong one.
     */
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
