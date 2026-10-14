<?php declare(strict_types=1);

namespace TaskCustomPricing\Tests\Integration\Command;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Test\Product\ProductBuilder;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Test\Integration\Builder\Customer\CustomerBuilder;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TaskCustomPricing\Command\ImportCustomPricesCommand;
use TaskCustomPricing\Core\Content\CustomerCustomPrice\CustomerCustomPriceEntity;

class ImportCustomPricesCommandTest extends TestCase
{
    use IntegrationTestBehaviour;

    private IdsCollection $ids;

    private string $file;

    protected function setUp(): void
    {
        $this->ids = new IdsCollection();
        $this->file = tempnam(sys_get_temp_dir(), 'custom-prices') ?: throw new \RuntimeException('no temp file');

        $context = Context::createDefaultContext();
        static::getContainer()->get('product.repository')->create([
            (new ProductBuilder($this->ids, 'IMPORT-P1'))->price(50.0)->build(),
        ], $context);
        static::getContainer()->get('customer.repository')->create([
            (new CustomerBuilder($this->ids, 'import-customer'))->build(),
        ], $context);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testImportCreatesAndThenUpdatesThePrice(): void
    {
        $email = $this->ids->get('import-customer') . '@example.com';

        static::assertSame(Command::SUCCESS, $this->import([['customer_email' => $email, 'product_number' => 'IMPORT-P1', 'custom_price' => 40]]));
        static::assertSame(40.0, $this->loadPrice()?->getCustomPrice());

        // Importing again must update, not duplicate
        static::assertSame(Command::SUCCESS, $this->import([['customer_email' => $email, 'product_number' => 'IMPORT-P1', 'custom_price' => '35.5']]));
        static::assertSame(35.5, $this->loadPrice()?->getCustomPrice());
        static::assertSame(1, $this->countPrices());
    }

    public function testInvalidRowsAreSkippedAndReported(): void
    {
        $email = $this->ids->get('import-customer') . '@example.com';

        $exitCode = $this->import([
            ['customer_email' => 'nobody@example.com', 'product_number' => 'IMPORT-P1', 'custom_price' => 10],
            ['customer_email' => $email, 'product_number' => 'DOES-NOT-EXIST', 'custom_price' => 10],
            ['customer_email' => $email, 'product_number' => 'IMPORT-P1', 'custom_price' => -1],
            ['customer_email' => $email, 'product_number' => 'IMPORT-P1'],
            ['customer_email' => $email, 'product_number' => 'IMPORT-P1', 'custom_price' => 12],
        ]);

        static::assertSame(Command::FAILURE, $exitCode);
        static::assertSame(12.0, $this->loadPrice()?->getCustomPrice(), 'valid rows are still imported');
    }

    public function testInvalidJsonFails(): void
    {
        file_put_contents($this->file, '{not json');
        $tester = new CommandTester(static::getContainer()->get(ImportCustomPricesCommand::class));

        static::assertSame(Command::FAILURE, $tester->execute(['file' => $this->file]));
        static::assertStringContainsString('not valid JSON', $tester->getDisplay());
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function import(array $rows): int
    {
        file_put_contents($this->file, json_encode($rows, \JSON_THROW_ON_ERROR));

        return (new CommandTester(static::getContainer()->get(ImportCustomPricesCommand::class)))->execute(['file' => $this->file]);
    }

    private function loadPrice(): ?CustomerCustomPriceEntity
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('customerId', $this->ids->get('import-customer')));
        $price = static::getContainer()->get('customer_custom_price.repository')->search($criteria, Context::createDefaultContext())->first();

        return $price instanceof CustomerCustomPriceEntity ? $price : null;
    }

    private function countPrices(): int
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('customerId', $this->ids->get('import-customer')));

        return static::getContainer()->get('customer_custom_price.repository')->search($criteria, Context::createDefaultContext())->getTotal();
    }
}
