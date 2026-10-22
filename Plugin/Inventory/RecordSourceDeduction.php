<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Plugin\Inventory;

use Hryvinskyi\AdminActionLogInventory\Model\SourceStockTracker;
use Magento\Inventory\Model\SourceItem\Command\DecrementSourceItemQty;

/**
 * Records the quantity a shipment deducted from each source, once the deduction has returned
 *
 * The inventory deducts shipped quantities with one query, past the source item save, so this is the only place the
 * deduction can be seen. It is recorded for whoever ships: the admin creating the shipment, an integration through
 * the web API, a process.
 */
class RecordSourceDeduction
{
    /**
     * @param SourceStockTracker $tracker
     */
    public function __construct(
        private readonly SourceStockTracker $tracker
    ) {
    }

    /**
     * Deduct, and record what the deduction changed
     *
     * @param DecrementSourceItemQty $subject
     * @param callable $proceed
     * @phpstan-param callable(array<mixed>): void $proceed
     * @param array<mixed> $sourceItemDecrementData Each with the source item ("source_item") and the quantity taken
     * @return void
     */
    public function aroundExecute(
        DecrementSourceItemQty $subject,
        callable $proceed,
        array $sourceItemDecrementData
    ): void {
        $sourceItems = [];
        foreach ($sourceItemDecrementData as $decrement) {
            $sourceItems[] = is_array($decrement) ? ($decrement['source_item'] ?? null) : null;
        }

        $this->tracker->forItems($sourceItems, static function () use ($proceed, $sourceItemDecrementData): bool {
            $proceed($sourceItemDecrementData);

            return true;
        });
    }
}
