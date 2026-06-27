<?php

declare(strict_types=1);

namespace App\MarketData;

use App\Dto\OhlcDto;
use App\Dto\QuoteDto;
use App\Entity\Stock;
use App\Exception\MarketDataProviderException;
use App\Service\DecimalMath;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class TwelveDataProvider implements MarketDataProviderInterface
{
    private const PROVIDER = 'twelvedata';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(string:TWELVEDATA_API_KEY)%')]
        private string $apiKey,
        #[Autowire('%env(string:TWELVEDATA_BASE_URL)%')]
        private string $baseUrl = 'https://api.twelvedata.com',
    ) {
    }

    public function supports(Stock $stock): bool
    {
        return trim($this->apiKey) !== '' && $this->supportsSymbol($stock);
    }

    public function getCurrentQuote(Stock $stock): QuoteDto
    {
        $this->assertSupported($stock);

        $data = $this->request('/quote', [
            'symbol' => $this->symbol($stock),
        ]);

        return $this->quoteDto($stock, $data);
    }

    /**
     * @return list<OhlcDto>
     */
    public function getDailyOhlc(Stock $stock, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $this->assertSupported($stock);

        $data = $this->request('/time_series', [
            'symbol' => $this->symbol($stock),
            'interval' => '1day',
            'start_date' => \DateTimeImmutable::createFromInterface($from)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),
            'end_date' => \DateTimeImmutable::createFromInterface($to)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),
            'outputsize' => '5000',
            'order' => 'ASC',
        ]);

        if (!isset($data['values']) || !is_array($data['values']) || $data['values'] === []) {
            throw new MarketDataProviderException('Twelve Data returned no candle data for this symbol and date range.');
        }

        $candles = [];
        foreach ($data['values'] as $row) {
            if (!is_array($row)) {
                throw new MarketDataProviderException('Twelve Data returned an invalid candle response.');
            }

            foreach (['datetime', 'open', 'high', 'low', 'close'] as $key) {
                if (!isset($row[$key]) || ($key !== 'datetime' && !is_numeric($row[$key]))) {
                    throw new MarketDataProviderException('Twelve Data candle data is invalid.');
                }
            }

            $candles[] = new OhlcDto(
                $stock->getSymbol(),
                new \DateTimeImmutable((string) $row['datetime'], new \DateTimeZone('UTC')),
                DecimalMath::normalize((string) $row['open']),
                DecimalMath::normalize((string) $row['high']),
                DecimalMath::normalize((string) $row['low']),
                DecimalMath::normalize((string) $row['close']),
                isset($row['volume']) && is_numeric($row['volume']) ? (int) $row['volume'] : null,
                self::PROVIDER,
            );
        }

        usort($candles, static fn (OhlcDto $left, OhlcDto $right): int => $left->date <=> $right->date);

        return $candles;
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function request(string $path, array $query): array
    {
        try {
            $response = $this->httpClient->request('GET', rtrim($this->baseUrl, '/').$path, [
                'query' => $query + ['apikey' => $this->apiKey],
                'timeout' => 15,
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable $exception) {
            throw new MarketDataProviderException('Twelve Data market data request failed.', previous: $exception);
        }

        if (!is_array($data)) {
            throw new MarketDataProviderException('Twelve Data returned an invalid response.');
        }

        if (($data['status'] ?? null) === 'error' || isset($data['code']) && (int) $data['code'] >= 400) {
            throw new MarketDataProviderException(sprintf(
                'Twelve Data returned an error response%s%s.',
                isset($data['code']) ? ' code='.(string) $data['code'] : '',
                isset($data['message']) && is_scalar($data['message']) ? ' message='.(string) $data['message'] : '',
            ));
        }

        return $data;
    }

    private function assertSupported(Stock $stock): void
    {
        if (!$this->supports($stock)) {
            throw new MarketDataProviderException(sprintf('Twelve Data is not configured or does not support %s.', $stock->getSymbol()));
        }
    }

    private function symbol(Stock $stock): string
    {
        $symbol = strtoupper(trim($stock->getSymbol()));

        return str_ends_with($symbol, '.US') ? substr($symbol, 0, -3) : $symbol;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function quoteDto(Stock $stock, array $data): QuoteDto
    {
        $price = $data['close'] ?? $data['price'] ?? null;
        if (!is_numeric($price) || (float) $price <= 0.0) {
            throw new MarketDataProviderException('Twelve Data returned no quote price for this symbol.');
        }

        $marketTime = null;
        $timestamp = $data['timestamp'] ?? null;
        if (is_numeric($timestamp) && (int) $timestamp > 0) {
            $marketTime = (new \DateTimeImmutable('@'.(int) $timestamp))->setTimezone(new \DateTimeZone('UTC'));
        } elseif (isset($data['datetime']) && is_string($data['datetime']) && $data['datetime'] !== '') {
            $marketTime = new \DateTimeImmutable($data['datetime'], new \DateTimeZone('UTC'));
        }

        return new QuoteDto(
            $stock->getSymbol(),
            DecimalMath::normalize((string) $price),
            isset($data['change']) && is_numeric($data['change']) ? DecimalMath::normalize((string) $data['change']) : null,
            isset($data['percent_change']) && is_numeric($data['percent_change']) ? DecimalMath::normalize((string) $data['percent_change'], 4) : null,
            isset($data['currency']) && is_string($data['currency']) && $data['currency'] !== '' ? $data['currency'] : $stock->getCurrency(),
            $marketTime,
            self::PROVIDER,
        );
    }

    private function supportsSymbol(Stock $stock): bool
    {
        $symbol = strtoupper(trim($stock->getSymbol()));
        if ($symbol === '') {
            return false;
        }

        return !str_contains($symbol, '.') || str_ends_with($symbol, '.US');
    }
}
