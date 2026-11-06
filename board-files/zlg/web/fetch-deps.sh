#!/bin/sh
# Download the open-source parts of the new web interface into deps/, each
# pinned by checksum. Run on the PC (the rootfs stage and README section 7
# do this); the board gets deps/ inside the migration bundle.
#
#   CodeIgniter 3.1.13       MIT     replaces NEP's CodeIgniter 2.1.3
#   jQuery Migrate 3.5.2     MIT     lets NEP's jQuery 1.x code run on jQuery 3
#   blockUI 2.70.1           MIT     replaces NEP's 2.39 (needs $.browser)
#   Highcharts 13.1.1        free for personal/non-commercial use
#                            (highcharts.com/license); replaces NEP's 2.3.3
#
# jQuery and jQuery UI themselves come from Debian (libjs-jquery,
# libjs-jquery-ui), so apt keeps them patched.
set -eu
cd "$(dirname "$0")"
D=deps
mkdir -p "$D/dl" "$D/js"

get() {	# get URL FILE SHA256
	if [ ! -f "$D/dl/$2" ] || ! echo "$3  $D/dl/$2" | sha256sum -c --status; then
		echo "fetch $1"
		curl -fsSL -o "$D/dl/$2.tmp" "$1"
		mv "$D/dl/$2.tmp" "$D/dl/$2"
	fi
	echo "$3  $D/dl/$2" | sha256sum -c --status || {
		echo "checksum mismatch for $2" >&2
		exit 1
	}
}

get https://github.com/bcit-ci/CodeIgniter/archive/refs/tags/3.1.13.tar.gz \
	codeigniter-3.1.13.tar.gz \
	8cb7bea9db3aa6aaa028ec9793e65b21540ec21890505d8ef03addcba46f9d23
get https://registry.npmjs.org/jquery-migrate/-/jquery-migrate-3.5.2.tgz \
	jquery-migrate-3.5.2.tgz \
	d1a1d30cc6b6c867510c64d5eb3c7b39da3a37839e529f7cdfc9a696983af45d
get https://registry.npmjs.org/block-ui/-/block-ui-2.70.1.tgz \
	block-ui-2.70.1.tgz \
	68e2240adf75a5faba181adde15aa711c282460c5c7d4a0368162f9158ece93f

get https://registry.npmjs.org/highcharts/-/highcharts-13.1.1.tgz \
	highcharts-13.1.1.tgz \
	a5738d8c6cef098b26a661e25f9202c5a756f15c440c9c9b6ea503ac45f66109

rm -rf "$D/ci3"
mkdir -p "$D/ci3"
tar xzf "$D/dl/codeigniter-3.1.13.tar.gz" -C "$D/ci3" --strip-components=1 \
	CodeIgniter-3.1.13/system CodeIgniter-3.1.13/application \
	CodeIgniter-3.1.13/license.txt CodeIgniter-3.1.13/index.php
tar xzf "$D/dl/jquery-migrate-3.5.2.tgz" -O package/dist/jquery-migrate.min.js > "$D/js/jquery-migrate.min.js"
tar xzf "$D/dl/block-ui-2.70.1.tgz" -O package/jquery.blockUI.js > "$D/js/jquery.blockUI.js"
tar xzf "$D/dl/highcharts-13.1.1.tgz" -O package/highcharts.js > "$D/js/highcharts.js"
tar xzf "$D/dl/highcharts-13.1.1.tgz" -O package/LICENSE.txt > "$D/js/highcharts-LICENSE.txt"
echo "deps ready in $(pwd)/$D"
