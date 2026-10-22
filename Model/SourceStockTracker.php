<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Model;

use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs a write to the stock of products in sources and records what it changed
 *
 * The stored state of the products in sources the write names is read before it and again after it returns, and the
 * difference is recorded. A write that fails records nothing. Reading and recording never fail the write: a failure
 * is logged, and the write runs, or its result is returned, as if nothing were recorded.
 *
 * Comparing with what is stored, not with what was asked, means a write that stores the values already there
 * records nothing, however often it runs in one request.
 *
 * @phpstan-import-type Pair from SourceItemState
 */
class SourceStockTracker
{
    /**
     * @param SourceItemState $state
     * @param SourceStockChanges $changes
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SourceItemState $state,
        private readonly SourceStockChanges $changes,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Run a write naming source items, and record what it changed
     *
     * @template T
     * @param array<mixed> $sourceItems The write's source items; anything else is ignored
     * @param callable $write
     * @phpstan-param callable(): T $write
     * @return T
     */
    public function forItems(array $sourceItems, callable $write): mixed
    {
        $pairs = [];
        foreach ($sourceItems as $item) {
            $sku = $item instanceof SourceItemInterface ? $item->getSku() : null;
            $source = $item instanceof SourceItemInterface ? $item->getSourceCode() : null;
            if ($sku !== null && $sku !== '' && $source !== null && $source !== '') {
                $pairs[] = ['sku' => $sku, 'source' => $source];
            }
        }

        return $this->track($pairs, $write);
    }

    /**
     * Run a write naming products and sources, every product in every source, and record what it changed
     *
     * @template T
     * @param array<mixed> $skus
     * @param array<mixed> $sources Source codes
     * @param callable $write
     * @phpstan-param callable(): T $write
     * @return T
     */
    public function forProducts(array $skus, array $sources, callable $write): mixed
    {
        $pairs = [];
        foreach ($skus as $sku) {
            foreach ($sources as $source) {
                if (is_string($sku) && $sku !== '' && is_string($source) && $source !== '') {
                    $pairs[] = ['sku' => $sku, 'source' => $source];
                }
            }
        }

        return $this->track($pairs, $write);
    }

    /**
     * Read, write, read again, record
     *
     * @template T
     * @param list<Pair> $pairs
     * @param callable $write
     * @phpstan-param callable(): T $write
     * @return T
     */
    private function track(array $pairs, callable $write): mixed
    {
        if ($pairs === []) {
            return $write();
        }

        try {
            $before = $this->state->read($pairs);
        } catch (\Throwable $exception) {
            $this->logger->error(
                'The stock of products in sources could not be read before a change.',
                ['exception' => $exception]
            );

            return $write();
        }

        $result = $write();

        try {
            $this->changes->record($before, $this->state->read($pairs));
        } catch (\Throwable $exception) {
            $this->logger->error(
                'The change of the stock of products in sources could not be recorded.',
                ['exception' => $exception]
            );
        }

        return $result;
    }
}
