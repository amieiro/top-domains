<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckWordPressCommand extends Command {
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'top-domains:check-wp {--resume} {request_timeout?} {domains_per_batch?} {concurrent_requests?} {show_temp_results_every?} {domain_offset?}';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Check if websites in the domains table are using WordPress.';

	/**
	 * The user agent to use for the requests.
	 *
	 * @var string
	 */
    protected string $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36';

	/**
	 * The timeout for each request.
	 *
	 * @var int
	 */
	protected int $request_timeout = 10;

	/**
	 * The number of domains to process in each batch.
	 *
	 * @var int
	 */
	protected int $domains_per_batch = 200;

	/**
	 * The number of concurrent requests to send.
	 *
	 * @var int
	 */
	protected int $concurrent_requests = 200;

	/**
	 * Show temporary results every X websites tested.
	 *
	 * @var int
	 */
	protected int $show_temp_results_every = 200;

	/**
	 * The start time of the batch processing.
	 *
	 * @var \Carbon\Carbon
	 */
	protected Carbon $start_time;

	/**
	 * Count of domains, WordPress, not WordPress, and no reply domains in the current batch.
	 *
	 * @var int
	 */
    protected int $domainsProcessed = 0;
	protected int $wordpressCount = 0;
	protected int $notWordPressCount = 0;
	protected int $noReplyCount = 0;

	/**
	 * The number of domains to skip from the top of the list.
	 *
	 * @var int
	 */
	protected int $domain_offset = 600000;

	/** 
	 * Whether the application is in debug mode.
	 * 
	 * @var bool
	 */
	protected bool $appDebug;

	/**
	 * Execute the console command.
	 */
	public function handle() {
		ini_set('memory_limit', '512M');

		$this->request_timeout = $this->argument('request_timeout') ?? $this->request_timeout;
		$this->domains_per_batch = $this->argument('domains_per_batch') ?? $this->domains_per_batch;
		$this->concurrent_requests = $this->argument('concurrent_requests') ?? $this->concurrent_requests;
		$this->show_temp_results_every = $this->argument('show_temp_results_every') ?? $this->show_temp_results_every;
		$this->domain_offset = $this->argument('domain_offset') ?? $this->domain_offset;

		$this->appDebug = env('APP_DEBUG', false);

		$batch = $this->getBatch();
		if (!$batch) {
			$this->info('No batch found to process.');
			return;
		}

		$this->info('Processing batch ID: ' . $batch->id);
        if ($this->domain_offset > 0) {
            $this->info("Skipping the first {$this->domain_offset} domains in the batch.");
        }
		DB::table('batches')->where('id', $batch->id)->update(['started' => true]);

		$this->start_time = Carbon::now();

		while (true) {
			$domains = $this->getDomains($batch->id);
			if ($domains->isEmpty()) {
				$this->info('No more domains to process in this batch.');
				break;
			}

			$responses = $this->makeConcurrentRequests($domains);
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
	protected function getBatch() {
		if ($this->option('resume')) {
			return DB::table('batches')->where('started', true)->where('completed', false)->orderBy('id')->first();
		}
		return DB::table('batches')->where('started', false)->orderBy('id')->first();
	}

	/**
	 * Get domains for the batch.
	 *
	 * @param int $batchId
	 *
	 * @return \Illuminate\Support\Collection
	 */
	protected function getDomains(int $batchId) {
		return DB::table('domains')
			->where('batch_id', $batchId)
			->where('is_wordpress', 'untested')
			->orderBy('id')
			->offset($this->domain_offset)
			->limit($this->domains_per_batch)
			->get();
	}

	/**
	 * Make concurrent requests.
	 *
	 * @param \Illuminate\Support\Collection $domains
	 *
	 * @return array
	 */
	protected function makeConcurrentRequests($domains): array {
		$client = new Client();
		$responses = [];

		$requests = function () use ($domains, $client) {
			foreach ($domains as $domain) {
				yield function () use ($client, $domain) {
					return $client->getAsync(
						'https://' . $domain->domain,
						[
							'headers' => ['User-Agent' => $this->userAgent],
							'allow_redirects' => true,
							'timeout' => $this->request_timeout,
						]
					);
				};
			}
		};

		Pool::batch(
			$client,
			$requests(),
			[
				'concurrency' => $this->concurrent_requests,
				'fulfilled' => function ($response, $index) use ($domains, &$responses) {
					$responses[$domains[$index]->id] = $response;
				},
				'rejected' => function ($reason, $index) use ($domains, &$responses) {
					$responses[$domains[$index]->id] = null;
				},
			]
		);

		return $responses;
	}

	/**
	 * Show temporary results.
	 *
	 * @param int $tested
	 * @param int $wordpress
	 * @param int $notWordPress
	 * @param int $noReply
	 * @param Carbon $startTime
	 */
	protected function showTempResults(int $tested, int $wordpress, int $notWordPress, int $noReply, Carbon $startTime): void {
		if ($this->appDebug && $tested % $this->show_temp_results_every == 0) {
			$percentage = round(($wordpress / ($wordpress + $notWordPress)) * 100, 2);
			$secondsElapsed = Carbon::now()->diffInSeconds($startTime);
			$secondsPerRequest = round(abs($secondsElapsed) / $tested, 3);
			$this->info(
                "----------------------------------------------------------------\n" .
				"$tested websites tested so far: " .
				"$wordpress are using WordPress ($percentage%), " .
				"$notWordPress are not using WordPress, " .
				"$noReply did not reply.\n" .
				"Started {$startTime->diffForHumans()}: $secondsPerRequest s per request."
			);
		}
	}

	/**
	 * Process responses.
	 *
	 * @param array $responses
	 */
	protected function processResponses(array $responses) {
		$now = Carbon::now();

		foreach ($responses as $domainId => $response) {
			if ($response === null) {
				DB::table('domains')->where('id', $domainId)->update([
					'is_wordpress' => 'no_http_reply',
					'updated_at' => $now,
				]);
				$this->noReplyCount++;
				continue;
			}

			$status = $this->isWordPress($response);
			DB::table('domains')->where('id', $domainId)->update([
				'is_wordpress' => $status,
				'updated_at' => $now,
			]);

			if ($status === 'yes') {
				$this->wordpressCount++;
			} elseif ($status === 'no') {
				$this->notWordPressCount++;
			} else {
				$this->noReplyCount++;
			}
		}

		$this->domainsProcessed += count($responses);
		$this->showTempResults($this->domainsProcessed, $this->wordpressCount, $this->notWordPressCount, $this->noReplyCount, $this->start_time);
	}

	/**
	 * Check if the response indicates WordPress.
	 *
	 * @param \GuzzleHttp\Psr7\Response $response
	 *
	 * @return string
	 */
    protected function isWordPress($response): string {
        try {
            $body = $response->getBody();
            if (empty($body)) {
                return 'no_http_reply';
            }

            // Check HTTP headers for WordPress-specific indicators
            $headers = $response->getHeaders();
			if (isset($headers['X-Powered-By']) && str_contains($headers['X-Powered-By'][0], 'WordPress')) {
				return 'yes';
			}
            
			if (isset($headers['X-Pingback']) && str_contains($headers['X-Pingback'][0], 'xmlrpc.php')) {
				return 'yes';
			}
            
			if (isset($headers['Link'])) {
				foreach ($headers['Link'] as $linkHeader) {
					if (str_contains($linkHeader, 'wp-json')) {
						return 'yes';
					}
				}
			}

            // WordPress generator meta tag (most reliable)
            if (str_contains($body, '<meta name="generator" content="WordPress')) {
                return 'yes';
            }

            // WordPress core directory paths
            if (str_contains($body, '/wp-content/') ||
                str_contains($body, '/wp-includes/') ||
                str_contains($body, '/wp-admin/')) {
                return 'yes';
            }

            // WordPress REST API endpoints
            if (str_contains($body, 'wp-json') ||
                str_contains($body, '/wp-json/wp/v2/') ||
                str_contains($body, 'wp-json/wp/v2/posts') ||
                str_contains($body, 'wp-json/wp/v2/pages') ||
                str_contains($body, 'wp-json/wp/v2/users')) {
                return 'yes';
            }

            // WordPress admin and login pages
            if (str_contains($body, 'wp-login.php') ||
                str_contains($body, '/wp-admin/') ||
                str_contains($body, 'wp-admin/css/login') ||
                str_contains($body, 'wp-admin/css/forms')) {
                return 'yes';
            }

            // WordPress XML-RPC and RSD (Really Simple Discovery)
            if (str_contains($body, 'xmlrpc.php') ||
                str_contains($body, 'EditURI') && str_contains($body, 'xmlrpc.php?rsd') ||
                str_contains($body, 'Really Simple Discovery') ||
                str_contains($body, 'rsd+xml')) {
                return 'yes';
            }

            // WordPress-specific JavaScript and CSS files
            if (str_contains($body, 'wp-emoji-release.min.js') ||
                str_contains($body, 'wp-block-library') ||
                str_contains($body, 'dashicons.min.css') ||
                str_contains($body, '/wp-includes/js/') ||
                str_contains($body, '/wp-includes/css/') ||
                str_contains($body, 'wp-emoji') ||
                str_contains($body, 'emoji-release')) {
                return 'yes';
            }

            // WordPress theme and plugin paths
            if (str_contains($body, '/wp-content/themes/') ||
                str_contains($body, '/wp-content/plugins/') ||
                str_contains($body, '/wp-content/uploads/') ||
                str_contains($body, '/themes/twentytwentyfour/') ||
                str_contains($body, '/themes/twentytwentythree/') ||
                str_contains($body, '/themes/twentytwentytwo/')) {
                return 'yes';
            }

            // WordPress-specific CSS classes and IDs
            if (str_contains($body, 'class="wp-') ||
                str_contains($body, 'id="wp-') ||
                str_contains($body, 'wp-block-') ||
                str_contains($body, 'wp-site-blocks') ||
                str_contains($body, 'wp-container-') ||
                str_contains($body, 'wp-image-') ||
                str_contains($body, 'wp-caption') ||
                str_contains($body, 'wp-attachment-')) {
                return 'yes';
            }

            // WordPress comment system
            if (str_contains($body, 'comment-form') ||
                str_contains($body, 'comment-list') ||
                str_contains($body, 'comment-body') ||
                str_contains($body, 'comment-meta') ||
                str_contains($body, 'wp-comment-') ||
                str_contains($body, 'commentform')) {
                return 'yes';
            }

            // WordPress version query strings (highly specific)
            if (preg_match('/\.js\?ver=[\d.]+/', $body) ||
                preg_match('/\.css\?ver=[\d.]+/', $body) ||
                preg_match('/wp-[\w-]+\.min\.js\?ver=([\d.]+)/', $body) ||
                preg_match('/wp-[\w-]+\.css\?ver=([\d.]+)/', $body)) {
                return 'yes';
            }

            // WordPress unique functions and features
            if (str_contains($body, 'wp_unique_id') ||
                str_contains($body, 'wp_enqueue_script') ||
                str_contains($body, 'wp_enqueue_style') ||
                str_contains($body, 'wp_head') ||
                str_contains($body, 'wp_footer')) {
                return 'yes';
            }

            // WordPress pingback
            if (str_contains($body, 'rel="pingback"')) {
                return 'yes';
            }

            // WordPress media and gallery patterns
            if (str_contains($body, 'wp-block-image') ||
                str_contains($body, 'wp-block-gallery') ||
                str_contains($body, 'gallery-') && str_contains($body, 'wp-')) {
                return 'yes';
            }

            // WordPress shortlinks
            if (str_contains($body, 'rel="shortlink"') && str_contains($body, '?p=')) {
                return 'yes';
            }

            return 'no';
        } catch (\Exception $e) {
            return 'no_http_reply';
        }
    }
}
