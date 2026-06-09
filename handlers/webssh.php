<?php
$ws = isset($_GET['ws']) ? (string)$_GET['ws'] : (isset($_GET['amp;ws']) ? (string)$_GET['amp;ws'] : '');
$protocol = isset($_GET['protocol']) ? (string)$_GET['protocol'] : (isset($_GET['amp;protocol']) ? (string)$_GET['amp;protocol'] : '');
$container = isset($_GET['container']) ? (string)$_GET['container'] : (isset($_GET['amp;container']) ? (string)$_GET['amp;container'] : '');

if ($ws === '' || $protocol === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Missing WebSSH parameters\n";
    echo "Received query: " . ($_SERVER['QUERY_STRING'] ?? '') . "\n";
    exit;
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WebSSH</title>
    <style>
        html,body{height:100%;margin:0;background:#0b1020;color:#e5e7eb;font-family:Consolas,Menlo,monospace}
        .bar{height:44px;display:flex;align-items:center;gap:12px;padding:0 14px;background:#111827;border-bottom:1px solid #243047}
        .dot{width:9px;height:9px;border-radius:50%;background:#f59e0b}
        .dot.ok{background:#22c55e}.dot.err{background:#ef4444}
        .title{font-size:14px;color:#cbd5e1}
        #term{height:calc(100% - 45px);box-sizing:border-box;padding:14px;overflow:auto;white-space:pre-wrap;word-break:break-word;font-size:14px;line-height:1.45;outline:none}
        .hint{color:#94a3b8}
        .meta{color:#94a3b8}
    </style>
</head>
<body>
<div class="bar">
    <span id="state" class="dot"></span>
    <span class="title">WebSSH <?php echo htmlspecialchars($container, ENT_QUOTES, 'UTF-8'); ?></span>
</div>
<div id="term" tabindex="0"><span class="hint">Connecting...</span></div>
<script>
(function(){
    var wsUrl = <?php echo json_encode($ws, JSON_UNESCAPED_SLASHES); ?>;
    var protocol = <?php echo json_encode($protocol, JSON_UNESCAPED_SLASHES); ?>;
    var term = document.getElementById('term');
    var state = document.getElementById('state');
    var socket;

    function append(text) {
        term.appendChild(document.createTextNode(text));
        term.scrollTop = term.scrollHeight;
    }

    function setState(cls, text) {
        state.className = 'dot ' + cls;
        append(text);
    }

    append('\nTarget: ' + wsUrl + '\n');
    append('Protocol: ' + protocol + '\n\n');

    try {
        socket = new WebSocket(wsUrl, protocol);
    } catch (e) {
        setState('err', '\nWebSocket create failed: ' + e.message + '\n');
        return;
    }

    socket.onopen = function(){
        term.textContent = '';
        setState('ok', 'Connected.\r\n');
        term.focus();
    };
    socket.onmessage = function(event){
        append(typeof event.data === 'string' ? event.data : '');
    };
    socket.onerror = function(){
        setState('err', '\nWebSocket error. Check HTTPS certificate, WSS service, Origin policy and ticket validity.\n');
    };
    socket.onclose = function(event){
        setState('err', '\nDisconnected. code=' + event.code + ' reason=' + (event.reason || '-') + ' clean=' + event.wasClean + '\n');
    };

    term.addEventListener('keydown', function(e){
        if (!socket || socket.readyState !== WebSocket.OPEN) {
            return;
        }
        if (e.key === 'Enter') {
            socket.send('\r');
            e.preventDefault();
            return;
        }
        if (e.key === 'Backspace') {
            socket.send('\x7f');
            e.preventDefault();
            return;
        }
        if (e.key === 'Tab') {
            socket.send('\t');
            e.preventDefault();
            return;
        }
        if (e.key.length === 1) {
            socket.send(e.key);
            e.preventDefault();
        }
    });
})();
</script>
</body>
</html>
