<?php

namespace Tests\Unit;

use App\Services\ClickUp\Routing\RuleEvaluatorService;
use PHPUnit\Framework\TestCase;

class RuleEvaluatorServiceTest extends TestCase
{
    private RuleEvaluatorService $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new RuleEvaluatorService();
    }

    public function test_evaluates_single_equals_condition(): void
    {
        $condition = ['field' => 'subcategory', 'operator' => 'equals', 'value' => 'Ultima'];
        $row = ['subcategory' => 'Ultima', 'account' => 'BPD Riau'];

        $this->assertTrue($this->evaluator->evaluateSingleCondition($condition, $row));
        $this->assertFalse($this->evaluator->evaluateSingleCondition($condition, ['subcategory' => 'Cafeins']));
    }

    public function test_evaluates_single_contains_condition(): void
    {
        $condition = ['field' => 'account', 'operator' => 'contains', 'value' => 'Safari'];
        $row = ['account' => 'Royal Safari Garden'];

        $this->assertTrue($this->evaluator->evaluateSingleCondition($condition, $row));
        $this->assertFalse($this->evaluator->evaluateSingleCondition($condition, ['account' => 'BPD Riau']));
    }

    public function test_evaluates_starts_with_and_ends_with_conditions(): void
    {
        $startsWithCond = ['field' => 'subject', 'operator' => 'starts_with', 'value' => 'INCIDENT'];
        $endsWithCond = ['field' => 'email', 'operator' => 'ends_with', 'value' => '@lintasarta.co.id'];

        $row = [
            'subject' => 'Incident - DB Down',
            'email' => 'user@lintasarta.co.id',
        ];

        $this->assertTrue($this->evaluator->evaluateSingleCondition($startsWithCond, $row));
        $this->assertTrue($this->evaluator->evaluateSingleCondition($endsWithCond, $row));
    }

    public function test_evaluates_not_equals_and_is_not_empty(): void
    {
        $notEqualsCond = ['field' => 'status', 'operator' => 'not_equals', 'value' => 'closed'];
        $isNotEmptyCond = ['field' => 'description', 'operator' => 'is_not_empty'];

        $row = [
            'status' => 'open',
            'description' => 'Server issue details',
        ];

        $this->assertTrue($this->evaluator->evaluateSingleCondition($notEqualsCond, $row));
        $this->assertTrue($this->evaluator->evaluateSingleCondition($isNotEmptyCond, $row));
    }

    public function test_matches_rule_with_and_operator_all_conditions_must_pass(): void
    {
        $rule = [
            'operator' => 'AND',
            'conditions' => [
                ['field' => 'account', 'operator' => 'equals', 'value' => 'BPD Riau'],
                ['field' => 'service_category', 'operator' => 'contains', 'value' => 'Managed'],
            ],
            'target_module' => 'EBESHA',
        ];

        $matchingRow = [
            'account' => 'BPD Riau',
            'service category' => 'Managed Service',
        ];

        $partialRow = [
            'account' => 'BPD Riau',
            'service category' => 'Direct Support',
        ];

        $this->assertTrue($this->evaluator->matchesRule($rule, $matchingRow));
        $this->assertFalse($this->evaluator->matchesRule($rule, $partialRow));
    }

    public function test_matches_rule_with_or_operator_any_condition_passes(): void
    {
        $rule = [
            'operator' => 'OR',
            'conditions' => [
                ['field' => 'subcategory', 'operator' => 'equals', 'value' => 'MyLintasarta'],
                ['field' => 'subcategory', 'operator' => 'equals', 'value' => 'MyPMOIS'],
            ],
            'target_module' => 'MYLA',
        ];

        $row1 = ['subcategory' => 'MyLintasarta'];
        $row2 = ['subcategory' => 'MyPMOIS'];
        $row3 = ['subcategory' => 'Starla'];

        $this->assertTrue($this->evaluator->matchesRule($rule, $row1));
        $this->assertTrue($this->evaluator->matchesRule($rule, $row2));
        $this->assertFalse($this->evaluator->matchesRule($rule, $row3));
    }

    public function test_matches_legacy_rule_without_conditions_array(): void
    {
        $legacyRule = [
            'excel_field' => 'account',
            'excel_value' => 'Royal Safari Garden',
            'target_module' => 'EBESHA',
        ];

        $matchingRow = ['account' => 'Royal Safari Garden'];
        $nonMatchingRow = ['account' => 'BPD Riau'];

        $this->assertTrue($this->evaluator->matchesRule($legacyRule, $matchingRow));
        $this->assertFalse($this->evaluator->matchesRule($legacyRule, $nonMatchingRow));
    }

    public function test_user_specific_multi_rule_routing_scenario(): void
    {
        $rule1 = [
            'source_format' => 'ebesha',
            'operator' => 'AND',
            'conditions' => [
                ['field' => 'account', 'operator' => 'equals', 'value' => 'abc'],
                ['field' => 'service_category', 'operator' => 'equals', 'value' => 'server'],
            ],
            'target_module' => 'Ultima & Starlink',
        ];

        $rule2 = [
            'source_format' => 'ebesha',
            'operator' => 'AND',
            'conditions' => [
                ['field' => 'account', 'operator' => 'equals', 'value' => 'abc'],
                ['field' => 'service_category', 'operator' => 'equals', 'value' => 'db'],
            ],
            'target_module' => 'Infra - Ultima/Starlink LA',
        ];

        $rowServer = [
            'account' => 'abc',
            'service_category' => 'server',
        ];

        $rowDb = [
            'account' => 'abc',
            'service_category' => 'db',
        ];

        $this->assertTrue($this->evaluator->matchesRule($rule1, $rowServer));
        $this->assertFalse($this->evaluator->matchesRule($rule2, $rowServer));

        $this->assertFalse($this->evaluator->matchesRule($rule1, $rowDb));
        $this->assertTrue($this->evaluator->matchesRule($rule2, $rowDb));
    }
}
