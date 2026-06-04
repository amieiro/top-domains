<?php

namespace App\Console\Commands;

use App\Support\WordPressDetector;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CheckWordPressCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'top-domains:check-wp
		{--resume : Resume the last incomplete batch instead of starting a new one}
		{--request_timeout= : Timeout in seconds for each HTTP request (default: 10)}
		{--connect_timeout= : Timeout in seconds for establishing the connection (default: 4)}
		{--domains_per_batch= : Number of domains to process per batch (default: 200)}
		{--concurrent_requests= : Number of concurrent HTTP requests (default: 200)}
		{--show_temp_results_every= : Show temporary results every X websites tested (default: 200)}
		{--domain_offset= : Number of domains to skip from the top of the list (default: 600000)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check if websites in the domains table are using WordPress.';

    /**
     * The user agent to use for the requests.
     */
    protected string $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36';

    /**
     * The timeout for each request.
     */
    protected int $request_timeout = 10;

    /**
     * The timeout for establishing the connection. Failing fast on dead hosts
     * frees a concurrency slot instead of waiting for the full request timeout.
     */
    protected int $connect_timeout = 4;

    /**
     * The number of domains to process in each batch.
     */
    protected int $domains_per_batch = 200;

    /**
     * The number of concurrent requests to send.
     */
    protected int $concurrent_requests = 200;

    /**
     * Show temporary results every X websites tested.
     */
    protected int $show_temp_results_every = 200;

    /**
     * Maximum number of body bytes to read and inspect per response. WordPress
     * fingerprints live in the document head and early body, so a cap keeps
     * parsing cheap on media-heavy pages without losing detections.
     */
    protected int $max_body_bytes = 131072;

    /**
     * The start time of the batch processing.
     */
    protected Carbon $start_time;

    /**
     * Count of domains, WordPress, not WordPress, and no reply domains in the current batch.
     */
    protected int $domainsProcessed = 0;

    protected int $wordpressCount = 0;

    protected int $notWordPressCount = 0;

    protected int $noReplyCount = 0;

    /**
     * The number of domains to skip from the top of the list.
     */
    protected int $domain_offset = 600000;

    /**
     * The first domain id to process, derived once from the offset so that
     * pagination is keyset based instead of a costly per-iteration OFFSET.
     */
    protected int $start_id = 0;

    /**
     * Whether the application is in debug mode.
     */
    protected bool $appDebug;

    /**
     * A single Guzzle client reused across batches so connections can be pooled.
     */
    protected Client $client;

    /**
     * The WordPress fingerprint detector.
     */
    protected WordPressDetector $detector;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        ini_set('memory_limit', '2G');

        // Ensure proper casting of options to their respective types
        $this->request_timeout = (int) ($this->option('request_timeout') ?? $this->request_timeout);
        $this->connect_timeout = (int) ($this->option('connect_timeout') ?? $this->connect_timeout);
        $this->domains_per_batch = (int) ($this->option('domains_per_batch') ?? $this->domains_per_batch);
        $this->concurrent_requests = (int) ($this->option('concurrent_requests') ?? $this->concurrent_requests);
        $this->show_temp_results_every = (int) ($this->option('show_temp_results_every') ?? $this->show_temp_results_every);
        $this->domain_offset = (int) ($this->option('domain_offset') ?? $this->domain_offset);

        $this->appDebug = env('APP_DEBUG', false);
        $this->client = new Client;
        $this->detector = new WordPressDetector;

        $batch = $this->getBatch();
        if (! $batch) {
            $this->info('No batch found to process.');

            return;
        }

        $this->info('Processing batch ID: '.$batch->id);
        if ($this->domain_offset > 0) {
            $this->info("Skipping the first {$this->domain_offset} domains in the batch.");
        }
        $this->start_id = $this->resolveStartId($batch->id);
        DB::table('batches')->where('id', $batch->id)->update(['started' => true]);

        $this->start_time = Carbon::now();

        while (true) {
            $domains = $this->getDomains($batch->id);
            if ($domains->isEmpty()) {
                $this->info('No more domains to process in this batch.');
                break;
            }

            $responses = $this->makeConcurrentRequests($domains, 'https://');

            // Retry hosts that failed over HTTPS using plain HTTP, since a share
            // of them are only reachable there.
            $failed = $domains->filter(fn ($domain) => $responses[$domain->id] === null)->values();
            if ($failed->isNotEmpty()) {
                foreach ($this->makeConcurrentRequests($failed, 'http://') as $domainId => $response) {
                    if ($response !== null) {
                        $responses[$domainId] = $response;
                    }
                }
            }

            $this->processResponses($responses);
        }

        $timeElapsed = $this->start_time->diffForHumans(null, true);
        $this->info("Batch processing completed. Time elapsed: $timeElapsed.");
    }

    /**
     * Get the batch to process.
     *
     * @return object|null
     */
    protected function getBatch()
    {
        if ($this->option('resume')) {
            return DB::table('batches')->where('started', true)->where('completed', false)->orderBy('id')->first();
        }

        return DB::table('batches')->where('started', false)->orderBy('id')->first();
    }

    /**
     * Resolve the first domain id to process from the configured offset. This
     * runs once so subsequent fetches never pay the cost of a large OFFSET.
     */
    protected function resolveStartId(int $batchId): int
    {
        if ($this->domain_offset <= 0) {
            return 0;
        }

        return (int) (DB::table('domains')
            ->where('batch_id', $batchId)
            ->orderBy('id')
            ->offset($this->domain_offset)
            ->limit(1)
            ->value('id') ?? PHP_INT_MAX);
    }

    /**
     * Get domains for the batch.
     *
     * Keyset pagination: processed rows leave the 'untested' set, so we only
     * need a lower bound on the id instead of a growing OFFSET.
     *
     *
     * @return Collection
     */
    protected function getDomains(int $batchId)
    {
        return DB::table('domains')
            ->where('batch_id', $batchId)
            ->where('id', '>=', $this->start_id)
            ->where('is_wordpress', 'untested')
            ->orderBy('id')
            ->limit($this->domains_per_batch)
            ->get();
    }

    /**
     * Make concurrent requests for a set of domains using the given scheme.
     *
     * @param  Collection  $domains
     */
    protected function makeConcurrentRequests($domains, string $scheme): array
    {
        $list = array_values($domains->all());
        $responses = [];

        $requests = function () use ($list, $scheme) {
            foreach ($list as $domain) {
                yield function () use ($domain, $scheme) {
                    return $this->client->getAsync(
                        $scheme.$domain->domain,
                        [
                            'headers' => [
                                'User-Agent' => $this->userAgent,
                                'Range' => 'bytes=0-'.($this->max_body_bytes - 1),
                            ],
                            'allow_redirects' => true,
                            'timeout' => $this->request_timeout,
                            'connect_timeout' => $this->connect_timeout,
                        ]
                    );
                };
            }
        };

        Pool::batch(
            $this->client,
            $requests(),
            [
                'concurrency' => $this->concurrent_requests,
                'fulfilled' => function ($response, $index) use ($list, &$responses) {
                    $responses[$list[$index]->id] = $response;
                },
                'rejected' => function ($reason, $index) use ($list, &$responses) {
                    $responses[$list[$index]->id] = null;
                },
            ]
        );

        return $responses;
    }

    /**
     * Show temporary results.
     */
    protected function showTempResults(int $tested, int $wordpress, int $notWordPress, int $noReply, Carbon $startTime): void
    {
        if ($this->appDebug && $tested % $this->show_temp_results_every == 0) {
            $classified = $wordpress + $notWordPress;
            $percentage = $classified > 0 ? round(($wordpress / $classified) * 100, 2) : 0;
            $secondsElapsed = Carbon::now()->diffInSeconds($startTime);
            $secondsPerRequest = round(abs($secondsElapsed) / $tested, 3);
            $this->info(
                "----------------------------------------------------------------\n".
                number_format($tested).' websites tested so far: '.
                number_format($wordpress)." are using WordPress ($percentage%), ".
                number_format($notWordPress).' are not using WordPress, '.
                number_format($noReply)." did not reply.\n".
                "Started {$startTime->diffForHumans()}: $secondsPerRequest s per request."
            );
        }
    }

    /**
     * Process responses, writing all results for the batch in a single
     * transaction grouped by status to minimise SQLite write overhead.
     */
    protected function processResponses(array $responses)
    {
        $now = Carbon::now();
        $grouped = ['yes' => [], 'no' => [], 'no_http_reply' => []];

        foreach ($responses as $domainId => $response) {
            $status = $this->classify($response);
            $grouped[$status][] = $domainId;

            if ($status === 'yes') {
                $this->wordpressCount++;
            } elseif ($status === 'no') {
                $this->notWordPressCount++;
            } else {
                $this->noReplyCount++;
            }
        }

        DB::transaction(function () use ($grouped, $now) {
            foreach ($grouped as $status => $ids) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    DB::table('domains')->whereIn('id', $chunk)->update([
                        'is_wordpress' => $status,
                        'updated_at' => $now,
                    ]);
                }
            }
        });

        $this->domainsProcessed += count($responses);
        $this->showTempResults($this->domainsProcessed, $this->wordpressCount, $this->notWordPressCount, $this->noReplyCount, $this->start_time);
    }

    /**
     * Classify a single response into a status string.
     *
     * @param  Response|null  $response
     */
    protected function classify($response): string
    {
        if ($response === null) {
            return 'no_http_reply';
        }

        try {
            $stream = $response->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $body = Utils::copyToString($stream, $this->max_body_bytes);

            return $this->detector->detect($body, $response->getHeaders()) ? 'yes' : 'no';
        } catch (\Throwable $e) {
            return 'no_http_reply';
        }
    }
}
