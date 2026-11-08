<?php defined('BASEPATH') OR exit('No direct script access allowed');
/* message line, and the login box or the logged-in bar */
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$act = base_url() . 'access/';
/* NEP's views leave one <div> open, which footer_view closes ("end of main
   content") before the main container: this page's content does the same */
?>
<div class="zlg-main">
<div class="zlg-page" style="padding-bottom:0">
<div class="zlg-account">
<?php if ($logged_in): ?>
  Logged in for changes.
  <a href="#" onclick="$('#zlg-passwd').toggle();return false;">Change the settings password</a> &middot;
  <form method="post" action="<?php echo $act; ?>logout">
    <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"/>
    <input type="hidden" name="page" value="<?php echo $h($page); ?>"/>
    <button class="zlg-btn light" type="submit">Log out</button>
  </form>
<?php else: ?>
  <form method="post" action="<?php echo $act; ?>login">
    <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"/>
    <input type="hidden" name="page" value="<?php echo $h($page); ?>"/>
    Settings password <input type="password" name="password" autocomplete="current-password"/>
    <button class="zlg-btn" type="submit">Log in</button>
  </form>
<?php endif; ?>
</div>
<div style="clear:both"></div>
<?php if ($logged_in): ?>
<div id="zlg-passwd" class="zlg-box zlg-form" style="display:none;margin-top:10px">
  <form method="post" action="<?php echo $act; ?>password">
    <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"/>
    <input type="hidden" name="page" value="<?php echo $h($page); ?>"/>
    <div class="row"><label>Current password</label><input type="password" name="old" autocomplete="current-password"/></div>
    <div class="row"><label>New password</label><input type="password" name="new" autocomplete="new-password"/>
      <span class="hint">8 characters or more. It protects the Wi-Fi, Modules and Advanced pages.</span></div>
    <div class="row"><label>New password again</label><input type="password" name="new2" autocomplete="new-password"/></div>
    <div class="row"><label></label><button class="zlg-btn" type="submit">Change password</button></div>
  </form>
</div>
<?php endif; ?>
<?php if (is_array($msg ?? null)): ?>
<div class="zlg-msg <?php echo !empty($msg['ok']) ? 'ok' : 'err'; ?>" style="margin-top:10px"><?php echo $h($msg['text']); ?></div>
<?php endif; ?>
</div>
