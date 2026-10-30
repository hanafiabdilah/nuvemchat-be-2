<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Services\Crm\CrmReports;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * CRM › Overview and Reports. The period is calendar days in the workspace's
 * timezone — a preset or from/to — and every figure is limited to what the
 * reader may see (CrmReports).
 */
class CrmReportController extends Controller
{
    public const PERIODS = ['7d', '30d', '90d', 'this_month', 'last_month', 'this_quarter', 'this_year'];

    public function overview(Request $request)
    {
        return response()->json($this->reports($request)->overview());
    }

    public function sales(Request $request)
    {
        return response()->json($this->reports($request)->sales());
    }

    public function sources(Request $request)
    {
        return response()->json($this->reports($request)->sources());
    }

    public function pipeline(Request $request)
    {
        return response()->json($this->reports($request)->pipeline());
    }

    public function forecast(Request $request)
    {
        $data = $request->validate(['months' => ['nullable', 'integer', 'min:1', 'max:12']]);

        return response()->json($this->reports($request)->forecast((int) ($data['months'] ?? 6)));
    }

    public function activities(Request $request)
    {
        return response()->json($this->reports($request)->activities());
    }

    private function reports(Request $request): CrmReports
    {
        $data = $request->validate([
            'period' => ['nullable', Rule::in(self::PERIODS)],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'owner' => ['nullable', 'string', 'max:20'],
            'pipeline_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $timezone = $user->tenant?->displayTimezone() ?? config('app.timezone');
        $now = Carbon::now($timezone);

        if (! empty($data['from'])) {
            $from = Carbon::parse($data['from'], $timezone)->startOfDay();
            $to = Carbon::parse($data['to'] ?? $data['from'], $timezone)->endOfDay();

            // A year at most: the figures are computed live.
            if ($from->diffInDays($to) > 366) {
                $from = $to->copy()->subDays(366)->startOfDay();
            }
        } else {
            [$from, $to] = match ($data['period'] ?? '30d') {
                '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
                '90d' => [$now->copy()->subDays(89)->startOfDay(), $now->copy()->endOfDay()],
                'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
                'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
                'this_quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfDay()],
                'this_year' => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
                default => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            };
        }

        return new CrmReports($user, [
            'from' => $from->utc(),
            'to' => $to->utc(),
            'timezone' => $timezone,
        ], array_filter([
            'owner' => $data['owner'] ?? null,
            'pipeline_id' => $data['pipeline_id'] ?? null,
        ]));
    }
}
