# WordPress scraper

This project scraps the top 1 million websites and shows the number of websites using WordPress.

# List sources

This project uses 5 different sources to get the list with the top domains. I get this list [here](https://github.com/PeterDaveHello/top-1m-domains).

- [Cisco Umbrella](https://s3-us-west-1.amazonaws.com/umbrella-static/index.html). [Download link](https://s3-us-west-1.amazonaws.com/umbrella-static/top-1m.csv.zip). 1 M rows.
- [Majestic](https://majestic.com/reports/majestic-million). [Download link](https://downloads.majestic.com/majestic_million.csv). 1 M rows.
- [Build With](https://builtwith.com/top-1m). [Download link](https://builtwith.com/dl/builtwith-top1m.zip). 1 M rows.
- [DomCop](https://www.domcop.com/top-10-million-websites). [Download link](https://www.domcop.com/files/top/top10milliondomains.csv.zip). 10 M rows.
- [Tranco](https://tranco-list.eu/). [Download link](https://tranco-list.eu/top-1m.csv.zip). 1 M rows.

## Requirements

This project uses Laravel 12, so you need to have [PHP 8.2](https://laravel.com/docs/12.x/deployment#server-requirements) 
or higher installed on your machine. I have tested this project on PHP 8.4.

## Installation

You need to have [Composer](https://getcomposer.org/) installed on your machine.
You can install it by following the instructions on the [Composer website](https://getcomposer.org/download/).
You also need to have [Git](https://git-scm.com/) installed on your machine.
Once you have installed Composer and Git, you can clone this repository by running the following command:

```bash
git clone git@github.com:amieiro/top-domains.git
```

After cloning the repository, you need to install the dependencies by running the following command:

```bash
composer install
```

Then you need to create a [SQLite](https://www.sqlite.org/) database. 

```bash
touch database\database.sqlite
```

And to create a .env file.

```bash
cp .env.example .env
```

Finally, you need to set the application key.

```bash
php artisan key:generate
```

## Execution 

To execute the project, you need to run some commands.

Execute this command if you need to clean the database:

```bash
php artisan migrate:fresh
```

Then you need to download the CSV file with the domains. To do these, you can run:

```bash
php artisan top-domains:download all 
```

to download the 5 files or you can run:

```bash
php artisan top-domains:download provider 
```
to download only 1 file. Replace `provider` with one of these providers:
  - umbrella
  - majestic
  - builtwith
  - domcop
  - tranco

Once you have the CSV dowloaded, you need to import to the database. To do it, run:

```bash
php artisan top-domains:import provider
```

Replace `provider` with one of the providers showed above.

Finally, you can run the check:

```bash
php artisan top-domains:check-wp
```

You have some parameter for this command:

  - resume: Resume the last incomplete batch instead of starting a new one
  - request_timeout: Timeout in seconds for each HTTP request (default: 10)
  - domains_per_batch: Number of domains to process per batch (default: 200)
  - concurrent_requests: Number of concurrent HTTP requests (default: 200)
  - show_temp_results_every: Show temporary results every X websites tested (default: 200)
  - domain_offset: Number of domains to skip from the top of the list (default: 600000)}'

```bash
php artisan top-domains:check-wp --resume --request_timeout=20 --domains_per_batch=500 --concurrent_requests=250 --show_temp_results_every=1000 --domain_offset=500000
```

If you have some problem with the memory limit, you can increase it by running the following command:

```bash
php -d memory_limit=1024M artisan top-domains:check-wp
```

## License

This project is licensed under the [GNU GPL v2](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html) license or higher.
