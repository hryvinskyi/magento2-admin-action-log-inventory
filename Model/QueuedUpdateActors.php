<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Model;

use Hryvinskyi\ActivityLog\Api\Data\ActorInterface;
use Hryvinskyi\ActivityLog\Api\Data\ActorInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Data\ActorType;
use Magento\Framework\App\ResourceConnection;
use Magento\InventoryCatalog\Model\UpdateInventory\InventoryData;

/**
 * Who asked for each queued stock update of products' stock in bulk, kept until a consumer carries the update out
 *
 * The update's message holds only the products and the stock data, so the account that asked for it is kept beside
 * the queue under a hash of exactly those, and taken back by the consumer that receives the same message. Two equal
 * updates queued by different accounts are carried out for them in the order they were queued. Only accounts are
 * kept: a process that queues an update would be the consumer's own actor anyway. A kept row nobody takes (a
 * message lost by the queue) is removed after a week.
 */
class QueuedUpdateActors
{
    public const TABLE = 'hryvinskyi_admin_action_log_inventory_queued';
    private const KEEP_DAYS = 7;

    /**
     * @param ResourceConnection $resourceConnection
     * @param ActorInterfaceFactory $actorFactory
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ActorInterfaceFactory $actorFactory
    ) {
    }

    /**
     * Keep the account asking for an update that is about to be queued
     *
     * @param InventoryData $update
     * @param ActorInterface $actor
     * @return void
     */
    public function remember(InventoryData $update, ActorInterface $actor): void
    {
        $id = $actor->getId();
        if ($id === null || $id === '') {
            return;
        }

        $this->resourceConnection->getConnection()->insert($this->table(), [
            'message_key' => $this->keyOf($update),
            'actor_type' => $actor->getType()->value,
            'actor_id' => mb_substr($id, 0, 64),
            'actor_name' => mb_substr($actor->getName(), 0, 255),
        ]);
    }

    /**
     * Take back the account that asked for an update a consumer received, or null when none was kept
     *
     * @param InventoryData $update
     * @return ActorInterface|null
     */
    public function take(InventoryData $update): ?ActorInterface
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->delete($this->table(), [
            'created_at < ?' => gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400),
        ]);

        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->table())
                ->where('message_key = ?', $this->keyOf($update))
                ->order('entity_id ASC')
                ->limit(1)
        );
        if (!is_array($row) || !is_numeric($row['entity_id'] ?? null)) {
            return null;
        }
        $connection->delete($this->table(), ['entity_id = ?' => (int) $row['entity_id']]);

        $type = ActorType::tryFrom(is_string($row['actor_type'] ?? null) ? $row['actor_type'] : '');
        $name = $row['actor_name'] ?? null;
        $id = $row['actor_id'] ?? null;

        return $type === null || !is_string($name)
            ? null
            : $this->actorFactory->create(['type' => $type, 'name' => $name, 'id' => is_string($id) ? $id : null]);
    }

    /**
     * What tells one update's message from another: its products and its stock data
     *
     * @param InventoryData $update
     * @return string
     */
    private function keyOf(InventoryData $update): string
    {
        return hash('sha256', json_encode([$update->getSkus(), $update->getData()], JSON_THROW_ON_ERROR));
    }

    /**
     * The table the requesters are kept in
     *
     * @return string
     */
    private function table(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }
}
