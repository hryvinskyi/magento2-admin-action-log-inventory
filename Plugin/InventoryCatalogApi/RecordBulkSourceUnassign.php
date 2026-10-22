<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Plugin\InventoryCatalogApi;

use Hryvinskyi\AdminActionLogInventory\Model\SourceStockTracker;
use Magento\InventoryCatalogApi\Api\BulkSourceUnassignInterface;

/**
 * Records the products a bulk unassignment took out of sources, once it has returned
 *
 * The unassignment deletes its rows directly, past the source item deletion, so it is followed on its own.
 */
class RecordBulkSourceUnassign
{
    /**
     * @param SourceStockTracker $tracker
     */
    public function __construct(
        private readonly SourceStockTracker $tracker
    ) {
    }

    /**
     * Unassign, and record what the unassignment changed
     *
     * @param BulkSourceUnassignInterface $subject
     * @param callable $proceed
     * @phpstan-param callable(array<mixed>, array<mixed>): int $proceed
     * @param array<mixed> $skus
     * @param array<mixed> $sourceCodes
     * @return int
     */
    public function aroundExecute(
        BulkSourceUnassignInterface $subject,
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
