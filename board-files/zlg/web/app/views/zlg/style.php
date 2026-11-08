<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<style>
/* the kit's settings pages (Wi-Fi, Modules, Advanced) */
.zlg-page { padding: 15px 20px; font-size: 13px; line-height: 1.5; color: #333; }
.zlg-page h2 { font-size: 17px; margin: 18px 0 8px; color: #3C633C; }
.zlg-page h2:first-child { margin-top: 0; }
.zlg-box { background: #f7faf7; border: 1px solid #D7EBD5; border-radius: 5px; padding: 12px 16px; margin-bottom: 14px; }
.zlg-kv { border-collapse: collapse; }
.zlg-kv th { text-align: left; font-weight: normal; color: #666; padding: 3px 18px 3px 0; vertical-align: top; white-space: nowrap; }
.zlg-kv td { padding: 3px 0; }
.zlg-form label { display: inline-block; width: 150px; color: #555; vertical-align: top; padding-top: 4px; }
.zlg-form .row { margin: 7px 0; }
.zlg-form input[type=text], .zlg-form input[type=password], .zlg-form select { width: 240px; padding: 3px 5px; border: 1px solid #bbb; border-radius: 3px; font-size: 13px; }
.zlg-form .hint { display: block; margin-left: 154px; color: #777; font-size: 12px; }
.zlg-btn { background: #3C633C; color: #fff; border: 0; border-radius: 4px; padding: 5px 14px; font-size: 13px; cursor: pointer; }
.zlg-btn:hover { background: #2d4c2d; }
.zlg-btn.light { background: #e4ece4; color: #333; }
.zlg-btn:disabled { opacity: .5; cursor: default; }
.zlg-msg { border-radius: 4px; padding: 8px 12px; margin-bottom: 12px; }
.zlg-msg.ok { background: #e3f3e1; border: 1px solid #9fcf98; }
.zlg-msg.err { background: #fbe9e7; border: 1px solid #e6a49a; }
.zlg-msg.info { background: #eef3fa; border: 1px solid #a9bfdc; }
.zlg-account { float: right; font-size: 12px; color: #666; }
.zlg-account form { display: inline; }
.zlg-account input[type=password] { width: 140px; padding: 2px 4px; }
.zlg-list { border-collapse: collapse; margin-top: 6px; }
.zlg-list td, .zlg-list th { padding: 3px 12px 3px 0; text-align: left; }
.zlg-list tr.pick { cursor: pointer; }
.zlg-list tr.pick:hover { background: #e4ece4; }
.zlg-muted { color: #888; }
</style>
