@page {
    margin: 105px 70px 95px 70px;
}

body {
    font-family: Helvetica, Arial, sans-serif;
    font-size: 10px;
    color: #1a202c;
}

/* ============================== HEADER ============================== */

.header {
    border-bottom: 2px solid #15803d;
    padding-bottom: 12px;
    margin-bottom: 4px;
}

.header table {
    width: 100%;
    border-collapse: collapse;
}

.header .logo-cell {
    width: 65px;
}

.header .logo-cell img {
    height: 60px;
}

.header .institution-cell {
    text-align: center;
}

.institution-name {
    font-size: 15px;
    font-weight: bold;
    color: #1a202c;
}

.institution-office {
    font-size: 10px;
    font-weight: bold;
    color: #15803d;
    margin-top: 1px;
}

.institution-tagline {
    font-size: 8.5px;
    color: #4a5568;
    margin-top: 2px;
}

/* Document title — the largest, boldest text on the page. */
.title {
    text-align: center;
    font-size: 19px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    margin: 16px 0 4px 0;
}

.title-rule {
    width: 70px;
    height: 2px;
    background-color: #15803d;
    margin: 0 auto 16px auto;
}

/* ========================= METADATA STRIP ========================== */

.meta-strip {
    background-color: #f0f5f2;
    border: 1px solid #d9e5df;
    border-radius: 4px;
    margin-bottom: 18px;
}

.meta-strip table {
    width: 100%;
    border-collapse: collapse;
}

.meta-strip td {
    padding: 8px 14px;
    width: 33.33%;
    vertical-align: top;
}

.meta-strip td + td {
    border-left: 1px solid #d9e5df;
}

.meta-label {
    font-size: 8px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: #4a5568;
    margin-bottom: 2px;
}

.meta-value {
    font-size: 10px;
    font-weight: bold;
    color: #1a202c;
}

/* ============================ MAIN BODY ============================= */

.section-heading {
    font-size: 12.5px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: #1a202c;
    margin: 18px 0 8px 0;
    padding-bottom: 4px;
    border-bottom: 1px solid #cbd5e0;
}

.photo-card {
    text-align: center;
    margin: 2px 0 16px 0;
}

.photo-card img {
    max-width: 300px;
    max-height: 190px;
    border: 1px solid #d9e5df;
    border-radius: 4px;
    padding: 4px;
    background-color: #ffffff;
}

.photo-caption {
    font-size: 8.5px;
    font-style: italic;
    color: #718096;
    margin-top: 5px;
}

/* Paired key/value details, two columns of label+value per row so the
   full page width is used instead of one long stacked single column. */
table.details-grid {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 4px;
}

table.details-grid td {
    padding: 7px 8px;
    vertical-align: top;
}

table.details-grid td.label {
    width: 18%;
    font-size: 8.5px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: #4a5568;
}

table.details-grid td.value {
    width: 32%;
    font-size: 10px;
    font-weight: bold;
    color: #1a202c;
}

.reference-line {
    font-size: 9px;
    color: #4a5568;
    margin: 2px 0 16px 0;
}

.reference-line .reference-label {
    font-weight: bold;
    color: #1a202c;
    margin-right: 4px;
}

.reference-line a {
    color: #15803d;
}

.findings-box, .note-box {
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    padding: 10px 12px;
    margin-bottom: 16px;
    font-size: 10px;
}

.summary-line {
    font-size: 10px;
    color: #4a5568;
    margin-bottom: 10px;
}

table.data-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 18px;
}

table.data-table th {
    background-color: #f0fdf4;
    border: 1px solid #cbd5e0;
    padding: 7px 8px;
    text-align: left;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: #2d3748;
}

table.data-table td {
    border: 1px solid #cbd5e0;
    padding: 7px 8px;
    font-size: 9.5px;
}

table.data-table td.no-data {
    text-align: center;
    color: #718096;
    padding: 16px;
}

table.data-table tr.group-heading td {
    background-color: #f7fafc;
    font-weight: bold;
}

.status-ok {
    color: #15803d;
}

.status-low {
    color: #c53030;
    font-weight: bold;
}

.improvement-positive {
    color: #15803d;
}

.improvement-negative {
    color: #c53030;
}

/* ========================== SIGNATURE BLOCK ========================== */

.signature-block {
    width: 100%;
    border-collapse: collapse;
    margin-top: 50px;
}

.signature-block td {
    width: 50%;
    text-align: center;
    padding: 0 24px;
    vertical-align: top;
}

.signature-heading {
    font-size: 11px;
    font-weight: bold;
    text-align: center;
}

/* The blank line itself. margin-top is the clear space left for an actual
   pen signature above it — keep this at roughly 40-50px on every report,
   even a compacted one, so there's always genuinely enough room to sign. */
.signature-underline {
    width: 190px;
    margin: 50px auto 8px auto;
    border-top: 1px solid #1a202c;
}

.signature-field {
    font-size: 9.5px;
    color: #1a202c;
    text-align: center;
    margin-top: 3px;
}

/* The Position line is the one field the system already knows and
   pre-fills — bolded so it visually reads as supplied data, distinct
   from the blank Name/Date lines the signer completes by hand. */
.signature-prefilled {
    font-weight: bold;
}

/* ============================== FOOTER =============================== */

.footer {
    position: fixed;
    bottom: -75px;
    left: 0;
    right: 0;
    border-top: 1px solid #d9e5df;
    padding-top: 6px;
}

.footer table {
    width: 100%;
    border-collapse: collapse;
}

.footer td {
    font-size: 8px;
    color: #718096;
}

.footer td.right {
    text-align: right;
}

.footer-attribution {
    text-align: center;
    margin-top: 3px;
    font-size: 7.5px;
    color: #a0aec0;
}

/* Per-page number — dompdf's counter(page) works correctly (unlike
   counter(pages) for the total, which is unreliable here — see the
   comment in _pdf-footer.blade.php for why the total is server-computed). */
.pagenum:after {
    content: counter(page);
}
