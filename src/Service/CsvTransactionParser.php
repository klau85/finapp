<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class CsvTransactionParser
{
    private const REQUIRED_COLUMNS = ['date', 'symbol', 'type', 'quantity', 'price', 'currency', 'fees'];

    /**
     * @return list<ParsedCsvRow>
     */
    public function parse(UploadedFile $file): array
    {
        $handle = fopen($file->getPathname(), 'r');
        if ($handle === false) {
            return [new ParsedCsvRow(1, [], ['Could not read uploaded file.'])];
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return [new ParsedCsvRow(1, [], ['CSV file is empty.'])];
        }

        $header = array_map(static fn (string $column): string => strtolower(trim($column)), $header);
        $missingColumns = array_values(array_diff(self::REQUIRED_COLUMNS, $header));
        if ($missingColumns !== []) {
            fclose($handle);

            return [new ParsedCsvRow(1, [], ['Missing required columns: '.implode(', ', $missingColumns).'.'])];
        }

        $columnIndexes = array_flip($header);
        $rows = [];
        $rowNumber = 1;

        while (($csvRow = fgetcsv($handle)) !== false) {
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

    private function isDecimal(string $value): bool
    {
        return preg_match('/^\d+(?:\.\d+)?$/', trim($value)) === 1;
    }
}
