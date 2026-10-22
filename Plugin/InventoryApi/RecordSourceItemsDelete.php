<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Plugin\InventoryApi;

use Hryvinskyi\AdminActionLogInventory\Model\SourceStockTracker;
use Magento\InventoryApi\Api\SourceItemsDeleteInterface;

/**
 * Records the products a deletion of source items unassigned from their sources, once the deletion has returned
 */
class RecordSourceItemsDelete
{
    /**
     * @param SourceStockTracker $tracker
     */
    public function __construct(
        private readonly SourceStockTracker $tracker
    ) {
    }

    /**
     * Delete, and record what the deletion changed
     *
     * @param SourceItemsDeleteInterface $subject
     * @param callable $proceed
     * @phpstan-param callable(array<mixed>): void $proceed
     * @param array<mixed> $sourceItems
     * @return void
     */
    public function aroundExecute(SourceItemsDeleteInterface $subject, callable $proceed, array $sourceItems): void
    {
        $this->tracker->forItems($sourceItems, static function () use ($proceed, $sourceItems): bool {
            $proceed($sourceItems);

            return true;
        });
    }
}
