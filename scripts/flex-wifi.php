<?php
$FLEX = '/usr/bin/siglent/usr/flex-wifi';
$CONF = '/usr/bin/siglent/usr/flex-wifi.conf';
$CTRL = '/tmp/wpa_ctrl';
$LIB  = 'LD_LIBRARY_PATH=' . $FLEX . '/libs';

function e($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function sh($cmd) {
    $out = shell_exec($cmd . ' 2>&1');
    return ($out === null) ? '' : trim($out);
}

function wpa($cmd) {
    global $FLEX, $CTRL, $LIB;
    return sh($LIB . ' ' . $FLEX . '/wpa_cli -p' . $CTRL . ' ' . $cmd);
}

function wpa_running() { return sh('pidof wpa_supplicant') !== ''; }

function start_wpa() {
    global $FLEX, $CONF, $CTRL, $LIB;
    sh('ip link set wlan0 up');
    sh($LIB . ' ' . $FLEX . '/wpa_supplicant -Dnl80211 -iwlan0 -c' . $CONF . ' -C' . $CTRL . ' -B');
}

function ensure_wpa() {
    global $CONF, $CTRL;
    if (wpa_running()) return true;
    if (!is_dir('/sys/class/net/wlan0')) return false;
    if (!file_exists($CONF))
        file_put_contents($CONF, "ctrl_interface=" . $CTRL . "\n");
    start_wpa();
    usleep(800000);
    return wpa_running();
}

function parse_scan_results() {
    $scan = array();
    $lines = explode("\n", wpa('scan_results'));
    for ($i = 1; $i < count($lines); $i++) {
        $p = explode("\t", trim($lines[$i]));
        if (count($p) >= 5 && $p[4] !== '')
            $scan[] = array('bssid'=>$p[0], 'freq'=>intval($p[1]),
                           'signal'=>intval($p[2]), 'flags'=>$p[3], 'ssid'=>$p[4]);
    }
    usort($scan, function($a, $b) { return $b['signal'] - $a['signal']; });
    return $scan;
}

function get_full_status() {
    $r = array('wpa'=>false, 'wlan'=>is_dir('/sys/class/net/wlan0'),
               'mod'=>strpos(sh('lsmod'), 'mt76x0u') !== false,
               'ip'=>'', 'mask'=>'', 'gw'=>'', 'mac'=>'', 'ssid'=>'', 'state'=>'', 'rssi'=>'', 'freq'=>'');
    if ($r['wlan']) {
        if (preg_match('/inet ([\d.]+)\/([\d]+)/', sh('ip -4 addr show wlan0'), $m)) {
            $r['ip'] = $m[1];
            $cidr = intval($m[2]);
            $r['mask'] = long2ip(~((1 << (32 - $cidr)) - 1));
        }
        if (preg_match('/default via ([\d.]+)/', sh('ip route show dev wlan0'), $m))
            $r['gw'] = $m[1];
    }
    if (!wpa_running()) return $r;
    $r['wpa'] = true;
    foreach (explode("\n", wpa('status')) as $line)
        if (strpos($line, '=') !== false) {
            list($k, $v) = explode('=', $line, 2);
            $k = trim($k); $v = trim($v);
            if ($k === 'ssid') $r['ssid'] = $v;
            if ($k === 'wpa_state') $r['state'] = $v;
            if ($k === 'address') $r['mac'] = $v;
            if ($k === 'freq') $r['freq'] = $v;
        }
    foreach (explode("\n", wpa('signal_poll')) as $line)
        if (strpos($line, 'RSSI=') === 0) $r['rssi'] = trim(substr($line, 5));
    return $r;
}

// AJAX endpoints
$ajax = isset($_GET['ajax']) ? $_GET['ajax'] : '';

if ($ajax === 'scan_trigger') {
    header('Content-Type: application/json');
    if (!is_dir('/sys/class/net/wlan0')) { echo json_encode(array('ok'=>false, 'msg'=>'No WiFi interface')); exit; }
    if (!ensure_wpa()) { echo json_encode(array('ok'=>false, 'msg'=>'Cannot start wpa_supplicant')); exit; }
    wpa('scan');
    echo json_encode(array('ok'=>true));
    exit;
}

if ($ajax === 'scan_results') {
    header('Content-Type: application/json');
    echo json_encode(array('networks'=>parse_scan_results()));
    exit;
}

if ($ajax === 'status') {
    header('Content-Type: application/json');
    echo json_encode(get_full_status());
    exit;
}

if ($ajax === 'connect') {
    header('Content-Type: application/json');
    $ssid = isset($_POST['ssid']) ? $_POST['ssid'] : '';
    $psk  = isset($_POST['psk'])  ? $_POST['psk']  : '';

    if ($ssid === '') { echo json_encode(array('ok'=>false, 'msg'=>'SSID is required.')); exit; }
    if (strlen($ssid) > 32) { echo json_encode(array('ok'=>false, 'msg'=>'SSID too long (max 32).')); exit; }
    if (!preg_match('/^[\x20-\x7E]+$/', $ssid)) { echo json_encode(array('ok'=>false, 'msg'=>'SSID contains invalid characters.')); exit; }
    if ($psk !== '' && !preg_match('/^[\x20-\x7E]{8,63}$/', $psk)) { echo json_encode(array('ok'=>false, 'msg'=>'Passphrase must be 8-63 printable characters.')); exit; }
    if (!is_dir('/sys/class/net/wlan0')) { echo json_encode(array('ok'=>false, 'msg'=>'No WiFi interface.')); exit; }

    $esc_ssid = str_replace(array('\\', '"'), array('\\\\', '\\"'), $ssid);
    if ($psk !== '') {
        $esc_psk = str_replace(array('\\', '"'), array('\\\\', '\\"'), $psk);
        $block = "network={\n\tssid=\"" . $esc_ssid . "\"\n\tpsk=\"" . $esc_psk . "\"\n}";
    } else {
        $block = "network={\n\tssid=\"" . $esc_ssid . "\"\n\tkey_mgmt=NONE\n}";
    }

    file_put_contents($CONF, "ctrl_interface=" . $CTRL . "\n\n" . $block . "\n");
    sh('killall wpa_cli 2>/dev/null');
    if (wpa_running()) { sh('killall wpa_supplicant'); sleep(1); }
    start_wpa();
    sh($LIB . ' ' . $FLEX . '/wpa_cli -p' . $CTRL . ' -a ' . $FLEX . '/wpa_action.sh -B');
    echo json_encode(array('ok'=>true, 'msg'=>'Configuration saved.'));
    exit;
}

if ($ajax === 'forget') {
    header('Content-Type: application/json');
    if (wpa_running()) { sh('killall wpa_supplicant'); sleep(1); }
    sh('ip link set wlan0 down');
    @unlink($CONF);
    echo json_encode(array('ok'=>true, 'msg'=>'Disconnected. Configuration removed.'));
    exit;
}

// Initial page state
$st = get_full_status();
$scan = ($st['wpa']) ? parse_scan_results() : array();
$has_conf = file_exists($CONF);

function band($f) { return $f > 5000 ? '5G' : '2.4G'; }
function sec($flags) {
    if (strpos($flags, 'WPA2') !== false || strpos($flags, 'RSN') !== false) return 'WPA2';
    if (strpos($flags, 'WPA') !== false) return 'WPA';
    if (strpos($flags, 'WEP') !== false) return 'WEP';
    return 'Open';
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>flex-wifi</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f0f2f5;color:#333;padding:16px}
.c{max-width:600px;margin:0 auto}
h1{font-size:20px;padding:12px 16px;background:#2c3e50;color:#fff;border-radius:8px 8px 0 0;letter-spacing:1px}
.card{background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);margin-bottom:16px;overflow:hidden}
.cb{padding:16px}
.ct{font-size:13px;font-weight:600;color:#7f8c8d;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px}
.sr{display:flex;align-items:center;gap:8px;margin-bottom:4px;font-size:14px}
.dot{width:10px;height:10px;border-radius:50%;display:inline-block;flex-shrink:0}
.dg{background:#27ae60}.dy{background:#bdc3c7}.dr{background:#e74c3c}
.msg{padding:10px 16px;border-radius:6px;margin-bottom:16px;font-size:14px}
.msg-ok{background:#d5f5e3;color:#1e8449}.msg-er{background:#fadbd8;color:#c0392b}
table{width:100%;border-collapse:collapse;font-size:14px}
th{text-align:left;padding:8px;border-bottom:2px solid #ecf0f1;color:#7f8c8d;font-size:12px;text-transform:uppercase}
td{padding:8px;border-bottom:1px solid #ecf0f1}
tr.cl:hover{background:#ebf5fb;cursor:pointer}
.btn{display:inline-block;padding:8px 16px;border:none;border-radius:4px;font-size:14px;cursor:pointer;color:#fff}
.bb{background:#3498db}.bb:hover{background:#2980b9}
.br{background:#e74c3c}.br:hover{background:#c0392b}
.btn:disabled{background:#bdc3c7;cursor:not-allowed}
input[type=text],input[type=password]{width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:4px;font-size:14px;margin-bottom:8px}
input:focus{outline:none;border-color:#3498db}
label{display:block;font-size:13px;color:#7f8c8d;margin-bottom:4px}
.fr{margin-bottom:12px}
.mono{font-family:monospace;font-size:13px}
.sub{font-size:13px;color:#95a5a6}
.hdr{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.spin{display:inline-block;width:14px;height:14px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:sp .8s linear infinite;vertical-align:middle}
@keyframes sp{to{transform:rotate(360deg)}}
.info-row{display:flex;gap:16px;flex-wrap:wrap;font-size:13px;color:#555;margin-top:4px}
.info-row span{display:flex;align-items:center;gap:4px}
.info-label{color:#95a5a6}
</style>
</head>
<body>
<div class="c">

<div class="card">
<h1>flex-wifi</h1>
<div class="cb">
  <div class="ct">Status</div>
  <div id="status-area"></div>
</div>
</div>

<div id="msg-area"></div>

<div class="card">
<div class="cb">
  <div class="hdr">
    <span class="ct" style="margin:0">Available Networks</span>
    <button class="btn bb" id="scan-btn" onclick="doScan()">Scan</button>
  </div>
  <div id="scan-area"><div class="sub">No scan results. Click Scan to search for networks.</div></div>
</div>
</div>

<div class="card">
<div class="cb">
  <div class="ct">Connect</div>
  <div class="fr">
    <label for="ssid">SSID</label>
    <input type="text" id="ssid" autocomplete="off">
  </div>
  <div class="fr">
    <label for="psk">Passphrase <span class="sub">(leave empty for open networks)</span></label>
    <input type="password" id="psk" autocomplete="off">
  </div>
  <button class="btn bb" id="connect-btn" onclick="doConnect()">Connect</button>
</div>
</div>

<div id="footer" style="text-align:center;font-size:12px;color:#bdc3c7;padding:4px"></div>

</div>

<script>
function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}

function showMsg(text, isErr) {
    var a = document.getElementById('msg-area');
    if (!text) { a.innerHTML = ''; return; }
    a.innerHTML = '<div class="msg ' + (isErr ? 'msg-er' : 'msg-ok') + '">' + esc(text) + '</div>';
}

function renderStatus(s) {
    var a = document.getElementById('status-area');
    var f = document.getElementById('footer');
    f.innerHTML = 'mt76x0u ' + (s.mod ? '&#10003;' : '&#10007;') +
                  ' &middot; wlan0 ' + (s.wlan ? '&#10003;' : '&#10007;') +
                  ' &middot; wpa ' + (s.wpa ? '&#10003;' : '&#10007;');

    if (!s.wlan) {
        a.innerHTML = '<div class="sr"><span class="dot dr"></span> No WiFi interface detected</div>' +
            '<div class="sub">' + (s.mod ? 'Modules loaded but wlan0 missing.' : 'Kernel modules not loaded. Is the dongle plugged in?') + '</div>';
        return;
    }
    if (s.state === 'COMPLETED') {
        var h = '<div class="sr"><span class="dot dg"></span> Connected to <strong>' + esc(s.ssid) + '</strong></div>';
        h += '<div class="info-row">';
        if (s.ip) h += '<span><span class="info-label">IP</span> <span class="mono">' + esc(s.ip) + '</span></span>';
        if (s.mask) h += '<span><span class="info-label">Mask</span> <span class="mono">' + esc(s.mask) + '</span></span>';
        if (s.gw) h += '<span><span class="info-label">Gateway</span> <span class="mono">' + esc(s.gw) + '</span></span>';
        if (s.mac) h += '<span><span class="info-label">MAC</span> <span class="mono">' + esc(s.mac) + '</span></span>';
        if (s.rssi) h += '<span><span class="info-label">Signal</span> ' + esc(s.rssi) + ' dBm</span>';
        if (s.freq) h += '<span><span class="info-label">Freq</span> ' + esc(s.freq) + ' MHz</span>';
        h += '</div>';
        h += '<div style="margin-top:12px"><button class="btn br" onclick="doForget()">Forget Network</button></div>';
        a.innerHTML = h;
    } else if (s.wpa) {
        var state = s.state ? s.state.replace(/_/g, ' ').toLowerCase() : 'not connected';
        a.innerHTML = '<div class="sr"><span class="dot dy"></span> ' + esc(state) + '</div>';
    } else {
        a.innerHTML = '<div class="sr"><span class="dot dy"></span> WiFi ready — not configured</div>' +
            '<div class="sub">Scan for networks below.</div>';
    }
}

function renderNetworks(networks) {
    var a = document.getElementById('scan-area');
    if (!networks || networks.length === 0) {
        a.innerHTML = '<div class="sub">No networks found.</div>';
        return;
    }
    var h = '<table><tr><th>Network</th><th>Band</th><th>dBm</th><th>Security</th></tr>';
    for (var i = 0; i < networks.length; i++) {
        var n = networks[i];
        var ssidAttr = JSON.stringify(n.ssid).replace(/&/g,'&amp;').replace(/"/g,'&quot;');
        h += '<tr class="cl" onclick="selNet(' + ssidAttr + ')">';
        h += '<td>' + esc(n.ssid) + '</td>';
        h += '<td>' + (n.freq > 5000 ? '5G' : '2.4G') + '</td>';
        h += '<td class="mono">' + n.signal + '</td>';
        var sec = 'Open';
        if (n.flags.indexOf('WPA2') >= 0 || n.flags.indexOf('RSN') >= 0) sec = 'WPA2';
        else if (n.flags.indexOf('WPA') >= 0) sec = 'WPA';
        else if (n.flags.indexOf('WEP') >= 0) sec = 'WEP';
        h += '<td>' + sec + '</td></tr>';
    }
    h += '</table>';
    a.innerHTML = h;
}

function selNet(ssid) {
    document.getElementById('ssid').value = ssid;
    document.getElementById('psk').focus();
}

function ajax(method, url, data, cb) {
    var xhr = new XMLHttpRequest();
    xhr.open(method, url);
    if (method === 'POST') xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() { cb(JSON.parse(xhr.responseText)); };
    xhr.onerror = function() { cb(null); };
    xhr.send(data || null);
}

function refreshStatus(cb) {
    ajax('GET', '?ajax=status', null, function(s) {
        if (s) renderStatus(s);
        if (cb) cb(s);
    });
}

// Scan
var scanTimer = null, scanCount = 0, prevCount = -1;

function doScan() {
    var btn = document.getElementById('scan-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spin"></span> Scanning';
    document.getElementById('scan-area').innerHTML = '<div class="sub"><span class="spin" style="border-color:#3498db;border-top-color:transparent"></span> Scanning...</div>';
    showMsg('');

    ajax('GET', '?ajax=scan_trigger', null, function(r) {
        if (!r || !r.ok) {
            document.getElementById('scan-area').innerHTML = '<div class="sub" style="color:#e74c3c">' + (r ? esc(r.msg) : 'Error') + '</div>';
            btn.disabled = false; btn.textContent = 'Scan';
            return;
        }
        scanCount = 0; prevCount = -1;
        scanTimer = setInterval(pollResults, 2000);
    });
}

function pollResults() {
    scanCount++;
    ajax('GET', '?ajax=scan_results', null, function(r) {
        var n = (r && r.networks) ? r.networks.length : 0;
        if (n > 0 && n === prevCount) { finishScan(r.networks); return; }
        prevCount = n;
        if (scanCount >= 8) finishScan(r ? r.networks : []);
    });
}

function finishScan(networks) {
    clearInterval(scanTimer);
    renderNetworks(networks);
    var btn = document.getElementById('scan-btn');
    btn.disabled = false; btn.textContent = 'Scan';
    refreshStatus();
}

// Connect
function doConnect() {
    var ssid = document.getElementById('ssid').value;
    var psk = document.getElementById('psk').value;
    if (!ssid) { showMsg('SSID is required.', true); return; }

    var btn = document.getElementById('connect-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spin"></span> Connecting';
    showMsg('');

    var data = 'ssid=' + encodeURIComponent(ssid) + '&psk=' + encodeURIComponent(psk);
    ajax('POST', '?ajax=connect', data, function(r) {
        if (!r || !r.ok) {
            showMsg(r ? r.msg : 'Connection error', true);
            btn.disabled = false; btn.textContent = 'Connect';
            return;
        }
        showMsg(r.msg, false);
        // Poll status until connected or timeout
        var attempts = 0;
        var poll = setInterval(function() {
            attempts++;
            refreshStatus(function(s) {
                if (s && s.state === 'COMPLETED' && s.ip) {
                    clearInterval(poll);
                    btn.disabled = false; btn.textContent = 'Connect';
                }
                if (attempts >= 10) {
                    clearInterval(poll);
                    btn.disabled = false; btn.textContent = 'Connect';
                    if (!s || s.state !== 'COMPLETED')
                        showMsg('Connection taking longer than expected. Check status.', true);
                }
            });
        }, 2000);
    });
}

// Forget
function doForget() {
    if (!confirm('Disconnect and remove saved config?')) return;
    ajax('POST', '?ajax=forget', null, function(r) {
        if (r) showMsg(r.msg, !r.ok);
        refreshStatus();
    });
}

// Initial render
renderStatus(<?=json_encode($st)?>);
<?php if (count($scan) > 0): ?>
renderNetworks(<?=json_encode($scan)?>);
<?php endif; ?>

// Auto-refresh status every 5s
setInterval(function(){ refreshStatus(); }, 5000);
</script>
</body>
</html>
