<?php

namespace App\Services;

use App\Models\LcFile;
use App\Models\Purchase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves the Trade Portal's LC file form (docs/tasneem-samples/PLAN.md §3.2).
 * One form, three owners: the LC / PI / CI numbers and dates, ports, delivery
 * terms and country of origin are the purchase's (the ERP's PI / CI / PL use
 * them too); the supplier's address and contacts are the supplier's; the rest
 * is the LC file's. Each is written to its own record, so nothing is kept twice.
 */
class LcFileService
{
    /** The form fields that belong to the purchase. */
    public const PURCHASE_FIELDS = [
        'pi_number', 'pi_date', 'lc_number', 'lc_date', 'ci_number', 'ci_date',
        'delivery_terms', 'port_of_loading', 'port_of_discharge', 'country_of_origin',
    ];

    /** Form field => supplier column. */
    public const SUPPLIER_FIELDS = [
        'supplier_company_name' => 'company_name',
        'supplier_address' => 'address',
        'supplier_country' => 'country',
        'supplier_phone' => 'phone',
        'supplier_email' => 'email',
    ];

    /** @param  array<string, mixed>  $data */
    public function save(?LcFile $file, array $data): LcFile
    {
        return DB::transaction(function () use ($file, $data): LcFile {
            $purchase = $file?->purchase ?? Purchase::query()->find($data['purchase_id'] ?? null);

            if (! $purchase) {
                throw ValidationException::withMessages(['purchase_id' => __('Choose the purchase (PI) this LC is for.')]);
            }

            if (! $file && $purchase->lcFile()->exists()) {
                throw ValidationException::withMessages(['purchase_id' => __('This purchase already has an LC file.')]);
            }

            $purchase->update(array_map(fn ($value) => blank($value) ? null : $value, array_intersect_key($data, array_flip(self::PURCHASE_FIELDS))));

            if ($purchase->supplier) {
                $supplierData = [];

                foreach (self::SUPPLIER_FIELDS as $field => $column) {
                    if (array_key_exists($field, $data)) {
                        $supplierData[$column] = blank($data[$field]) ? null : $data[$field];
                    }
                }

                $purchase->supplier->update($supplierData);
            }

            $fileData = array_intersect_key($data, array_flip((new LcFile)->getFillable()));
            unset($fileData['company_id'], $fileData['purchase_id']);
            $fileData = array_map(fn ($value) => $value === '' ? null : $value, $fileData);

            $file ??= new LcFile(['purchase_id' => $purchase->getKey()]);
            $file->fill($fileData);
            $file->stage ??= array_key_first(LcFile::STAGES);
            $file->doc_status ??= array_key_first(LcFile::DOC_STATUSES);
            $file->save();

            return $file->refresh();
        });
    }

    /** Moves the file to its next stage; nothing after the last one. */
    public function nextStage(LcFile $file): void
    {
        $next = $file->nextStage();

        if ($next !== null) {
            $file->update(['stage' => $next]);
        }
    }

    /**
     * The form's values of an existing file: its own, its purchase's and its supplier's.
     *
     * @return array<string, mixed>
     */
    public function formData(LcFile $file): array
    {
        $purchase = $file->purchase;
        $data = [...$file->attributesToArray(), ...$purchase?->only(self::PURCHASE_FIELDS) ?? []];

        foreach (['pi_date', 'lc_date', 'ci_date'] as $date) {
            $data[$date] = $purchase?->{$date}?->toDateString();
        }

        foreach ($file->getCasts() as $column => $cast) {
            if ($cast === 'date') {
                $data[$column] = $file->{$column}?->toDateString();
            }
        }

        foreach (self::SUPPLIER_FIELDS as $field => $column) {
            $data[$field] = $purchase?->supplier?->{$column};
        }

        return $data;
    }
}
