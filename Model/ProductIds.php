<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryCatalogApi\Model\GetProductIdsBySkusInterface;

/**
 * Product ids by SKU, read together; a SKU no product has is left out
 *
 * The inventory's lookup refuses a whole list for one unknown SKU, so after such a refusal each SKU is asked alone.
 */
class ProductIds
{
    /**
     * @param GetProductIdsBySkusInterface $productIdsBySkus
     */
    public function __construct(
        private readonly GetProductIdsBySkusInterface $productIdsBySkus
    ) {
    }

    /**
     * Ids by SKU, as text
     *
     * @param list<string> $skus
     * @return array<string, string>
     */
    public function of(array $skus): array
    {
        $skus = array_values(array_unique($skus));
        if ($skus === []) {
            return [];
        }

        try {
            return $this->ids($skus, $this->productIdsBySkus->execute($skus));
        } catch (NoSuchEntityException) {
            return $this->oneByOne($skus);
        }
    }

    /**
     * Ids asked one SKU at a time, leaving out the SKUs no product has
     *
     * @param list<string> $skus
     * @return array<string, string>
     */
    private function oneByOne(array $skus): array
    {
        $ids = [];
        foreach ($skus as $sku) {
            try {
                $ids += $this->ids([$sku], $this->productIdsBySkus->execute([$sku]));
            } catch (NoSuchEntityException) {
                continue;
            }
        }

        return $ids;
    }

    /**
     * The ids of the SKUs asked, from the lookup's answer
     *
     * @param list<string> $skus
     * @param array<mixed> $found
     * @return array<string, string>
     */
    private function ids(array $skus, array $found): array
    {
        $ids = [];
        foreach ($skus as $sku) {
            $id = $found[$sku] ?? null;
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[$sku] = (string) $id;
            }
        }

        return $ids;
    }
}
