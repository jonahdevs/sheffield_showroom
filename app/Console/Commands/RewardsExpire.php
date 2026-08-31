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

/**
 * Closes the two things in the reward feature that lapse with the calendar.
 *
 * Neither of them has to be swept for the application to behave correctly:
 * `ShuffleSession::isShuffleable()` and `ShuffleResult::isRedeemable()` both
 * read the date rather than trusting the status, so an unswept row is already
 * refused at the point it matters. This exists so the two agree - a list of
 * outstanding rewards that quietly includes a dozen dead ones is a list
 * nobody can act on, and a report counting them as unredeemed says the
 * promotion did worse than it did.
 *
 * Written as a command rather than as a job because it is also the thing you
 * want to be able to run by hand after a campaign closes, and because there is
 * no queue in this application to put it on.
 *
 * Safe to run repeatedly, and safe to run never.
 */
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
            /* Updated in bulk rather than model by model. Nothing observes
               these transitions - there are no events on them and no queue to
               fire one into - so a thousand rows is one statement. */
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
