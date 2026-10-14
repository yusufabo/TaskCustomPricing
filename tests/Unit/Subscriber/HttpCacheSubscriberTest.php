<?php declare(strict_types=1);

namespace TaskCustomPricing\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Adapter\Cache\Event\HttpCacheCookieEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Generator;
use Symfony\Component\HttpFoundation\Request;
use TaskCustomPricing\Core\Content\CustomerCustomPrice\CustomPriceLoader;
use TaskCustomPricing\Subscriber\HttpCacheSubscriber;

class HttpCacheSubscriberTest extends TestCase
{
    public function testCustomerWithCustomPricesGetsOwnCacheHash(): void
    {
        $customer = new CustomerEntity();
        $customer->setId(Uuid::randomHex());

        $loader = $this->createMock(CustomPriceLoader::class);
        $loader->method('customerHasCustomPrices')->with($customer->getId())->willReturn(true);

        $event = new HttpCacheCookieEvent(new Request(), Generator::generateSalesChannelContext(customer: $customer), []);
        (new HttpCacheSubscriber($loader))->onCacheCookie($event);

        static::assertSame($customer->getId(), $event->get(HttpCacheSubscriber::CACHE_PART));
    }

    public function testCustomerWithoutCustomPricesSharesTheCache(): void
    {
        $customer = new CustomerEntity();
        $customer->setId(Uuid::randomHex());

        $loader = $this->createMock(CustomPriceLoader::class);
        $loader->method('customerHasCustomPrices')->willReturn(false);

        $event = new HttpCacheCookieEvent(new Request(), Generator::generateSalesChannelContext(customer: $customer), []);
        (new HttpCacheSubscriber($loader))->onCacheCookie($event);

        static::assertNull($event->get(HttpCacheSubscriber::CACHE_PART));
    }

    public function testGuestIsIgnored(): void
    {
        $loader = $this->createMock(CustomPriceLoader::class);
        $loader->expects(static::never())->method('customerHasCustomPrices');

        $event = new HttpCacheCookieEvent(new Request(), Generator::generateSalesChannelContext(overrides: ['customer' => null]), []);
        (new HttpCacheSubscriber($loader))->onCacheCookie($event);

        static::assertNull($event->get(HttpCacheSubscriber::CACHE_PART));
    }
}
