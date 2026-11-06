/*
 * zlg-time.so - show ZLG's programs the clock the way ZLG's system kept it.
 *
 * ZLG's factory system kept its clock on local time, labelled UTC (TZ unset,
 * PHP date.timezone = UTC), and NEP's software depends on that throughout:
 * gateway-plc writes local times, PHP turns them into "UTC" epochs for the
 * charts with strtotime(), cron runs at local hours. Debian keeps the clock on
 * real UTC. Preloaded (LD_PRELOAD) into ZLG's programs only, this library
 * adds the local UTC offset to CLOCK_REALTIME, so inside /srv/zlg the clock
 * reads local time again, while the board's own clock stays correct.
 *
 * The offset (seconds east of UTC, e.g. -18000 for EST) is read from
 * /m6g2c/run/utcoffset (inside /srv/zlg) or /run/zlg/utcoffset (outside,
 * e.g. the php-fpm master before its workers chroot), which zlg-chroot keeps
 * up to date across daylight saving changes; it is re-read at most once a
 * minute.
 *
 * Both time ABIs are covered: the 32-bit time_t calls of ZLG's 2014 programs
 * and the 64-bit ones (__clock_gettime64 etc., glibc 2.34+) of Debian 13's
 * armhf programs such as php-fpm8.4.
 *
 * Built without the C library (raw system calls), so it loads into ZLG's
 * 2014 glibc whatever compiler builds it:
 *   arm-linux-gnueabihf-gcc -O2 -fPIC -shared -nostdlib -nostartfiles \
 *       -Wl,--hash-style=both -o zlg-time.so zlg-time.c
 */

#define NR_read			3
#define NR_open			5
#define NR_close		6
#define NR_gettimeofday		78
#define NR_clock_gettime	263
#define NR_clock_gettime64	403

#define CLOCK_REALTIME		0
#define CLOCK_REALTIME_COARSE	5

struct ts32 { long tv_sec; long tv_nsec; };
struct tv32 { long tv_sec; long tv_usec; };
struct ts64 { long long tv_sec; long tv_nsec; long pad; };
struct tv64 { long long tv_sec; long long tv_usec; };

#if defined(__arm__)
static long sc3(long n, long a, long b, long c)
{
	register long r0 __asm__("r0") = a;
	register long r1 __asm__("r1") = b;
	register long r2 __asm__("r2") = c;
	register long r7 __asm__("r7") = n;

	__asm__ volatile("svc 0" : "+r"(r0) : "r"(r1), "r"(r2), "r"(r7) : "memory");
	return r0;
}
#else
/* host build, for testing the logic only */
extern long syscall(long, ...);
static long sc3(long n, long a, long b, long c)
{
	switch (n) {
	case NR_read:		return syscall(0, a, b, c);
	case NR_open:		return syscall(2, a, b, c);
	case NR_close:		return syscall(3, a);
	case NR_gettimeofday:	return syscall(96, a, b);
	case NR_clock_gettime:	return syscall(228, a, b);
	case NR_clock_gettime64: {
		/* x86-64 timespec is already 64-bit: copy into the 32-bit ABI layout */
		struct { long long s; long long ns; } t;
		long r = syscall(228, a, &t);
		struct ts64 *o = (struct ts64 *)b;
		if (r == 0) { o->tv_sec = t.s; o->tv_nsec = (long)t.ns; }
		return r;
	}
	}
	return -38;
}
#endif

static volatile long offset;
static volatile long next_check;

static long real_seconds(void)
{
	struct ts32 ts;

	if (sc3(NR_clock_gettime, CLOCK_REALTIME, (long)&ts, 0) < 0)
		return 0;
	return ts.tv_sec;
}

static void refresh(long now)
{
	char buf[24];
	long fd, n, v = 0, i = 0, neg = 0;

	if (now < next_check)
		return;
	next_check = now + 60;
	fd = sc3(NR_open, (long)"/m6g2c/run/utcoffset", 0 /* O_RDONLY */, 0);
	if (fd < 0)
		fd = sc3(NR_open, (long)"/run/zlg/utcoffset", 0, 0);
	if (fd < 0)
		return;
	n = sc3(NR_read, fd, (long)buf, sizeof(buf) - 1);
	sc3(NR_close, fd, 0, 0);
	if (n <= 0)
		return;
	while (i < n && (buf[i] == ' ' || buf[i] == '\t'))
		i++;
	if (i < n && (buf[i] == '-' || buf[i] == '+'))
		neg = buf[i++] == '-';
	if (i >= n || buf[i] < '0' || buf[i] > '9')
		return;
	for (; i < n && buf[i] >= '0' && buf[i] <= '9'; i++)
		v = v * 10 + (buf[i] - '0');
	if (v > 14 * 3600)		/* not a UTC offset */
		return;
	offset = neg ? -v : v;
}

__attribute__((visibility("default")))
int clock_gettime(int clk, struct ts32 *ts)
{
	long r = sc3(NR_clock_gettime, clk, (long)ts, 0);

	if (r < 0)
		return -1;
	if (clk == CLOCK_REALTIME || clk == CLOCK_REALTIME_COARSE) {
		refresh(ts->tv_sec);
		ts->tv_sec += offset;
	}
	return 0;
}

__attribute__((visibility("default")))
int gettimeofday(struct tv32 *tv, void *tz)
{
	long r = sc3(NR_gettimeofday, (long)tv, (long)tz, 0);

	if (r < 0)
		return -1;
	if (tv) {
		refresh(tv->tv_sec);
		tv->tv_sec += offset;
	}
	return 0;
}

__attribute__((visibility("default")))
long time(long *t)
{
	long now = real_seconds();

	refresh(now);
	now += offset;
	if (t)
		*t = now;
	return now;
}

/* ---- 64-bit time_t (glibc 2.34+, Debian 13 armhf) ---- */

__attribute__((visibility("default")))
int __clock_gettime64(int clk, struct ts64 *ts)
{
	long r = sc3(NR_clock_gettime64, clk, (long)ts, 0);

	if (r < 0)
		return -1;
	if (clk == CLOCK_REALTIME || clk == CLOCK_REALTIME_COARSE) {
		refresh((long)ts->tv_sec);
		ts->tv_sec += offset;
	}
	return 0;
}

__attribute__((visibility("default")))
int __gettimeofday64(struct tv64 *tv, void *tz)
{
	struct ts64 ts;

	(void)tz;
	if (!tv)
		return 0;
	if (__clock_gettime64(CLOCK_REALTIME, &ts) < 0)
		return -1;
	tv->tv_sec = ts.tv_sec;
	tv->tv_usec = ts.tv_nsec / 1000;
	return 0;
}

__attribute__((visibility("default")))
long long __time64(long long *t)
{
	struct ts64 ts;

	if (__clock_gettime64(CLOCK_REALTIME, &ts) < 0)
		return -1;
	if (t)
		*t = ts.tv_sec;
	return ts.tv_sec;
}
