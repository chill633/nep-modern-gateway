# Notes for Claude: ZLG EPC-M6G2C Debian kit

**Branch nep-fw-20230903** (NEP firmware 20230903, minor 23, the "New"
unit). Branched from nep-fw-20210303 on 2026-11-06; shared fixes are made on
nep-fw-20210303 and merged here. Everything below the next paragraph
describes the Old unit (firmware 20210303) unless it says otherwise.

New unit (2026-11-06): same EPC-M6G2C hardware and serial PLC module
(comm_device=0, plc_module=1, single phase). Factory system: root@
192.168.1.241 (NAND). SD system: debian@192.168.1.239, hostname NEP-yyyyy
(serial not recorded here), web interface on port 80 (--switch done), local-only
on, local weather. Findings:
- NEP's web app is byte-identical to 20210303's. The boot script
  /usr/local/bin/gateway renames index.php, index.html and nep/index.php to
  .bak unless /root/Gateway/web_profile has INTERNAL_WEB other than "off";
  ZLG's lighttpd then answers 403. zlg-web-port uses CI3's own index.php,
  so it works unchanged.
- New cloud links: gateway-mqtt-nep (mqtts://broker.nepviewer.com:8883;
  zlg-mqtt.service is skipped when ZLG_LOCAL_ONLY=1, via zlg-chroot
  local-only-off) and cron critical_alert.php (POSTs to nepviewer.com;
  dropped in local-only). New local cron jobs: handleMin.php, jitter.sh
  delays; plc.sh in cron (dropped, as on Old).
- The boot script also sets plc_module from the serial number's second
  digit, unpacks /usr/lib/lib.tar.gz (MQTT libraries) and links the
  US/EU MQTT client; zlg-chroot strips its program starts, mqtt included.
- Both units' plc_id.conf list the same 24 inverters (0x33xxxxxx ...).
  Until 2026-11-06 the New unit was out of power-line range of them and
  the Old unit ran them. That evening the user put the New unit into
  production in the Old one's place (see "Production moved to the New
  unit"); live readings confirmed on 2026-11-07. Don't stop its zlg-plc
  for tests now.
- Found here and fixed in shared code: on a fresh install zlg-chroot
  starts before install-web.sh installs PHP 8.4, so the cron scripts kept
  PHP 5 and NEP's Yahoo wea; install-web.sh now restarts zlg.target when
  the PHP 8.4 wrapper isn't mounted.

Build kit for a Debian 13 (trixie, armhf) SD-card image for the ZLG M6G2C-256LI
module (i.MX6UL, 256 MB DDR3L, Spansion S34ML02G2 NAND) in a ZLG EPC-M6G2C box.
The box is an NEP solar gateway: 12 dual-panel microinverters, reported as 24
modules (`data/1`-`24`) plus the gateway itself (`data/0`). README.md is the
user documentation; this file is the working context.

## State (2026-11-05)

Everything works on the board ("Old", the production unit until 2026-11-06;
see "Production moved to the New unit" below):
U-Boot 2024.01 (own board port), Linux 6.12.115, trixie, watchdog, Ethernet,
Wi-Fi (TL-WN823N, rtl8xxxu; TL-WN722N, ath9k_htc, since 2026-11-07),
NAND (read-only partitions, BCH strength 4 via `fsl,use-minimum-ecc`),
display (Tianma TM043NBH02, RGB565 via patches/linux/0001), touch, beeper,
RTC (holds UTC), spidev.

NEP's gateway software runs unchanged from a copy of the factory rootfs in
`/srv/zlg` (board-files/zlg/, README section 7): zlg-chroot.service plus
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
- "Old" (20210303) runs an older NEP version than "New" (no
  gateway-mqtt-nep; local web enabled; `gateway-pctrl` runs once with
  check_interval=0). Services skip programs a version lacks (ExecCondition).

## Web interface on Debian's lighttpd + PHP 8.4 (2026-11-06)

board-files/zlg/web/ (README 7a): zlg-web-port (PHP, runs on the board)
converts /srv/zlg/root/Gateway/nep -> /srv/zlg/www (CI 2.1.3 -> 3.1.13 with
CI3's config files + NEP's settings, Ucfirst controllers, tokenizer-based
count()/implode()/sort()/shmop_read() -> zlg_ compat functions,
bareword array subscripts ($pos[y]) -> quoted keys (quote_bareword_keys;
skips string interpolation, heredocs and true/false/null; NEP's uncaught
Status::spos() fatal on PHP 8 was why Manage Layout never saved panel
positions, and php -l can't catch a run-time-only error),
#[AllowDynamicProperties], jQuery 3 + Migrate, blockUI 2.70). zlg-web-fpm
(chroot /srv/zlg, www-data, zlg-time.so preloaded) + zlg-web (lighttpd,
port ZLG_WEB_PORT, 8080 until install-web.sh --switch). Deps pinned in
fetch-deps.sh (deps/ is git-ignored).

The footer shows "Version 1.00-chill633" (zlg-web-port replaces the echo
of NEP's /opt/config/version, which NEP's info and log pages still read).

base_url must be a full URL (built from HTTP_HOST in config.php): NEP calls
redirect(base_url()."..."), and CI3's redirect() prepends the base again to
anything without "://" (a relative '/nep/' broke Manage layout, login and
settings redirects with 404s).

Findings: controllers/proxy.php is php_screw-encrypted (dropped);
status2.php declares class Status (dead); misetup is not served.
zlg-time.so needed __clock_gettime64 / __time64 / __gettimeofday64 for
Debian 13 armhf programs.

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

Panels 9 and 10 (one inverter; 23 and 24 on the New unit, see "Production
moved to the New unit") read 0 with status 8000: that inverter is
physically unplugged, not a fault.

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

## README (2026-11-06)

The user edits the README's wording; keep their decisions:
- Title: "... for the NEP BDG-256 Gateway (ZLG M6G2C-256LI)". Two summary
  paragraphs open it (old ZLG system -> Debian 13 / Linux 6.12 / U-Boot
  2024.01; lighttpd + PHP 8.4, CI3, jQuery 3, Highcharts 13; misetup taken
  off the network; weather and map restored), then "## The build kit".
- Tested state is described as done: the Old unit needs no
  re-checking; section 4's factory-dump steps are only for other units or
  carriers; section 9 lists what was tested and what wasn't (MI200E, ENET1,
  CAN, UART7/8, ADC, WPA3-only Wi-Fi). Don't reintroduce "confirm/verify
  before relying on it" wording for this unit.
- Section 3: the boot switch is under the corner of the LCD, NVRAM -> SD;
  the SD slot is under the case on the LCD side, contacts facing the board,
  the case needs a small cutout; use a high-endurance microSD card. The
  fallback via ZLG's NAND U-Boot (and the /boot format note) was removed on
  request.
- The serial console is output only (TX + GND); log in over SSH via
  Ethernet or Wi-Fi (5a). After section 7's setup, zlg-hmi owns tty1.
- 5a Wi-Fi: the dongle goes in the USB-A port on the bottom of the Gateway.
  Since 2026-11-07 (the user's decision): recommend replacing NEP's
  TL-WN823N with a TL-WN722N v1 (AR9271), on a short USB-A extension
  because it doesn't fit in the weatherproof housing. NEP's "BDG WiFi
  Booster" (CF-WR302S, plug-in repeater with Ethernet) is mentioned but not
  recommended (the user: poor solution, another AP on the home network).
- Sections run in order: 5a Wi-Fi, 5b Display, 5c MI200E, 6, 7 NEP software
  with its one subsection 7a web interface (it was briefly 7b; subsections
  are lettered from a, as 4a under 4), 8 Layout, 9 What has been tested.
  Script comments and zlg-chroot's error message say "README section 7";
  03-rootfs.sh's comment says "7a". The board's installed copies are
  current (installed after the renumbering).
- 7a: with the same NEP version as the branch's gateway, run
  install-web.sh --switch right away; compare on port 8080 only for a
  different NEP version.

Declined by the user: baking a /srv/zlg snapshot into the image and
building without NAND (M6G2C_ENABLE_NAND=0). zlg-import from the NAND stays.

## Shared fixes from the New unit (2026-11-06)

Made on nep-fw-20210303 and merged into nep-fw-20230903:
- Local-only mode (ZLG_LOCAL_ONLY=1) also cuts firmware 20230903's cloud
  links: zlg-mqtt.service has `ExecCondition=zlg-chroot local-only-off`
  (skips gateway-mqtt-nep, MQTT to broker.nepviewer.com:8883), and
  zlg-chroot drops the critical_alert.php cron line along with my_nsync.
  Harmless on 20210303, which has neither.
- install-web.sh restarts zlg.target when /srv/zlg/opt/php5/bin/php isn't
  the PHP 8.4 wrapper: on a fresh install zlg-chroot starts before PHP 8.4
  is installed, so the cron scripts kept PHP 5 and NEP's dead Yahoo wea.
  README 7a describes this restart and the firmware check after the board
  install commands; section 9 records the firmware check and hostname
  (NEP-xxxxx) on the Old unit.
- README: firmware 20230903 and the New unit in the top note and in 7a.

## Outbound traffic and blocked pages (2026-11-06)

What the gateway itself contacts (asked by the user): api.open-meteo.com
hourly (weather, HTTPS, IPv4), Debian's NTP pool (systemd-timesyncd),
deb.debian.org / security.debian.org only when apt runs (install-web.sh or
by hand; apt-daily timers off, APT_DAILY=0), and DHCP/DNS to the router.
The Weather page's OpenStreetMap map is loaded by the viewer's browser,
not the gateway. Cut by local-only: my_nsync/remote, gateway-mqtt-nep,
critical_alert.php. NEP's binaries are closed source; one snapshot of the
New unit showed no outside connections. Offered but not done: a day-long
outbound connection log, an outbound firewall.

Email reports on the Old unit: off. /opt/config/report has r1-r3, a1, a2
all 0; the recipient would come from /opt/config/general (`email`), which
doesn't exist; gene.sh only builds the reports via curl to /nep/report/*
and sets /tmp marker files that nothing reads (NEP's send.sh /
send_alert_*.sh cron lines are commented out in ginit.sh).
/opt/config/email holds NEP's factory mail settings, not the user's. The
New unit is the same (checked 2026-11-06: report all 0, no general file,
send lines commented out in ginit.sh). The report controller's send() uses
smtp_host from that file.

zlg-web-port blocks (routes to status) home/info, home/show, maildebug and
report/send; README section 7 lists them, the outbound connections and the
email-report findings. The routes use `(?i)`: CodeIgniter finds the
controller file by ucfirst() and PHP methods are case-insensitive, so
without it /nep/Home/info and /nep/Maildebug/index were still reachable.
Verified on both units with variants (Report/send, report/SEND, ...);
/nep/report/day etc. still work (gene.sh needs them).

## Power-line modem and inverter discovery (2026-11-06)

Read from the binaries and scripts only; nothing was run.
- The Old unit's PLC module is an Eastsoft ES1642 (its 2021 gateway-plc and
  plc_set know only ES1642). gateway-plc sets a network password and PLC
  address on it at start-up, so it likely only exchanges frames within its
  own network: passive listening would probably show only the user's own
  inverters (not confirmed). Firmware 20230903 also supports the ES1670
  (a CCO with a node whitelist built from plc_id.conf and a route table).
- Inverters answer polls; finding unconfigured ones needs an active scan.
  Only firmware 20230903's plc_set has it: `plc_set -p 00000 -m EXT`
  broadcasts to address 00000 and writes answering nodes to plc_new.conf
  (also comm_id.conf); /usr/local/bin/scan_plcid wraps it (kills
  gateway-plc, N rounds). plc_new.conf exists on neither unit, so no scan
  has run. The Old unit's plc_set only sends commands to a configured index
  (-j -p -i -c -d -k -r). Running the new plc_set -m on the Old unit: not
  done, not recommended without a reason (stops readings, transmits
  broadcasts, untested with the old files, contains code that writes
  plc_id.conf: back it up first; inverters only answer in daylight).
- Offered to the user and not chosen for now: a day-long logging
  pass-through of /dev/ttymxc1 (pty relay, two ~1-minute gaps in the
  readings).
- PVG = NEP's PVG-2 module-level rapid-shutdown devices with monitoring;
  the user has none (comm_device=0, pvg_scanner=0). gateway-scan (Qt scanner
  for PVG IDs), pvg_scan_code, pvg_tx_code and pvgtxcode/ are for them and
  irrelevant here.
- /usr/local/bin/sys_ctrl (20230903) broadcasts on/off to all inverters:
  with comm_device=0 via `plc_set -p 00000 -z -c 0001...b0...` (on) or
  `...01...` (off). Nothing runs it; never run it.

## misetup: inverter grid settings (2026-11-06)

Read from both units' copies (nep/misetup: HTML/JS + proxy.cgi); nothing
was run and the copies were deleted.
- misetup is the page that reads and writes the microinverters' grid
  settings. proxy.cgi (not served by the
  new web server) takes mod/pwd/ind/cmd and runs `plc_set -j -p PWD -i
  IDX -c HEX`. mod: 1 read, 2 write, 3 count inverters, 4 "Setup Mode"
  (kills gateway-plc: the modem serves one program; readings stop), 5
  reboot, 6 reload the list. Extended (1547 advanced) values go through
  proxy.js mread_extend/mwrite_extend.
- setting/*.conf: 41 grid profiles, US ones US-240, US-240-1547A,
  US-240-RULE21, US-HECO-240 plus 208 V variants. Fields: fre/fref
  (50/60 Hz; Japan-50Hz and Japan-60Hz differ only in these and the
  frequency limits), grid code, voltage trips (smaxv, maxv, maxv2/maxtv2,
  maxv3/maxtv3, minv3/mintv3, minv2/mintv2, minv, sminv), frequency trips
  (smaxf ... sminf with times), pwr, rect, and for 1547/Rule 21 db_offset,
  pwrf_ctrl, vwat_ctrl, wvar_p*q*, vvar_offset_v1-v4, vvar_q1q2,
  vvar_ctrl, ac_ref, vnorm, ramp_delay, temperature_max.
- Command: 34 bytes starting 00 01; byte 7 grid, 8 power, 10 minv, 11
  maxv, 13 maxv2, 15 maxv3, 16 minf, 17 maxf, 18/19 suppress, 24-25 maxv
  clearing times, 30 sminv, 31 smaxv. Voltage byte = |V - MID_V_200| *
  ac_v_gain / 4 (other divisors for some grids); ac_v_gain from plc.conf
  AC_V_Gain* (Old unit: 17.468, 17.447, 33.65, 17.447, 39.05, 39.05,
  14.89); power byte = pwr / power_gain (2, 4, 20 or 40).
- The model comes from the inverter ID's first two hex digits. The user's
  inverters start with 33 = 800B in the 20230903 misetup (ac_v_gains[3],
  power_gain 4). **The Old unit's (20210303) misetup has no 33 case**: it
  falls back to ac_v_gain 1.0 and power_gain 2, so a write from it would
  encode the voltage trips ~17x too low (tripping a 240 V grid) and the
  power limit doubled. Never write settings with the Old unit's misetup;
  the 20230903 copy handles the 800B.
- 20230903 adds custom profiles (save_as_custom_conf.php,
  set_para_profile.php, para_profile.txt), mi_set_code and mi_repeat_set.
- Baseline read on 2026-11-08 (user approved) through the kit's Advanced
  page on the New unit: all 24 modules, basic (0001) and extended (0002),
  raw replies in ~/Working/nep-local/mi-baseline-20261108.txt and
  /var/lib/zlg-admin/ on the board (they contain module IDs: never commit).
  Modules 23/24 reply all zeros (the unplugged inverter); 3-22 identical;
  1/2 (one inverter) differ in reply bytes 15, 19 and 39. Replies echo the
  module ID in bytes 4-7 and 10-13.
- The odd decoded values, worked out 2026-11-08 (code + baseline only):
  the inverters report grid 6 (byte 14). The 2023 decoder (proc(), and the
  encoder vacset()/freqset() as its inverse) handles grid 6 with fixed
  tables: maxv = 270 + b/10, minv/minv2 = 200 - b (gain 1, hence -18 V
  and 26 V), f = fre +- b/10 (fre = the page's Grid Frequency field). The
  2021 decoder handled grid 6 like grid 1: V = 220 +- b*4/ac_v_gain
  (17.447 for "33"), f = 60 +- b/32. The bytes fit the 2021 scheme
  exactly: 0xae/0xc0/0xda = 40.0/44.0/50.0 V, 0x30/0x36/0x90 =
  1.5/1.69/4.5 Hz, i.e. OV 260 V (stage 1), 264 V; UV 170 V (180 V on
  module 1/2's minv/sminv), 170 V; OF 61.5, 64.5 Hz; UF 58.3, 55.5 Hz;
  rect 600 s; pwr 760 W. No NEP profile (2021 or 2023, incl. custom)
  matches byte for byte: likely factory settings. Inverter version bytes
  8-9 = c9 8b. If the old encoding is the inverters', a 2023-page write of
  US-240-1547A would put VAC_MIN at ~199 V instead of 108 V.
- Decision (2026-11-08): the user expects no answer from NEP (questions
  about older firmware get "update to the latest"), so the question was
  dropped from the vendor report. Documentation search: NEP's BDM-800
  datasheet (signaturesolar.com, BDM-550/650/800/1000-070824) gives only
  the window 211-264 Vac / 59.3-60.5 Hz (adjustable), UL 1741-SA/SB; the
  BDM-600x manual has no trip table; manualshive/manuals.online blocked.
  The 2023 reading is physically impossible (-18 V, 74.4 Hz), the 2021
  one plausible (264 V = the datasheet maximum). Settled by the test below.

## 800B 2021 encoding: test, page, calibration (2026-11-08)

- One-inverter test (user approved, module 3; tool /tmp/mitest.php on .239,
  gone after reboot): byte 0x46 in OV1 (cmd byte 15) = 236.05 V in the 2021
  encoding tripped the inverter with error 0x40 (AC RMS over) at a 242 V
  grid. The 800B (IDs 33) use the 2021 encoding. Error codes are hex bit
  flags (BDG-256 manual 7.6: bit 4 freq over, bit 6 AC RMS over, bit 15
  PLC comm).
- Encoding: V = 220 +- b*4/17.447 (161.5-278.5 V; an under-limit must be
  below 220 V); f = nominal +- b/32 Hz; trip times one 16-bit word per
  pair, (fast*10)<<11 | (slow*10) (fast <= 3.1 s, slow <= 204.7 s, 0.1 s
  floor); reconnect stored byte * 2.048 s, and the inverter stores the
  sent byte + 16 (write c[9] = r[16] - 16); power b*4 W. Reply byte
  (k^1)+8 holds command byte k (pairs swapped). Command byte 6
  ("Freq_cal_offset") is taken as the actual grid frequency 59 + b/32 Hz:
  the inverter recalibrates its reading (reply byte 15, signed 1/32 Hz)
  to match. Resending it from a reply is what moved module 3's
  calibration during the test; never send it except to calibrate.
- Current settings (2021 reading, modules 3-22): OV 259.9/264 V, UV 170 V
  (1/2: VAC_MIN/START_MIN 180.1 V), OF 61.5/64.5 Hz, UF 58.31/55.5 Hz,
  **all trip times 0.1 s**, reconnect 300 s, 760 W, calibration +10/32 Hz,
  grid 6; extended: FREQ-WATT and VAR-CTRL off, V_Norm 230. The grid
  reaches 250 V; natural 0x40 trips seen at ~247 V.
- **Each read (0001 + zeros) or write takes an 800B offline ~5 min**
  (status 8B01, then its 300 s reconnect). Settings unchanged.
- Kit: js/zlg-mi2021.js (window.zlgMi2021; added to misetup index.html by
  zlg-web-port after _mi.js) wraps NEP's proc()/mwrite() for ID 33 on
  grid 1/6: decodes into the form, encodes from the last reply + checked
  fields, refuses out-of-range values, notes rounding, posts enc=2021.
  zlg-admin accepts 0001 writes to IDs 33 only with enc=2021, grid byte 1
  or 6 and byte 6 = 00. mi-report.php decodes them the same way
  (mi_decode_2021; "Encoding" row/column; the separate 2021 columns are
  gone). `zlg-admin --mi-calibrate MODULE OFFSET` (1/32 Hz) restored
  module 3 to +10/32 Hz on the first try (min.dat is local time:
  local_tz()). Verified live: Check of 3/4 through the page; offline: the
  JS encoder reproduces the restore command and the PHP decoder equals
  the JS one. **No write through the page yet; ZLG_MI_WRITES still 0.**
- Utility profile (local only: ~/Working/nep-local/grid-settings/, CFF v2.0
  -> NEP field mapping, names the utility, never commit): can't encode
  OV2 288 V, UV2 120 V, OF1/UF1 300 s, ES_V_LOW 220.08 V; NEP's volt-var
  curve is symmetric (utility Q4 -0.44 with Q1 0 impossible); no
  randomized delay. Open for the user: which profile within the limits,
  a deviation request to the utility. Performance categories (III/B) are
  a classification, not a setting; NEP's ATM certificate
  (201026118GZU-001, in ~/Working/Reference) lists UL 1741 SB,
  IEEE 1547-2018, 1547.1-2020. The user is the installer. The volt-var
  open-loop delay is the first VAR-CTRL row (0-400 s).

## Production moved to the New unit (2026-11-06)

The user put the New unit (firmware 20230903, NEP-yyyyy, 192.168.1.239)
into production that evening, replacing the Old one; no readings until
the inverters wake in the morning. Checked read-only after its boot: the
gateway programs, web (port 80) and cron running, zlg-mqtt skipped
(local-only), plc_id.conf lists the same 24 modules (0x33xxxxxx ...
0x33xxxxxx), comm_device=0, plc_module=1, /dev/ttymxc1, utcoffset -18000,
local weather.
Live readings confirmed on 2026-11-07 (07:44): 22 modules producing,
the gateway's total (0_status "now") is their sum. The Old unit
(192.168.1.183, NEP-xxxxx) is now free for tests (e.g. the ttymxc1
logging pass-through or the plc_set -m scan); its misetup must still
never write inverter settings. Since 2026-11-07 it is on the bench booted
from its factory NAND system (firmware 20210303, kernel 4.1.15, hostname
Gateway) at 192.168.1.241, user root; no inverters attached.

Wi-Fi at the New unit's production spot (2026-11-06, log read only): the
link is weak (-64 to -68 dBm, rx bitrate 2 Mbit/s, repeated
CTRL-EVENT-BEACON-LOSS; the APs also dropped it with reason=34 in the
15:35-19:10 boot; no problems at its earlier spot). After a beacon-loss
disconnect the RTL8192EU (rtl8xxxu) can't authenticate with any of the
user's four access points ("authentication ... timed out") until the dongle
is unplugged and replugged; the user did that twice (20:12, 20:18). Power
save is off. Other boot warnings are harmless (unclean journal after the
move, mi200e -ENXIO, iwconfig wext, networkd "Could not set hostname").
Options given: Ethernet (eth0 is configured, cable unplugged), a USB
extension or a dongle with an antenna, or a kit service that resets the
dongle when it can't reconnect. The reset service was not wanted.

Module IDs and panel numbers: each inverter reports two module IDs (M_ID
on the display), identical except the last hex digit, which is the
inverter's input port (0 or 1). Panel numbers follow plc_id.conf's order
and differ per unit: the unplugged inverter is panels 9/10 on the Old
unit and 23/24 on the New unit. Within a pair the order isn't always
port 0 first (the New unit's 1/2, 3/4, 5/6 are port 1, port 0). Refer to
modules by M_ID; keep the IDs themselves out of the repository.

## TL-WN722N (AR9271) on the New unit (2026-11-07)

The New unit now uses a TP-Link TL-WN722N (v1: Atheros AR9271, 0cf3:9271,
external antenna) instead of the TL-WN823N. Kit changes: m6g2c.config
enables CONFIG_WLAN_VENDOR_ATH + CONFIG_ATH9K_HTC=m, with ATH9K and ATH10K
off (imx_v6_v7_defconfig turns ath10k on once the vendor menu is on);
ATH9K_HTC selects MAC80211_LEDS, so mac80211.ko changes too (built-in
code doesn't; only the embedded /proc/config.gz). 03-rootfs.sh installs
firmware-ath9k-htc (Debian main, open-source htc_9271-1.4.0.fw), not
firmware-atheros. The fragment check passed (196 settings).
Same spot, same morning: TL-WN823N -56 to -64 dBm, rx 2-6.5 Mbit/s, ping
avg 35-37 ms, 28 beacon losses overnight; TL-WN722N -51 to -54 dBm, rx/tx
72.2 Mbit/s (MCS 7, its maximum), ping avg 16 ms, 0 beacon losses in the
first minutes. The router kept 192.168.1.239 for the new MAC. The AR9271's
EEPROM says CN; the AP's country IE (US) takes over. README 5a recommends
it (see "README" above); a longer check of beacon losses is still useful.
On the board only the wireless modules were replaced (cfg80211, mac80211,
drivers/net/wireless from work/kernel-install; kernel unchanged, still
config without ath9k in /proc/config.gz); the old ones' backup
(/root/modules-backup-20261107) was deleted on 2026-11-08. The next SD image carries
both. Both dongles work on the new modules; the TL-WN823N is the spare.
Lessons: reloading the Wi-Fi modules needs `systemctl restart
wpa_supplicant@wlan0` afterwards (the old process keeps a stale nl80211
handle: CTRL-EVENT-SCAN-FAILED ret=-2, never connects; a USB replug
restarts it by itself); /tmp is cleared at boot; work/linux-* was
root-owned after `sudo ./build.sh all` (the user chowned it back).
Tell the user up front which physical steps (dongle swaps) are theirs and
when, before anything that takes the board off the network.

## Settings pages: Wi-Fi, Modules, Advanced (2026-11-08)

The user's plan (Claude Docs "NEP Modernization - Gateway Config Pages"):
port NEP's separate config pages into the menu after Weather. Done and
tested on the New unit (.239); README 7a "Settings pages" documents them.
- NEP's originals (identical in both firmwares except misetup):
  /wifi (own password, default "gateway", MD5 in vtwifi/wlan.pwd; edits
  vtwifi/wpa.conf, which Debian ignores), /module (singular; no login;
  plc_id.conf + plc_phase.conf), /misetup. The kit serves none of them;
  lighttpd.conf redirects (301) /wifi, /module(s), /misetup (and anything
  under them) to /nep/wifi, /nep/modules, /nep/advanced.
- board-files/zlg/web/admin/zlg-admin: root helper (PHP CLI) behind
  zlg-admin.socket (/run/zlg/admin.sock = /m6g2c/run/admin.sock in the
  chroot, group www-data, Accept=yes -> zlg-admin@.service, one JSON line
  each way). It checks every request itself: settings password
  (password_hash in /var/lib/zlg-admin/password; missing = "gateway"),
  tokens (sha256, 2 h idle, /run/zlg-admin), 5 failures / 5 min.
  CI3 pages (web/app: Access, Wifi, Modules, Advanced controllers,
  Zlg_admin library with per-session CSRF token, views/zlg) are copied in
  by zlg-web-port after NEP's code; menu entries + lang keys too.
  NEP's views leave one <div> open (#modindex_box) that footer_view closes
  ("end of main content") before #main_container: the kit's pages open
  <div class="zlg-main"> in views/zlg/account.php for that (#main_container
  and #modindex_box have fixed heights, 400/620 px, so not reused).
- Wi-Fi: writes /etc/wpa_supplicant/wpa_supplicant-wlan0.conf (WPA2: derived
  psk only) and /etc/systemd/network/25-wireless.network, via a transient
  systemd-run job; 90 s connect test (wpa_state COMPLETED, IPv4, router
  answers ping), else restore and retry. Pending marker
  /var/lib/zlg-admin/wifi-pending + zlg-wifi-guard.service (sysinit, before
  wpa_supplicant) undo an unconfirmed change at boot. wpa_cli and iw are in
  /usr/sbin (not in the debian user's PATH). Tested 2026-11-08: unchanged
  save ~10 s; wrong passphrase rolled back after ~100 s; guard run by hand.
- Modules: backups in /var/lib/zlg-admin/modules (last 10); after a save
  zlg-admin touches /run/zlg/restart (zlg-restart.path -> zlg.target), which
  clears the web sessions (CI session files are in the chroot's /tmp) and
  NEP's ginit.sh deletes the *_status files until gdata.sh's next run (up to
  4 min). End-to-end tested (module 25 FF000002 -> FF000003 -> back; file
  byte-identical). ZLG_ADMIN_GW=<dir> (root CLI only) points modules.* at a
  test copy without restarting.
- Advanced = NEP's 20230903 misetup HTML/JS, built by zlg-web-port into
  /srv/zlg/www/misetup-ui (jQuery 3 + Migrate, blockUI 2.70, Bootstrap
  3.3.7 kept; js/proxy.js and common.js are symlinks to orig/ resolved via
  the chroot prefix); index.html becomes views/zlg/misetup_ui.php (base
  href, CSRF ajaxSetup, hidden pwd field, container-fluid) shown in an
  iframe. URLs: proxy.cgi -> /nep/advanced/proxy (GET init; POST mod
  1 read, 2 write, 3 find, 4 setup, 5 leave (NEP's "Reboot Gateway", label
  changed, also in files()' language), 6 IDs), get_file.php -> files,
  get_setting.php -> setting, save_as_custom_conf.php -> savecustom,
  set_para_profile.php -> setprofile. Replies are text/plain (NEP evals).
  Read = 0001/0002 + 64 zero hex digits (any other payload is a write).
  Setup mode = systemctl stop zlg-plc + transient zlg-mi-resume-*.timer
  (600 s, re-armed per request); killing gateway-plc alone doesn't work on
  Debian (Restart=always, 10 s). NEP's page needs Setup Mode before Check.
  PLC password: /var/lib/zlg-admin/mi-password (zlg-admin
  --set-mi-password; set on the New unit). Writes: login + ZLG_MI_WRITES=1
  (zlg.default: 0; not set on the board) + gateway.cfg major >= 20230903;
  logged. Never enabled or tested yet. Other misetup versions (20210303 has
  getfile.php/get_content.php) are skipped by the port; tested with the Old
  unit's copy.
- Grid report (Advanced page, 2026-11-08): web/admin/mi-report.php
  (installed with the web files, required by zlg-admin --mi-report-job,
  a systemd-run job; ops mi.report_start/status/get; start and download
  need the settings login, the user's decision; status is open).
  Nominal frequency (the limits are offsets from it; NEP's page asks for
  it): the inverters' measured Hz, column 7 of data/<n>/min.dat (date,
  time, kW, DC V, AC V, AC A, Hz, ...; local time), rounded to 50/60, if
  under a day old; else by grid code (5,8,12-15,17: 50; 6,7,16: 60); else
  offsets "(Hz from nominal)". No selector, the user's decision. An
  all-zero reply is a final no-answer (no retries, no extended read):
  130 s for 25 modules.
  PHP port of proc()/proc_extend() (tables extracted from proxy.js by
  script); checked against NEP's JS in Firefox on the baseline replies:
  22 modules, 1034 fields, 0 differences (pwr/fref exist only in NEP's
  debug mode, not compared). Output /run/zlg-admin/mi-report.tgz (CSV +
  hand-written Courier PDF, landscape Letter). While it runs, MI Setup's
  setup/leave/read/write are refused. 800B inverters on grid 1/6 are
  decoded in the 2021 encoding (see "800B 2021 encoding" below), others
  as NEP's 2023 page. Live runs: 22 of 25 answered (23/24 no answer, 25 meter
  skipped); gateway-plc restarted after. Reports contain module
  IDs: never commit one.
- Browser checks: headless Firefox via /snap/bin/geckodriver and a small
  WebDriver client (urllib), screenshots; sshpass is installed on the
  workstation.

## Vendor security report (2026-11-06)

The user plans to report NEP's vulnerabilities to the vendor. The report
and its findings are local only: ~/Working/NEP-BDG-256-security-report.md
(outside the public repository). Until NEP has been told and has had time
to respond, keep the findings out of everything committed (files and
commit messages); read the local report for the details. It covers
firmware 20230903 only (the user: reporting the old firmware would just
get "update"), with no serials, IDs or location, and states that it is
static analysis only. Never quote NEP's credentials anywhere.
The kit's gateways are not exposed to the findings that matter (details in
the local report).

Disclosure policy (the user, 2026-11-07): no finding is described in the
public repository until NEP has been notified and 90 days have passed
(or NEP has fixed them earlier and the user agrees). On 2026-11-07 both
branches' whole history was rewritten (filter-branch: README.md,
CLAUDE.md, zlg-web-port and four commit messages reworded) and
force-pushed; the kit's mitigations are described without saying why
(misetup not served, diagnostic/test pages routed to status, local-only).
The pre-rewrite history is in ~/Working/nep-modern-gateway-backup-20261107.git
(local only, never push it). To drop the old commits GitHub still served
by ID, the user had the repository deleted and recreated the same day
(same name, description, settings; both branches pushed, default
nep-fw-20210303); the old IDs now give 404. Never push from a clone made
before 2026-11-07 (it carries the old history). Writing new docs: say what
the kit does, not which weakness of NEP's it closes, and describe none of
the report's findings, not even indirectly.

## Repository and access

- GitHub: chill633/nep-modern-gateway (**public**; recreated 2026-11-07,
  see "Disclosure policy"; the old name chill633/claude no longer
  redirects). Keep identifying details out of
  everything committed (files and commit messages): gateway serial numbers
  and the real hostnames (NEP- + the serial's last five characters; the
  docs say NEP-xxxxx for the Old unit, NEP-yyyyy for the New one), inverter
  IDs, the user's location or coordinates, passwords, Wi-Fi details. On
  2026-11-06 they were removed from both branches' history (filter-branch,
  force-pushed). Use the IP addresses to reach the boards. Local
  checkout: ~/Working/nep-modern-gateway. gh is installed and logged in
  (delete_repo scope added 2026-11-07 for the recreate; the user may
  remove it).
- Branches per NEP firmware major version (`major=` under [version] in
  /root/Gateway/gateway.cfg): **nep-fw-20210303** (default; the "Old"
  unit, minor 03, local web interface works; renamed from
  claude/m6g2c-trixie-kit) and **nep-fw-20230903** (the "New" unit; NEP
  hides its local web interface, which the kit brings back). The New unit
  runs nep-fw-20230903 since 2026-11-06: SD system debian@192.168.1.239,
  hostname NEP-yyyyy, web interface on port 80, local-only; details in that
  branch's CLAUDE.md. board-files/zlg/nep-firmware holds the branch's
  version; nep-firmware.sh's nep_fw_check runs in zlg-import (NAND's
  gateway.cfg) and install-web.sh (the copy's), --any-firmware skips it.
  Fixes to the shared parts (U-Boot, kernel, rootfs, watchdog, Wi-Fi, the
  zlg kit) are made on nep-fw-20210303 and merged into nep-fw-20230903.
- Working on nep-fw-20230903 without leaving this checkout: `git worktree
  add <scratch>/wt-20230903 nep-fw-20230903`, merge or edit there, push,
  then `git worktree remove`. To build its board bundle, copy the
  git-ignored board-files/zlg/web/deps/ and board-files/zlg/zlg-time.so
  into the worktree first.
- Parts of the README differ per branch on purpose: the firmware note and
  the Status note at the top, the summary's second paragraph (20230903 adds
  the restored local pages and cut cloud links), section 7's zlg-httpd row
  and hostname example (NEP-xxxxx / NEP-yyyyy), 7a's "installed and running
  on port 80 on ..." line and its list of when to compare on port 8080
  (each branch describes its own firmware first), section 9's 20230903
  test record, and section 9's line about the shared parts running on the
  other gateway (nep-fw-20210303 points to the 20230903 branch's README;
  nep-fw-20230903 points to the start of its own section 9), and section
  the Status note's and section 9's description of which gateway is in
  production. Section 7's "Email reports" bullet says "off on both
  gateways" on both branches. When merging nep-fw-20210303 into nep-fw-20230903,
  keep nep-fw-20230903's wording there (the top note conflicted once,
  2026-11-06), and check that board-files/zlg/nep-firmware still says
  20230903.
- Passwords aren't stored in the repository. The user gives them per
  session (SD systems: user debian; the New unit's factory system: root);
  keep them only in the session scratch folder and delete them when asked.
  The user plans to change them at the end of the project. On 2026-11-06
  all passwords and the scratch folders (copies of both units' software,
  including /etc/shadow and SSH host keys, the qemu test setup with NEP's
  pages and the Old unit's data, screenshots) were deleted at the user's
  request: ask for the passwords again before working on a board.
- Local files outside the repository, in ~/Working: m6g2c-trixie/ (the
  build tree; out/m6g2c-debian-trixie-armhf.img(.xz) from 2026-11-05, used
  for both units' SD cards), zlg-migrate.tar.gz (the last board bundle
  built from the checkout) and zlg-nep.tar.gz (NEP's web bundle from the
  Old unit; moved out of the repository folder, the only copy). The
  repository folder holds no vendor files; zlg-*.tar.gz is git-ignored
  anyway. Recreating a board bundle: `tar czf ../zlg-migrate.tar.gz -C
  board-files zlg` after fetch-deps.sh and building zlg-time.so.

## Working with the user

- Hardware access: over SSH with the passwords the user gives per session,
  or the user runs commands and pastes output. The serial console is
  output only (TX not connected). Never stop the production unit's
  services for tests that would interrupt its readings: since 2026-11-06
  that is the New unit (debian@192.168.1.239, NEP-yyyyy), not the Old one.
- Board: `debian@192.168.1.183` on Wi-Fi, hostname **NEP-xxxxx** (since
  2026-11-06; was m6g2c). zlg-chroot's set_hostname names the board
  `NEP-<last 5 characters of SN=>` from /srv/zlg/root/Gateway/sn (serial
  not recorded here) at each zlg-chroot start, updating /etc/hostname and the
  127.0.1.1 line in /etc/hosts; a fresh card is m6g2c (HOSTNAME_TARGET)
  until zlg-import. ZLG_HOSTNAME=0 in /etc/default/zlg keeps the name.
  Verified on the board. The router may know the board by its old name
  until the next DHCP renewal; use the IP address.
- `local.env` holds the Wi-Fi credentials; it is git-ignored. Keep it out of
  commits and shared files.
- Earlier tools: tools/decode-pinctrl.py, gpmi-raw-analyze.py, wpa-conf.py.
