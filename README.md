# Debian trixie (armhf) SD-card image for the ZLG M6G2C-256LI

Build kit that produces a bootable SD-card image for the ZLG **M6G2C-256LI**
core module:

| Part | Device | Notes |
|---|---|---|
| SoC | NXP i.MX6UL, MCIMX6G2CVM05 | Cortex-A7, 528 MHz |
| DRAM | ISSI IS43TR16128DL-125KBLI | DDR3L, 128M x 16 = 256 MiB, 8 banks, 14 row / 10 col, run at 400 MHz |
| NAND | Spansion/Cypress S34ML02G2 (marking ML02G200BH100) | 2 Gbit SLC, 2048+128 B pages, needs 4-bit ECC |

Everything is built from source: mainline **U-Boot 2024.01** (SPL + U-Boot,
with a new `zlg_m6g2c` board port), **Linux 6.12 LTS** with an M6G2C device
tree, and a **Debian 13 "trixie" armhf** root filesystem.

> Earlier versions of this kit built Debian 10 buster with Linux 6.1. That
> combination is what was tested on the EPC-M6G2C; the trixie/6.12 port keeps
> the same board files but has not yet been booted on hardware (section 8).

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

Serial console: **UART1, 115200 8N1**. Log in as **debian / debian**; you
must choose a new password at first login (root is locked, use `sudo`). The
first boot grows the root partition to the card size and creates SSH host
keys. The Ethernet port comes up with DHCP (systemd-networkd), and the
battery-backed PCF85063 RTC is used as the system clock (`/dev/rtc0`).

### Booting from the SD card

The i.MX6UL must be told to boot from uSDHC1. On ZLG carriers this is set with
the boot-mode DIP switch/jumpers (BOOT_MODE = internal boot, BOOT_CFG = SD on
uSDHC1); see the carrier manual. On the EPC-M6G2C this is confirmed to work:
the module's boot fuses don't lock it to NAND.

**If your module ignores the switches** (ZLG may have burned the boot fuses
for NAND), keep ZLG's NAND U-Boot and let it start this system from the card.
At the factory U-Boot prompt:

```
mmc list                       # find the SD card's index, usually 0
setenv sdboot 'mmc dev 0; ext4load mmc 0:1 0x82000000 /zImage; ext4load mmc 0:1 0x83000000 /dtbs/imx6ul-zlg-m6g2c.dtb; setenv bootargs console=ttymxc0,115200 root=/dev/mmcblk0p2 rootfstype=ext4 rootwait ro; bootz 0x82000000 - 0x83000000'
run sdboot                     # try once
setenv bootcmd 'run sdboot'; saveenv   # make it permanent
```

/boot is ext4 without a journal and without the `64bit` feature, so older
vendor U-Boots such as ZLG's 2015.04 can read it with `ext4load` (or
`ext2load`, which reads the same files). Plain ext2 was used before, but its
timestamps end in January 2038, which Debian 13's 64-bit `time_t` userland
goes past.

---

## 4. Before relying on it: confirm the board-specific details

ZLG does not publish the M6G2C schematic or device tree. A factory boot log
of an **EPC-M6G2C** (ZLG U-Boot 2015.04 + Linux 4.1.15) confirmed part of the
hardware; the rest is still standard i.MX6UL wiring, marked `VERIFY` in the
sources:

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
| DDR calibration | NXP EVK values (DRAM already works) | `m6g2c.c` |

The fastest way to settle all of them is to read them off the factory
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

To confirm the pin on your unit, run this on the factory Linux:
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
* Emergency mode with no way to type → the shell is on the serial console
  (UART1) only. A USB keyboard can't reach it, because there is no display
  console. Wire the adapter's TX to the board's RX: three wires, GND, TX→RX
  and RX←TX. Once the system boots normally, SSH works as well (DHCP on
  ENET2; user `debian`).
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

## 5b. Display (Tianma TM043NBH02)

The LCD runs through the i.MX LCD controller's DRM driver (`mxsfb`) and a
generic `panel-dpi` panel with ZLG's timings: 9 MHz, 480x272. Because the
panel is wired with 16 data lines, it needs RGB565. `panel-dpi` can't express
that (as of 6.12 the `data-mapping` property is only implemented for LVDS
panels), so `patches/linux/0001-...` adds it to `panel-dpi`. Without it the colours would be wrong. The kernel
stage applies every patch in `patches/linux/`.

* Text console: `tty1` shows a login prompt; a USB keyboard works there.
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

## 5a. Wi-Fi (TP-Link TL-WN823N USB dongle)

The dongle that ships with the EPC-M6G2C works with the in-kernel
`rtl8xxxu` driver: v2/v3 (USB ID 2357:0109) use the RTL8192EU chip, v1 the
RTL8192CU. Its firmware comes from Debian's `firmware-realtek` package. Plug
it into either USB port; `lsusb` shows which version you have.

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
networks later, edit the config file (`wpa_passphrase "SSID" "pass"` makes the
`network={}` block), then `sudo systemctl restart wpa_supplicant@wlan0`.

The kernel only trusts the upstream-signed copy of the Wi-Fi regulatory
database, so the rootfs stage selects `/lib/firmware/regulatory.db-upstream`.
Otherwise the kernel logs "regulatory.db is malformed" and ignores
`WIFI_COUNTRY`. Check with `iw reg get`.

WPA2/WPA3 "transition mode" access points accept this WPA2 key. A WPA3-only
(SAE) network needs the plain passphrase and `key_mgmt=SAE` (with
`ieee80211w=2`) instead; trixie's wpa_supplicant supports that, but whether
`rtl8xxxu` does SAE on your dongle is untested.

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

## 9. Running ZLG's gateway software (NEP) on the new system

`board-files/zlg/` (on the board: `/usr/local/share/zlg/`) runs ZLG's
original programs unchanged, from a copy of the factory root filesystem in
`/srv/zlg`, under systemd:

| service | program | ZLG started it from |
|---|---|---|
| `zlg-plc` | `gateway-plc` (talks to the inverters over `/dev/ttymxc1` here) | `/usr/local/bin/gateway`, `plc.sh` |
| `zlg-pctrl` | `gateway-pctrl` | `/usr/local/bin/gateway` |
| `zlg-mqtt` | `gateway-mqtt-nep` (NEP's cloud) | `/usr/local/bin/gateway`, `plc.sh` |
| `zlg-hmi` | `gateway-hmi -qws` (touch screen, takes over `tty1`) | `/usr/local/bin/gateway`, `plc.sh` |
| `zlg-httpd` | ZLG's lighttpd + PHP 5.4, port 80 | `rcS` |
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
* `sudo zlg-chroot shell` opens a shell inside the copy with ZLG's environment.
* `ZLG_LOCAL_ONLY=1` in `/etc/default/zlg` drops the cron jobs that upload to
  NEP's cloud (`my_nsync` -> `php remote`, nepviewer.com). Data collection,
  the reports and the local web pages carry on; the pages' per-inverter
  status files come from `gdata.sh` -> `lsync` -> `php proxy`, which is local.
* Programs a given NEP version doesn't have (older ones have no
  `gateway-mqtt-nep`) are skipped, not restarted in a loop.
* `zlg-import` refuses to replace an existing `/srv/zlg` without `--force`,
  because the copy's data then is newer than the NAND's.
* `gateway-plc` rewrites `/root/Gateway/data/*/min.dat` constantly. Use a
  good (endurance) SD card.
* Only one system talks to the inverters at a time anyway: the board runs
  either the SD card or the NAND.

## 7. Layout

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
board-files/zlg/            runs ZLG's gateway software in /srv/zlg (section 9)
tools/                      factory hardware dump, pinctrl decoder, DCD/DDR decoder,
                            watchdog-GPIO finder
```

## 8. What has and has not been tested

Checked while writing this kit: the U-Boot Kconfig patch applies (and
re-applies) cleanly to v2024.01 and every defconfig value takes effect; the
board C file type-checks against the U-Boot headers for both SPL and U-Boot
proper; both device trees compile against U-Boot 2024.01 / Linux 6.12
includes with all feature combinations; every kernel config fragment value
takes effect on top of `imx_v6_v7_defconfig`; and both decoding tools
round-trip test data.

On an EPC-M6G2C, the buster/6.1 version of this kit booted and everything in
the section 4 table worked (watchdog, Ethernet, Wi-Fi, NAND, display, touch,
beeper, SPI, ADC). For trixie/6.12, the kernel configuration and the display
patch were checked against v6.12.115 and the device tree compiles against
it, but the trixie image has not been booted on the board yet. If it resets
early, compare the boot timing with the 6.1 log (section 4a).
