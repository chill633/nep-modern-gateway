#!/usr/bin/env bash
# Stage 4: assemble the SD-card image.
#
#   offset      content
#   0           MBR (disk id $DISK_ID)
#   1 KiB       SPL                     (i.MX6 ROM loads it from here)
#   69 KiB      u-boot-dtb.img          (SPL loads it from sector 0x8a)
#   768 KiB     U-Boot environment      (8 KiB)
#   4 MiB       p1  ext4  "boot"  /boot   (no journal)
#   ...         p2  ext4  "rootfs" /    (grown to the card size on first boot)
#
# Output: out/$IMAGE_NAME (+ .xz, .sha256)

# shellcheck source=common.sh
source "$(dirname "$0")/common.sh"

need_root
need_cmds sfdisk losetup mkfs.ext4 rsync dd xz sha256sum

ROOTFS="$ROOTFS_DIR"
IMG="$OUT/$IMAGE_NAME"
for f in "$OUT/SPL" "$OUT/u-boot-dtb.img" "$ROOTFS/boot/zImage"; do
	[ -e "$f" ] || die "missing $f - run the earlier stages first"
done

used_mb=$(du -sm --exclude=./proc --exclude=./sys "$ROOTFS" | cut -f1)
min_mb=$(( 4 + BOOT_PART_MB + used_mb * 12 / 10 + 64 ))
[ "$IMAGE_SIZE_MB" -ge "$min_mb" ] || die "IMAGE_SIZE_MB=$IMAGE_SIZE_MB is too small (need >= $min_mb)"

log "Creating ${IMAGE_SIZE_MB} MiB image $IMG"
rm -f "$IMG" "$IMG.xz" "$IMG.sha256"
truncate -s "${IMAGE_SIZE_MB}M" "$IMG"

boot_start=8192                                   # 4 MiB in 512-byte sectors
boot_size=$(( BOOT_PART_MB * 2048 ))
root_start=$(( boot_start + boot_size ))
sfdisk --quiet "$IMG" <<EOF
label: dos
label-id: $DISK_ID
unit: sectors

start=$boot_start, size=$boot_size, type=83
start=$root_start, type=83
EOF

LOOP=""
MNT="$WORK/mnt"
cleanup() {
	umount "$MNT/boot" 2>/dev/null || true
	umount "$MNT" 2>/dev/null || true
	[ -n "$LOOP" ] && losetup -d "$LOOP" 2>/dev/null || true
}
trap cleanup EXIT

LOOP=$(losetup --find --show --partscan "$IMG")
for _ in $(seq 50); do [ -b "${LOOP}p2" ] && break; sleep 0.1; done
[ -b "${LOOP}p2" ] || die "partitions of $LOOP did not appear"

# /boot: ext4 with 256-byte inodes (timestamps past 2038; ext2 stops at
# 2038-01-19), no journal so U-Boot never meets a journal needing recovery.
# No 64bit feature either (pointless on a small partition): older vendor
# U-Boots such as ZLG's 2015.04 misread 64-byte group descriptors. U-Boot
# reads extents and ignores checksums on read.
mkfs.ext4 -q -L boot -I 256 -O ^has_journal,^64bit "${LOOP}p1"

# /: plain ext4. Debian 13's e2fsprogs (1.47) handles everything a 1.47 host
# creates, unlike buster's 1.44 did.
mkfs.ext4 -q -L rootfs "${LOOP}p2"

log "Copying the root filesystem"
mkdir -p "$MNT"
mount "${LOOP}p2" "$MNT"
mkdir -p "$MNT/boot"
mount "${LOOP}p1" "$MNT/boot"
rsync -aHAX --numeric-ids \
	--exclude='/.stage-*' --exclude='/proc/*' --exclude='/sys/*' \
	--exclude='/dev/*' --exclude='/run/*' --exclude='/tmp/*' \
	"$ROOTFS/" "$MNT/"
sync
umount "$MNT/boot"
umount "$MNT"
losetup -d "$LOOP"
LOOP=""

log "Writing SPL and U-Boot"
dd if="$OUT/SPL"            of="$IMG" bs=1k seek=1  conv=notrunc status=none
dd if="$OUT/u-boot-dtb.img" of="$IMG" bs=1k seek=69 conv=notrunc status=none

log "Compressing"
xz -T0 -k -f "$IMG"
(cd "$OUT" && sha256sum "$IMAGE_NAME" "$IMAGE_NAME.xz" > "$IMAGE_NAME.sha256")
fix_owner "$OUT"

log "Image ready:"
ls -lh "$IMG" "$IMG.xz"
echo
echo "Write it with:  xzcat $IMG.xz | sudo dd of=/dev/sdX bs=4M conv=fsync status=progress"
