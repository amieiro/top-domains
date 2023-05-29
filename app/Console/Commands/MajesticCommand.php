<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MajesticCommand extends Command {
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'scrap-majestic';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Scrap the Majestic top 1 million websites and shows the number of websites using WordPress.';

	/**
	 * The URL of the CSV file containing the top 1 million websites.
	 *
	 * @var string
	 */
	protected string $URL = 'https://downloads.majestic.com/majestic_million.csv';

	/**
	 * The name of the CSV file containing the top 1 million websites.
	 *
	 * @var string
	 */
	protected string $CSV_file = 'majestic_million.csv';

	/**
	 * The number of websites tested so far.
	 *
	 * @var int
	 */
	protected int $number_of_websites_tested = 0;

	/**
	 * The number of websites using WordPress.
	 *
	 * @var int
	 */
	protected int $number_of_websites_using_wordpress = 0;

	/**
	 * The user agent to use for the requests.
	 *
	 * @var string
	 */
	protected string $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Safari/537.36';

	/**
	 * The number of URLs to send in each batch.
	 *
	 * @var int
	 */
	protected int $number_of_url_per_chunk = 200;

	/**
	 * The number of concurrent requests to send.
	 *
	 * @var int
	 */
	protected int $number_of_concurrent_requests = 200;

	/**
	 * Show temporary results every X websites tested.
	 *
	 * @var int
	 */
	protected int $show_temp_results_every = 200;

	/**
	 * The timeout for each request.
	 *
	 * @var int
	 */
	protected int $request_timeout = 10;

	/**
	 * The start time of the script.
	 *
	 * @var Carbon
	 */
	protected Carbon $start_time;

	/**
	 * The number of domains to skip from the top of the list.
	 *
	 * @var int
	 */
	protected int $domain_offset = 100000;

	/**
	 * Create a new command instance.
	 */
	public function __construct() {
		parent::__construct();
	}

	/**
	 * Execute the console command.
	 */
	public function handle() {
		$this->start_time = Carbon::now();
		$this->info( 'Scraping Majestic top 1 million websites...' );
		if ( ( getenv( 'APP_DEBUG' ) == 'false' ) || ( ! Storage::disk( 'local' )->exists( $this->CSV_file ) ) ) {
			Storage::disk( 'local' )->put( $this->CSV_file, file_get_contents( $this->URL ) );
		}
		$path    = Storage::path( $this->CSV_file );
		$handle  = fopen( $path, "r" );
		$URIs    = [];
		$counter = 0;
		if ( $handle ) {
			while ( ( $line = fgetcsv( $handle ) ) !== false ) {
				try {
					if ( $counter < $this->number_of_url_per_chunk ) {
						if ( ! is_numeric( $line[0] ) ) {  // Skip the first line: header
							continue;
						}
						if ( $line[0] < $this->domain_offset ) {  // Skip the first websites from the top of the list.
							continue;
						}
						$URIs[] = $line[6];
						$counter ++;
					} else {
						$responses = $this->makeConcurrentRequests( $URIs );
						$this->increase_counters( $responses );
						$URIs    = [];
						$counter = 0;
					}

				} catch ( \Exception $e ) {
					continue;
				}
			}
		}
		fclose( $handle );

		$this->info( 'Scraping finished!' );
	}

	/**
	 * Check if the domain is using WordPress.
	 *
	 * @param string $domain
	 * @param Response $response
	 *
	 * @return bool
	 */
	protected function is_WordPress( string $domain, Response $response ): bool {
		try {
			if ( str_contains( $response->getHeaderLine( 'link' ), 'rel="https://api.w.org/"' ) ) {
				return true;
			}
			if ( str_contains( $response->getBody(), '<meta name="generator" content="WordPress' ) ) {
				return true;
			}
			if ( str_contains( $response->getBody(), $domain . '/wp-content/' ) ) {
				return true;
			}
			if ( str_contains( $response->getBody(), $domain . '/wp-includes/' ) ) {
				return true;
			}
			if ( str_contains( $response->getBody(), $domain . '/wp-admin/' ) ) {
				return true;
			}
		} catch ( \Exception $e ) {
			return false;
		}

		return false;
	}

	/**
	 * Increase the internal counters.
	 *
	 * @param array $responses
	 *
	 * @return void
	 */
	protected function increase_counters( array $responses ): void {
		foreach ( $responses as $domain => $response ) {
			if ( $this->is_WordPress( $domain, $response ) ) {
				$this->number_of_websites_using_wordpress ++;
			}
			$this->number_of_websites_tested ++;
			$this->show_temp_results();
		}
	}

	/**
	 * Show temporary results every X websites tested.
	 *
	 * @return void
	 */
	protected function show_temp_results(): void {
		if ( $this->number_of_websites_tested % $this->show_temp_results_every == 0 ) {
			$percentage          = round( ( $this->number_of_websites_using_wordpress / $this->number_of_websites_tested ) * 100, 2 );
			$seconds_elapsed     = Carbon::now()->diffInSeconds( $this->start_time );
			$seconds_per_request = round( $seconds_elapsed / $this->number_of_websites_tested, 3 );
			$string_to_show      = $this->number_of_websites_tested . ' websites tested so far, ';
			$string_to_show      .= $this->number_of_websites_using_wordpress . ' are using WordPress: ' . $percentage . '%. ';
			$string_to_show      .= 'Started ' . $this->start_time->diffForHumans() . ': ' . $seconds_per_request . ' s. per request.';
			$this->info( $string_to_show );
		}
	}

	/**
	 * Make concurrent requests.
	 *
	 * @param array $URIs
	 *
	 * @return array
	 */
	protected function makeConcurrentRequests( array $URIs ): array {
		$client            = new Client();
		$correct_responses = [];
		try {
			$requests = function () use ( $URIs, $client ) {
				foreach ( $URIs as $uri ) {
					yield function () use ( $client, $uri ) {
						return $client->getAsync(
							'https://' . $uri,
							[
								'headers'         => [
									'User-Agent' => $this->userAgent,
								],
								'allow_redirects' => true,
								'timeout'         => $this->request_timeout,
							] );
					};
				}
			};

			Pool::batch(
				$client,
				$requests(),
				[
					'concurrency' => $this->number_of_concurrent_requests,
					'options'     => [
						'timeout'         => $this->request_timeout,
						'allow_redirects' => true,
					],
					'fulfilled'   => function ( $response, $index ) use ( $URIs, &$correct_responses ) {
						$correct_responses[ $URIs[ $index ] ] = $response;
					},
					'rejected'    => function ( $reason, $index ) use ( $URIs ) {
						// Don't do anything with failed requests.
					},
				]
			);


		} catch ( \Exception $e ) {
			$this->error( 'Error: ' . $e->getMessage() );
		} finally {
			return $correct_responses;
		}
	}
}
