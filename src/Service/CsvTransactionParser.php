<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BrokerAccount;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class CsvTransactionParser
{
    private const REQUIRED_COLUMNS = ['date', 'symbol', 'type', 'quantity', 'price', 'currency', 'fees'];
    private const XTB_REQUIRED_COLUMNS = ['type', 'ticker', 'time', 'comment'];

    /**
     * @return list<ParsedCsvRow>
     */
    public function parse(UploadedFile $file, ?BrokerAccount $brokerAccount = null): array
    {
        if ($brokerAccount?->getBrokerType() === 'xtb') {
            return $this->parseXtb($file, $brokerAccount->getCurrency());
        }

        return $this->parseCustom($file);
    }

    /**
     * @return list<ParsedCsvRow>
     */
    private function parseCustom(UploadedFile $file): array
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

            $rows[] = new ParsedCsvRow($rowNumber, $this->normalize($data), $this->validate($data));
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
    private function normalize(array $data): array
    {
        return [
            'date' => $data['date'],
            'symbol' => strtoupper($data['symbol']),
            'type' => strtoupper($data['type']),
            'quantity' => $this->isDecimal($data['quantity']) ? DecimalMath::normalize($data['quantity']) : $data['quantity'],
            'price' => $this->isDecimal($data['price']) ? DecimalMath::normalize($data['price']) : $data['price'],
            'currency' => strtoupper($data['currency']),
            'fees' => $this->isDecimal($data['fees']) ? DecimalMath::normalize($data['fees']) : $data['fees'],
        ];
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

        $ticker = strtoupper(trim($data['ticker']));
        $symbol = preg_replace('/\.US$/i', '', $ticker) ?? $ticker;
        if ($ticker === '') {
            $errors[] = 'ticker is required.';
        } elseif (!str_ends_with($ticker, '.US')) {
            $errors[] = 'only US stocks can be imported for now. XTB ticker must end with .US.';
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

        return [[
            'date' => $date?->format('Y-m-d') ?? $data['time'],
            'transactionDate' => $date?->format('Y-m-d H:i:s') ?? $data['time'],
            'symbol' => $symbol,
            'type' => $type ?? $data['type'],
            'quantity' => $quantity,
            'price' => $price,
            'currency' => strtoupper($currency),
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
}
