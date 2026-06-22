<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\MarketDataUnavailableException;
use App\MarketData\MarketDataManager;
use App\Repository\StockRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:market-data:sync-quotes', description: 'Refresh current quotes for owned and watched stocks.')]
final class SyncMarketQuotesCommand extends Command
{
    public function __construct(
        private readonly StockRepository $stockRepository,
        private readonly MarketDataManager $marketDataManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $synced = 0;
        $failed = 0;

        foreach ($this->stockRepository->findOwnedOrWatchedStocks() as $stock) {
            try {
                $this->marketDataManager->getCurrentQuote($stock);
                ++$synced;
            } catch (MarketDataUnavailableException $exception) {
                ++$failed;
                $io->warning(sprintf('%s: market data unavailable.', $stock->getSymbol()));
                if ($output->isVerbose() && $exception->getPrevious() !== null) {
                    $io->writeln(sprintf('<comment>%s</comment>', $exception->getPrevious()->getMessage()));
                }
            }
        }

        $message = sprintf('Synced %d quote(s). Failed: %d.', $synced, $failed);
        if ($failed > 0) {
            $io->error($message);

            return Command::FAILURE;
        }

        $io->success($message);

        return Command::SUCCESS;
    }
}
