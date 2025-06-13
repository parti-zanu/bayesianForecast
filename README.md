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
```


## Parameters

- **$historical**: An array of previous full-month totals (float values, at least 6 recommended for stability).
- **$currentPartial**: The amount recorded so far in the current month.

## Output

- **bayesian_expected_total**: Forecasted total for the end of the month (posterior mean).
- **bayesian_credible_interval**: Central credible interval for the forecast (default: 90%).
- **credible_interval_width**: The width of the credible interval (lower to upper).
- **expected_position_in_interval**: Where the mean falls inside the interval (0=lower, 1=upper).
- **posterior_stddev**: Standard deviation of the posterior distribution (forecast uncertainty).
- **warnings**: Array of warnings or positive diagnostics about precision, outliers, or uncertainty.

## Interpretation

- Point estimate: Use bayesian_expected_total as your “best guess” forecast.
- Credible interval: Plan for outcomes between lower and upper (90% probability, can adjust).
- Warnings: Heed any returned warnings—they highlight when forecasts may be risky or exceptionally reliable.
- Diagnostic values: Wide intervals or high stddevs signal caution; narrow intervals mean high confidence.

## Tuning

- Credible interval percentiles (e.g., to 95%)
- Recency decay (ALPHA_DECAY) for how much weight to give to recent months
- Number of grid steps (NUM_STEPS) for accuracy vs. performance

## Limitations

- Assumes monthly data; cannot model intra-month patterns or shocks not reflected in history.
- If current month’s value is unprecedented, forecast will extrapolate, but always respect observed minimums.


