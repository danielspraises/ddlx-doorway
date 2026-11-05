<?php
// Pretend AI services for the self-tests: /ok-gemini, /ok-groq answer; /busy returns 503; /slow-ok answers after 1 second.
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($p === '/busy') { http_response_code(503); echo '{"error":{"code":503,"status":"UNAVAILABLE"}}'; return; }
if ($p === '/slow-ok') { usleep(1000000); echo json_encode(['candidates' => [['content' => ['parts' => [['text' => 'slow gemini answer']]]]]]); return; }
if ($p === '/ok-gemini') { echo json_encode(['candidates' => [['content' => ['parts' => [['thought' => true, 'text' => 'thinking'], ['text' => 'gemini answer']]]]]]); return; }
if ($p === '/ok-groq') { echo json_encode(['choices' => [['message' => ['content' => 'groq answer']]]]); return; }
http_response_code(404); echo '{}';
