<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Plugin\InventoryApi;

use Hryvinskyi\AdminActionLogInventory\Model\SourceStockTracker;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;

/**
 * Records the quantity and status a save of source items changed, once the save has returned
 *
 * Declared on the interface, so it also runs for the save the legacy stock item's synchronisation uses.
 */
class RecordSourceItemsSave
{
    /**
     * @param SourceStockTracker $tracker
     */
    public function __construct(
        private readonly SourceStockTracker $tracker
    ) {
    }

    /**
     * Save, and record what the save changed
     *
     * @param SourceItemsSaveInterface $subject
     * @param callable $proceed
     * @phpstan-param callable(array<mixed>): void $proceed
     * @param array<mixed> $sourceItems
     * @return void
     */
    public function aroundExecute(SourceItemsSaveInterface $subject, callable $proceed, array $sourceItems): void
    {
        $this->tracker->forItems($sourceItems, static function () use ($proceed, $sourceItems): bool {
            $proceed($sourceItems);

            return true;
        });
    }
}
