# NEP firmware check, sourced by zlg-import and web/install-web.sh.
#
# Each branch of this kit is for one NEP firmware major version, the
# major= line of [version] in /root/Gateway/gateway.cfg: nep-fw-20210303 is
# for 20210303 (its local web interface still works), nep-fw-20230903 for
# 20230903. The file nep-firmware next to this one holds the branch's
# version; --any-firmware skips the check.

# nep_fw_major CFG: the major= version in CFG's [version] section
nep_fw_major() {
	[ -r "$1" ] || return 0
	tr -d '\r' < "$1" |
		sed -n '/^\[version\]/,/^\[/s/^[[:space:]]*major[[:space:]]*=[[:space:]]*\([0-9]*\).*/\1/p' |
		head -n 1
}

# nep_fw_check CFG WANTFILE ANY: stop unless CFG's version matches
nep_fw_check() {
	want=
	[ -r "$2" ] && want=$(tr -dc '0-9' < "$2")
	[ "$3" = 1 ] && return 0
	[ -n "$want" ] || { echo "nep-firmware check: $2 is missing" >&2; exit 1; }
	have=$(nep_fw_major "$1")
	if [ -z "$have" ]; then
		echo "This kit is for NEP firmware $want (branch nep-fw-$want), but $1" >&2
		echo "has no firmware version. Use --any-firmware to go ahead anyway." >&2
		exit 1
	fi
	if [ "$have" != "$want" ]; then
		echo "This kit is for NEP firmware $want (branch nep-fw-$want), but this" >&2
		echo "gateway runs firmware $have. Use the kit's nep-fw-$have branch," >&2
		echo "or --any-firmware to go ahead anyway." >&2
		exit 1
	fi
	echo "NEP firmware $have: matches this kit."
}
