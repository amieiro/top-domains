<?php

namespace App\Console\Commands;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
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

	protected $URL = 'https://downloads.majestic.com/majestic_million.csv';
	protected $CSV_file = 'majestic_million.csv';
	protected $number_of_websites_tested = 0;
	protected $number_of_websites_using_wordpress = 0;
	protected $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Safari/537.36';

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

		$this->info( 'Scraping Majestic top 1 million websites...' );
		if ( ( getenv( 'APP_DEBUG' ) == 'false' ) || ( ! Storage::disk( 'local' )->exists( $this->CSV_file ) ) ) {
			Storage::disk( 'local' )->put( $this->CSV_file, file_get_contents( $this->URL ) );
		}
		$path   = Storage::path( $this->CSV_file );
		$handle = fopen( $path, "r" );
		if ( $handle ) {
			while ( ( $line = fgetcsv( $handle ) ) !== false ) {
				try {
					if ( ! is_numeric( $line[0] ) ) {  // Skip the first line: header
						continue;
					}
					if ( $line[0] < 110000 ) {  // Skip the first 110k websites
						continue;
					}
					$response = Http::withUserAgent( $this->userAgent )->timeout( 7 )->get( $line[6] );
					if ( $this->is_WordPress( $line[6], $response ) ) {
						$this->number_of_websites_using_wordpress ++;
						$this->info( '----> ' . $line[0] . ': ' . $line[6] . ' is using WordPress!' );
					} else {
						$this->info( $line[0] . ': ' . $line[6] . ' is not using WordPress!' );
					}
				} catch ( \Exception $e ) {
					$this->info( $line[0] . ': ' . $line[6] . ': Error getting this site' );
					continue;
				}
				$this->number_of_websites_tested ++;
				$this->show_temp_results();
			}
		}
		fclose( $handle );

		$this->info( 'Scraping finished!' );
	}

	private function is_WordPress( string $domain, PromiseInterface|\Illuminate\Http\Client\Response $response ): bool {
		if ( str_contains( $response->header( 'link' ), 'rel="https://api.w.org/"' ) ) {
			return true;
		}
		if ( str_contains( $response->body(), '<meta name="generator" content="WordPress' ) ) {
			return true;
		}
		if ( str_contains( $response->body(), $domain . '/wp-content/' ) ) {
			return true;
		}
		if ( str_contains( $response->body(), $domain . '/wp-includes/' ) ) {
			return true;
		}
		if ( str_contains( $response->body(), $domain . '/wp-admin/' ) ) {
			return true;
		}

		return false;
	}

	private function show_temp_results() {
		if ( $this->number_of_websites_tested % 50 == 0 ) {
			$percentage = round( ( $this->number_of_websites_using_wordpress / $this->number_of_websites_tested ) * 100, 2 );
			$this->info( $this->number_of_websites_tested . ' websites tested so far, ' . $this->number_of_websites_using_wordpress . ' are using WordPress: ' . $percentage . '%' );
		}
	}
}
