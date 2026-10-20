<?php

/*
 * IMS v2 server settings (IMS-V2/TRD.md §7). Business settings live in the
 * office's IMS settings page, not here.
 */
return [
    // Tesseract OCR for reading NID cards on this server (packages
    // tesseract-ocr + tesseract-ocr-ben). When it is missing, staff check
    // KYC pictures by hand.
    'tesseract_path' => env('IMS_TESSERACT_PATH', 'tesseract'),
];
