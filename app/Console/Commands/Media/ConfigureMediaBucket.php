<?php

namespace App\Console\Commands\Media;

use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Lets dashboards read media from the bucket with fetch().
 *
 * An <img> or <audio> tag needs nothing, but "download" and "copy image" in the
 * chat (components/Chat/mediaActions.ts) fetch the file as a blob, and a blob
 * from another origin needs CORS. Those fetches start at our signed link and
 * are redirected to the bucket; after a cross-origin redirect the browser sends
 * `Origin: null`, so only a wildcard origin matches. That is safe here: every
 * private object is still behind a presigned URL, and the public prefix is
 * public by definition. Read-only methods only.
 */
class ConfigureMediaBucket extends Command
{
    protected $signature = 'media:configure-bucket
                            {disk=media : An S3 disk whose bucket to configure}';

    protected $description = 'Apply the CORS rule dashboards need to fetch media from the bucket';

    public function handle(): int
    {
        $disk = (string) $this->argument('disk');
        $config = (array) config("filesystems.disks.{$disk}");

        if (($config['driver'] ?? null) !== 's3' || empty($config['bucket'])) {
            $this->error("Disk [{$disk}] is not an S3 disk with a bucket.");

            return self::FAILURE;
        }

        $rules = [[
            'AllowedMethods' => ['GET', 'HEAD'],
            'AllowedOrigins' => ['*'],
            'AllowedHeaders' => ['*'],
            'MaxAgeSeconds' => 86400,
        ]];

        $this->client($disk)->putBucketCors([
            'Bucket' => $config['bucket'],
            'CORSConfiguration' => ['CORSRules' => $rules],
        ]);

        $this->info("CORS applied to bucket [{$config['bucket']}]: GET/HEAD from any origin.");

        return self::SUCCESS;
    }

    private function client(string $disk): S3Client
    {
        // Bindable so a test can hand in a client with a mocked handler.
        return app()->bound('media.s3client')
            ? app('media.s3client')
            : Storage::disk($disk)->getClient();
    }
}
