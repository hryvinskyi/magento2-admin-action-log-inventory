<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Plugin\MessageQueue;

use Hryvinskyi\ActivityLog\Api\ActorContextInterface;
use Hryvinskyi\AdminActionLogInventory\Model\QueuedUpdateActors;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\InventoryCatalog\Model\UpdateInventory\InventoryData;
use Psr\Log\LoggerInterface;

/**
 * Keeps who asked for a bulk stock update ("Update Attributes" of the product grid) as it is queued
 *
 * Every message published passes here; only the stock update topic is kept. Keeping it never stops the message.
 */
class RememberStockUpdateRequester
{
    private const TOPIC = 'inventory.mass.update';

    /**
     * @param QueuedUpdateActors $queuedActors
     * @param ActorContextInterface $actorContext
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly QueuedUpdateActors $queuedActors,
        private readonly ActorContextInterface $actorContext,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Keep the account asking for a stock update before its message is queued
     *
     * @param PublisherInterface $subject
     * @param string $topicName
     * @param mixed $data
     * @return null
     */
    public function beforePublish(PublisherInterface $subject, $topicName, $data)
    {
        if ($topicName !== self::TOPIC || !$data instanceof InventoryData) {
            return null;
        }

        try {
            $this->queuedActors->remember($data, $this->actorContext->current());
        } catch (\Throwable $exception) {
            $this->logger->error('Who asked for a queued stock update could not be kept.', ['exception' => $exception]);
        }

        return null;
    }
}
