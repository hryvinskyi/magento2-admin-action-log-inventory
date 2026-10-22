<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Model;

use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;

/**
 * The stored quantity and status of products in sources, read through the inventory's own repository
 *
 * A product in a source is a pair of its SKU and the source's code. Each stored pair is keyed by key(); a pair that is
 * not stored (the product is not assigned to the source) is left out. A quantity reads as the decimal the inventory
 * stores ("95.0000"), a status as "1" (in stock) or "0" (out of stock).
 *
 * @phpstan-type Pair array{sku: string, source: string}
 * @phpstan-type StoredPair array{sku: string, source: string, quantity: string|null, status: string|null}
 */
class SourceItemState
{
    private const QUANTITY_FORMAT = '%.4F';

    /**
     * @param SourceItemRepositoryInterface $sourceItems
     * @param SearchCriteriaBuilderFactory $criteriaBuilders
     */
    public function __construct(
        private readonly SourceItemRepositoryInterface $sourceItems,
        private readonly SearchCriteriaBuilderFactory $criteriaBuilders
    ) {
    }

    /**
     * The pairs as stored now, keyed by key()
     *
     * @param list<Pair> $pairs
     * @return array<string, StoredPair>
     */
    public function read(array $pairs): array
    {
        $wanted = [];
        foreach ($pairs as $pair) {
            $wanted[$this->key($pair['sku'], $pair['source'])] = true;
        }

        if ($wanted === []) {
            return [];
        }

        $criteria = $this->criteriaBuilders->create()
            ->addFilter(SourceItemInterface::SKU, array_values(array_unique(array_column($pairs, 'sku'))), 'in')
            ->addFilter(
                SourceItemInterface::SOURCE_CODE,
                array_values(array_unique(array_column($pairs, 'source'))),
                'in'
            )
            ->create();

        $stored = [];
        foreach ($this->sourceItems->getList($criteria)->getItems() as $item) {
            $sku = $item->getSku();
            $source = $item->getSourceCode();
            if ($sku === null || $source === null || !isset($wanted[$this->key($sku, $source)])) {
                continue;
            }

            $stored[$this->key($sku, $source)] = [
                'sku' => $sku,
                'source' => $source,
                'quantity' => $this->quantity($item->getQuantity()),
                'status' => $item->getStatus() === null ? null : (string) $item->getStatus(),
            ];
        }

        return $stored;
    }

    /**
     * What tells a product in a source from any other
     *
     * @param string $sku
     * @param string $source
     * @return string
     */
    public function key(string $sku, string $source): string
    {
        return $source . "\0" . $sku;
    }

    /**
     * A quantity as the decimal the inventory stores, or null for none
     *
     * @param float|null $quantity
     * @return string|null
     */
    private function quantity(?float $quantity): ?string
    {
        return $quantity === null ? null : sprintf(self::QUANTITY_FORMAT, $quantity);
    }
}
