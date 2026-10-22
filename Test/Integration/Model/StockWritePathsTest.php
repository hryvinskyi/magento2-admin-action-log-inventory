<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Test\Integration\Model;

use Hryvinskyi\AdminActionLogInventory\Test\Integration\Fixture\Stock;
use Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\DbIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Stock written through the classic stock item, and stock written in a transaction that rolls back
 *
 * The classic stock item's own changes are journaled once their transaction commits, so these tests commit for real
 * and remove what they wrote.
 */
#[DbIsolation(false)]
class StockWritePathsTest extends TestCase
{
    private const SKU = 'aal-msi-paths';

    /**
     * @var Stock
     */
    private Stock $stock;

    /**
     * @var string
     */
    private string $productId = '';

    /**
     * @var int
     */
    private int $since = 0;

    protected function setUp(): void
    {
        $this->stock = new Stock();
        $this->productId = $this->stock->product(self::SKU, 'Aal MSI Paths', 100);
        $this->since = $this->stock->latestJournalId();
    }

    protected function tearDown(): void
    {
        $this->stock->deleteProduct(self::SKU);
        $this->stock->forgetChangesOf($this->productId);
    }

    public function testAQuantitySavedThroughTheClassicStockItemIsRecordedOnceAsTheSourcesQuantity(): void
    {
        $item = Stock::service(StockItemInterfaceFactory::class)->create();
        $item->setQty(95);
        $item->setIsInStock(true);
        Stock::service(StockRegistryInterface::class)->updateStockItemBySku(self::SKU, $item);

        $changes = $this->stock->changesOf($this->productId, $this->since);
        $quantities = $this->stock->carrying($changes, 'quantity');
        self::assertCount(1, $quantities, 'The default source\'s quantity, once');
        self::assertSame(
            ['before' => '100.0000', 'after' => '95.0000', 'scope' => 'Default Source'],
            $this->stock->fields($quantities[0])['quantity']
        );
        self::assertSame([], $this->stock->carrying($changes, 'qty'), 'The stock item\'s copy is not recorded again');
        self::assertSame([], $this->stock->carrying($changes, 'is_in_stock'));
    }

    public function testTheClassicStockItemsOwnSettingsAreStillRecordedFromIt(): void
    {
        Stock::service(StockRegistryStorage::class)->clean();
        $item = Stock::service(StockRegistryInterface::class)->getStockItemBySku(self::SKU);
        $item->setMinQty(2);
        $item->setUseConfigMinQty(false);
        Stock::service(StockRegistryInterface::class)->updateStockItemBySku(self::SKU, $item);

        $settings = $this->stock->carrying($this->stock->changesOf($this->productId, $this->since), 'min_qty');
        self::assertCount(1, $settings);
        self::assertEquals(2, (float) $this->stock->fields($settings[0])['min_qty']['after']);
        self::assertArrayNotHasKey('qty', $this->stock->fields($settings[0]));
    }

    public function testStockWrittenInATransactionThatRollsBackLeavesNoRecord(): void
    {
        $connection = Stock::service(ResourceConnection::class)->getConnection();
        $connection->beginTransaction();
        try {
            $this->stock->save(self::SKU, 'default', 95, 1);
            self::assertCount(1, $this->stock->changesOf($this->productId, $this->since), 'Written inside it');
        } finally {
            $connection->rollBack();
        }

        self::assertSame([], $this->stock->changesOf($this->productId, $this->since));
    }
}
