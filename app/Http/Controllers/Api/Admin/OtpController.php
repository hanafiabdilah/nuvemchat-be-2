<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\AdminListing;
use App\Http\Controllers\Controller;
use App\Models\Otp;
use Illuminate\Http\Request;

/**
 * Back Office: list issued OTPs and their state (pending / verified / expired),
 * useful for support and for reading a code when delivery is unavailable.
 */
class OtpController extends Controller
{
    use AdminListing;

    public function index(Request $request)
    {
        // Capped: an uncapped per_page let one request read the whole table.
        $perPage = max(1, min((int) $request->query('per_page', 25), 100));
        $dates = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $from = $dates['from'] ?? null;
        $to = $dates['to'] ?? null;
        $statusFilter = $request->query('status'); // pending | verified | expired
        $search = $request->query('search');       // number, or the user's e-mail / name, contains
        $purpose = $request->query('purpose');     // whatsapp_verification | password_reset

        $otps = Otp::query()
            ->with('user:id,name,email')
            // By the user as well as the number: a password-reset code for an
            // account with no WhatsApp number has no number to search by, and
            // finding it is exactly what support opens this page for.
            ->when($search, fn ($q) => $q->where(fn ($q) => $q
                ->where('whatsapp_number', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($u) => $u
                    ->where('email', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%"))))
            ->when($purpose, fn ($q) => $q->where('purpose', $purpose))
            ->when($from || $to, fn ($q) => $this->whereDayRange($q, 'created_at', $from, $to))
            ->when($statusFilter === 'verified', fn ($q) => $q->whereNotNull('verified_at'))
            ->when($statusFilter === 'pending', fn ($q) => $q->whereNull('verified_at')->where('expires_at', '>', now()))
            ->when($statusFilter === 'expired', fn ($q) => $q->whereNull('verified_at')->where('expires_at', '<=', now()))
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $otps->getCollection()->map(fn (Otp $otp) => [
                'id' => $otp->id,
                'whatsapp_number' => $otp->whatsapp_number,
                'code' => $otp->code,
                'purpose' => $otp->purpose,
                'attempts' => $otp->attempts,
                'status' => $otp->verified_at ? 'verified' : ($otp->isExpired() ? 'expired' : 'pending'),
                'expires_at' => $otp->expires_at?->toIso8601String(),
                'verified_at' => $otp->verified_at?->toIso8601String(),
                'user' => $otp->user ? ['id' => $otp->user->id, 'name' => $otp->user->name, 'email' => $otp->user->email] : null,
                'created_at' => $otp->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $otps->currentPage(),
                'last_page' => $otps->lastPage(),
                'per_page' => $otps->perPage(),
                'total' => $otps->total(),
                'from' => $otps->firstItem(),
                'to' => $otps->lastItem(),
            ],
        ]);
    }
}
