<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Model;

use Hryvinskyi\AdminActionLog\Api\Data\EntityChangeInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityChangeInterfaceFactory;
use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;
use Hryvinskyi\AdminActionLog\Api\EntityChangeRecorderInterface;
use Psr\Log\LoggerInterface;

/**
 * Records how the stock of products in sources changed, as changes of the products
 *
 * Each product in a source whose quantity or status changed is one change of the product, in the source's scope
 * (named after the source), with the fields "quantity" and "source_status" before and after. A product newly
 * assigned to a source has its fields set from nothing; a product unassigned from one has them emptied. The product
 * itself is neither created nor deleted, so each is recorded as an update. A product that no longer exists records
 * nothing: its deletion is recorded already.
 *
 * The changes of one write are recorded together, so the products they name are read at once. Each product in a
 * source is turned into its change on its own: one that cannot be (its source's name unreadable, a value refused) is
 * logged and left out, and the rest of the write is still recorded.
 *
 * @phpstan-import-type StoredPair from SourceItemState
 */
class SourceStockChanges
{
    public const ENTITY_CODE = 'product';
    public const QUANTITY_FIELD = 'quantity';
    public const STATUS_FIELD = 'source_status';

    /**
     * @param EntityChangeRecorderInterface $recorder
     * @param EntityChangeInterfaceFactory $changeFactory
     * @param ProductIds $productIds
     * @param SourceNames $sourceNames
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly EntityChangeRecorderInterface $recorder,
        private readonly EntityChangeInterfaceFactory $changeFactory,
        private readonly ProductIds $productIds,
        private readonly SourceNames $sourceNames,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Record every product in a source whose stock differs between the two states
     *
     * @param array<string,StoredPair> $before Keyed by SourceItemState::key()
     * @param array<string,StoredPair> $after Keyed by SourceItemState::key()
     * @return void
     */
    public function record(array $before, array $after): void
    {
        $changed = [];
        foreach ($before + $after as $key => $pair) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ($old === null
                || $new === null
                || $old['quantity'] !== $new['quantity']
                || $old['status'] !== $new['status']
            ) {
                $changed[$key] = $pair;
            }
        }

        if ($changed === []) {
            return;
        }

        $ids = $this->productIds->of(array_column($changed, 'sku'));
        $changes = [];
        foreach ($changed as $key => $pair) {
            $id = $ids[$pair['sku']] ?? null;
            if ($id === null) {
                continue;
            }

            try {
                $changes[] = $this->change($id, $pair['source'], $before[$key] ?? null, $after[$key] ?? null);
            } catch (\Throwable $exception) {
                $this->logger->error(
                    sprintf('The stock of product %s in source %s could not be recorded.', $id, $pair['source']),
                    ['exception' => $exception]
                );
            }
        }

        if ($changes !== []) {
            $this->recorder->recordAll($changes);
        }
    }

    /**
     * The change of one product in one source
     *
     * @param string $productId
     * @param string $source
     * @param StoredPair|null $before
     * @param StoredPair|null $after
     * @return EntityChangeInterface
     */
    private function change(string $productId, string $source, ?array $before, ?array $after): EntityChangeInterface
    {
        return $this->changeFactory->create()
            ->withEntity(self::ENTITY_CODE, $productId)
            ->withScope(EntityScopeInterface::TYPE_GLOBAL, 0, $this->sourceNames->nameOf($source))
            ->withField(self::QUANTITY_FIELD, $before['quantity'] ?? null, $after['quantity'] ?? null)
            ->withField(self::STATUS_FIELD, $before['status'] ?? null, $after['status'] ?? null);
    }
}
