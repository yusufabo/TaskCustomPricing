<?php declare(strict_types=1);

namespace TaskCustomPricing\Tests\Unit\Core\Content\Product;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceCollection;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceEntity;
use Shopware\Core\Content\Product\SalesChannel\Price\AbstractProductPriceCalculator;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Tax\Aggregate\TaxRule\TaxRuleCollection;
use Shopware\Core\System\Tax\TaxCollection;
use Shopware\Core\System\Tax\TaxEntity;
use Shopware\Core\Test\Generator;
use TaskCustomPricing\Core\Content\CustomerCustomPrice\CustomPriceLoader;
use TaskCustomPricing\Core\Content\Product\SalesChannel\Price\CustomerPriceProductCalculator;

class CustomerPriceProductCalculatorTest extends TestCase
{
    private string $taxId;

    private string $customerId;

    protected function setUp(): void
    {
        $this->taxId = Uuid::randomHex();
        $this->customerId = Uuid::randomHex();
    }

    public function testReplacesPriceAndKeepsOriginalAsListPrice(): void
    {
        $product = $this->createProduct(119.0, 100.0);
        $product->setPrices(new ProductPriceCollection([(new ProductPriceEntity())->assign(['id' => Uuid::randomHex()])]));
        $inner = $this->createInner();

        $calculator = new CustomerPriceProductCalculator($inner, $this->createLoader([$product->getId() => 59.5]));
        $calculator->calculate([$product], $this->createContext(withCustomer: true));

        $price = $product->getPrice()?->getCurrencyPrice(Defaults::CURRENCY);
        static::assertNotNull($price);
        static::assertSame(59.5, $price->getGross());
        static::assertEqualsWithDelta(50.0, $price->getNet(), 0.0001);
        static::assertSame(119.0, $price->getListPrice()?->getGross());
        static::assertSame(0, $product->getPrices()?->count(), 'advanced prices must not override the custom price');
        static::assertSame([$product], $inner->calculated, 'Shopware must still calculate the price');

        $extension = $product->getExtension(CustomerPriceProductCalculator::EXTENSION_NAME);
        static::assertInstanceOf(ArrayStruct::class, $extension);
        static::assertSame(59.5, $extension->get('customPrice'));
    }

    public function testSecondCalculationKeepsTheOriginalListPrice(): void
    {
        $product = $this->createProduct(119.0, 100.0);
        $calculator = new CustomerPriceProductCalculator($this->createInner(), $this->createLoader([$product->getId() => 59.5]));
        $context = $this->createContext(withCustomer: true);

        $calculator->calculate([$product], $context);
        $calculator->calculate([$product], $context);

        static::assertSame(119.0, $product->getPrice()?->getCurrencyPrice(Defaults::CURRENCY)?->getListPrice()?->getGross());
    }

    public function testNoListPriceWhenCustomPriceIsHigher(): void
    {
        $product = $this->createProduct(119.0, 100.0);
        $calculator = new CustomerPriceProductCalculator($this->createInner(), $this->createLoader([$product->getId() => 150.0]));

        $calculator->calculate([$product], $this->createContext(withCustomer: true));

        $price = $product->getPrice()?->getCurrencyPrice(Defaults::CURRENCY);
        static::assertSame(150.0, $price?->getGross());
        static::assertNull($price->getListPrice());
    }

    public function testProductsWithoutCustomPriceStayUnchanged(): void
    {
        $product = $this->createProduct(119.0, 100.0);
        $calculator = new CustomerPriceProductCalculator($this->createInner(), $this->createLoader([]));

        $calculator->calculate([$product], $this->createContext(withCustomer: true));

        static::assertSame(119.0, $product->getPrice()?->getCurrencyPrice(Defaults::CURRENCY)?->getGross());
        static::assertNull($product->getExtension(CustomerPriceProductCalculator::EXTENSION_NAME));
    }

    public function testGuestsDoNotQueryCustomPrices(): void
    {
        $product = $this->createProduct(119.0, 100.0);
        $loader = $this->createMock(CustomPriceLoader::class);
        $loader->expects(static::never())->method('load');
        $inner = $this->createInner();

        (new CustomerPriceProductCalculator($inner, $loader))->calculate([$product], $this->createContext(withCustomer: false));

        static::assertSame(119.0, $product->getPrice()?->getCurrencyPrice(Defaults::CURRENCY)?->getGross());
        static::assertSame([$product], $inner->calculated);
    }

    private function createProduct(float $gross, float $net): SalesChannelProductEntity
    {
        $product = new SalesChannelProductEntity();
        $product->setId(Uuid::randomHex());
        $product->setTaxId($this->taxId);
        $product->setPrice(new PriceCollection([new Price(Defaults::CURRENCY, $net, $gross, true)]));

        return $product;
    }

    /**
     * @param array<string, float> $prices
     */
    private function createLoader(array $prices): CustomPriceLoader
    {
        $loader = $this->createMock(CustomPriceLoader::class);
        $loader->method('load')->willReturn($prices);

        return $loader;
    }

    private function createContext(bool $withCustomer): SalesChannelContext
    {
        $tax = new TaxEntity();
        $tax->setId($this->taxId);
        $tax->setTaxRate(19.0);
        $tax->setRules(new TaxRuleCollection());

        $customer = new CustomerEntity();
        $customer->setId($this->customerId);

        return Generator::generateSalesChannelContext(
            taxRules: new TaxCollection([$tax]),
            customer: $customer,
            // The generator always adds a customer, so a guest needs an explicit override
            overrides: $withCustomer ? [] : ['customer' => null],
        );
    }

    private function createInner(): AbstractProductPriceCalculator
    {
        return new class extends AbstractProductPriceCalculator {
            /**
             * @var array<mixed>
             */
            public array $calculated = [];

            public function getDecorated(): AbstractProductPriceCalculator
            {
                throw new \LogicException('not decorated');
            }

            public function calculate(iterable $products, SalesChannelContext $context): void
            {
                $this->calculated = \is_array($products) ? $products : iterator_to_array($products);
            }
        };
    }
}
