#!/bin/sh
# Install nep-mqtt (README section 7b) on a running board:
#   sudo sh /usr/local/share/zlg/ha-mqtt/install.sh
# Run again after an update: it replaces the program and restarts the
# service, and leaves /etc/nep-mqtt.conf alone.
set -eu
cd "$(dirname "$0")"
[ "$(id -u)" = 0 ] || { echo "run with sudo" >&2; exit 1; }

python3 -c 'import paho.mqtt.client as m; m.CallbackAPIVersion' 2>/dev/null || {
	apt-get update
	apt-get -y install python3-paho-mqtt
}
install -m 755 nep-mqtt /usr/local/bin/nep-mqtt
install -m 644 nep-mqtt.service /etc/systemd/system/nep-mqtt.service
systemctl daemon-reload
if [ ! -e /etc/nep-mqtt.conf ]; then
	install -m 600 nep-mqtt.conf /etc/nep-mqtt.conf
	echo "Installed. Set the broker's host, user and password:"
	echo "  sudo nano /etc/nep-mqtt.conf"
	echo "then:  sudo systemctl enable --now nep-mqtt"
	exit 0
fi
systemctl enable nep-mqtt
systemctl restart nep-mqtt
sleep 3
systemctl --no-pager --lines=8 status nep-mqtt || true
