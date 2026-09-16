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
        if ($limit <= 0) {
            return ['remaining_bytes' => null, 'unlimited' => true];
        }
        if (!UsageSettings::get('enabled')) {
            return ['remaining_bytes' => null, 'unlimited' => false];
        }

        [$start, $end] = self::cycle($policy);
        $usage = DB::table('v2_usage_traffic')
            ->where('layer', 'nic')
            ->where('machine_id', $machine->id)
            ->whereBetween('bucket', [$start, $end - 1])
            ->selectRaw('COALESCE(SUM(up),0) as incoming, COALESCE(SUM(down),0) as outgoing')
            ->first();
        $incoming = (int) ($usage?->incoming ?? 0);
        $outgoing = (int) ($usage?->outgoing ?? 0);
        $used = match ($policy['direction']) {
            'upload' => $incoming,
            'download' => $outgoing,
            default => $incoming + $outgoing,
        };
        $unit = $policy['unit'] === 'TiB' ? 1099511627776 : 1073741824;
        $limitBytes = (int) round($limit * $unit);

        return [
            'remaining_bytes' => max(0, $limitBytes - $used),
            'unlimited' => false,
        ];
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
