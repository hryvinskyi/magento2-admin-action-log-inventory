<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Test\Unit\Model;

use Hryvinskyi\AdminActionLogInventory\Model\SourceItemState;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemSearchResultsInterface;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * The stored stock of the products in sources a write names, read in one query and kept to the pairs named
 */
class SourceItemStateTest extends TestCase
{
    /**
     * Filters the query was given: field, values, condition
     *
     * @var list<array{string, mixed, string}>
     */
    private array $filters = [];

    public function testThePairsNamedAreReadInOneQueryAsTheInventoryStoresThem(): void
    {
        $state = $this->state([
            $this->item('24-MB01', 'default', 95.0, 1),
            $this->item('24-MB01', 'eu', 4.5, 0),
            $this->item('24-MB02', 'default', null, null),
        ]);

        $read = $state->read([
            ['sku' => '24-MB01', 'source' => 'default'],
            ['sku' => '24-MB02', 'source' => 'default'],
            ['sku' => '24-MB02', 'source' => 'eu'],
        ]);

        self::assertSame(
            [
                ['sku', ['24-MB01', '24-MB02'], 'in'],
                ['source_code', ['default', 'eu'], 'in'],
            ],
            $this->filters
        );
        self::assertSame(
            [
                $state->key('24-MB01', 'default') => [
                    'sku' => '24-MB01',
                    'source' => 'default',
                    'quantity' => '95.0000',
                    'status' => '1',
                ],
                $state->key('24-MB02', 'default') => [
                    'sku' => '24-MB02',
                    'source' => 'default',
                    'quantity' => null,
                    'status' => null,
                ],
            ],
            $read,
            'A pair the query also found but nobody named (24-MB01 in eu) is left out, and so is one not stored'
        );
    }

    public function testNamingNothingReadsNothing(): void
    {
        self::assertSame([], $this->state([])->read([]));
        self::assertSame([], $this->filters);
    }

    public function testAKeyTellsEveryProductInEverySourceApart(): void
    {
        $state = $this->state([]);

        self::assertNotSame($state->key('a', 'bc'), $state->key('ab', 'c'));
    }

    private function item(string $sku, string $source, ?float $quantity, ?int $status): SourceItemInterface
    {
        $item = $this->createMock(SourceItemInterface::class);
        $item->method('getSku')->willReturn($sku);
        $item->method('getSourceCode')->willReturn($source);
        $item->method('getQuantity')->willReturn($quantity);
        $item->method('getStatus')->willReturn($status);

        return $item;
    }

    /**
     * @param list<SourceItemInterface> $items What the repository answers
     * @return SourceItemState
     */
    private function state(array $items): SourceItemState
    {
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(
            function (string $field, mixed $value, string $condition) use ($builder): SearchCriteriaBuilder {
                $this->filters[] = [$field, $value, $condition];

                return $builder;
            }
        );
        $builder->method('create')->willReturn($this->createMock(SearchCriteria::class));
        $builders = $this->createMock(SearchCriteriaBuilderFactory::class);
        $builders->method('create')->willReturn($builder);

        $results = $this->createMock(SourceItemSearchResultsInterface::class);
        $results->method('getItems')->willReturn($items);
        $repository = $this->createMock(SourceItemRepositoryInterface::class);
        $repository->method('getList')->willReturn($results);

        return new SourceItemState($repository, $builders);
    }
}
