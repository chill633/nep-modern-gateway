/* SPDX-License-Identifier: GPL-2.0+ */
/*
 * Configuration for the ZLG M6G2C core module (i.MX6UL, 256 MiB DDR3L).
 */

#ifndef __ZLG_M6G2C_CONFIG_H
#define __ZLG_M6G2C_CONFIG_H

#include <asm/arch/imx-regs.h>
#include <linux/sizes.h>
#include <linux/stringify.h>
#include "mx6_common.h"
#include <asm/mach-imx/gpio.h>

#define CFG_MXC_UART_BASE		UART1_BASE

/* MMC: only uSDHC1 (SD card). uSDHC2's usual pads carry the NAND. */
#ifdef CONFIG_FSL_USDHC
#define CFG_SYS_FSL_ESDHC_ADDR		USDHC1_BASE_ADDR
#define CFG_SYS_FSL_USDHC_NUM		1
#endif

/*
 * 256 MiB of DRAM at 0x80000000. U-Boot relocates itself to the top, so keep
 * everything we load below 0x8c000000.
 *
 * SD card layout (see scripts/04-image.sh):
 *   1 KiB      SPL
 *   69 KiB     u-boot-dtb.img
 *   768 KiB    U-Boot environment (8 KiB)
 *   4 MiB      p1  ext4  /boot   (no journal; zImage, dtbs/, extlinux/extlinux.conf)
 *   ...        p2  ext4  /
 */
#define CFG_EXTRA_ENV_SETTINGS \
	"console=ttymxc0\0" \
	"bootm_size=0x10000000\0" \
	"kernel_addr_r=0x82000000\0" \
	"fdt_addr_r=0x88000000\0" \
	"ramdisk_addr_r=0x88100000\0" \
	"scriptaddr=0x87000000\0" \
	"pxefile_addr_r=0x87100000\0" \
	"fdtfile=imx6ul-zlg-m6g2c.dtb\0" \
	"mmcdev=0\0" \
	"mmcbootpart=1\0" \
	"bootscript=boot.scr\0" \
	"extlinux_boot=" \
		"sysboot mmc ${mmcdev}:${mmcbootpart} any ${scriptaddr} " \
		"/extlinux/extlinux.conf\0" \
	"script_boot=" \
		"if load mmc ${mmcdev}:${mmcbootpart} ${scriptaddr} " \
		"/${bootscript}; then source ${scriptaddr}; fi\0" \
	"rescue_boot=echo Fallback: direct zImage boot; " \
		"load mmc ${mmcdev}:${mmcbootpart} ${kernel_addr_r} /zImage && " \
		"load mmc ${mmcdev}:${mmcbootpart} ${fdt_addr_r} /dtbs/${fdtfile} && " \
		"setenv bootargs console=${console},${baudrate} " \
		"root=/dev/mmcblk0p2 rootfstype=ext4 rootwait rw && " \
		"bootz ${kernel_addr_r} - ${fdt_addr_r}\0"

/* Physical Memory Map */
#define PHYS_SDRAM			MMDC0_ARB_BASE_ADDR

#define CFG_SYS_SDRAM_BASE		PHYS_SDRAM
#define CFG_SYS_INIT_RAM_ADDR		IRAM_BASE_ADDR
#define CFG_SYS_INIT_RAM_SIZE		IRAM_SIZE

#endif /* __ZLG_M6G2C_CONFIG_H */
