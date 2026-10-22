<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Test\Unit\Model;

use Hryvinskyi\AdminActionLogInventory\Model\SourceItemState;
use Hryvinskyi\AdminActionLogInventory\Model\SourceStockChanges;
use Hryvinskyi\AdminActionLogInventory\Model\SourceStockTracker;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A write is run between two reads of what it names, and what changed is recorded; recording never fails the write
 *
 * @phpstan-import-type StoredPair from SourceItemState
 */
class SourceStockTrackerTest extends TestCase
{
    /**
     * What happened, in order: "read", "write", "record"
     *
     * @var list<string>
     */
    private array $steps = [];

    /**
     * The pairs each read was asked for
     *
     * @var list<list<array{sku: string, source: string}>>
     */
    private array $reads = [];

    /**
     * @var list<string>
     */
    private array $errors = [];

    /**
     * States the reads answer, in turn
     *
     * @var list<array<string, StoredPair>>
     */
    private array $states = [];

    /**
     * @var \Throwable|null
     */
    private ?\Throwable $readFailure = null;

    /**
     * @var \Throwable|null
     */
    private ?\Throwable $recordFailure = null;

    /**
     * @var \Throwable|null
     */
    private ?\Throwable $writeFailure = null;

    public function testTheStateIsReadBeforeAndAfterTheWriteAndTheDifferenceRecorded(): void
    {
        $result = $this->tracker()->forItems(
            [$this->item('24-MB01', 'default'), $this->item(null, 'default'), 'not an item'],
            function (): string {
                $this->steps[] = 'write';

                return 'written';
            }
        );

        self::assertSame('written', $result);
        self::assertSame(['read', 'write', 'read', 'record'], $this->steps);
        self::assertSame([[['sku' => '24-MB01', 'source' => 'default']]], array_slice($this->reads, 0, 1));
    }

    public function testABulkWriteNamesEveryProductInEverySource(): void
    {
        $this->tracker()->forProducts(['24-MB01', '24-MB02', 7], ['default', 'eu'], fn (): int => 4);

        self::assertSame(
            [
                ['sku' => '24-MB01', 'source' => 'default'],
                ['sku' => '24-MB01', 'source' => 'eu'],
                ['sku' => '24-MB02', 'source' => 'default'],
                ['sku' => '24-MB02', 'source' => 'eu'],
            ],
            $this->reads[0]
        );
    }

    public function testAWriteNamingNothingIsRunWithoutReading(): void
    {
        $result = $this->tracker()->forProducts([], ['default'], fn (): int => 0);

        self::assertSame(0, $result);
        self::assertSame([], $this->steps);
    }

    public function testAWriteThatFailsRecordsNothingAndItsFailureReachesTheCaller(): void
    {
        $this->writeFailure = new \DomainException('The quantity is not valid.');
        try {
            $this->tracker()->forItems([$this->item('24-MB01', 'default')], $this->write());
            self::fail('The write\'s failure was swallowed.');
        } catch (\DomainException $exception) {
            self::assertSame('The quantity is not valid.', $exception->getMessage());
        }

        self::assertSame(['read', 'write'], $this->steps);
    }

    public function testAStateThatCannotBeReadLetsTheWriteRunUnrecorded(): void
    {
        $this->readFailure = new \RuntimeException('Lost connection');

        $result = $this->tracker()->forItems([$this->item('24-MB01', 'default')], function (): string {
            $this->steps[] = 'write';

            return 'written';
        });

        self::assertSame('written', $result);
        self::assertSame(['write'], $this->steps);
        self::assertSame(['The stock of products in sources could not be read before a change.'], $this->errors);
    }

    public function testAFailureToRecordIsLoggedAndTheWritesResultReturned(): void
    {
        $this->recordFailure = new \RuntimeException('Deadlock');

        $result = $this->tracker()->forProducts(['24-MB01'], ['default'], fn (): int => 1);

        self::assertSame(1, $result);
        self::assertSame(['The change of the stock of products in sources could not be recorded.'], $this->errors);
    }

    /**
     * A write that returns "written", or fails with the failure set
     *
     * @return callable(): string
     */
    private function write(): callable
    {
        return function (): string {
            $this->steps[] = 'write';
            if ($this->writeFailure !== null) {
                throw $this->writeFailure;
            }

            return 'written';
        };
    }

    private function item(?string $sku, string $source): SourceItemInterface
    {
        $item = $this->createMock(SourceItemInterface::class);
        $item->method('getSku')->willReturn($sku);
        $item->method('getSourceCode')->willReturn($source);

        return $item;
    }

    private function tracker(): SourceStockTracker
    {
        $state = $this->createMock(SourceItemState::class);
        $state->method('read')->willReturnCallback(function (array $pairs): array {
            if ($this->readFailure !== null) {
                throw $this->readFailure;
            }

            /** @var list<array{sku: string, source: string}> $pairs */
            $this->steps[] = 'read';
            $this->reads[] = $pairs;

            return array_shift($this->states) ?? [];
        });

        $changes = $this->createMock(SourceStockChanges::class);
        $changes->method('record')->willReturnCallback(function (): void {
            if ($this->recordFailure !== null) {
                throw $this->recordFailure;
            }

            $this->steps[] = 'record';
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string|\Stringable $message): void {
            $this->errors[] = (string) $message;
        });

        return new SourceStockTracker($state, $changes, $logger);
    }
}
