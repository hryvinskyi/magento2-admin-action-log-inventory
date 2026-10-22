<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Test\Integration\Fixture;

use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\InventoryApi\Api\Data\SourceInterfaceFactory;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsDeleteInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\Assert;

/**
 * Products, sources and source items made through the inventory's real services, and the product changes the
 * journal holds for them
 *
 * @phpstan-import-type ChangeDetail from EntityChangeStore
 * @phpstan-type RecordedChange array{
 *     id: int,
 *     action: string,
 *     subject: string,
 *     actor: string,
 *     detail: ChangeDetail
 * }
 */
class Stock
{
    /**
     * A product with its stock, saved through the product repository; its id
     *
     * @param string $sku
     * @param string $name
     * @param float $quantity
     * @param string $typeId A simple product unless said otherwise
     * @return string
     */
    public function product(string $sku, string $name, float $quantity, string $typeId = 'simple'): string
    {
        $product = self::service(ProductInterfaceFactory::class)->create();
        Assert::assertInstanceOf(Product::class, $product);
        $product->setSku($sku)
            ->setName($name)
            ->setAttributeSetId(4)
            ->setTypeId($typeId)
            ->setPrice(10)
            ->setStatus(1)
            ->setVisibility(4)
            ->setWebsiteIds([1])
            ->setData('stock_data', ['qty' => $quantity, 'is_in_stock' => 1, 'manage_stock' => 1]);
        $id = (string) self::service(ProductRepositoryInterface::class)->save($product)->getId();
        self::service(StockRegistryStorage::class)->clean();

        return $id;
    }

    /**
     * Delete the product, if it is there
     *
     * @param string $sku
     * @return void
     */
    public function deleteProduct(string $sku): void
    {
        $registry = self::service(Registry::class);
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
        try {
            self::service(ProductRepositoryInterface::class)->deleteById($sku);
        } catch (NoSuchEntityException) {
            return;
        } finally {
            $registry->unregister('isSecureArea');
            self::service(StockRegistryStorage::class)->clean();
        }
    }

    /**
     * A source saved through the source repository
     *
     * @param string $code
     * @param string $name
     * @return void
     */
    public function source(string $code, string $name): void
    {
        $source = self::service(SourceInterfaceFactory::class)->create();
        $source->setSourceCode($code);
        $source->setName($name);
        $source->setEnabled(true);
        $source->setPostcode('10001');
        $source->setCountryId('US');
        self::service(SourceRepositoryInterface::class)->save($source);
    }

    /**
     * Save a product's quantity and status in a source through the source item save
     *
     * @param string $sku
     * @param string $source
     * @param float $quantity
     * @param int $status
     * @return void
     */
    public function save(string $sku, string $source, float $quantity, int $status): void
    {
        self::service(SourceItemsSaveInterface::class)->execute([$this->item($sku, $source, $quantity, $status)]);
    }

    /**
     * Unassign a product from a source through the source item deletion
     *
     * @param string $sku
     * @param string $source
     * @return void
     */
    public function delete(string $sku, string $source): void
    {
        self::service(SourceItemsDeleteInterface::class)->execute([$this->item($sku, $source, 0, 0)]);
    }

    /**
     * The newest journal id, to tell later records from earlier ones
     *
     * @return int
     */
    public function latestJournalId(): int
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $id = $connection->fetchOne(
            $connection->select()->from($resource->getTableName('hryvinskyi_activity_log'), ['MAX(entity_id)'])
        );

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * The product's changes journaled after the given journal id, oldest first
     *
     * @param string $productId
     * @param int $after
     * @return list<RecordedChange>
     */
    public function changesOf(string $productId, int $after): array
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $changes = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($resource->getTableName('hryvinskyi_activity_log'))
                ->where('channel = ?', 'entity')
                ->where('subject_type = ?', 'product')
                ->where('subject_id = ?', $productId)
                ->where('entity_id > ?', $after)
                ->order('entity_id ASC')
        ) as $row) {
            Assert::assertIsArray($row);
            Assert::assertIsNumeric($row['entity_id'] ?? null);
            Assert::assertIsString($row['action'] ?? null);
            Assert::assertIsString($row['subject_label'] ?? null);
            Assert::assertIsString($row['actor_name'] ?? null);
            $detail = self::service(EntityChangeStore::class)->find((int) $row['entity_id']);
            Assert::assertNotNull($detail, 'A change without its field detail.');
            $changes[] = [
                'id' => (int) $row['entity_id'],
                'action' => $row['action'],
                'subject' => $row['subject_label'],
                'actor' => $row['actor_name'],
                'detail' => $detail,
            ];
        }

        return $changes;
    }

    /**
     * The changes among these that carry the field
     *
     * @param list<RecordedChange> $changes
     * @param string $field
     * @return list<RecordedChange>
     */
    public function carrying(array $changes, string $field): array
    {
        return array_values(array_filter(
            $changes,
            static fn (array $change): bool
                => in_array($field, array_column($change['detail']['fields'], 'field'), true)
        ));
    }

    /**
     * A change's fields by name: before, after, scope label
     *
     * @param RecordedChange $change
     * @return array<string, array{before: string|null, after: string|null, scope: string}>
     */
    public function fields(array $change): array
    {
        $fields = [];
        foreach ($change['detail']['fields'] as $field) {
            $fields[$field['field']] = [
                'before' => $field['before'],
                'after' => $field['after'],
                'scope' => $field['scopeLabel'],
            ];
        }

        return $fields;
    }

    /**
     * Remove what the tests journaled for the product
     *
     * @param string $productId
     * @return void
     */
    public function forgetChangesOf(string $productId): void
    {
        $resource = self::service(ResourceConnection::class);
        $resource->getConnection()->delete($resource->getTableName('hryvinskyi_activity_log'), [
            'channel = ?' => 'entity',
            'subject_type = ?' => 'product',
            'subject_id = ?' => $productId,
        ]);
    }

    /**
     * A service from the real container, typed
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public static function service(string $class): object
    {
        $service = Bootstrap::getObjectManager()->get($class);
        Assert::assertInstanceOf($class, $service);

        return $service;
    }

    /**
     * A source item through its factory
     *
     * @param string $sku
     * @param string $source
     * @param float $quantity
     * @param int $status
     * @return SourceItemInterface
     */
    private function item(string $sku, string $source, float $quantity, int $status): SourceItemInterface
    {
        $item = self::service(SourceItemInterfaceFactory::class)->create();
        $item->setSku($sku);
        $item->setSourceCode($source);
        $item->setQuantity($quantity);
        $item->setStatus($status);

        return $item;
    }
}
