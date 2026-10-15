<?php

namespace Tests\Concerns;

use App\Models\Personnel;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;

/**
 * Reads a downloaded PDF report as the HTML it is rendered from: the request
 * goes through the real route and controller (validation, campus/person
 * scoping, logging), the data handed to the PDF view is captured, and that
 * view is rendered again as HTML for the assertions — dompdf's binary output
 * isn't searchable.
 */
trait ReadsReportHtml
{
    /**
     * @return array{html: string, response: TestResponse}
     */
    protected function capturePdf(Personnel $as, string $view, string $url): array
    {
        $captured = null;
        View::composer($view, function ($v) use (&$captured) { $captured = $v->getData(); });

        $response = $this->actingAs($as)->get($url)->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'), $url);
        $this->assertNotNull($captured, "$view was not rendered for $url");

        return ['html' => view($view, $captured)->render(), 'response' => $response];
    }

    /** A module period report (Generate Report modal). */
    protected function periodPdfHtml(Personnel $as, string $module, array $query): string
    {
        return $this->capturePdf($as, 'reports.detailed-pdf', route('reports.module.pdf', ['module' => $module] + $query))['html'];
    }

    /** The Consolidated DRRM Report. */
    protected function consolidatedPdfHtml(Personnel $as, array $query): string
    {
        return $this->capturePdf($as, 'reports.consolidated-pdf', route('reports.consolidated.pdf', $query))['html'];
    }

    /**
     * The report's printed text: no markup, no stylesheet (its comments never
     * print), entities decoded ("SMS &amp; …" → "SMS & …").
     */
    protected function reportText(string $html): string
    {
        $html = preg_replace('#<style\b[^>]*>.*?</style>#si', '', $html);

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
