<?php
/**
 * Iran-first printable invoice template (T4).
 * Expects $doc array — see IranLocalizationDocument::renderPrintableDocument.
 */
if (!isset($doc) || !is_array($doc)) {
    throw new RuntimeException('قالب فاکتور بدون داده سند قابل رندر نیست.');
}
echo IranLocalizationDocument::renderPrintableDocument($doc);
