<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ShuffleSessionStatus;
use App\Models\ShuffleSession;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

# The one place the token is let out, on a staff-only screen. The model hides
# `token` from serialisation so every other page has to decide on purpose.
#[TypeScript(location: ['App', 'Data'])]
class ShuffleSessionData extends Data
{
    public function __construct(
        public int $id,
        public string $customer_name,
        public ShuffleSessionStatus $status,
        public string $status_label,
        public string $url,
        public ?string $expires_at,
        public bool $is_shuffleable,
        public ?ShuffleRewardData $reward = null,
    ) {}

    public static function fromModel(ShuffleSession $session): self
    {
        $result = $session->relationLoaded('result') ? $session->result : null;

        return new self(
            id: $session->id,
            customer_name: $session->customer->displayName(),
            status: $session->status,
            status_label: $session->status->label(),
            url: route('rewards.shuffle.show', $session->token),
            expires_at: $session->expires_at?->toDayDateTimeString(),
            is_shuffleable: $session->isShuffleable(),
            reward: $result === null ? null : ShuffleRewardData::fromModel($result),
        );
    }
}
