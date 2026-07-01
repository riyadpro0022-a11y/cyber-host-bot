<?php
// ====================================================================
// Telegram Bot Hosting Engine & Dashboard (Unrestricted Hosting Theme)
// ====================================================================

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); // কাস্টম এরর হ্যান্ডলারের মাধ্যমে এরর দেখাবো

define('BOTS_DIR', 'bots/');

// প্রয়োজনীয় ডিরেক্টরি তৈরি
if (!is_dir(BOTS_DIR)) {
    @mkdir(BOTS_DIR, 0755, true);
}

// কাস্টম এরর ও ব্যতিক্রম হ্যান্ডলার (যা ব্যবহারকারীকে বিস্তারিত সমস্যা বুঝিয়ে দিবে)
$system_error = null;
function handle_system_error($errno, $errstr, $errfile, $errline) {
    global $system_error;
    $system_error = [
        'type' => 'Error',
        'message' => $errstr,
        'file' => basename($errfile),
        'line' => $errline
    ];
    return true;
}
set_error_handler("handle_system_error");

// সেশন অ্যালার্ট মেসেজ
$alert = $_SESSION['alert'] ?? null;
unset($_SESSION['alert']);

function set_alert($message, $type = 'error') {
    $_SESSION['alert'] = ['msg' => $message, 'type' => $type];
}

// টোকেন খোঁজার হেল্পার ফাংশন
function extract_token($code) {
    if (preg_match('/(\d+:[\w-]{35,})/', $code, $matches)) {
        return $matches[1];
    }
    return null;
}

// --- ফাইল আপলোড প্রসেস ---
if (isset($_FILES['bot_file']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $file = $_FILES['bot_file'];
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    
    if ($ext !== 'php') {
        set_alert("Invalid file format. Only .php files are allowed.");
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        set_alert("File upload failed with error code: " . $file['error']);
    } else {
        // কোনো প্রকার সিকিউরিটি ফিল্টার বা টোকেন রিকোয়ারমেন্ট ছাড়া সরাসরি আপলোড
        $botId = "bot_" . uniqid();
        $botDir = BOTS_DIR . $botId;
        @mkdir($botDir, 0755, true);
        
        if (move_uploaded_file($file['tmp_name'], $botDir . "/bot.php")) {
            // স্ট্যাটাস তৈরি
            file_put_contents($botDir . "/status.json", json_encode(['status' => 'stopped']));
            set_alert("File successfully deployed!", "success");
        } else {
            set_alert("Failed to write bot script to destination directory.");
        }
    }
    header("Location: index.php"); exit;
}

// --- কোড এডিটর সেভ প্রসেস ---
if (isset($_POST['save_code'])) {
    $botId = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['bot_id']);
    $code = $_POST['code'] ?? '';
    $botDir = BOTS_DIR . $botId . "/";
    
    if (is_dir($botDir)) {
        // কোনো রেস্ট্রিকশন ছাড়াই কোড সরাসরি সেভ করা হচ্ছে
        file_put_contents($botDir . "bot.php", $code);
        set_alert("Source code updated successfully!", "success");
    }
    header("Location: index.php"); exit;
}

// --- বট অ্যাকশনস (Start, Stop, Delete) ---
if (isset($_GET['action']) && isset($_GET['bot_id'])) {
    $botId = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['bot_id']);
    $botDir = BOTS_DIR . $botId . "/";
    $botFile = $botDir . "bot.php";
    $statusFile = $botDir . "status.json";
    
    if (is_dir($botDir) && file_exists($botFile)) {
        $action = $_GET['action'];
        $code = file_get_contents($botFile);
        $token = extract_token($code);
        
        if ($action === 'start') {
            if ($token) {
                // Gateway URL জেনারেট করা
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
                $host = $_SERVER['HTTP_HOST'];
                $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
                $scriptPath = ($scriptPath === '/' || $scriptPath === '\\') ? '' : $scriptPath;
                $webhookUrl = "$protocol://$host" . $scriptPath . "/gateway.php?id=" . $botId;
                
                // টেলিগ্রাম এপিআই কল
                $apiUrl = "https://api.telegram.org/bot$token/setWebhook?url=" . urlencode($webhookUrl);
                $response = @file_get_contents($apiUrl);
                $resData = json_decode($response, true);
                
                if ($resData && $resData['ok']) {
                    file_put_contents($statusFile, json_encode(['status' => 'running', 'token' => $token, 'updated' => date('Y-m-d H:i:s')]));
                    set_alert("Bot Online! Webhook linked successfully.", "success");
                } else {
                    $desc = $resData['description'] ?? 'API issue';
                    set_alert("Telegram Webhook Registration Failed: " . $desc);
                }
            } else {
                set_alert("Auto-start failed: No Telegram Bot Token detected in your bot.php file.");
            }
        } elseif ($action === 'stop') {
            if ($token) {
                // ডিলিট ওয়েব হুক
                $apiUrl = "https://api.telegram.org/bot$token/deleteWebhook";
                @file_get_contents($apiUrl);
            }
            file_put_contents($statusFile, json_encode(['status' => 'stopped']));
            set_alert("Bot stopped successfully.", "success");
        }
        
        if ($action === 'delete') {
            // ফাইল মুছে ফেলা
            array_map('unlink', glob("$botDir*.*"));
            @rmdir($botDir);
            set_alert("Bot instance successfully deleted.", "success");
        }
    }
    header("Location: index.php"); exit;
}

// সমস্ত বটের লিস্ট লোড করা
$myBots = is_dir(BOTS_DIR) ? array_filter(glob(BOTS_DIR . '*'), 'is_dir') : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CYBER HOST | Public Webhook Hosting</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-primary: #0a0a0c;
            --bg-secondary: #141418;
            --accent-yellow: #ffcc00;
            --accent-hover: #e6b800;
            --text-main: #ffffff;
            --text-muted: #8a8a93;
            --border-color: #22222a;
            --danger-red: #ff3b30;
            --success-green: #34c759;
        }

        body {
            font-family: 'Segoe UI', Roboto, sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-main);
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* হেডার */
        .header {
            background-color: var(--bg-secondary);
            border-bottom: 2px solid var(--accent-yellow);
            padding: 15px 25px;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .logo {
            font-size: 24px;
            font-weight: 900;
            color: var(--accent-yellow);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* কন্টেইনার */
        .container {
            max-width: 600px;
            width: 90%;
            margin: 30px auto;
            flex-grow: 1;
        }

        /* কার্ড থিম */
        .card {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            margin-bottom: 25px;
        }

        .card-title {
            font-size: 20px;
            font-weight: bold;
            color: var(--accent-yellow);
            margin-top: 0;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* বাটন */
        .btn-submit {
            background-color: var(--accent-yellow);
            color: var(--bg-primary);
            border: none;
            width: 100%;
            padding: 14px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.2s ease;
        }

        .btn-submit:hover {
            background-color: var(--accent-hover);
        }

        .btn-logout {
            background-color: transparent;
            color: var(--accent-yellow);
            border: 1px solid var(--accent-yellow);
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            transition: 0.2s ease;
        }

        .btn-logout:hover {
            background-color: var(--accent-yellow);
            color: var(--bg-primary);
        }

        /* এরর এবং নোটিফিকেশন মেসেজ */
        .alert {
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            font-weight: 500;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-left: 5px solid;
            text-align: left;
        }

        .alert-error {
            background-color: rgba(255, 59, 48, 0.1);
            color: var(--danger-red);
            border-color: var(--danger-red);
        }

        .alert-success {
            background-color: rgba(52, 199, 89, 0.1);
            color: var(--success-green);
            border-color: var(--success-green);
        }

        /* বট কারুকার্য এবং ইন্টারেক্টিভ কন্টেন্ট */
        .bot-item {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            margin-bottom: 15px;
            overflow: hidden;
            transition: 0.3s ease;
        }

        .bot-header {
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            user-select: none;
        }

        .bot-header:hover {
            background-color: rgba(255, 204, 0, 0.05);
        }

        .bot-title {
            font-weight: bold;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .status-badge {
            font-size: 10px;
            padding: 3px 8px;
            border-radius: 50px;
            text-transform: uppercase;
            font-weight: 900;
        }

        .status-running { background-color: var(--success-green); color: black; }
        .status-stopped { background-color: var(--text-muted); color: black; }

        /* বট বিস্তারিত প্যানেল */
        .bot-details {
            display: none;
            background-color: #0d0d11;
            padding: 20px;
            border-top: 1px solid var(--border-color);
        }

        /* একশন বাটনস গ্রিড */
        .action-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }

        .btn-act {
            padding: 10px;
            border-radius: 8px;
            border: none;
            font-weight: bold;
            font-size: 12px;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: 0.2s;
        }

        .btn-start { background-color: var(--success-green); color: black; }
        .btn-stop { background-color: #ff9500; color: black; }
        .btn-log { background-color: var(--accent-yellow); color: black; }
        .btn-edit { background-color: #007aff; color: white; }
        .btn-del { background-color: var(--danger-red); color: white; grid-column: span 2; }

        .btn-act:hover { opacity: 0.85; }

        /* ফাইল আপলোড জোন */
        .upload-zone {
            border: 2px dashed var(--accent-yellow);
            border-radius: 12px;
            padding: 30px 15px;
            text-align: center;
            cursor: pointer;
            background-color: rgba(255, 204, 0, 0.02);
            transition: 0.3s;
        }

        .upload-zone:hover {
            background-color: rgba(255, 204, 0, 0.06);
        }

        .upload-zone i {
            font-size: 36px;
            color: var(--accent-yellow);
            margin-bottom: 10px;
        }

        /* কাস্টম কোড এডিটর স্ক্রিন */
        .editor-overlay {
            position: fixed;
            top: 0; left: 0;
            width: 100vw; height: 100vh;
            background-color: var(--bg-primary);
            z-index: 9999;
            display: none;
            flex-direction: column;
        }

        .editor-header {
            background-color: var(--bg-secondary);
            padding: 15px 20px;
            border-bottom: 1px solid var(--accent-yellow);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .editor-textarea {
            width: 100%;
            flex-grow: 1;
            background-color: #070709;
            color: #4af626; /* ম্যাট্রিক্স গ্রিন কালার */
            font-family: 'Courier New', monospace;
            font-size: 15px;
            padding: 20px;
            border: none;
            outline: none;
            resize: none;
            box-sizing: border-box;
        }

        /* টার্মিনাল লগ ভিউ */
        .terminal-box {
            background-color: black;
            color: var(--accent-yellow);
            font-family: monospace;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            max-height: 200px;
            overflow-y: auto;
            text-align: left;
            white-space: pre-wrap;
            font-size: 12px;
        }
    </style>
</head>
<body>

    <div class="header">
        <a href="index.php" class="logo"><i class="fas fa-server"></i> CYBER HOST</a>
    </div>

    <div class="container">
        
        <!-- সিসটেম এরর ট্র্যাকিং মেসেজ (বিন্দু পরিমাণ বাগ সহজেই দেখার জন্য) -->
        <?php if ($system_error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Code Interceptor Error:</strong> <?= htmlspecialchars($system_error['message']); ?> <br>
                    <small>Location: <?= htmlspecialchars($system_error['file']); ?> [Line: <?= $system_error['line']; ?>]</small>
                </div>
            </div>
        <?php endif; ?>

        <!-- অ্যালার্টস -->
        <?php if ($alert): ?>
            <div class="alert <?= $alert['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <i class="fas <?= $alert['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <div><?= htmlspecialchars($alert['msg']); ?></div>
            </div>
        <?php endif; ?>

        <!-- ড্যাশবোর্ড স্ক্রিন (সরাসরি অ্যাক্সেস) -->
        <div class="card">
            <div class="card-title"><i class="fas fa-terminal"></i> BOT DEPLOYMENT</div>
            <form method="POST" enctype="multipart/form-data">
                <div class="upload-zone" onclick="document.getElementById('file_input').click()">
                    <i class="fas fa-file-code"></i>
                    <p id="upload_lbl" style="margin:0; font-weight:bold; font-size:14px;">TAP TO SELECT PHP BOT SCRIPT</p>
                    <span style="font-size:11px; color:var(--text-muted);">Script upload unrestricted.</span>
                    <input type="file" name="bot_file" id="file_input" accept=".php" style="display:none;" required onchange="updateLabel(this)">
                </div>
                <button type="submit" class="btn-submit" style="margin-top: 15px;"><i class="fas fa-cloud-upload-alt"></i> ROCKET LAUNCH</button>
            </form>
        </div>

        <div style="font-weight:bold; color:var(--accent-yellow); margin-bottom:15px; text-transform:uppercase; font-size:12px; letter-spacing:1px; text-align:left;">
            Active Terminals (<?= count($myBots); ?> Deployed)
        </div>

        <?php if (empty($myBots)): ?>
            <div style="text-align:center; padding: 40px; color:var(--text-muted); background-color:var(--bg-secondary); border-radius:12px;">
                <i class="fas fa-network-wired" style="font-size:36px; display:block; margin-bottom:10px; color:var(--accent-yellow);"></i>
                No scripts deployed yet. Upload a script above to begin!
            </div>
        <?php endif; ?>

        <?php foreach($myBots as $b): 
            $botId = basename($b);
            $statusFile = $b . "/status.json";
            $statusData = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : ['status' => 'stopped'];
            $isRunning = ($statusData['status'] ?? '') === 'running';
            $botCode = file_exists($b . "/bot.php") ? file_get_contents($b . "/bot.php") : '';
            $logFile = $b . "/log.txt";
            $logs = file_exists($logFile) ? trim(file_get_contents($logFile)) : "No payload requests received yet. Send a command to your Bot.";
        ?>
        <div class="bot-item" id="bot_card_<?= $botId; ?>">
            <div class="bot-header" onclick="toggleDetails('<?= $botId; ?>')">
                <div class="bot-title">
                    <i class="fas fa-robot" style="color:var(--accent-yellow);"></i>
                    <span><?= $botId; ?></span>
                </div>
                <span class="status-badge <?= $isRunning ? 'status-running' : 'status-stopped'; ?>">
                    <?= $isRunning ? 'ONLINE' : 'STOPPED'; ?>
                </span>
            </div>
            
            <div class="bot-details" id="details_<?= $botId; ?>">
                <div class="action-grid">
                    <?php if ($isRunning): ?>
                        <a href="?action=stop&bot_id=<?= $botId; ?>" class="btn-act btn-stop"><i class="fas fa-stop"></i> STOP ENGINE</a>
                    <?php else: ?>
                        <a href="?action=start&bot_id=<?= $botId; ?>" class="btn-act btn-start"><i class="fas fa-play"></i> START WEBHOOK</a>
                    <?php endif; ?>
                    
                    <button onclick="viewLogs('<?= $botId; ?>')" class="btn-act btn-log"><i class="fas fa-history"></i> PAYLOAD LOGS</button>
                    <button onclick="openEditor('<?= $botId; ?>')" class="btn-act btn-edit"><i class="fas fa-edit"></i> WEB EDITOR</button>
                    <a href="?action=delete&bot_id=<?= $botId; ?>" onclick="return confirm('Confirm complete destruction of this bot?')" class="btn-act btn-del"><i class="fas fa-trash-alt"></i> DESTROY INSTANCE</a>
                </div>

                <!-- হিডেন লগ এবং কোড ডাম্প -->
                <div id="hidden_logs_<?= $botId; ?>" style="display:none;"><?= htmlspecialchars($logs); ?></div>
                <div id="hidden_code_<?= $botId; ?>" style="display:none;"><?= htmlspecialchars($botCode); ?></div>

                <div style="font-size:11px; color:var(--text-muted); text-align:left; margin-top:10px;">
                    <i class="fas fa-link"></i> Webhook Gateway: <br>
                    <code style="color:white; word-break: break-all;"><?= (empty($_SERVER['HTTPS']) ? 'http' : 'https') . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . "/gateway.php?id=" . $botId; ?></code>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

    </div>

    <!-- লগের জন্য পপআপ মডাল বক্স -->
    <div id="logModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:10000; justify-content:center; align-items:center;">
        <div class="card" style="width:90%; max-width:500px; text-align:center;">
            <div class="card-title" style="justify-content:space-between;">
                <span><i class="fas fa-history"></i> LIVE PAYLOAD LOGS</span>
                <i class="fas fa-times" onclick="closeLogModal()" style="cursor:pointer; color:var(--danger-red);"></i>
            </div>
            <div class="terminal-box" id="modalLogContent">Loading logs...</div>
            <button onclick="closeLogModal()" class="btn-submit" style="margin-top:15px;">DISMISS WINDOW</button>
        </div>
    </div>

    <!-- ফুলস্ক্রিন ওয়েব আইডিই / কোড এডিটর -->
    <div class="editor-overlay" id="editorContainer">
        <form method="POST" action="index.php" style="display:flex; flex-direction:column; height:100%;">
            <div class="editor-header">
                <span style="font-weight:bold; color:var(--accent-yellow);"><i class="fas fa-code"></i> CYBER_EDITOR_CORE.PHP</span>
                <div style="display:flex; gap:10px;">
                    <button type="submit" name="save_code" class="btn-logout" style="background:var(--accent-yellow); color:black; border:none;"><i class="fas fa-save"></i> SAVE SCRIPT</button>
                    <button type="button" onclick="closeEditor()" class="btn-logout" style="border-color:var(--danger-red); color:var(--danger-red);"><i class="fas fa-times"></i> CLOSE</button>
                </div>
            </div>
            <input type="hidden" name="bot_id" id="editor_bot_id">
            <textarea name="code" class="editor-textarea" id="editor_textarea" spellcheck="false"></textarea>
        </form>
    </div>

    <script>
        // ফাইল সিলেকশন লেবেল আপডেট
        function updateLabel(input) {
            if(input.files[0]) {
                document.getElementById('upload_lbl').innerHTML = "READY: " + input.files[0].name;
                document.getElementById('upload_lbl').parentElement.style.borderColor = "var(--success-green)";
            }
        }

        // বট একর্ডিয়ন টগল
        function toggleDetails(botId) {
            let detailPanel = document.getElementById('details_' + botId);
            if (detailPanel.style.display === 'block') {
                detailPanel.style.display = 'none';
            } else {
                detailPanel.style.display = 'block';
            }
        }

        // লগ প্রদর্শন
        function viewLogs(botId) {
            let logs = document.getElementById('hidden_logs_' + botId).innerHTML;
            document.getElementById('modalLogContent').textContent = logs;
            document.getElementById('logModal').style.display = 'flex';
        }

        function closeLogModal() {
            document.getElementById('logModal').style.display = 'none';
        }

        // ওয়েব এডিটর অ্যাকশনস
        function openEditor(botId) {
            let code = document.getElementById('hidden_code_' + botId).textContent;
            document.getElementById('editor_textarea').value = code;
            document.getElementById('editor_bot_id').value = botId;
            document.getElementById('editorContainer').style.display = 'flex';
        }

        function closeEditor() {
            document.getElementById('editorContainer').style.display = 'none';
        }
    </script>
</body>
</html>