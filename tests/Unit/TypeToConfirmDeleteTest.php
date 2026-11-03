<?php

namespace Tests\Unit;

use App\Support\TypeToConfirmDelete;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use PHPUnit\Framework\TestCase;

/**
 * The matching rules and the name shown by the "type the name to delete" modal
 * (CLAUDE.md "Delete = type the name, then Trash"). Pure logic: no app boot.
 */
class TypeToConfirmDeleteTest extends TestCase
{
    private function record(array $attributes, int $key = 7): Model
    {
        $model = new class extends Model
        {
            protected $guarded = [];
        };
        $model->forceFill($attributes);
        $model->setAttribute('id', $key);

        return $model;
    }

    public function test_the_name_must_be_typed_exactly(): void
    {
        $this->assertTrue(TypeToConfirmDelete::matches('Blue Cap', 'Blue Cap'));
        $this->assertFalse(TypeToConfirmDelete::matches('blue cap', 'Blue Cap'), 'case matters');
        $this->assertFalse(TypeToConfirmDelete::matches('Blue', 'Blue Cap'));
        $this->assertFalse(TypeToConfirmDelete::matches('', 'Blue Cap'));
    }

    public function test_spaces_at_the_ends_and_doubled_spaces_do_not_matter(): void
    {
        $this->assertTrue(TypeToConfirmDelete::matches('  Blue   Cap ', 'Blue Cap'));
        $this->assertTrue(TypeToConfirmDelete::matches("Blue\u{00A0}Cap", 'Blue Cap'));
    }

    public function test_a_pasted_name_in_another_unicode_form_still_matches(): void
    {
        if (! class_exists(\Normalizer::class)) {
            $this->markTestSkipped('ext-intl is not loaded.');
        }

        // "é" as one character versus "e" + a combining accent.
        $this->assertTrue(TypeToConfirmDelete::matches("Cafe\u{0301}", "Caf\u{00E9}"));
    }

    public function test_the_resource_record_title_is_the_name_when_it_is_a_real_title(): void
    {
        $name = TypeToConfirmDelete::recordName($this->record(['name' => 'ignored']), 'Order INV-1001', ['expense', 'Expense']);

        $this->assertSame('Order INV-1001', $name);
    }

    public function test_a_title_that_is_only_the_model_label_is_replaced_by_a_name_like_field(): void
    {
        $record = $this->record(['voucher_number' => 'VCH-0042', 'amount' => 500]);

        $this->assertSame('VCH-0042', TypeToConfirmDelete::recordName($record, 'Voucher', ['voucher', 'Voucher']));
        $this->assertSame('VCH-0042', TypeToConfirmDelete::recordName($record, null, ['voucher', 'Voucher']));
    }

    public function test_name_like_fields_are_tried_in_order(): void
    {
        $this->assertSame('Shop', TypeToConfirmDelete::recordName($this->record(['title' => 'Shop', 'code' => 'X1']), null, ['thing']));
        $this->assertSame('X1', TypeToConfirmDelete::recordName($this->record(['title' => '  ', 'code' => 'X1']), null, ['thing']));
    }

    public function test_a_record_with_no_name_falls_back_to_label_and_id(): void
    {
        $this->assertSame('Expense #7', TypeToConfirmDelete::recordName($this->record(['amount' => 5]), null, ['expense', 'Expense']));
        $this->assertSame('expense #7', TypeToConfirmDelete::recordName($this->record(['amount' => 5]), 'expense', ['expense']));
    }

    public function test_array_attributes_are_not_used_as_a_name(): void
    {
        $this->assertSame('thing #7', TypeToConfirmDelete::recordName($this->record(['name' => ['en' => 'A']]), null, ['thing']));
    }

    public function test_it_knows_which_models_go_to_trash(): void
    {
        $soft = new class extends Model
        {
            use SoftDeletes;
        };
        $hard = new class extends Model {};

        $this->assertTrue(TypeToConfirmDelete::usesSoftDeletes($soft));
        $this->assertTrue(TypeToConfirmDelete::usesSoftDeletes($soft::class));
        $this->assertFalse(TypeToConfirmDelete::usesSoftDeletes($hard));
    }
}
