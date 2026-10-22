<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\InventoryCatalogApi\Model\GetProductTypesBySkusInterface;
use Magento\InventoryCatalogApi\Model\GetSkusByProductIdsInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;

/**
 * Whether a product keeps its stock in sources: the inventory manages source items for products of its type
 *
 * A simple product does, a configurable or bundle product does not (its stock follows its children). The answer is
 * kept for the rest of the request. A product that cannot be found keeps none.
 */
class SourceManagedProducts implements ResetAfterRequestInterface
{
    /**
     * @var array<int, bool>
     */
    private array $known = [];

    /**
     * @param GetSkusByProductIdsInterface $skusByProductIds
     * @param GetProductTypesBySkusInterface $productTypesBySkus
     * @param IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowed
     */
    public function __construct(
        private readonly GetSkusByProductIdsInterface $skusByProductIds,
        private readonly GetProductTypesBySkusInterface $productTypesBySkus,
        private readonly IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowed
    ) {
    }

    /**
     * Whether the product with the id keeps its stock in sources
     *
     * @param string $productId
     * @return bool
     */
    public function keepsStockInSources(string $productId): bool
    {
        if (!ctype_digit($productId)) {
            return false;
        }

        $id = (int) $productId;

        return $this->known[$id] ??= $this->lookUp($id);
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->known = [];
    }

    /**
     * Ask the inventory about the product's type
     *
     * @param int $productId
     * @return bool
     */
    private function lookUp(int $productId): bool
    {
        try {
            $sku = $this->skusByProductIds->execute([$productId])[$productId] ?? null;
            $type = is_string($sku) ? ($this->productTypesBySkus->execute([$sku])[$sku] ?? null) : null;
        } catch (LocalizedException) {
            return false;
        }

        return is_string($type) && $this->isSourceItemManagementAllowed->execute($type);
    }
}
