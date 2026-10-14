<?php declare(strict_types=1);

namespace TaskCustomPricing\Subscriber;

use Shopware\Core\Framework\Adapter\Cache\Event\HttpCacheCookieEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use TaskCustomPricing\Core\Content\CustomerCustomPrice\CustomPriceLoader;

/**
 * Shopware's HTTP cache key doesn't contain the customer. Without this, a page rendered with
 * one customer's custom price could be served from the cache to another customer.
 */
class HttpCacheSubscriber implements EventSubscriberInterface
{
    public const CACHE_PART = 'task-custom-pricing';

    public function __construct(private readonly CustomPriceLoader $loader)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [HttpCacheCookieEvent::class => 'onCacheCookie'];
    }

    public function onCacheCookie(HttpCacheCookieEvent $event): void
    {
        $customer = $event->context->getCustomer();
        if ($customer === null || !$this->loader->customerHasCustomPrices($customer->getId())) {
            return;
        }

        $event->add(self::CACHE_PART, $customer->getId());
    }
}
