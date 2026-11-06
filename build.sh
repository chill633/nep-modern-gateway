#!/usr/bin/env bash
# Build a Debian (armhf, default 13 "trixie") SD-card image for the ZLG M6G2C-256LI.
#
#   ./build.sh uboot      SPL + U-Boot           (no root needed)
#   ./build.sh kernel     Linux 6.12 + DTB       (no root needed)
#   sudo ./build.sh rootfs   Debian rootfs       (root: debootstrap/chroot)
#   sudo ./build.sh image    SD-card image       (root: loop devices)
#   sudo ./build.sh all      everything, in order
#   ./build.sh clean      remove work/ and out/
set -euo pipefail

TOP=$(cd "$(dirname "$0")" && pwd)
S="$TOP/scripts"

stage() {
	case "$1" in
	uboot)  bash "$S/01-uboot.sh" ;;
	kernel) bash "$S/02-kernel.sh" ;;
	rootfs) bash "$S/03-rootfs.sh" ;;
	image)  bash "$S/04-image.sh" ;;
	*) echo "unknown stage: $1" >&2; exit 2 ;;
	esac
}

case "${1:-}" in
uboot|kernel|rootfs|image)
	stage "$1" ;;
all)
	for s in uboot kernel rootfs image; do stage "$s"; done ;;
clean)
	# shellcheck source=config.env
	source "$TOP/config.env"
	for r in "$WORK"/rootfs*/; do
		if mountpoint -q "${r}proc" 2>/dev/null; then
			echo "$r still has mounts; not cleaning" >&2; exit 1
		fi
	done
	rm -rf "$WORK" "$OUT" ;;
*)
	sed -n '2,9p' "$0" | sed 's/^# \{0,1\}//'
	exit 1 ;;
esac
