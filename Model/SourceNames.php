<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLogInventory\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;

/**
 * The name of a source as people know it, read through the inventory's repository once per source and request
 *
 * A source without a name, or one that no longer exists, is named by its code. A name is cut to what a change's
 * scope label holds.
 */
class SourceNames implements ResetAfterRequestInterface
{
    private const MAX_LENGTH = 255;

    /**
     * @var array<string, string>
     */
    private array $names = [];

    /**
     * @param SourceRepositoryInterface $sources
     */
    public function __construct(
        private readonly SourceRepositoryInterface $sources
    ) {
    }

    /**
     * The source's name, or its code
     *
     * @param string $code
     * @return string
     */
    public function nameOf(string $code): string
    {
        return $this->names[$code] ??= $this->read($code);
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->names = [];
    }

    /**
     * The stored name, or the code
     *
     * @param string $code
     * @return string
     */
    private function read(string $code): string
    {
        try {
            $name = trim((string) $this->sources->get($code)->getName());
        } catch (NoSuchEntityException) {
            $name = '';
        }

        return mb_substr($name === '' ? $code : $name, 0, self::MAX_LENGTH);
    }
}
