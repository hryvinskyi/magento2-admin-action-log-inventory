<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Test\Integration\Controller;

use Hryvinskyi\AdminActionLogInventory\Test\Integration\Fixture\Stock;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Message\MessageInterface;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * One save of the admin product form records each change of the product's stock once
 *
 * The form's save writes the stock twice: the stock item it saves with the product brings the default source along,
 * and the form's sources are saved again once the product is saved. The second write finds the values already
 * stored. The quantity the admin typed is a change the form asked for, never one set automatically. The stock item's
 * own changes are journaled when the product's transaction commits, so this test commits for real and removes what
 * it wrote.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
class ProductFormSaveTest extends AbstractBackendController
{
    private const SKU = 'aal-msi-form';
    private const PARENT_SKU = 'aal-msi-form-parent';

    /**
     * @var string
     */
    protected $resource = 'Magento_Catalog::products';

    /**
     * @var string
     */
    protected $uri = 'backend/catalog/product/save';

    /**
     * @var string
     */
    protected $httpMethod = HttpRequest::METHOD_POST;

    /**
     * @var Stock
     */
    private Stock $stock;

    /**
     * @var string
     */
    private string $productId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = new Stock();
        $this->productId = $this->stock->product(self::SKU, 'Aal MSI Form', 100);
    }

    /**
     * @var string
     */
    private string $parentId = '';

    protected function tearDown(): void
    {
        $this->stock->deleteProduct(self::SKU);
        $this->stock->forgetChangesOf($this->productId);
        if ($this->parentId !== '') {
            $this->stock->deleteProduct(self::PARENT_SKU);
            $this->stock->forgetChangesOf($this->parentId);
        }
        parent::tearDown();
    }

    public function testAQuantityChangedInTheFormIsRecordedOnce(): void
    {
        $since = $this->stock->latestJournalId();

        $this->saveForm($this->productId, self::SKU, 'Aal MSI Form', ['qty' => '95', 'is_in_stock' => '1'], [
            'qty' => '95',
            'is_in_stock' => '1',
        ]);
        $stored = Stock::service(GetSourceItemsBySkuInterface::class)->execute(self::SKU);
        self::assertSame([95.0], array_map(static fn ($item): ?float => $item->getQuantity(), array_values($stored)));

        $changes = $this->stock->changesOf($this->productId, $since);
        $quantities = $this->stock->carrying($changes, 'quantity');
        self::assertCount(1, $quantities, 'The quantity is recorded once');
        self::assertSame(
            ['before' => '100.0000', 'after' => '95.0000', 'scope' => 'Default Source'],
            $this->stock->fields($quantities[0])['quantity']
        );
        self::assertSame([], $this->stock->carrying($changes, 'qty'), 'And not again through the stock item');
        self::assertFalse(
            array_column($quantities[0]['detail']['fields'], 'automatic', 'field')['quantity'] ?? null,
            'A quantity typed into the form was asked for, although the form sends it under another name'
        );
    }

    public function testTheStockStatusOfAProductKeptOutOfSourcesIsRecordedByTheStockItemAsAskedFor(): void
    {
        $this->parentId = $this->stock->product(self::PARENT_SKU, 'Aal MSI Form Parent', 0, 'configurable');
        $since = $this->stock->latestJournalId();

        $this->saveForm(
            $this->parentId,
            self::PARENT_SKU,
            'Aal MSI Form Parent',
            ['is_in_stock' => '0'],
            ['is_in_stock' => '1']
        );

        $statuses = $this->stock->carrying($this->stock->changesOf($this->parentId, $since), 'is_in_stock');
        self::assertCount(1, $statuses, 'A product without stock in sources has its status recorded by its stock item');
        self::assertSame(
            ['before' => '1', 'after' => '0'],
            array_intersect_key($this->stock->fields($statuses[0])['is_in_stock'], ['before' => 1, 'after' => 1])
        );
        self::assertFalse(
            array_column($statuses[0]['detail']['fields'], 'automatic', 'field')['is_in_stock'] ?? null,
            'The status typed into the form was asked for; the form also sends the status it loaded'
        );
    }

    /**
     * Dispatch the product form's save as the form posts it: what the admin edits under quantity_and_stock_status,
     * what the form loaded under stock_data
     *
     * @param string $productId
     * @param string $sku
     * @param string $name
     * @param array<string, string> $edited
     * @param array<string, string> $loaded
     * @return void
     */
    private function saveForm(string $productId, string $sku, string $name, array $edited, array $loaded): void
    {
        $request = $this->getRequest();
        self::assertInstanceOf(HttpRequest::class, $request);
        $request->setMethod(HttpRequest::METHOD_POST);
        $request->setPostValue([
            'product' => [
                'attribute_set_id' => '4',
                'status' => '1',
                'name' => $name,
                'sku' => $sku,
                'price' => '10',
                'tax_class_id' => '2',
                'visibility' => '4',
                'website_ids' => [1 => '1'],
                'quantity_and_stock_status' => $edited,
                'stock_data' => $loaded + ['manage_stock' => '1', 'use_config_manage_stock' => '1'],
            ],
        ]);
        $this->dispatch('backend/catalog/product/save/id/' . $productId);

        $this->assertSessionMessages(self::containsEqual('You saved the product.'), MessageInterface::TYPE_SUCCESS);
    }
}
