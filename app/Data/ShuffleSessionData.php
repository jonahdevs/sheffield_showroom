<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ShuffleSessionStatus;
use App\Models\ShuffleSession;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A turn, as the staff screen showing the QR code reads it.
 *
 * This is the one place the token is deliberately let out, because it is the
 * one place it has to be: the QR code is drawn from `url`, on a screen only an
 * authenticated member of staff can open, and the customer photographs it off
 * that screen. The model hides `token` from serialisation precisely so that
 * every *other* page has to make this decision on purpose.
 */
#[TypeScript(location: ['App', 'Data'])]
class ShuffleSessionData extends Data
{
    public function __construct(
        public int $id,
        public string $customer_name,
        public ShuffleSessionStatus $status,
        public string $status_label,
        /** The address the QR code carries. Nothing else in it identifies anybody. */
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
