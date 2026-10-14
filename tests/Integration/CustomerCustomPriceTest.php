<?php declare(strict_types=1);

namespace TaskCustomPricing\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\Test\Product\ProductBuilder;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\Integration\Builder\Customer\CustomerBuilder;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Shopware\Core\Test\TestDefaults;

/**
 * Runs the real Shopware price pipeline: DAL entity, decorated price calculator and cart.
 */
class CustomerCustomPriceTest extends TestCase
{
    use IntegrationTestBehaviour;

    private IdsCollection $ids;

    protected function setUp(): void
    {
        $this->ids = new IdsCollection();
        $context = Context::createDefaultContext();

        static::getContainer()->get('product.repository')->create([
            (new ProductBuilder($this->ids, 'custom-price-product'))
                ->price(100.0)
                ->visibility()
                ->stock(10)
                ->build(),
        ], $context);

        static::getContainer()->get('customer.repository')->create([
            (new CustomerBuilder($this->ids, 'with-price'))->build(),
            (new CustomerBuilder($this->ids, 'without-price'))->build(),
        ], $context);

        static::getContainer()->get('customer_custom_price.repository')->create([[
            'customerId' => $this->ids->get('with-price'),
            'productId' => $this->ids->get('custom-price-product'),
            'productVersionId' => Defaults::LIVE_VERSION,
            'customPrice' => 80.0,
        ]], $context);
    }

    public function testCustomerSeesCustomPriceWithOriginalAsListPrice(): void
    {
        $product = $this->loadProduct($this->createContext('with-price'));

        static::assertSame(80.0, $product->getCalculatedPrice()->getUnitPrice());
        static::assertSame(100.0, $product->getCalculatedPrice()->getListPrice()?->getPrice());
        static::assertSame(80.0, $product->getCalculatedCheapestPrice()->getUnitPrice());
    }

    public function testOtherCustomersAndGuestsSeeNormalPrice(): void
    {
        static::assertSame(100.0, $this->loadProduct($this->createContext('without-price'))->getCalculatedPrice()->getUnitPrice());
        static::assertSame(100.0, $this->loadProduct($this->createContext(null))->getCalculatedPrice()->getUnitPrice());
    }

    public function testCartChargesCustomPrice(): void
    {
        $context = $this->createContext('with-price');
        $cartService = static::getContainer()->get(CartService::class);

        $cart = $cartService->getCart($context->getToken(), $context);
        $lineItem = new LineItem($this->ids->get('custom-price-product'), LineItem::PRODUCT_LINE_ITEM_TYPE, $this->ids->get('custom-price-product'), 2);
        $lineItem->setStackable(true);
        $cart = $cartService->add($cart, $lineItem, $context);

        $item = $cart->getLineItems()->get($this->ids->get('custom-price-product'));
        static::assertNotNull($item);
        static::assertSame(80.0, $item->getPrice()?->getUnitPrice());
        static::assertSame(160.0, $item->getPrice()->getTotalPrice());
    }

    public function testDeletingTheProductDeletesItsCustomPrices(): void
    {
        $context = Context::createDefaultContext();
        static::getContainer()->get('product.repository')->delete([['id' => $this->ids->get('custom-price-product')]], $context);

        $criteria = (new Criteria())->addFilter(new EqualsFilter('customerId', $this->ids->get('with-price')));
        static::assertSame(0, static::getContainer()->get('customer_custom_price.repository')->search($criteria, $context)->getTotal());
    }

    private function createContext(?string $customerKey): SalesChannelContext
    {
        $options = $customerKey === null ? [] : [SalesChannelContextService::CUSTOMER_ID => $this->ids->get($customerKey)];

        return static::getContainer()->get(SalesChannelContextFactory::class)
            ->create(Uuid::randomHex(), TestDefaults::SALES_CHANNEL, $options);
    }

    private function loadProduct(SalesChannelContext $context): SalesChannelProductEntity
    {
        $product = static::getContainer()->get('sales_channel.product.repository')
            ->search(new Criteria([$this->ids->get('custom-price-product')]), $context)
            ->first();
        static::assertInstanceOf(SalesChannelProductEntity::class, $product);

        return $product;
    }
}
