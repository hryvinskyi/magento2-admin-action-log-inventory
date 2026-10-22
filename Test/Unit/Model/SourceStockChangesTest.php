<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Test\Unit\Model;

use Hryvinskyi\AdminActionLog\Api\Data\EntityChangeInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityChangeInterfaceFactory;
use Hryvinskyi\AdminActionLog\Api\EntityChangeRecorderInterface;
use Hryvinskyi\AdminActionLog\Model\Data\EntityChange;
use Hryvinskyi\AdminActionLog\Model\Data\EntityScope;
use Hryvinskyi\AdminActionLogInventory\Model\ProductIds;
use Hryvinskyi\AdminActionLogInventory\Model\SourceNames;
use Hryvinskyi\AdminActionLogInventory\Model\SourceStockChanges;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\InventoryCatalogApi\Model\GetProductIdsBySkusInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Two states of products in sources become product changes, over a recording fake of the recorder
 */
class SourceStockChangesTest extends TestCase
{
    /**
     * @var list<EntityChangeInterface>
     */
    private array $recorded = [];

    /**
     * How many times the recorder was called
     *
     * @var int
     */
    private int $calls = 0;

    /**
     * Messages logged as errors
     *
     * @var list<string>
     */
    private array $errors = [];

    public function testAChangedQuantityIsAChangeOfTheProductInTheSourcesScope(): void
    {
        $this->changes()->record(
            ['default' . "\0" . '24-MB01' => $this->pair('24-MB01', 'default', '100.0000', '1')],
            ['default' . "\0" . '24-MB01' => $this->pair('24-MB01', 'default', '95.0000', '1')]
        );

        self::assertCount(1, $this->recorded);
        $change = $this->recorded[0];
        self::assertSame(['product', '42'], [$change->getEntityCode(), $change->getEntityId()]);
        self::assertSame(EntityChangeInterface::OPERATION_UPDATE, $change->getOperation());
        self::assertSame(['global', 0, 'Default Source'], [
            $change->getScope()->getType(),
            $change->getScope()->getId(),
            $change->getScope()->getLabel(),
        ]);
        self::assertSame(['quantity' => '100.0000', 'source_status' => '1'], $change->getBefore());
        self::assertSame(['quantity' => '95.0000', 'source_status' => '1'], $change->getAfter());
    }

    public function testAssigningASourceSetsTheFieldsFromNothingAndUnassigningEmptiesThem(): void
    {
        $this->changes()->record(
            ['default' . "\0" . '24-MB01' => $this->pair('24-MB01', 'default', '5.0000', '1')],
            ['eu' . "\0" . '24-MB01' => $this->pair('24-MB01', 'eu', '0.0000', '0')]
        );

        self::assertCount(2, $this->recorded);
        [$unassigned, $assigned] = $this->recorded;
        self::assertSame('Default Source', $unassigned->getScope()->getLabel());
        self::assertSame(['quantity' => null, 'source_status' => null], $unassigned->getAfter());
        self::assertSame('eu', $assigned->getScope()->getLabel(), 'A source no longer stored is named by its code');
        self::assertSame(['quantity' => null, 'source_status' => null], $assigned->getBefore());
        self::assertSame(['quantity' => '0.0000', 'source_status' => '0'], $assigned->getAfter());
        self::assertSame(EntityChangeInterface::OPERATION_UPDATE, $assigned->getOperation(), 'The product exists');
    }

    public function testTheChangesOfOneWriteAreRecordedTogether(): void
    {
        $this->changes()->record(
            [
                'default' . "\0" . '24-MB01' => $this->pair('24-MB01', 'default', '5.0000', '1'),
                'eu' . "\0" . '24-MB01' => $this->pair('24-MB01', 'eu', '1.0000', '1'),
            ],
            [
                'default' . "\0" . '24-MB01' => $this->pair('24-MB01', 'default', '4.0000', '1'),
                'eu' . "\0" . '24-MB01' => $this->pair('24-MB01', 'eu', '0.0000', '0'),
            ]
        );

        self::assertSame(1, $this->calls, 'One call, so the products it names are read at once');
        self::assertCount(2, $this->recorded);
    }

    public function testAProductInASourceThatCannotBeRecordedNeverDropsTheRestOfTheWrite(): void
    {
        $this->changes()->record(
            [
                'broken' . "\0" . '24-MB01' => $this->pair('24-MB01', 'broken', '5.0000', '1'),
                'default' . "\0" . '24-MB01' => $this->pair('24-MB01', 'default', '5.0000', '1'),
            ],
            [
                'broken' . "\0" . '24-MB01' => $this->pair('24-MB01', 'broken', '3.0000', '1'),
                'default' . "\0" . '24-MB01' => $this->pair('24-MB01', 'default', '4.0000', '1'),
            ]
        );

        self::assertSame(['Default Source'], array_map(
            static fn (EntityChangeInterface $change): string => $change->getScope()->getLabel(),
            $this->recorded
        ));
        self::assertSame(['The stock of product 42 in source broken could not be recorded.'], $this->errors);
    }

    public function testStockThatDidNotChangeRecordsNothing(): void
    {
        $pair = ['default' . "\0" . '24-MB01' => $this->pair('24-MB01', 'default', '95.0000', '1')];

        $this->changes()->record($pair, $pair);
        $this->changes()->record([], []);

        self::assertSame([], $this->recorded);
        self::assertSame(0, $this->calls, 'Nothing to record, no call');
    }

    public function testStockOfAProductThatNoLongerExistsRecordsNothing(): void
    {
        $this->changes()->record(
            ['default' . "\0" . 'gone' => $this->pair('gone', 'default', '1.0000', '1')],
            []
        );

        self::assertSame([], $this->recorded);
    }

    /**
     * @return array{sku: string, source: string, quantity: string|null, status: string|null}
     */
    private function pair(string $sku, string $source, ?string $quantity, ?string $status): array
    {
        return ['sku' => $sku, 'source' => $source, 'quantity' => $quantity, 'status' => $status];
    }

    private function changes(): SourceStockChanges
    {
        $recorder = $this->createMock(EntityChangeRecorderInterface::class);
        $recorder->expects(self::never())->method('record');
        $recorder->method('recordAll')->willReturnCallback(function (array $changes): void {
            ++$this->calls;
            foreach ($changes as $change) {
                self::assertInstanceOf(EntityChangeInterface::class, $change);
                $this->recorded[] = $change;
            }
        });

        $factory = $this->createMock(EntityChangeInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(static fn (): EntityChangeInterface => new EntityChange(
            new EntityScope()
        ));

        $ids = $this->createMock(GetProductIdsBySkusInterface::class);
        $ids->method('execute')->willReturnCallback(static function (array $skus): array {
            /** @var list<string> $skus */
            $known = ['24-MB01' => 42];
            if (array_diff($skus, array_keys($known)) !== []) {
                throw new NoSuchEntityException();
            }

            return array_intersect_key($known, array_flip($skus));
        });

        $source = $this->createMock(SourceInterface::class);
        $source->method('getName')->willReturn('Default Source');
        $sources = $this->createMock(SourceRepositoryInterface::class);
        $sources->method('get')->willReturnCallback(static function (string $code) use ($source): SourceInterface {
            if ($code === 'broken') {
                throw new \RuntimeException('Lost connection to the database');
            }

            if ($code !== 'default') {
                throw new NoSuchEntityException();
            }

            return $source;
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string|\Stringable $message): void {
            $this->errors[] = (string) $message;
        });

        return new SourceStockChanges($recorder, $factory, new ProductIds($ids), new SourceNames($sources), $logger);
    }
}
