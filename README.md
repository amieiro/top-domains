# WordPress Detection and Analysis Tool

This project analyzes the top 1 million websites to identify those using WordPress. It provides detailed insights into WordPress adoption across various domains.

## List Sources

This project uses five different sources to gather lists of top domains. The sources and their download links are:

- [Cisco Umbrella](https://s3-us-west-1.amazonaws.com/umbrella-static/index.html): [Download link](https://s3-us-west-1.amazonaws.com/umbrella-static/top-1m.csv.zip) (1 million rows).
- [Majestic](https://majestic.com/reports/majestic-million): [Download link](https://downloads.majestic.com/majestic_million.csv) (1 million rows).
- [BuiltWith](https://builtwith.com/top-1m): [Download link](https://builtwith.com/dl/builtwith-top1m.zip) (1 million rows).
- [DomCop](https://www.domcop.com/top-10-million-websites): [Download link](https://www.domcop.com/files/top/top10milliondomains.csv.zip) (10 million rows).
- [Tranco](https://tranco-list.eu/): [Download link](https://tranco-list.eu/top-1m.csv.zip) (1 million rows).

## Requirements

This project is built using Laravel 12 and requires [PHP 8.2](https://laravel.com/docs/12.x/deployment#server-requirements) or higher. It has been tested on PHP 8.4.

## Installation

To set up the project, ensure you have [Composer](https://getcomposer.org/) and [Git](https://git-scm.com/) installed on your machine. Follow these steps:

1. Clone the repository:
   ```bash
   git clone git@github.com:amieiro/top-domains.git
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Create an SQLite database:
   ```bash
   touch database/database.sqlite
   ```

4. Set up the environment file:
   ```bash
   cp .env.example .env
   ```

5. Generate the application key:
   ```bash
   php artisan key:generate
   ```

## Execution

To execute the project, follow these steps:

1. **Clean the database** (if needed):
   ```bash
   php artisan migrate:fresh
   ```

2. **Download domain lists**:
   - Download all files:
     ```bash
     php artisan top-domains:download all
     ```
   - Download a specific file:
     ```bash
     php artisan top-domains:download provider
     ```
     Replace `provider` with one of the following:
     - umbrella
     - majestic
     - builtwith
     - domcop
     - tranco

3. **Import domains into the database**:
   ```bash
   php artisan top-domains:import provider
   ```
   Replace `provider` with one of the providers listed above.

4. **Analyze WordPress usage**:
   ```bash
   php artisan top-domains:check-wp
   ```

### Command Parameters

The `top-domains:check-wp` command supports the following parameters:

- `resume`: Resume the last incomplete batch instead of starting a new one.
- `request_timeout`: Timeout in seconds for each HTTP request (default: 10).
- `domains_per_batch`: Number of domains to process per batch (default: 200).
- `concurrent_requests`: Number of concurrent HTTP requests (default: 200).
- `show_temp_results_every`: Show temporary results every X websites tested (default: 200).
- `domain_offset`: Number of domains to skip from the top of the list (default: 600,000).

Example:
```bash
php artisan top-domains:check-wp --resume --request_timeout=20 --domains_per_batch=500 --concurrent_requests=250 --show_temp_results_every=1000 --domain_offset=500000
```

### Memory Limit

If you encounter memory limit issues, increase the limit by running:
```bash
php -d memory_limit=1024M artisan top-domains:check-wp
```

## License

This project is licensed under the [GNU GPL v2](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html) license or higher.
