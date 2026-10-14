<?php declare(strict_types=1);

namespace TaskCustomPricing\Core\Content\Extension;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use TaskCustomPricing\Core\Content\CustomerCustomPrice\CustomerCustomPriceDefinition;

/**
 * Reverse side of customer_custom_price.customer, so deleting a customer through the DAL also deletes its prices.
 */
class CustomerCustomPriceExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new OneToManyAssociationField('customCustomerPrices', CustomerCustomPriceDefinition::class, 'customer_id'))
                ->addFlags(new CascadeDelete())
        );
    }

    public function getEntityName(): string
    {
        return CustomerDefinition::ENTITY_NAME;
    }
}
