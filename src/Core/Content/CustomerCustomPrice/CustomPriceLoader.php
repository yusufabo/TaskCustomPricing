<?php declare(strict_types=1);

namespace TaskCustomPricing\Core\Content\CustomerCustomPrice;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Reads custom prices with plain SQL, because it runs for every product price calculation.
 */
class CustomPriceLoader
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<string> $productIds
     *
     * @return array<string, float> custom gross price by product id (hex)
     */
    public function load(string $customerId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllKeyValue(
            'SELECT LOWER(HEX(product_id)), custom_price
             FROM customer_custom_price
             WHERE customer_id = :customerId
               AND product_version_id = :versionId
               AND product_id IN (:productIds)',
            [
                'customerId' => Uuid::fromHexToBytes($customerId),
                'versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'productIds' => Uuid::fromHexToBytesList(array_values(array_unique($productIds))),
            ],
            ['productIds' => ArrayParameterType::BINARY],
        );

        $prices = [];
        foreach ($rows as $productId => $price) {
            if (is_numeric($price)) {
                $prices[(string) $productId] = (float) $price;
            }
        }

        return $prices;
    }

    public function customerHasCustomPrices(string $customerId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM customer_custom_price WHERE customer_id = :customerId LIMIT 1',
            ['customerId' => Uuid::fromHexToBytes($customerId)],
        );
    }
}
