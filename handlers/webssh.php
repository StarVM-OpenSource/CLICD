<?php
$ws = isset($_GET['ws']) ? (string)$_GET['ws'] : (isset($_GET['amp;ws']) ? (string)$_GET['amp;ws'] : '');
$protocol = isset($_GET['protocol']) ? (string)$_GET['protocol'] : (isset($_GET['amp;protocol']) ? (string)$_GET['amp;protocol'] : '');
$container = isset($_GET['container']) ? (string)$_GET['container'] : (isset($_GET['amp;container']) ? (string)$_GET['amp;container'] : '');
$ticket = isset($_GET['ticket']) ? (string)$_GET['ticket'] : (isset($_GET['amp;ticket']) ? (string)$_GET['amp;ticket'] : '');

if ($protocol === '' && $ticket !== '') {
    $protocol = 'clicd-ticket.' . $ticket;
}

if ($ws === '') {
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
        body{cursor:text}
        .bar{height:44px;display:flex;align-items:center;gap:12px;padding:0 14px;background:#111827;border-bottom:1px solid #243047}
        .dot{width:9px;height:9px;border-radius:50%;background:#f59e0b}
        .dot.ok{background:#22c55e}.dot.err{background:#ef4444}
        .title{font-size:14px;color:#cbd5e1;flex:1}
        .tools{display:flex;align-items:center;gap:8px;font-size:12px;color:#94a3b8;flex-wrap:wrap;justify-content:flex-end}
        .tools select{height:26px;background:#0f172a;color:#cbd5e1;border:1px solid #334155;border-radius:4px}
        .tools button{height:26px;border:1px solid #334155;background:#0f172a;color:#cbd5e1;border-radius:4px;padding:0 8px;cursor:pointer}
        #keyhint{min-width:44px;text-align:right}
        #iostat{min-width:120px;text-align:right}
        #term{height:calc(100% - 89px);box-sizing:border-box;padding:14px;overflow:auto;white-space:pre-wrap;word-break:break-word;font-size:14px;line-height:1.45;outline:none}
        .inputbar{height:44px;display:flex;align-items:center;gap:8px;padding:6px 10px;box-sizing:border-box;background:#111827;border-top:1px solid #243047}
        #cmd{flex:1;height:30px;background:#020617;color:#e5e7eb;border:1px solid #334155;border-radius:4px;padding:0 8px;font:14px Consolas,Menlo,monospace;outline:none}
        #sendcmd{height:30px;border:1px solid #2563eb;background:#2563eb;color:#fff;border-radius:4px;padding:0 12px;cursor:pointer}
        .hint{color:#94a3b8}
        .meta{color:#94a3b8}
    </style>
</head>
<body>
<div class="bar">
    <span id="state" class="dot"></span>
    <span class="title">WebSSH <?php echo htmlspecialchars($container, ENT_QUOTES, 'UTF-8'); ?></span>
    <span class="tools">
        <span>发送模式</span>
        <select id="send-mode">
            <option value="raw" selected>raw</option>
            <option value="binary">binary</option>
            <option value="json-input">json input</option>
            <option value="json-data">json data</option>
            <option value="json-stdin">json stdin</option>
        </select>
        <button id="send-enter" type="button">回车</button>
        <span id="iostat">S0 R0</span>
        <span id="keyhint"></span>
    </span>
</div>
<div id="term" tabindex="0"><span class="hint">正在连接...</span></div>
<div class="inputbar">
    <input id="cmd" type="text" autocomplete="off" spellcheck="false" placeholder="在这里输入命令，例如 ls -la">
    <button id="sendcmd" type="button">发送</button>
</div>
<script>
(function(){
    var wsUrl = <?php echo json_encode($ws, JSON_UNESCAPED_SLASHES); ?>;
    var protocol = <?php echo json_encode($protocol, JSON_UNESCAPED_SLASHES); ?>;
    var ticket = <?php echo json_encode($ticket, JSON_UNESCAPED_SLASHES); ?>;
    var term = document.getElementById('term');
    var state = document.getElementById('state');
    var modeSelect = document.getElementById('send-mode');
    var keyhint = document.getElementById('keyhint');
    var iostat = document.getElementById('iostat');
    var sendEnter = document.getElementById('send-enter');
    var cmd = document.getElementById('cmd');
    var sendcmd = document.getElementById('sendcmd');
    var socket;
    var hintTimer;
    var sentCount = 0;
    var recvCount = 0;
    var decoder = window.TextDecoder ? new TextDecoder('utf-8') : null;
    var termLines = [''];
    var cursorRow = 0;
    var cursorCol = 0;
    var maxLines = 2000;

    function append(text) {
        writeTerminal(stripTerminalControls(String(text || '')));
        renderTerminal();
    }

    function clearTerminal() {
        termLines = [''];
        cursorRow = 0;
        cursorCol = 0;
        renderTerminal();
    }

    function stripTerminalControls(text) {
        return text
            .replace(/\x1b\][\s\S]*?(?:\x07|\x1b\\)/g, '')
            .replace(/\x1b\[(?:2J|H)/g, '\f')
            .replace(/\x1b\[[0-?]*[ -/]*K/g, '\v')
            .replace(/\ufffd\[[0-?]*[ -/]*K/g, '\v')
            .replace(/\x1b\[[0-?]*[ -/]*[@-~]/g, '')
            .replace(/\ufffd\[[0-?]*[ -/]*[@-~]/g, '')
            .replace(/\x1b[()][A-Za-z0-9]/g, '')
            .replace(/\x1b[@-Z\\-_]/g, '')
            .replace(/[\x00-\x08\x0e-\x1f\x7f]/g, '');
    }

    function ensureLine() {
        while (cursorRow >= termLines.length) {
            termLines.push('');
        }
    }

    function trimTerminal() {
        if (termLines.length <= maxLines) {
            return;
        }
        var overflow = termLines.length - maxLines;
        termLines.splice(0, overflow);
        cursorRow = Math.max(0, cursorRow - overflow);
    }

    function writeTerminal(text) {
        for (var i = 0; i < text.length; i++) {
            var ch = text.charAt(i);
            if (ch === '\f') {
                termLines = [''];
                cursorRow = 0;
                cursorCol = 0;
                continue;
            }
            if (ch === '\v') {
                ensureLine();
                termLines[cursorRow] = termLines[cursorRow].slice(0, cursorCol);
                continue;
            }
            if (ch === '\r') {
                cursorCol = 0;
                continue;
            }
            if (ch === '\n') {
                cursorRow++;
                cursorCol = 0;
                ensureLine();
                trimTerminal();
                continue;
            }
            if (ch === '\b') {
                cursorCol = Math.max(0, cursorCol - 1);
                continue;
            }
            if (ch === '\t') {
                var spaces = 4 - (cursorCol % 4);
                for (var s = 0; s < spaces; s++) {
                    writePrintable(' ');
                }
                continue;
            }
            writePrintable(ch);
        }
        trimTerminal();
    }

    function writePrintable(ch) {
        ensureLine();
        var line = termLines[cursorRow];
        if (cursorCol > line.length) {
            line += new Array(cursorCol - line.length + 1).join(' ');
        }
        termLines[cursorRow] = line.slice(0, cursorCol) + ch + line.slice(cursorCol + 1);
        cursorCol++;
    }

    function renderTerminal() {
        term.textContent = termLines.join('\n');
        term.scrollTop = term.scrollHeight;
    }

    function setState(cls, text) {
        state.className = 'dot ' + cls;
        append(text);
    }

    function updateIoStatus() {
        if (!iostat) return;
        var stateText = socket ? ['CONNECTING','OPEN','CLOSING','CLOSED'][socket.readyState] : '-';
        iostat.textContent = 'S' + sentCount + ' R' + recvCount + ' ' + stateText;
    }

    function websocketProtocolValue(value) {
        value = String(value || '');
        return /^[!#$%&'*+\-.^_`|~0-9A-Za-z]+$/.test(value) ? value : '';
    }

    try {
        var protocolValue = websocketProtocolValue(protocol);
        if (!protocolValue && ticket) {
            append('[WebSSH] 票据已通过 URL 参数传递，当前浏览器不会发送子协议。\n');
        }
        socket = protocolValue ? new WebSocket(wsUrl, protocolValue) : new WebSocket(wsUrl);
        socket.binaryType = 'arraybuffer';
    } catch (e) {
        setState('err', '\nWebSocket 创建失败：' + e.message + '\n');
        return;
    }

    socket.onopen = function(){
        clearTerminal();
        setState('ok', '已连接。\r\n');
        updateIoStatus();
        if (cmd) cmd.focus();
    };
    socket.onmessage = function(event){
        recvCount++;
        updateIoStatus();
        if (typeof event.data === 'string') {
            handleIncomingText(event.data);
            return;
        }
        if (event.data instanceof ArrayBuffer) {
            handleIncomingText(decodeIncoming(event.data));
            return;
        }
        if (window.Blob && event.data instanceof Blob) {
            event.data.arrayBuffer().then(function(buffer){
                handleIncomingText(decodeIncoming(buffer));
            }).catch(function(){
                append('\n[WebSSH] 无法解码服务端返回内容。\n');
            });
        }
    };
    socket.onerror = function(){
        setState('err', '\nWebSocket 连接错误，请检查 HTTPS 证书、WSS 服务、Origin 策略和票据有效期。\n');
    };
    socket.onclose = function(event){
        updateIoStatus();
        setState('err', '\n连接已断开。code=' + event.code + ' reason=' + (event.reason || '-') + ' clean=' + event.wasClean + '\n');
    };

    function flashKey(text) {
        if (!keyhint) return;
        keyhint.textContent = text;
        window.clearTimeout(hintTimer);
        hintTimer = window.setTimeout(function(){ keyhint.textContent = ''; }, 500);
    }

    function decodeIncoming(buffer) {
        if (decoder) {
            return decoder.decode(new Uint8Array(buffer));
        }
        var bytes = new Uint8Array(buffer);
        var text = '';
        for (var i = 0; i < bytes.length; i++) {
            text += String.fromCharCode(bytes[i]);
        }
        try {
            return decodeURIComponent(escape(text));
        } catch (e) {
            return text;
        }
    }

    function handleIncomingText(text) {
        append(text);
        if (text.indexOf('SSH shell ready') !== -1) {
            window.setTimeout(function(){ send('\r'); }, 250);
        }
    }

    function wsPayload(data) {
        var mode = modeSelect ? modeSelect.value : 'raw';
        if (mode === 'raw') {
            return data;
        }
        if (mode === 'binary') {
            return new TextEncoder().encode(data);
        }
        if (mode === 'json-data') {
            return JSON.stringify({type:'data', data:data});
        }
        if (mode === 'json-stdin') {
            return JSON.stringify({type:'stdin', data:data});
        }
        return JSON.stringify({type:'input', data:data});
    }

    function send(data) {
        if (!socket || socket.readyState !== WebSocket.OPEN) {
            return false;
        }
        socket.send(wsPayload(data));
        sentCount++;
        updateIoStatus();
        flashKey(data === '\r' ? '回车' : data === '\x7f' ? '退格' : data.length > 1 ? data.length + ' 字符' : data);
        return true;
    }

    function keyToData(e) {
        if (e.ctrlKey && !e.altKey && !e.metaKey && e.key.length === 1) {
            var code = e.key.toUpperCase().charCodeAt(0);
            if (code >= 64 && code <= 95) {
                return String.fromCharCode(code - 64);
            }
        }
        var map = {
            Enter: '\r',
            Backspace: '\x7f',
            Tab: '\t',
            Escape: '\x1b',
            ArrowUp: '\x1b[A',
            ArrowDown: '\x1b[B',
            ArrowRight: '\x1b[C',
            ArrowLeft: '\x1b[D',
            Delete: '\x1b[3~',
            Home: '\x1b[H',
            End: '\x1b[F',
            PageUp: '\x1b[5~',
            PageDown: '\x1b[6~'
        };
        if (map[e.key]) {
            return map[e.key];
        }
        if (!e.ctrlKey && !e.altKey && !e.metaKey && e.key.length === 1) {
            return e.key;
        }
        return null;
    }

    document.addEventListener('keydown', function(e){
        if (e.target === cmd) {
            return;
        }
        var data = keyToData(e);
        if (data !== null && send(data)) {
            e.preventDefault();
        }
    });

    document.addEventListener('paste', function(e){
        if (e.target === cmd) {
            return;
        }
        var text = e.clipboardData ? e.clipboardData.getData('text/plain') : '';
        if (text && send(text)) {
            e.preventDefault();
        }
    });

    document.addEventListener('mousedown', function(){
        if (cmd) cmd.focus();
    });

    function sendCommandLine() {
        if (!cmd) return;
        var value = cmd.value;
        if (value === '') {
            send('\r');
            return;
        }
        if (send(value + '\r')) {
            cmd.value = '';
        }
    }

    if (sendcmd) {
        sendcmd.addEventListener('click', sendCommandLine);
    }
    if (sendEnter) {
        sendEnter.addEventListener('click', function(){
            send('\r');
            if (cmd) cmd.focus();
        });
    }
    if (cmd) {
        cmd.addEventListener('keydown', function(e){
            if (e.key === 'Enter') {
                sendCommandLine();
                e.preventDefault();
                return;
            }
            if (e.ctrlKey && e.key.toLowerCase() === 'c') {
                send('\x03');
                e.preventDefault();
            }
        });
    }
    window.setInterval(updateIoStatus, 1000);
})();
</script>
</body>
</html>
