{{--
    Compact overrides for PER-RECORD reports only (building, hazard
    inspection, incident, drill, alert), loaded after _style when a template
    sets $docCompact = true. Tightens spacing so a typical record fits on one
    page; nothing is hidden — long records still flow onto page 2. The page
    box and letterhead are the same as every other PDF (config/pdf.php).
    Period/module and Consolidated reports never load this file.
--}}
body { font-size: 8.2pt; line-height: 1.3; }


.doc-title { font-size: 12.5pt; }
.doc-subtitle { font-size: 8.5pt; margin-top: 1px; }

table.doc-meta { margin: 6px 0 8px 0; }
table.doc-meta td { padding: 3px 6px; width: 25%; }
.meta-value { font-size: 8pt; }

h2.section { font-size: 9.2pt; margin: 8px 0 4px 0; padding: 2px 6px; }
p.lead { margin: 0 0 4px 0; }

table.fields { margin-bottom: 5px; }
table.fields td { padding: 3px 6px; }
.field-value { font-size: 8.5pt; }
.field-text { font-size: 8.2pt; }

table.data { margin-bottom: 6px; }
table.data th { padding: 3px 5px; font-size: 7pt; }
table.data td { padding: 2.5px 5px; font-size: 7.8pt; }

.photo { margin: 2px 0 6px 0; }
.caption { font-size: 7pt; }

/* Signature block: still ~38px of clear space above each line to sign. */
.signatures { margin-top: 14px; }
.sig-line { height: 38px; }
.sig-date { margin-top: 6px; }
