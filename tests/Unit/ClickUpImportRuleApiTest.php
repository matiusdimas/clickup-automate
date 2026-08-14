<?php

namespace Tests\Unit;

use App\Models\ClickUpImportRule;
use PHPUnit\Framework\TestCase;

class ClickUpImportRuleApiTest extends TestCase
{
    public function test_import_rule_model_fillable_and_casts(): void
    {
        $rule = new ClickUpImportRule([
            'excel_field' => 'account',
            'excel_value' => 'Royal Safari Garden',
            'target_module' => 'EBESHA',
            'source_format' => 'ebesha',
            'operator' => 'AND',
            'conditions' => [
                ['field' => 'account', 'operator' => 'equals', 'value' => 'Royal Safari Garden'],
                ['field' => 'contact', 'operator' => 'contains', 'value' => 'CMMS'],
            ],
        ]);

        $this->assertEquals('account', $rule->excel_field);
        $this->assertEquals('Royal Safari Garden', $rule->excel_value);
        $this->assertEquals('EBESHA', $rule->target_module);
        $this->assertEquals('ebesha', $rule->source_format);
        $this->assertEquals('AND', $rule->operator);
        $this->assertIsArray($rule->conditions);
        $this->assertCount(2, $rule->conditions);
    }
}
