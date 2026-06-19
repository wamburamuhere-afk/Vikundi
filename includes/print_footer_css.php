<style>
        /* ── PRINT FOOTER (shared — includes/print_footer_css.php) ── */

        /* Canonical page margins: 16 mm bottom leaves room for the fixed footer.
           Declared here so it is the LAST @page rule in document order and wins
           the cascade over any per-page @page that may be defined earlier.
           top 10mm | right 8mm | bottom 16mm | left 8mm                       */
        @page { margin: 10mm 8mm 16mm 8mm; }

        .print-footer {
            position: fixed;              /* repeats on every printed page      */
            bottom: 0; left: 0; right: 0; /* sits inside the 16 mm bottom band  */
            height: 16px;
            background: #fff;
            border-top: 1px solid #dee2e6;
            padding: 0 22px;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .print-footer p     { margin: 0; font-size: 7px; color: #2c3e50; line-height: 1; }
        .print-footer .brand {
            font-size: 7px; color: #3498db; font-weight: 600;
            print-color-adjust: exact; -webkit-print-color-adjust: exact;
        }
        .footer-spacer { height: 12px; }

        @media print {
            body { padding-bottom: 4mm !important; }
            .footer-spacer { display: none !important; }
        }
</style>
