# shellcheck shell=bash
# Shared helpers for the build stages. Sourced, not executed.

set -euo pipefail

# shellcheck source=../config.env
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/config.env"

log()  { printf '\033[1;32m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mWARN:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

need_root() {
	[ "$(id -u)" -eq 0 ] || die "this stage must run as root (use sudo)"
}

need_cmds() {
	local missing=() c
	for c in "$@"; do
		command -v "$c" >/dev/null 2>&1 || missing+=("$c")
	done
	[ ${#missing[@]} -eq 0 ] || die "missing host tools: ${missing[*]} (see README: Host requirements)"
}

# Shallow-clone a ref once; later runs reuse the tree.
fetch_git() {
	local url=$1 ref=$2 dir=$3
	if [ -d "$dir/.git" ]; then
		log "Reusing $dir"
		return
	fi
	log "Cloning $url ($ref)"
	git clone --depth 1 --branch "$ref" "$url" "$dir"
}

# When the build runs under sudo, hand the outputs back to the invoking user.
fix_owner() {
	if [ -n "${SUDO_UID:-}" ] && [ -n "${SUDO_GID:-}" ]; then
		chown -R "$SUDO_UID:$SUDO_GID" "$@" 2>/dev/null || true
	fi
}

# Parse WDT_GPIO ("<bank>:<pin>") into WDT_BANK / WDT_PIN; empty = no watchdog.
parse_wdt_gpio() {
	WDT_BANK="" WDT_PIN=""
	[ -n "${WDT_GPIO:-}" ] || return 0
	if [[ "$WDT_GPIO" =~ ^(gpio)?([1-5])[:.\ _-]([0-9]{1,2})$ ]]; then
		WDT_BANK=${BASH_REMATCH[2]}
		WDT_PIN=$((10#${BASH_REMATCH[3]}))
		[ "$WDT_PIN" -le 31 ] || die "WDT_GPIO=$WDT_GPIO: pin must be 0-31"
	else
		die "WDT_GPIO=$WDT_GPIO: expected <bank>:<pin>, e.g. 1:9 for GPIO1_IO09"
	fi
}

# Find the pad whose GPIO function is GPIO<bank>_IO<pin> in a pinfunc header.
#   find_gpio_pad <header> <macro prefix MX6_PAD_|MX6UL_PAD_>
find_gpio_pad() {
	local nn pads
	nn=$(printf '%02d' "$WDT_PIN")
	pads=$(grep -oE "\b${2}[A-Z0-9_]+__GPIO${WDT_BANK}_IO${nn}\b" "$1" | sort -u)
	[ "$(printf '%s\n' "$pads" | grep -c .)" -eq 1 ] ||
		die "no unique pad for GPIO${WDT_BANK}_IO${nn} in $1 (got: ${pads:-none})"
	printf '%s' "$pads"
}

partuuid() {  # partuuid <partition number>
	printf '%s-%02d' "${DISK_ID#0x}" "$1"
}

# One root filesystem tree per Debian release, so changing DEBIAN_SUITE never
# builds on top of another release's debootstrap.
ROOTFS_DIR="$WORK/rootfs-$DEBIAN_SUITE"

mkdir -p "$WORK" "$OUT"
