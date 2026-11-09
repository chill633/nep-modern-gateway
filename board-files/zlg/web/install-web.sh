#!/bin/sh
# Install the PHP 8.4 version of NEP's web interface on the board, next to
# ZLG's own web server, and switch between them.
#
#   sudo sh install-web.sh            install or update; serves on port 8080
#   sudo sh install-web.sh --switch   the new server takes port 80 (ZLG's off)
#   sudo sh install-web.sh --revert   back to ZLG's server on port 80
#   sudo sh install-web.sh --rebuild  only re-run zlg-web-port
#   --any-firmware                    skip the NEP firmware check
#
# Needs zlg/install.sh and zlg-import done first (README section 7), and
# internet access for apt. NEP's code is converted on the board by
# zlg-web-port from /srv/zlg; the original stays as it is.
set -eu
cd "$(dirname "$0")"
HERE=$(pwd -P)
SHARE=/usr/local/share/zlg/web

die() { echo "install-web: $*" >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || die "run as root"

set_port() {	# set_port N: ZLG_WEB_PORT in /etc/default/zlg
	touch /etc/default/zlg
	if grep -q '^ZLG_WEB_PORT=' /etc/default/zlg; then
		sed -i "s/^ZLG_WEB_PORT=.*/ZLG_WEB_PORT=$1/" /etc/default/zlg
	else
		printf '\n# Port of the PHP 8.4 web interface (install-web.sh)\nZLG_WEB_PORT=%s\n' "$1" >> /etc/default/zlg
	fi
}

ANYFW=0
MODE=
for a in "$@"; do
	case "$a" in
	--any-firmware) ANYFW=1 ;;
	*) [ -z "$MODE" ] || die "one option at a time: $MODE, $a"; MODE=$a ;;
	esac
done

case "$MODE" in
--switch)
	systemctl is-active -q zlg-web.service || die "zlg-web isn't running; install first"
	systemctl disable --now zlg-httpd.service
	systemctl remove-wants zlg.target zlg-httpd.service 2>/dev/null || true
	set_port 80
	systemctl restart zlg-web.service
	echo "The new web interface is on port 80; ZLG's server is stopped."
	exit 0 ;;
--revert)
	set_port 8080
	systemctl restart zlg-web.service
	systemctl add-wants zlg.target zlg-httpd.service
	systemctl enable --now zlg-httpd.service 2>/dev/null || systemctl start zlg-httpd.service
	echo "ZLG's web server is back on port 80; the new one is on 8080."
	exit 0 ;;
--rebuild)
	"$SHARE/zlg-web-port"
	systemctl reload zlg-web-fpm.service 2>/dev/null || true
	exit 0 ;;
"") ;;
*) die "unknown option $MODE" ;;
esac

[ -x /srv/zlg/root/Gateway/gateway-plc ] || die "/srv/zlg is empty: run zlg-import first"
[ -x /usr/local/sbin/zlg-chroot ] || die "run zlg/install.sh first"
# shellcheck disable=SC1091
. "$HERE/../nep-firmware.sh"
nep_fw_check /srv/zlg/root/Gateway/gateway.cfg "$HERE/../nep-firmware" "$ANYFW"
[ -f deps/ci3/system/core/CodeIgniter.php ] ||
	die "deps/ is missing: run board-files/zlg/web/fetch-deps.sh on the PC first"

echo "Installing Debian's lighttpd, PHP 8.4 and jQuery ..."
apt-get update -q
DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends \
	lighttpd php8.4-fpm php8.4-cli php8.4-mbstring libjs-jquery libjs-jquery-ui
# Debian's own instances would take port 80 and RAM: ours run instead
systemctl disable --now lighttpd.service php8.4-fpm.service 2>/dev/null || true

echo "Installing the web files ..."
# run from the image's copy (README 7a: /usr/local/share/zlg/web), it is
# already in place: removing $SHARE would remove the script's own files
if [ "$HERE" != "$(cd "$SHARE" 2>/dev/null && pwd -P)" ]; then
	rm -rf "$SHARE"
	mkdir -p "$SHARE"
	cp -r "$HERE/." "$SHARE/"
fi
chmod 755 "$SHARE/zlg-web-port"
install -d /etc/zlg
install -m 644 lighttpd.conf /etc/zlg/lighttpd.conf
install -m 644 php-fpm.conf /etc/zlg/php-fpm.conf
install -m 644 systemd/zlg-web.service systemd/zlg-web-fpm.service /etc/systemd/system/
grep -q '^ZLG_WEB_PORT=' /etc/default/zlg 2>/dev/null || set_port 8080
# the settings pages' helper (root): Wi-Fi, Modules; viewing is open,
# changes need the settings password (default "gateway")
install -m 755 admin/zlg-admin /usr/local/sbin/zlg-admin
install -m 644 systemd/zlg-admin.socket systemd/zlg-admin@.service \
	systemd/zlg-wifi-guard.service /etc/systemd/system/

echo "Converting NEP's web interface (zlg-web-port) ..."
"$SHARE/zlg-web-port"

systemctl daemon-reload
systemctl enable zlg-wifi-guard.service
systemctl enable --now zlg-admin.socket
systemctl add-wants zlg.target zlg-web-fpm.service
systemctl add-wants zlg.target zlg-web.service
systemctl restart zlg-web-fpm.service zlg-web.service
# zlg-chroot switches ZLG's command-line PHP (cron: php proxy, wea, ...) to
# PHP 8.4 only if PHP 8.4 was installed when it started; on a fresh install
# it wasn't, so restart ZLG's services once
if ! mountpoint -q /srv/zlg/opt/php5/bin/php 2>/dev/null; then
	echo "Restarting ZLG's services so their PHP scripts use PHP 8.4 ..."
	systemctl restart zlg.target
fi
port=$(sed -n 's/^ZLG_WEB_PORT=//p' /etc/default/zlg)
ip=$(hostname -I 2>/dev/null | cut -d' ' -f1)
echo
[ -s /var/lib/zlg-admin/mi-password ] ||
	echo "Advanced page (MI Setup): set the PLC password once:  sudo zlg-admin --set-mi-password"
if [ "$port" = 80 ]; then
	echo "Done: http://${ip:-<board>}/ (the new server; ZLG's stays off)."
else
	echo "Done: http://${ip:-<board>}:${port}/ (ZLG's server stays on port 80)."
	echo "When it looks right:  sudo sh $0 --switch"
fi
