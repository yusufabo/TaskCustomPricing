<?php declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\SalesChannel\Price\ProductPriceCalculator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TaskCustomPricing\Command\ImportCustomPricesCommand;
use TaskCustomPricing\Core\Content\CustomerCustomPrice\CustomerCustomPriceDefinition;
use TaskCustomPricing\Core\Content\CustomerCustomPrice\CustomPriceLoader;
use TaskCustomPricing\Core\Content\Extension\CustomerCustomPriceExtension;
use TaskCustomPricing\Core\Content\Extension\ProductCustomPriceExtension;
use TaskCustomPricing\Core\Content\Product\SalesChannel\Price\CustomerPriceProductCalculator;
use TaskCustomPricing\Subscriber\HttpCacheSubscriber;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    // Entity definition and the reverse associations on customer and product
    $services->set(CustomerCustomPriceDefinition::class)
        ->tag('shopware.entity.definition', ['entity' => CustomerCustomPriceDefinition::ENTITY_NAME]);

    $services->set(CustomerCustomPriceExtension::class)
        ->tag('shopware.entity.extension');

    $services->set(ProductCustomPriceExtension::class)
        ->tag('shopware.entity.extension');

    // Price calculation: used by product pages, listings, search and the cart
    $services->set(CustomPriceLoader::class)
        ->args([service(Connection::class)]);

    $services->set(CustomerPriceProductCalculator::class)
        ->decorate(ProductPriceCalculator::class)
        ->args([service(CustomerPriceProductCalculator::class . '.inner'), service(CustomPriceLoader::class)]);

    $services->set(HttpCacheSubscriber::class)
        ->args([service(CustomPriceLoader::class)])
        ->tag('kernel.event_subscriber');

    // Import
    $services->set(ImportCustomPricesCommand::class)
        ->args([service(Connection::class), service('customer_custom_price.repository'), service('event_dispatcher')])
        ->tag('console.command');
};
