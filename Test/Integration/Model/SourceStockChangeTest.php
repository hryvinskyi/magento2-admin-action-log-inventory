<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Test\Integration\Model;

use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeOperation;
use Hryvinskyi\AdminActionLog\Model\Search\Field\FieldDisplays;
use Hryvinskyi\AdminActionLog\Model\Search\Field\FieldLabelSourceInterface;
use Hryvinskyi\AdminActionLogInventory\Test\Integration\Fixture\Stock;
use Hryvinskyi\ActivityLog\Api\ActorScopeInterface;
use Hryvinskyi\ActivityLog\Api\Data\ActorInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Data\ActorType;
use Hryvinskyi\AdminActionLogInventory\Model\QueuedUpdateActors;
use Hryvinskyi\AdminActionLogInventory\Plugin\MessageQueue\RememberStockUpdateRequester;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Inventory\Model\SourceItem\Command\DecrementSourceItemQty;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryCatalog\Model\UpdateInventory;
use Magento\InventoryCatalog\Model\UpdateInventory\InventoryDataFactory;
use Magento\InventoryCatalogApi\Api\BulkSourceAssignInterface;
use Magento\InventoryCatalogApi\Api\BulkSourceUnassignInterface;
use Magento\TestFramework\Fixture\DbIsolation;
use PHPUnit\Framework\TestCase;

/**
 * The stock of a product in sources, written through the inventory's real services, is recorded as changes of the
 * product
 *
 * The recorder writes at once, inside each test's transaction.
 */
#[DbIsolation(true)]
class SourceStockChangeTest extends TestCase
{
    private const SKU = 'aal-msi-tote';
    private const SOURCE = 'aal_back_room';

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
        $this->productId = $this->stock->product(self::SKU, 'Aal MSI Tote', 100);
        $this->since = $this->stock->latestJournalId();
    }

    public function testAQuantitySavedOnTheDefaultSourceIsAChangeOfTheProductInThatSource(): void
    {
        $this->stock->save(self::SKU, 'default', 95, 1);

        $changes = $this->stock->changesOf($this->productId, $this->since);
        self::assertCount(1, $changes);
        self::assertSame('Product saved', $changes[0]['action']);
        self::assertSame('Product aal-msi-tote · Aal MSI Tote · quantity', $changes[0]['subject']);
        self::assertSame('product', $changes[0]['detail']['entityType']);
        self::assertSame($this->productId, $changes[0]['detail']['entityId']);
        self::assertSame(ChangeOperation::Update, $changes[0]['detail']['operation']);
        self::assertSame(
            ['quantity' => ['before' => '100.0000', 'after' => '95.0000', 'scope' => 'Default Source']],
            $this->stock->fields($changes[0]),
            'Only what changed, in the source\'s scope'
        );
        self::assertSame('global', $changes[0]['detail']['fields'][0]['scopeType']);
    }

    public function testGoingOutOfStockRecordsTheStatusWithTheQuantity(): void
    {
        $this->stock->save(self::SKU, 'default', 0, 0);

        $changes = $this->stock->changesOf($this->productId, $this->since);
        self::assertCount(1, $changes);
        $fields = $this->stock->fields($changes[0]);
        self::assertSame(['100.0000', '0.0000'], [$fields['quantity']['before'], $fields['quantity']['after']]);
        self::assertSame(['1', '0'], [$fields['source_status']['before'], $fields['source_status']['after']]);
    }

    public function testAssigningASourceSetsTheStockFromNothingAndUnassigningEmptiesIt(): void
    {
        $this->stock->source(self::SOURCE, 'Aal Back Room');

        $this->stock->save(self::SKU, self::SOURCE, 10, 1);
        $this->stock->delete(self::SKU, self::SOURCE);

        $changes = $this->stock->changesOf($this->productId, $this->since);
        self::assertCount(2, $changes);
        [$assigned, $unassigned] = $changes;
        self::assertSame(ChangeOperation::Update, $assigned['detail']['operation'], 'The product already existed');
        self::assertSame(
            [
                'quantity' => ['before' => null, 'after' => '10.0000', 'scope' => 'Aal Back Room'],
                'source_status' => ['before' => null, 'after' => '1', 'scope' => 'Aal Back Room'],
            ],
            $this->stock->fields($assigned)
        );
        self::assertSame(
            [
                'quantity' => ['before' => '10.0000', 'after' => null, 'scope' => 'Aal Back Room'],
                'source_status' => ['before' => '1', 'after' => null, 'scope' => 'Aal Back Room'],
            ],
            $this->stock->fields($unassigned)
        );
    }

    public function testSavingTheStockAlreadyStoredRecordsNothingHoweverOftenItIsSaved(): void
    {
        $this->stock->save(self::SKU, 'default', 95, 1);
        $this->stock->save(self::SKU, 'default', 95, 1);
        $this->stock->save(self::SKU, 'default', 95.0, 1);

        self::assertCount(1, $this->stock->changesOf($this->productId, $this->since), 'The first save changed it');
    }

    public function testAssigningAndUnassigningInBulkIsRecordedToo(): void
    {
        $this->stock->source(self::SOURCE, 'Aal Back Room');

        Stock::service(BulkSourceAssignInterface::class)->execute([self::SKU], [self::SOURCE]);
        Stock::service(BulkSourceUnassignInterface::class)->execute([self::SKU], [self::SOURCE]);

        $changes = $this->stock->changesOf($this->productId, $this->since);
        self::assertCount(2, $changes);
        self::assertSame(
            ['before' => null, 'after' => '0.0000', 'scope' => 'Aal Back Room'],
            $this->stock->fields($changes[0])['quantity']
        );
        self::assertSame(
            ['before' => '0.0000', 'after' => null, 'scope' => 'Aal Back Room'],
            $this->stock->fields($changes[1])['quantity']
        );
    }

    public function testAShipmentsDeductionIsAChangeOfTheProductInTheSource(): void
    {
        $sourceItems = Stock::service(GetSourceItemsBySkuInterface::class)->execute(self::SKU);
        $sourceItem = array_values($sourceItems)[0] ?? null;
        self::assertNotNull($sourceItem);

        Stock::service(DecrementSourceItemQty::class)->execute([
            ['source_item' => $sourceItem, 'qty_to_decrement' => 2],
        ]);

        $changes = $this->stock->changesOf($this->productId, $this->since);
        self::assertCount(1, $changes);
        self::assertSame(
            ['quantity' => ['before' => '100.0000', 'after' => '98.0000', 'scope' => 'Default Source']],
            $this->stock->fields($changes[0])
        );
    }

    public function testAQueuedStockUpdateIsRecordedForTheAdminWhoAskedForIt(): void
    {
        $update = Stock::service(InventoryDataFactory::class)->create([
            'skus' => [self::SKU],
            'data' => '{"qty":"40","is_in_stock":"1"}',
        ]);
        $admin = Stock::service(ActorInterfaceFactory::class)->create([
            'type' => ActorType::Admin,
            'name' => 'jane',
            'id' => '7',
        ]);
        $publisher = $this->createStub(PublisherInterface::class);
        Stock::service(ActorScopeInterface::class)->actAs(
            $admin,
            static function () use ($update, $publisher): void {
                Stock::service(RememberStockUpdateRequester::class)->beforePublish(
                    $publisher,
                    'inventory.mass.update',
                    $update
                );
            }
        );

        Stock::service(UpdateInventory::class)->execute($update);
        Stock::service(UpdateInventory::class)->execute($update);

        $changes = $this->stock->changesOf($this->productId, $this->since);
        self::assertCount(1, $changes, 'The second run stores what is already stored');
        self::assertSame('jane', $changes[0]['actor'], 'Recorded for the admin, not for the consumer process');
        self::assertSame(
            ['before' => '100.0000', 'after' => '40.0000', 'scope' => 'Default Source'],
            $this->stock->fields($changes[0])['quantity']
        );
        self::assertNull(
            Stock::service(QueuedUpdateActors::class)->take($update),
            'Who asked is taken once, by the update it was kept for'
        );
    }

    public function testTheFieldsAreShownAndFoundAsTheAdminsFormsNameThem(): void
    {
        $display = Stock::service(FieldDisplays::class)->forEntity('product');
        self::assertNotNull($display);

        self::assertSame(
            ['quantity' => 'Quantity', 'source_status' => 'Stock Status'],
            $display->labelsOf(['quantity', 'source_status'])
        );
        self::assertSame(
            ['100.0000' => '100', '4.5000' => '4.5'],
            $display->valuesOf('quantity', ['100.0000', '4.5000'])
        );
        self::assertSame(['1' => 'In Stock', '0' => 'Out of Stock'], $display->valuesOf('source_status', ['1', '0']));

        $labelled = Stock::service(FieldLabelSourceInterface::class)->codesLabelled('Quantity');
        self::assertContains('quantity', $labelled, '"field: Quantity" finds the source\'s quantity');
    }
}
