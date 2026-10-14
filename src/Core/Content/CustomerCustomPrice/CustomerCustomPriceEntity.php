<?php declare(strict_types=1);

namespace TaskCustomPricing\Core\Content\CustomerCustomPrice;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class CustomerCustomPriceEntity extends Entity
{
    use EntityIdTrait;

    protected string $customerId;

    protected string $productId;

    protected string $productVersionId;

    protected float $customPrice;

    protected ?CustomerEntity $customer = null;

    protected ?ProductEntity $product = null;

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function setCustomerId(string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function setProductId(string $productId): void
    {
        $this->productId = $productId;
    }

    public function getProductVersionId(): string
    {
        return $this->productVersionId;
    }

    public function setProductVersionId(string $productVersionId): void
    {
        $this->productVersionId = $productVersionId;
    }

    public function getCustomPrice(): float
    {
        return $this->customPrice;
    }

    public function setCustomPrice(float $customPrice): void
    {
        $this->customPrice = $customPrice;
    }

    public function getCustomer(): ?CustomerEntity
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerEntity $customer): void
    {
        $this->customer = $customer;
    }

    public function getProduct(): ?ProductEntity
    {
        return $this->product;
    }

    public function setProduct(?ProductEntity $product): void
    {
        $this->product = $product;
    }
}
