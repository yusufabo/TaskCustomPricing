<?php declare(strict_types=1);

namespace TaskCustomPricing;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class TaskCustomPricing extends Plugin
{
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container?->get(Connection::class);
        if ($connection instanceof Connection) {
            $connection->executeStatement('DROP TABLE IF EXISTS `customer_custom_price`');
        }
    }
}
