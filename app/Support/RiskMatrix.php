<?php

namespace App\Support;

/**
 * The 5×5 risk matrix used to rate a hazard at a building or area (thesis
 * panel, round 2: replaces the hand-picked Low / Medium / High).
 *
 *   risk score = likelihood (1–5) × impact (1–5)       → 1 to 25
 *   risk level = Low 1–4 · Moderate 5–9 · High 10–16 · Very High 17–25
 *
 * The inspector rates the two factors; the score and level are always
 * computed here, never typed in. The form, the badges, the dashboard, the
 * reports and the Help page all read this class; the plain-language meaning
 * of each level is in config/glossary.php (risk_levels).
 *
 * Basis: the likelihood × consequence matrix of ISO 31000 risk management, as
 * used in UNDRR's Risk = Hazard × Exposure × Vulnerability framing, and the
 * semi-quantitative school-building risk index of Oreta & Brizuela (DLSU,
 * 2013), which also bands buildings by the product of rated factors.
 *
 * FOR DRRMO REVIEW: the wording of each step and the band edges.
 */
final class RiskMatrix
{
    public const LIKELIHOOD = [
        1 => ['label' => 'Rare', 'meaning' => 'Could happen only in exceptional cases (less than once in 10 years).'],
        2 => ['label' => 'Unlikely', 'meaning' => 'Could happen at some time (about once in 5 to 10 years).'],
        3 => ['label' => 'Possible', 'meaning' => 'Might happen (about once in 1 to 5 years).'],
        4 => ['label' => 'Likely', 'meaning' => 'Will probably happen (about once a year).'],
        5 => ['label' => 'Almost certain', 'meaning' => 'Expected to happen (several times a year, or already happening).'],
    ];

    public const IMPACT = [
        1 => ['label' => 'Insignificant', 'meaning' => 'No injuries; negligible damage; classes go on.'],
        2 => ['label' => 'Minor', 'meaning' => 'First-aid injuries; small repairable damage; disruption of less than a day.'],
        3 => ['label' => 'Moderate', 'meaning' => 'Injuries needing medical care; part of the building unusable; classes stopped for up to a week.'],
        4 => ['label' => 'Major', 'meaning' => 'Serious injuries; the building is unsafe until repaired; classes stopped for more than a week.'],
        5 => ['label' => 'Catastrophic', 'meaning' => 'Deaths or many serious injuries; collapse or total loss; long closure.'],
    ];

    /** Highest score of each level, lowest level first. */
    public const BANDS = ['Low' => 4, 'Moderate' => 9, 'High' => 16, 'Very High' => 25];

    /** Levels, lowest first. */
    public const LEVELS = ['Low', 'Moderate', 'High', 'Very High'];

    /** Levels counted as "high risk" on the dashboard and in reports. */
    public const HIGH_LEVELS = ['High', 'Very High'];

    /** Fill colour per level, for charts and map markers (light, dark). */
    public const COLOURS = [
        'Low' => ['#16a34a', '#4ade80'],
        'Moderate' => ['#eab308', '#facc15'],
        'High' => ['#ea580c', '#fb923c'],
        'Very High' => ['#dc2626', '#f87171'],
    ];

    public static function score(int $likelihood, int $impact): int
    {
        if (! isset(self::LIKELIHOOD[$likelihood], self::IMPACT[$impact])) {
            throw new \InvalidArgumentException('Likelihood and impact are each rated 1 to 5.');
        }

        return $likelihood * $impact;
    }

    public static function level(int $score): string
    {
        foreach (self::BANDS as $level => $max) {
            if ($score <= $max) {
                return $level;
            }
        }

        return 'Very High';
    }

    /** Level for a likelihood and impact pair. */
    public static function rate(int $likelihood, int $impact): string
    {
        return self::level(self::score($likelihood, $impact));
    }

    /** "5–9" style range of a level. */
    public static function range(string $level): string
    {
        $previous = 0;
        foreach (self::BANDS as $name => $max) {
            if ($name === $level) {
                return ($previous + 1) . '–' . $max;
            }
            $previous = $max;
        }

        return '';
    }

    /** Older records were rated Low / Medium / High by hand; Medium is now Moderate. */
    public static function normalise(?string $level): ?string
    {
        return $level === 'Medium' ? 'Moderate' : $level;
    }
}
