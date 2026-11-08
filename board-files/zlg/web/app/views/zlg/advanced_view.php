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
      without a request. The inverters answer only while they produce (daylight).</li>
    <li><b>Set</b> writes the settings shown to the selected modules. Wrong values can make the inverters
      disconnect from the grid or run outside what your utility allows: change them only with the values
      your interconnection agreement requires, and try one module first.</li>
    <li>The password field of NEP's page isn't needed: the gateway fills it in.</li>
  </ul>
</div>
<iframe id="misetup" src="<?php echo base_url(); ?>advanced/ui" style="width:100%;height:1400px;border:0;background:#fff;border-radius:5px" title="MI Setup"></iframe>
<script type="text/javascript">
$(function () {
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
