<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use ZipArchive;

class DownloadTopDomains extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'top-domains:download {source}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download top domains lists from various sources';

    /**
     * List of sources and their URLs.
     */
    private $sources = [
        'umbrella' => 'https://s3-us-west-1.amazonaws.com/umbrella-static/top-1m.csv.zip',
        'majestic' => 'https://downloads.majestic.com/majestic_million.csv',
        'builtwith' => 'https://builtwith.com/dl/builtwith-top1m.zip',
        'domcop' => 'https://www.domcop.com/files/top/top10milliondomains.csv.zip',
        'tranco' => 'https://tranco-list.eu/top-1m.csv.zip',
    ];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        ini_set('memory_limit', '512M');

        $source = $this->argument('source');

        if ($source === 'all') {
            foreach ($this->sources as $key => $url) {
                $this->downloadAndProcess($key, $url);
            }
        } elseif (array_key_exists($source, $this->sources)) {
            $this->downloadAndProcess($source, $this->sources[$source]);
        } else {
            $this->error('Invalid source provided.');
            return 1;
        }

        $this->info('Download and processing completed.');
        return 0;
    }

    /**
     * Download and process the file.
     *
     * @param string $source
     * @param string $url
     */
    private function downloadAndProcess(string $source, string $url)
    {
        $this->info("Downloading file for source: $source");

        try {
            $response = Http::timeout(90)->get($url);
        } catch (\Exception $e) {
            $this->error("Exception while downloading file for source: $source. " . $e->getMessage());
            return;
        }
        if ($response->failed()) {
            $this->error("Failed to download file for source: $source");
            return;
        }

        if (str_ends_with($url, '.zip')) {
            $compressedFilePath = "$source.zip";
            Storage::put($compressedFilePath, $response->body());
            $this->info("Compressed file saved to storage: $compressedFilePath");

            $this->info("Unzipping file: $compressedFilePath");
            $this->unzipFile($compressedFilePath, $source);
            Storage::delete($compressedFilePath);
            $this->info("Zip file removed: $compressedFilePath");
        } else {
            $filePath = "$source.csv";
            Storage::put($filePath, $response->body());
            $this->info("File saved to storage: $filePath");
        }
    }

    /**
     * Unzip the file.
     *
     * @param string $filePath
     */
    private function unzipFile(string $filePath, string $source)
    {
        $zip = new ZipArchive();
        $fullPath = storage_path("app/private/$filePath");

        if ($zip->open($fullPath) === true) {
            $filename = $zip->getNameIndex(0);
            $zip->extractTo(storage_path('app/private'), $filename);
            $zip->close();
            $this->info("File unzipped successfully.");
            $this->info("Extracted file path: " . storage_path("app/private/$filename"));
            // Rename the extracted file to a consistent name
            $extractedFilePath = storage_path("app/private/$filename");
            $finalFilePath = storage_path("app/private/$source.csv");
            if (file_exists($extractedFilePath)) {
                rename($extractedFilePath, $finalFilePath);
                $this->info("Extracted file renamed to: $finalFilePath");
            }
        } else {
            $this->error("Failed to unzip file: $filePath");
        }
    }
}
