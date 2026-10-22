<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Plugin\InventoryCatalog;

use Hryvinskyi\ActivityLog\Api\ActorScopeInterface;
use Hryvinskyi\AdminActionLogInventory\Model\QueuedUpdateActors;
use Magento\InventoryCatalog\Model\UpdateInventory;
use Magento\InventoryCatalog\Model\UpdateInventory\InventoryData;
use Psr\Log\LoggerInterface;

/**
 * A bulk stock update the consumer carries out is recorded for the account that asked for it, not for the consumer
 *
 * An update whose requester was not kept runs as it would without this; taking it never fails the update.
 */
class ActAsStockUpdateRequester
{
    /**
     * @param QueuedUpdateActors $queuedActors
     * @param ActorScopeInterface $actorScope
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly QueuedUpdateActors $queuedActors,
        private readonly ActorScopeInterface $actorScope,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Carry out the update for the account that asked for it
     *
     * @param UpdateInventory $subject
     * @param callable $proceed
     * @phpstan-param callable(InventoryData): void $proceed
     * @param InventoryData $data
     * @return void
     */
    public function aroundExecute(UpdateInventory $subject, callable $proceed, InventoryData $data): void
    {
        try {
            $requester = $this->queuedActors->take($data);
        } catch (\Throwable $exception) {
            $this->logger->error('Who asked for a queued stock update could not be told.', ['exception' => $exception]);
            $requester = null;
        }

        if ($requester === null) {
            $proceed($data);

            return;
        }

        $this->actorScope->actAs($requester, static function () use ($proceed, $data): void {
            $proceed($data);
        });
    }
}
