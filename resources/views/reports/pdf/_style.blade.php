{{--
    Shared stylesheet for every PDF (dompdf). dompdf has no flexbox/grid and
    limited CSS, so layout is tables + widths only. Font: DejaVu Sans, bundled
    with dompdf (vendor/dompdf/dompdf/lib/fonts) and embedded (subset) into
    each PDF, so it renders identically everywhere and covers ₱, –, —, ✓.
--}}
body {
    font-family: "DejaVu Sans", sans-serif;
    font-size: 8.5pt;
    line-height: 1.35;
    color: #1f2933;
}

/* The letterhead (header artwork, footer band, watermark) and the page box
   come from the shell (document.blade.php) and config/pdf.php. */

/* ============================== Footer ============================== */
/* One line in the strip between the content box and the letterhead's yellow
   band; its position is set in document.blade.php from config/pdf.php. */
.doc-footer { position: fixed; left: 0; right: 0; }
.doc-footer table { width: 100%; border-collapse: collapse; }
.doc-footer td { font-size: 6pt; line-height: 1.15; color: #52606d; vertical-align: bottom; padding: 0; white-space: nowrap; }
.doc-footer .note { font-style: italic; }
.doc-footer .right { text-align: right; }
.doc-footer .flag { text-align: center; font-weight: bold; color: #1f2933; letter-spacing: .6px; }
.pagenum:after { content: counter(page); }

/* =========================== Title block ============================ */
.doc-title { text-align: center; font-size: 13.5pt; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; color: #102a1c; margin: 0; }
.doc-subtitle { text-align: center; font-size: 9pt; color: #3e4c59; margin-top: 3px; }

.doc-stamp-wrap { text-align: center; margin-top: 5px; }
.doc-stamp { display: inline-block; padding: 2px 10px; border: 1.5px solid #1f2933; font-size: 9pt; font-weight: bold;
    letter-spacing: 1.5px; text-transform: uppercase; color: #1f2933; }

table.doc-meta { width: 100%; border-collapse: collapse; margin: 10px 0 14px 0; border: .5px solid #b8c4bd; }
table.doc-meta td { padding: 4px 7px; vertical-align: top; border: .5px solid #d5ddd8; width: 33.33%; }
.meta-label { font-size: 6.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: .4px; color: #52606d; }
.meta-value { font-size: 8.5pt; color: #1f2933; }
.meta-note { font-size: 7pt; color: #7b8794; font-style: italic; }

/* ============================= Sections ============================= */
h2.section { font-size: 10pt; font-weight: bold; text-transform: uppercase; letter-spacing: .5px; color: #102a1c;
    margin: 14px 0 6px 0; padding: 3px 6px; background-color: #eef3ef; border-left: 3px solid #1d6b3a; }
h3.subsection { font-size: 9pt; font-weight: bold; color: #1f2933; margin: 10px 0 4px 0; }
h2.section, h3.subsection { page-break-after: avoid; } /* never a heading alone at a page foot */
p.lead { margin: 0 0 6px 0; color: #3e4c59; }
p.caption, .caption { font-size: 7.3pt; color: #52606d; font-style: italic; }
p.empty { margin: 4px 0 10px 0; padding: 8px; border: .5px dashed #b8c4bd; text-align: center; color: #52606d; font-style: italic; }
.notice { margin: 0 0 8px 0; padding: 6px 8px; border: .75px solid #9a7b17; background-color: #fbf6e6; font-size: 8pt; color: #5c4a0e; }

/* ========================= Form field blocks ======================== */
/* Two-column labelled fields for short values; .field-wide spans both. */
table.fields { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
table.fields td { width: 50%; vertical-align: top; padding: 5px 7px; border: .5px solid #d5ddd8; }
table.fields td.field-wide { width: 100%; }
.field-label { font-size: 6.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: .4px; color: #52606d; margin-bottom: 1px; }
.field-value { font-size: 9pt; color: #1f2933; }
.field-text { font-size: 8.5pt; color: #1f2933; }
.not-specified { color: #7b8794; font-style: italic; }

/* ============================== Tables ============================== */
table.data { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
table.data thead { display: table-header-group; } /* repeats on every page */
table.data th { background-color: #e3ece6; color: #102a1c; font-size: 7.3pt; font-weight: bold; text-transform: uppercase;
    letter-spacing: .3px; text-align: left; padding: 4px 5px; border: .5px solid #9aa5b1; }
table.data td { font-size: 8pt; padding: 3.5px 5px; border: .5px solid #b8c4bd; vertical-align: top; }
table.data tbody tr:nth-child(even) td { background-color: #f5f8f6; }
table.data tr { page-break-inside: avoid; }
/* dompdf quirk: if the first body row doesn't fit under the header, the header
   is stranded and stops repeating for the rest of the table — keep them together. */
table.data thead tr { page-break-after: avoid; }
/* Short summary tables (a few grouped rows) — never leave a lone header row. */
.keep-together { page-break-inside: avoid; }
table.data .num, table.data th.num { text-align: right; white-space: nowrap; }
table.data .center { text-align: center; }
table.data .nowrap { white-space: nowrap; }
table.data td.wrap { word-wrap: break-word; }
table.data tr.group td { background-color: #eef3ef; font-weight: bold; color: #102a1c; }
table.data td.none { text-align: center; color: #52606d; font-style: italic; padding: 8px; }
/* Second value in a merged cell (Campus / school, Date / time) — portrait pages are narrow. */
table.data .sub { color: #52606d; font-size: 7.3pt; }
table.data tfoot td { font-weight: bold; background-color: #eef3ef; }

/* Status / risk labels: readable in black & white — the word is always
   printed; weight and border style (not colour alone) distinguish levels. */
.tag { font-size: 7pt; font-weight: bold; text-transform: uppercase; letter-spacing: .3px; padding: 0 3px; border: .5px solid #7b8794; white-space: nowrap; }
.tag-high, .tag-active, .tag-total, .tag-failed { border: 1.2px solid #1f2933; }
.tag-medium, .tag-under-response, .tag-major, .tag-partial { border: .75px dashed #3e4c59; }
.tag-low, .tag-resolved, .tag-minor, .tag-sent { font-weight: normal; }
.tag-returned { border: 1.2px solid #1f2933; }
.tag-submitted, .tag-acknowledged { border: .75px dashed #3e4c59; }
.tag-scheduled, .tag-completed { font-weight: normal; }

/* Simple horizontal bar (charts can't render in dompdf). */
.bar-track { background-color: #e4e7eb; height: 7px; width: 100%; }
.bar-fill { background-color: #1d6b3a; height: 7px; }
.change-up { font-weight: bold; }
.change-down { font-weight: bold; }

/* Photos: sized in PHP to fit a box, never stretched (see x-pdf.photo). */
.photo { text-align: center; margin: 4px 0 10px 0; page-break-inside: avoid; }
.photo img { border: .5px solid #9aa5b1; padding: 3px; }

/* ========================== Signature block ========================= */
.signatures { margin-top: 26px; page-break-inside: avoid; }
.signatures table { width: 100%; border-collapse: collapse; }
.signatures td { width: 50%; vertical-align: top; padding: 0 22px; }
.sig-heading { font-size: 8.5pt; font-weight: bold; }
.sig-line { border-bottom: .75px solid #1f2933; height: 44px; margin: 0 10px 3px 10px; }
.sig-name { text-align: center; font-size: 9pt; font-weight: bold; text-transform: uppercase; }
.sig-name.blank { font-weight: normal; text-transform: none; color: #7b8794; }
.sig-role { text-align: center; font-size: 8pt; color: #3e4c59; }
.sig-date { font-size: 8pt; margin: 10px 10px 0 10px; }
.sig-date span { display: inline-block; width: 120px; border-bottom: .5px solid #1f2933; }
