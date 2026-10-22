<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Test\Unit\Model;

use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityPartInterface;
use Hryvinskyi\AdminActionLogInventory\Model\SourceManagedProducts;
use Hryvinskyi\AdminActionLogInventory\Model\StockItemPart;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\CatalogInventory\Model\Stock\Item as StockItem;
use Magento\Framework\Model\AbstractModel;
use Magento\InventoryCatalogApi\Model\GetProductTypesBySkusInterface;
use Magento\InventoryCatalogApi\Model\GetSkusByProductIdsInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use PHPUnit\Framework\TestCase;

/**
 * The stock item part leaves out what the sources record, for the products that keep their stock in sources only
 */
class StockItemPartTest extends TestCase
{
    private const STOCK = ['qty' => '95.0000', 'is_in_stock' => '1', 'backorders' => '0', 'min_qty' => '2.0000'];

    /**
     * Product ids the inventory was asked about
     *
     * @var list<int>
     */
    private array $lookups = [];

    public function testAProductKeepingItsStockInSourcesKeepsOnlyItsOtherSettings(): void
    {
        self::assertSame(
            ['backorders' => '0', 'min_qty' => '2.0000'],
            $this->part()->fields($this->stockItem(42), [])
        );
    }

    public function testAProductKeepingNoStockInSourcesKeepsEveryField(): void
    {
        self::assertSame(self::STOCK, $this->part()->fields($this->stockItem(43), []), 'A configurable product');
        self::assertSame(self::STOCK, $this->part()->fields($this->stockItem(404), []), 'A product not found');
        self::assertSame(self::STOCK, $this->part()->fields($this->stockItem(null), []), 'A stock item of nothing');
    }

    public function testTheInventoryIsAskedOncePerProductAndRequest(): void
    {
        $part = $this->part();
        $part->fields($this->stockItem(42), []);
        $part->fields($this->stockItem(42), []);

        self::assertSame([42], $this->lookups);
    }

    public function testEverythingElseIsThePartsItDecorates(): void
    {
        $part = $this->part();
        $item = $this->stockItem(42);

        self::assertSame(['product', 'Product'], [$part->getCode(), $part->getLabel()]);
        self::assertTrue($part->supports($item));
        self::assertSame(self::STOCK, $part->stateBefore($item));
        self::assertSame('All Store Views', $part->scope($item)->getLabel());
        self::assertTrue($part->isSecretField($item, 'secret'));
        self::assertSame('Product 24-MB01 · Joust Duffle Bag', $part->describe($item));
        self::assertSame('42', $part->entityId($item));
        self::assertSame(StockItem::class, $part->getModelClass(), 'Kept by the same class, so it replaces the part');
        self::assertSame(['qty' => '100.0000'], $part->stateAfter($item));
    }

    private function stockItem(?int $productId): AbstractModel
    {
        $item = $this->createMock(AbstractModel::class);
        $item->method('getData')->willReturnCallback(
            static fn (string $key = ''): ?int => $key === 'product_id' ? $productId : null
        );

        return $item;
    }

    private function part(): StockItemPart
    {
        $scope = $this->createMock(EntityScopeInterface::class);
        $scope->method('getLabel')->willReturn('All Store Views');

        $inner = $this->createMock(TrackedEntityPartInterface::class);
        $inner->method('getCode')->willReturn('product');
        $inner->method('getLabel')->willReturn('Product');
        $inner->method('getModelClass')->willReturn(StockItem::class);
        $inner->method('stateAfter')->willReturn(['qty' => '100.0000']);
        $inner->method('supports')->willReturn(true);
        $inner->method('stateBefore')->willReturn(self::STOCK);
        $inner->method('fields')->willReturn(self::STOCK);
        $inner->method('scope')->willReturn($scope);
        $inner->method('isSecretField')->willReturn(true);
        $inner->method('describe')->willReturn('Product 24-MB01 · Joust Duffle Bag');
        $inner->method('entityId')->willReturnCallback(static function (AbstractModel $model): ?string {
            $id = $model->getData('product_id');

            return is_int($id) ? (string) $id : null;
        });

        $skus = $this->createMock(GetSkusByProductIdsInterface::class);
        $skus->method('execute')->willReturnCallback(function (array $ids): array {
            /** @var list<int> $ids */
            $id = $ids[0] ?? 0;
            $this->lookups[] = $id;
            $known = [42 => '24-MB01', 43 => 'MH01'];
            if (!isset($known[$id])) {
                throw new NoSuchEntityException();
            }

            return [$id => $known[$id]];
        });

        $types = $this->createMock(GetProductTypesBySkusInterface::class);
        $types->method('execute')->willReturnCallback(
            static function (array $skus): array {
                /** @var list<string> $skus */
                return array_intersect_key(['24-MB01' => 'simple', 'MH01' => 'configurable'], array_flip($skus));
            }
        );

        $allowed = $this->createMock(IsSourceItemManagementAllowedForProductTypeInterface::class);
        $allowed->method('execute')->willReturnCallback(static fn (string $type): bool => $type === 'simple');

        return new StockItemPart(
            $inner,
            new SourceManagedProducts($skus, $types, $allowed),
            ['qty' => 'qty', 'is_in_stock' => 'is_in_stock']
        );
    }
}
