<?php

namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;

class PremiumCalculator
{
    /**
     * Daily rates per plan code.
     */
    public const DAILY_RATES = [
        'BASIC' => 12,
        'PLUS' => 20,
        'PREMIUM' => 30,
    ];

    /**
     * Destination multipliers.
     */
    public const DESTINATION_MULTIPLIERS = [
        'DOMESTIC' => '1.00',
        'INTERNATIONAL' => '1.80',
    ];

    /**
     * Coverage (sum insured) multipliers.
     */
    public const COVERAGE_MULTIPLIERS = [
        100000 => '1.00',
        300000 => '1.25',
        500000 => '1.50',
    ];

    /**
     * Calculate travel days inclusive: (trip_end - trip_start) + 1.
     */
    public function calculateTravelDays(string $tripStart, string $tripEnd): int
    {
        $start = Carbon::parse($tripStart)->startOfDay();
        $end = Carbon::parse($tripEnd)->startOfDay();

        if ($end->lt($start)) {
            throw new InvalidArgumentException('Trip end date cannot be earlier than trip start date.');
        }

        return (int) $start->diffInDays($end) + 1;
    }

    /**
     * Calculate age on the trip start date.
     */
    public function calculateAge(string $dateOfBirth, string $tripStart): int
    {
        $dob = Carbon::parse($dateOfBirth)->startOfDay();
        $start = Carbon::parse($tripStart)->startOfDay();

        if ($dob->gt($start)) {
            throw new InvalidArgumentException('Date of birth cannot be after the trip start date.');
        }

        return (int) $dob->diffInYears($start);
    }

    /**
     * Determine daily rate for a plan code.
     */
    public function getDailyRate(string $planCode): int
    {
        $upper = strtoupper(trim($planCode));
        if (! isset(self::DAILY_RATES[$upper])) {
            throw new InvalidArgumentException("Unsupported plan code: {$planCode}");
        }

        return self::DAILY_RATES[$upper];
    }

    /**
     * Determine age multiplier based on age bands.
     * - 0–17: 1.10
     * - 18–60: 1.00
     * - 61–70: 1.50
     * - 71+: 2.00
     */
    public function getAgeMultiplier(int $age): string
    {
        if ($age < 0) {
            throw new InvalidArgumentException("Invalid negative age: {$age}");
        }

        return match (true) {
            $age <= 17 => '1.10',
            $age <= 60 => '1.00',
            $age <= 70 => '1.50',
            default => '2.00',
        };
    }

    /**
     * Determine destination multiplier.
     */
    public function getDestinationMultiplier(string $destination): string
    {
        $upper = strtoupper(trim($destination));
        if (! isset(self::DESTINATION_MULTIPLIERS[$upper])) {
            throw new InvalidArgumentException("Unsupported destination: {$destination}");
        }

        return self::DESTINATION_MULTIPLIERS[$upper];
    }

    /**
     * Determine coverage multiplier.
     */
    public function getCoverageMultiplier(int $sumInsured): string
    {
        if (! isset(self::COVERAGE_MULTIPLIERS[$sumInsured])) {
            throw new InvalidArgumentException("Unsupported sum insured: {$sumInsured}");
        }

        return self::COVERAGE_MULTIPLIERS[$sumInsured];
    }

    /**
     * Calculate the final premium and return calculation details.
     * Rounding only the final result to two decimal places using decimal-safe arithmetic.
     *
     * @return array{
     *     travel_days: int,
     *     age_on_trip_start: int,
     *     daily_rate: int,
     *     age_multiplier: string,
     *     destination_multiplier: string,
     *     coverage_multiplier: string,
     *     premium: string
     * }
     */
    public function calculate(
        string $planCode,
        string $destination,
        int $sumInsured,
        string $dateOfBirth,
        string $tripStart,
        string $tripEnd
    ): array {
        $travelDays = $this->calculateTravelDays($tripStart, $tripEnd);
        $age = $this->calculateAge($dateOfBirth, $tripStart);
        $dailyRate = $this->getDailyRate($planCode);
        $ageMultiplier = $this->getAgeMultiplier($age);
        $destinationMultiplier = $this->getDestinationMultiplier($destination);
        $coverageMultiplier = $this->getCoverageMultiplier($sumInsured);

        // Decimal-safe step-by-step arithmetic without premature rounding
        $base = bcmul((string) $dailyRate, (string) $travelDays, 6);
        $step1 = bcmul($base, $ageMultiplier, 6);
        $step2 = bcmul($step1, $destinationMultiplier, 6);
        $final = bcmul($step2, $coverageMultiplier, 6);

        // Round only the final result to two decimal places
        $rounded = round((float) $final, 2);
        $formattedPremium = number_format($rounded, 2, '.', '');

        return [
            'travel_days' => $travelDays,
            'age_on_trip_start' => $age,
            'daily_rate' => $dailyRate,
            'age_multiplier' => $ageMultiplier,
            'destination_multiplier' => $destinationMultiplier,
            'coverage_multiplier' => $coverageMultiplier,
            'premium' => $formattedPremium,
        ];
    }
}
