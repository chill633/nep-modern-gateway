<?php defined('BASEPATH') OR exit('No direct script access allowed');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ok = !empty($mods['ok']);
$ids = $ok ? $mods['ids'] : [];
?>
<div class="zlg-page">
<?php if (!$ok): ?>
  <div class="zlg-msg err"><?php echo $h(ucfirst($mods['error'] ?? 'unknown error')); ?>.</div>
<?php else: ?>
<h2>Modules</h2>
<p style="margin-top:0">The IDs the gateway polls, in panel order: module 1 is panel 1 on the Overview page.
Each inverter has two IDs, one per input, differing only in the last digit.
<?php echo count($ids); ?> modules are listed.</p>
<?php if (!empty($mods['phase_3'])): ?>
<div class="zlg-msg info">This gateway is set for three-phase. The phase of each module isn't shown or changed here.</div>
<?php endif; ?>
<div class="zlg-box zlg-form">
  <form id="mf" onsubmit="return false">
    <table class="zlg-list" id="mt">
      <tr><th>Module</th><th>ID</th></tr>
<?php foreach ($ids as $i => $v): ?>
      <tr><td><?php echo $i + 1; ?></td><td>
<?php if ($logged_in): ?>
        <input type="text" name="ids[]" value="<?php echo $h($v); ?>" maxlength="16" style="width:150px;font-family:monospace"/>
<?php else: ?>
        <span style="font-family:monospace"><?php echo $h($v); ?></span>
<?php endif; ?>
      </td></tr>
<?php endforeach; ?>
    </table>
<?php if ($logged_in): ?>
    <p style="margin:10px 0">
      <button class="zlg-btn light" type="button" id="add">Add a module</button>
      <span class="zlg-muted">An ID is 1 to 8 hex digits, or a whole serial number such as 21508-0005F123-U.
      To remove the last modules, empty their fields; the list can't have gaps.</span></p>
    <button class="zlg-btn" type="button" id="save">Save and restart</button>
    <p class="zlg-muted" style="margin:10px 0 0">Saving restarts the gateway software so it polls the new list
      (about a minute without readings; the web pages are back after about 30 seconds). The previous list is kept as a backup.</p>
<?php else: ?>
    <p class="zlg-muted" style="margin:10px 0 0">Log in (top right) to change the list.</p>
<?php endif; ?>
  </form>
</div>
<div id="progress" class="zlg-msg info" style="display:none"></div>
<?php endif; ?>
</div>
<?php if ($ok && $logged_in): ?>
<script type="text/javascript">
$(function () {
  var base = "<?php echo base_url(); ?>modules/";
  var csrf = "<?php echo $h($csrf); ?>";
  var max = <?php echo (int)$mods['slots']; ?>;
  function esc(s) { return $("<div>").text(s).html(); }
  function show(cls, html) { $("#progress").attr("class", "zlg-msg " + cls).html(html).show(); }
  $("#add").on("click", function () {
    var n = $("#mt tr").length;
    if (n > max) return;
    $("#mt").append('<tr><td>' + n + '</td><td><input type="text" name="ids[]" maxlength="16" style="width:150px;font-family:monospace"/></td></tr>');
    $("#mt tr:last input").focus();
  });
  function back(t0) {
    $.ajax({ url: base, timeout: 4000, cache: false })
      .done(function () { location.href = base; })
      .fail(function () {
        show("info", "Restarting the gateway software ... " + Math.round((Date.now() - t0) / 1000) + " s");
        setTimeout(function () { back(t0); }, 3000);
      });
  }
  $("#save").on("click", function () {
    if (!confirm("Save the module list and restart the gateway software?")) return;
    var b = $(this).prop("disabled", true);
    $.ajax({ url: base + "save", method: "POST", dataType: "json", timeout: 30000,
             data: $("#mf").serialize() + "&csrf=" + encodeURIComponent(csrf) })
      .done(function (r) {
        if (!r.ok) { show("err", "Not saved: " + esc(r.error) + "."); b.prop("disabled", false); return; }
        if (!r.changed) { show("ok", "Nothing changed; nothing was saved."); b.prop("disabled", false); return; }
        show("info", "Saved " + r.count + " modules. Restarting the gateway software ...");
        var t0 = Date.now();
        setTimeout(function () { back(t0); }, 8000);
      })
      .fail(function () { show("err", "The gateway didn't answer."); b.prop("disabled", false); });
  });
});
</script>
<?php endif; ?>
