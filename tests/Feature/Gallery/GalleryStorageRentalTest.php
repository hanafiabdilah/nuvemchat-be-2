<?php

use App\Enums\Gallery\StorageRentalStatus;
use App\Models\CreditTransaction;
use App\Models\GalleryAsset;
use App\Models\GalleryStorageRental;
use App\Models\Setting;
use App\Services\Credits\CreditService;
use App\Services\Gallery\GalleryPricing;
use App\Services\Gallery\GalleryRentalService;
use App\Services\Gallery\GalleryStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GalleryFixtures;

/**
 * Renting library space: the asymmetry between growing and shrinking, and what
 * the deadline does when nobody can pay.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    cache()->flush();
    Setting::set(GalleryPricing::KEY_PRICE_PER_GB_CENTS, '200'); // R$ 2,00 / GB / month
    Setting::set(GalleryPricing::KEY_MIN_RENT_GB, '1');
    Setting::set(GalleryPricing::KEY_MAX_RENT_GB, '500');
});

it('rents space for a full month and grants it immediately', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1, balanceCents: 10_000);
    Sanctum::actingAs($tenant->user);

    $this->putJson('/api/gallery/storage', ['gb' => 10])
        ->assertOk()
        ->assertJsonPath('data.rental.gb', 10)
        ->assertJsonPath('data.storage.rented_gb', 10);

    // Plan + rental, added up in one place so no screen can disagree with the
    // check that gates an upload.
    expect(app(GalleryStorage::class)->limitBytes($tenant->fresh()))
        ->toBe(11 * GalleryStorage::BYTES_PER_GB);

    expect(app(CreditService::class)->balanceCents($tenant))->toBe(10_000 - 2_000);
});

it('refuses to rent what the balance will not cover, and names the shortfall', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1, balanceCents: 500);
    Sanctum::actingAs($tenant->user);

    $this->putJson('/api/gallery/storage', ['gb' => 10])
        ->assertStatus(422)
        ->assertJsonPath('code', 'insufficient_credit')
        ->assertJsonPath('shortfall_cents', 1_500);

    // A row that was created to hang the charge reference on must not be left
    // granting free space.
    $rental = GalleryStorageRental::where('tenant_id', $tenant->id)->first();
    expect($rental?->status)->not->toBe(StorageRentalStatus::Active)
        ->and(app(GalleryStorage::class)->rentedGb($tenant->fresh()))->toBe(0);
});

it('charges only the rest of the cycle when the amount goes up', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1, balanceCents: 50_000);
    $rentals = app(GalleryRentalService::class);

    $this->travelTo(now()->startOfDay());
    $rentals->setAmount($tenant, 10);
    $spentOnFirstMonth = 10 * 200;

    // Half-way through the paid month, add another 10 GB.
    $this->travelTo(now()->addDays(15));
    $rentals->setAmount($tenant->fresh(), 20);

    $charged = app(CreditService::class)->balanceCents($tenant) - 50_000;
    $prorated = abs($charged) - $spentOnFirstMonth;

    // A full month for the added 10 GB would be 2000 cents; roughly half the
    // cycle is left, so anything near a full month means the proration was
    // skipped.
    expect($prorated)->toBeGreaterThan(700)->toBeLessThan(1_300);

    expect(app(GalleryStorage::class)->rentedGb($tenant->fresh()))->toBe(20);
});

it('schedules a reduction for the renewal instead of taking back a paid month', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1, balanceCents: 50_000);
    Sanctum::actingAs($tenant->user);

    $this->putJson('/api/gallery/storage', ['gb' => 20])->assertOk();
    $balanceAfterRent = app(CreditService::class)->balanceCents($tenant);

    $this->putJson('/api/gallery/storage', ['gb' => 5])
        ->assertOk()
        ->assertJsonPath('data.rental.gb', 20)        // still theirs today
        ->assertJsonPath('data.rental.pending_gb', 5); // and 5 from the renewal

    // Nothing is charged and nothing is refunded: the month is already paid.
    expect(app(CreditService::class)->balanceCents($tenant))->toBe($balanceAfterRent)
        ->and(app(GalleryStorage::class)->rentedGb($tenant->fresh()))->toBe(20);
});

it('quotes the change before it happens', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1, balanceCents: 300);
    Sanctum::actingAs($tenant->user);

    $this->postJson('/api/gallery/storage/quote', ['gb' => 10])
        ->assertOk()
        ->assertJsonPath('data.charge_now_cents', 2_000)
        ->assertJsonPath('data.balance_cents', 300)
        // Stated before the button, not discovered after the commit.
        ->assertJsonPath('data.shortfall_cents', 1_700);

    expect(GalleryStorageRental::count())->toBe(0);
});

it('charges a cycle once even when the pass dies after taking the money', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1, balanceCents: 50_000);
    $rentals = app(GalleryRentalService::class);

    $rentals->setAmount($tenant, 10);
    $rental = GalleryStorageRental::where('tenant_id', $tenant->id)->first();
    $due = now()->addDay();
    $rental->forceFill(['renews_at' => $due])->save();

    $balanceBefore = app(CreditService::class)->balanceCents($tenant);

    expect($rentals->chargeRenewal($rental->fresh()))->toBeTrue();

    // The failure the ledger reference exists for: the debit landed, the row
    // never advanced (worker killed in between), and the next pass sees the
    // same cycle still due. It must not take the money twice.
    //
    // Written straight to the table: the in-memory model still holds the old
    // date, so an Eloquent save would find nothing dirty and quietly do nothing.
    GalleryStorageRental::whereKey($rental->id)->toBase()->update(['renews_at' => $due]);

    expect($rentals->chargeRenewal($rental->fresh()))->toBeTrue()
        ->and(app(CreditService::class)->balanceCents($tenant))->toBe($balanceBefore - 2_000);
});

it('catches up from today instead of billing every month the scheduler missed', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1, balanceCents: 50_000);
    $rentals = app(GalleryRentalService::class);

    $rentals->setAmount($tenant, 10);
    $rental = GalleryStorageRental::where('tenant_id', $tenant->id)->first();
    // Three cycles went by with the pass down.
    $rental->forceFill(['renews_at' => now()->subMonths(3)])->save();

    $balanceBefore = app(CreditService::class)->balanceCents($tenant);
    $rentals->chargeRenewal($rental->fresh());

    // One month charged, and the next date is a month from now — not a queue
    // of back-charges the customer was never warned about.
    expect(app(CreditService::class)->balanceCents($tenant))->toBe($balanceBefore - 2_000)
        ->and($rental->fresh()->renews_at->isFuture())->toBeTrue();
});

it('applies a scheduled reduction at the renewal, and charges the smaller size', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1, balanceCents: 50_000);
    $rentals = app(GalleryRentalService::class);

    $rentals->setAmount($tenant, 20);
    $rentals->setAmount($tenant->fresh(), 5);

    $rental = GalleryStorageRental::where('tenant_id', $tenant->id)->first();
    $rental->forceFill(['renews_at' => now()->addDay()])->save();

    $balanceBefore = app(CreditService::class)->balanceCents($tenant);
    $rentals->chargeRenewal($rental->fresh());

    $rental->refresh();

    expect($rental->gb)->toBe(5)
        ->and($rental->pending_gb)->toBeNull()
        // 5 GB, not the 20 the customer asked us to stop charging for.
        ->and(app(CreditService::class)->balanceCents($tenant))->toBe($balanceBefore - 1_000);
});

it('cancels at the renewal without charging another month', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1, balanceCents: 50_000);
    Sanctum::actingAs($tenant->user);
    $rentals = app(GalleryRentalService::class);

    $rentals->setAmount($tenant, 10);
    $this->deleteJson('/api/gallery/storage')->assertOk()->assertJsonPath('data.rental.pending_gb', 0);

    $rental = GalleryStorageRental::where('tenant_id', $tenant->id)->first();
    $rental->forceFill(['renews_at' => now()->addDay()])->save();

    $balanceBefore = app(CreditService::class)->balanceCents($tenant);
    $rentals->chargeRenewal($rental->fresh());

    expect($rental->fresh()->status)->toBe(StorageRentalStatus::Cancelled)
        ->and(app(CreditService::class)->balanceCents($tenant))->toBe($balanceBefore);
});

it('ends an unpayable rental at the deadline and deletes nothing', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1, balanceCents: 2_000);
    $rentals = app(GalleryRentalService::class);

    $rentals->setAmount($tenant, 10);           // spends the whole balance
    $asset = GalleryFixtures::asset($tenant, 5_000_000);

    $rental = GalleryStorageRental::where('tenant_id', $tenant->id)->first();
    $rental->forceFill(['renews_at' => now()->subMinute()])->save();

    $this->artisan('gallery:renew')->assertSuccessful();

    $rental->refresh();

    expect($rental->status)->toBe(StorageRentalStatus::Cancelled)
        ->and($rental->meta['cancel_reason'])->toBe('no_credit')
        ->and(app(GalleryStorage::class)->rentedGb($tenant->fresh()))->toBe(0);

    // The whole difference from a lapsed number: the allowance goes, the files
    // stay. Nothing in this codebase deletes a gallery asset on a timer.
    expect(GalleryAsset::find($asset->id))->not->toBeNull();
});

it('warns before the charge window rather than only at the deadline', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1, balanceCents: 2_000);
    $rentals = app(GalleryRentalService::class);

    $rentals->setAmount($tenant, 10); // spends the balance

    $rental = GalleryStorageRental::where('tenant_id', $tenant->id)->first();
    $rental->forceFill(['renews_at' => now()->addDays(5)])->save();

    $this->artisan('gallery:renew')->assertSuccessful();

    $rental->refresh();

    // Nothing charged and nothing ended — but the tenant has been told, five
    // days before the space would go.
    expect($rental->status)->toBe(StorageRentalStatus::Active)
        ->and($rental->renewal_reminder_sent_at)->not->toBeNull()
        // Only the original rent: the pass warned, it did not reach for money
        // that is not due for another five days.
        ->and(CreditTransaction::where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('refuses to sell less than the platform minimum, but always allows zero', function () {
    Setting::set(GalleryPricing::KEY_MIN_RENT_GB, '5');
    cache()->flush();

    $tenant = GalleryFixtures::tenant(planGb: 1, balanceCents: 50_000);
    Sanctum::actingAs($tenant->user);

    $this->putJson('/api/gallery/storage', ['gb' => 2])
        ->assertStatus(422)
        ->assertJsonPath('code', 'below_minimum');

    // Zero is how somebody stops renting, so it can never be below a floor.
    $this->putJson('/api/gallery/storage', ['gb' => 0])->assertOk();
});
