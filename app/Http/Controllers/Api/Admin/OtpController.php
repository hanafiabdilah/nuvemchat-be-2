<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Otp;
use Illuminate\Http\Request;

/**
 * Back Office: list issued OTPs and their state (pending / verified / expired),
 * useful for support and for reading a code when delivery is unavailable.
 */
class OtpController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 25);
        $statusFilter = $request->query('status'); // pending | verified | expired
        $search = $request->query('search');       // number contains
        $purpose = $request->query('purpose');     // whatsapp_verification | password_reset

        $otps = Otp::query()
            ->with('user:id,name,email')
            ->when($search, fn ($q) => $q->where('whatsapp_number', 'like', "%{$search}%"))
            ->when($purpose, fn ($q) => $q->where('purpose', $purpose))
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
