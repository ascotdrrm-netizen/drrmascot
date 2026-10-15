<?php

namespace App\Traits;

use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Illuminate\Support\Facades\Storage;

trait RendersPdfAssets
{
    /**
     * Render a report view whose footer prints "Page X of Y", where X comes
     * from dompdf's own working per-page counter(page) CSS counter, but Y
     * (the total page count) is NOT reliably available via CSS in this
     * dompdf version — counter(pages) always resolves to 0 here, and @page
     * margin boxes aren't rendered at all (confirmed empirically). The only
     * reliable source of the true total is dompdf's own canvas API, which
     * is only known AFTER a render pass completes. So this renders once
     * (throwaway) purely to learn the page count, then renders again for
     * real with that count baked in as a plain value the footer can print.
     *
     * Every PDF is portrait on the one paper size in config/pdf.php (the
     * ASCOT letterhead is portrait-only), so callers don't choose a size.
     *
     * @param array<string, mixed> $data
     */
    protected function loadPdfViewWithPageCount(string $view, array $data): DomPdfWrapper
    {
        $paper = self::pdfPaperPoints();

        // Font subsetting: embed only the glyphs used from the bundled DejaVu
        // Sans instead of the whole font files (keeps each PDF small).
        $probe = \Barryvdh\DomPDF\Facade\Pdf::loadView($view, $data + ['totalPages' => 1])
            ->setOption('isFontSubsettingEnabled', true)
            ->setPaper($paper, 'portrait');
        $probe->render();
        $totalPages = $probe->getDomPDF()->getCanvas()->get_page_count();

        return \Barryvdh\DomPDF\Facade\Pdf::loadView($view, $data + ['totalPages' => $totalPages])
            ->setOption('isFontSubsettingEnabled', true)
            ->setPaper($paper, 'portrait');
    }

    /** [0, 0, width, height] in points (72 per inch) from config('pdf.paper_inches'). */
    public static function pdfPaperPoints(): array
    {
        [$width, $height] = config('pdf.paper_inches');

        return [0, 0, $width * 72, $height * 72];
    }

    /**
     * Base64 data URI for a user-uploaded photo on the "public" storage disk,
     * shrunk for printing (App\Support\PdfImage, like drill attachments) so a
     * full-size phone photo doesn't make a one-page record PDF megabytes big.
     * Embedding as base64 (rather than referencing the storage URL/path directly)
     * avoids depending on dompdf's remote-fetch config or OS path quirks.
     */
    protected function storagePhotoDataUri(?string $storagePath): ?string
    {
        // From the records disk (this server, or Supabase Storage).
        return \App\Support\PdfImage::fromStoredFile($storagePath);
    }
}
