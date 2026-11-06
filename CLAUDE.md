# Notes for Claude: ZLG EPC-M6G2C Debian kit

Build kit for a Debian 13 (trixie, armhf) SD-card image for the ZLG M6G2C-256LI
module (i.MX6UL, 256 MB DDR3L, Spansion S34ML02G2 NAND) in a ZLG EPC-M6G2C box.
The box is an NEP solar gateway: 12 dual-panel microinverters, reported as 24
modules (`data/1`-`24`) plus the gateway itself (`data/0`). README.md is the
user documentation; this file is the working context.

## State (2026-11-05)

Everything works on the board ("Old", the production unit):
U-Boot 2024.01 (own board port), Linux 6.12.115, trixie, watchdog, Ethernet,
Wi-Fi (TL-WN823N, rtl8xxxu), NAND (read-only partitions, BCH strength 4 via
`fsl,use-minimum-ecc`), display (Tianma TM043NBH02, RGB565 via
patches/linux/0001), touch, beeper, RTC (holds UTC), spidev.

NEP's gateway software runs unchanged from a copy of the factory rootfs in
`/srv/zlg` (board-files/zlg/, README section 9): zlg-chroot.service plus
zlg-{plc,pctrl,hmi,httpd,cron}.service. Verified: inverter data, LCD, web
status page, Today/Hour/Month/Year charts, "Last updated". Local-only mode is
on (`ZLG_LOCAL_ONLY=1`, no cloud uploads).

## Facts that cost time to establish

- Watchdog: external CAT82x on GPIO5_IO00, ~1.1-1.6 s. U-Boot feeds it
  (cyclic, 200 ms); Linux gpio-wdt moved to subsys_initcall_sync; kernel
  trimmed and LZO-compressed; systemd takes over at ~3.5 s.
- Kernel 6.12: `ARCH_FORCE_MAX_ORDER=10` (meaning changed in 6.4; keeps CMA at
  16 MiB). Fragment merged into the minimal defconfig, every line verified.
- The PLC link on this unit is a serial module on UART2, `/dev/ttymxc1`,
  9600 8N1 (`engineer.cfg`: comm_device=0, plc_module=1). The MI200E modems
  are not used (ZLG's own driver also fails their self check here). A
  re-implementation of NEP's MI200E driver exists (board-files/linux/drivers/
  nep-mi200e.c, module plc_mi200e_o) and is off by default
  (`M6G2C_ENABLE_MI200E=0`).
- Time: ZLG's system kept local time labelled UTC, and NEP's code depends on
  it (local strings in min.dat, strtotime() epochs + 8 h fudge in charts, cron
  at local hours). zlg-time.so (LD_PRELOAD, no libc, raw syscalls) shifts
  CLOCK_REALTIME for ZLG's programs by /run/zlg/utcoffset; TZ=UTC0 and PHP
  date.timezone=UTC as on ZLG's system. The web views had
  `new Date(t*1000-28800000)`; zlg-chroot rewrites those lines.
- Inside /srv/zlg, stand-ins (`/m6g2c/bin`) block commands that would disturb
  Debian (ifconfig changes, killall except gateway-*/nsync/php, date -s,
  modprobe, wpa_supplicant, ...); `reboot` restarts zlg.target (NEP's
  day_clean.sh reboots at 01:00).
- Web live values come from SysV shm (keys 1234, 4321) written by
  gateway-plc; per-inverter boxes come from nep/static/local/<n>_status,
  written by `gdata.sh -> /opt/lsync -> php proxy` (local, every 4 min).
  Cloud upload is only `my_nsync -> php remote` (nepviewer.com).
- "Old" (production) runs an older NEP version than "New" (no
  gateway-mqtt-nep; local web enabled; `gateway-pctrl` runs once with
  check_interval=0). Services skip programs a version lacks (ExecCondition).

## Web interface on Debian's lighttpd + PHP 8.4 (2026-11-06)

board-files/zlg/web/ (README 9b): zlg-web-port (PHP, runs on the board)
converts /srv/zlg/root/Gateway/nep -> /srv/zlg/www (CI 2.1.3 -> 3.1.13 with
CI3's config files + NEP's settings, Ucfirst controllers, tokenizer-based
count()/implode()/sort()/shmop_read() -> zlg_ compat functions,
#[AllowDynamicProperties], jQuery 3 + Migrate, blockUI 2.70). zlg-web-fpm
(chroot /srv/zlg, www-data, zlg-time.so preloaded) + zlg-web (lighttpd,
port ZLG_WEB_PORT, 8080 until install-web.sh --switch). Deps pinned in
fetch-deps.sh (deps/ is git-ignored).

base_url must be a full URL (built from HTTP_HOST in config.php): NEP calls
redirect(base_url()."..."), and CI3's redirect() prepends the base again to
anything without "://" (a relative '/nep/' broke Manage layout, login and
settings redirects with 404s).

Findings: controllers/proxy.php is php_screw-encrypted (dropped);
status2.php declares class Status (dead); misetup is not served. zlg-time.so needed __clock_gettime64 /
__time64 / __gettimeofday64 for Debian 13 armhf programs.

Testing without the board: Debian's armhf PHP 8.4 / lighttpd .debs unpacked
over work/rootfs-trixie and run with qemu-arm-static -L (TCP instead of unix
sockets, host paths in lighttpd.conf, no chroot as non-root). Every page
renders; remaining warnings were missing test data.

Status (2026-11-06): deployed and switched to port 80 (install-web.sh
--switch); ZLG's zlg-httpd is off. Checked page by page against ZLG's
server; the user confirmed everything in the browser.

Problems found on the board, now fixed in the kit:
- php-fpm and lighttpd can't reopen /proc/self/fd/2 or /dev/stderr when
  stderr is the journal's socket: php-fpm logs to syslog, lighttpd has no
  server.errorlog.
- ZLG's rootfs has no /usr/share/zoneinfo; Debian's PHP then throws
  "Timezone database is corrupt" or segfaults. zlg-chroot bind-mounts the
  host's zoneinfo read-only.
- jQuery 3: .attr("checked")/.attr("value") return attributes (the status
  page's auto-refresh never ran, forms read stale values) and .live() is
  gone; zlg-web-port converts them to .prop()/.val()/$(document).on().

Panels 9 and 10 (one inverter) read 0 with status 8000: that
inverter is physically unplugged, not a fault.

ZLG's command-line PHP runs on Debian's PHP 8.4 too (zlg-chroot
php84_setup): host /usr bind-mounted ro at /srv/zlg/m6g2c/host/usr, a
wrapper (own ld-linux + --library-path) bind-mounted over
/opt/php5/bin/php, one generated ini (/m6g2c/php84.ini; Debian's conf.d
symlinks don't resolve inside). serialize_precision=14 (PHP 5's float
output; also in php-fpm.conf). Verified on the board: proxy's 127 output
files identical to PHP 5's apart from timestamps; gene.sh reports OK.
wea queries Yahoo YQL (shut down 2019), so the weather was already empty.
install.sh no longer re-enables zlg-httpd once ZLG_WEB_PORT=80.

Front-end libraries (2026-11-06): Highcharts 13.1.1 (fetch-deps.sh, npm,
pinned) + js/zlg-highcharts.js (24-hour axis labels as Highcharts 2 did;
served at /zlg-web/local/); simpletooltip -> jQuery UI .tooltip() with
escaped titles keeping <br/>; Modernizr removed (no CSS used it); masonry JS,
poshytip and infinitescroll were never loaded. lighttpd 403s NEP's copies of
all replaced libraries. Only day_view uses Highcharts; status4/status3 views
aren't loaded by any controller.

Browser testing: headless Firefox (snap) driven over Marionette (a small
Python client; profile under ~/snap/firefox/common), probing
Highcharts.charts and jQuery UI widget instances, plus screenshots.

Weather (2026-11-06): zlg-weather.php (Open-Meteo, IPv4 only because the
user's LAN advertises NAT64 v6 addresses that don't route) mounted over
/opt/php5/bin/wea; CA bundle copied to /m6g2c/ca-certificates.crt (ZLG's are
from 2015). Location from /root/Gateway/location (woeid, lon, lat) or
ZLG_WEATHER_LAT/LON; the factory value is near Jinan, China. The board has
ZLG_WEATHER_LAT / ZLG_WEATHER_LON (the user's location) in /etc/default/zlg.
zlg-web-port restores the status page's weather line (adds #wt to NEP's empty
ui-state-highlight bar, uncomments uw()); the Info page (info/index) shows it
too, linked as "Weather" after Layout in the menu (added by the port). The
port adds lang_navi_Weather and lang_weather_map_today to NEP's english,
japanese and chinese nep_lang.php (keeping CRLF/BOM) and points the Info
page's hard-coded English labels at NEP's lang_weather_map_* keys. The
language follows engineer.cfg "language" (0 en, 1 ja, 2 zh), read by
status/index into the session. web/Googlemaps.php replaces NEP's Google Static Maps
library (maps.google.cn, no key) with an OpenStreetMap iframe, same API,
position from weather.conf first. The port lints application files with
php -l (--no-lint for qemu tests); it caught nothing before because a
repeated #[AllowDynamicProperties] is a compile error, not a parse error.

## Working with the user

- Hardware access is only through the user: they run commands on the board
  and paste output; serial console is output-only (TX not connected).
- Board: `debian@m6g2c`, 192.168.1.183 on Wi-Fi.
- `local.env` holds the Wi-Fi credentials; it is git-ignored. Keep it out of
  commits and shared files.
- Earlier tools: tools/decode-pinctrl.py, gpmi-raw-analyze.py, wpa-conf.py.
