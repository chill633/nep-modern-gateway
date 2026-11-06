#!/usr/bin/env bash
# Stage 1: build SPL + U-Boot for the ZLG M6G2C from mainline U-Boot.
#
# Outputs: out/SPL, out/u-boot-dtb.img
#
# Optional DDR calibration overrides (values from tools/imx-dcd-extract.py or
# from the NXP DDR Stress Test), e.g.
#   DDR_MPDGCTRL0=0x41640158 DDR_MPRDDLCTL=0x40403237 ./build.sh uboot

# shellcheck source=common.sh
source "$(dirname "$0")/common.sh"

need_cmds git make "${CROSS_COMPILE}gcc" bison flex python3

SRC="$WORK/u-boot"
fetch_git "$UBOOT_GIT" "$UBOOT_REF" "$SRC"

log "Adding the ZLG M6G2C board port"
cp -r "$TOP/board-files/u-boot/." "$SRC/"

KCONFIG="$SRC/arch/arm/mach-imx/mx6/Kconfig"
if ! grep -q 'config TARGET_ZLG_M6G2C' "$KCONFIG"; then
	# New target inside the board "choice", right before the NXP EVK entry.
	awk '
	/^config TARGET_MX6UL_14X14_EVK$/ && !done {
		print "config TARGET_ZLG_M6G2C"
		print "\tbool \"ZLG M6G2C core module (i.MX6UL)\""
		print "\tdepends on MX6UL"
		print "\tselect BOARD_LATE_INIT"
		print "\tselect DM"
		print "\tselect DM_THERMAL"
		print "\tselect SUPPORT_SPL"
		print "\timply CMD_DM"
		print ""
		done = 1
	}
	{ print }
	/^source "board\/freescale\/mx6ul_14x14_evk\/Kconfig"$/ {
		print "source \"board/zlg/m6g2c/Kconfig\""
	}' "$KCONFIG" > "$KCONFIG.new"
	mv "$KCONFIG.new" "$KCONFIG"
	grep -q 'config TARGET_ZLG_M6G2C' "$KCONFIG" &&
		grep -q 'board/zlg/m6g2c/Kconfig' "$KCONFIG" ||
		die "could not patch $KCONFIG (U-Boot layout changed? UBOOT_REF=$UBOOT_REF)"
fi

DTS_MAKEFILE="$SRC/arch/arm/dts/Makefile"
if ! grep -q 'imx6ul-zlg-m6g2c.dtb' "$DTS_MAKEFILE"; then
	sed -i '/^\timx6ul-14x14-evk\.dtb \\$/a\	imx6ul-zlg-m6g2c.dtb \\' "$DTS_MAKEFILE"
	grep -q 'imx6ul-zlg-m6g2c.dtb' "$DTS_MAKEFILE" ||
		die "could not patch $DTS_MAKEFILE"
fi

# DDR calibration overrides -> -D defines picked up by board/zlg/m6g2c/m6g2c.c
kcflags=""
for reg in MPWLDECTRL0 MPDGCTRL0 MPRDDLCTL MPWRDLCTL; do
	var="DDR_${reg}"
	if [ -n "${!var:-}" ]; then
		kcflags+=" -DM6G2C_${reg}=${!var}"
		log "DDR calibration: ${reg}=${!var}"
	fi
done

# External hardware watchdog feeder
parse_wdt_gpio
if [ -n "$WDT_BANK" ]; then
	pad=$(find_gpio_pad "$SRC/arch/arm/include/asm/arch-mx6/mx6ul_pins.h" MX6_PAD_)
	kcflags+=" -DM6G2C_WDT_PAD=$pad -DM6G2C_WDT_BASE=GPIO${WDT_BANK}_BASE_ADDR -DM6G2C_WDT_PIN=$WDT_PIN"
	log "Watchdog feed on GPIO${WDT_BANK}_IO$(printf %02d "$WDT_PIN") ($pad)"
else
	warn "WDT_GPIO not set: U-Boot will not feed the module's external watchdog"
fi

# KCFLAGS changes don't trigger rebuilds; make sure the board file is redone.
rm -f "$SRC/board/zlg/m6g2c/m6g2c.o" "$SRC/spl/board/zlg/m6g2c/m6g2c.o"

log "Building U-Boot"
make -C "$SRC" CROSS_COMPILE="$CROSS_COMPILE" zlg_m6g2c_defconfig
make -C "$SRC" CROSS_COMPILE="$CROSS_COMPILE" KCFLAGS="$kcflags" -j"$JOBS"

# The ROM loads SPL from 1 KiB; SPL loads U-Boot from sector 0x8a (69 KiB).
spl_size=$(stat -c %s "$SRC/SPL")
[ "$spl_size" -le $(( (69 - 1) * 1024 )) ] ||
	die "SPL is ${spl_size} bytes and would overlap u-boot-dtb.img at 69 KiB"

cp "$SRC/SPL" "$SRC/u-boot-dtb.img" "$OUT/"
fix_owner "$OUT"
log "U-Boot done: $OUT/SPL ($spl_size bytes), $OUT/u-boot-dtb.img"
