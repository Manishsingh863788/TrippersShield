<?php

namespace Tests\Unit;

use App\Services\PremiumCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PremiumCalculatorTest extends TestCase
{
    private PremiumCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PremiumCalculator();
    }

    public function test_matches_required_specification_example(): void
    {
        // Specification example:
        // Plan: PLUS (₹20/day)
        // Trip: 10 November 2026 through 14 November 2026 (5 days inclusive)
        // Age: 28 on trip start (multiplier 1.00)
        // Destination: INTERNATIONAL (multiplier 1.80)
        // Coverage: ₹300,000 (multiplier 1.25)
        // Travel days: 5
        // Expected Premium: ₹225.00
        $result = $this->calculator->calculate(
            planCode: 'PLUS',
            destination: 'INTERNATIONAL',
            sumInsured: 300000,
            dateOfBirth: '1998-11-10', // Exactly 28 on 2026-11-10
            tripStart: '2026-11-10',
            tripEnd: '2026-11-14'
        );

        $this->assertSame(5, $result['travel_days']);
        $this->assertSame(28, $result['age_on_trip_start']);
        $this->assertSame(20, $result['daily_rate']);
        $this->assertSame('1.00', $result['age_multiplier']);
        $this->assertSame('1.80', $result['destination_multiplier']);
        $this->assertSame('1.25', $result['coverage_multiplier']);
        $this->assertSame('225.00', $result['premium']);
    }

    public function test_travel_days_is_inclusive(): void
    {
        // Same day trip is 1 day
        $this->assertSame(1, $this->calculator->calculateTravelDays('2026-12-01', '2026-12-01'));
        // 3 days trip
        $this->assertSame(3, $this->calculator->calculateTravelDays('2026-12-01', '2026-12-03'));
    }

    public function test_rejects_trip_end_before_start(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculateTravelDays('2026-12-05', '2026-12-01');
    }

    public function test_age_calculated_on_trip_start_not_today(): void
    {
        // Born 2000-05-15. Trip start 2026-05-14 -> age 25. Trip start 2026-05-15 -> age 26.
        $this->assertSame(25, $this->calculator->calculateAge('2000-05-15', '2026-05-14'));
        $this->assertSame(26, $this->calculator->calculateAge('2000-05-15', '2026-05-15'));
    }

    public function test_daily_rates_for_all_plans(): void
    {
        $this->assertSame(12, $this->calculator->getDailyRate('BASIC'));
        $this->assertSame(20, $this->calculator->getDailyRate('PLUS'));
        $this->assertSame(30, $this->calculator->getDailyRate('PREMIUM'));
    }

    public function test_age_multiplier_for_all_bands(): void
    {
        // 0-17: 1.10
        $this->assertSame('1.10', $this->calculator->getAgeMultiplier(0));
        $this->assertSame('1.10', $this->calculator->getAgeMultiplier(17));

        // 18-60: 1.00
        $this->assertSame('1.00', $this->calculator->getAgeMultiplier(18));
        $this->assertSame('1.00', $this->calculator->getAgeMultiplier(60));

        // 61-70: 1.50
        $this->assertSame('1.50', $this->calculator->getAgeMultiplier(61));
        $this->assertSame('1.50', $this->calculator->getAgeMultiplier(70));

        // 71+: 2.00
        $this->assertSame('2.00', $this->calculator->getAgeMultiplier(71));
        $this->assertSame('2.00', $this->calculator->getAgeMultiplier(85));
    }

    public function test_destination_multipliers(): void
    {
        $this->assertSame('1.00', $this->calculator->getDestinationMultiplier('DOMESTIC'));
        $this->assertSame('1.80', $this->calculator->getDestinationMultiplier('INTERNATIONAL'));
    }

    public function test_coverage_multipliers(): void
    {
        $this->assertSame('1.00', $this->calculator->getCoverageMultiplier(100000));
        $this->assertSame('1.25', $this->calculator->getCoverageMultiplier(300000));
        $this->assertSame('1.50', $this->calculator->getCoverageMultiplier(500000));
    }

    public function test_unsupported_plan_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->getDailyRate('ULTRA');
    }

    public function test_unsupported_destination_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->getDestinationMultiplier('MARS');
    }

    public function test_unsupported_coverage_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->getCoverageMultiplier(250000);
    }
}
