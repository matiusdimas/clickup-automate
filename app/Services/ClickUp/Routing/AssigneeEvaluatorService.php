<?php

namespace App\Services\ClickUp\Routing;

use App\Models\ClickUpAssigneeRule;
use App\Services\ClickUp\ClickUpAppRegistry;
use App\Services\ClickUp\ClickUpUserRegistry;

class AssigneeEvaluatorService
{
    private RuleEvaluatorService $ruleEvaluator;

    public function __construct(?RuleEvaluatorService $ruleEvaluator = null)
    {
        $this->ruleEvaluator = $ruleEvaluator ?? new RuleEvaluatorService();
    }

    /**
     * Resolve array of ClickUp User IDs to assign for a ticket payload or application.
     *
     * @param string $appName Name of target app (e.g. 'Cafeins', 'Infra - cPanel Arint')
     * @param array $normalizedRow Full normalized Excel/SDP row
     * @param iterable|null $customRules Optional rules collection for testing/override
     * @return int[] Array of integer ClickUp User IDs
     */
    public function resolveAssignees(string $appName, array $normalizedRow = [], ?iterable $customRules = null): array
    {
        $appNameClean = trim($appName);
        $isInfra = ClickUpAppRegistry::isInfraApp($appNameClean);
        $categoryKey = $isInfra ? 'INFRA' : 'MAIN';

        $rules = $customRules ?? $this->fetchActiveRules();

        foreach ($rules as $rule) {
            if ($this->matchesAssigneeRule($rule, $appNameClean, $categoryKey, $normalizedRow)) {
                $ids = is_array($rule) ? ($rule['assignee_ids'] ?? []) : ($rule->assignee_ids ?? []);
                if (is_array($ids) && !empty($ids)) {
                    return array_map('intval', array_values(array_unique($ids)));
                }
            }
        }

        // Fallback default rules
        if ($isInfra) {
            return ClickUpUserRegistry::defaultInfraAppAssignees();
        }

        return ClickUpUserRegistry::defaultMainAppAssignees();
    }

    /**
     * Determine if a rule matches the given application, category, and row data.
     */
    public function matchesAssigneeRule(mixed $rule, string $appName, string $categoryKey, array $normalizedRow): bool
    {
        $ruleCat = strtoupper((string) (is_array($rule) ? ($rule['app_category'] ?? 'ALL') : ($rule->app_category ?? 'ALL')));
        $targetApp = trim((string) (is_array($rule) ? ($rule['target_app'] ?? '') : ($rule->target_app ?? '')));
        $conditions = is_array($rule) ? ($rule['conditions'] ?? null) : ($rule->conditions ?? null);

        // Check App or Category Match
        $categoryMatches = false;
        if ($ruleCat === 'ALL') {
            $categoryMatches = true;
        } elseif ($ruleCat === 'SPECIFIC') {
            $categoryMatches = strtolower($targetApp) === strtolower($appName);
        } elseif ($ruleCat === $categoryKey) {
            if (filled($targetApp)) {
                $categoryMatches = strtolower($targetApp) === strtolower($appName);
            } else {
                $categoryMatches = true;
            }
        }

        if (!$categoryMatches) {
            return false;
        }

        // Check Extra Field Conditions if defined
        if (!empty($conditions) && is_array($conditions) && !empty($normalizedRow)) {
            return $this->ruleEvaluator->matchesRule(['conditions' => $conditions, 'operator' => is_array($rule) ? ($rule['operator'] ?? 'AND') : ($rule->operator ?? 'AND')], $normalizedRow);
        }

        return true;
    }

    /**
     * Fetch active assignee rules ordered by priority desc.
     */
    protected function fetchActiveRules(): iterable
    {
        try {
            return ClickUpAssigneeRule::query()
                ->where('is_active', true)
                ->orderBy('priority', 'desc')
                ->orderBy('id', 'desc')
                ->get();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
