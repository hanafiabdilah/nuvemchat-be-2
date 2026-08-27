<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Live\LiveMonitor;
use Illuminate\Http\Request;

/**
 * Back Office: the platform wallboard.
 *
 * The realtime sibling of the Conversations overview page, and it keeps that
 * page's rule exactly: this is not a reader for message content. What it adds
 * is the axis that one cannot have — right now. A workspace whose queue is
 * building at 09:00 is a support call at 09:20 and a churn risk by lunch; a
 * channel that stopped delivering shows up here as an outbound lane that went
 * quiet while the inbound one kept moving, minutes before anybody reports it.
 *
 * Contact names and phone numbers are masked (LiveMonitor::forPlatform) — they
 * belong to a customer's customer, and nothing an operator does with this page
 * needs them. Tenant identities are not masked: knowing which workspace is on
 * fire is the entire point.
 *
 * The Back Office has no websocket of its own — platform admins hold no tenant
 * and so cannot authorize any of the Reverb channels — so "live" here is a
 * keyset poll. That is also why the payload is split: `full=1` for the roster
 * and counters on a slow tick, the bare delta on a fast one.
 */
class AdminLiveController extends Controller
{
    /** Agents shown across the whole platform. Online only — see below. */
    private const AGENT_LIMIT = 60;

    public function index(Request $request)
    {
        $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'after_id' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.LiveMonitor::MAX_FEED_LIMIT],
            'full' => ['nullable', 'boolean'],
        ]);

        $tenantId = $request->integer('tenant_id') ?: null;
        $monitor = LiveMonitor::forPlatform($tenantId);

        $afterId = $request->filled('after_id') ? (int) $request->integer('after_id') : null;
        $events = $monitor->feed($afterId, (int) $request->integer('limit', LiveMonitor::FEED_LIMIT));

        $payload = [
            'now' => now()->toIso8601String(),
            'cursor' => $events === [] && $afterId !== null ? $afterId : $monitor->cursorFor($events),
            'events' => $events,
        ];

        if ($afterId === null || $request->boolean('full')) {
            // Platform-wide, "every agent" is every agent of every customer —
            // thousands of rows describing mostly empty chairs. The question
            // this page answers is who is working, so only they are listed.
            $payload['pulse'] = $monitor->pulse();
            $payload['status_updates'] = $monitor->statusUpdates();
            $payload['agents'] = $monitor->agents(onlineOnly: true, limit: self::AGENT_LIMIT);
            $payload['tenants'] = $monitor->activeTenants();
        }

        return response()->json(['data' => $this->withTenantNames($payload)]);
    }

    /**
     * Resolve every tenant id mentioned anywhere in the payload to a name, in
     * one query. A tenant has no name of its own — the account's owning user
     * carries it — which is why this is not simply a join in the monitor.
     */
    private function withTenantNames(array $payload): array
    {
        $ids = collect($payload['events'] ?? [])->pluck('tenant_id')
            ->merge(collect($payload['agents'] ?? [])->pluck('tenant_id'))
            ->merge(collect($payload['tenants'] ?? [])->pluck('tenant_id'))
            ->filter()
            ->unique();

        if ($ids->isEmpty()) {
            return $payload;
        }

        $names = Tenant::with('user:id,name')
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (Tenant $tenant) => [
                $tenant->id => $tenant->user?->name ?? "Tenant #{$tenant->id}",
            ]);

        $label = fn (array $row) => $row + [
            'tenant_name' => $names[$row['tenant_id'] ?? null] ?? null,
        ];

        foreach (['events', 'agents', 'tenants'] as $key) {
            if (isset($payload[$key])) {
                $payload[$key] = array_map($label, $payload[$key]);
            }
        }

        return $payload;
    }
}
