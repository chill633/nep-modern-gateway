#!/bin/sh
# Install the ZLG migration files on a running board (the image's rootfs
# stage does the same):  sudo sh install.sh
set -eu
cd "$(dirname "$0")"
[ -f zlg-time.so ] || {
	echo "zlg-time.so is missing. Build it on your PC (README section 7):" >&2
	echo "  arm-linux-gnueabihf-gcc -O2 -marm -fPIC -shared -nostdlib -nostartfiles -fno-stack-protector -fno-builtin -Wl,--hash-style=both -o zlg/zlg-time.so zlg/zlg-time.c" >&2
	exit 1
}
install -m 755 zlg-import zlg-chroot /usr/local/sbin/
# (the image's copy already is /usr/local/share/zlg: install refuses to copy
# a file onto itself)
if [ "$(pwd -P)" != "$(cd /usr/local/share/zlg 2>/dev/null && pwd -P)" ]; then
	install -D -m 644 nep-firmware /usr/local/share/zlg/nep-firmware
	install -D -m 644 nep-firmware.sh /usr/local/share/zlg/nep-firmware.sh
fi
install -D -m 644 zlg-time.so /usr/local/lib/zlg/zlg-time.so
install -D -m 644 zlg-weather.php /usr/local/lib/zlg/zlg-weather.php
[ -e /etc/default/zlg ] || install -m 644 zlg.default /etc/default/zlg
install -m 644 systemd/*.service systemd/*.target systemd/*.path systemd/*.timer /etc/systemd/system/
systemctl daemon-reload
# the programs ZLG's system runs (zlg.target's Wants)
for u in zlg-plc.service zlg-mqtt.service zlg-pctrl.service zlg-hmi.service \
	 zlg-cron.service zlg-restart.path zlg-offset.timer zlg-weather.timer; do
	systemctl add-wants zlg.target "$u"
done
# ZLG's own web server, unless the PHP 8.4 one has replaced it on port 80
# (web/install-web.sh --switch)
if ! grep -q '^ZLG_WEB_PORT=80$' /etc/default/zlg 2>/dev/null; then
	systemctl add-wants zlg.target zlg-httpd.service
fi
# gateway-hmi uses the display and tty1
systemctl mask getty@tty1.service
systemctl daemon-reload
echo "Installed. Next:  sudo zlg-import  then  sudo systemctl enable --now zlg.target"
