# Bayesian Forecast Command for Laravel

A robust, diagnostic-ready Bayesian forecast for projecting end-of-month totals from historical monthly data, written as a Laravel console command.

## Features

- Bayesian inference with recency-weighted empirical prior
- Robust to outliers and regime changes
- Dynamic credible intervals, posterior diagnostics, and actionable warnings
- Auto-expands forecast range if current month’s spend is unprecedented
- Highly configurable and easy to extend

## Requirements

- PHP 8.1+
- [Laravel framework](https://laravel.com/) (tested on 9.x/10.x)
- [markrogoyski/math-php](https://github.com/markrogoyski/math-php) (`composer require markrogoyski/math-php`)

## Installation

1. Copy the `GetFcast` command to your `app/Console/Commands` directory.
2. Register the command in your `Kernel.php` if you want to use it via `artisan`.
3. Ensure `markrogoyski/math-php` is installed via Composer.

## Usage

From within your Laravel app directory, you can call the forecast directly, or trigger via Artisan:

### Example (standalone usage):

```php
use App\Console\Commands\GetFcast;

$historical = [5121.11,7519.06,7781.19,8492.45,8372.08,9314.49,11273.61,8003.63,8177.52,8688.28,9644.96];
$current = 15000; // Amount spent so far this month

$command = new GetFcast();
$result = $command->bayesianForecast($historical, $current);

print_r($result);

Array
(
    [bayesian_expected_total] => 15387.67
    [bayesian_credible_interval] => Array
        (
            [lower] => 15042.0
            [upper] => 15789.0
        )
    [credible_interval_width] => 747
    [expected_position_in_interval] => 0.61
    [posterior_stddev] => 192.56
    [warnings] => Array
        (
            [0] => Forecast is precise: credible interval is narrow and can be used with high confidence for planning.
        )
)

