<?php

namespace App\Services\ClickUp\Routing;

use App\Models\ClickUpImportRule;
use Illuminate\Support\Str;

class RuleEvaluatorService
{
    /**
     * Evaluate if a normalized import row matches the given rule.
     *
     * @param ClickUpImportRule|array $rule
     * @param array $normalizedRow
     * @return bool
     */
    public function matchesRule(mixed $rule, array $normalizedRow): bool
    {
        $conditions = is_array($rule)
            ? ($rule['conditions'] ?? null)
            : ($rule->conditions ?? null);

        $operator = strtoupper(is_array($rule)
            ? ($rule['operator'] ?? 'AND')
            : ($rule->operator ?? 'AND'));

        // If no explicit conditions array, evaluate legacy single field/value
        if (empty($conditions) || !is_array($conditions)) {
            $excelField = is_array($rule) ? ($rule['excel_field'] ?? '') : ($rule->excel_field ?? '');
            $excelValue = is_array($rule) ? ($rule['excel_value'] ?? '') : ($rule->excel_value ?? '');

            if (blank($excelField)) {
                return false;
            }

            return $this->evaluateSingleCondition([
                'field' => $excelField,
                'operator' => 'equals',
                'value' => $excelValue,
            ], $normalizedRow);
        }

        if (empty($conditions)) {
            return false;
        }

        if ($operator === 'OR') {
            foreach ($conditions as $condition) {
                if ($this->evaluateSingleCondition($condition, $normalizedRow)) {
                    return true;
                }
            }
            return false;
        }

        // Default 'AND': All conditions must pass
        foreach ($conditions as $condition) {
            if (!$this->evaluateSingleCondition($condition, $normalizedRow)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate a single condition against a normalized import row.
     *
     * @param array{field: string, operator?: string, value?: string} $condition
     * @param array $normalizedRow
     * @return bool
     */
    public function evaluateSingleCondition(array $condition, array $normalizedRow): bool
    {
        $fieldKey = Str::of((string) ($condition['field'] ?? ''))->lower()->replace(['-', '_'], ' ')->squish()->toString();

        if (blank($fieldKey)) {
            return false;
        }

        $rowValue = data_get($normalizedRow, $fieldKey);
        if ($rowValue === null) {
            // Check fallback keys if not present directly
            $rowValue = data_get($normalizedRow, (string) ($condition['field'] ?? ''));
        }

        $actual = strtolower(trim((string) ($rowValue ?? '')));
        $expected = strtolower(trim((string) ($condition['value'] ?? '')));
        $op = strtolower(trim((string) ($condition['operator'] ?? 'equals')));

        return match ($op) {
            'equals', 'eq', 'is' => $actual === $expected,
            'contains', 'like' => str_contains($actual, $expected),
            'not_equals', 'neq', 'is_not' => $actual !== $expected,
            'starts_with' => str_starts_with($actual, $expected),
            'ends_with' => str_ends_with($actual, $expected),
            'is_not_empty' => filled($actual),
            'is_empty' => blank($actual),
            default => $actual === $expected,
        };
    }
}
