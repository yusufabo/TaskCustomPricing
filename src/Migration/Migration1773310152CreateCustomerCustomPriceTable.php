<?php declare(strict_types=1);

namespace TaskCustomPricing\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1773310152CreateCustomerCustomPriceTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1773310152;
    }

    public function update(Connection $connection): void
    {
        // product is versioned, so the reference includes product_version_id.
        // One price per customer and product (uniq.customer_product).
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `customer_custom_price` (
                `id` BINARY(16) NOT NULL,
                `customer_id` BINARY(16) NOT NULL,
                `product_id` BINARY(16) NOT NULL,
                `product_version_id` BINARY(16) NOT NULL,
                `custom_price` DECIMAL(10,2) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                CONSTRAINT `check.customer_custom_price.custom_price` CHECK (`custom_price` >= 0),
                UNIQUE KEY `uniq.customer_custom_price.customer_product` (`customer_id`, `product_id`, `product_version_id`),
                CONSTRAINT `fk.customer_custom_price.customer_id` FOREIGN KEY (`customer_id`)
                    REFERENCES `customer` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.customer_custom_price.product_id` FOREIGN KEY (`product_id`, `product_version_id`)
                    REFERENCES `product` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
