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
	protected $signature = 'top-domains:check-wp {--resume}';

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
	protected int $domain_offset = 500000;

	/**
	 * Execute the console command.
	 */
	public function handle() {
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

		$this->info('Batch processing completed.');
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
		if ($tested % $this->show_temp_results_every == 0) {
			$percentage = round(($wordpress / ($wordpress + $notWordPress)) * 100, 2);
			$secondsElapsed = Carbon::now()->diffInSeconds($startTime);
			$secondsPerRequest = round($secondsElapsed / $tested, 3);
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

			$isWordPress = $this->isWordPress($response);
			DB::table('domains')->where('id', $domainId)->update([
				'is_wordpress' => $isWordPress ? 'yes' : 'no',
				'updated_at' => $now,
			]);

			if ($isWordPress) {
				$this->wordpressCount++;
			} else {
				$this->notWordPressCount++;
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
	 * @return bool
	 */
	protected function isWordPress($response): bool {
		try {
			$body = $response->getBody();
			return str_contains($body, '<meta name="generator" content="WordPress') ||
				str_contains($body, '/wp-content/') ||
				str_contains($body, '/wp-includes/') ||
				str_contains($body, '/wp-admin/') ||
				str_contains($body, 'wp-json') ||
				str_contains($body, 'wp-login.php') ||
				str_contains($body, 'xmlrpc.php');
		} catch (\Exception $e) {
			return false;
		}
	}
}
