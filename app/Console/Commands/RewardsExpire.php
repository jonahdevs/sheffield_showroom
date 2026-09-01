<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RewardResultStatus;
use App\Enums\ShuffleSessionStatus;
use App\Models\ShuffleResult;
use App\Models\ShuffleSession;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

use function Laravel\Prompts\table;

# Only tidies statuses, never recomputes a date. `isShuffleable()` and
# `isRedeemable()` read `expires_at` directly, so an unswept row is already refused.
class RewardsExpire extends Command
{
    protected $signature = 'rewards:expire {--pretend : Report what would be closed without writing anything}';

    protected $description = 'Close shuffle turns and won rewards whose date has passed';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $pretend = (bool) $this->option('pretend');

        $sessions = ShuffleSession::query()->lapsed($now);
        $results = ShuffleResult::query()->lapsed($now);

        $lapsedSessions = (clone $sessions)->count();
        $lapsedResults = (clone $results)->count();

        if (! $pretend) {
            # Bulk update: nothing observes these transitions today. Adding a
            # model event to either would silently skip it here.
            $sessions->update(['status' => ShuffleSessionStatus::Expired]);
            $results->update(['status' => RewardResultStatus::Expired]);
        }

        table(
            ['Outcome', 'Count', 'Detail'],
            [
                [
                    $pretend ? 'Would close' : 'Closed',
                    $lapsedSessions,
                    'Turns nobody took before the QR expired',
                ],
                [
                    $pretend ? 'Would close' : 'Closed',
                    $lapsedResults,
                    'Rewards won and never redeemed in time',
                ],
            ],
        );

        if ($lapsedSessions === 0 && $lapsedResults === 0) {
            $this->info('Nothing had lapsed.');
        }

        return self::SUCCESS;
    }
}
