<?php

namespace App\Reports;

use App\Models\Supplier;
use Carbon\CarbonInterface;

/**
 * One supplier's account as of a date: the product-wise table and the chain
 * that ends in what we still owe him. The screen, CSV, XLSX and print all
 * read this one object, so they can never disagree.
 *
 * Balance = value of goods received
 *         - goods he took from our stock (pieces x his buy price)
 *         - customer advance he collected
 *         - (cash + shipping + ad cost we gave)
 */
final class SupplierStatement
{
    /**
     * @param  array<int, array<string, mixed>>  $products  one row per product, keys as in SupplierAccountService
     * @param  array<string, float|int>  $productTotals  the same keys, summed
     */
    public function __construct(
        public readonly Supplier $supplier,
        public readonly CarbonInterface $asOf,
        public readonly array $products,
        public readonly array $productTotals,
        public readonly float $valueReceived,
        public readonly float $goodsTaken,
        public readonly float $customerAdvance,
        public readonly float $cash,
        public readonly float $shipping,
        public readonly float $adCost,
        public readonly int $productsWithoutBuyPrice,
        public readonly int $productsWithDifference,
    ) {}

    public function valueAfterDeductions(): float
    {
        return round($this->valueReceived - $this->goodsTaken - $this->customerAdvance, 2);
    }

    public function totalGiven(): float
    {
        return round($this->cash + $this->shipping + $this->adCost, 2);
    }

    /** Positive: we still owe the supplier. Negative: we have given more than the goods are worth. */
    public function balance(): float
    {
        return round($this->valueAfterDeductions() - $this->totalGiven(), 2);
    }

    /** False while a received product has no buy price: the value of goods is then too low. */
    public function isFinal(): bool
    {
        return $this->productsWithoutBuyPrice === 0;
    }

    /**
     * The account block, line by line.
     *
     * @return array<int, array{label: string, value: float, strong: bool, note: ?string}>
     */
    public function lines(): array
    {
        $balanceNote = match (true) {
            $this->balance() > 0 => __('We still owe the supplier'),
            $this->balance() < 0 => __('We have given more than the goods are worth'),
            default => __('Settled'),
        };

        if (! $this->isFinal()) {
            $balanceNote = __('NOT FINAL: buy price missing for :count products.', ['count' => $this->productsWithoutBuyPrice]).' '.$balanceNote;
        }

        return [
            ['label' => __('Value of goods received'), 'value' => $this->valueReceived, 'strong' => false, 'note' => null],
            ['label' => __('Less: goods the supplier took from our stock'), 'value' => $this->goodsTaken, 'strong' => false, 'note' => null],
            ['label' => __('Less: customer advance the supplier collected'), 'value' => $this->customerAdvance, 'strong' => false, 'note' => null],
            ['label' => __('Value after deductions'), 'value' => $this->valueAfterDeductions(), 'strong' => true, 'note' => null],
            ['label' => __('Cash we gave'), 'value' => $this->cash, 'strong' => false, 'note' => null],
            ['label' => __('Shipping we paid'), 'value' => $this->shipping, 'strong' => false, 'note' => null],
            ['label' => __('Ad cost we paid'), 'value' => $this->adCost, 'strong' => false, 'note' => null],
            ['label' => __('Total we gave'), 'value' => $this->totalGiven(), 'strong' => true, 'note' => null],
            ['label' => __('Supplier still gets from us'), 'value' => $this->balance(), 'strong' => true, 'note' => $balanceNote],
        ];
    }
}
