<?php

namespace App\Services;

use App\Models\User;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Services\Plugin\HookManager;

/**
 * Service for handling traffic reset.
 */
class TrafficResetService
{
  /**
   * Check if a user's traffic should be reset and perform the reset.
   */
  public function checkAndReset(User $user, string $triggerSource = TrafficResetLog::SOURCE_AUTO): bool
  {
    if (!$user->shouldResetTraffic()) {
      return false;
    }

    // $onlyIfDue: performReset re-checks eligibility under the row lock,
    // so a second concurrent caller cannot reset the same user twice.
    return $this->performReset($user, $triggerSource, true);
  }

  /**
   * Perform the traffic reset for a user.
   */
  /**
   * SB-TA-011: one-shot audit metadata channel for the next
   * performReset() call on this instance. manualReset() deposits the
   * caller-collected metadata (reason + admin id) here and
   * performReset() consumes it into the reset log written inside its
   * transaction. A property channel is used instead of a fourth
   * performReset() parameter on purpose: performReset() is an
   * override point (test doubles and downstream subclasses override
   * it with the current three-parameter signature), and widening the
   * signature is a fatal signature-compatibility break for every
   * existing override.
   */
  private array $resetLogMetadata = [];

  public function performReset(User $user, string $triggerSource = TrafficResetLog::SOURCE_MANUAL, bool $onlyIfDue = false): bool
  {
    $metadata = $this->resetLogMetadata;
    $this->resetLogMetadata = [];
    try {
      return DB::transaction(function () use ($user, $triggerSource, $onlyIfDue, $metadata) {
        // SB-AUD-023: lock the user row and work from the freshly read
        // row. Previously the reset read u/d from the caller's possibly
        // stale in-memory model and wrote absolute zeroes without any
        // lock: a traffic increment landing in between was silently
        // wiped (and the reset log recorded values that were never the
        // real counters), and two concurrent checkAndReset callers
        // could both reset. With the row locked, increments serialize
        // against the reset — they are either fully included in the
        // logged old counters or counted in the new period.
        $locked = User::where('id', $user->id)->lockForUpdate()->first();
        if (!$locked) {
          return false;
        }

        if ($onlyIfDue && !$locked->shouldResetTraffic()) {
          return false;
        }

        $oldUpload = $locked->u ?? 0;
        $oldDownload = $locked->d ?? 0;
        $oldTotal = $oldUpload + $oldDownload;

        $nextResetTime = $this->calculateNextResetTime($locked);
        if ($onlyIfDue && $nextResetTime === null) {
          $locked->update(['next_reset_at' => null]);
          $user->next_reset_at = null;
          return false;
        }

        $resetAt = time();
        $resetCount = ($locked->reset_count ?? 0) + 1;
        $nextResetAt = $nextResetTime ? $nextResetTime->timestamp : null;

        $locked->update([
          'u' => 0,
          'd' => 0,
          'last_reset_at' => $resetAt,
          'reset_count' => $resetCount,
          'next_reset_at' => $nextResetAt,
        ]);

        // Mirror the reset fields onto the caller's instance so a later
        // save() on it cannot resurrect the pre-reset counters. Any
        // other in-memory changes on the instance (e.g. an order's
        // surplus credit) are deliberately left untouched.
        $user->forceFill([
          'u' => 0,
          'd' => 0,
          'last_reset_at' => $resetAt,
          'reset_count' => $resetCount,
          'next_reset_at' => $nextResetAt,
        ]);

        $this->recordResetLog($user, [
          'reset_type' => $this->getResetTypeFromPlan($locked->plan),
          'trigger_source' => $triggerSource,
          // SB-TA-011: audit metadata collected by the caller (the
          // admin controller's reason + admin id) must reach the log
          // row written inside this same transaction; manualReset()
          // collected it and silently dropped it before this fix.
          'metadata' => $metadata !== [] ? $metadata : null,
          'old_upload' => $oldUpload,
          'old_download' => $oldDownload,
          'old_total' => $oldTotal,
          'new_upload' => 0,
          'new_download' => 0,
          'new_total' => 0,
        ]);

        $this->clearUserCache($user);
        HookManager::call('traffic.reset.after', $user);
        return true;
      });
    } catch (\Exception $e) {
      Log::error(__('traffic_reset.reset_failed'), [
        'user_id' => $user->id,
        'email' => $user->email,
        'error' => $e->getMessage(),
        'trigger_source' => $triggerSource,
      ]);

      return false;
    }
  }

  /**
   * Calculate the next traffic reset time for a user.
   */
  public function calculateNextResetTime(User $user): ?Carbon
  {
    if (!$user->plan) {
      return null;
    }

    $resetMethod = $user->plan->reset_traffic_method;
    if ($resetMethod === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
      if (!app(Setting::class)->isAvailable()) {
        throw new \RuntimeException('Traffic reset policy unavailable');
      }
      $resetMethod = (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
    }

    if ($user->expired_at === null && $resetMethod !== Plan::RESET_TRAFFIC_MONTHLY) {
      return null;
    }

    $now = Carbon::now(config('app.timezone'));

    return match ($resetMethod) {
      Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => $this->getNextMonthFirstDay($now),
      Plan::RESET_TRAFFIC_MONTHLY => $this->getNextMonthlyReset($user, $now),
      Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => $this->getNextYearFirstDay($now),
      Plan::RESET_TRAFFIC_YEARLY => $this->getNextYearlyReset($user, $now),
      default => null,
    };
  }

  /**
   * Get the first day of the next month.
   *
   * SB-TA-008: build from the first day of the CURRENT month and add
   * one month. The previous addMonth()->startOfMonth() overflows at
   * long month ends: Jan 31 + 1 month lands in early March, and
   * startOfMonth() then returns Mar 1 — February is skipped whole.
   */
  private function getNextMonthFirstDay(Carbon $from): Carbon
  {
    return $from->copy()->startOfMonth()->addMonth();
  }

  /**
   * SB-TA-008: construct a (year, month, anchorDay) candidate with the
   * anchor day clamped to the target month's last day BEFORE any
   * comparison. Carbon's day()/month() setters overflow into the
   * following month (day 31 in a 30-day month, Feb 29 in a non-leap
   * year), so an unclamped candidate can silently become a date in
   * the wrong month and be returned early.
   */
  private function clampedCandidate(int $year, int $month, int $anchorDay, array $time): Carbon
  {
    $lastDay = Carbon::create($year, $month, 1)->endOfMonth()->day;
    return Carbon::create($year, $month, min($anchorDay, $lastDay))->setTime(...$time);
  }

  /**
   * Get the next monthly reset time based on the user's expiration date.
   *
   * Logic:
   * 1. If the user has no expiration date, reset on the 1st of each month.
   * 2. If the user has an expiration date, use the day of that date as the monthly reset day.
   * 3. Prioritize the reset day in the current month if it has not passed yet.
   * 4. Handle cases where the day does not exist in a month (e.g., 31st in February).
   */
  private function getNextMonthlyReset(User $user, Carbon $from): Carbon
  {
    if ($user->expired_at === null) {
      return $this->getNextMonthFirstDay($from);
    }

    $expiredAt = Carbon::createFromTimestamp($user->expired_at, config('app.timezone'));
    $resetDay = $expiredAt->day;
    $resetTime = [$expiredAt->hour, $expiredAt->minute, $expiredAt->second];

    // SB-TA-008: BOTH candidates are clamped before comparison. The
    // previous code clamped only the next-month candidate; the
    // current-month candidate was built with day($resetDay), which
    // overflows into the next month in short months (anchor 29–31 in
    // February, anchor 31 in a 30-day month) and was then returned
    // early as a date in the wrong month.
    $currentMonthTarget = $this->clampedCandidate($from->year, $from->month, $resetDay, $resetTime);
    if ($currentMonthTarget->timestamp > $from->timestamp) {
      return $currentMonthTarget;
    }

    $nextMonth = $from->copy()->startOfMonth()->addMonth();
    return $this->clampedCandidate($nextMonth->year, $nextMonth->month, $resetDay, $resetTime);
  }

  /**
   * Get the first day of the next year.
   */
  private function getNextYearFirstDay(Carbon $from): Carbon
  {
    return $from->copy()->addYear()->startOfYear();
  }

  /**
   * Get the next yearly reset time based on the user's expiration date.
   *
   * Logic:
   * 1. If the user has no expiration date, reset on January 1st of each year.
   * 2. If the user has an expiration date, use the month and day of that date as the yearly reset date.
   * 3. Prioritize the reset date in the current year if it has not passed yet.
   * 4. Handle the case of February 29th in a leap year.
   */
  private function getNextYearlyReset(User $user, Carbon $from): Carbon
  {
    $expiredAt = Carbon::createFromTimestamp($user->expired_at, config('app.timezone'));
    $resetMonth = $expiredAt->month;
    $resetDay = $expiredAt->day;
    $resetTime = [$expiredAt->hour, $expiredAt->minute, $expiredAt->second];

    // SB-TA-008: same defect shape as the monthly path — only the
    // next-year candidate was clamped; the current-year candidate
    // overflowed (Feb 29 anchor in a non-leap year became Mar 1) and
    // could be returned early. Both candidates are now built clamped
    // (clampedCandidate takes the anchor month as the target month).
    $currentYearTarget = $this->clampedCandidate($from->year, $resetMonth, $resetDay, $resetTime);
    if ($currentYearTarget->timestamp > $from->timestamp) {
      return $currentYearTarget;
    }

    return $this->clampedCandidate($from->year + 1, $resetMonth, $resetDay, $resetTime);
  }


  /**
   * Record the traffic reset log.
   */
  private function recordResetLog(User $user, array $data): void
  {
    TrafficResetLog::create([
      'user_id' => $user->id,
      'reset_type' => $data['reset_type'],
      'reset_time' => now(),
      'old_upload' => $data['old_upload'],
      'old_download' => $data['old_download'],
      'old_total' => $data['old_total'],
      'new_upload' => $data['new_upload'],
      'new_download' => $data['new_download'],
      'new_total' => $data['new_total'],
      'trigger_source' => $data['trigger_source'],
      'metadata' => $data['metadata'] ?? null,
    ]);
  }

  /**
   * Get the reset type from the user's plan.
   */
  private function getResetTypeFromPlan(?Plan $plan): string
  {
    if (!$plan) {
      return TrafficResetLog::TYPE_MANUAL;
    }

    $resetMethod = $plan->reset_traffic_method;

    if ($resetMethod === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
      $resetMethod = (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
    }

    return match ($resetMethod) {
      Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => TrafficResetLog::TYPE_FIRST_DAY_MONTH,
      Plan::RESET_TRAFFIC_MONTHLY => TrafficResetLog::TYPE_MONTHLY,
      Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => TrafficResetLog::TYPE_FIRST_DAY_YEAR,
      Plan::RESET_TRAFFIC_YEARLY => TrafficResetLog::TYPE_YEARLY,
      Plan::RESET_TRAFFIC_NEVER => TrafficResetLog::TYPE_MANUAL,
      default => TrafficResetLog::TYPE_MANUAL,
    };
  }

  /**
   * Clear user-related cache.
   */
  private function clearUserCache(User $user): void
  {
    $cacheKeys = [
      "user_traffic_{$user->id}",
      "user_reset_status_{$user->id}",
      "user_subscription_{$user->token}",
    ];

    foreach ($cacheKeys as $key) {
      Cache::forget($key);
    }
  }

  /**
   * Batch check and reset users. Processes all eligible users in batches.
   */
  public function batchCheckReset(int $batchSize = 100, ?callable $progressCallback = null): array
  {
    $startTime = microtime(true);
    $totalResetCount = 0;
    $totalProcessedCount = 0;
    $batchNumber = 1;
    $errors = [];
    $lastProcessedId = 0;

    try {
      do {
        $users = User::where('next_reset_at', '<=', time())
          ->whereNotNull('next_reset_at')
          ->where('id', '>', $lastProcessedId)
          ->where(function ($query) {
            $query->where('expired_at', '>', time())
              ->orWhereNull('expired_at');
          })
          ->where('banned', 0)
          ->whereNotNull('plan_id')
          ->orderBy('id')
          ->limit($batchSize)
          ->get();

        if ($users->isEmpty()) {
          break;
        }

        $batchResetCount = 0;

        if ($progressCallback) {
          $progressCallback([
            'batch_number' => $batchNumber,
            'batch_size' => $users->count(),
            'total_processed' => $totalProcessedCount,
          ]);
        }

        foreach ($users as $user) {
          try {
            if ($this->checkAndReset($user, TrafficResetLog::SOURCE_CRON)) {
              $batchResetCount++;
              $totalResetCount++;
            }
            $totalProcessedCount++;
            $lastProcessedId = $user->id;
          } catch (\Exception $e) {
            $error = [
              'user_id' => $user->id,
              'email' => $user->email,
              'error' => $e->getMessage(),
              'batch' => $batchNumber,
              'timestamp' => now()->toDateTimeString(),
            ];
            $batchErrors[] = $error;
            $errors[] = $error;

            Log::error('User traffic reset failed', $error);

            $totalProcessedCount++;
            $lastProcessedId = $user->id;
          }
        }

        $batchNumber++;

        if ($batchNumber % 10 === 0) {
          gc_collect_cycles();
        }

        if ($batchNumber % 5 === 0) {
          usleep(100000);
        }

      } while (true);

    } catch (\Exception $e) {
      Log::error('Batch traffic reset task failed with an exception', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'total_processed' => $totalProcessedCount,
        'total_reset' => $totalResetCount,
        'last_processed_id' => $lastProcessedId,
      ]);

      $errors[] = [
        'type' => 'system_error',
        'error' => $e->getMessage(),
        'batch' => $batchNumber,
        'last_processed_id' => $lastProcessedId,
        'timestamp' => now()->toDateTimeString(),
      ];
    }

    $totalDuration = round(microtime(true) - $startTime, 2);

    $result = [
      'total_processed' => $totalProcessedCount,
      'total_reset' => $totalResetCount,
      'total_batches' => $batchNumber - 1,
      'error_count' => count($errors),
      'errors' => $errors,
      'duration' => $totalDuration,
      'batch_size' => $batchSize,
      'last_processed_id' => $lastProcessedId,
      'completed_at' => now()->toDateTimeString(),
    ];

    return $result;
  }

  /**
   * Set the initial reset time for a new user.
   */
  public function setInitialResetTime(User $user): void
  {
    if ($user->next_reset_at !== null) {
      return;
    }

    $nextResetTime = $this->calculateNextResetTime($user);

    if ($nextResetTime) {
      $user->update(['next_reset_at' => $nextResetTime->timestamp]);
    }
  }

  /**
   * Get the user's traffic reset history.
   */
  public function getUserResetHistory(User $user, int $limit = 10): \Illuminate\Database\Eloquent\Collection
  {
    return $user->trafficResetLogs()
      ->orderBy('reset_time', 'desc')
      ->limit($limit)
      ->get();
  }

  /**
   * Check if the user is eligible for traffic reset.
   */
  public function canReset(User $user): bool
  {
    return $user->isActive() && $user->plan !== null;
  }

  /**
   * Manually reset a user's traffic (Admin function).
   */
  public function manualReset(User $user, array $metadata = []): bool
  {
    if (!$this->canReset($user)) {
      return false;
    }

    // SB-TA-011: forward the audit metadata (reason + admin id) into
    // the reset transaction so it lands on the reset log — via the
    // one-shot channel documented on $resetLogMetadata.
    $this->resetLogMetadata = $metadata;
    return $this->performReset($user, TrafficResetLog::SOURCE_MANUAL);
  }
}