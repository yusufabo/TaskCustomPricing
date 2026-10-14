<?php declare(strict_types=1);

namespace TaskCustomPricing\Core\Content\Product\SalesChannel\Price;

use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceCollection;
use Shopware\Core\Content\Product\SalesChannel\Price\AbstractProductPriceCalculator;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use TaskCustomPricing\Core\Content\CustomerCustomPrice\CustomPriceLoader;

/**
 * Replaces the product price with the logged-in customer's custom price before Shopware calculates it.
 *
 * Shopware uses this calculator for product pages, listings, search and the cart, so the price the
 * customer sees is also the price that is charged. The normal price is kept as list price, so the
 * default templates show it crossed out.
 */
class CustomerPriceProductCalculator extends AbstractProductPriceCalculator
{
    public const EXTENSION_NAME = 'customPricing';

    public function __construct(
        private readonly AbstractProductPriceCalculator $decorated,
        private readonly CustomPriceLoader $loader,
    ) {
    }

    public function getDecorated(): AbstractProductPriceCalculator
    {
        return $this->decorated;
    }

    public function calculate(iterable $products, SalesChannelContext $context): void
    {
        $customer = $context->getCustomer();
        if ($customer !== null) {
            // The products are iterated twice, so a generator must be turned into an array first
            $products = \is_array($products) ? $products : iterator_to_array($products, false);
            $this->applyCustomPrices($products, $customer->getId(), $context);
        }

        $this->decorated->calculate($products, $context);
    }

    /**
     * @param array<Entity> $products
     */
    private function applyCustomPrices(array $products, string $customerId, SalesChannelContext $context): void
    {
        $byId = [];
        foreach ($products as $product) {
            if ($product->has('id') && $product->has('price') && \is_string($product->get('id'))) {
                $byId[$product->get('id')] = $product;
            }
        }

        $customPrices = $this->loader->load($customerId, array_keys($byId));

        foreach ($customPrices as $productId => $customPrice) {
            $product = $byId[$productId] ?? null;
            if ($product !== null) {
                $this->applyCustomPrice($product, $customPrice, $context);
            }
        }
    }

    private function applyCustomPrice(Entity $product, float $customPrice, SalesChannelContext $context): void
    {
        // On a second calculation (e.g. in the cart) the product already has the custom price
        $extension = $product->getExtension(self::EXTENSION_NAME);
        $originalPrices = $extension instanceof ArrayStruct ? $extension->get('originalPrice') : $product->get('price');

        $taxId = $product->has('taxId') ? $product->get('taxId') : null;
        if (!$originalPrices instanceof PriceCollection || !\is_string($taxId)) {
            return;
        }

        $original = $originalPrices->getCurrencyPrice(Defaults::CURRENCY);
        if ($original === null) {
            return;
        }

        $taxRate = $this->getTaxRate($context->buildTaxRules($taxId));
        $listPrice = $original->getGross() > $customPrice
            ? new Price(Defaults::CURRENCY, $original->getNet(), $original->getGross(), $original->getLinked())
            : null;

        $price = new Price(
            Defaults::CURRENCY,
            $customPrice / (1 + $taxRate / 100),
            $customPrice,
            true,
            $listPrice,
        );

        $assign = [
            'price' => new PriceCollection([$price]),
            // The custom price wins over advanced (quantity/rule) prices and the indexed cheapest price
            'prices' => new ProductPriceCollection(),
        ];
        if ($product->has('cheapestPrice')) {
            $assign['cheapestPrice'] = null;
        }
        $product->assign($assign);

        $product->addExtension(self::EXTENSION_NAME, new ArrayStruct([
            'originalPrice' => $originalPrices,
            'customPrice' => $customPrice,
        ]));
    }

    private function getTaxRate(TaxRuleCollection $rules): float
    {
        $rate = 0.0;
        foreach ($rules as $rule) {
            $rate += $rule->getTaxRate() * $rule->getPercentage() / 100;
        }

        return $rate;
    }
}
