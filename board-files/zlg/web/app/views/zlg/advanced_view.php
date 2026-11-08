<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="zlg-page">
<h2>Advanced: inverter grid settings</h2>
<?php if (!$built): ?>
  <div class="zlg-msg err">Not available on this gateway: the Advanced page uses the MI Setup page of NEP's
    firmware 20230903, and this gateway's software is another version.</div>
<?php else: ?>
<div class="zlg-box">
  This is NEP's MI Setup page. It reads the grid settings of the inverters (voltage and frequency trip
  limits, power limit, grid code) and, after logging in, writes them.
  <ul style="margin:6px 0 0 18px;padding:0">
    <li><b>Check</b> reads the selected modules. It first enters <b>setup mode</b>: the gateway stops polling
      the inverters (no new readings) until you press <b>Leave Setup Mode</b>, or by itself after 10 minutes
      without a request. The inverters answer only while they produce (daylight). An 800B inverter stops
      producing for about 5 minutes (its reconnect time) after each read or write.</li>
    <li><b>Set</b> writes the settings shown to the selected modules. Wrong values can make the inverters
      disconnect from the grid or run outside what your utility allows: change them only with the values
      your interconnection agreement requires, and try one module first.</li>
    <li><b>800B inverters</b> (IDs starting 33) keep their settings in the encoding of NEP's older MI Setup
      (2021), not this page's: for them the page reads and writes in that encoding, on grid codes 6
      (1547.1 240/120V) and 1. It holds 161.5 to 278.5 V, nominal &plusmn; 7.9 Hz, fast trip times up to 3.1 s
      and slow ones up to 204.7 s, in 0.1 s steps (rounded down). A value outside that is refused, not
      changed. Check a module before Set: a write keeps the module's current values for the fields that
      aren't checked. The page shows the NEP range hints of its own encoding; the messages under
      Inverter Output give the decoded 2021 values.</li>
    <li>The password field of NEP's page isn't needed: the gateway fills it in.</li>
  </ul>
</div>
<div class="zlg-box zlg-form" id="report">
  <b>Grid report.</b> Reads every inverter's settings and gives a .tgz file with a CSV and a PDF of them
  (decoded as this page shows them, 800B inverters in their 2021 encoding, with the raw replies). It takes about 10 seconds per module;
  the gateway has no new readings meanwhile, the inverters answer only while they produce, and each one
  stops producing for about 5 minutes after it is read.
  <div class="row" style="margin-top:8px">
<?php if ($logged_in): ?>
    <button class="zlg-btn" type="button" id="rstart">Generate Grid Report</button>
    <span id="rstate" style="margin-left:12px"></span>
<?php else: ?>
    <span class="zlg-muted">Log in (top right) to make a report.</span>
<?php endif; ?>
  </div>
</div>
<iframe id="misetup" src="<?php echo base_url(); ?>advanced/ui" style="width:100%;height:1400px;border:0;background:#fff;border-radius:5px" title="MI Setup"></iframe>
<script type="text/javascript">
$(function () {
  var base = "<?php echo base_url(); ?>advanced/";
  var csrf = "<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>";
  function esc(s) { return $("<div>").text(s).html(); }
  function show(r) {
    var a = r.report || {};
    if (a.state === "starting" || a.state === "reading") {
      $("#rstart").prop("disabled", true);
      $("#rstate").text("Reading module " + (a.done || 0) + " of " + (a.total || "?") + " ...");
      setTimeout(poll, 3000);
    } else {
      $("#rstart").prop("disabled", false);
      if (a.state === "done")
        $("#rstate").html('Ready (' + esc(a.message || "") + '): <a href="' + base + 'report_download?id=' +
          encodeURIComponent(a.id) + '">' + esc(a.name) + '</a>');
      else if (a.state === "failed")
        $("#rstate").text("Failed: " + (a.message || "unknown error"));
    }
  }
  function poll() {
    $.ajax({ url: base + "report_status", dataType: "json", cache: false, timeout: 10000 })
      .done(show).fail(function () { setTimeout(poll, 5000); });
  }
  $("#rstart").on("click", function () {
    if (!confirm("Read every inverter's settings now? The gateway has no new readings until it is done (about 10 seconds per module)."))
      return;
    $("#rstart").prop("disabled", true);
    $.ajax({ url: base + "report_start", method: "POST", dataType: "json",
             data: { csrf: csrf } })
      .done(function (r) {
        if (!r.ok) { $("#rstate").text("Not started: " + r.error + "."); $("#rstart").prop("disabled", false); return; }
        poll();
      })
      .fail(function () { $("#rstate").text("The gateway didn't answer."); $("#rstart").prop("disabled", false); });
  });
  if ($("#rstart").length) poll();

  /* the frame grows with NEP's page (same origin) */
  var f = document.getElementById("misetup");
  setInterval(function () {
    try {
      var h = f.contentWindow.document.body.scrollHeight;
      if (h > 200 && Math.abs(h + 40 - f.offsetHeight) > 20) f.style.height = (h + 40) + "px";
    } catch (e) {}
  }, 1000);
});
</script>
<?php endif; ?>
</div>
