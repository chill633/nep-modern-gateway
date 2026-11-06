#!/usr/bin/env bash
# Stage 3: Debian armhf root filesystem (debootstrap + qemu-user).
# Default: Debian 13 "trixie". Releases from 12 "bookworm" on are supported.
#
# Input : work/kernel-install (stage 2)
# Output: work/rootfs

# shellcheck source=common.sh
source "$(dirname "$0")/common.sh"

need_root
need_cmds debootstrap chroot mount umount curl

ROOTFS="$ROOTFS_DIR"
KINSTALL="$WORK/kernel-install"
[ -f "$KINSTALL/boot/kernel-release" ] || die "run the kernel stage first"
KVER=$(cat "$KINSTALL/boot/kernel-release")

# ---- qemu-user for running armhf binaries in the chroot -----------------
QEMU=$(command -v qemu-arm-static || command -v qemu-arm || true)
[ -n "$QEMU" ] || die "qemu-user-static is not installed"
[ -e /proc/sys/fs/binfmt_misc/qemu-arm ] ||
	die "binfmt_misc has no qemu-arm handler (install binfmt-support / qemu-user-static, or run: update-binfmts --enable qemu-arm)"

# ---- Keyring ----------------------------------------------------------------
# The host's Debian archive keys. Retired keys are added too, so archived
# releases still verify. If debootstrap reports a missing key for a new
# release, update the host's debian-archive-keyring package (README section 1).
KEYRING="$WORK/debian-archive-keyring.gpg"
: > "$KEYRING"
for k in /usr/share/keyrings/debian-archive-keyring.gpg \
         /usr/share/keyrings/debian-archive-removed-keys.gpg; do
	[ -f "$k" ] && cat "$k" >> "$KEYRING"
done
[ -s "$KEYRING" ] || die "install the debian-archive-keyring package on the host"

# ---- Mount helpers -------------------------------------------------------
MOUNTS=()
mount_chroot() {
	local d
	for d in proc sys dev dev/pts; do
		mkdir -p "$ROOTFS/$d"
	done
	mount -t proc proc "$ROOTFS/proc";            MOUNTS+=("$ROOTFS/proc")
	mount -t sysfs sys "$ROOTFS/sys";             MOUNTS+=("$ROOTFS/sys")
	mount --bind /dev "$ROOTFS/dev";              MOUNTS+=("$ROOTFS/dev")
	mount -t devpts devpts "$ROOTFS/dev/pts";     MOUNTS+=("$ROOTFS/dev/pts")
}
umount_chroot() {
	local i
	for (( i=${#MOUNTS[@]}-1; i>=0; i-- )); do
		umount -l "${MOUNTS[$i]}" 2>/dev/null || true
	done
	MOUNTS=()
}
trap umount_chroot EXIT

in_chroot() {
	LC_ALL=C LANG=C DEBIAN_FRONTEND=noninteractive \
		chroot "$ROOTFS" /usr/bin/env PATH=/usr/sbin:/usr/bin:/sbin:/bin "$@"
}

# ---- Base system ----------------------------------------------------------
if [ -f "$ROOTFS/.stage-debootstrap-done" ]; then
	log "Reusing existing debootstrap in $ROOTFS"
else
	rm -rf "$ROOTFS"
	mkdir -p "$ROOTFS"
	# An older host debootstrap may not know the suite's name yet; all
	# releases since buster use the same script as sid.
	DBS_SCRIPT=()
	if [ ! -e "/usr/share/debootstrap/scripts/$DEBIAN_SUITE" ] &&
	   [ -e /usr/share/debootstrap/scripts/sid ]; then
		warn "host debootstrap has no '$DEBIAN_SUITE' script; using 'sid'"
		DBS_SCRIPT=(/usr/share/debootstrap/scripts/sid)
	fi
	log "debootstrap first stage ($DEBIAN_SUITE/$DEBIAN_ARCH from $DEBIAN_MIRROR)"
	debootstrap --arch="$DEBIAN_ARCH" --foreign --variant=minbase \
		--keyring="$KEYRING" \
		--include=systemd,systemd-sysv,udev,dbus,kmod,ca-certificates \
		"$DEBIAN_SUITE" "$ROOTFS" "$DEBIAN_MIRROR" "${DBS_SCRIPT[@]}" ||
		die "debootstrap failed. 'NO_PUBKEY' or 'Release signed by unknown key' means the host's debian-archive-keyring is too old for $DEBIAN_SUITE (README section 1)"

	# Older binfmt registrations without the F flag need the emulator inside.
	cp "$QEMU" "$ROOTFS/usr/bin/"

	log "debootstrap second stage (emulated, takes a while)"
	in_chroot /debootstrap/debootstrap --second-stage
	touch "$ROOTFS/.stage-debootstrap-done"
fi

# Older binfmt registrations without the F flag need the emulator inside
# (removed again at the end of this stage).
cp "$QEMU" "$ROOTFS/usr/bin/"
mount_chroot

# ---- APT ------------------------------------------------------------------
log "Configuring APT"
# Firmware (firmware-realtek, firmware-misc-nonfree) is in "non-free-firmware"
# from bookworm on. Security updates are in "<suite>-security".
{
	echo "deb $DEBIAN_MIRROR $DEBIAN_SUITE $DEBIAN_COMPONENTS"
	echo "deb $DEBIAN_MIRROR $DEBIAN_SUITE-updates $DEBIAN_COMPONENTS"
	echo "deb $DEBIAN_SECURITY_MIRROR $DEBIAN_SUITE-security $DEBIAN_COMPONENTS"
} > "$ROOTFS/etc/apt/sources.list"
rm -f "$ROOTFS/etc/apt/apt.conf.d/90archive"   # left over from buster builds
cat > "$ROOTFS/etc/apt/apt.conf.d/90norecommends" <<'EOF'
APT::Install-Recommends "false";
APT::Install-Suggests "false";
EOF

# Don't start services inside the chroot.
printf '#!/bin/sh\nexit 101\n' > "$ROOTFS/usr/sbin/policy-rc.d"
chmod 755 "$ROOTFS/usr/sbin/policy-rc.d"
rm -f "$ROOTFS/etc/resolv.conf"
cp -L /etc/resolv.conf "$ROOTFS/etc/resolv.conf"

PACKAGES=(
	locales tzdata sudo bash-completion less nano vim-tiny
	iproute2 iputils-ping netbase net-tools openssh-server curl wget
	procps psmisc fdisk e2fsprogs cloud-guest-utils
	mtd-utils can-utils i2c-tools usbutils u-boot-tools libubootenv-tool util-linux-extra
	libpam-systemd systemd-resolved systemd-timesyncd
	wpasupplicant iw wireless-regdb firmware-realtek
	firmware-misc-nonfree
)
# shellcheck disable=SC2206
PACKAGES+=(${EXTRA_PACKAGES})

log "Installing packages: ${PACKAGES[*]}"
in_chroot apt-get update
in_chroot apt-get -y dist-upgrade
in_chroot apt-get -y install "${PACKAGES[@]}"

# ---- System configuration -------------------------------------------------
log "Configuring the system"

echo "$HOSTNAME_TARGET" > "$ROOTFS/etc/hostname"
cat > "$ROOTFS/etc/hosts" <<EOF
127.0.0.1	localhost
127.0.1.1	$HOSTNAME_TARGET
::1		localhost ip6-localhost ip6-loopback
ff02::1		ip6-allnodes
ff02::2		ip6-allrouters
EOF

cat > "$ROOTFS/etc/fstab" <<EOF
# <device>                 <mount>  <type>  <options>                          <dump> <pass>
PARTUUID=$(partuuid 2)  /        ext4    defaults,noatime,errors=remount-ro  0      1
PARTUUID=$(partuuid 1)  /boot    ext4    defaults,noatime                    0      2
tmpfs                      /tmp     tmpfs   defaults,nosuid,nodev,size=64M     0      0
EOF

# Locale and time zone
sed -i "s/^# *\(${LOCALE//./\\.} \)/\1/" "$ROOTFS/etc/locale.gen"
grep -q "^${LOCALE} " "$ROOTFS/etc/locale.gen" || echo "${LOCALE} ${LOCALE#*.}" >> "$ROOTFS/etc/locale.gen"
in_chroot locale-gen
in_chroot update-locale LANG="$LOCALE"
ln -sf "/usr/share/zoneinfo/$TIMEZONE" "$ROOTFS/etc/localtime"
echo "$TIMEZONE" > "$ROOTFS/etc/timezone"
in_chroot dpkg-reconfigure -f noninteractive tzdata

# User: sudo-capable, password must be changed at first login; root locked.
if ! in_chroot id "$DEFAULT_USER" >/dev/null 2>&1; then
	in_chroot useradd -m -s /bin/bash -G sudo,adm,dialout,plugdev,input,systemd-journal "$DEFAULT_USER"
fi
echo "$DEFAULT_USER:$DEFAULT_PASSWORD" | in_chroot chpasswd
in_chroot chage -d 0 "$DEFAULT_USER"
in_chroot passwd -l root

# Networking: systemd-networkd + resolved, DHCP on every wired port.
mkdir -p "$ROOTFS/etc/systemd/network"
cat > "$ROOTFS/etc/systemd/network/20-wired.network" <<'EOF'
[Match]
Name=eth* en*

[Network]
DHCP=yes
EOF
cat > "$ROOTFS/etc/systemd/network/25-wireless.network" <<'EOF'
[Match]
Name=wlan*

[Network]
DHCP=yes
EOF
in_chroot systemctl enable systemd-networkd.service systemd-resolved.service systemd-timesyncd.service

# ---- Wi-Fi: TP-Link TL-WN823N (rtl8xxxu) ----------------------------------
# The kernel command line has net.ifnames=0, so the dongle is "wlan0".
# udev starts wpa_supplicant@wlan0 only when the dongle is present. Enabling
# it as a normal service would hold up the boot ~90 s without the dongle.
# The package's D-Bus wpa_supplicant.service isn't used; switch it off.
in_chroot systemctl disable wpa_supplicant.service >/dev/null 2>&1 || true
cat > "$ROOTFS/etc/udev/rules.d/80-wifi-wpa-supplicant.rules" <<'EOF'
SUBSYSTEM=="net", ACTION=="add", KERNEL=="wlan0", TAG+="systemd", ENV{SYSTEMD_WANTS}+="wpa_supplicant@wlan0.service"
EOF

WPA_CONF="$ROOTFS/etc/wpa_supplicant/wpa_supplicant-wlan0.conf"
mkdir -p "$(dirname "$WPA_CONF")"
if [ -n "$WIFI_SSID" ]; then
	log "Wi-Fi: network \"$WIFI_SSID\" (WPA2-PSK, country $WIFI_COUNTRY)"
	# PBKDF2-SHA1(passphrase, SSID, 4096, 32), as wpa_passphrase computes it.
	# The values go through the environment, so they never appear on a
	# command line.
	( umask 077
	  WIFI_SSID="$WIFI_SSID" WIFI_PASSPHRASE="$WIFI_PASSPHRASE" WIFI_COUNTRY="$WIFI_COUNTRY" \
		python3 -I "$TOP/tools/wpa-conf.py" > "$WPA_CONF" ) ||
		die "could not generate the Wi-Fi configuration"
else
	log "Wi-Fi: no network configured (WIFI_SSID empty)"
	( umask 077
	  printf 'ctrl_interface=/run/wpa_supplicant\nupdate_config=1\ncountry=%s\n' "$WIFI_COUNTRY" > "$WPA_CONF" )
fi
chmod 600 "$WPA_CONF"

# Wi-Fi regulatory database. Debian ships two copies: one signed with
# Debian's key (what Debian's own kernels trust) and the upstream one,
# signed with the keys our kernel has built in. Debian's is the default,
# which our kernel rejects as "malformed or signature is missing/invalid",
# so the country setting is ignored. Select the upstream copy.
if [ -e "$ROOTFS/lib/firmware/regulatory.db-upstream" ]; then
	in_chroot update-alternatives --set regulatory.db /lib/firmware/regulatory.db-upstream
else
	warn "no regulatory.db-upstream in wireless-regdb; Wi-Fi will use the world regulatory domain"
fi

# e2fsck and a clock that starts in the past: until the RTC has a valid time,
# the clock starts at systemd's build date, so the filesystem's last-write
# time looks like the future. e2fsck then runs a full check on every boot
# (~5 s). Tell it the clock can't be trusted.
cat > "$ROOTFS/etc/e2fsck.conf" <<'EOF'
[options]
	broken_system_clock = 1
EOF

# apt's daily timers: off by default (APT_DAILY=0). They cost memory and
# SD-card writes, and nothing on the image installs updates by itself
# anyway. Run "sudo apt update && sudo apt upgrade" yourself, or install
# unattended-upgrades and set APT_DAILY=1.
if [ "${APT_DAILY}" = 1 ]; then
	in_chroot systemctl enable apt-daily.timer apt-daily-upgrade.timer >/dev/null 2>&1 || true
else
	in_chroot systemctl disable apt-daily.timer apt-daily-upgrade.timer >/dev/null 2>&1 || true
fi

# Keep the journal small on an SD card.
mkdir -p "$ROOTFS/etc/systemd/journald.conf.d"
cat > "$ROOTFS/etc/systemd/journald.conf.d/10-size.conf" <<'EOF'
[Journal]
SystemMaxUse=64M
EOF

# Emergency/rescue mode must give a usable shell: root is locked, and
# sulogin then refuses to start ("the root account is locked"). Allow it
# on the console, which needs physical access to the serial port.
if [ "${RESCUE_SHELL_NOPASSWD}" = 1 ]; then
	for u in emergency rescue; do
		mkdir -p "$ROOTFS/etc/systemd/system/$u.service.d"
		printf '[Service]\nEnvironment=SYSTEMD_SULOGIN_FORCE=1\n' \
			> "$ROOTFS/etc/systemd/system/$u.service.d/10-locked-root.conf"
	done
fi

# The i.MX6UL watchdog: let systemd use it once the system is up.
mkdir -p "$ROOTFS/etc/systemd/system.conf.d"
cat > "$ROOTFS/etc/systemd/system.conf.d/10-watchdog.conf" <<'EOF'
[Manager]
RuntimeWatchdogSec=30
ShutdownWatchdogSec=2min
EOF

# U-Boot environment access from Linux (fw_printenv / fw_setenv, from
# libubootenv-tool; u-boot-tools no longer ships them).
cat > "$ROOTFS/etc/fw_env.config" <<'EOF'
# device        offset   size
/dev/mmcblk0    0xC0000  0x2000
EOF

# ---- First boot: grow the root partition, create SSH host keys ----------
install -m 755 "$TOP/board-files/rootfs/m6g2c-beep" "$ROOTFS/usr/local/bin/m6g2c-beep"
install -m 755 "$TOP/board-files/rootfs/m6g2c-firstboot" "$ROOTFS/usr/local/sbin/m6g2c-firstboot"
install -m 644 "$TOP/board-files/rootfs/m6g2c-firstboot.service" \
	"$ROOTFS/etc/systemd/system/m6g2c-firstboot.service"
in_chroot systemctl enable m6g2c-firstboot.service
touch "$ROOTFS/etc/m6g2c-firstboot"

# Migration kit for ZLG's gateway software (README section 9); installed on
# the board with: sudo sh /usr/local/share/zlg/install.sh
rm -rf "$ROOTFS/usr/local/share/zlg"
mkdir -p "$ROOTFS/usr/local/share/zlg"
cp -r "$TOP/board-files/zlg/." "$ROOTFS/usr/local/share/zlg/"
# zlg-time.so (the shifted clock for ZLG's programs): no C library, so it
# loads into ZLG's old glibc
"${CROSS_COMPILE}gcc" -O2 -marm -fPIC -shared -nostdlib -nostartfiles \
	-fno-stack-protector -fno-builtin -Wl,--hash-style=both \
	-o "$ROOTFS/usr/local/share/zlg/zlg-time.so" "$TOP/board-files/zlg/zlg-time.c" ||
	die "could not build zlg-time.so with ${CROSS_COMPILE}gcc"

# ---- Kernel ----------------------------------------------------------------
log "Installing kernel $KVER"
rm -rf "$ROOTFS/boot"/* "$ROOTFS/lib/modules"/*
cp -a "$KINSTALL/boot/." "$ROOTFS/boot/"
mkdir -p "$ROOTFS/lib/modules"
cp -a "$KINSTALL/lib/modules/$KVER" "$ROOTFS/lib/modules/"
in_chroot depmod -a "$KVER"

mkdir -p "$ROOTFS/boot/extlinux"
cat > "$ROOTFS/boot/extlinux/extlinux.conf" <<EOF
# Read by U-Boot from the boot partition (p1); paths are relative to it.
# Debian $DEBIAN_SUITE on the ZLG M6G2C, Linux $KVER
#
# No "menu" lines on purpose: any of them makes U-Boot show an interactive
# menu and wait "timeout" tenths of a second. Interrupt U-Boot's autoboot
# countdown instead if you need to change the boot.
default debian

label debian
	linux /zImage
	fdt /dtbs/${DTB_NAME}.dtb
	append console=${SERIAL_CONSOLE},${SERIAL_BAUD} root=PARTUUID=$(partuuid 2) rootfstype=ext4 rootwait ro fsck.repair=yes net.ifnames=0${KERNEL_CMDLINE_EXTRA:+ $KERNEL_CMDLINE_EXTRA}
EOF

# ---- Clean up --------------------------------------------------------------
log "Cleaning up"
in_chroot apt-get clean
rm -rf "$ROOTFS"/var/lib/apt/lists/*
rm -f "$ROOTFS"/etc/ssh/ssh_host_*
: > "$ROOTFS/etc/machine-id"
rm -f "$ROOTFS/var/lib/dbus/machine-id"
rm -f "$ROOTFS/usr/sbin/policy-rc.d"
ln -sf /run/systemd/resolve/resolv.conf "$ROOTFS/etc/resolv.conf"
rm -f "$ROOTFS/usr/bin/$(basename "$QEMU")"
find "$ROOTFS/var/log" -type f -exec truncate -s 0 {} +

umount_chroot
log "Root filesystem done: $ROOTFS"
