<?php
require __DIR__ . '/app/bootstrap.php';
$secret = $_GET['secret'] ?? '';
if (!$secret || !hash_equals((string)setting('cron_secret',''), (string)$secret)) { http_response_code(403); echo "Forbidden\n"; exit; }
$res = Notify::sendDueReminders();
$res['ai_suggestions_created'] = AiSuggestionEngine::refreshAll();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($res, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
