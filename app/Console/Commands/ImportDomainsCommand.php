<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportDomainsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'top-domains:import {file} {--max-rows=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import domains from a CSV file into the domains table';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $fileName = $this->argument('file');
        $filePath = storage_path("app/{$fileName}.csv");

        if (!file_exists($filePath)) {
            $this->error("File {$fileName}.csv does not exist.");
            return 1;
        }

        $maxDomains = $this->option('max-rows') ?? (env('APP_DEBUG') ? 100 : null);

        $fileHandle = fopen($filePath, 'r');
        if (!$fileHandle) {
            $this->error("Unable to open file {$fileName}.csv.");
            return 1;
        }

        $domains = [];
        $rowCount = 0;

        while (($row = fgetcsv($fileHandle)) !== false) {
            $domain = $this->extractDomain($fileName, $row);
            if ($domain) {
                $domains[] = ['domain' => $domain];
                $rowCount++;
            }

            if ($maxDomains && $rowCount >= $maxDomains) {
                break;
            }
        }

        fclose($fileHandle);

        $batchId = DB::table('batches')->insertGetId([
            'provider' => $fileName,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $now = now();
        foreach (array_chunk($domains, 100) as $chunk) {
            foreach ($chunk as &$domain) {
                $domain['batch_id'] = $batchId;
                $domain['created_at'] = $now;
                $domain['updated_at'] = $now;
            }
            DB::table('domains')->insert($chunk);
        }

        $this->info("Imported {$rowCount} domains from {$fileName}.csv.");

        return 0;
    }

    /**
     * Extract the domain based on the file format.
     *
     * @param string $fileName
     * @param array $row
     * @return string|null
     */
    private function extractDomain(string $fileName, array $row): ?string
    {
        switch ($fileName) {
            case 'umbrella':
            case 'builtwith':
            case 'tranco':
                return $row[1] ?? null;

            case 'majestic':
                return (isset($row[2]) && $row[2] !== 'Domain') ? $row[2] : null;

            case 'domcop':
                return isset($row[1]) && $row[1] !== 'Domain' ? $row[1] : null;

            default:
                Log::warning("Unknown file format: {$fileName}");
                return null;
        }
    }
}
