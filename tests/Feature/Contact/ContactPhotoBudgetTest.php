<?php

use App\Jobs\SyncContactPhoto;
use App\Services\Contact\Photo\PhotoHttp;

/**
 * The contact-photo sync has to finish inside its own job timeout.
 *
 * ⚠️ Not a style rule. A job that hits its timeout does not simply fail —
 * Laravel's worker cannot safely carry on past the alarm, so the whole
 * `queue:work` process exits and everything queued behind it waits for the
 * container to come back, about a minute. On 26 Sep 2026 this job did that
 * twice, and what it stalled was a flow's 3-second pause, which then arrived 63
 * seconds late. The report came in as "the flow's timer is not respected"; the
 * cause was these numbers drifting apart (3 lookups × 20s + a 30s download
 * against a hand-written 60s timeout).
 */
it('leaves the job enough room for the slowest lookup it can make', function () {
    expect(PhotoHttp::BUDGET)->toBeLessThan((new SyncContactPhoto(
        new App\Models\Contact,
        new App\Models\Connection,
    ))->timeout);
});

it('leaves headroom for the work that is not an HTTP call', function () {
    // Hashing the image and writing it to object storage is a round trip to
    // another region, and it happens after the budget above is already spent.
    $job = new SyncContactPhoto(new App\Models\Contact, new App\Models\Connection);

    expect($job->timeout - PhotoHttp::BUDGET)->toBeGreaterThanOrEqual(20);

    // The budget is the sum of what it allows, not a number typed next to it.
    expect(PhotoHttp::MAX_LOOKUPS * PhotoHttp::LOOKUP_TIMEOUT + PhotoHttp::DOWNLOAD_TIMEOUT)
        ->toBe(PhotoHttp::BUDGET);
});

/**
 * Every network call on this path goes through PhotoHttp.
 *
 * A resolver that sets its own timeout is exactly how the budget drifted out of
 * step with the job in the first place, and the failure it produces is a dead
 * queue worker rather than anything that looks like a photo problem.
 */
it('routes every photo call through the shared budget', function () {
    $files = array_merge(
        glob(app_path('Services/Contact/Photo/Resolvers/*.php')),
        [app_path('Services/Contact/Photo/ContactPhotoSyncer.php')],
    );

    foreach ($files as $file) {
        expect(file_get_contents($file))
            ->not->toMatch('/Http::(timeout|connectTimeout)\(/', basename($file) . ' sets its own HTTP timeout');
    }
});

it('keeps a profile picture off the queue a flow reply waits on', function () {
    // Worth having, worth nothing urgently — and network-bound against whichever
    // channel is slow today, which is the wrong neighbour for a flow's next
    // bubble. With MEDIA_QUEUE unset this is still `default`, so it needs no
    // deployment step to be correct.
    config(['queue.media' => 'media']);

    $job = new SyncContactPhoto(new App\Models\Contact, new App\Models\Connection);

    expect($job->queue)->toBe('media');
});
