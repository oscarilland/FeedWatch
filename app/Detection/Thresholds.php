<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * Every threshold detection and status use, read from the `settings` table
 * (plan 004) so they can be tuned without a deploy. Each value is
 * range-checked; an invalid one falls back to its default.
 */
final class Thresholds
{
    /**
     * name => [default, minimum, maximum]
     *
     * @var array<string, array{float, float, float}>
     */
    public const array SETTINGS = [
        'gap_tolerance_ms' => [2000.0, 200.0, 4000.0],
        'silence_ms' => [2000.0, 500.0, 30000.0],
        'line_gaps_amber' => [3.0, 1.0, 100.0],
        'line_gap_window_s' => [300.0, 60.0, 3600.0],
        'data_loss_red_s' => [60.0, 10.0, 3600.0],
        'latency_amber_multiple' => [3.0, 1.5, 50.0],
        'latency_red_multiple' => [10.0, 2.0, 100.0],
        'latency_sustain_s' => [5.0, 1.0, 60.0],
        'baseline_window_min' => [30.0, 5.0, 240.0],
        'idle_after_min' => [30.0, 5.0, 1440.0],
        'incident_merge_s' => [30.0, 0.0, 600.0],
        'chaos_expiry_min' => [5.0, 1.0, 60.0],
    ];

    private function __construct(
        public readonly int $gapToleranceUs,
        public readonly int $silenceUs,
        public readonly int $lineGapsAmber,
        public readonly int $lineGapWindowUs,
        public readonly int $dataLossRedUs,
        public readonly float $latencyAmberMultiple,
        public readonly float $latencyRedMultiple,
        public readonly int $latencySustainSeconds,
        public readonly int $baselineWindowMinutes,
        public readonly int $idleAfterUs,
        public readonly int $incidentMergeUs,
        public readonly int $chaosExpiryUs,
    ) {
    }

    public static function defaults(): self
    {
        return self::fromSettings([])[0];
    }

    /**
     * @param array<string, string> $settings name => value, as stored
     * @return array{self, list<string>} the thresholds, and a warning for each value that was rejected
     */
    public static function fromSettings(array $settings): array
    {
        $warnings = [];
        $value = static function (string $name) use ($settings, &$warnings): float {
            [$default, $min, $max] = self::SETTINGS[$name];
            if (!array_key_exists($name, $settings)) {
                return $default;
            }
            $raw = trim($settings[$name]);
            if (!is_numeric($raw) || (float) $raw < $min || (float) $raw > $max) {
                $warnings[] = "Setting {$name} must be a number from {$min} to {$max}; using {$default}";

                return $default;
            }

            return (float) $raw;
        };

        $amber = $value('latency_amber_multiple');
        $red = $value('latency_red_multiple');
        if ($red <= $amber) {
            $warnings[] = 'Setting latency_red_multiple must be above latency_amber_multiple; using the defaults for both';
            $amber = self::SETTINGS['latency_amber_multiple'][0];
            $red = self::SETTINGS['latency_red_multiple'][0];
        }

        $thresholds = new self(
            (int) round($value('gap_tolerance_ms') * 1_000),
            (int) round($value('silence_ms') * 1_000),
            (int) round($value('line_gaps_amber')),
            (int) round($value('line_gap_window_s') * 1_000_000),
            (int) round($value('data_loss_red_s') * 1_000_000),
            $amber,
            $red,
            (int) round($value('latency_sustain_s')),
            (int) round($value('baseline_window_min')),
            (int) round($value('idle_after_min') * 60_000_000),
            (int) round($value('incident_merge_s') * 1_000_000),
            (int) round($value('chaos_expiry_min') * 60_000_000),
        );

        return [$thresholds, $warnings];
    }
}
