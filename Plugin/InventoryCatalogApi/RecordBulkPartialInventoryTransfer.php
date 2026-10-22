<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Plugin\InventoryCatalogApi;

use Hryvinskyi\AdminActionLogInventory\Model\SourceStockTracker;
use Magento\InventoryCatalogApi\Api\BulkPartialInventoryTransferInterface;
use Magento\InventoryCatalogApi\Api\Data\PartialInventoryTransferItemInterface;

/**
 * Records the quantities a partial transfer moved between two sources, in both of them, once it has returned
 *
 * The transfer writes its rows directly, past the source item save, so it is followed on its own.
 */
class RecordBulkPartialInventoryTransfer
{
    /**
     * @param SourceStockTracker $tracker
     */
    public function __construct(
        private readonly SourceStockTracker $tracker
    ) {
    }

    /**
     * Transfer, and record what the transfer changed
     *
     * @param BulkPartialInventoryTransferInterface $subject
     * @param callable $proceed
     * @phpstan-param callable(string, string, array<mixed>): void $proceed
     * @param string $originSourceCode
     * @param string $destinationSourceCode
     * @param array<mixed> $items
     * @return void
     */
    public function aroundExecute(
        BulkPartialInventoryTransferInterface $subject,
        callable $proceed,
        string $originSourceCode,
        string $destinationSourceCode,
        array $items
    ): void {
        $skus = [];
        foreach ($items as $item) {
            if ($item instanceof PartialInventoryTransferItemInterface) {
                $skus[] = $item->getSku();
            }
        }

        $this->tracker->forProducts(
            $skus,
            [$originSourceCode, $destinationSourceCode],
            static function () use ($proceed, $originSourceCode, $destinationSourceCode, $items): bool {
                $proceed($originSourceCode, $destinationSourceCode, $items);

                return true;
            }
        );
    }
}
