<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\MarketDataUnavailableException;
use App\MarketData\MarketDataManager;
use App\Repository\StockRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:market-data:sync-ohlc', description: 'Refresh daily OHLC candles.')]
final class SyncMarketOhlcCommand extends Command
{
    public function __construct(
        private readonly StockRepository $stockRepository,
        private readonly MarketDataManager $marketDataManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbol', null, InputOption::VALUE_OPTIONAL, 'Limit sync to one symbol, e.g. NVDA')
            ->addOption('from', null, InputOption::VALUE_OPTIONAL, 'Start date, YYYY-MM-DD')
            ->addOption('to', null, InputOption::VALUE_OPTIONAL, 'End date, YYYY-MM-DD');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $from = new \DateTimeImmutable((string) ($input->getOption('from') ?: '-10 years'), new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable((string) ($input->getOption('to') ?: 'today'), new \DateTimeZone('UTC'));
        $symbol = $input->getOption('symbol');
        $stocks = [];

        if (is_string($symbol) && $symbol !== '') {
            $stock = $this->stockRepository->findOneBySymbol($symbol);
            if ($stock === null) {
                $io->error(sprintf('Stock %s was not found.', strtoupper($symbol)));

                return Command::FAILURE;
            }
            $stocks = [$stock];
        } else {
            $stocks = $this->stockRepository->findOwnedOrWatchedStocks();
        }

        $synced = 0;
        $failed = 0;
        foreach ($stocks as $stock) {
            try {
                $candles = $this->marketDataManager->getOhlc($stock, $from, $to, 'daily');
                ++$synced;

                $io->writeln(sprintf('%s: %d candle(s).', $stock->getSymbol(), count($candles)));
            } catch (MarketDataUnavailableException $exception) {
                ++$failed;
                $io->warning(sprintf('%s: chart data unavailable.', $stock->getSymbol()));
                if ($output->isVerbose() && $exception->getPrevious() !== null) {
                    $io->writeln(sprintf('<comment>%s</comment>', $exception->getPrevious()->getMessage()));
                }
            }
        }

        $message = sprintf('Synced OHLC for %d stock(s). Failed: %d.', $synced, $failed);
        if ($failed > 0) {
            $io->error($message);

            return Command::FAILURE;
        }

        $io->success($message);

        return Command::SUCCESS;
    }
}
