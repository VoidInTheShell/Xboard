<?php

namespace App\Services\Usage;

use App\Models\ServerMachine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MachineTrafficService
{
    public function summaries(array $machineIds): array
    {
        $ids = collect($machineIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return ServerMachine::whereIn('id', $ids)->get()->mapWithKeys(
            fn (ServerMachine $machine) => [$machine->id => $this->summary($machine)]
        )->all();
    }

    public function summary(ServerMachine $machine): array
    {
        $policy = array_replace(self::defaultPolicy(), (array) ($machine->traffic_policy ?? []));
        $limit = (float) $policy['limit'];
        $unit = $policy['unit'] === 'TiB' ? 1099511627776 : 1073741824;
        if ($limit <= 0) {
            return ['remaining_bytes' => null, 'used_bytes' => null, 'limit_bytes' => null, 'unlimited' => true];
        }
        $limitBytes = (int) round($limit * $unit);
        if (!UsageSettings::get('enabled')) {
            return ['remaining_bytes' => null, 'used_bytes' => null, 'limit_bytes' => $limitBytes, 'unlimited' => false];
        }

        $usage = $this->cycleUsage($machine);
        return [
            'remaining_bytes' => max(0, $limitBytes - $usage['used']),
            'used_bytes' => $usage['used'],
            'limit_bytes' => $limitBytes,
            'unlimited' => false,
        ];
    }

    /**
     * Cycle usage with incoming/outgoing split. A calibration snapshot, when
     * present and still inside the current cycle, replaces the usage counted
     * before it; panel statistics then accumulate from the next full hour.
     */
    public function cycleUsage(ServerMachine $machine): array
    {
        $policy = array_replace(self::defaultPolicy(), (array) ($machine->traffic_policy ?? []));
        [$start, $end] = self::cycle($policy);
        $calibration = $this->activeCalibration($policy, $start);
        $sumFrom = $start;
        $baseline = 0;
        if ($calibration !== null) {
            $baseline = (int) $calibration['used_bytes'];
            $sumFrom = max($start, (intdiv((int) $calibration['at'], 3600) + 1) * 3600);
        }
        $usage = DB::table('v2_usage_traffic')
            ->where('layer', 'nic')
            ->where('machine_id', $machine->id)
            ->whereBetween('bucket', [$sumFrom, $end - 1])
            ->selectRaw('COALESCE(SUM(up),0) as incoming, COALESCE(SUM(down),0) as outgoing')
            ->first();
        $incoming = (int) ($usage?->incoming ?? 0);
        $outgoing = (int) ($usage?->outgoing ?? 0);
        $used = match ($policy['direction']) {
            'upload' => $incoming,
            'download' => $outgoing,
            default => $incoming + $outgoing,
        };

        return [
            'incoming' => $incoming,
            'outgoing' => $outgoing,
            'used' => $baseline + $used,
            'baseline' => $baseline,
            'cycle' => [$start, $end],
            'calibration' => $calibration,
        ];
    }

    /**
     * Return the calibration snapshot only when it was recorded for the cycle
     * that is currently in progress; a reset-day change invalidates it.
     */
    public function activeCalibration(array $policy, int $cycleStart): ?array
    {
        $calibration = (array) ($policy['calibration'] ?? null);
        $usedBytes = (int) ($calibration['used_bytes'] ?? -1);
        $at = (int) ($calibration['at'] ?? 0);
        if ($usedBytes < 0 || $at <= 0 || (int) ($calibration['cycle_start'] ?? 0) !== $cycleStart) {
            return null;
        }

        return ['cycle_start' => $cycleStart, 'used_bytes' => $usedBytes, 'at' => $at];
    }

    public static function defaultPolicy(): array
    {
        return ['limit' => '1', 'unit' => 'TiB', 'resetDay' => '1', 'zone' => 'Asia/Shanghai', 'direction' => 'both', 'warning' => '80'];
    }

    public static function cycle(array $policy, ?CarbonImmutable $now = null): array
    {
        $policy = array_replace(self::defaultPolicy(), $policy);
        $now = ($now ?? CarbonImmutable::now())->setTimezone($policy['zone']);
        $month = $now->startOfMonth();
        $boundary = fn ($value) => $value->addDays(min((int) $policy['resetDay'], $value->daysInMonth) - 1);
        if ($now->lt($boundary($month))) {
            $month = $month->subMonthNoOverflow();
        }

        return [$boundary($month)->timestamp, $boundary($month->addMonthNoOverflow())->timestamp];
    }
}
