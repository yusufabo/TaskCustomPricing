<?php declare(strict_types=1);

namespace TaskCustomPricing\Core\Content\CustomerCustomPrice;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<CustomerCustomPriceEntity>
 */
class CustomerCustomPriceCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return CustomerCustomPriceEntity::class;
    }
}
