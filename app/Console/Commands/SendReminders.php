<?php

namespace App\Console\Commands;

use App\Models\Reminder;
use App\Notifications\DailyNudge;
use App\Services\ScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Eloquent\Builder;

class SendReminders extends Command
{
    protected $signature = 'reminders:send';

    protected $description = 'Send web-push nudges for reminders whose scheduled time has passed today';

    public function handle(ScheduleService $schedule): int
    {
        $now = CarbonImmutable::now();

        // schedule holds zero-padded "HH:MM", so text comparison is chronological.
        $due = Reminder::query()
            ->where('enabled', true)
            ->where('kind', 'daily_nudge')
            ->where('schedule', '<=', $now->format('H:i'))
            ->where(fn (Builder $q) => $q
                ->whereNull('last_sent_at')
                ->orWhereDate('last_sent_at', '<', $now->toDateString()))
            ->with('user')
            ->get();

        foreach ($due as $reminder) {
            $dueCount = $schedule->dueOn($reminder->user, $now->startOfDay())->count();

            if ($dueCount === 0) {
                continue; // day already complete — nothing to nudge, no stamp so tomorrow re-evaluates
            }

            $reminder->user->notify(new DailyNudge($dueCount));
            $reminder->update(['last_sent_at' => $now]);
            $this->info("Nudged user {$reminder->user_id}: {$dueCount} due.");
        }

        return self::SUCCESS;
    }
}
