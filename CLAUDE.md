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

## Next: move the web interface to Debian's lighttpd + PHP 8.4

The user wants this because ZLG's lighttpd + PHP 5.4.12 (with php_screw, apc,
zmq) is no longer maintained.

- App: `/root/Gateway/nep`, CodeIgniter 2.1.3 (doesn't run on PHP 8).
  Plan: CodeIgniter 3.1.13; port the ~dozen controllers/views.
  Rewrites from ZLG's lighttpd.conf: `^/nep/static/(.*)$` => `$0`,
  `/nep/(.*)$` => `/nep/index.php/$1`; url.access-deny `~`, `.inc`.
- Uses shmop (in Debian's PHP), possibly SQLite/PDO (check), no apc/ereg/zmq
  found in the web code. Cron PHP (proxy, wea, report pages via gene.sh)
  moves to PHP 8.4 as well, retiring /opt/php5 and /opt/httpd.
- Serving: Debian lighttpd as www-data + a dedicated PHP-FPM pool with
  `chroot = /srv/zlg` (absolute /root/Gateway paths keep working) and
  zlg-time.so preloaded (the charts depend on the shifted clock). Don't serve misetup.
- JavaScript: jQuery 1.6.2/1.8.2/1.9.1, jQuery UI 1.8.17/1.9.0, Highcharts
  2.2.3/2.3.3, blockUI 2.39, masonry 2.1, poshytip 1.1, infinitescroll,
  corner. Plan: Debian's libjs-jquery / libjs-jquery-ui (+ jQuery Migrate while
  porting), current Highcharts (personal use), replace or drop old plugins,
  delete gateway-web (old UI) and macOS `._*` files.
- Needed from the user: the full app bundle (README / conversation):
  `cd /srv/zlg && sudo tar czf /tmp/zlg-nep.tar.gz --exclude='root/Gateway/nep/static/local' root/Gateway/nep root/Gateway/index.php root/Gateway/index.html root/Gateway/misetup opt/httpd/conf opt/php5/lib/php.ini`
  ZLG/NEP files are the vendor's: don't commit them to this repository.

## Working with the user

- Hardware access is only through the user: they run commands on the board
  and paste output; serial console is output-only (TX not connected).
- Board: `debian@m6g2c`, 192.168.1.183 on Wi-Fi.
- `local.env` holds the Wi-Fi credentials; it is git-ignored. Keep it out of
  commits and shared files.
- Earlier tools: tools/decode-pinctrl.py, gpmi-raw-analyze.py, wpa-conf.py.
