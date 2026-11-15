<?php defined('BASEPATH') OR exit('No direct script access allowed');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ok = !empty($st['ok']);
$link = $st['link'] ?? null;
$v4 = $st['ipv4'] ?? ['mode' => 'dhcp', 'address' => '', 'gateway' => '', 'dns' => []];
$net = ($st['networks'] ?? [])[0] ?? ['ssid' => '', 'security' => 'wpa2'];
$secname = ['open' => 'None (open)', 'wpa2' => 'WPA2-Personal', 'wpa3' => 'WPA3-Personal'];
$connected = $link && ($link['state'] ?? '') === 'COMPLETED';
$chan = function ($f) {
	if (!$f) return '';
	if ($f >= 2412 && $f <= 2472) return ($f - 2407) / 5 . ' (2.4 GHz)';
	if ($f == 2484) return '14 (2.4 GHz)';
	if ($f >= 5000) return ($f - 5000) / 5 . ' (5 GHz)';
	return "$f MHz";
};
?>
<div class="zlg-page">
<?php if (!$ok): ?>
  <div class="zlg-msg err"><?php echo $h(ucfirst($st['error'] ?? 'unknown error')); ?>.</div>
<?php else: ?>

<h2>Wi-Fi connection</h2>
<div class="zlg-box">
<?php if (!$st['present']): ?>
  <span class="zlg-muted">No Wi-Fi adapter is plugged in.</span>
<?php else: ?>
  <table class="zlg-kv">
    <tr><th>Status</th><td><?php echo $connected ? 'Connected' : $h($link['state'] ?: 'Not connected'); ?></td></tr>
    <tr><th>Network</th><td><?php echo $h($link['ssid'] ?: '–'); ?></td></tr>
<?php if ($connected): ?>
    <tr><th>Access point</th><td><?php echo $h($link['bssid']); ?>, channel <?php echo $h($chan($link['freq'])); ?></td></tr>
    <tr><th>Signal</th><td><?php echo $link['signal'] !== null ? $h($link['signal'] . ' dBm') : '–'; ?></td></tr>
    <tr><th>Bit rate</th><td><?php echo $h($link['rx_rate'] ?: '–'); ?> receive, <?php echo $h($link['tx_rate'] ?: '–'); ?> send</td></tr>
<?php endif; ?>
    <tr><th>IP address</th><td><?php echo $h($link['ip'] ?: '–'); echo $st['prefix'] ? '/' . (int)$st['prefix'] : ''; ?>
      (<?php echo $v4['mode'] === 'dhcp' ? 'from the router, DHCP' : 'fixed'; ?>)</td></tr>
    <tr><th>Router</th><td><?php echo $h($st['route'] ?: '–'); ?></td></tr>
    <tr><th>Adapter</th><td><?php echo $h($st['driver']); ?></td></tr>
  </table>
<?php endif; ?>
</div>

<h2>Wi-Fi settings</h2>
<?php if (!$logged_in): ?>
<div class="zlg-box">
  <table class="zlg-kv">
    <tr><th>Network</th><td><?php echo $h($net['ssid'] ?: '–'); ?></td></tr>
    <tr><th>Security</th><td><?php echo $h($secname[$net['security']] ?? $net['security']); ?></td></tr>
    <tr><th>IP address</th><td><?php echo $v4['mode'] === 'dhcp' ? 'From the router (DHCP)' :
      $h($v4['address'] . ', router ' . $v4['gateway'] . ', DNS ' . implode(' ', $v4['dns'])); ?></td></tr>
  </table>
  <p class="zlg-muted" style="margin:8px 0 0">Log in (top right) to change these settings.</p>
</div>
<?php else: ?>
<div class="zlg-box zlg-form">
  <form id="wf" onsubmit="return false">
    <div class="row"><label>Network name</label><input type="text" name="ssid" id="ssid" maxlength="32" value="<?php echo $h($net['ssid']); ?>"/>
      <button class="zlg-btn light" type="button" id="scan">Search</button>
      <div id="scanres" style="margin-left:154px"></div></div>
    <div class="row"><label>Security</label><select name="security" id="security">
<?php foreach ($secname as $k => $n): ?>
      <option value="<?php echo $k; ?>"<?php echo $net['security'] === $k ? ' selected' : ''; ?>><?php echo $n; ?></option>
<?php endforeach; ?>
    </select>
      <span class="hint">WPA2-Personal also works with access points in WPA2/WPA3 mode. WPA3-Personal is only for networks without WPA2 (not tested on this gateway).</span></div>
    <div class="row" id="passrow"><label>Wi-Fi password</label><input type="password" name="passphrase" id="passphrase" maxlength="63" autocomplete="new-password"/>
      <span class="hint" id="passhint">Leave empty to keep the stored password for this network.</span></div>
    <div class="row"><label>IP address</label><select name="ipv4" id="ipv4">
      <option value="dhcp"<?php echo $v4['mode'] === 'dhcp' ? ' selected' : ''; ?>>From the router (DHCP)</option>
      <option value="static"<?php echo $v4['mode'] === 'static' ? ' selected' : ''; ?>>Fixed</option>
    </select></div>
    <div id="staticrows">
      <div class="row"><label>Address</label><input type="text" name="address" value="<?php echo $h($v4['address']); ?>" placeholder="192.168.1.50/24"/>
        <span class="hint">With the network size, e.g. /24 for 255.255.255.0.</span></div>
      <div class="row"><label>Router</label><input type="text" name="gateway" value="<?php echo $h($v4['gateway']); ?>" placeholder="192.168.1.1"/></div>
      <div class="row"><label>DNS servers</label><input type="text" name="dns" value="<?php echo $h(implode(' ', $v4['dns'])); ?>" placeholder="the router if empty"/></div>
    </div>
    <div class="row"><label></label><button class="zlg-btn" type="button" id="save">Save and connect</button></div>
    <p class="zlg-muted" style="margin:10px 0 0">After saving, the gateway reconnects with the new settings. If it can't connect
      within 90 seconds, it puts the current settings back by itself. If the power goes off during
      that time, the current settings come back at the next start.</p>
  </form>
</div>
<?php endif; ?>
<div id="progress" class="zlg-msg info" style="display:none"></div>

<?php endif; ?>

<?php
/* Home Assistant (nep-mqtt, README 7b): defaults until it is set up */
$ha = is_array($ha ?? null) ? $ha : [];
$ha_ok = !empty($ha['ok']);
$ha_set = $ha_ok && !empty($ha['password_set']) && ($ha['url'] ?? '') !== '';
$ha_url = $ha_set ? $ha['url'] : 'http://homeassistant.local';
$ha_port = $ha_set && ($ha['port'] ?? '') !== '' ? $ha['port'] : '1883';
$ha_user = $ha_set && ($ha['username'] ?? '') !== '' ? $ha['username'] : 'nep';
?>
<h2 id="ha">Home Assistant</h2>
<div class="zlg-box">
<?php if (!$ha_ok): ?>
  <span class="zlg-muted"><?php echo $h(ucfirst($ha['error'] ?? 'unknown error')); ?>.</span>
<?php else: ?>
  <table class="zlg-kv">
    <tr><th>Status</th><td><?php
      if (empty($ha['installed']))
        echo 'Not installed on this gateway. Install it once with <code>sudo sh /usr/local/share/zlg/ha-mqtt/install.sh</code>.';
      elseif (!$ha_set)
        echo 'Not set up yet.';
      elseif (empty($ha['enabled']))
        echo 'Off.';
      else
        echo !empty($ha['active']) ? 'On: the solar data goes to Home Assistant every minute.' : 'On, but the service isn\'t running.';
    ?></td></tr>
<?php if ($ha_set): ?>
    <tr><th>Home Assistant</th><td><?php echo $h($ha['url'] . ', port ' . ($ha['port'] ?: '1883') . ', user ' . $ha['username']); ?></td></tr>
<?php endif; ?>
<?php if (!empty($ha['installed']) && ($ha['log'] ?? '') !== ''): ?>
    <tr><th>Last message</th><td><?php echo $h($ha['log']); ?></td></tr>
<?php endif; ?>
  </table>
<?php endif; ?>
</div>

<?php if ($ha_ok && !empty($ha['installed'])): ?>
<?php if (!$logged_in): ?>
<div class="zlg-box"><p class="zlg-muted" style="margin:0">Log in (top right) to change these settings.</p></div>
<?php else: ?>
<div class="zlg-box zlg-form">
  <form id="haf" onsubmit="return false">
    <div class="row"><label></label><input type="checkbox" name="enabled" id="ha_on" value="1"<?php echo !$ha_set || !empty($ha['enabled']) ? ' checked' : ''; ?>/>Send the solar data to Home Assistant</div>
    <div id="ha_rows">
    <div class="row"><label>Home Assistant URL</label><input type="text" name="url" maxlength="200" value="<?php echo $h($ha_url); ?>"/>
      <span class="hint">Its address as you open it in the browser, without the port (:8123). Only the name or IP address is used.
        If the gateway can't find homeassistant.local, enter Home Assistant's IP address (Settings &gt; System &gt; Network).</span></div>
    <div class="row"><label>Port</label><input type="text" name="port" class="short" maxlength="5" value="<?php echo $h($ha_port); ?>"/>
      <span class="hint">The Mosquitto broker's MQTT port: 1883 unless you changed it.</span></div>
    <div class="row"><label>User name</label><input type="text" name="username" maxlength="64" autocomplete="off" value="<?php echo $h($ha_user); ?>"/></div>
    <div class="row"><label>Password</label><input type="password" name="password" maxlength="128" autocomplete="new-password"/>
      <span class="hint"><?php echo $ha_set ? 'Leave empty to keep the stored password.' : 'The password of the Home Assistant user above.'; ?></span></div>
    </div>
    <div class="row"><label></label><button class="zlg-btn" type="button" id="ha_save">Save</button></div>
    <p class="zlg-muted" style="margin:10px 0 0">Before saving, the gateway logs in to Home Assistant's broker with these
      settings; nothing changes if that fails.</p>
  </form>
</div>
<div id="ha_msg" class="zlg-msg" style="display:none"></div>
<?php endif; ?>
<?php endif; ?>

<h2>Adding the gateway's user in Home Assistant</h2>
<div class="zlg-box">
  The gateway sends its data to Home Assistant's MQTT broker and logs in there as a Home Assistant user.
  Give it a user of its own, so it never needs your login:
  <ol class="zlg-steps">
    <li><b>The broker</b>, once: in Home Assistant, open Settings &gt; Add-ons &gt; Add-on store, install
      <b>Mosquitto broker</b> and start it. Home Assistant then offers to set up the <b>MQTT</b> integration
      (Settings &gt; Devices &amp; services): accept it.</li>
    <li><b>The user</b>: Settings &gt; People &gt; <b>Add person</b>. Name it, for example, "NEP gateway", turn on
      <b>Allow login</b>, and enter the user name <b>nep</b> (Home Assistant's user names are lower case) and a
      password of its own. Leave <b>Administrator</b> off: the gateway needs no other rights. Don't use the names
      "homeassistant" or "addons", which the broker keeps for itself.</li>
    <li><b>Here</b>: log in (top right), enter the same user name and password with Home Assistant's address, and
      press Save. Within a minute the gateway and every inverter appear under Settings &gt; Devices &amp; services
      &gt; MQTT. Their energy sensors can be added to the Energy dashboard (Settings &gt; Dashboards &gt; Energy).</li>
  </ol>
  <p class="zlg-muted" style="margin:6px 0 0">The Mosquitto broker accepts Home Assistant's users as MQTT logins,
    so the broker itself needs no settings. Menu names can differ a little between Home Assistant versions.</p>
</div>
</div>

<script type="text/javascript">
$(function () {
  var base = "<?php echo base_url(); ?>wifi/";
  var csrf = "<?php echo $h($csrf); ?>";
  var stored = <?php echo json_encode(array_map(fn($n) => $n['ssid'] . "\n" . $n['security'], $st['networks'] ?? [])); ?>;
  function esc(s) { return $("<div>").text(s).html(); }
  function update() {
    var sec = $("#security").val();
    $("#passrow").toggle(sec !== "open");
    $("#staticrows").toggle($("#ipv4").val() === "static");
    var known = stored.indexOf($("#ssid").val() + "\n" + sec) >= 0;
    $("#passhint").text(known ? "Leave empty to keep the stored password for this network."
                              : "Needed: no password is stored for this network.");
  }
  $("#security, #ipv4").on("change", update);
  $("#ssid").on("input", update);
  update();

  $("#scan").on("click", function () {
    var b = $(this).prop("disabled", true);
    $("#scanres").html('<span class="zlg-muted">Searching (about 5 seconds) ...</span>');
    $.ajax({ url: base + "scan", method: "POST", data: { csrf: csrf }, dataType: "json", timeout: 60000 })
      .done(function (r) {
        if (!r.ok) { $("#scanres").html('<span class="zlg-muted">' + esc(r.error) + '</span>'); return; }
        var t = $('<table class="zlg-list"><tr><th>Network</th><th>Signal</th><th>Security</th><th>Access points</th></tr></table>');
        $.each(r.networks, function (i, n) {
          var sec = { open: "None", wpa2: "WPA2", wpa3: "WPA3", wep: "WEP (not supported)" }[n.security];
          var tr = $('<tr class="pick"><td></td><td></td><td></td><td></td></tr>');
          tr.children().eq(0).text(n.ssid);
          tr.children().eq(1).text(n.signal + " dBm");
          tr.children().eq(2).text(sec);
          tr.children().eq(3).text(n.aps);
          tr.on("click", function () {
            $("#ssid").val(n.ssid);
            if (n.security !== "wep") $("#security").val(n.security);
            update();
          });
          t.append(tr);
        });
        $("#scanres").empty().append(r.networks.length ? t : '<span class="zlg-muted">No networks found.</span>');
      })
      .fail(function () { $("#scanres").html('<span class="zlg-muted">The search failed.</span>'); })
      .always(function () { b.prop("disabled", false); });
  });

  function show(cls, html) { $("#progress").attr("class", "zlg-msg " + cls).html(html).show(); }

  function poll(id, t0, wait) {
    var secs = Math.round((Date.now() - t0) / 1000);
    $.ajax({ url: base + "status", dataType: "json", timeout: 4000, cache: false })
      .done(function (r) {
        var a = r.apply || {};
        if (a.id !== id) return again();
        if (a.state === "ok") {
          show("ok", "Connected to <b>" + esc(a.ssid) + "</b> as " + esc(a.ip) + ". " +
            (location.hostname !== a.ip && /^[\d.]+$/.test(location.hostname)
              ? 'The gateway\'s address has changed: <a href="http://' + esc(a.ip) + '/nep/wifi">open it here</a>.'
              : '<a href="' + base + '">Reload the page</a>.'));
        } else if (a.state === "rolled_back" || a.state === "rollback_failed" || a.state === "failed") {
          show("err", "Not changed: " + esc(a.message || a.state) + ". <a href=\"" + base + "\">Reload the page</a>.");
        } else {
          show("info", (a.state === "rolling_back" ? "That didn't connect; putting the previous settings back ... "
                                                   : "Connecting to <b>" + esc(a.ssid) + "</b> ... ") + secs + " s");
          again();
        }
      })
      .fail(function () {
        show("info", "Connecting ... " + secs + " s. The gateway is unreachable while it reconnects; this page keeps trying.");
        again();
      });
    function again() {
      if (secs > 2 * wait + 60) {
        show("err", "No answer from the gateway after " + secs + " s. If you gave it a fixed address, open that address; " +
          "otherwise check the router's list of devices.");
        return;
      }
      setTimeout(function () { poll(id, t0, wait); }, 3000);
    }
  }

  $("#save").on("click", function () {
    if (!confirm("Save these Wi-Fi settings? The gateway reconnects, and goes back to the current settings if it can't connect within 90 seconds."))
      return;
    var b = $(this).prop("disabled", true);
    $.ajax({ url: base + "save", method: "POST", data: $("#wf").serialize() + "&csrf=" + encodeURIComponent(csrf),
             dataType: "json", timeout: 30000 })
      .done(function (r) {
        if (!r.ok) { show("err", "Not saved: " + esc(r.error) + "."); b.prop("disabled", false); return; }
        $("#wf :input").prop("disabled", true);
        poll(r.id, Date.now(), r.wait);
      })
      .fail(function () { show("err", "The gateway didn't answer."); b.prop("disabled", false); });
  });

  /* Home Assistant */
  function haShow(cls, html) { $("#ha_msg").attr("class", "zlg-msg " + cls).html(html).show(); }
  function haRows() { $("#ha_rows").toggle($("#ha_on").prop("checked")); }
  $("#ha_on").on("change", haRows);
  haRows();
  $("#ha_save").on("click", function () {
    var b = $(this).prop("disabled", true);
    var on = $("#ha_on").prop("checked");
    haShow("info", on ? "Logging in to Home Assistant's broker ..." : "Turning it off ...");
    $.ajax({ url: base + "ha_save", method: "POST", data: $("#haf").serialize() + "&csrf=" + encodeURIComponent(csrf),
             dataType: "json", timeout: 45000 })
      .done(function (r) {
        if (!r.ok) { haShow("err", "Not saved: " + esc(r.error) + "."); return; }
        haShow("ok", (r.enabled ? "Saved: the gateway logged in to " + esc(r.host) + " and now sends its data there. " +
                                  "The devices appear in Home Assistant within a minute. "
                                : "Turned off; the settings are kept. ") +
          '<a href="' + base + '#ha">Reload the page</a>.');
        $("#haf input[name=password]").val("");
      })
      .fail(function () { haShow("err", "The gateway didn't answer."); })
      .always(function () { b.prop("disabled", false); });
  });
});
</script>
