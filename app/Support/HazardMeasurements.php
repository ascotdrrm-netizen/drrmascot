<?php

namespace App\Support;

use App\Models\Incident\Incident;

/**
 * The one definition of hazard measurements (config/hazard_measurements.php),
 * read by both modules:
 *
 * - a Disaster Advisory asks for its hazard's fields as written — forecasts
 *   from the bulletin, with the one figure the warning is built around
 *   required;
 * - a Disaster-related incident records the same fields of its type as
 *   OBSERVED: the field's 'observed_label' where the advisory's label is
 *   worded as a forecast (same key, same unit, same validation), and every
 *   field optional — a blank is better than a guess. Campus emergencies,
 *   Other, and a type with no entry (Fire) record none.
 */
class HazardMeasurements
{
    /** A hazard's fields as an advisory asks for them. */
    public static function forAdvisory(?string $hazard): array
    {
        return config("hazard_measurements.{$hazard}", []);
    }

    /** An incident type's fields as an incident records them, or [] when it records none. */
    public static function forIncident(?string $type): array
    {
        if (Incident::groupOf($type) !== Incident::DISASTER_GROUP) {
            return [];
        }

        return array_map(
            fn (array $field) => ['label' => $field['observed_label'] ?? $field['label'], 'required' => false] + $field,
            config("hazard_measurements.{$type}", []),
        );
    }

    /** label => value (and unit), for the filled fields only, in field order. */
    public static function lines(array $fields, ?array $values): array
    {
        $lines = [];

        foreach ($fields as $key => $field) {
            $value = $values[$key] ?? null;

            if (filled($value)) {
                $lines[$field['label']] = $value . (isset($field['unit']) ? ' ' . $field['unit'] : '');
            }
        }

        return $lines;
    }

    /**
     * Validation for each field ("measurements.<key>"), with the field's
     * label as its attribute name: [rules, attributes].
     */
    public static function fieldRules(array $fields): array
    {
        $rules = [];
        $attributes = [];

        foreach ($fields as $key => $field) {
            $rule = [($field['required'] ?? false) ? 'required' : 'nullable'];
            $rules["measurements.{$key}"] = array_merge($rule, match ($field['type']) {
                'number' => ['numeric', "min:{$field['min']}", "max:{$field['max']}"],
                'select' => ['in:' . implode(',', array_keys($field['options']))],
                default => ['string', 'max:' . $field['max']],
            });
            $attributes["measurements.{$key}"] = $field['label'];
        }

        return [$rules, $attributes];
    }

    /** The submitted values without blanks, or null when nothing is left. */
    public static function filled(?array $values): ?array
    {
        $kept = array_filter($values ?? [], fn ($value) => $value !== null && $value !== '');

        return $kept ?: null;
    }
}
