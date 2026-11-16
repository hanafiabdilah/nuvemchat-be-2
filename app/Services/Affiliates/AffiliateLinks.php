<?php

namespace App\Services\Affiliates;

use App\Models\Affiliate;
use App\Models\AffiliateClick;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The link half of the affiliate program: who a visitor is locked to, and the
 * workspace they then create.
 *
 * The lock is a cookie on the platform host, set by the server when the link
 * is opened (/r/{code}). Server-set, because a script-written value is the
 * first thing a browser's tracking protection expires; on the platform host,
 * because that is where signup happens — the marketing site the visitor is
 * sent on to only links back here.
 *
 * ⚠️ First touch wins. A visitor already locked to an affiliator stays with
 * them when they open another affiliator's link: "locked until they sign up"
 * means nobody can take them over on the way.
 */
class AffiliateLinks
{
    public const COOKIE = 'pingly_ref';

    public function findActive(?string $code): ?Affiliate
    {
        $code = Affiliate::normalizeCode($code);

        if ($code === '' || strlen($code) > 32) {
            return null;
        }

        return Affiliate::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->first();
    }

    /**
     * Count one visitor once a day. The address is only kept as a keyed hash,
     * and only to tell a second visit from a new visitor.
     */
    public function recordClick(Affiliate $affiliate, ?string $ip): void
    {
        try {
            AffiliateClick::query()->insertOrIgnore([
                'affiliate_id' => $affiliate->id,
                'visitor_hash' => hash_hmac('sha256', (string) $ip, (string) config('app.key')),
                'day' => now()->toDateString(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // A count that misses one visit is fine; a link that errors is not.
            Log::warning('Affiliate click not recorded', ['affiliate_id' => $affiliate->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The affiliator a signup belongs to: the cookie first (first touch), then
     * a code the sign-up page carried over from its own URL, for a browser
     * that never received the cookie.
     */
    public function forSignup(Request $request): ?Affiliate
    {
        return $this->findActive($request->cookie(self::COOKIE))
            ?? $this->findActive($request->input('ref'));
    }

    /**
     * Lock a new workspace to its affiliator, for good. Never the affiliator's
     * own workspace: earning on your own plan is a discount, not a referral.
     */
    public function attach(Tenant $tenant, Affiliate $affiliate, ?User $signedUp = null): bool
    {
        $owner = $affiliate->user;

        if ($owner === null || (int) $owner->tenant_id === (int) $tenant->id) {
            return false;
        }

        // The same person signing up again under another e-mail is still
        // themselves: the WhatsApp number is the one thing they verify.
        if ($signedUp && $owner->whatsapp_number && $owner->whatsapp_number === $signedUp->whatsapp_number) {
            return false;
        }

        $tenant->forceFill([
            'referred_by_affiliate_id' => $affiliate->id,
            'referred_at' => now(),
        ])->save();

        return true;
    }
}
