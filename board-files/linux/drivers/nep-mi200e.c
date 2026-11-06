// SPDX-License-Identifier: GPL-2.0
/*
 * NEP MI200E power-line modem driver for the ZLG EPC-M6G2C gateway.
 *
 * A re-implementation, for current kernels, of NEP's "plc_mi200e_o" module
 * (version 6.0.0-3phases, built for ZLG's Linux 4.1.15). The behaviour was
 * reconstructed from that GPL-licensed module so that NEP's unmodified
 * gateway-plc program keeps working:
 *
 *   /dev/mi200e   misc device
 *   write()       always copies 130 bytes: payload in [0..127], payload
 *                 length in [129]. Queues one frame and waits up to 4 s for
 *                 it to go out. Returns the payload length, or 0 on timeout.
 *   read()        never blocks. Copies 130 bytes: payload in [0..127], its
 *                 length in [128]. Returns that length, or 0 if no frame.
 *   ioctl 0x01    status: counters, returns 1 if idle, -1 if sending
 *   ioctl 0x81    set the 16-bit vendor/network id (arg)
 *   ioctl 0x82..4 accepted and ignored (frequency, specode, baud rate)
 *   ioctl 0x85    select the phase / modem: 0xa, 0xb, 0xc
 *   ioctl 0x86    power-cycle and re-initialise the modems
 *
 * Hardware: three MI200E modems, one per mains phase, share ECSPI1. Each
 * has its own chip select GPIO. The driver drives the ECSPI registers
 * directly, without the SPI core, as NEP's did, so ECSPI1 must not be used
 * by spi-imx at the same time. A state machine, clocked by EPIT2 about
 * every millisecond, moves one 16-bit word per tick to or from the selected
 * modem.
 *
 * All GPIOs are driven with raw levels, exactly as NEP's driver did, so
 * their polarity flags in the device tree don't matter.
 */

#include <linux/clk.h>
#include <linux/delay.h>
#include <linux/gpio/consumer.h>
#include <linux/interrupt.h>
#include <linux/io.h>
#include <linux/iopoll.h>
#include <linux/miscdevice.h>
#include <linux/module.h>
#include <linux/mod_devicetable.h>
#include <linux/mutex.h>
#include <linux/platform_device.h>
#include <linux/poll.h>
#include <linux/spinlock.h>
#include <linux/uaccess.h>
#include <linux/wait.h>

#define DRV_NAME		"mi200e"

/* ECSPI registers */
#define ECSPI_RXDATA		0x00
#define ECSPI_TXDATA		0x04
#define ECSPI_CONREG		0x08
#define ECSPI_CONFIGREG		0x0c
#define ECSPI_INTREG		0x10
#define ECSPI_DMAREG		0x14
#define ECSPI_STATREG		0x18
#define ECSPI_PERIODREG		0x1c

#define ECSPI_CON_EN		BIT(0)
#define ECSPI_CON_XCH		BIT(2)
/* 8-bit bursts, channel 0, all channels master; dividers added at run time */
#define ECSPI_CON_BASE		0x007000f0
#define ECSPI_STAT_RR		BIT(3)
#define ECSPI_STAT_TC		BIT(7)
#define ECSPI_STAT_CLEAR	0xc0	/* TC | RO */
#define ECSPI_CONFIG_VAL	0x100
#define ECSPI_PERIOD_VAL	0x8000

/* NEP's dividers: 60 MHz / 15 / 8 = 500 kHz */
#define MI200E_SPI_HZ		500000
#define MI200E_CON_NEP		0x0070e3f1

/* EPIT registers */
#define EPIT_CR			0x00
#define EPIT_SR			0x04
#define EPIT_LR			0x08
#define EPIT_CMPR		0x0c
/* RLD | ENMOD, CLKSRC = peripheral clock, IOVW, WAITEN, STOPEN */
#define EPIT_CR_VAL		0x012a000a
#define EPIT_CR_EN_OCIEN	0x5

/* NEP: 0x5d01 + 1 counts of the 24 MHz peripheral clock */
static unsigned int tick_ns = 992083;
module_param(tick_ns, uint, 0444);
MODULE_PARM_DESC(tick_ns, "State machine period in ns (NEP: 992083)");

/* MI200E registers (write address; reads set bit 7) */
#define MI_REG_MODE		0x01
#define MI_REG_STATUS		0x02
#define MI_REG_RXCFG		0x03
#define MI_CMD_TXWORD		0x04
#define MI_CMD_RXWORD		0x84
#define MI_REG_CRC_H		0xa8
#define MI_REG_CRC_L		0xa9

#define MI_MODE_RX		0x8d
#define MI_MODE_TX		0x0d

#define MI_RING			10
#define MI_FRAME		130	/* user buffer size */
#define MI_PAYLOAD		128

/* ioctls, plain numbers as in NEP's driver */
#define MI_IOC_STATUS		0x01
#define MI_IOC_VID		0x81
#define MI_IOC_FREQ		0x82
#define MI_IOC_SPECODE		0x83
#define MI_IOC_BDRATE		0x84
#define MI_IOC_CONF_3PCS	0x85
#define MI_IOC_CONF_RESET	0x86

/* tx_state values */
#define TX_PENDING		1
#define TX_SENDING		2
#define TX_IDLE			6

/* state machine */
enum {
	ST_IDLE = 1,
	ST_TX_PREAMBLE = 2,
	ST_TX_LENGTH = 3,
	ST_TX_VID = 4,
	ST_TX_DATA = 5,
	ST_TX_CRC = 6,
	ST_TX_END = 7,
	ST_RX_WAIT = 16,
	ST_RX_VID = 18,
	ST_RX_DATA = 19,
	ST_RX_CRC = 20,
};

#define RX_TIMEOUT_TICKS	100

struct mi200e_frame {
	u8 data[MI_PAYLOAD];
	u8 len;		/* tx: payload + 4; rx: words * 2 */
};

struct mi200e {
	struct device *dev;
	void __iomem *spi;
	void __iomem *epit;
	struct clk *clk_ipg, *clk_per, *clk_epit;
	u32 conreg;
	u32 epit_lr;
	int irq;

	struct gpio_desc *power, *reset, *led, *ss;
	struct gpio_desc *phase_cs[3];

	struct miscdevice misc;
	struct mutex io_lock;		/* user calls */
	spinlock_t lock;		/* state machine vs. user calls */
	wait_queue_head_t waitq;

	unsigned int phase;		/* selected modem, 0..2 */
	u16 vid;

	struct mi200e_frame rx[MI_RING], tx[MI_RING];
	unsigned int rx_wr, rx_rd, tx_wr, tx_rd;
	u8 tx_state;
	bool tx_done;

	/* counters */
	u32 tx_frames, rx_frames, crc_errors, id_errors, timeouts;

	/* state machine */
	int state;
	u8 status_reg;
	u8 rec_h, rec_l;
	u16 overtime;
	u8 should_send, pkg_id;
	u8 *tx_ptr;
	u8 sent;
	u8 pkg_len;
	u8 rx_cnt;
	u8 *rx_ptr;
	u8 rx_buf[MI_FRAME];
	bool rx_flag, err_flag;
	u8 dbg_reg, dbg_val;

	/* 130-byte buffers exchanged with user space */
	u8 ubuf_rx[MI_FRAME];
	u8 ubuf_tx[MI_FRAME];
};

/* ---- GPIOs --------------------------------------------------------------- */

static void mi_set(struct gpio_desc *d, int v)
{
	gpiod_set_raw_value(d, v);
}

/* Chip select, active low; one modem per phase. */
static void mi_cs(struct mi200e *mi, bool select)
{
	unsigned int sel = mi->phase < 3 ? mi->phase : 0;
	int i;

	for (i = 0; i < 3; i++)
		if (!select || i != sel)
			mi_set(mi->phase_cs[i], 1);
	if (select)
		mi_set(mi->phase_cs[sel], 0);
}

/* ---- ECSPI ------------------------------------------------------------------ */

static void mi_spi_setup(struct mi200e *mi)
{
	writel(mi->conreg, mi->spi + ECSPI_CONREG);
	writel(ECSPI_CONFIG_VAL, mi->spi + ECSPI_CONFIGREG);
	writel(ECSPI_STAT_CLEAR, mi->spi + ECSPI_STATREG);
}

static bool mi_spi_drain(struct mi200e *mi, unsigned int tries, unsigned int delay_us)
{
	while (readl(mi->spi + ECSPI_STATREG) & ECSPI_STAT_RR) {
		if (!tries--)
			return false;
		udelay(delay_us);
		readl(mi->spi + ECSPI_RXDATA);
	}
	return true;
}

static bool mi_spi_xfer(struct mi200e *mi)
{
	u32 stat;

	writel(readl(mi->spi + ECSPI_CONREG) | ECSPI_CON_EN | ECSPI_CON_XCH,
	       mi->spi + ECSPI_CONREG);
	/* NEP allowed ~51 us; a 3-byte burst takes 48 us at 500 kHz */
	if (readl_poll_timeout_atomic(mi->spi + ECSPI_STATREG, stat,
				      stat & ECSPI_STAT_TC, 1, 200))
		return false;
	writel(ECSPI_STAT_CLEAR, mi->spi + ECSPI_STATREG);
	return true;
}

static u8 mi_read_reg(struct mi200e *mi, u8 reg)
{
	u8 val = 0;

	mi_cs(mi, true);
	mi_spi_setup(mi);
	if (!mi_spi_drain(mi, 100, 1)) {
		dev_warn_ratelimited(mi->dev, "read_reg %#x: RX FIFO stuck\n", reg);
		goto out;
	}
	writel(reg | 0x80, mi->spi + ECSPI_TXDATA);
	writel(0, mi->spi + ECSPI_TXDATA);
	if (!mi_spi_xfer(mi)) {
		dev_warn_ratelimited(mi->dev, "read_reg %#x: SPI timeout\n", reg);
		goto out;
	}
	readl(mi->spi + ECSPI_RXDATA);
	val = readl(mi->spi + ECSPI_RXDATA);
out:
	mi_cs(mi, false);
	return val;
}

static bool mi_write_reg(struct mi200e *mi, u8 reg, u8 val)
{
	bool ok;

	mi_cs(mi, true);
	mi_spi_setup(mi);
	writel(reg, mi->spi + ECSPI_TXDATA);
	writel(val, mi->spi + ECSPI_TXDATA);
	ok = mi_spi_xfer(mi);
	if (!ok)
		dev_warn_ratelimited(mi->dev, "write_reg %#x: SPI timeout\n", reg);
	mi_cs(mi, false);
	return ok;
}

static bool mi_read_word(struct mi200e *mi)
{
	bool ok = false;

	mi_cs(mi, true);
	mi_spi_setup(mi);
	mi->rec_h = 0;
	mi->rec_l = 0;
	if (!mi_spi_drain(mi, 100, 1)) {
		dev_warn_ratelimited(mi->dev, "read_word: RX FIFO stuck\n");
		goto out;
	}
	writel(MI_CMD_RXWORD, mi->spi + ECSPI_TXDATA);
	writel(0, mi->spi + ECSPI_TXDATA);
	writel(0, mi->spi + ECSPI_TXDATA);
	if (!mi_spi_xfer(mi))
		goto out;
	readl(mi->spi + ECSPI_RXDATA);
	mi->rec_h = readl(mi->spi + ECSPI_RXDATA);
	mi->rec_l = readl(mi->spi + ECSPI_RXDATA);
	ok = true;
out:
	mi_cs(mi, false);
	return ok;
}

static bool mi_send_word(struct mi200e *mi, u8 hi, u8 lo)
{
	bool ok;

	mi_write_reg(mi, MI_REG_STATUS, mi->status_reg & 0x7f);
	udelay(1);
	mi_cs(mi, true);
	mi_spi_setup(mi);
	writel(MI_CMD_TXWORD, mi->spi + ECSPI_TXDATA);
	writel(hi, mi->spi + ECSPI_TXDATA);
	writel(lo, mi->spi + ECSPI_TXDATA);
	ok = mi_spi_xfer(mi);
	if (!ok)
		dev_warn_ratelimited(mi->dev, "send_word: SPI timeout\n");
	mi_cs(mi, false);
	return ok;
}

/* ---- modem set-up ------------------------------------------------------------ */

static void mi_self_check(struct mi200e *mi)
{
	u8 v81, v87a, v87b, v87c, crc_h, crc_l;
	int errors;

	v81 = mi_read_reg(mi, 0x81);
	v87a = mi_read_reg(mi, 0x87);
	mi_write_reg(mi, 0x07, 0x00);
	v87b = mi_read_reg(mi, 0x87);
	mi_write_reg(mi, 0x07, 0x07);
	v87c = mi_read_reg(mi, 0x87);
	crc_h = mi_read_reg(mi, MI_REG_CRC_H);
	crc_l = mi_read_reg(mi, MI_REG_CRC_L);

	errors = (v81 != 0xff) + (v87a != 0x17) + (v87b != 0x00) +
		 (v87c != 0x07) + (crc_h != 0) + (crc_l != 0);
	/* the values NEP's check expects: ff 17 00 07 00 00 */
	dev_info(mi->dev,
		 "modem %u: self check %s (%d): %02x %02x %02x %02x %02x %02x\n",
		 mi->phase, errors ? "failed" : "ok", errors,
		 v81, v87a, v87b, v87c, crc_h, crc_l);
}

static const u8 mi_init_regs[][2] = {
	{ 0x01, 0x8d }, { 0x07, 0x17 }, { 0x0a, 0x00 }, { 0x0b, 0x00 },
	{ 0x0c, 0x58 }, { 0x0d, 0x0a }, { 0x0e, 0xff }, { 0x0f, 0x15 },
	{ 0x10, 0x88 }, { 0x11, 0x88 }, { 0x12, 0x88 }, { 0x13, 0xa3 },
	{ 0xb0, 0x00 },
};

static void mi_init_modem(struct mi200e *mi)
{
	int i;

	mi_self_check(mi);
	for (i = 0; i < ARRAY_SIZE(mi_init_regs); i++)
		mi_write_reg(mi, mi_init_regs[i][0], mi_init_regs[i][1]);
	usleep_range(10000, 12000);
}

/*
 * Power-cycle the modems and set them up, as NEP's probe and reset ioctl
 * did. Process context, with the EPIT interrupt off.
 */
static void mi_hw_init(struct mi200e *mi, bool drain)
{
	unsigned long timeout;

	mi->phase = 0;
	mi_cs(mi, false);

	mi_set(mi->power, 0);
	msleep(1000);
	mi_set(mi->power, 1);
	mi_set(mi->reset, 0);
	mi_set(mi->led, 0);

	writel(0, mi->spi + ECSPI_CONREG);
	msleep(100);
	writel(ECSPI_CON_EN, mi->spi + ECSPI_CONREG);
	msleep(100);

	if (drain) {
		timeout = jiffies + msecs_to_jiffies(1000);
		while (readl(mi->spi + ECSPI_STATREG) & ECSPI_STAT_RR) {
			if (time_after(jiffies, timeout)) {
				dev_warn(mi->dev, "SPI RX FIFO does not empty\n");
				break;
			}
			readl(mi->spi + ECSPI_RXDATA);
			usleep_range(1000, 1200);
		}
	}

	writel(mi->conreg, mi->spi + ECSPI_CONREG);
	writel(ECSPI_CONFIG_VAL, mi->spi + ECSPI_CONFIGREG);
	writel(0, mi->spi + ECSPI_INTREG);
	writel(0, mi->spi + ECSPI_DMAREG);
	writel(ECSPI_PERIOD_VAL, mi->spi + ECSPI_PERIODREG);
	writel(ECSPI_STAT_CLEAR, mi->spi + ECSPI_STATREG);
	mi_set(mi->ss, 0);
	msleep(50);

	dev_dbg(mi->dev, "resetting modems\n");
	mi_set(mi->reset, 0);
	msleep(1100);
	mi_set(mi->reset, 1);
	msleep(100);

	for (mi->phase = 0; mi->phase < 3; mi->phase++)
		mi_init_modem(mi);
	mi->phase = 0;
}

/* ---- EPIT ------------------------------------------------------------------- */

static void mi_epit_start(struct mi200e *mi)
{
	writel(1, mi->epit + EPIT_SR);
	writel(readl(mi->epit + EPIT_CR) | EPIT_CR_EN_OCIEN, mi->epit + EPIT_CR);
}

static void mi_epit_stop(struct mi200e *mi)
{
	writel(readl(mi->epit + EPIT_CR) & ~EPIT_CR_EN_OCIEN, mi->epit + EPIT_CR);
}

static void mi_epit_setup(struct mi200e *mi)
{
	writel(0, mi->epit + EPIT_CR);
	writel(0, mi->epit + EPIT_CMPR);
	writel(EPIT_CR_VAL, mi->epit + EPIT_CR);
	writel(0, mi->epit + EPIT_LR);
	writel(mi->epit_lr, mi->epit + EPIT_LR);
}

/* ---- state machine (EPIT interrupt) ----------------------------------------- */

/* Bit 7 of the status register: the modem takes the next TX word. */
static bool mi_tx_ready(struct mi200e *mi, u8 mask)
{
	u8 s = mi_read_reg(mi, MI_REG_STATUS);

	if (!(s & 0x80))
		return false;
	mi_write_reg(mi, MI_REG_STATUS, s & mask);
	mi->status_reg = s;
	return true;
}

static void mi_rx_abort(struct mi200e *mi, const char *what)
{
	u8 v;

	mi->state = ST_IDLE;
	mi->err_flag = true;
	v = mi_read_reg(mi, MI_REG_STATUS);
	mi_write_reg(mi, MI_REG_STATUS, (v & 0x75) | 0x80);
	mi_write_reg(mi, MI_REG_MODE, MI_MODE_RX);
	if (what) {
		mi->overtime = 1;
		mi->timeouts++;
		dev_dbg(mi->dev, "%s timeout\n", what);
	}
}

/* (status & 0x30) == 0x30: receiving; 0x40: a word is ready */
static int mi_rx_status(struct mi200e *mi)
{
	u8 s = mi_read_reg(mi, MI_REG_STATUS);

	mi->status_reg = s;
	if ((s & 0x30) == 0x30)
		return (s & 0x40) ? 1 : 0;
	return -1;
}

static bool mi_rx_timed_out(struct mi200e *mi)
{
	return ++mi->overtime > RX_TIMEOUT_TICKS;
}

static void mi_state_machine(struct mi200e *mi)
{
	struct mi200e_frame *f;
	int r, i;
	u8 s, v;

	switch (mi->state) {
	case ST_IDLE:
		mi_set(mi->led, 0);
		if (mi->tx_state == TX_PENDING) {
			mi->tx_state = TX_SENDING;
			mi_write_reg(mi, MI_REG_MODE, MI_MODE_TX);
			mi->state = ST_TX_PREAMBLE;
			goto tx_preamble;
		}
		mi_write_reg(mi, MI_REG_MODE, MI_MODE_RX);
		s = mi_read_reg(mi, MI_REG_STATUS);
		if ((s & 0x30) == 0x30) {
			mi->rx_flag = false;
			mi->err_flag = false;
			mi->overtime = 0;
			mi->state = ST_RX_WAIT;
		}
		break;

	case ST_TX_PREAMBLE:
tx_preamble:
		if (!mi_tx_ready(mi, 0x7f))
			break;
		mi_write_reg(mi, MI_REG_MODE, MI_MODE_TX);
		mi_send_word(mi, 0xff, 0xff);
		mi->should_send = mi->tx[mi->tx_rd].len;
		mi->pkg_id = (mi->should_send >> 1) & 0x3f;
		mi->state = ST_TX_LENGTH;
		break;

	case ST_TX_LENGTH:
		if (!mi_tx_ready(mi, 0x7d))
			break;
		mi_send_word(mi, 0x1a, mi->pkg_id);
		mi->state = ST_TX_VID;
		break;

	case ST_TX_VID:
		if (!mi_tx_ready(mi, 0x7f))
			break;
		mi_write_reg(mi, MI_REG_MODE, 0x01);
		mi->sent = 0;
		mi->tx_ptr = mi->tx[mi->tx_rd].data;
		mi_send_word(mi, mi->vid >> 8, mi->vid & 0xff);
		mi->sent += 2;
		mi->state = ST_TX_DATA;
		mi_set(mi->led, 1);
		break;

	case ST_TX_DATA:
		if (!mi_tx_ready(mi, 0x7f))
			break;
		mi_send_word(mi, mi->tx_ptr[0], mi->tx_ptr[1]);
		mi->sent += 2;
		mi->tx_ptr += 2;
		if ((int)mi->sent >= (int)mi->should_send - 2)
			mi->state = ST_TX_CRC;
		break;

	case ST_TX_CRC:
		if (!mi_tx_ready(mi, 0x7f))
			break;
		{
			u8 h = mi_read_reg(mi, MI_REG_CRC_H);
			u8 l = mi_read_reg(mi, MI_REG_CRC_L);

			mi_send_word(mi, h, l);
		}
		mi->state = ST_TX_END;
		break;

	case ST_TX_END:
		s = mi_read_reg(mi, MI_REG_STATUS);
		mi->status_reg = s;
		if (!(s & 0x80))
			break;
		mi->state = ST_IDLE;
		mi_write_reg(mi, MI_REG_MODE, MI_MODE_RX);
		mi_write_reg(mi, MI_REG_STATUS, (mi->status_reg & 0x35) | 0x80);
		mi->tx_frames++;
		mi->tx_rd = (mi->tx_rd + 1) % MI_RING;
		mi->tx_done = true;
		mi->tx_state = TX_IDLE;
		wake_up(&mi->waitq);
		break;

	case ST_RX_WAIT:
		v = mi_read_reg(mi, MI_REG_RXCFG);
		s = mi_read_reg(mi, MI_REG_STATUS);
		mi->status_reg = s;
		if (v) {
			mi_write_reg(mi, MI_REG_RXCFG, 0);
			mi_write_reg(mi, MI_REG_STATUS, (mi->status_reg & 0x3d) | 0x82);
			mi->pkg_len = v & 0x3f;
			mi->state = ST_RX_VID;
			mi->overtime = 0;
			break;
		}
		if ((s & 0x30) == 0x30 || !mi_rx_timed_out(mi))
			break;
		/* clear the error flags, back to idle */
		v = mi_read_reg(mi, MI_REG_STATUS);
		mi_write_reg(mi, MI_REG_STATUS, v & 0xf5);
		mi->overtime = 1;
		mi->state = ST_IDLE;
		mi->timeouts++;
		dev_dbg(mi->dev, "RX_0 timeout\n");
		break;

	case ST_RX_VID:
		r = mi_rx_status(mi);
		if (r < 0) {
			if (mi_rx_timed_out(mi))
				mi_rx_abort(mi, "RX_1");
			break;
		}
		if (!r)
			break;
		mi_read_word(mi);
		mi->overtime = 0;
		mi_write_reg(mi, MI_REG_STATUS, (mi->status_reg & 0x3d) | 0x82);
		if (mi->rec_h == (mi->vid >> 8) && mi->rec_l == (mi->vid & 0xff)) {
			mi->state = ST_RX_DATA;
			mi->rx_cnt = 1;
			mi->rx_ptr = mi->rx_buf;
			mi_set(mi->led, 1);
		} else {
			mi->id_errors++;
			dev_dbg(mi->dev, "RX id %02X%02X != %04X\n",
				mi->rec_h, mi->rec_l, mi->vid);
			mi_rx_abort(mi, NULL);
		}
		break;

	case ST_RX_DATA:
		r = mi_rx_status(mi);
		if (r < 0) {
			if (mi_rx_timed_out(mi))
				mi_rx_abort(mi, "RX_2");
			break;
		}
		if (!r)
			break;
		mi_read_word(mi);
		mi_write_reg(mi, MI_REG_STATUS, (mi->status_reg & 0x3d) | 0x82);
		if (mi->rx_ptr + 2 <= mi->rx_buf + sizeof(mi->rx_buf)) {
			mi->rx_ptr[0] = mi->rec_h;
			mi->rx_ptr[1] = mi->rec_l;
			mi->rx_ptr += 2;
		}
		mi->rx_cnt++;
		if ((int)mi->rx_cnt >= (int)mi->pkg_len - 1)
			mi->state = ST_RX_CRC;
		break;

	case ST_RX_CRC:
		r = mi_rx_status(mi);
		if (r < 0) {
			if (mi_rx_timed_out(mi))
				mi_rx_abort(mi, "RX_3");
			break;
		}
		if (!r)
			break;
		mi_read_word(mi);
		if (mi->rx_ptr + 2 <= mi->rx_buf + sizeof(mi->rx_buf)) {
			mi->rx_ptr[0] = mi->rec_h;
			mi->rx_ptr[1] = mi->rec_l;
		}
		mi->rx_flag = true;
		v = mi_read_reg(mi, MI_REG_STATUS);
		if (v & 0x02) {
			mi->rx_frames++;
		} else {
			mi->err_flag = true;
			mi->crc_errors++;
			dev_dbg(mi->dev, "RX CRC error (%#x)\n", v);
		}
		/* NEP queued the frame either way; gateway-plc checks it */
		f = &mi->rx[mi->rx_wr];
		for (i = 0; i < mi->pkg_len * 2 && i < MI_PAYLOAD; i++)
			f->data[i] = mi->rx_buf[i];
		f->len = mi->pkg_len * 2;
		mi->rx_wr = (mi->rx_wr + 1) % MI_RING;
		mi->state = ST_IDLE;
		mi_write_reg(mi, MI_REG_MODE, MI_MODE_RX);
		mi_write_reg(mi, MI_REG_STATUS, (mi->status_reg & 0x35) | 0x80);
		wake_up(&mi->waitq);
		break;

	default:
		break;
	}
}

static irqreturn_t mi_irq(int irq, void *data)
{
	struct mi200e *mi = data;

	writel(1, mi->epit + EPIT_SR);
	spin_lock(&mi->lock);
	mi_state_machine(mi);
	spin_unlock(&mi->lock);
	return IRQ_HANDLED;
}

/* ---- character device ------------------------------------------------------ */

/* the misc core points private_data at our miscdevice */
static struct mi200e *to_mi(struct file *file)
{
	return container_of(file->private_data, struct mi200e, misc);
}

static ssize_t mi_read(struct file *file, char __user *buf, size_t count,
		       loff_t *ppos)
{
	struct mi200e *mi = to_mi(file);
	struct mi200e_frame *f;
	unsigned long flags;
	ssize_t ret = 0;
	int n;

	mutex_lock(&mi->io_lock);
	spin_lock_irqsave(&mi->lock, flags);
	if (mi->rx_rd == mi->rx_wr) {
		spin_unlock_irqrestore(&mi->lock, flags);
		goto out;
	}
	f = &mi->rx[mi->rx_rd];
	n = f->len >= 4 ? f->len - 4 : 0;
	if (n > MI_PAYLOAD)
		n = MI_PAYLOAD;
	memcpy(mi->ubuf_rx, f->data, n);
	mi->ubuf_rx[MI_PAYLOAD] = n;
	spin_unlock_irqrestore(&mi->lock, flags);

	/* NEP's driver always copies 130 bytes, whatever count says */
	if (copy_to_user(buf, mi->ubuf_rx, MI_FRAME)) {
		ret = -EFAULT;
		goto out;
	}
	spin_lock_irqsave(&mi->lock, flags);
	mi->rx_rd = (mi->rx_rd + 1) % MI_RING;
	spin_unlock_irqrestore(&mi->lock, flags);
	ret = n;
out:
	mutex_unlock(&mi->io_lock);
	return ret;
}

static ssize_t mi_write(struct file *file, const char __user *buf,
			size_t count, loff_t *ppos)
{
	struct mi200e *mi = to_mi(file);
	struct mi200e_frame *f;
	unsigned long flags;
	ssize_t ret;
	u8 len;

	mutex_lock(&mi->io_lock);
	/* NEP's driver always copies 130 bytes; the length is in [129] */
	if (copy_from_user(mi->ubuf_tx, buf, MI_FRAME)) {
		ret = -EFAULT;
		goto out;
	}
	len = min_t(u8, mi->ubuf_tx[MI_FRAME - 1], MI_PAYLOAD);

	spin_lock_irqsave(&mi->lock, flags);
	f = &mi->tx[mi->tx_wr];
	memcpy(f->data, mi->ubuf_tx, len);
	f->len = len + 4;
	mi->tx_wr = (mi->tx_wr + 1) % MI_RING;
	mi->tx_done = false;
	mi->tx_state = TX_PENDING;
	spin_unlock_irqrestore(&mi->lock, flags);

	if (wait_event_timeout(mi->waitq, READ_ONCE(mi->tx_done),
			       msecs_to_jiffies(4000))) {
		ret = len;
	} else {
		dev_dbg(mi->dev, "write timeout\n");
		ret = 0;
	}
out:
	mutex_unlock(&mi->io_lock);
	return ret;
}

static __poll_t mi_poll(struct file *file, poll_table *wait)
{
	struct mi200e *mi = to_mi(file);

	poll_wait(file, &mi->waitq, wait);
	return READ_ONCE(mi->rx_rd) != READ_ONCE(mi->rx_wr) ?
		EPOLLIN | EPOLLRDNORM : 0;
}

static long mi_ioctl(struct file *file, unsigned int cmd, unsigned long arg)
{
	struct mi200e *mi = to_mi(file);
	unsigned long flags;
	long ret = 0;

	dev_dbg(mi->dev, "ioctl %#x %#lx\n", cmd, arg);
	switch (cmd) {
	case MI_IOC_STATUS:
		mutex_lock(&mi->io_lock);
		mi->ubuf_rx[0] = 0;
		mi->ubuf_rx[1] = 0;
		mi->ubuf_rx[2] = mi->tx_frames;
		if (copy_to_user((void __user *)arg, mi->ubuf_rx, MI_FRAME))
			dev_dbg(mi->dev, "status: bad user buffer\n");
		ret = READ_ONCE(mi->tx_state) == TX_IDLE ? 1 : -EPERM;
		mutex_unlock(&mi->io_lock);
		break;

	case MI_IOC_VID:
		spin_lock_irqsave(&mi->lock, flags);
		mi->vid = arg;
		spin_unlock_irqrestore(&mi->lock, flags);
		break;

	case MI_IOC_CONF_3PCS:
		spin_lock_irqsave(&mi->lock, flags);
		mi->phase = (arg == 0xb) ? 1 : (arg == 0xc) ? 2 : 0;
		spin_unlock_irqrestore(&mi->lock, flags);
		break;

	case MI_IOC_CONF_RESET:
		mutex_lock(&mi->io_lock);
		mi_epit_stop(mi);
		disable_irq(mi->irq);
		mi->state = ST_IDLE;
		mi_hw_init(mi, false);
		enable_irq(mi->irq);
		mi_epit_start(mi);
		mutex_unlock(&mi->io_lock);
		break;

	case MI_IOC_FREQ:
	case MI_IOC_SPECODE:
	case MI_IOC_BDRATE:
	default:
		/* accepted and ignored, as by NEP's driver */
		break;
	}
	return ret;
}

static const struct file_operations mi_fops = {
	.owner		= THIS_MODULE,	/* no rmmod while gateway-plc has it open */
	.read		= mi_read,
	.write		= mi_write,
	.poll		= mi_poll,
	.unlocked_ioctl	= mi_ioctl,
	.llseek		= noop_llseek,
};

/* ---- sysfs ------------------------------------------------------------------ */

static ssize_t stats_show(struct device *dev, struct device_attribute *attr,
			  char *buf)
{
	struct mi200e *mi = dev_get_drvdata(dev);

	return sysfs_emit(buf,
		"phase %u\nvid 0x%04x\nstate %d\ntx_state %u\n"
		"tx_frames %u\nrx_frames %u\ncrc_errors %u\nid_errors %u\n"
		"timeouts %u\n",
		mi->phase, mi->vid, mi->state, mi->tx_state, mi->tx_frames,
		mi->rx_frames, mi->crc_errors, mi->id_errors, mi->timeouts);
}
static DEVICE_ATTR_RO(stats);

/*
 * For bring-up: "echo 87 > reg" reads register 0x87 of the selected phase,
 * "echo 07 17 > reg" writes 0x17 to register 0x07; "cat reg" shows the last
 * access.
 */
static ssize_t reg_show(struct device *dev, struct device_attribute *attr,
			char *buf)
{
	struct mi200e *mi = dev_get_drvdata(dev);

	return sysfs_emit(buf, "phase %u reg %02x = %02x\n", mi->phase,
			  mi->dbg_reg, mi->dbg_val);
}

static ssize_t reg_store(struct device *dev, struct device_attribute *attr,
			 const char *buf, size_t count)
{
	struct mi200e *mi = dev_get_drvdata(dev);
	unsigned int reg, val;
	unsigned long flags;
	int n;

	n = sscanf(buf, "%x %x", &reg, &val);
	if (n < 1 || reg > 0xff || (n == 2 && val > 0xff))
		return -EINVAL;
	spin_lock_irqsave(&mi->lock, flags);
	mi->dbg_reg = reg;
	if (n == 2) {
		mi_write_reg(mi, reg & 0x7f, val);
		mi->dbg_val = val;
	} else {
		mi->dbg_val = mi_read_reg(mi, reg);
	}
	spin_unlock_irqrestore(&mi->lock, flags);
	return count;
}
static DEVICE_ATTR_RW(reg);

/* the selected modem, 0-2 (gateway-plc's ioctl 0x85 sets it too) */
static ssize_t phase_show(struct device *dev, struct device_attribute *attr,
			  char *buf)
{
	struct mi200e *mi = dev_get_drvdata(dev);

	return sysfs_emit(buf, "%u\n", mi->phase);
}

static ssize_t phase_store(struct device *dev, struct device_attribute *attr,
			   const char *buf, size_t count)
{
	struct mi200e *mi = dev_get_drvdata(dev);
	unsigned long flags;
	unsigned int p;

	if (kstrtouint(buf, 0, &p) || p > 2)
		return -EINVAL;
	spin_lock_irqsave(&mi->lock, flags);
	mi->phase = p;
	spin_unlock_irqrestore(&mi->lock, flags);
	return count;
}
static DEVICE_ATTR_RW(phase);

static struct attribute *mi_attrs[] = {
	&dev_attr_stats.attr,
	&dev_attr_reg.attr,
	&dev_attr_phase.attr,
	NULL
};
ATTRIBUTE_GROUPS(mi);

/* ---- probe ------------------------------------------------------------------ */

static u32 mi_conreg(struct mi200e *mi)
{
	unsigned long rate = clk_get_rate(mi->clk_per);
	unsigned int pre, post;

	if (rate == 60000000)
		return MI200E_CON_NEP;
	/* the closest rate not above 500 kHz */
	for (post = 0; post < 16; post++)
		for (pre = 0; pre < 16; pre++)
			if (rate / (pre + 1) / (1UL << post) <= MI200E_SPI_HZ)
				goto found;
	pre = 15;
	post = 15;
found:
	dev_info(mi->dev, "ECSPI clock %lu Hz, SPI %lu Hz\n", rate,
		 rate / (pre + 1) / (1UL << post));
	return ECSPI_CON_BASE | (pre << 12) | (post << 8) | ECSPI_CON_EN;
}

static int mi_get_gpio(struct mi200e *mi, struct gpio_desc **d,
		       const char *name, unsigned int idx, int level)
{
	*d = devm_gpiod_get_index(mi->dev, name, idx, GPIOD_ASIS);
	if (IS_ERR(*d))
		return dev_err_probe(mi->dev, PTR_ERR(*d), "%s-gpios[%u]\n",
				     name, idx);
	return gpiod_direction_output_raw(*d, level);
}

static void mi_disable_clk(void *data)
{
	clk_disable_unprepare(data);
}

static int mi_enable_clk(struct mi200e *mi, struct clk **clk, const char *id)
{
	int ret;

	*clk = devm_clk_get(mi->dev, id);
	if (IS_ERR(*clk))
		return dev_err_probe(mi->dev, PTR_ERR(*clk), "clock %s\n", id);
	ret = clk_prepare_enable(*clk);
	if (ret)
		return ret;
	return devm_add_action_or_reset(mi->dev, mi_disable_clk, *clk);
}

static int mi_probe(struct platform_device *pdev)
{
	struct device *dev = &pdev->dev;
	struct mi200e *mi;
	unsigned long rate;
	int ret, i;

	mi = devm_kzalloc(dev, sizeof(*mi), GFP_KERNEL);
	if (!mi)
		return -ENOMEM;
	mi->dev = dev;
	spin_lock_init(&mi->lock);
	mutex_init(&mi->io_lock);
	init_waitqueue_head(&mi->waitq);
	platform_set_drvdata(pdev, mi);

	/* the same initial levels as NEP's driver */
	ret = mi_get_gpio(mi, &mi->reset, "reset", 0, 0);
	if (!ret)
		ret = mi_get_gpio(mi, &mi->ss, "ss", 0, 1);
	for (i = 0; i < 3 && !ret; i++)
		ret = mi_get_gpio(mi, &mi->phase_cs[i], "phase", i, 1);
	if (!ret)
		ret = mi_get_gpio(mi, &mi->led, "led", 0, 1);
	if (!ret)
		ret = mi_get_gpio(mi, &mi->power, "power", 0, 1);
	if (ret)
		return ret;

	mi->spi = devm_platform_ioremap_resource_byname(pdev, "ecspi");
	if (IS_ERR(mi->spi))
		return PTR_ERR(mi->spi);
	mi->epit = devm_platform_ioremap_resource_byname(pdev, "epit");
	if (IS_ERR(mi->epit))
		return PTR_ERR(mi->epit);
	mi->irq = platform_get_irq(pdev, 0);
	if (mi->irq < 0)
		return mi->irq;

	ret = mi_enable_clk(mi, &mi->clk_per, "per");
	if (!ret)
		ret = mi_enable_clk(mi, &mi->clk_ipg, "ipg");
	if (!ret)
		ret = mi_enable_clk(mi, &mi->clk_epit, "epit");
	if (ret)
		return ret;

	mi->conreg = mi_conreg(mi);
	rate = clk_get_rate(mi->clk_epit);
	mi->epit_lr = DIV_ROUND_CLOSEST_ULL((u64)rate * tick_ns, NSEC_PER_SEC) - 1;
	dev_info(dev, "EPIT clock %lu Hz, load %#x\n", rate, mi->epit_lr);

	mi_hw_init(mi, true);
	mi->vid = 0x1fff;
	msleep(200);

	mi_epit_setup(mi);
	ret = devm_request_irq(dev, mi->irq, mi_irq, 0, DRV_NAME, mi);
	if (ret)
		return dev_err_probe(dev, ret, "EPIT interrupt\n");

	mi->state = ST_IDLE;
	mi->tx_state = TX_IDLE;
	mi_epit_start(mi);

	mi->misc.minor = MISC_DYNAMIC_MINOR;
	mi->misc.name = DRV_NAME;
	mi->misc.fops = &mi_fops;
	mi->misc.parent = dev;
	ret = misc_register(&mi->misc);
	if (ret) {
		mi_epit_stop(mi);
		return dev_err_probe(dev, ret, "misc_register\n");
	}

	dev_info(dev, "MI200E power-line modems ready (3 phases)\n");
	return 0;
}

static void mi_remove(struct platform_device *pdev)
{
	struct mi200e *mi = platform_get_drvdata(pdev);

	misc_deregister(&mi->misc);
	mi_epit_stop(mi);
	disable_irq(mi->irq);
	writel(0, mi->spi + ECSPI_CONREG);
}

static const struct of_device_id mi_of_match[] = {
	{ .compatible = "nep,mi200e" },
	{ }
};
MODULE_DEVICE_TABLE(of, mi_of_match);

static struct platform_driver mi_driver = {
	.probe = mi_probe,
	.remove = mi_remove,
	.driver = {
		.name = DRV_NAME,
		.of_match_table = mi_of_match,
		.dev_groups = mi_groups,
	},
};
module_platform_driver(mi_driver);

MODULE_DESCRIPTION("NEP MI200E power-line modem (ZLG EPC-M6G2C)");
MODULE_LICENSE("GPL");
