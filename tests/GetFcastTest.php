<?php
use PHPUnit\Framework\TestCase;
use App\Console\Commands\GetFcast;

require_once __DIR__ . '/../GetFcast.php';

class GetFcastTest extends TestCase
{
    public function testForecastReturnsExpectedKeys()
    {
        $cmd = new GetFcast();
        $historical = [1000, 1200, 1500, 1600, 1700, 1800];
        $current = 900;
        $result = $cmd->bayesianForecast($historical, $current);

        $this->assertArrayHasKey('bayesian_expected_total', $result);
        $this->assertArrayHasKey('bayesian_credible_interval', $result);
        $this->assertArrayHasKey('credible_interval_width', $result);
        $this->assertArrayHasKey('expected_position_in_interval', $result);
        $this->assertArrayHasKey('posterior_stddev', $result);
        $this->assertArrayHasKey('warnings', $result);
    }

    public function testForecastRequiresSufficientHistory()
    {
        $cmd = new GetFcast();
        $historical = [1000, 1200];
        $result = $cmd->bayesianForecast($historical, 100);
        $this->assertArrayHasKey('error', $result);
    }

    public function testNegativeHistoricalValuesAreFiltered()
    {
        $cmd = new GetFcast();
        $historical = [1000, -200, 1500, 1800, 1600, 1900];
        $result = $cmd->bayesianForecast($historical, 100);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertArrayHasKey('bayesian_expected_total', $result);
    }
}
