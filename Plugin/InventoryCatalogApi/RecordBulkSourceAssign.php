<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Plugin\InventoryCatalogApi;

use Hryvinskyi\AdminActionLogInventory\Model\SourceStockTracker;
use Magento\InventoryCatalogApi\Api\BulkSourceAssignInterface;

/**
 * Records the products a bulk assignment put into sources, once it has returned
 *
 * The assignment writes its rows directly, past the source item save, so it is followed on its own.
 */
class RecordBulkSourceAssign
{
    /**
     * @param SourceStockTracker $tracker
     */
    public function __construct(
        private readonly SourceStockTracker $tracker
    ) {
    }

    /**
     * Assign, and record what the assignment changed
     *
     * @param BulkSourceAssignInterface $subject
     * @param callable $proceed
     * @phpstan-param callable(array<mixed>, array<mixed>): int $proceed
     * @param array<mixed> $skus
     * @param array<mixed> $sourceCodes
     * @return int
     */
    public function aroundExecute(
        BulkSourceAssignInterface $subject,
        callable $proceed,
        array $skus,
        array $sourceCodes
    ): int {
        return $this->tracker->forProducts(
            $skus,
            $sourceCodes,
            static fn (): int => $proceed($skus, $sourceCodes)
        );
    }
}
