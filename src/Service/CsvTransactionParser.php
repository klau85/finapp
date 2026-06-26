<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BrokerAccount;
use App\Entity\Transaction;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class CsvTransactionParser
{
    private const REQUIRED_COLUMNS = ['date', 'symbol', 'type', 'quantity', 'price', 'currency', 'fees'];
    private const XTB_REQUIRED_COLUMNS = ['type', 'ticker', 'time', 'comment', 'amount'];
    private const XTB_EUR_SUFFIXES = ['.DE', '.FR', '.PA', '.AS'];
    private const REVOLUT_REQUIRED_COLUMNS = ['date', 'ticker', 'type', 'quantity', 'price per share', 'currency'];

    /**
     * @return list<ParsedCsvRow>
     */
    public function parse(UploadedFile $file, ?BrokerAccount $brokerAccount = null): array
    {
        if ($brokerAccount?->getBrokerType() === 'xtb') {
            return $this->parseXtb($file, $brokerAccount->getCurrency());
        }

        if ($brokerAccount?->getBrokerType() === 'revolut') {
            return $this->parseRevolut($file);
        }

        return $this->parseCustom($file, $brokerAccount?->getCurrency() ?? 'USD');
    }

    /**
     * @return list<ParsedCsvRow>
     */
    private function parseCustom(UploadedFile $file, string $brokerCurrency): array
    {
        $handle = fopen($file->getPathname(), 'r');
        if ($handle === false) {
            return [new ParsedCsvRow(1, [], ['Could not read uploaded file.'])];
        }

        $delimiter = $this->detectDelimiter($handle);
        $header = fgetcsv($handle, null, $delimiter, '"', '');
        if ($header === false) {
            fclose($handle);

            return [new ParsedCsvRow(1, [], ['CSV file is empty.'])];
        }

        $header = array_map(static fn (string $column): string => strtolower(trim(ltrim($column, "\xEF\xBB\xBF"))), $header);
        $missingColumns = array_values(array_diff(self::REQUIRED_COLUMNS, $header));
        if ($missingColumns !== []) {
            fclose($handle);

            return [new ParsedCsvRow(1, [], ['Missing required columns: '.implode(', ', $missingColumns).'.'])];
        }

        $columnIndexes = array_flip($header);
        $rows = [];
        $rowNumber = 1;

        while (($csvRow = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            ++$rowNumber;

            if ($csvRow === [null] || $this->isBlankRow($csvRow)) {
                continue;
            }

            $data = [];
            foreach (self::REQUIRED_COLUMNS as $column) {
                $data[$column] = trim((string) ($csvRow[$columnIndexes[$column]] ?? ''));
            }

            if (isset($columnIndexes['amount'])) {
                $data['amount'] = trim((string) ($csvRow[$columnIndexes['amount']] ?? ''));
            }

            $normalized = $this->normalize($data, $brokerCurrency);
            $rows[] = new ParsedCsvRow($rowNumber, $normalized, $this->validate($data));
        }

        fclose($handle);

        if ($rows === []) {
            return [new ParsedCsvRow(1, [], ['CSV file contains no transaction rows.'])];
        }

        return $rows;
    }

    /**
     * @return list<ParsedCsvRow>
     */
    private function parseXtb(UploadedFile $file, string $currency): array
    {
        $handle = fopen($file->getPathname(), 'r');
        if ($handle === false) {
            return [new ParsedCsvRow(1, [], ['Could not read uploaded file.'])];
        }

        $delimiter = $this->detectDelimiter($handle);
        $header = fgetcsv($handle, null, $delimiter, '"', '');
        if ($header === false) {
            fclose($handle);

            return [new ParsedCsvRow(1, [], ['CSV file is empty.'])];
        }

        $header = array_map(static fn (string $column): string => strtolower(trim(ltrim($column, "\xEF\xBB\xBF"))), $header);
        $missingColumns = array_values(array_diff(self::XTB_REQUIRED_COLUMNS, $header));
        if ($missingColumns !== []) {
            fclose($handle);

            return [new ParsedCsvRow(1, [], ['Missing required XTB columns: '.implode(', ', $missingColumns).'.'])];
        }

        $columnIndexes = array_flip($header);
        $rows = [];
        $rowNumber = 1;

        while (($csvRow = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            ++$rowNumber;

            if ($csvRow === [null] || $this->isBlankRow($csvRow)) {
                continue;
            }

            $xtbData = [];
            foreach (self::XTB_REQUIRED_COLUMNS as $column) {
                $xtbData[$column] = trim((string) ($csvRow[$columnIndexes[$column]] ?? ''));
            }

            [$data, $errors] = $this->normalizeXtb($xtbData, $currency);
            $rows[] = new ParsedCsvRow($rowNumber, $data, $errors);
        }

        fclose($handle);

        if ($rows === []) {
            return [new ParsedCsvRow(1, [], ['CSV file contains no transaction rows.'])];
        }

        return $rows;
    }

    /**
     * @return list<ParsedCsvRow>
     */
    private function parseRevolut(UploadedFile $file): array
    {
        $handle = fopen($file->getPathname(), 'r');
        if ($handle === false) {
            return [new ParsedCsvRow(1, [], ['Could not read uploaded file.'])];
        }

        $delimiter = $this->detectDelimiter($handle);
        $header = fgetcsv($handle, null, $delimiter, '"', '');
        if ($header === false) {
            fclose($handle);

            return [new ParsedCsvRow(1, [], ['CSV file is empty.'])];
        }

        $header = array_map(static fn (string $column): string => strtolower(trim(ltrim($column, "\xEF\xBB\xBF"))), $header);
        $missingColumns = array_values(array_diff(self::REVOLUT_REQUIRED_COLUMNS, $header));
        if ($missingColumns !== []) {
            fclose($handle);

            return [new ParsedCsvRow(1, [], ['Missing required Revolut columns: '.implode(', ', $missingColumns).'.'])];
        }

        $columnIndexes = array_flip($header);
        $rows = [];
        $rowNumber = 1;

        while (($csvRow = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            ++$rowNumber;

            if ($csvRow === [null] || $this->isBlankRow($csvRow)) {
                continue;
            }

            $revolutData = [];
            foreach (self::REVOLUT_REQUIRED_COLUMNS as $column) {
                $revolutData[$column] = trim((string) ($csvRow[$columnIndexes[$column]] ?? ''));
            }

            [$data, $errors] = $this->normalizeRevolut($revolutData);
            $rows[] = new ParsedCsvRow($rowNumber, $data, $errors);
        }

        fclose($handle);

        if ($rows === []) {
            return [new ParsedCsvRow(1, [], ['CSV file contains no transaction rows.'])];
        }

        return $rows;
    }

    /**
     * @param list<mixed> $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, string> $data
     * @return array<string, string>
     */
    private function normalize(array $data, string $brokerCurrency): array
    {
        $normalized = [
            'date' => $data['date'],
            'symbol' => strtoupper($data['symbol']),
            'type' => strtoupper($data['type']),
            'quantity' => $this->isDecimal($data['quantity']) ? DecimalMath::normalize($data['quantity']) : $data['quantity'],
            'price' => $this->isDecimal($data['price']) ? DecimalMath::normalize($data['price']) : $data['price'],
            'currency' => strtoupper($data['currency']),
            'fees' => $this->isDecimal($data['fees']) ? DecimalMath::normalize($data['fees']) : $data['fees'],
        ];

        if (($data['amount'] ?? '') !== '' && ($brokerAmount = $this->normalizeAbsoluteDecimal($data['amount'])) !== null) {
            $normalized['brokerAmount'] = $brokerAmount;
            $normalized['brokerCurrency'] = strtoupper($brokerCurrency);
        }

        return $normalized;
    }

    /**
     * @param array<string, string> $data
     * @return list<string>
     */
    private function validate(array $data): array
    {
        $errors = [];

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $data['date'], new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $data['date']) {
            $errors[] = 'date must use YYYY-MM-DD.';
        }

        if ($data['symbol'] === '') {
            $errors[] = 'symbol is required.';
        }

        if (!in_array(strtoupper($data['type']), ['BUY', 'SELL'], true)) {
            $errors[] = 'type must be BUY or SELL.';
        }

        foreach (['quantity', 'price'] as $field) {
            if (!$this->isDecimal($data[$field]) || DecimalMath::cmp($data[$field], '0.00000000') <= 0) {
                $errors[] = $field.' must be a positive decimal.';
            }
        }

        if (!$this->isDecimal($data['fees']) || DecimalMath::cmp($data['fees'], '0.00000000') < 0) {
            $errors[] = 'fees must be zero or a positive decimal.';
        }

        if (!preg_match('/^[A-Z]{3}$/', strtoupper($data['currency']))) {
            $errors[] = 'currency must be a 3-letter code.';
        } elseif (strtoupper($data['currency']) !== 'USD') {
            $errors[] = 'custom CSV import supports USD currency only.';
        }

        if (($data['amount'] ?? '') !== '' && $this->normalizeAbsoluteDecimal($data['amount']) === null) {
            $errors[] = 'amount must be a decimal value.';
        }

        return $errors;
    }

    /**
     * @param array<string, string> $data
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function normalizeXtb(array $data, string $currency): array
    {
        $errors = [];

        $type = match (strtolower($data['type'])) {
            'stock purchase' => 'BUY',
            'stock sell' => 'SELL',
            default => null,
        };

        if ($type === null) {
            $errors[] = 'type must be Stock purchase or Stock sell.';
        }

        $date = $this->parseXtbDate($data['time']);
        if (!$date instanceof \DateTimeImmutable) {
            $errors[] = 'time must use YYYY-MM-DD HH:MM:SS.';
        }

        [$symbol, $priceCurrency, $tickerError] = $this->mapXtbTicker($data['ticker']);
        if ($tickerError !== null) {
            $errors[] = $tickerError;
        }

        if (trim($data['ticker']) === '') {
            $errors[] = 'ticker is required.';
        }

        $quantity = '';
        $price = '';
        if (preg_match('/^(?:OPEN|CLOSE)\s+\S+\s+(\d+(?:\.\d+)?)(?:\/\d+(?:\.\d+)?)?\s+@\s+(\d+(?:\.\d+)?)$/i', $data['comment'], $matches) === 1) {
            $quantity = DecimalMath::normalize($matches[1]);
            $price = DecimalMath::normalize($matches[2]);

            if (DecimalMath::cmp($quantity, '0.00000000') <= 0) {
                $errors[] = 'quantity must be a positive decimal.';
            }

            if (DecimalMath::cmp($price, '0.00000000') <= 0) {
                $errors[] = 'price must be a positive decimal.';
            }
        } else {
            $errors[] = 'comment must contain quantity and price in the expected XTB format.';
        }

        $brokerAmount = $this->normalizeAbsoluteDecimal($data['amount']);
        if ($brokerAmount === null) {
            $errors[] = 'amount must be a decimal value.';
            $brokerAmount = $data['amount'];
        }

        return [[
            'date' => $date?->format('Y-m-d') ?? $data['time'],
            'transactionDate' => $date?->format('Y-m-d H:i:s') ?? $data['time'],
            'symbol' => $symbol,
            'type' => $type ?? $data['type'],
            'quantity' => $quantity,
            'price' => $price,
            'currency' => $priceCurrency,
            'fees' => '0.00000000',
            'brokerAmount' => $brokerAmount,
            'brokerCurrency' => strtoupper($currency),
        ], $errors];
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function mapXtbTicker(string $value): array
    {
        $ticker = strtoupper(trim($value));
        if ($ticker === '') {
            return ['', 'USD', null];
        }

        if (str_ends_with($ticker, '.US')) {
            return [substr($ticker, 0, -3), 'USD', null];
        }

        foreach (self::XTB_EUR_SUFFIXES as $suffix) {
            if (str_ends_with($ticker, $suffix)) {
                return [$ticker, 'EUR', null];
            }
        }

        if (!str_contains($ticker, '.')) {
            return [$ticker, 'USD', null];
        }

        return [$ticker, 'USD', 'unsupported XTB ticker suffix. Supported suffixes are .US, .DE, .FR, .PA, .AS, or no suffix.'];
    }

    /**
     * @param array<string, string> $data
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function normalizeRevolut(array $data): array
    {
        $errors = [];

        $type = match (strtoupper($data['type'])) {
            'BUY - MARKET' => Transaction::TYPE_BUY,
            'SELL - MARKET' => Transaction::TYPE_SELL,
            'STOCK SPLIT' => Transaction::TYPE_STOCK_SPLIT,
            default => null,
        };

        if ($type === null) {
            $errors[] = 'type must be BUY - MARKET, SELL - MARKET, or STOCK SPLIT.';
        }

        $date = $this->parseRevolutDate($data['date']);
        if (!$date instanceof \DateTimeImmutable) {
            $errors[] = 'date must use a valid Revolut ISO timestamp.';
        }

        $symbol = strtoupper(trim($data['ticker']));
        if ($symbol === '') {
            $errors[] = 'ticker is required.';
        }

        $quantity = $this->isSignedDecimal($data['quantity']) ? DecimalMath::normalize($data['quantity']) : $data['quantity'];
        if ($type === Transaction::TYPE_STOCK_SPLIT) {
            if (!$this->isSignedDecimal($data['quantity']) || DecimalMath::cmp($quantity, DecimalMath::zero()) === 0) {
                $errors[] = 'quantity must be a non-zero decimal for stock splits.';
            }
        } elseif (!$this->isDecimal($data['quantity']) || DecimalMath::cmp($data['quantity'], '0.00000000') <= 0) {
            $errors[] = 'quantity must be a positive decimal.';
        }

        $price = '';
        $priceCurrency = '';
        if ($type === Transaction::TYPE_STOCK_SPLIT && $data['price per share'] === '') {
            $price = DecimalMath::zero();
        } elseif (preg_match('/^([A-Z]{3})\s+(\d+(?:\.\d+)?)$/i', $data['price per share'], $matches) === 1) {
            $priceCurrency = strtoupper($matches[1]);
            $price = DecimalMath::normalize($matches[2]);

            if ($priceCurrency !== 'USD') {
                $errors[] = 'price per share currency must be USD.';
            }

            if (DecimalMath::cmp($price, '0.00000000') <= 0) {
                $errors[] = 'price must be a positive decimal.';
            }
        } else {
            $errors[] = 'price per share must use format "USD 50.56".';
        }

        $currency = strtoupper($data['currency']);
        if ($currency !== 'USD') {
            $errors[] = 'currency must be USD.';
        }

        return [[
            'date' => $date?->format('Y-m-d') ?? $data['date'],
            'transactionDate' => $date?->format('Y-m-d H:i:s') ?? $data['date'],
            'symbol' => $symbol,
            'type' => $type ?? $data['type'],
            'quantity' => $quantity,
            'price' => $price,
            'currency' => $currency,
            'fees' => '0.00000000',
        ], $errors];
    }

    private function parseXtbDate(string $value): ?\DateTimeImmutable
    {
        $timezone = new \DateTimeZone('UTC');
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{1,2}):(\d{1,2}):(\d{1,2})$/', $value, $matches) !== 1) {
            return null;
        }

        $hour = (int) $matches[2];
        $minute = (int) $matches[3];
        $second = (int) $matches[4];
        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            sprintf('%s %02d:%02d:%02d', $matches[1], $hour, $minute, $second),
            $timezone,
        );
        $errors = \DateTimeImmutable::getLastErrors();

        return $date instanceof \DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            ? $date
            : null;
    }

    private function parseRevolutDate(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/', $value) !== 1) {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * @param resource $handle
     */
    private function detectDelimiter($handle): string
    {
        $line = fgets($handle);
        if ($line === false) {
            rewind($handle);

            return ',';
        }

        rewind($handle);

        $delimiters = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($delimiters);

        $delimiter = array_key_first($delimiters);

        return is_string($delimiter) && $delimiters[$delimiter] > 0 ? $delimiter : ',';
    }

    private function isDecimal(string $value): bool
    {
        return preg_match('/^\d+(?:\.\d+)?$/', trim($value)) === 1;
    }

    private function isSignedDecimal(string $value): bool
    {
        return preg_match('/^-?\d+(?:\.\d+)?$/', trim($value)) === 1;
    }

    private function normalizeAbsoluteDecimal(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $value = str_replace(["\xc2\xa0", ' ', "'"], '', $value);
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $value = str_replace($thousandsSeparator, '', $value);
            $value = str_replace($decimalSeparator, '.', $value);
        } elseif ($lastComma !== false) {
            $value = str_replace(',', '.', $value);
        }

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) !== 1) {
            return null;
        }

        return DecimalMath::normalize(ltrim($value, '-'));
    }
}
