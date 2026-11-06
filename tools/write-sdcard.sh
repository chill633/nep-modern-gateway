#!/usr/bin/env bash
# Write the image to an SD card safely, then verify what landed on the card.
#
#   sudo tools/write-sdcard.sh out/m6g2c-debian-trixie-armhf.img.xz /dev/sdX
#   sudo tools/write-sdcard.sh --verify-only out/...img /dev/sdX   (check a card)
#
# Why: desktop Linux mounts SD-card partitions automatically. Writing an image
# with dd while the card's old partitions are mounted lets the PC later flush
# its cached metadata for the old filesystem over the new one. On the board
# that shows up as "ext4_check_descriptors: Checksum for group N failed".
#
# The script:
#   * refuses anything that isn't a whole, removable (USB/MMC) disk, unless --force
#   * unmounts every partition of the card before writing
#   * writes with O_DIRECT + fsync, then drops the cache and reads the card back
#   * compares the read-back with the image and checks the root filesystem
set -euo pipefail

die()  { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }
log()  { printf '\033[1;32m==>\033[0m %s\n' "$*"; }

FORCE=0 YES=0 VERIFY_ONLY=0
while [ $# -gt 0 ]; do
	case "$1" in
	--force) FORCE=1 ;;
	--yes|-y) YES=1 ;;
	--verify-only) VERIFY_ONLY=1 ;;
	-*) die "unknown option $1" ;;
	*) break ;;
	esac
	shift
done
[ $# -eq 2 ] || die "usage: $0 [--yes] [--force] [--verify-only] IMAGE(.img|.img.xz) /dev/sdX"
IMG=$1 DEV=$2
[ "$(id -u)" -eq 0 ] || die "run as root (sudo)"
[ -f "$IMG" ] || die "no such image: $IMG"
[ -b "$DEV" ] || die "$DEV is not a block device"
for c in lsblk dd cmp blockdev; do command -v "$c" >/dev/null || die "missing $c"; done

# ---- Safety checks ----------------------------------------------------------
dtype=$(lsblk -dno TYPE "$DEV")
[ "$dtype" = disk ] || { [ "$dtype" = loop ] && [ $FORCE -eq 1 ]; } ||
	die "$DEV is not a whole disk (give /dev/sdX, not /dev/sdX1)"
rm_flag=$(lsblk -dno RM "$DEV" | tr -d ' ')
tran=$(lsblk -dno TRAN "$DEV" | tr -d ' ')
case "$DEV" in /dev/mmcblk*) tran=${tran:-mmc} ;; esac
if [ "$rm_flag" != 1 ] && [ "$tran" != usb ] && [ "$tran" != mmc ] && [ $FORCE -eq 0 ]; then
	die "$DEV is not a removable/USB/MMC disk (RM=$rm_flag TRAN=${tran:-?}); use --force if you are sure"
fi
for mp in / /boot /home /usr /var; do
	src=$(findmnt -no SOURCE "$mp" 2>/dev/null || true)
	case "$src" in "$DEV"*) die "$DEV holds this computer's $mp" ;; esac
done

xz_img=0
case "$IMG" in *.xz) xz_img=1; command -v xz >/dev/null || die "missing xz" ;; esac
img_size() { if [ $xz_img -eq 1 ]; then xz --robot -l "$IMG" | awk '$1=="totals"{print $5}'; else stat -c %s "$IMG"; fi; }
read_img() { if [ $xz_img -eq 1 ]; then xz -dc "$IMG"; else cat "$IMG"; fi; }
SIZE=$(img_size)
[ -n "$SIZE" ] && [ "$SIZE" -gt 0 ] || die "could not determine the image size"
DEVSIZE=$(blockdev --getsize64 "$DEV")
[ "$DEVSIZE" -ge "$SIZE" ] || die "card ($DEVSIZE bytes) is smaller than the image ($SIZE bytes)"

part() { case "$DEV" in *[0-9]) echo "${DEV}p$1" ;; *) echo "${DEV}$1" ;; esac; }

unmount_all() {
	local p mp
	for p in $(lsblk -lnpo NAME "$DEV"); do
		for mp in $(findmnt -rno TARGET -S "$p" 2>/dev/null); do
			log "unmounting $p from $mp"
			umount "$mp" 2>/dev/null || umount -l "$mp" || die "could not unmount $mp"
		done
	done
}

# ---- Write --------------------------------------------------------------------
if [ $VERIFY_ONLY -eq 0 ]; then
	lsblk -o NAME,SIZE,MODEL,TRAN,MOUNTPOINT "$DEV"
	if [ $YES -eq 0 ]; then
		read -r -p "Overwrite ALL data on $DEV with $(basename "$IMG")? [y/N] " a
		[ "$a" = y ] || [ "$a" = Y ] || die "aborted"
	fi
	unmount_all
	log "Writing $(basename "$IMG") ($((SIZE / 1048576)) MiB) to $DEV"
	read_img | dd of="$DEV" bs=4M iflag=fullblock oflag=direct conv=fsync status=progress
	sync
	unmount_all                     # in case something auto-mounted mid-write
fi

# ---- Verify -------------------------------------------------------------------
log "Reading the card back and comparing with the image"
blockdev --flushbufs "$DEV"
echo 3 > /proc/sys/vm/drop_caches
if read_img | cmp -n "$SIZE" - "$DEV"; then
	log "Card contents match the image"
else
	die "card does NOT match the image (cmp shows the first differing byte). Bad card, card reader, or something wrote to it"
fi

blockdev --rereadpt "$DEV" 2>/dev/null || true
sleep 1
P2=$(part 2)
if [ -b "$P2" ] && command -v e2fsck >/dev/null; then
	unmount_all
	log "Checking the root filesystem on $P2 (read-only)"
	e2fsck -fn "$P2" || die "e2fsck reports problems on $P2"
fi
log "Done. Remove the card without mounting it."
