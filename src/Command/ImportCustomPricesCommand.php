<?php declare(strict_types=1);

namespace TaskCustomPricing\Command;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\Events\InvalidateProductCache;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use TaskCustomPricing\Core\Content\CustomerCustomPrice\CustomerCustomPriceCollection;

#[AsCommand(
    name: 'taskcustompricing:import-prices',
    description: 'Imports customer-specific prices from a JSON file',
)]
class ImportCustomPricesCommand extends Command
{
    /**
     * @param EntityRepository<CustomerCustomPriceCollection> $customPriceRepository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $customPriceRepository,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'file',
            InputArgument::REQUIRED,
            'JSON file: [{"customer_email": "...", "product_number": "...", "custom_price": 9.99}, ...]'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $input->getArgument('file');
        if (!\is_string($file)) {
            $io->error('Pass the path to a JSON file.');

            return Command::INVALID;
        }

        $rows = $this->readFile($file, $io);
        if ($rows === null) {
            return Command::FAILURE;
        }

        $payload = [];
        $skipped = 0;
        foreach ($rows as $index => $row) {
            $data = $this->buildPayload($row, $index, $io);
            if ($data === null) {
                ++$skipped;
                continue;
            }

            // The id is derived from customer + product, so importing again updates the price instead of duplicating it
            $payload[$data['id']] = $data;
        }

        if ($payload !== []) {
            $this->customPriceRepository->upsert(array_values($payload), Context::createCLIContext());
            $this->dispatcher->dispatch(new InvalidateProductCache(array_values(array_unique(array_column($payload, 'productId')))));
        }

        $io->success(\sprintf('Imported %d price(s), skipped %d row(s).', \count($payload), $skipped));

        return $skipped > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array<mixed>|null
     */
    private function readFile(string $file, SymfonyStyle $io): ?array
    {
        if (!is_file($file) || !is_readable($file)) {
            $io->error(\sprintf('File "%s" not found or not readable.', $file));

            return null;
        }

        try {
            $rows = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(\sprintf('File "%s" is not valid JSON: %s', $file, $e->getMessage()));

            return null;
        }

        if (!\is_array($rows) || !array_is_list($rows)) {
            $io->error('The JSON file must contain a list of price objects.');

            return null;
        }

        return $rows;
    }

    /**
     * @return array{id: string, customerId: string, productId: string, productVersionId: string, customPrice: float}|null
     */
    private function buildPayload(mixed $row, int $index, SymfonyStyle $io): ?array
    {
        $line = \sprintf('Row %d', $index + 1);

        if (!\is_array($row)
            || !\is_string($row['customer_email'] ?? null)
            || !\is_string($row['product_number'] ?? null)
            || !is_numeric($row['custom_price'] ?? null)
        ) {
            $io->warning($line . ': needs "customer_email", "product_number" and a numeric "custom_price".');

            return null;
        }

        $email = $row['customer_email'];
        $productNumber = $row['product_number'];
        $price = (float) $row['custom_price'];

        if ($price < 0) {
            $io->warning(\sprintf('%s: negative price for %s / %s.', $line, $email, $productNumber));

            return null;
        }

        /** @var list<string> $customerIds */
        $customerIds = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(id)) FROM customer WHERE email = :email AND guest = 0',
            ['email' => $email],
        );
        if (\count($customerIds) !== 1) {
            $io->warning(\sprintf(
                '%s: %s customer account(s) with email "%s" found, expected exactly one.',
                $line,
                \count($customerIds),
                $email,
            ));

            return null;
        }

        $productId = $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM product WHERE product_number = :number AND version_id = :versionId',
            ['number' => $productNumber, 'versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
        );
        if (!\is_string($productId)) {
            $io->warning(\sprintf('%s: product "%s" not found.', $line, $productNumber));

            return null;
        }

        $io->writeln(\sprintf('%s: %s / %s = %.2f', $line, $email, $productNumber, $price));

        return [
            'id' => Uuid::fromStringToHex($customerIds[0] . '-' . $productId),
            'customerId' => $customerIds[0],
            'productId' => $productId,
            'productVersionId' => Defaults::LIVE_VERSION,
            'customPrice' => round($price, 2),
        ];
    }
}
