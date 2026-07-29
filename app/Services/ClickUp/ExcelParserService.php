<?php

namespace App\Services\ClickUp;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class AnonymousArrayImport implements ToArray
{
    public function array(array $array)
    {
        return $array;
    }
}

class ExcelParserService
{
    /**
     * Parses the uploaded Excel file and returns rows as associative arrays based on headers.
     */
    public function parseFile($file): array
    {
        $array = Excel::toArray(new AnonymousArrayImport(), $file);
        $rows = $array[0] ?? [];

        if (empty($rows)) {
            throw new RuntimeException('File Excel kosong atau format tidak sesuai.');
        }

        // Search for the header row
        $headerRowIndex = 0;
        $maxSearch = min(20, count($rows));

        for ($i = 0; $i < $maxSearch; $i++) {
            $rowString = strtolower(implode(' ', array_map('strval', $rows[$i])));
            if (str_contains($rowString, 'request id') ||
                str_contains($rowString, 'nomor tiket') ||
                str_contains($rowString, 'ticket number') ||
                str_contains($rowString, 'ticket_number') ||
                str_contains($rowString, 'subject')) {
                $headerRowIndex = $i;
                break;
            }
        }

        // Slice the rows starting from the header row index
        $tableRows = array_slice($rows, $headerRowIndex);

        if (empty($tableRows)) {
            throw new RuntimeException('Tidak dapat menemukan baris header pada file Excel.');
        }

        $rawHeaders = array_shift($tableRows);
        $headers = [];

        // Clean headers
        foreach ($rawHeaders as $idx => $h) {
            $val = trim((string) $h);
            $headers[] = $val !== '' ? $val : 'Column_' . $idx;
        }

        $associativeRows = [];
        foreach ($tableRows as $row) {
            if (count($headers) === count($row)) {
                $associativeRows[] = array_combine($headers, $row);
            } else {
                // Handle mismatch
                $paddedRow = array_pad($row, count($headers), '');
                $paddedRow = array_slice($paddedRow, 0, count($headers));
                $associativeRows[] = array_combine($headers, $paddedRow);
            }
        }

        return $associativeRows;
    }
}
