# Debian trixie (armhf) SD-card image for the NEP BDG-256 Gateway (ZLG M6G2C-256LI)

The NEP BDG-256 solar gateway collects the readings of NEP's microinverters
and shows them on its touch screen and web pages. As shipped, it ran ZLG's
2015-era embedded Linux (Linux 4.1, U-Boot 2015.04), with an old lighttpd
and PHP 5.4 (2013) web server and JavaScript libraries from 2012 (jQuery 1.8,
Highcharts 2), none of which get security updates any more. This kit moves the gateway
to a modern, supported system: **Debian 13 "trixie"** with **Linux 6.12 LTS**
and **U-Boot 2024.01**, booting from an SD card and leaving the factory
system on the built-in NAND untouched. NEP's own gateway programs keep
running unchanged, from a copy of the factory software, so the inverters,
the touch screen and the collected history carry on as before.

The web interface now runs on Debian's **lighttpd** and **PHP 8.4**, kept
patched by `apt`: NEP's pages were converted from CodeIgniter 2 to
**CodeIgniter 3**, and their JavaScript moved to Debian's **jQuery 3** and
**jQuery UI**, with **Highcharts 13** for the charts. NEP's own command-line
PHP scripts run on PHP 8.4 too. On firmware 20230903, where NEP had switched
the gateway's local web pages off, they are back. Local-only mode cuts the
gateway's links to NEP's cloud, including that firmware's MQTT connection.
Along the way, two features that had stopped working were restored: the
**weather**, whose Yahoo service closed in 2019, now comes from Open-Meteo
for the gateway's location, and the **map** on the Weather page, which
showed a broken Google Maps image, now shows OpenStreetMap. The gateway's
settings pages, which NEP served apart from the main pages (**Wi-Fi**, the
**module** list and the inverters' grid settings, **MI Setup**), are now in
the web interface's menu, behind a settings password.

## The build kit

The kit produces a bootable SD-card image for the ZLG **M6G2C-256LI**
core module:

| Part | Device | Notes |
|---|---|---|
| SoC | NXP i.MX6UL, MCIMX6G2CVM05 | Cortex-A7, 528 MHz |
| DRAM | ISSI IS43TR16128DL-125KBLI | DDR3L, 128M x 16 = 256 MiB, 8 banks, 14 row / 10 col, run at 400 MHz |
| NAND | Spansion/Cypress S34ML02G2 (marking ML02G200BH100) | 2 Gbit SLC, 2048+128 B pages, needs 4-bit ECC |

Everything is built from source: mainline **U-Boot 2024.01** (SPL + U-Boot,
with a new `zlg_m6g2c` board port), **Linux 6.12 LTS** with an M6G2C device
tree, and a **Debian 13 "trixie" armhf** root filesystem.

> **NEP firmware 20230903.** This branch, `nep-fw-20230903`, is for
> gateways running NEP firmware major version **20230903** (`major=` in the
> `[version]` section of `/root/Gateway/gateway.cfg`). Gateways with
> firmware 20210303 use the branch `nep-fw-20210303`. `zlg-import` and
> `install-web.sh` check the version and stop on a mismatch (section 7).
>
> Firmware 20230903 runs on the same hardware and has the same web pages as
> 20210303. NEP only hides the pages: its boot script renames `index.php`,
> `index.html` and `nep/index.php` to `.bak`, so its own lighttpd answers
> 403. This kit serves the pages with its own front controller, so they
> work again. The firmware also adds two links to NEP's cloud,
> `gateway-mqtt-nep` (MQTT to `broker.nepviewer.com`) and the cron job
> `critical_alert.php`; local-only mode cuts both (section 7).

> **Status:** this image runs in production on a gateway with firmware
> 20230903 (the "New" unit), together with NEP's gateway software and the
> modernised web interface on port 80 (section 7). It replaced the 20210303
> gateway on 6 November 2026. The rest of this README describes the
> hardware and the setup, which are the same as for firmware 20210303; see
> section 9 for what was checked on the 20230903 gateway. The checks in
> section 4 are for a different unit or carrier board.

```
SD card
  1 KiB    SPL               ROM loads it, SPL initialises the DDR3L
  69 KiB   u-boot-dtb.img    loaded by SPL from raw sector 0x8a
  768 KiB  U-Boot env        8 KiB, readable from Linux with fw_printenv
  4 MiB    p1  ext4  /boot   zImage, dtbs/, extlinux/extlinux.conf (no journal)
  ...      p2  ext4  /       Debian trixie, grown to fill the card at first boot
```

---

## 1. Host requirements

An x86-64 Debian 12/13 or Ubuntu 22.04/24.04 machine (a VM is fine) with
~15 GB free.

```sh
sudo apt install build-essential git bc bison flex libssl-dev libgnutls28-dev \
    uuid-dev python3 python3-dev python3-setuptools python3-pyelftools swig \
    device-tree-compiler gcc-arm-linux-gnueabihf kmod cpio rsync xz-utils lzop curl \
    debootstrap qemu-user-static binfmt-support debian-archive-keyring \
    fdisk e2fsprogs
```

`lzop` is needed because the kernel is LZO-compressed (it decompresses
fastest, which matters with the external watchdog); it isn't installed by
default on Ubuntu.

**Debian archive keys.** debootstrap checks trixie's signatures with the
host's `debian-archive-keyring`, which must include the Debian 12/13 keys.
Debian 12/13 and Ubuntu 24.04 have them. Ubuntu 22.04's package (2021.1.1) is
too old: debootstrap then stops with `Release signed by unknown key` or
`NO_PUBKEY`. Install the current `debian-archive-keyring_*_all.deb` from
<https://packages.debian.org/trixie/all/debian-archive-keyring/download>
with `sudo apt install ./debian-archive-keyring_*_all.deb`. If the host's
debootstrap is too old to know "trixie", the rootfs stage uses its `sid`
script, which is the same.

Stages `uboot` and `kernel` run as a normal user; `rootfs` and `image` need
root (debootstrap/chroot and loop devices).

## 2. Build

```sh
./build.sh uboot          # out/SPL, out/u-boot-dtb.img
./build.sh kernel         # work/kernel-install/
sudo ./build.sh rootfs    # work/rootfs-trixie/  (emulated, 20-40 min)
sudo ./build.sh image     # out/m6g2c-debian-trixie-armhf.img(.xz)
# or: sudo ./build.sh all
```

Settings live in `config.env`; any of them can be overridden on the command
line, for example `sudo IMAGE_SIZE_MB=4096 EXTRA_PACKAGES="python3 htop" ./build.sh all`.

## 3. Write the card and boot

```sh
sudo tools/write-sdcard.sh out/m6g2c-debian-trixie-armhf.img.xz /dev/sdX
```

Use this rather than a bare `dd`. Desktop Linux mounts a card's partitions
as soon as you insert it. If you `dd` over partitions that are mounted, the
PC later writes its cached metadata for the old filesystem over the new one.
On the board that shows up as `ext4_check_descriptors: Checksum for group N
failed`, followed by emergency mode. The script unmounts everything first,
writes, reads the card back to compare it with the image, and runs a
read-only `e2fsck`. `--verify-only` checks a card you already wrote.

To see whether the image or the card is at fault, run
`sudo tools/check-ext4-gdt.py out/m6g2c-debian-trixie-armhf.img` (the image)
and `sudo tools/check-ext4-gdt.py /dev/sdX2` (the card).

Serial console: **UART1, 115200 8N1**, output only. On the gateway only the
console's TX and GROUND pins are connected to the board, so you can watch
the boot messages there, but it doesn't accept input and you can't log in
from it.

To log in, use SSH, which means the gateway has to be on a network: wired
Ethernet (it comes up with DHCP, systemd-networkd) or Wi-Fi (section 5a;
the Wi-Fi settings are built into the image, so set them before building).
Log in as **debian / debian**; you must choose a new password at first
login (root is locked, use `sudo`). The first boot grows the root partition
to the card size and creates the SSH host keys, and the battery-backed
PCF85063 RTC is used as the system clock (`/dev/rtc0`).

### Booting from the SD card

The i.MX6UL must be told to boot from uSDHC1. On the BDG-256 there is a
switch under the corner of the LCD display. Change it from NVRAM to SD.

The SD card slot is hidden under the case, on the side of the board with the
LCD screen. Open the case and insert the SD card, contacts facing the board.
The case will not close with an SD card installed. You will need to make a
small cutout in the case to accommodate the SD card. You should use a high
endurance microSD card to ensure longevity.

---

## 4. Board-specific details (confirmed on the EPC-M6G2C)

ZLG does not publish the M6G2C schematic or device tree. The details below
were worked out from ZLG's factory boot log, its device tree (read from the
NAND) and the factory software, and confirmed on the EPC-M6G2C this kit was
developed on, which runs this image. On that unit they are settled; the
steps after the table are only for porting the kit to another unit or
carrier board:

| Item | Status | Where |
|---|---|---|
| SD card | **confirmed** uSDHC1 (has CD and WP GPIOs; this port polls instead) | U-Boot DTS + `m6g2c.c`, kernel DTS |
| Debug UART | **confirmed** UART1 (ttymxc0) | same |
| Ethernet | **confirmed** ENET2, DP83848C PHY at MDIO address 5; ENET2 pins match ZLG's DT. ZLG's DT also has ENET1 (PHY address 1), which wasn't probed on the logged unit: `M6G2C_ENABLE_FEC1=1` | kernel DTS |
| RTC | **confirmed** PCF85063 @0x51 on GPIO I2C, SDA=GPIO5_IO08, SCL=GPIO5_IO07 | kernel DTS |
| NAND | **confirmed** S34ML02G2, on-flash BBT, factory partition layout | kernel DTS (off by default) |
| USB | **confirmed** OTG1 dual-role (ID pin GPIO1_IO00), OTG2 host | kernel DTS |
| UART7, UART8 | **confirmed** LCD_DATA16/17 and LCD_DATA20/21 (ttymxc6, ttymxc7), enabled | kernel DTS |
| External hardware watchdog | **confirmed** CAT82x supervisor, WDI on **GPIO5_IO00** (SNVS_TAMPER0), 700 ms margin (ZLG's EPC-M6G2C device tree). Fed by default (`WDT_GPIO=5:0`) | `config.env` |
| LEDs | GPIO4_IO16 and GPIO4_IO14, active low. ZLG's file contradicts itself on which is "run"; the heartbeat goes on IO16 | kernel DTS |
| UART2-5 | **confirmed** (ZLG's factory DT, read from NAND): UART2-5 on their own pads, ttymxc1-4, enabled | kernel DTS |
| PLC modems | **confirmed** three NEP MI200E power-line modems (one per mains phase) on ECSPI1 (CSI_DATA04/06/07), chip selects GPIO4_IO20-22, power GPIO4_IO18, reset GPIO4_IO19, LED GPIO4_IO24, timed by EPIT2; `/dev/mi200e` (section 5c). Not used by single-phase units, which have a serial PLC module on UART2 |  kernel DTS + `board-files/linux/drivers` |
| ADC | **confirmed** ADC1 IN8/IN9 on GPIO1_IO08/09 (`/sys/bus/iio/devices/iio:device0`) | kernel DTS |
| GPIOs | **confirmed** ZLG's GPIO-only pins: GPIO3_IO04, GPIO5_IO01-04, GPIO4_IO17 and GPIO4_IO23 (`gpioinfo`, `gpioset`); GPIO4_IO18-22/24 too, unless the MI200E driver is on | kernel DTS |
| Display | **confirmed** Tianma TM043NBH02, 4.3" 480x272 on LCDIF, 16 data lines (RGB565), ZLG's timings; PWM5 backlight; text console and login on `tty1` | kernel DTS + `patches/linux` |
| Touch | **confirmed** 4-wire resistive on the i.MX6UL touch controller (GPIO1_IO01-04) | kernel DTS |
| Beeper | **confirmed** PWM6 on LCD_DATA19, 2.5 kHz as ZLG; `pwm-beeper` input device, console bell, `m6g2c-beep` | kernel DTS |
| CAN, MQS audio, ADV7180 video input | off | kernel DTS |
| DDR calibration | NXP EVK values; DRAM works with them on the EPC-M6G2C | `m6g2c.c` |

**Only for a different unit or carrier:** read the details off the factory
software **before** you overwrite anything:

1. Boot ZLG's original Linux, copy `tools/collect-zlg-hwinfo.sh` to the board
   and run `sh collect-zlg-hwinfo.sh`. It only reads. Copy the resulting
   `zlg-hwinfo.tar.gz` back to your PC.
2. Pin muxing, PHYs, watchdog GPIO, CAN pins:
   ```sh
   tools/decode-pinctrl.py --pinfunc work/linux-v6.12.115/arch/arm/boot/dts/nxp/imx/imx6ul-pinfunc.h \
       zlg-hwinfo/device-tree          # or zlg-hwinfo/mtd1.bin, the factory "dtb" partition
   ```
   This prints every pin group in `MX6UL_PAD_*` form, ready to paste into
   `board-files/linux/imx6ul-zlg-m6g2c.dts`. `phy-ids.txt` and
   `pinctrl-debugfs.txt` in the archive give the PHY addresses and live pin
   owners. (If you have ZLG's `imx6g2c-256m.dts` from their CD or FAE, that
   is the same information in source form.)
3. Board-calibrated DDR settings from ZLG's boot loader:
   ```sh
   tools/imx-dcd-extract.py zlg-hwinfo/mtd0.bin      # or ZLG's u-boot.imx
   ```
   It decodes the DDR geometry (should report 256 MiB, 14 row / 10 col,
   16-bit) and prints a ready-made command line such as
   `DDR_MPDGCTRL0=0x... DDR_MPRDDLCTL=0x... ./build.sh uboot`.

Then rebuild: `./build.sh uboot && ./build.sh kernel && sudo ./build.sh rootfs && sudo ./build.sh image`.

### 4a. External hardware watchdog (boot loop about 1.6 s after power-on)

The EPC-M6G2C has a CAT82x-type supervisor (about 1.6 s typical, about 1.1 s
worst case) that runs from power-on and resets the board unless its WDI
input is toggled. ZLG's EPC-M6G2C device tree puts WDI on **GPIO5_IO00**, and
the kit now feeds it by default (`WDT_GPIO=5:0`, `WDT_MARGIN_MS=700`, as in
ZLG's own configuration).

This is confirmed on the EPC-M6G2C this kit was developed on: the image
boots and runs there with the watchdog fed. To check the pin on a different
unit, run this on its factory Linux:
`cat /sys/kernel/debug/gpio` (mount debugfs first if needed). In that
kernel's numbering GPIO5_IO00 is `gpio-128`, and it should be listed as in
use by the watchdog driver. On another carrier, or if the watchdog is
disabled with the WDG jumper, set `WDT_GPIO=""`. Unfed, the board resets during U-Boot's
countdown, or at the prompt if you stop it. Restarts show `U-Boot SPL ...`
again with no error message.

* **To get going right away:** the factory U-Boot says *"connect WDG pin and
  press <zlg> or <ZLG> keys to stop autoboot"*. So the EPC-M6G2C has a
  **WDG** pin or jumper that disables the watchdog, matching the `WDO_EN`
  disable line in ZLG's reference design. With it connected, this image boots
  without any feeding. Check the EPC-M6G2C manual or the label next to the
  terminal block/jumpers for "WDG". It is also how you reach the factory
  U-Boot prompt: connect WDG, then type `zlg` during the 1 s countdown.
* **On a different carrier:** find the feed GPIO and build it in.
  1. Boot ZLG's factory Linux, run `tools/collect-zlg-hwinfo.sh`, copy the
     archive back, then:
     ```sh
     tools/find-wdt-gpio.py zlg-hwinfo/          # prints e.g.  WDT_GPIO=1:9
     ```
     It searches the live device tree, the factory `dtb` NAND partition
     (`mtd1.bin`) and debugfs. ZLG's driver announces itself as
     *"zlg gpio watchdog driver"*, and its node is matched by name. The quickest
     manual check on the factory system is:
     ```sh
     grep -il 'wdt\|wdg\|watchdog' /proc/device-tree/*/compatible
     ```
  2. Rebuild everything with it (U-Boot and the kernel both need it):
     ```sh
     ./build.sh uboot && ./build.sh kernel     # with WDT_GPIO=1:9 exported or set in config.env
     sudo -E ./build.sh rootfs && sudo -E ./build.sh image
     ```
  SPL toggles the pin from its first instructions and again around DDR
  set-up and loading U-Boot. U-Boot keeps toggling it every 200 ms, at the
  prompt as well, and once more right before starting Linux. Linux's built-in
  `gpio-wdt` driver then takes over (`WDT_MARGIN_MS`, 700 ms as in ZLG's
  configuration).

  Linux has to reach that driver before the watchdog fires (about 1.1 s at
  worst). Two things make that possible:
  * The kernel stage patches `gpio_wdt` to start at `subsys_initcall_sync`,
    right after the GPIO controllers. Stock Linux starts it at
    `arch_initcall`, before the GPIO controllers exist; it then waits until
    every other driver has started, and the board resets first.
  * The kernel is trimmed to the i.MX6UL (no sound, video input, Bluetooth
    or other SoCs' drivers; the display and Wi-Fi drivers that the board
    uses are in) and LZO-compressed, so it loads and decompresses faster.

  If it still resets right after `Starting kernel ...`, try
  `KERNEL_CMDLINE_EXTRA=quiet`, and check that the last U-Boot line before
  the reset is the kernel start, not an earlier step.

**Symptom guide**

* Nothing at all on UART1 → the ROM is not booting the card (boot switches /
  fuses, see section 3) or the console is on another UART.
* SPL banner, then hangs or `DRAM` errors → DDR calibration; use step 3.
* Resets by itself about 1.6 s after power-on, in U-Boot or early in Linux
  → external hardware watchdog; see 4a. Note that the kernel prints nothing
  until its serial driver starts. A silent reset right after
  `Starting kernel ...` therefore doesn't mean Linux never ran.
* `Checksum for group N failed` at boot, then emergency mode → the card no
  longer matches the image (see section 3). Rewrite it with
  `tools/write-sdcard.sh`. The kernel command line has `fsck.repair=yes`, so
  milder damage is repaired automatically at boot.
* Emergency mode → its shell is on the serial console only, which on the
  gateway is output only (see section 3), and SSH isn't running yet. Read
  the cause from the console output, then fix the card on the PC: rewrite it
  with `tools/write-sdcard.sh`, or check it with `tools/check-ext4-gdt.py`
  and `e2fsck`.
* Linux boots but no network → on the EPC-M6G2C the PHY address is known (5).
  If `dmesg` shows no PHY, the RMII pins or the 50 MHz reference clock
  direction differ from the assumption; see step 2.

## 5. Device-tree switches

`board-files/linux/imx6ul-zlg-m6g2c.dts` has these switches, which can also be
set when building the kernel (defaults shown):

```sh
M6G2C_ENABLE_FEC2=1 M6G2C_ENABLE_FEC1=0 M6G2C_ENABLE_RTC=1 M6G2C_ENABLE_LEDS=1 M6G2C_ENABLE_UART78=1 M6G2C_ENABLE_UART2345=1 \
M6G2C_ENABLE_MI200E=0 M6G2C_ENABLE_SPI=1 M6G2C_ENABLE_ADC=1 M6G2C_ENABLE_GPIO_HOG=1 \
M6G2C_ENABLE_DISPLAY=1 M6G2C_ENABLE_TOUCH=1 M6G2C_ENABLE_BEEPER=1 \
M6G2C_ENABLE_NAND=1 M6G2C_ENABLE_CAN=0 M6G2C_ENABLE_USB=1 ./build.sh kernel
```

**On-board NAND** is on, with ZLG's factory partitions all **read-only**:

| Device | Name | Size | Contents |
|---|---|---|---|
| `/dev/mtd0` | u-boot | 4 MiB | ZLG's U-Boot |
| `/dev/mtd1` | dtb | 1 MiB | ZLG's device tree |
| `/dev/mtd2` | kernel-0 | 8 MiB | ZLG's Linux 4.1.15 kernel |
| `/dev/mtd3` | rootfs | 243 MiB | UBI: ZLG's root filesystem (UBIFS) |

Mount the factory root filesystem with:

```sh
sudo ubiattach -p /dev/mtd3        # attaches read-only
sudo mount -t ubifs -o ro ubi0:rootfs /mnt
```

Two things were checked on an EPC-M6G2C before NAND was switched on by
default:
* Linux (checked with 6.1) finds ZLG's bad-block table (`Bad block table found at page
  131008`) and writes nothing. Read-only partitions can't prevent the one
  write they don't cover: Linux writes a new bad-block table if it can't find
  the old one. Check `dmesg` for that line after changing anything here.
* ZLG wrote the flash with BCH ECC strength 4, the chip's minimum, while Linux
  (6.1 and 6.12) defaults to the strongest layout that fits (18). Without
  `fsl,use-minimum-ecc` every read fails with `ECC error` (-74).
  `cat /sys/class/mtd/mtd3/ecc_strength` should print 4. On another unit,
  `tools/gpmi-raw-analyze.py` on a `nanddump --noecc --oob` page shows the
  strength actually used.

Remove `read-only` from a partition only once you intend to take over the
NAND, for example to install this system into it with `mtd-utils`/UBI
(included).

## 5a. Wi-Fi (USB dongle)

Two dongles work:

* **TP-Link TL-WN823N**, the dongle NEP ships with the Gateway. It uses the
  in-kernel `rtl8xxxu` driver: v2/v3 (USB ID 2357:0109) have the RTL8192EU
  chip, v1 the RTL8192CU. Its firmware comes from Debian's
  `firmware-realtek` package. It has a weak signal; see **Signal** below.
* **TP-Link TL-WN722N v1** (Atheros AR9271, USB ID 0cf3:9271), with an
  external antenna; recommended. It uses the in-kernel `ath9k_htc` driver,
  and its firmware comes from Debian's open-source `firmware-ath9k-htc`
  package. Only v1 has the AR9271: v2 and v3 have a Realtek RTL8188EUS,
  which `rtl8xxxu` supports but which hasn't been tested here.

Plug the dongle into the USB-A port on the bottom of the Gateway; `lsusb`
shows which one (and which version) you have. The TL-WN722N with its
antenna doesn't fit inside the Gateway's weatherproof housing, so it needs
a short USB-A extension cable (male to female).

Set the network in `local.env` (next to `config.env`; keep it private):

```sh
: "${WIFI_SSID:=My Network}"
: "${WIFI_PASSPHRASE:=my passphrase}"
: "${WIFI_COUNTRY:=US}"
```

The rootfs stage writes `/etc/wpa_supplicant/wpa_supplicant-wlan0.conf`
(mode 600). It contains only the derived WPA2 key, not the passphrase. udev
starts `wpa_supplicant@wlan0` when the dongle appears, and systemd-networkd
gets an address by DHCP. Without the dongle the boot doesn't wait for it.
`net.ifnames=0` on the kernel command line keeps the names `eth0` and `wlan0`.

On the board: `iw dev wlan0 link` shows the association, `ip addr show wlan0`
the address, and `journalctl -u wpa_supplicant@wlan0` any errors. To change
networks later, use the web interface's **Wi-Fi** page (section 7a), which
puts the previous settings back if the new ones don't connect. By hand: edit
the config file (`wpa_passphrase "SSID" "pass"` makes the `network={}`
block), then `sudo systemctl restart wpa_supplicant@wlan0`.

**Signal.** The TL-WN823N sits under the Gateway's metal case, which
weakens it. Where the signal is poor (seen at about -65 dBm with the
receive rate down to 2 Mbit/s), the link drops every so often
(`CTRL-EVENT-BEACON-LOSS` in `journalctl -u wpa_supplicant@wlan0`, "beacon
loss" in `iw dev wlan0 station dump`). After such a drop `rtl8xxxu` can get
stuck: every attempt to reconnect ends in "authentication with ... timed
out", and only unplugging and replugging the dongle brings it back.

The fix is to replace the TL-WN823N with a TL-WN722N v1. In the same spot
and on the same morning, the TL-WN823N had -56 to -64 dBm, a receive rate
of 2 to 6.5 Mbit/s and 28 beacon losses overnight; the TL-WN722N has -51
to -54 dBm and 72 Mbit/s both ways, its maximum on a 20 MHz channel.
Ethernet works too where a cable reaches the Gateway.

NEP also offers a "BDG WiFi Booster" (CF-WR302S) for weak Wi-Fi: a
plug-in repeater with an Ethernet port for the Gateway and its own
antennas. It adds another access point to the home network; replacing the
dongle is simpler.

Cards made before 7 November 2026 lack the TL-WN722N's driver. Build the
kernel again (`./build.sh kernel`), copy
`work/kernel-install/lib/modules/<release>/kernel/net/{wireless,mac80211}`
and `.../kernel/drivers/net/wireless` over the board's copies (as root), run
`sudo depmod -a` and `sudo apt install firmware-ath9k-htc`, then reboot
with the new dongle plugged in. (`mac80211` changes too, so the modules go
together.)

The kernel only trusts the upstream-signed copy of the Wi-Fi regulatory
database, so the rootfs stage selects `/lib/firmware/regulatory.db-upstream`.
Otherwise the kernel logs "regulatory.db is malformed" and ignores
`WIFI_COUNTRY`. Check with `iw reg get`.

WPA2/WPA3 "transition mode" access points accept this WPA2 key. A WPA3-only
(SAE) network needs the plain passphrase and `key_mgmt=SAE` (with
`ieee80211w=2`) instead; trixie's wpa_supplicant supports that, but whether
`rtl8xxxu` does SAE on your dongle is untested.

## 5b. Display (Tianma TM043NBH02)

The LCD runs through the i.MX LCD controller's DRM driver (`mxsfb`) and a
generic `panel-dpi` panel with ZLG's timings: 9 MHz, 480x272. Because the
panel is wired with 16 data lines, it needs RGB565. `panel-dpi` can't express
that (as of 6.12 the `data-mapping` property is only implemented for LVDS
panels), so `patches/linux/0001-...` adds it to `panel-dpi`. Without it the colours would be wrong. The kernel
stage applies every patch in `patches/linux/`.

* Text console: `tty1` shows a login prompt (a USB keyboard works there)
  until NEP's software is set up (section 7). After that, NEP's touch-screen
  program (`zlg-hmi`) takes over the display and `tty1`, and the login
  prompt there is switched off; log in over SSH (section 3).
* Backlight: `/sys/class/backlight/backlight/brightness`, 0-7.
* Touch: `/dev/input/by-path/*tsc*` (see `evtest`). It reports raw ADC
  values, so calibrate it for a GUI (tslib `ts_calibrate`, or a libinput
  calibration matrix).
* Beeper: `m6g2c-beep [Hz] [ms] [count]`, e.g. `m6g2c-beep 2500 100 3`. It
  needs the `input` group or root. The console bell (`printf '\a'` on
  `tty1`) also beeps, at 2.5 kHz.
* If the image looks shifted or unstable, flip `pixelclk-active` in the
  `panel-timing` node. ZLG's value is used, but its 4.1 kernel's LCD driver
  isn't the same driver.

## 5c. Power-line modems (NEP MI200E, `/dev/mi200e`)

**Which units use it.** NEP's `gateway-plc` picks its PLC link from
`/root/Gateway/engineer.cfg`:

| `comm_device` | `plc_module` | link |
|---|---|---|
| 0 | 0 | the MI200E modems, `/dev/mi200e` (this section) |
| 0 | 1 | serial PLC module on UART2, `/dev/ttymxc1`, 9600 8N1 |
| 0 | 2 | serial PLC module on UART2, other settings |
| 1 | | serial device on UART3, `/dev/ttymxc2` |
| 4 | | TCP to 10.10.100.254 (Wi-Fi bridge) |

The single-phase EPC-M6G2C this kit was developed on has `comm_device=0`,
`plc_module=1`: it uses `/dev/ttymxc1`, which works as it is, and its
MI200E reads back nothing (ZLG's own driver reports `self check Error!!:3`
on every modem there too). So `M6G2C_ENABLE_MI200E` is **off by default**;
turn it on for units configured for `/dev/mi200e`.


The EPC-M6G2C talks to the solar inverters over the mains through three
MI200E modems, one per phase. NEP's `gateway-plc` uses them through
`/dev/mi200e`, which ZLG's system provided with NEP's kernel module
`plc_mi200e_o.ko`. That module was built for Linux 4.1.15 and can't load on
6.12, so `board-files/linux/drivers/nep-mi200e.c` re-implements it. Its
behaviour was reconstructed from NEP's GPL module: the same SPI command
sequences, register set-up, timing and state machine. The interface is
unchanged, so `gateway-plc` needs no changes:

* `write()` copies 130 bytes (payload in bytes 0-127, its length in byte 129),
  sends the frame and waits up to 4 s; it returns the length, or 0 on timeout.
* `read()` never blocks; it copies 130 bytes (length in byte 128) and returns
  the length, or 0 if nothing arrived.
* ioctls: `0x81` network id, `0x85` phase (`0xa`/`0xb`/`0xc`), `0x86` reset;
  `0x82`-`0x84` are accepted and ignored, as before.

The kernel stage adds the driver to `drivers/misc` as the module
`plc_mi200e_o`, the name ZLG's scripts use, and udev loads it at boot. Probing
power-cycles and resets the modems, which takes about 3 s. On the board:

```sh
dmesg | grep mi200e                 # "MI200E power-line modems ready (3 phases)"
ls -l /dev/mi200e
cat /sys/devices/platform/2008000.mi200e/stats   # frames, CRC/id errors, timeouts
```

dmesg also shows the six self-check values (NEP's check expects `ff 17 00
07 00 00`); `phase` and `reg` in the same sysfs folder select a modem and
read (`echo 87 > reg; cat reg`) or write (`echo 07 17 > reg`) its registers.

The driver drives ECSPI1 itself, as NEP's did, so with it on, the plain
`spidev` (`M6G2C_ENABLE_SPI`) is switched off. `gateway-plc` also
contains an unused SPI code path for `/dev/spidev0.0`; it isn't needed.

## 6. About Debian trixie and Linux 6.12

Debian 13 "trixie" is Debian's current stable release (August 2025), with
security support until about 2028 and LTS until about 2030. The build uses
`deb.debian.org`, `trixie-updates` and `security.debian.org`
(`trixie-security`), with the `non-free-firmware` component for the Wi-Fi
firmware. Package lists are refreshed by `apt-daily.timer` only if
`APT_DAILY=1`; otherwise run `sudo apt update && sudo apt upgrade` yourself.

Differences from the buster build that you may notice on the board:
* systemd 257 runs cgroup v2 only; `systemd-resolved`, `systemd-timesyncd`
  and `fw_printenv` (`libubootenv-tool`) are separate packages, all installed.
* Debian 13 armhf uses a 64-bit `time_t`, so the board keeps working past
  2038 (hence the ext4 /boot).
* WPA3-SAE support in wpa_supplicant (section 5a).

The kernel is mainline Linux 6.12 LTS, the series Debian 13 itself ships, but
built from source: no Debian kernel carries an M6G2C device tree, and the
watchdog timing needs the trimmed configuration. `ARCH_FORCE_MAX_ORDER`
changed meaning in 6.4, so `m6g2c.config` now sets 10 (it was 11 for 6.1);
that keeps the display's CMA area at 16 MiB instead of rounding it up to
32 MiB. The device trees
moved to `arch/arm/boot/dts/nxp/imx/`, which the kernel stage detects.
Changing `KERNEL_REF` to another v6.12.y tag is a drop-in update; each version
gets its own tree in `work/linux-<tag>/`, and each Debian release its own
`work/rootfs-<suite>/`.

The trixie package set (`systemd-resolved`, `libubootenv-tool`,
`non-free-firmware`, ...) doesn't exist in buster, so to go back to the
tested buster/6.1 build, use the previous version of this kit rather than
just changing `DEBIAN_SUITE`.

## 7. Running ZLG's gateway software (NEP) on the new system

`board-files/zlg/` (on the board: `/usr/local/share/zlg/`) runs ZLG's
original programs unchanged, from a copy of the factory root filesystem in
`/srv/zlg`, under systemd:

| service | program | ZLG started it from |
|---|---|---|
| `zlg-plc` | `gateway-plc` (talks to the inverters over `/dev/ttymxc1` here) | `/usr/local/bin/gateway`, `plc.sh` |
| `zlg-pctrl` | `gateway-pctrl` | `/usr/local/bin/gateway` |
| `zlg-mqtt` | `gateway-mqtt-nep` (NEP's cloud) | `/usr/local/bin/gateway`, `plc.sh` |
| `zlg-hmi` | `gateway-hmi -qws` (touch screen, takes over `tty1`) | `/usr/local/bin/gateway`, `plc.sh` |
| `zlg-httpd` | ZLG's lighttpd + PHP 5.4, port 80 (replaced by section 7a; on firmware 20230903 NEP's boot script hides its pages, so it answers 403; off once switched) | `rcS` |
| `zlg-cron` | ZLG's crontab (uploads, reports, the 01:00 daily clean) | `ginit.sh` |

Each is restarted 10 s after it stops, which replaces `plc.sh`'s checks.
`zlg-chroot.service` first does what ZLG's start-up did that the programs
need: it runs ZLG's `/usr/local/bin/gateway` and `/opt/ginit.sh`, without
their program starts, pauses and Wi-Fi start-up. Those set up links, the
crontab, `localip` and similar files.

Inside the copy, commands that would disturb Debian are replaced by
stand-ins: `ifconfig` can show addresses but not change them; `killall` only
works on ZLG's own programs; `date` can't set the clock; `modprobe`,
`rmmod`, `hwclock`, `udhcpc`, `wpa_supplicant`, `hostapd` and `ip` do
nothing; and `reboot` (ZLG's `day_clean.sh` reboots at 01:00) restarts
`zlg.target` instead of the board. Debian keeps networking, Wi-Fi, SSH and
the clock.

Set it up on the board:

```sh
sudo timedatectl set-timezone America/New_York   # ZLG's programs use local time
sudo sh /usr/local/share/zlg/install.sh            # services; masks getty@tty1 for the HMI
sudo zlg-import                                    # NAND -> /srv/zlg (read-only on the NAND)
sudo systemctl enable --now zlg.target
systemctl list-units 'zlg*'
```

* **The clock.** ZLG's system kept its clock on local time labelled UTC, and
  NEP's software depends on that: it writes local times into its files, turns
  them into "UTC" epochs for the charts, and runs cron jobs at local hours.
  `zlg-time.so`, preloaded into ZLG's programs only, shows them the clock
  shifted by the board's UTC offset (`/run/zlg/utcoffset`, updated every 5
  minutes, so daylight saving is followed), with `TZ` and PHP on UTC as there.
  The board's own clock stays on real UTC. Set the board's zone with
  `timedatectl set-timezone`. The rootfs stage builds `zlg-time.so`; for a
  board set up from `zlg-migrate.tar.gz`, build it on the PC first:
  ```sh
  arm-linux-gnueabihf-gcc -O2 -marm -fPIC -shared -nostdlib -nostartfiles \
      -fno-stack-protector -fno-builtin -Wl,--hash-style=both \
      -o zlg/zlg-time.so zlg/zlg-time.c
  ```
* The web pages' "Last updated" subtracted China's 8-hour offset; `zlg-chroot`
  changes that line in the copy so it shows local time.
* **The hostname.** When the services start, `zlg-chroot` names the board
  `NEP-` and the last five characters of the gateway's serial number (the
  `SN=` line in `/root/Gateway/sn`), for example `NEP-yyyyy`, and updates
  `/etc/hostname` and `/etc/hosts` to match. Until then it is `m6g2c`
  (`HOSTNAME_TARGET` in `config.env`). `ZLG_HOSTNAME=0` in
  `/etc/default/zlg` keeps the hostname as it is.
* **The NEP firmware version.** This branch is for NEP firmware 20230903
  (`board-files/zlg/nep-firmware`). `zlg-import` reads `major=` from the
  NAND's `/root/Gateway/gateway.cfg`, and `install-web.sh` from the copy's,
  and both stop if the gateway runs another version, naming the branch to
  use instead. `--any-firmware` skips the check.
* `sudo zlg-chroot shell` opens a shell inside the copy with ZLG's environment.
* `ZLG_LOCAL_ONLY=1` in `/etc/default/zlg` cuts every link to NEP's cloud:
  it drops the cron jobs that upload to it (`my_nsync` -> `php remote`,
  nepviewer.com, and newer firmware's `critical_alert.php`) and doesn't
  start `gateway-mqtt-nep`, newer firmware's MQTT link to
  `broker.nepviewer.com`. That also stops NEP's cloud client from
  installing NEP's updates. Data collection, the reports and the local web
  pages carry on; the pages' per-inverter status files come from `gdata.sh`
  -> `lsync` -> `php proxy`, which is local.
* **What the gateway connects to,** with local-only mode on: Open-Meteo once
  an hour for the weather (`api.open-meteo.com`, HTTPS; with a weewx station
  set, 7a, the station on the local network instead), Debian's time
  servers (`systemd-timesyncd`), Debian's package servers only when `apt`
  runs (the automatic daily updates are off), and DHCP and DNS on the local
  network. The map on the Weather page is loaded by your browser from
  OpenStreetMap, not by the gateway. NEP's own programs are closed source;
  no connection from them to the outside has been seen.
* **Email reports** are NEP's, built hourly by `gene.sh`, and are off on
  both gateways: the report switches in `/opt/config/report` are all
  `0`, no recipient is set (`/opt/config/general`), and the jobs that would
  send them are commented out in NEP's `ginit.sh`. `/opt/config/email`
  holds NEP's own factory mail settings, not
  yours.
* Programs a given NEP version doesn't have (older ones have no
  `gateway-mqtt-nep`) are skipped, not restarted in a loop.
* `zlg-import` refuses to replace an existing `/srv/zlg` without `--force`,
  because the copy's data then is newer than the NAND's.
* `gateway-plc` rewrites `/root/Gateway/data/*/min.dat` constantly. Use a
  good (endurance) SD card.
* Only one system talks to the inverters at a time anyway: the board runs
  either the SD card or the NAND.

### 7a. The web interface on Debian's lighttpd and PHP 8.4

ZLG's web server (lighttpd + PHP 5.4.12 from 2013, `zlg-httpd`) is no
longer maintained. `board-files/zlg/web/` replaces it with Debian's lighttpd
and PHP 8.4, kept up to date by apt, while NEP's pages stay as they are:

* `zlg-web-port` converts NEP's app on the board, from the copy in
  `/srv/zlg` into `/srv/zlg/www` (NEP's code is never part of this kit):
  CodeIgniter 2.1.3 -> 3.1.13, Debian's jQuery 3 and jQuery UI 1.13 with
  jQuery Migrate, Highcharts 2.3.3 -> 13.1.1 (free for personal use; NEP's
  chart look kept by `js/zlg-highcharts.js`), simpletooltip -> jQuery UI's
  tooltip, Modernizr dropped, the Info page's map from OpenStreetMap
  (NEP's asked Google's Static Maps API without a key: a broken image), the
  status page's weather line back on, PHP 8 fixes (null-safe `count()` and friends, zeros instead
  of a failed page while `gateway-plc` restarts). It leaves out
  `controllers/proxy.php` (encrypted with `php_screw`, PHP 5 only) and a
  dead `status2.php`, and makes `home/info`, `home/show`, `maildebug` and
  `report/send` unreachable, in any capitalisation.
* `zlg-web-fpm` runs PHP 8.4 chrooted into `/srv/zlg` as `www-data`, with the
  shifted clock (`zlg-time.so`, which now also covers Debian 13's 64-bit
  time calls). `zlg-web` is lighttpd in front of it.
* Errors from NEP's code under PHP 8 go to `journalctl -u zlg-web-fpm`.
* NEP's own copies of the replaced libraries (jQuery 1.6-1.9, jQuery UI 1.8
  and 1.9, Highcharts 2, simpletooltip, Modernizr, poshytip) are still in
  `nep/static/js/` but lighttpd refuses them (403), so no page can load them.
* NEP's separate settings pages (`/wifi`, `/module`, `/misetup`) aren't
  served; their addresses redirect to the kit's own versions in the menu
  (below). ZLG's server,
  which served NEP's, is switched off.

This is installed and running on port 80 on the 20230903 gateway. The steps
below are for a fresh install or an update. Fetch the open-source parts on
the PC (the rootfs stage does it too), then copy the kit to the board:

```sh
board-files/zlg/web/fetch-deps.sh
arm-linux-gnueabihf-gcc -O2 -marm -fPIC -shared -nostdlib -nostartfiles \
    -fno-stack-protector -fno-builtin -Wl,--hash-style=both \
    -o board-files/zlg/zlg-time.so board-files/zlg/zlg-time.c
tar czf zlg-migrate.tar.gz -C board-files zlg
scp zlg-migrate.tar.gz debian@<board>:/tmp/     # the board's IP address or hostname
```

On the board:

```sh
cd /tmp && rm -rf zlg && tar xzf zlg-migrate.tar.gz
sudo sh zlg/install.sh                  # updated zlg-time.so
sudo systemctl restart zlg.target
sudo sh zlg/web/install-web.sh          # new server on port 8080
```

`install-web.sh` checks the NEP firmware version first (section 7). On a
fresh install it also restarts ZLG's services once at the end: `zlg-chroot`
can switch ZLG's command-line PHP (the cron jobs' `php proxy`, the weather,
the reports) to PHP 8.4 only if PHP 8.4 was installed when it started.

A fresh install starts the new server on port 8080, next to ZLG's on port
80. `sudo sh /usr/local/share/zlg/web/install-web.sh --switch` moves it to
port 80 and stops ZLG's; `--revert` goes back.

* **Firmware 20230903 (this branch's firmware):** the port was checked
  page by page in the browser on a 20230903 gateway, so run `--switch`
  right after installing. NEP's own server shows nothing on this firmware,
  so there is nothing to compare.
* **Firmware 20210303:** use the `nep-fw-20210303` branch. There the port
  was verified page by page against ZLG's server, so the same holds.
* **Any other NEP version:** its pages and code haven't been through the
  port yet. Compare `http://<board>:8080/` with ZLG's `http://<board>/`
  first, check `journalctl -u zlg-web-fpm` for PHP errors, and switch once
  everything matches. The installers' firmware check stops first; pass
  `--any-firmware` to go ahead.

Once switched, re-running `install-web.sh` keeps port 80. After
`zlg-import`, re-run `install-web.sh --rebuild`.

ZLG's command-line PHP (the cron jobs' `php proxy`, which writes the status
boxes, `php wea` and the report scripts) also runs on Debian's PHP 8.4:
`zlg-chroot` mounts the host's `/usr` read-only at `/m6g2c/host/usr` inside
the copy and mounts a wrapper over `/opt/php5/bin/php`, which starts
`php8.4` with its own dynamic loader and libraries. NEP's cron lines and
scripts stay unchanged, and apt keeps the PHP patched. `ZLG_PHP84_CLI=0` in
`/etc/default/zlg` goes back to ZLG's PHP 5.4.

The weather on the pages comes from `zlg-weather` (`board-files/zlg/
zlg-weather.php`), which `zlg-chroot` mounts over NEP's `wea`: NEP's asked
Yahoo's weather service, which closed in 2019. It writes today's weather in
wea's format, from a local weewx station if one is set (below), otherwise
from [Open-Meteo](https://open-meteo.com) (free, no API key, non-commercial
use): today's forecast, once an hour, when NEP's `gene.sh` calls `wea`. The
Open-Meteo location is `ZLG_WEATHER_LAT` / `ZLG_WEATHER_LON` in
`/etc/default/zlg`, or else the longitude/latitude on the gateway's General
settings page (`/root/Gateway/location`). It connects over IPv4 only:
networks that hand out NAT64 IPv6 addresses without a working IPv6 route
would otherwise make every request time out. `ZLG_WEATHER=0` keeps NEP's.
The weather shows on the status page (the bar above the inverter boxes) and
on the Info page (**Weather** in the menu, `http://<board>/nep/info/index`),
together with a map of the Open-Meteo location.

**Weather from a weewx station.** With a [weewx](https://weewx.com) (5.x)
station on the network, the weather comes from it instead: today's high and
low so far, sunrise and sunset, and as the conditions the current readings
(for example "59°F, 59% RH, wind 2 mph SE"; with "Rain 0.12 in/h" first
while it rains and the day's rain last once there is some). A small weewx
report, `board-files/zlg/weewx/zlg-json`, writes them to `zlg.json` with
each archive record, in fixed units whatever the station's own. On the
weewx host (the skins folder is `/etc/weewx/skins` for a Debian package
install, `~/weewx-data/skins` for a pip install):

```bash
sudo cp -r zlg-json /etc/weewx/skins/
```

then add a report under `[StdReport]` in `weewx.conf` and restart weewx.
`HTML_ROOT` is weewx's default web folder, `/var/www/html/weewx`; change it
only if the station's pages (its `index.html`) are served from another
folder:

```
    [[ZlgJson]]
        skin = zlg-json
        enable = true
        HTML_ROOT = /var/www/html/weewx
```

`sudo weectl report run ZlgJson` writes it once without waiting for the
next record. On a gateway set up with an older kit, run the kit's
`sudo sh install.sh` and `sudo sh web/install-web.sh` again first (they add
`zlg-weather.timer` and the Weather page's switch).

Then choose the source on the **Weather** page (`/nep/info/index`), under
the weather table: **Open-Meteo, with map** or **Local Weewx**, with the
station's IP address, name or the URL of its `zlg.json` (`192.168.1.20`
becomes `http://192.168.1.20/zlg.json`). Changing it needs the settings
password (the login is in the same box). Before saving Local Weewx, the
gateway reads the station's `zlg.json` and refuses an address that doesn't
answer with it; after saving it fetches the weather once, so the page shows
the new source at once, without a restart. With Local Weewx the page has no
map, and nothing is loaded from OpenStreetMap.

The switch writes `ZLG_WEATHER_SOURCE` (`weewx` or `openmeteo`) and
`ZLG_WEATHER_URL` in `/etc/default/zlg`; they can be set there by hand too
(then `sudo zlg-chroot weather`, or restart the NEP software). Without
`ZLG_WEATHER_SOURCE`, a set `ZLG_WEATHER_URL` means weewx.
`zlg-weather.timer` reads the station every 10 minutes, as well as
`gene.sh`'s hourly run, and Open-Meteo isn't contacted. A reading more than
30 minutes old shows as "Station offline since HH:MM"; if the station
doesn't answer, the last weather stays.

**Settings pages.** After Weather, the menu has the gateway's settings,
which NEP served as separate pages. Anyone on the network can view them;
changes need the settings password, `gateway` at first, changed on any of
the three pages (the Weather page's source switch uses it too) (`sudo zlg-admin --reset-password` sets it back). The work
is done by `zlg-admin`, a helper running as root outside `/srv/zlg`, which
checks every request itself; the pages reach it over a socket
(`zlg-admin.socket`, `/run/zlg/admin.sock`).

* **Wi-Fi** (`/nep/wifi`): the connection (network, access point, signal,
  bit rate, address) and its settings: the network name, with a search for
  networks in range; WPA2-Personal, WPA3-Personal or open; an address from
  the router (DHCP) or a fixed one. A change is tested for 90 seconds: if
  the gateway can't connect, the previous settings come back by themselves.
  If the power goes during the test, `zlg-wifi-guard` puts them back at the
  next boot. Tested: an unchanged save reconnects in about 10 seconds; a
  wrong Wi-Fi password is undone after 90 seconds.
* **Modules** (`/nep/modules`): the inverter and meter IDs the gateway
  polls (`plc_id.conf`), in panel order: 1 to 8 hex digits, or a whole
  serial number such as `21508-0005F123-U`. Saving keeps the last 10 lists
  in `/var/lib/zlg-admin/modules` and restarts the gateway software, as
  NEP's own "reboot" does: about a minute without readings, and the web
  pages log you out. The phase column of three-phase systems isn't shown.
* **Advanced** (`/nep/advanced`): NEP's MI Setup page from firmware
  20230903, which reads and writes the inverters' grid settings: one of
  NEP's country profiles (`misetup/setting/*.conf`; for the US `US-240`,
  `US-240-1547A` for IEEE 1547, `US-240-RULE21`, `US-HECO-240` and 208 V
  versions) or custom ones, which set 50 or 60 Hz, the voltage and
  frequency trip limits and clearing times, the power limit, the reconnect
  time and, for 1547 and Rule 21, the volt-var, volt-watt and ramp
  functions. The page and what it reads and writes are NEP's code; its
  server side (NEP's `proxy.cgi` and PHP files) is `zlg-admin`.
  * **Setup Mode** stops `gateway-plc` (the PLC module serves one program),
    so there are no readings until **Leave Setup Mode** (NEP's "Reboot
    Gateway" button) or 10 minutes without a request. The inverters answer
    only while they produce. **Check** reads the selected modules.
  * The PLC password NEP's page asks for is kept on the board: set it once
    with `sudo zlg-admin --set-mi-password` (it reads the value from its
    input); `install-web.sh` reminds you while it isn't set.
  * **Set** (writing) is off: it needs `ZLG_MI_WRITES=1` in
    `/etc/default/zlg`, the settings password and firmware 20230903 or
    later. Writes are logged (`journalctl -t zlg-admin`,
    `/var/lib/zlg-admin/mi-writes.log`). Wrong values can make the
    inverters disconnect from the grid or run outside what the utility
    allows; read every module first and keep the replies as a baseline.
  * **800B inverters (IDs starting `33`) are read and written in NEP's
    2021 encoding** on grid codes 6 and 1 (`js/zlg-mi2021.js`, served at
    `/zlg-web/local/`). NEP's 2021 and 2023 MI Setup pages encode these
    grid codes differently; the 800B's stored settings fit the 2021
    encoding (round values; the 2023 page shows -18 V and 74.4 Hz), and a
    one-inverter test confirmed it: a 236 V over-voltage limit written in
    the 2021 encoding tripped the inverter at a 242 V grid. The 2021
    encoding holds 161.5 to 278.5 V (220 V &plusmn; byte &times; 4 / 17.447),
    the nominal frequency &plusmn; 7.9 Hz (1/32 Hz steps), fast trip times up
    to 3.1 s and slow ones up to 204.7 s (0.1 s steps, rounded down) and
    the reconnect time in 2.048 s steps. A value outside that is refused
    with the allowed range, not changed; rounded values are listed under
    Inverter Output.
  * **Check a module before Set**: an 800B write starts from the module's
    last reply and changes only the checked fields. It never sends the
    frequency calibration (command byte 6, which the inverter takes as the
    actual grid frequency and recalibrates its reading to). `zlg-admin`
    refuses 800B writes that don't come from the page's 2021 encoder, with
    another grid code or with a calibration byte, whatever `ZLG_MI_WRITES`
    says.
  * **Extended Set** (all models): NEP's page sends every section whose box
    isn't ticked as 00, not "unchanged", and an 800B inverter stores some
    of those zeros (the fixed power factor, the watt-PF points, the
    volt-watt control byte; it keeps Temperature MAX and CTRL FLAG). The
    page asks before writing with V_Norm/Ramp, FREQ-WATT, VOLT-WATT or
    VAR-CTRL unticked: Check the module and tick the sections to keep.
    Writing a range of modules uses the form as it was when Set was
    pressed for every module (NEP's page reloaded it from each reply and
    sent V_Norm and Ramp Delay as 00 to all but the first). The two
    modules of an inverter share its settings.
  * **An 800B inverter stops producing for about 5 minutes after each read
    or write** (its reconnect time); the settings don't change.
  * `sudo zlg-admin --mi-calibrate MODULE OFFSET` sets one module's
    frequency calibration (OFFSET in 1/32 Hz, as the reply shows it) and
    writes its other settings back unchanged. It needs a minute reading
    from the last 5 minutes, stops `gateway-plc` while it runs, checks the
    result by reading back (up to 3 tries) and logs like a write.
  * **Generate Grid Report** (needs the settings password) reads every
    module's settings (about 10 seconds per module, no readings meanwhile;
    `gateway-plc` starts again afterwards) and offers a `.tgz` with a CSV
    (a row per module: every setting and the raw replies) and a PDF
    (modules with the same settings grouped, then the raw replies). The
    frequency limits are offsets from the nominal grid frequency, which the
    report takes from the frequency the inverters measure (their last
    minute readings, rounded to 50 or 60 Hz), else from the grid code;
    without either it shows the offsets. Settings are decoded as the
    Advanced page shows them (800B inverters in the 2021 encoding, with
    trip and reconnect times and the frequency calibration); an "Encoding"
    row/column says which. Meters (IDs starting `FF`) are skipped.
  * Only firmware 20230903's MI Setup is built. **Firmware 20210303's
    doesn't know the 800B inverters (IDs starting `33`)**: it would encode
    the voltage trips about 17 times too low and the power limit doubled.
    On other firmware versions the Advanced page says it isn't available.

## 8. Layout

```
build.sh                    stage runner
config.env                  all settings
scripts/01-uboot.sh         adds the board port to U-Boot 2024.01, builds SPL + U-Boot
scripts/02-kernel.sh        Linux 6.12 + DTB + modules
scripts/03-rootfs.sh        debootstrap trixie armhf, system configuration
scripts/04-image.sh         partitions, filesystems, boot loader -> .img/.img.xz
board-files/u-boot/         board/zlg/m6g2c, defconfig, config header, U-Boot DTS
board-files/linux/          kernel DTS, config fragment, drivers/ (MI200E PLC driver)
board-files/rootfs/         first-boot resize/SSH-key service
board-files/zlg/            runs ZLG's gateway software in /srv/zlg (section 7)
board-files/zlg/web/        NEP's web interface on lighttpd + PHP 8.4 (section 7a)
tools/                      factory hardware dump, pinctrl decoder, DCD/DDR decoder,
                            watchdog-GPIO finder
```

## 9. What has been tested

**On the gateway with NEP firmware 20230903 (the "New" unit), running the
trixie image:**

* Boot from the SD card, Wi-Fi, SSH and the NAND (read-only); `zlg-import`
  with its firmware check ("NEP firmware 20230903: matches this kit").
* NEP's gateway software from `/srv/zlg`: `gateway-plc` (with the serial
  port to the inverters open), `gateway-pctrl`, the touch-screen HMI and
  the cron jobs, with PHP 8.4 for the command-line scripts. The hostname
  became `NEP-yyyyy`.
* Local-only mode: `gateway-mqtt-nep` isn't started, the crontab keeps only
  its local jobs, and the board has no connections to the outside.
* The web interface on port 80, checked in the browser: status page,
  Energy Output charts (Highcharts 13), Alert, Layout and the Weather page
  with its weather and map; the history carried over from the factory
  system. ZLG's server is off.
* In production since the evening of 6 November 2026, replacing the
  20210303 gateway, with the same 24 inverter modules configured. Live
  inverter readings from the next morning on: every connected module
  reports, over the same serial PLC module and `gateway-plc` set-up as on
  the 20210303 gateway.
* Wi-Fi at its production spot was weak with the TL-WN823N (about -65 dBm,
  2 Mbit/s receive rate); the link dropped twice on the first evening and
  the dongle had to be replugged each time. Since 7 November it uses a
  TL-WN722N v1 (`ath9k_htc`) on a USB extension cable: about -52 dBm and
  72 Mbit/s (section 5a, "Signal").
* The settings pages (section 7a), on 8 November 2026:
  * Wi-Fi: viewing, login, the network search and the input checks. An
    unchanged save reconnected in about 10 seconds with the files
    byte-identical; a wrong Wi-Fi password was undone after 90 seconds;
    `zlg-wifi-guard` undid a pending change when run by hand.
  * Modules: one ID changed and changed back through the page, each save
    followed by the gateway software's restart and readings resuming; the
    file was byte-identical to the original afterwards.
  * Advanced: NEP's MI Setup page loads and decodes reads of every module
    through `zlg-admin`, with replies identical to `plc_set`'s; the
    readings resume on **Leave Setup Mode**. Writing hasn't been switched
    on or tried.

The rest of this section is the 20210303 gateway's test record, which
covers the shared parts (U-Boot, kernel, hardware).


**On the EPC-M6G2C this kit was developed on (the "Old" unit, firmware
20210303, in production until 6 November 2026), running the trixie image:**

* Boot: U-Boot 2024.01 (this kit's board port) and Linux 6.12.115 from the
  SD card, with the external hardware watchdog fed from power-on (section
  4a); systemd takes over at about 3.5 s.
* Hardware: Ethernet, Wi-Fi (TL-WN823N, `rtl8xxxu`), NAND (read-only
  partitions), display, touch, beeper, RTC (holds UTC) and `spidev`.
* NEP's gateway software from `/srv/zlg` (section 7): inverter data over the
  serial PLC module on `/dev/ttymxc1`, the touch-screen HMI, the cron jobs
  and reports, with local-only mode on.
* The web interface on Debian's lighttpd and PHP 8.4 (section 7a): every
  page compared with ZLG's own server and confirmed in the browser, then
  switched to port 80: status page with live inverter boxes, Energy Output
  charts (Highcharts 13), Alert, Layout including Manage layout and moving
  boxes, the Weather page with its map, and the settings pages. ZLG's PHP 5
  server is off.
* ZLG's command-line PHP on PHP 8.4: `php proxy` writes the same 127 files
  as under PHP 5 (apart from timestamps); the hourly reports are generated.
* The weather from Open-Meteo for the configured location.
* The NEP firmware check: `install-web.sh` reports "NEP firmware 20210303:
  matches this kit"; the board's hostname became `NEP-xxxxx`.
* The shared parts (U-Boot, kernel, Debian, the ZLG set-up, the web
  interface) also run on the gateway with firmware 20230903; its test
  record is at the start of this section. This unit's firmware has no
  Advanced page (section 7a).

**Not tested, because this unit doesn't use them:** the MI200E power-line
modems (this unit uses the serial PLC module; its MI200E modems fail their
self check under ZLG's own driver too, section 5c), ENET1, CAN, UART7/8 and
the ADC on trixie, and WPA3-only (SAE) Wi-Fi (section 5a).

**Build-time checks:** the U-Boot Kconfig patch applies (and re-applies)
cleanly to v2024.01 and every defconfig value takes effect; the board C file
type-checks for both SPL and U-Boot proper; both device trees compile with
all feature combinations; every kernel config fragment value takes effect on
top of `imx_v6_v7_defconfig`; and the decoding tools round-trip test data.
