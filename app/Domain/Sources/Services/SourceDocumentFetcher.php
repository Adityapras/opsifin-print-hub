<?php

namespace App\Domain\Sources\Services;

use App\Domain\Sources\Exceptions\InvalidPdfException;
use App\Domain\Sources\Models\SourceConnection;

final class SourceDocumentFetcher
{
    private const PDF_HEADER = '%PDF-';

    private const MAX_TOLERATED_PREFIX_BYTES = 16;

    public function __construct(
        private readonly LegacyOpsifinClient $client,
        private readonly PdfInspector $pdfInspector,
    ) {}

    /** @return array{contents: string, sha256: string, pages: int, size_bytes: int, source_url: string} */
    public function fetch(SourceConnection $connection, string $url): array
    {
        $contents = $this->normalizePdfHeader(
            $this->client->downloadPdf($connection, $url),
        );

        return ['contents' => $contents, 'source_url' => $url]
            + $this->pdfInspector->inspect($contents);
    }

    private function normalizePdfHeader(string $contents): string
    {
        if ($contents === '' || str_starts_with($contents, self::PDF_HEADER)) {
            return $contents;
        }

        $searchWindow = substr(
            $contents,
            0,
            self::MAX_TOLERATED_PREFIX_BYTES + strlen(self::PDF_HEADER),
        );
        $headerPosition = strpos($searchWindow, self::PDF_HEADER);

        if ($headerPosition === false || $headerPosition > self::MAX_TOLERATED_PREFIX_BYTES) {
            throw new InvalidPdfException('The document does not have a valid PDF header.');
        }

        $prefix = substr($contents, 0, $headerPosition);

        if (preg_match('/\A(?:\xEF\xBB\xBF)?[ \t\r\n]*\z/D', $prefix) !== 1) {
            // Hanya noise transport yang aman dibuang. HTML atau payload lain yang kebetulan
            // memuat teks %PDF- harus tetap ditolak agar tidak disamarkan sebagai dokumen.
            throw new InvalidPdfException('The document does not have a valid PDF header.');
        }

        return substr($contents, $headerPosition);
    }
}
