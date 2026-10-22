<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Plugin\InventoryCatalogApi;

use Hryvinskyi\AdminActionLogInventory\Model\SourceStockTracker;
use Magento\InventoryCatalogApi\Api\BulkInventoryTransferInterface;

/**
 * Records the stock a bulk transfer moved between two sources, in both of them, once it has returned
 *
 * The transfer writes its rows directly, past the source item save, so it is followed on its own.
 */
class RecordBulkInventoryTransfer
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
     * @param BulkInventoryTransferInterface $subject
     * @param callable $proceed
     * @phpstan-param callable(array<mixed>, string, string, bool): bool $proceed
     * @param array<mixed> $skus
     * @param string $originSource
     * @param string $destinationSource
     * @param bool $unassignFromOrigin
     * @return bool
     */
    public function aroundExecute(
        BulkInventoryTransferInterface $subject,
        callable $proceed,
        array $skus,
        string $originSource,
        string $destinationSource,
        bool $unassignFromOrigin
    ): bool {
        return $this->tracker->forProducts(
            $skus,
            [$originSource, $destinationSource],
            static fn (): bool => $proceed($skus, $originSource, $destinationSource, $unassignFromOrigin)
        );
    }
}
