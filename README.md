# WordPress scraper

This Laravel project scraps the top 1 million websites and shows the number of websites using WordPress.
Currently, the project only scraps the top 1 million websites from [Majestic](https://majestic.com/reports/majestic-million).

You can find other top 1 millions lists [here](https://github.com/PeterDaveHello/top-1m-domains).

## Requirements

This project uses Laravel 10, so you need to have [PHP 8.1](https://laravel.com/docs/10.x/releases#php-8) 
or higher installed on your machine. I have tested this project only on PHP 8.2.

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

## Execution 

To execute the project, you need to run the following command:

```bash
php artisan scrap-majestic
```

If you have some problem with the memory limit, you can increase it by running the following command:

```bash
php -d memory_limit=512M artisan scrap-majestic
```

## License

This project is licensed under the [GNU GPL v2](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html) license or higher.
