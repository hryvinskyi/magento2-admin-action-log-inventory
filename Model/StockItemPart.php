<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Model;

use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityPartInterface;
use Magento\Framework\Model\AbstractModel;

/**
 * A product's stock item, recorded as the part it decorates, without the values the product's sources record
 *
 * For a product that keeps its stock in sources, the quantity and status of each source are recorded as changes of
 * the source item, and the stock item only copies those of the default source: the "sourceFields" are left out of
 * its changes, so a change is never recorded twice. Every other field (the Advanced Inventory settings) is still
 * recorded from the stock item, and so is the whole stock item of a product that keeps no stock in sources (a
 * configurable or bundle product's stock status).
 */
class StockItemPart implements TrackedEntityPartInterface
{
    /**
     * @var array<string, true>
     */
    private readonly array $sourceFields;

    /**
     * @param TrackedEntityPartInterface $part The part decorated
     * @param SourceManagedProducts $products
     * @param array<string> $sourceFields Fields of the stock item its product's sources record
     */
    public function __construct(
        private readonly TrackedEntityPartInterface $part,
        private readonly SourceManagedProducts $products,
        array $sourceFields = []
    ) {
        $this->sourceFields = array_fill_keys(array_values($sourceFields), true);
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return $this->part->getCode();
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return $this->part->getLabel();
    }

    /**
     * @inheritDoc
     */
    public function getModelClass(): string
    {
        return $this->part->getModelClass();
    }

    /**
     * @inheritDoc
     */
    public function stateAfter(AbstractModel $model): array
    {
        return $this->part->stateAfter($model);
    }

    /**
     * @inheritDoc
     */
    public function supports(AbstractModel $model): bool
    {
        return $this->part->supports($model);
    }

    /**
     * @inheritDoc
     */
    public function stateBefore(AbstractModel $model): ?array
    {
        return $this->part->stateBefore($model);
    }

    /**
     * @inheritDoc
     */
    public function fields(AbstractModel $model, array $data): array
    {
        $fields = $this->part->fields($model, $data);
        $productId = $this->part->entityId($model);
        if ($productId === null || !$this->products->keepsStockInSources($productId)) {
            return $fields;
        }

        return array_diff_key($fields, $this->sourceFields);
    }

    /**
     * @inheritDoc
     */
    public function scope(AbstractModel $model): EntityScopeInterface
    {
        return $this->part->scope($model);
    }

    /**
     * @inheritDoc
     */
    public function isSecretField(AbstractModel $model, string $field): bool
    {
        return $this->part->isSecretField($model, $field);
    }

    /**
     * @inheritDoc
     */
    public function describe(AbstractModel $model): string
    {
        return $this->part->describe($model);
    }

    /**
     * @inheritDoc
     */
    public function entityId(AbstractModel $model): ?string
    {
        return $this->part->entityId($model);
    }
}
