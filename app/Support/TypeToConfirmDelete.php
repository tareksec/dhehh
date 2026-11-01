<?php

namespace App\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;

/**
 * "Type the name to delete" (owner request 2026-11-01).
 *
 * Pressing Delete opens a modal that shows the record's name with a copy button
 * and an input. Delete only goes through when the name is typed (or pasted)
 * exactly, so a stray click can never remove the wrong record. A bulk delete
 * asks for the number of selected records instead.
 *
 * It is wired once, for every Filament delete action in every panel
 * (`register()` is called from AppServiceProvider::boot()), so new work gets it
 * without extra code. A hand-made delete action (for example a "Move to trash"
 * action) calls `apply()` or `applyToSelection()` itself.
 *
 * What happens next depends on the model: a SoftDeletes model goes to Trash and
 * can be restored or deleted permanently from its Trash page (see
 * App\Filament\Pages\TrashedRecords); any other model is deleted for good and
 * the modal says so.
 */
final class TypeToConfirmDelete
{
    public const FIELD = 'confirmation';

    /** Attributes tried, in order, when the resource has no record title of its own. */
    private const NAME_ATTRIBUTES = [
        'name', 'title', 'label', 'subject',
        'order_number', 'voucher_number', 'expense_number', 'invoice_number', 'number',
        'code', 'sku', 'slug', 'email', 'phone',
    ];

    /** Wire the confirmation into every Filament delete action. */
    public static function register(): void
    {
        foreach ([DeleteAction::class, ForceDeleteAction::class] as $class) {
            $class::configureUsing(fn (Action $action): Action => self::apply($action));
        }

        foreach ([DeleteBulkAction::class, ForceDeleteBulkAction::class] as $class) {
            $class::configureUsing(fn (BulkAction $action): BulkAction => self::applyToSelection($action));
        }
    }

    /**
     * Make a single-record action ask for the record's name.
     *
     * @param  bool|null  $permanent  true = the action deletes for good (Trash page, force delete); null = decided by the model
     */
    public static function apply(Action $action, ?bool $permanent = null): Action
    {
        return $action
            ->modalDescription(fn (Action $action): string => self::describe($action->getRecord(), self::isPermanent($action, $permanent)))
            ->schema(fn (Action $action): array => self::fields(self::nameOf($action), isCount: false));
    }

    /** Make a bulk action ask for the number of selected records. */
    public static function applyToSelection(BulkAction $action, ?bool $permanent = null): BulkAction
    {
        return $action
            ->modalDescription(fn (BulkAction $action): string => self::describeSelection(self::isPermanent($action, $permanent)))
            ->schema(fn (BulkAction $action): array => self::fields((string) self::selectedCount($action), isCount: true));
    }

    /**
     * The name shown in the modal. The resource's own record title when it has
     * one; otherwise the first filled name-like attribute; otherwise "Label #id".
     *
     * @param  list<string|null>  $modelLabels  the model's label(s): a title equal to one of them is only the fallback label, not a name
     */
    public static function recordName(Model $record, ?string $title = null, array $modelLabels = []): string
    {
        $title = trim((string) $title);
        $labels = array_map(fn (?string $label): string => mb_strtolower(trim((string) $label)), $modelLabels);

        if ($title !== '' && ! in_array(mb_strtolower($title), $labels, true)) {
            return $title;
        }

        foreach (self::NAME_ATTRIBUTES as $attribute) {
            $value = $record->getAttribute($attribute);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        $label = trim((string) Arr::last(array_filter($modelLabels, fn (?string $l): bool => filled($l)))) ?: class_basename($record);

        return $label.' #'.$record->getKey();
    }

    /** Does what was typed equal the name? Case matters; spacing at the ends or doubled does not. */
    public static function matches(string $typed, string $expected): bool
    {
        return self::normalise($typed) === self::normalise($expected);
    }

    /** Does what was typed equal the number? Digits of any script (Bangla included) count. */
    public static function matchesNumber(string $typed, int $expected): bool
    {
        return self::normalise($typed, digits: true) === (string) $expected;
    }

    public static function usesSoftDeletes(Model|string $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    /** The panel's Trash page for this model, if its resource has one. */
    public static function trashUrl(Model|string $model): ?string
    {
        $resource = Filament::getModelResource($model);

        if (! is_string($resource) || ! $resource::hasPage('trash')) {
            return null;
        }

        return $resource::getUrl('trash');
    }

    /**
     * The modal fields for a hand-made action that deletes many things at once
     * (the number to type is the count it will delete).
     *
     * @return list<Component>
     */
    public static function countFields(int $count): array
    {
        return self::fields((string) $count, isCount: true);
    }

    /** @return list<Component> */
    private static function fields(string $expected, bool $isCount): array
    {
        return [
            Text::make($expected)
                ->badge()
                ->color('danger')
                ->copyable()
                ->copyMessage(__('Copied'))
                ->icon(Heroicon::OutlinedClipboardDocument)
                ->iconPosition(IconPosition::After)
                ->tooltip(__('Click to copy')),
            TextInput::make(self::FIELD)
                ->label($isCount ? __('Type the number to confirm') : __('Type the name to confirm'))
                ->infoTip($isCount
                    ? __('Copy the number shown above and paste it here, or type it. Delete stays locked until it matches. This stops a delete by mistake.')
                    : __('Copy the name shown above and paste it here, or type it exactly. Delete stays locked until it matches. This stops the wrong record being deleted by mistake.'))
                ->required()
                ->autocomplete(false)
                ->rules([
                    fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($expected, $isCount): void {
                        $ok = $isCount
                            ? self::matchesNumber((string) $value, (int) $expected)
                            : self::matches((string) $value, $expected);

                        if (! $ok) {
                            $fail($isCount
                                ? __('This is not the number shown above.')
                                : __('This does not match the name shown above. Copy it and paste it here.'));
                        }
                    },
                ]),
        ];
    }

    private static function describe(mixed $record, bool $permanent): string
    {
        if ($permanent) {
            return __('This will be deleted permanently. It cannot be restored.');
        }

        return $record instanceof Model && self::trashUrl($record)
            ? __('This will be moved to Trash. You can restore it, or delete it permanently, from the Trash page.')
            : __('This will be removed from the list.');
    }

    private static function describeSelection(bool $permanent): string
    {
        return $permanent
            ? __('The selected records will be deleted permanently. They cannot be restored.')
            : __('The selected records will be moved to Trash. You can restore them, or delete them permanently, from the Trash page.');
    }

    private static function nameOf(Action $action): string
    {
        $record = $action->getRecord();

        if (! $record instanceof Model) {
            return '';
        }

        return self::recordName($record, $action->getRecordTitle($record), [$action->getModelLabel(), $action->getTitleCaseModelLabel()]);
    }

    private static function selectedCount(BulkAction $action): int
    {
        return $action->getLivewire()->getSelectedTableRecordsQuery(shouldFetchSelectedRecords: false)->count();
    }

    /** Is this delete final? Decided by the caller, else by the action type, else by whether the model has a Trash. */
    private static function isPermanent(Action $action, ?bool $decided): bool
    {
        if ($decided !== null) {
            return $decided;
        }

        if ($action instanceof ForceDeleteAction || $action instanceof ForceDeleteBulkAction) {
            return true;
        }

        $model = $action instanceof BulkAction ? $action->getModel() : $action->getRecord();

        return ! (($model instanceof Model || is_string($model)) && self::usesSoftDeletes($model));
    }

    private static function normalise(string $value, bool $digits = false): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? $value;

        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }

        if ($digits && class_exists(\IntlChar::class)) {
            $value = preg_replace_callback('/\p{Nd}/u', fn (array $m): string => (string) \IntlChar::charDigitValue($m[0]), $value) ?? $value;
        }

        return $value;
    }
}
