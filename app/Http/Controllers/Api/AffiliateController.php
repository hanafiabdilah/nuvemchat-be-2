<?php

namespace App\Http\Controllers\Api;

use App\Enums\Affiliate\CommissionStatus;
use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\Tenant;
use App\Services\Affiliates\AffiliateCommissions;
use App\Services\Affiliates\AffiliateSettings;
use Illuminate\Http\Request;

/**
 * The affiliator's own page: their link, what it brought in, what they earned
 * and were paid, and where they want to be paid.
 *
 * The person's, not the workspace's: no permission is involved, only whether
 * this user was made an affiliator in the Back Office. The workspaces they
 * brought in are other customers, so they are shown by a masked name only.
 */
class AffiliateController extends Controller
{
    public function show(Request $request, AffiliateCommissions $commissions)
    {
        $affiliate = $this->affiliate($request);

        $recent = AffiliateCommission::query()
            ->with(['tenant.user:id,name', 'invoice:id,purpose,period_start,paid_at'])
            ->where('affiliate_id', $affiliate->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (AffiliateCommission $row) => [
                'id' => $row->id,
                'customer' => self::maskedName($row->tenant),
                'purpose' => $row->invoice?->purpose?->value,
                'clawback' => $row->reversal_of_id !== null,
                'base_cents' => $row->base_cents,
                'percent' => $row->percent,
                'amount_cents' => $row->amount_cents,
                'currency' => $row->currency,
                'status' => $row->status === CommissionStatus::Pending
                    ? ($row->available_at && $row->available_at->isPast() ? 'available' : 'holding')
                    : $row->status->value,
                'available_at' => $row->available_at?->toIso8601String(),
                'paid_at' => $row->paid_at?->toIso8601String(),
                'created_at' => $row->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'data' => [
                'code' => $affiliate->code,
                'link' => $affiliate->link(),
                'is_active' => $affiliate->is_active,
                'commission_percent' => $affiliate->commission_percent,
                'commission_type' => $affiliate->commission_type->value,
                'recurring_months' => $affiliate->recurring_months,
                'hold_days' => AffiliateSettings::holdDays(),
                'payout_details' => $affiliate->payout_details,
                'stats' => $commissions->stats($affiliate),
                'balances' => $commissions->balances($affiliate),
                'commissions' => $recent,
                'payouts' => $affiliate->payouts()
                    ->orderByDesc('paid_at')
                    ->limit(50)
                    ->get(['id', 'amount_cents', 'currency', 'method', 'reference', 'paid_at'])
                    ->map(fn ($payout) => [
                        'id' => $payout->id,
                        'amount_cents' => $payout->amount_cents,
                        'currency' => $payout->currency,
                        'method' => $payout->method,
                        'reference' => $payout->reference,
                        'paid_at' => $payout->paid_at?->toIso8601String(),
                    ]),
            ],
        ]);
    }

    /** Where the affiliator wants to be paid: read by whoever pays, by hand. */
    public function updatePayoutDetails(Request $request)
    {
        $affiliate = $this->affiliate($request);

        $validated = $request->validate([
            'holder_name' => ['required', 'string', 'max:120'],
            'document' => ['nullable', 'string', 'max:40'],
            'pix_key' => ['nullable', 'string', 'max:140'],
            // Anything else needed to pay them: bank, branch, account, e-wallet.
            'details' => ['nullable', 'string', 'max:500'],
        ]);

        $affiliate->update(['payout_details' => array_map(
            fn ($value) => is_string($value) ? trim($value) : $value,
            $validated,
        )]);

        return response()->json(['data' => $affiliate->fresh()->payout_details]);
    }

    protected function affiliate(Request $request): Affiliate
    {
        $affiliate = Affiliate::query()->where('user_id', $request->user()->id)->first();

        abort_if($affiliate === null, 404, 'Not an affiliate');

        return $affiliate;
    }

    /** "Ana D." — enough to recognise a referral, not enough to look them up. */
    public static function maskedName(?Tenant $tenant): string
    {
        $name = trim((string) ($tenant?->billing_name ?: $tenant?->user?->name));

        if ($name === '') {
            return '—';
        }

        $parts = preg_split('/\s+/u', $name) ?: [$name];
        $first = $parts[0];
        $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1).'.' : '';

        return trim($first.' '.$last);
    }
}
