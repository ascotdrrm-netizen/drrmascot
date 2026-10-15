<?php

/*
|--------------------------------------------------------------------------
| Printed documents (dompdf)
|--------------------------------------------------------------------------
|
| Every PDF the system prints uses this one page setup: portrait, the
| official ASCOT letterhead full-bleed behind each page, and the content box
| below. Read by App\Traits\RendersPdfAssets (paper) and the shared shell
| resources/views/reports/pdf/document.blade.php (letterhead, margins).
|
| PAPER. 8.5 x 14 in (Legal, Word paper code 5) — what ASCOT's own memos
| use. For long bond, change it to [8.5, 13]; the letterhead stretches to
| whatever size is set here, exactly as the memo template does.
|
| CONTENT BOX. Measured from the letterhead (1308 x 2000 px) as it lands on
| an 8.5 x 14 page (stretched ~7.7% vertically, like the memo):
|   header artwork ends at the double yellow rule   1.71 in from the top
|   yellow footer band                              13.55 in -> 14.00 in
|   faint building watermark                        12.17 in -> 13.55 in
| so content starts at 1.9 in and stops 0.6 in above the paper's bottom
| (13.4 in); the page-number line sits in the 0.15 in between 13.4 in and
| the band. Text may print over the watermark.
|
*/

return [

    'paper_inches' => [8.5, 14],

    // An opaque RGB copy of images/ascot-letterhead.png (the official artwork,
    // kept as supplied): pixel-identical, but dompdf no longer splits an alpha
    // channel pixel by pixel — ~0.4 s per render instead of ~2.1 s. If the
    // artwork is updated, regenerate the copy (it has no transparency).
    'letterhead' => 'images/ascot-letterhead-print.png',

    // Where the yellow footer band starts, as a fraction of the image's height
    // (row 1935 of 2000). The page-number line is placed just above it.
    'footer_band_top' => 1935 / 2000,

    'margins_inches' => [
        'top' => 1.9,
        'right' => 1.0,
        'bottom' => 0.6,
        'left' => 1.0,
    ],

];
