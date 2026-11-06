#!/bin/sh
# Run this ON THE BOARD, under ZLG's factory Linux (busybox sh is fine), before
# replacing anything. It only reads; it changes nothing on the board.
#
# It saves what the mainline port needs and ZLG does not publish:
#   * the live device tree (pin muxing, PHY addresses, watchdog GPIO, CAN, UARTs)
#   * the boot loader from NAND (its DCD holds board-calibrated DDR settings)
#   * MTD layout, U-Boot environment, kernel command line, PHY IDs, pinmux state
#
#   sh collect-zlg-hwinfo.sh [output-dir]      (default: /tmp/zlg-hwinfo)
# then copy the resulting .tar.gz to your PC (USB stick, SD card or scp).

OUT=${1:-/tmp/zlg-hwinfo}
mkdir -p "$OUT" || exit 1
cd "$OUT" || exit 1

run() {  # run <file> <command...>
	f=$1; shift
	"$@" > "$f" 2>&1 || echo "(command failed: $*)" >> "$f"
}

run uname.txt        uname -a
run cmdline.txt      cat /proc/cmdline
run cpuinfo.txt      cat /proc/cpuinfo
run meminfo.txt      cat /proc/meminfo
run mtd.txt          cat /proc/mtd
run partitions.txt   cat /proc/partitions
run dmesg.txt        dmesg
run interfaces.txt   ip addr
run modules.txt      cat /proc/modules

# Device tree: raw copy (always works) and decompiled (if dtc exists).
if [ -d /proc/device-tree ]; then
	cp -r /proc/device-tree ./device-tree 2>/dev/null
	command -v dtc >/dev/null 2>&1 &&
		dtc -I fs -O dts -o live.dts /proc/device-tree 2>/dev/null
fi
cp /boot/*.dtb . 2>/dev/null

# Ethernet PHYs
for d in /sys/bus/mdio_bus/devices/*; do
	[ -e "$d/phy_id" ] && echo "$(basename "$d") $(cat "$d/phy_id")"
done > phy-ids.txt 2>/dev/null

# Pin and GPIO state from debugfs (mounted temporarily if needed)
dbg=/sys/kernel/debug
if [ ! -d "$dbg/pinctrl" ]; then
	mount -t debugfs none "$dbg" 2>/dev/null && umounted=1
fi
for f in "$dbg"/pinctrl/*/pinmux-pins "$dbg"/pinctrl/*/pinconf-pins; do
	[ -r "$f" ] && { echo "### $f"; cat "$f"; }
done > pinctrl-debugfs.txt 2>/dev/null
[ -r "$dbg/gpio" ] && cat "$dbg/gpio" > gpio-debugfs.txt
[ -n "${umounted:-}" ] && umount "$dbg"

# U-Boot environment
command -v fw_printenv >/dev/null 2>&1 && run uboot-env.txt fw_printenv

# Boot loader and device-tree partitions from NAND, read through the MTD
# driver (with ECC). On the EPC-M6G2C these are mtd0 "u-boot" (4 MiB) and
# mtd1 "dtb" (1 MiB, the factory device tree). Kernel/rootfs are skipped.
grep -iE '"(u-?boot|boot|spl|bootloader|uboot|dtb|fdt|devicetree)[^"]*"' /proc/mtd 2>/dev/null |
while IFS=: read -r dev _rest; do
	if command -v nanddump >/dev/null 2>&1; then
		nanddump -q -f "$dev.bin" "/dev/$dev" 2>/dev/null
	else
		dd if="/dev/$dev" of="$dev.bin" bs=128k 2>/dev/null
	fi
done
# Fall back to mtd0 if nothing matched
ls mtd*.bin >/dev/null 2>&1 || dd if=/dev/mtd0 of=mtd0.bin bs=128k count=16 2>/dev/null

cd .. && tar czf "$(basename "$OUT").tar.gz" "$(basename "$OUT")" &&
	echo "Saved $(pwd)/$(basename "$OUT").tar.gz"
