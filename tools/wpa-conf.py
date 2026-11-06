#!/usr/bin/env python3
"""
Print a wpa_supplicant configuration for one WPA2-Personal network.

Input comes from the environment (so it never shows on a command line):
  WIFI_SSID         network name (1-32 bytes)
  WIFI_PASSPHRASE   8-63 printable ASCII characters
  WIFI_COUNTRY      two-letter regulatory domain, e.g. US

Only the derived 256-bit key is written, PBKDF2-HMAC-SHA1(passphrase, SSID,
4096 iterations), exactly what wpa_passphrase prints. The passphrase isn't
stored on the device. That key also works with WPA2/WPA3 "transition mode"
access points; a WPA3-only network (SAE) needs the plain passphrase.

  tools/wpa-conf.py --selftest      checks against the IEEE 802.11i test vectors
"""
import hashlib
import os
import re
import sys


def psk(passphrase, ssid):
    return hashlib.pbkdf2_hmac("sha1", passphrase.encode("ascii"), ssid, 4096, 32).hex()


def selftest():
    # IEEE 802.11i-2004, Annex H.4.1
    vectors = [
        ("password", b"IEEE",
         "f42c6fc52df0ebef9ebb4b90b38a5f902e83fe1b135a70e23aed762e9710a12e"),
        ("ThisIsAPassword", b"ThisIsASSID",
         "0dc0d6eb90555ed6419756b9a15ec3e3209b63df707dd508d14581f8982721af"),
        ("aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", b"Z" * 32,
         "becb93866bb8c3832cb777c2f559807c8c59afcb6eae734885001300a981cc62"),
    ]
    for pw, ssid, want in vectors:
        got = psk(pw, ssid)
        print("%-34s %-12s %s" % (pw, ssid.decode(), "ok" if got == want else "FAIL " + got))
        if got != want:
            return 1
    return 0


def main():
    if sys.argv[1:] == ["--selftest"]:
        return selftest()
    ssid = os.environ.get("WIFI_SSID", "").encode("utf-8")
    pw = os.environ.get("WIFI_PASSPHRASE", "")
    country = os.environ.get("WIFI_COUNTRY", "US").upper()
    if not 1 <= len(ssid) <= 32:
        sys.exit("WIFI_SSID must be 1-32 bytes")
    if not (8 <= len(pw) <= 63 and all(32 <= ord(c) <= 126 for c in pw)):
        sys.exit("WIFI_PASSPHRASE must be 8-63 printable ASCII characters")
    if not re.fullmatch(r"[A-Z]{2}", country):
        sys.exit("WIFI_COUNTRY must be a two-letter code, e.g. US")

    # Quoted SSID when it's plain text; hex otherwise (quotes, non-ASCII...).
    text = ssid.decode("utf-8", "replace")
    if all(32 <= b <= 126 for b in ssid) and '"' not in text:
        ssid_line = 'ssid="%s"' % text
    else:
        ssid_line = "ssid=%s" % ssid.hex()

    print("ctrl_interface=/run/wpa_supplicant")
    print("update_config=1")
    print("country=%s" % country)
    print()
    print("network={")
    print("\t%s" % ssid_line)
    print("\tkey_mgmt=WPA-PSK")
    print("\tpsk=%s" % psk(pw, ssid))
    print("}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
