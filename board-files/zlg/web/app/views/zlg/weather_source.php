<?php defined('BASEPATH') OR exit('No direct script access allowed');
/* the Weather page's source switch, shown under NEP's weather table
   (zlg-web-port adds it to info_view) */
$CI =& get_instance();
$CI->load->library('zlg_admin');
$zw = $CI->zlg_admin->call('weather.get');
$zw_in = $CI->zlg_admin->logged_in();
$zw_csrf = $CI->zlg_admin->csrf();
$zw_msg = $CI->session->flashdata('zlg_msg');
$zh = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$zw_src = $zw['source'] ?? 'openmeteo';
$zw_url = (string)($zw['url'] ?? '');
/* the address as typed: without the default http:// and /zlg.json */
$zw_short = preg_replace(['~^http://~', '~/zlg\.json$~'], '', $zw_url);
?>
<style>
#zlg-wsrc { margin-top: 14px; padding-top: 8px; border-top: 1px solid #ccc; font-size: 12px; line-height: 18px; }
#zlg-wsrc h3 { font-size: 13px; margin: 0 0 4px; color: #3c633c; }
#zlg-wsrc label { display: block; cursor: pointer; margin: 2px 0; }
#zlg-wsrc label input { display: inline; float: none; width: auto; height: auto; margin: 0 5px 0 0; vertical-align: -2px; }
#zlg-wsrc input[type=text], #zlg-wsrc input[type=password] { width: 225px; padding: 2px 4px; border: 1px solid #bbb; border-radius: 3px; font-size: 12px; }
#zlg-wsrc .hint { color: #777; font-size: 11px; line-height: 14px; }
#zlg-wsrc button { background: #3C633C; color: #fff; border: 0; border-radius: 4px; padding: 3px 12px; font-size: 12px; cursor: pointer; margin-top: 4px; }
#zlg-wsrc button:disabled { opacity: .5; cursor: default; }
#zlg-wsrc .msg { margin-top: 5px; }
#zlg-wsrc .err { color: #b03020; }
#zlg-wsrc .ok { color: #3c633c; }
</style>
<div id="zlg-wsrc">
  <h3>Weather source</h3>
<?php if (empty($zw['ok'])): ?>
  <div class="err"><?php echo $zh(ucfirst($zw['error'] ?? 'unknown error')); ?>.</div>
<?php elseif (empty($zw['enabled'])): ?>
  <div class="hint">The kit's weather is turned off (ZLG_WEATHER=0).</div>
<?php else: ?>
  <form id="zlg-wsrc-f" onsubmit="return false">
    <label><input type="radio" name="source" value="openmeteo"<?php echo $zw_src === 'openmeteo' ? ' checked' : ''; ?><?php echo $zw_in ? '' : ' disabled'; ?>/> Open-Meteo, with map</label>
    <label><input type="radio" name="source" value="weewx"<?php echo $zw_src === 'weewx' ? ' checked' : ''; ?><?php echo $zw_in ? '' : ' disabled'; ?>/> Local Weewx</label>
    <div id="zlg-wsrc-url"<?php echo $zw_src === 'weewx' || $zw_in ? '' : ' style="display:none"'; ?>>
<?php if ($zw_in): ?>
      <input type="text" name="url" value="<?php echo $zh($zw_short); ?>" placeholder="192.168.1.20 or weather.lan" maxlength="200"/>
      <div class="hint">The weather station's IP address or name, or the URL of its zlg.json (README 7a).</div>
<?php else: ?>
      <div class="hint">Station: <?php echo $zh($zw_url ?: '–'); ?></div>
<?php endif; ?>
    </div>
<?php if ($zw_in): ?>
    <button type="button" id="zlg-wsrc-save">Save</button>
<?php endif; ?>
  </form>
<?php if (!$zw_in): ?>
  <form method="post" action="<?php echo base_url(); ?>access/login" style="margin-top:6px">
    <input type="hidden" name="csrf" value="<?php echo $zh($zw_csrf); ?>"/>
    <input type="hidden" name="page" value="info/index"/>
    <div class="hint">Settings password, to change the source:</div>
    <input type="password" name="password" autocomplete="current-password" style="width:140px"/>
    <button type="submit">Log in</button>
  </form>
<?php endif; ?>
  <div class="msg" id="zlg-wsrc-msg"><?php if (is_array($zw_msg)) echo '<span class="' . (!empty($zw_msg['ok']) ? 'ok' : 'err') . '">' . $zh($zw_msg['text']) . '</span>'; ?></div>
<?php if ($zw_in): ?>
<script type="text/javascript">
$(function () {
  var f = $("#zlg-wsrc-f"), m = $("#zlg-wsrc-msg");
  function show(cls, t) { m.html($("<span>").addClass(cls).text(t)); }
  f.find("input[name=source]").on("change", function () {
    f.find("input[name=url]").trigger("focus");
  });
  $("#zlg-wsrc-save").on("click", function () {
    var b = $(this).prop("disabled", true);
    show("", "Saving" + (f.find("input[name=source]:checked").val() === "weewx" ? " and checking the station" : "") + " ...");
    $.ajax({ url: "<?php echo base_url(); ?>weather/save", method: "POST", dataType: "json", timeout: 50000,
             data: f.serialize() + "&csrf=<?php echo $zh($zw_csrf); ?>" })
      .done(function (r) {
        if (!r.ok) { show("err", "Not changed: " + r.error + "."); b.prop("disabled", false); return; }
        location.reload();
      })
      .fail(function (x) {
        var e = x.responseJSON && x.responseJSON.error;
        show("err", e ? "Not changed: " + e + "." : "The gateway didn't answer.");
        b.prop("disabled", false);
      });
  });
});
</script>
<?php endif; ?>
<?php endif; ?>
</div>
