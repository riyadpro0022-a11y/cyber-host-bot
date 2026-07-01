<?php
// ==========================================
// Gateway: Webhook Router & Traffic Logger
// ==========================================

error_reporting(0);
ini_set('display_errors', 0);

$botId = $_GET['id'] ?? '';

// সিকিউরিটি চেক: বট আইডি ভ্যালিডেশন
if (!preg_match('/^[a-zA-Z0-9_]+$/', $botId)) {
    http_response_code(400);
    exit("Invalid Bot ID");
}

// বটের ডিরেক্টরি অনুসন্ধান
$botDir = "bots/" . $botId;
$botFile = $botDir . "/bot.php";
$logFile = $botDir . "/log.txt";

if (!file_exists($botFile)) {
    http_response_code(404);
    exit("Bot Core File Missing");
}

// ইনকামিং রিকোয়েস্ট লগ করা
$input = file_get_contents("php://input");
if (!empty($input)) {
    $payload = json_decode($input, true);
    $time = date("Y-m-d H:i:s");
    
    $logEntry = "[$time] Incoming Telegram Update:\n";
    $logEntry .= json_encode($payload, JSON_PRETTY_PRINT) . "\n";
    $logEntry .= str_repeat("-", 50) . "\n";
    
    if (file_exists($logFile) && filesize($logFile) > 150000) {
        file_put_contents($logFile, $logEntry);
    } else {
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}

// বট স্ক্রিপ্ট রান করানো
try {
    include($botFile);
} catch (Throwable $e) {
    file_put_contents($logFile, "[" . date("Y-m-d H:i:s") . "] Runtime Error: " . $e->getMessage() . "\n", FILE_APPEND);
}