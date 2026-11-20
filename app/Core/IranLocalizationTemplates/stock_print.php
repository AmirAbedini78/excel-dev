<?php
/**
 * Iran-first printable stock movement / warehouse slip template (T4).
 * Reuses the shared RTL document renderer; title should describe stock context.
 */
if (!isset($doc) || !is_array($doc)) {
    throw new RuntimeException('قالب حواله/رسید انبار بدون داده سند قابل رندر نیست.');
}
if (!isset($doc['title']) || trim((string)$doc['title']) === '') {
    $doc['title'] = 'حواله / رسید انبار';
}
echo IranLocalizationDocument::renderPrintableDocument($doc);
