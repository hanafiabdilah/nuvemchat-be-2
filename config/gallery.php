<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media gallery
    |--------------------------------------------------------------------------
    |
    | The tenant's own media library: files uploaded once and sent many times.
    | Nothing here is ever deleted on a timer — a gallery asset is not message
    | media, and `media:purge` does not look at it. The tenant pays for the
    | bytes, and when the space runs out the library goes read-only.
    |
    */

    'disk' => env('GALLERY_DISK', 'local'),

    /*
    | Ceiling per file. Well above what any channel will accept (WhatsApp caps
    | images at 5 MB and video at 16), because the gallery is a library first:
    | a master file kept here can still be too big to send, and refusing the
    | upload for that reason would be this feature deciding what a workspace is
    | allowed to keep.
    */
    'max_upload_mb' => (int) env('GALLERY_MAX_UPLOAD_MB', 64),

    /*
    | Extensions refused outright, whatever MIME type the browser claims.
    |
    | The files live on the private disk and are only ever streamed back with
    | an explicit Content-Type, so none of these could execute here. The reason
    | is the other end: a gallery URL is handed to customers over WhatsApp, and
    | a workspace should not be able to use the platform's domain as the host
    | for an installer. `svg` is on the list for the same reason — it is a
    | document that can carry script, and it renders inline in a browser.
    */
    'blocked_extensions' => [
        'php', 'phtml', 'phar', 'exe', 'msi', 'bat', 'cmd', 'com', 'scr',
        'sh', 'bash', 'zsh', 'ps1', 'jar', 'apk', 'deb', 'rpm', 'dmg',
        'html', 'htm', 'svg', 'xhtml',
    ],

    /*
    |--------------------------------------------------------------------------
    | Commercial defaults
    |--------------------------------------------------------------------------
    |
    | Read through App\Services\Gallery\GalleryPricing, never directly: the
    | Back Office overrides all three in the `settings` table, and a screen that
    | quotes the config default while the API charges the override is a screen
    | that lies to the customer.
    |
    */

    'pricing' => [

        /** What one gigabyte-month costs the tenant, in cents. */
        'price_per_gb_cents' => (int) env('GALLERY_PRICE_PER_GB_CENTS', 190),

        /** Smallest rentable amount. Below this the billing costs more than the sale. */
        'min_rent_gb' => (int) env('GALLERY_MIN_RENT_GB', 1),

        /**
         * Largest amount a tenant can rent from the dashboard.
         *
         * A ceiling, not a capacity plan: it exists so a typo in a number field
         * cannot take a four-figure sum out of a balance in one click. Raise it
         * in the Back Office when somebody genuinely wants more.
         */
        'max_rent_gb' => (int) env('GALLERY_MAX_RENT_GB', 500),

    ],

];
