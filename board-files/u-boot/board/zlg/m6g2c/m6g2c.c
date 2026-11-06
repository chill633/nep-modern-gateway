// SPDX-License-Identifier: GPL-2.0+
/*
 * ZLG M6G2C-128LI / M6G2C-256LI core module (NXP i.MX6UL, MCIMX6G2CVM05)
 *
 * Memory : ISSI IS43TR16128DL-125KBLI, DDR3L, 128M x 16 (2 Gbit = 256 MiB),
 *          8 banks, 14 row / 10 column address bits, 2 KiB page,
 *          DDR3-1600 11-11-11 part, run at 400 MHz (DDR800) by the i.MX6UL.
 * Storage: Spansion/Cypress S34ML02G2 (marking ML02G200BH100), 2 Gbit SLC
 *          NAND, 2048+128 byte pages, needs 4-bit/528-byte ECC.
 *
 * Boot   : SPL from the SD card on uSDHC1, U-Boot proper from raw sector 0x8a.
 *
 * Based on board/freescale/mx6ul_14x14_evk/mx6ul_14x14_evk.c
 */

#include <common.h>
#include <init.h>
#include <asm/arch/clock.h>
#include <asm/arch/crm_regs.h>
#include <asm/arch/imx-regs.h>
#include <asm/arch/iomux.h>
#include <asm/arch/mx6-pins.h>
#include <asm/arch/sys_proto.h>
#include <asm/global_data.h>
#include <asm/io.h>
#include <asm/mach-imx/iomux-v3.h>
#include <asm/sections.h>
#include <env.h>
#include <fsl_esdhc_imx.h>
#include <linux/sizes.h>
#include <mmc.h>

DECLARE_GLOBAL_DATA_PTR;

#define UART_PAD_CTRL  (PAD_CTL_PKE | PAD_CTL_PUE |		\
	PAD_CTL_PUS_100K_UP | PAD_CTL_SPEED_MED |		\
	PAD_CTL_DSE_40ohm   | PAD_CTL_SRE_FAST  | PAD_CTL_HYS)

int dram_init(void)
{
	gd->ram_size = imx_ddr_size();

	return 0;
}

/* Debug console: UART1 on the UART1_TX_DATA / UART1_RX_DATA pads. */
static iomux_v3_cfg_t const uart1_pads[] = {
	MX6_PAD_UART1_TX_DATA__UART1_DCE_TX | MUX_PAD_CTRL(UART_PAD_CTRL),
	MX6_PAD_UART1_RX_DATA__UART1_DCE_RX | MUX_PAD_CTRL(UART_PAD_CTRL),
};

static void setup_iomux_uart(void)
{
	imx_iomux_v3_setup_multiple_pads(uart1_pads, ARRAY_SIZE(uart1_pads));
}

/*
 * External hardware watchdog (the EPC-M6G2C uses a CAT82x supervisor on
 * GPIO5_IO00: ~1.6 s typical, about 1.1 s worst case; ZLG's ZL6300 designs
 * are similar). It runs from power-on and resets
 * the module unless its WDI input sees an edge in time, so it has to be fed
 * from the very first instructions of SPL until Linux's gpio-wdt driver
 * takes over.
 *
 * scripts/01-uboot.sh defines these from WDT_GPIO="<bank>:<pin>":
 *   M6G2C_WDT_PAD   MX6_PAD_..._GPIOb_IOnn pad macro
 *   M6G2C_WDT_BASE  GPIOb_BASE_ADDR
 *   M6G2C_WDT_PIN   nn
 *
 * Plain register access is used so the same code works in SPL (no driver
 * model, no GPIO driver) and in U-Boot proper.
 */
#ifdef M6G2C_WDT_PAD
#include <cyclic.h>

/* 0x17059, the value ZLG's EPC-M6G2C device tree uses for this pad */
#define WDT_PAD_CTRL	0x17059
#define GPIO_DR		0x00
#define GPIO_GDIR	0x04

static iomux_v3_cfg_t const wdt_pad = M6G2C_WDT_PAD | MUX_PAD_CTRL(WDT_PAD_CTRL);

static void m6g2c_wdt_setup(void)
{
	void __iomem *base = (void __iomem *)M6G2C_WDT_BASE;

	imx_iomux_v3_setup_pad(wdt_pad);
	setbits_le32(base + GPIO_GDIR, BIT(M6G2C_WDT_PIN));
}

/* Toggle WDI. The supervisor triggers on either edge. */
static void m6g2c_wdt_kick(void)
{
	void __iomem *base = (void __iomem *)M6G2C_WDT_BASE;
	u32 dr = readl(base + GPIO_DR);

	writel(dr ^ BIT(M6G2C_WDT_PIN), base + GPIO_DR);
}

#ifndef CONFIG_SPL_BUILD
static void m6g2c_wdt_cyclic(void *ctx)
{
	m6g2c_wdt_kick();
}

static void m6g2c_wdt_register(void)
{
	/* schedule() is called from udelay(), console polling, MMC/fs reads */
	if (!cyclic_register(m6g2c_wdt_cyclic, 200 * 1000, "m6g2c-wdt", NULL))
		puts("WDT: could not register the watchdog feeder!\n");
}

/* Last kick right before jumping to Linux: its gpio-wdt has ~1 s to probe. */
void board_quiesce_devices(void)
{
	m6g2c_wdt_kick();
}
#endif
#else
static inline void m6g2c_wdt_setup(void) {}
static inline void m6g2c_wdt_kick(void) {}
#ifndef CONFIG_SPL_BUILD
static inline void m6g2c_wdt_register(void) {}
#endif
#endif /* M6G2C_WDT_PAD */

int board_early_init_f(void)
{
	m6g2c_wdt_setup();
	m6g2c_wdt_kick();
	setup_iomux_uart();
#ifndef CONFIG_SPL_BUILD
	/* pre-relocation; board_f drops this list before relocating */
	m6g2c_wdt_register();
#endif

	return 0;
}

int board_init(void)
{
	/* Address of boot parameters */
	gd->bd->bi_boot_params = PHYS_SDRAM + 0x100;

	m6g2c_wdt_kick();
#ifndef CONFIG_SPL_BUILD
	m6g2c_wdt_register();	/* again, now in the relocated U-Boot */
#endif

	return 0;
}

int board_late_init(void)
{
#ifdef CONFIG_ENV_VARS_UBOOT_RUNTIME_CONFIG
	env_set("board_name", "M6G2C");
	env_set("board_rev", "256LI");
#endif
	return 0;
}

int checkboard(void)
{
	puts("Board: ZLG M6G2C (i.MX6UL)\n");

	return 0;
}

#ifdef CONFIG_SPL_BUILD
#include <linux/libfdt.h>
#include <spl.h>
#include <asm/arch/mx6-ddr.h>

/*
 * ---------------------------------------------------------------------------
 * SD card (SPL only; U-Boot proper uses the device tree)
 * The card is on uSDHC1 (SD1_* pads). The NAND occupies the NAND_* pads, so
 * uSDHC2 cannot be on its usual pads on this module.
 * ---------------------------------------------------------------------------
 */
#define USDHC_PAD_CTRL (PAD_CTL_PKE | PAD_CTL_PUE |		\
	PAD_CTL_PUS_22K_UP  | PAD_CTL_SPEED_LOW |		\
	PAD_CTL_DSE_80ohm   | PAD_CTL_SRE_FAST  | PAD_CTL_HYS)

static iomux_v3_cfg_t const usdhc1_pads[] = {
	MX6_PAD_SD1_CLK__USDHC1_CLK     | MUX_PAD_CTRL(USDHC_PAD_CTRL),
	MX6_PAD_SD1_CMD__USDHC1_CMD     | MUX_PAD_CTRL(USDHC_PAD_CTRL),
	MX6_PAD_SD1_DATA0__USDHC1_DATA0 | MUX_PAD_CTRL(USDHC_PAD_CTRL),
	MX6_PAD_SD1_DATA1__USDHC1_DATA1 | MUX_PAD_CTRL(USDHC_PAD_CTRL),
	MX6_PAD_SD1_DATA2__USDHC1_DATA2 | MUX_PAD_CTRL(USDHC_PAD_CTRL),
	MX6_PAD_SD1_DATA3__USDHC1_DATA3 | MUX_PAD_CTRL(USDHC_PAD_CTRL),
};

static struct fsl_esdhc_cfg usdhc_cfg[1] = {
	{USDHC1_BASE_ADDR, 0, 4},
};

int board_mmc_getcd(struct mmc *mmc)
{
	/* We booted from this card, so it is present. */
	return 1;
}

int board_mmc_init(struct bd_info *bis)
{
	imx_iomux_v3_setup_multiple_pads(usdhc1_pads, ARRAY_SIZE(usdhc1_pads));
	usdhc_cfg[0].sdhc_clk = mxc_get_clock(MXC_ESDHC_CLK);
	return fsl_esdhc_initialize(bis, &usdhc_cfg[0]);
}

/*
 * ---------------------------------------------------------------------------
 * DDR3L: ISSI IS43TR16128DL-125KBLI
 * ---------------------------------------------------------------------------
 */
static struct mx6ul_iomux_grp_regs mx6_grp_ioregs = {
	.grp_addds = 0x00000030,
	.grp_ddrmode_ctl = 0x00020000,
	.grp_b0ds = 0x00000030,
	.grp_ctlds = 0x00000030,
	.grp_b1ds = 0x00000030,
	.grp_ddrpke = 0x00000000,
	.grp_ddrmode = 0x00020000,
	.grp_ddr_type = 0x000c0000,		/* DDR3 / DDR3L */
};

static struct mx6ul_iomux_ddr_regs mx6_ddr_ioregs = {
	.dram_dqm0 = 0x00000030,
	.dram_dqm1 = 0x00000030,
	.dram_ras = 0x00000030,
	.dram_cas = 0x00000030,
	.dram_odt0 = 0x00000030,
	.dram_odt1 = 0x00000030,
	.dram_sdba2 = 0x00000000,
	.dram_sdclk_0 = 0x00000030,
	.dram_sdqs0 = 0x00000030,
	.dram_sdqs1 = 0x00000030,
	.dram_reset = 0x00000030,
};

/*
 * Write-levelling / DQS-gating / read- and write-delay calibration.
 *
 * These depend on the PCB layout, not on the memory chip. The defaults are
 * the NXP i.MX6UL EVK values, which work on most i.MX6UL modules with a
 * single x16 DDR3L device, but for production use replace them with the
 * values from ZLG's own boot loader (tools/imx-dcd-extract.py prints them)
 * or from a run of the NXP DDR Stress Test tool on this board.
 */
#ifndef M6G2C_MPWLDECTRL0
#define M6G2C_MPWLDECTRL0	0x00000000
#endif
#ifndef M6G2C_MPDGCTRL0
#define M6G2C_MPDGCTRL0		0x41570155
#endif
#ifndef M6G2C_MPRDDLCTL
#define M6G2C_MPRDDLCTL		0x4040474A
#endif
#ifndef M6G2C_MPWRDLCTL
#define M6G2C_MPWRDLCTL		0x40405550
#endif

static struct mx6_mmdc_calibration mx6_mmcd_calib = {
	.p0_mpwldectrl0 = M6G2C_MPWLDECTRL0,
	.p0_mpdgctrl0 = M6G2C_MPDGCTRL0,
	.p0_mprddlctl = M6G2C_MPRDDLCTL,
	.p0_mpwrdlctl = M6G2C_MPWRDLCTL,
};

struct mx6_ddr_sysinfo ddr_sysinfo = {
	.dsize = 0,		/* 16-bit bus */
	.cs_density = 18,	/* MDASP = 4 * 18 - 1 = 0x47 -> 256 MiB on CS0 */
	.ncs = 1,
	.cs1_mirror = 0,
	.rtt_wr = 2,		/* RTT_WR = RZQ/2 */
	.rtt_nom = 1,		/* RTT_Nom = RZQ/4 */
	.walat = 0,		/* Write additional latency */
	.ralat = 5,		/* Read additional latency */
	.mif3_mode = 3,		/* Command prediction working mode */
	.bi_on = 1,		/* Bank interleaving enabled */
	.sde_to_rst = 0x10,	/* 14 cycles, 200us (JEDEC default) */
	.rst_to_cke = 0x23,	/* 33 cycles, 500us (JEDEC default) */
	.ddr_type = DDR_TYPE_DDR3,
	.refsel = 0,		/* Refresh cycles at 64KHz */
	.refr = 1,		/* 2 refresh commands per refresh cycle */
};

/*
 * IS43TR16128DL-125K (DDR3-1600K, 11-11-11):
 *   tRCD = tRP = 13.75 ns, tRC = 48.75 ns, tRAS(min) = 35 ns.
 * mx6_dram_cfg() derives tRFC (160 ns for 2 Gbit), tXS, tFAW etc. from
 * .density, so only the values below are chip specific.
 */
static struct mx6_ddr3_cfg mem_ddr = {
	.mem_speed = 800,
	.density = 2,		/* Gbit per device */
	.width = 16,
	.banks = 8,
	.rowaddr = 14,		/* A0-A13 */
	.coladdr = 10,		/* A0-A9  */
	.pagesz = 2,		/* KiB */
	.trcd = 1375,		/* 10 ps units */
	.trcmin = 4875,
	.trasmin = 3500,
};

static void ccgr_init(void)
{
	struct mxc_ccm_reg *ccm = (struct mxc_ccm_reg *)CCM_BASE_ADDR;

	writel(0xFFFFFFFF, &ccm->CCGR0);
	writel(0xFFFFFFFF, &ccm->CCGR1);
	writel(0xFFFFFFFF, &ccm->CCGR2);
	writel(0xFFFFFFFF, &ccm->CCGR3);
	writel(0xFFFFFFFF, &ccm->CCGR4);
	writel(0xFFFFFFFF, &ccm->CCGR5);
	writel(0xFFFFFFFF, &ccm->CCGR6);
	writel(0xFFFFFFFF, &ccm->CCGR7);
}

static void spl_dram_init(void)
{
	mx6ul_dram_iocfg(mem_ddr.width, &mx6_ddr_ioregs, &mx6_grp_ioregs);
	mx6_dram_cfg(&ddr_sysinfo, &mx6_mmcd_calib, &mem_ddr);
}

#ifdef M6G2C_WDT_PAD
/* SPL loads U-Boot proper from the card: one more kick before handing over. */
void spl_board_prepare_for_boot(void)
{
	m6g2c_wdt_kick();
}
#endif

void board_init_f(ulong dummy)
{
	ccgr_init();

	/* Feed the external watchdog as early as possible (clocks are on). */
	m6g2c_wdt_setup();
	m6g2c_wdt_kick();

	/* setup AIPS and disable watchdog */
	arch_cpu_init();

	/* iomux for the console */
	board_early_init_f();

	/* setup GP timer */
	timer_init();

	/* UART clocks enabled and gd valid - init serial console */
	preloader_console_init();

	/* DDR initialization */
	m6g2c_wdt_kick();
	spl_dram_init();
	m6g2c_wdt_kick();

	/* Clear the BSS. */
	memset(__bss_start, 0, __bss_end - __bss_start);

	/* load/boot image from boot device */
	board_init_r(NULL, 0);
}
#endif /* CONFIG_SPL_BUILD */
