<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Test\Unit\Model;

use Hryvinskyi\AdminActionLogInventory\Model\ProductIds;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryCatalogApi\Model\GetProductIdsBySkusInterface;
use PHPUnit\Framework\TestCase;

/**
 * Product ids by SKU: read together, and one by one when the inventory refuses a list for one unknown SKU
 */
class ProductIdsTest extends TestCase
{
    /**
     * The lists the inventory was asked for
     *
     * @var list<list<string>>
     */
    private array $asked = [];

    public function testKnownSkusAreReadTogether(): void
    {
        self::assertSame(['24-MB01' => '42', '24-MB02' => '43'], $this->ids()->of(['24-MB01', '24-MB02', '24-MB01']));
        self::assertSame([['24-MB01', '24-MB02']], $this->asked);
    }

    public function testAnUnknownSkuIsLeftOutAndTheOthersAreStillFound(): void
    {
        self::assertSame(['24-MB01' => '42'], $this->ids()->of(['24-MB01', 'gone']));
        self::assertSame([['24-MB01', 'gone'], ['24-MB01'], ['gone']], $this->asked);
    }

    public function testNoSkuAsksNothing(): void
    {
        self::assertSame([], $this->ids()->of([]));
        self::assertSame([], $this->asked);
    }

    private function ids(): ProductIds
    {
        $lookup = $this->createMock(GetProductIdsBySkusInterface::class);
        $lookup->method('execute')->willReturnCallback(function (array $skus): array {
            /** @var list<string> $skus */
            $this->asked[] = $skus;
            $known = ['24-MB01' => 42, '24-MB02' => '43'];
            if (array_diff($skus, array_keys($known)) !== []) {
                throw new NoSuchEntityException();
            }

            return array_intersect_key($known, array_flip($skus));
        });

        return new ProductIds($lookup);
    }
}
