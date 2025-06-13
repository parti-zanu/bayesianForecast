<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use MathPHP\Statistics\Average;
use MathPHP\Statistics\Descriptive;
use MathPHP\Probability\Distribution\Continuous\Normal;


class GetFcast extends Command
{

	protected $signature = 'app:get-fcast';
	protected $description = '';

	private const RANGE_MIN_MULTIPLIER = 0.8;
	private const RANGE_MAX_MULTIPLIER = 1.2;
	private const STDDEV_RANGE = 3; // Number of stddevs for min/max
	private const NUM_STEPS = 1000; // Binning for totals
	private const ALPHA_DECAY = 0.5; // Recency weight decay
	private const PSEUDOCOUNT = 0.1; // For histogram prior
	private const CREDIBLE_INTERVAL_LOWER = 0.05;
	private const CREDIBLE_INTERVAL_UPPER = 0.95;
	private const OBS_NOISE_MIN = 0.03; // Minimum percent of mean for noise
	private const OBS_NOISE_STD_MULT = 1.0; // Multiplier for stddev in noise


	/**
	 *
	 */
	public function __construct() {
		parent::__construct();
	}

	/**
	 *
	 */
	public function handle() {

		$old = [5121.11,7519.06,7781.19,8492.45,8372.08,9314.49,11273.61,8003.63,8177.52,8688.28,9644.96,];
		$current = 4199;

		dd($this->bayesianForecast($old,$current));
	}



	/**
	 * Bayesian forecast for end-of-month total using historical monthly data and current month progress.
	 *
	 * Given an array of previous monthly totals and the partial value for the current month so far,
	 * this method estimates the most probable total for the end of the current month using a Bayesian
	 * updating approach with an empirical recency-weighted prior and a normal likelihood.
	 *
	 * The method dynamically adapts to outliers, regime changes, and unexpected current-month behavior.
	 * It returns the expected value, credible interval, posterior standard deviation, and diagnostics/warnings
	 * for interpreting forecast confidence and model fit.
	 *
	 * @param array $historical     Array of previous monthly totals (float values, at least 6 elements recommended).
	 * @param float $currentPartial Amount recorded so far in the current month.
	 * @return array {
	 *   @var float   bayesian_expected_total         Posterior mean (forecast point estimate).
	 *   @var array   bayesian_credible_interval     Central credible interval [lower, upper].
	 *   @var float   credible_interval_width        Width of the credible interval.
	 *   @var float   expected_position_in_interval  Normalized position of the mean in the interval (0=lower, 1=upper).
	 *   @var float   posterior_stddev               Standard deviation of posterior (uncertainty).
	 *   @var ?array  warnings                       List of diagnostic messages about model reliability/precision.
	 * }
	 */

	public function bayesianForecast(array $historical, float $currentPartial): array {
	    // ---- STEP 1: Gather and validate input ----

	    // Current day-of-month and total days in this month
	    $daysSoFar = (int) date('j');
	    $daysInMonth = (int) date('t');

	    // Require at least 6 historical months and a valid current day context
	    if (count($historical) < 6 || $daysSoFar <= 0 || $daysInMonth <= 0) {
	        return [
	            'error' => 'Invalid input.'
	        ];
	    }

	    // Remove any invalid (zero/negative) values from history
	    $historical = array_filter($historical, fn($v) => $v > 0);

	    if (count($historical) < 6) {
	        return [
	            'error' => 'Not enough valid historical data.'
	        ];
	    }

	    // ---- STEP 2: Compute prior distribution and grid ----

	    // Historical mean and stddev (via MathPHP)
	    $mean = Average::mean($historical);
	    $std = Descriptive::standardDeviation($historical, false); // population stddev
	    if ($std == 0) {
	        $std = $mean * self::OBS_NOISE_MIN ?: 1;
	    }

	    // Dynamic grid: ensure currentPartial is included
	    $min = min($mean - self::STDDEV_RANGE * $std, $currentPartial * 0.95, $currentPartial - ($mean * 0.25));
	    $max = max($mean + self::STDDEV_RANGE * $std, $currentPartial * 1.25, $currentPartial + ($mean * 0.5));
	    if ($min > $currentPartial * 0.95) {
	        $min = $currentPartial * 0.95;
	    }
	    $numSteps = self::NUM_STEPS;
	    $step = ($max - $min) / $numSteps;
	    $possibleTotals = range($min, $max, $step);

	    $numBins = count($possibleTotals);
	    $histogramBandwidth = max(1, ($max - $min) / $numBins);

	    // ---- STEP 3: Weight recent data higher for prior (exponential decay) ----
	    $alpha = self::ALPHA_DECAY;
	    $N = count($historical);
	    $weights = [];
	    for ($i = 0; $i < $N; $i++) {
	        $weights[] = pow($alpha, $N - $i - 1);
	    }

	    // ---- STEP 4: Build empirical prior with recency weighting ----
	    $prior = [];
	    $totalPriorWeight = 0;
	    foreach ($possibleTotals as $total) {
	        $weightedCount = 0;
	        foreach ($historical as $i => $monthTotal) {
	            if (abs($monthTotal - $total) <= $histogramBandwidth / 2) {
	                $weightedCount += $weights[$i];
	            }
	        }
	        $priorProb = $weightedCount + self::PSEUDOCOUNT;
	        $prior[$total] = $priorProb;
	        $totalPriorWeight += $priorProb;
	    }
	    foreach ($prior as $total => $prob) {
	        $prior[$total] = $prob / $totalPriorWeight;
	    }

	    // ---- STEP 5: Edge case—day 1 or no data yet ----
	    if ($daysSoFar <= 1 || $currentPartial <= 0) {
	        return [
	            'bayesian_expected_total' => round($mean, 2),
	            'bayesian_credible_interval' => [
	                'lower' => round($mean - 2 * $std, 2),
	                'upper' => round($mean + 2 * $std, 2),
	            ],
	            'credible_interval_width' => round(4 * $std, 2),
	            'expected_position_in_interval' => 0.5,
	            'posterior_stddev' => round($std, 2),
	            'warnings' => ['Insufficient data for Bayesian update; using historical mean only.'],
	        ];
	    }

	    // ---- STEP 6: Bayesian update (likelihood and posterior) ----
	    $progress = $daysSoFar / $daysInMonth;
	    $observationNoise = max(
	        $std * $progress * self::OBS_NOISE_STD_MULT,
	        $mean * self::OBS_NOISE_MIN
	    );

	    $posterior = [];
	    $posteriorSum = 0;
	    foreach ($possibleTotals as $total) {
	        // Forbid totals below current consumption
	        if ($total < $currentPartial) {
	            $posterior[$total] = 0;
	            continue;
	        }
	        $priorProb = $prior[$total];
	        $expectedPartial = $total * $progress;
	        $likelihoodDist = new Normal($expectedPartial, $observationNoise);
	        $likelihood = $likelihoodDist->pdf($currentPartial);
	        $posteriorProb = $priorProb * $likelihood;
	        $posterior[$total] = $posteriorProb;
	        $posteriorSum += $posteriorProb;
	    }
	    // Normalize posterior to sum to 1
	    foreach ($posterior as $total => $value) {
	        $posterior[$total] = $posteriorSum > 0 ? $value / $posteriorSum : 0;
	    }

	    // ---- STEP 7: Posterior summary statistics ----
	    // Posterior mean (expected value)
	    $expectedValue = 0;
	    foreach ($posterior as $total => $prob) {
	        $expectedValue += $total * $prob;
	    }

	    // Posterior mode (most likely value)
	    $maxPosteriorTotal = array_search(max($posterior), $posterior);

	    // Compute credible interval based on configured percentiles
	    ksort($posterior);
	    $cumulative = 0;
	    $lower = null;
	    $upper = null;
	    foreach ($posterior as $total => $prob) {
	        $cumulative += $prob;
	        if ($lower === null && $cumulative >= self::CREDIBLE_INTERVAL_LOWER) {
	            $lower = $total;
	        }
	        if ($upper === null && $cumulative >= self::CREDIBLE_INTERVAL_UPPER) {
	            $upper = $total;
	            break;
	        }
	    }

	    // Credible interval width (uncertainty metric)
	    $intervalWidth = $upper - $lower;

	    // Position of expected value within the interval [0=lower bound, 1=upper bound]
	    $positionInInterval = ($intervalWidth > 0)
	        ? ($expectedValue - $lower) / $intervalWidth
	        : null;

	    // Posterior standard deviation (uncertainty)
	    $posteriorStddev = 0;
	    foreach ($posterior as $total => $prob) {
	        $posteriorStddev += pow($total - $expectedValue, 2) * $prob;
	    }
	    $posteriorStddev = sqrt($posteriorStddev);

	    // Collect all warnings for diagnostics
	    $warnings = [];
	    if ($positionInInterval !== null) {
	        if ($positionInInterval < 0.10) {
	            $warnings[] = "Forecast is near lower end of credible interval—possible regime change or outlier.";
	        }
	        if ($positionInInterval > 0.90) {
	            $warnings[] = "Forecast is near upper end of credible interval—possible regime change or outlier.";
	        }
	        if ($intervalWidth > 2 * $expectedValue) {
	            $warnings[] = "Forecast uncertainty is very high—interval is more than twice the mean. Use with caution.";
	        }
		    if ($intervalWidth <= 0.2 * $expectedValue) {
		    	// If the credible interval width is less than, say, 20% of the forecast mean, consider it “precise enough for confident planning.”
		        $warnings[] = "Forecast is precise: credible interval is narrow and can be used with high confidence for planning.";
		    }
	    }

	    // ---- STEP 8: Return all diagnostics and forecast ----
	    return [
	        'bayesian_expected_total' => round($expectedValue, 2),
	        'bayesian_credible_interval' => [
	            'lower' => round($lower, 2),
	            'upper' => round($upper, 2),
	        ],
	        'credible_interval_width' => round($intervalWidth, 2),
	        'expected_position_in_interval' => $positionInInterval !== null ? round($positionInInterval, 3) : null,
	        'posterior_stddev' => round($posteriorStddev, 2),
	        'warnings' => !empty($warnings) ? $warnings : null,
	    ];
	}





}//EOF
