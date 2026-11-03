<?php

namespace App\Domain\Sources\Services;

use App\Domain\Sources\Exceptions\InvalidPdfException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

final class PdfInspector
{
    /** @return array{sha256: string, pages: int, size_bytes: int} */
    public function inspect(string $contents): array
    {
        if ($contents === '' || strlen($contents) > config('print_hub.max_pdf_bytes')) {
            throw new InvalidPdfException('The PDF is empty or exceeds the size limit.');
        }

        try {
            $pages = (new Fpdi)->setSourceFile(StreamReader::createByString($contents));
        } catch (Throwable $exception) {
            throw new InvalidPdfException('Dokumen bukan PDF yang dapat dibaca.', previous: $exception);
        }

        if ($pages < 1) {
            throw new InvalidPdfException('The PDF has no pages.');
        }

        return [
            'sha256' => hash('sha256', $contents),
            'pages' => $pages,
            'size_bytes' => strlen($contents),
        ];
    }
}
