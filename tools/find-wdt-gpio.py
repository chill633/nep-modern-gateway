#!/usr/bin/env python3
"""
Find the GPIO that feeds the M6G2C's external hardware watchdog, using what
tools/collect-zlg-hwinfo.sh saved from ZLG's factory Linux.

It looks in two places:
  * the factory device tree: any node whose name or compatible mentions
    wdt/watchdog/wdog/dog and has a *gpio(s) property, decoded to GPIOb_IOnn
  * debugfs GPIO state (gpio-debugfs.txt): lines whose consumer label
    mentions a watchdog

Usage:
  find-wdt-gpio.py zlg-hwinfo/            (directory from the archive)
  find-wdt-gpio.py zlg-hwinfo/device-tree zlg-hwinfo/gpio-debugfs.txt
  find-wdt-gpio.py live.dts
  find-wdt-gpio.py zlg-hwinfo/mtd1.bin     (the factory "dtb" NAND partition)

Prints the WDT_GPIO value to build with, e.g.  WDT_GPIO=1:9
"""

import os
import re
import struct
import sys

GPIO_BASES = {0x0209C000: 1, 0x020A0000: 2, 0x020A4000: 3, 0x020A8000: 4,
              0x020AC000: 5}
WDT_WORDS = re.compile(r"wdt|watchdog|wdog|dog|wdi|wdg", re.I)


class Node:
    def __init__(self, path):
        self.path, self.props = path, {}

    def cells(self, name):
        v = self.props.get(name)
        if isinstance(v, bytes) and len(v) % 4 == 0:
            return list(struct.unpack(">%dI" % (len(v) // 4), v))
        if isinstance(v, list):
            return v
        return None

    def string(self, name):
        v = self.props.get(name)
        if isinstance(v, bytes):
            return v.rstrip(b"\0").replace(b"\0", b" ").decode(errors="replace")
        if isinstance(v, str):
            return v
        return ""


def load_fs(root):
    nodes = []
    for dirpath, _dirs, files in os.walk(root):
        n = Node("/" + os.path.relpath(dirpath, root).replace(os.sep, "/").lstrip("./"))
        for f in files:
            try:
                with open(os.path.join(dirpath, f), "rb") as fh:
                    n.props[f] = fh.read()
            except OSError:
                pass
        nodes.append(n)
    return nodes


def load_dtb(path):
    """Flattened DT blob. Trailing data (e.g. a whole NAND partition) is fine."""
    b = open(path, "rb").read()
    start = b.find(b"\xd0\x0d\xfe\xed")
    if start < 0:
        raise ValueError("no device tree blob in %s" % path)
    b = b[start:]
    (_magic, total, off_struct, off_str, _rsv, _ver, _lc, _boot, _szs,
     _szst) = struct.unpack(">10I", b[:40])
    b = b[:total]
    nodes, stack, p = [], [], off_struct

    def string(o):
        e = b.index(b"\0", off_str + o)
        return b[off_str + o:e].decode(errors="replace")

    while p + 4 <= len(b):
        tag = struct.unpack(">I", b[p:p + 4])[0]
        p += 4
        if tag == 1:                                    # FDT_BEGIN_NODE
            e = b.index(b"\0", p)
            name = b[p:e].decode(errors="replace")
            p = (e + 4) & ~3
            path = "/" if not stack else stack[-1].path.rstrip("/") + "/" + name
            stack.append(Node(path))
            nodes.append(stack[-1])
        elif tag == 2:                                  # FDT_END_NODE
            stack.pop()
        elif tag == 3:                                  # FDT_PROP
            ln, nameoff = struct.unpack(">II", b[p:p + 8])
            p += 8
            stack[-1].props[string(nameoff)] = b[p:p + ln]
            p = (p + ln + 3) & ~3
        elif tag == 9:                                  # FDT_END
            break
    return nodes


def is_dtb(path):
    with open(path, "rb") as f:
        return b"\xd0\x0d\xfe\xed" in f.read(1 << 20)


def load_dts(path):
    """Minimal parser for dtc-decompiled source (numbers, strings, <cells>)."""
    text = re.sub(r"/\*.*?\*/", "", open(path, errors="replace").read(), flags=re.S)
    nodes, stack = [], []
    tok = re.compile(r'([\w,.@+#-]+|/)\s*\{|\}\s*;|([\w,.#+?-]+)\s*(?:=\s*([^;]*))?;', re.S)
    for m in tok.finditer(text):
        if m.group(1):
            name = m.group(1)
            path = "/" if not stack else stack[-1].path.rstrip("/") + "/" + name
            stack.append(Node(path))
            nodes.append(stack[-1])
        elif m.group(0).startswith("}"):
            if stack:
                stack.pop()
        elif stack and m.group(2):
            val = (m.group(3) or "").strip()
            if val.startswith("<"):
                cells = re.findall(r"0x[0-9a-fA-F]+|\d+", val)
                stack[-1].props[m.group(2)] = [int(c, 0) for c in cells]
            else:
                stack[-1].props[m.group(2)] = " ".join(re.findall(r'"([^"]*)"', val))
    return nodes


def gpio_banks(nodes):
    """phandle -> GPIO bank, from gpio-controller nodes' reg addresses."""
    banks = {}
    for n in nodes:
        if "gpio-controller" not in n.props:
            continue
        reg = n.cells("reg")
        m = re.search(r"@([0-9a-fA-F]+)$", n.path)
        addr = reg[0] if reg else (int(m.group(1), 16) if m else None)
        bank = GPIO_BASES.get(addr)
        ph = (n.cells("phandle") or n.cells("linux,phandle") or [None])[0]
        if bank and ph is not None:
            banks[ph] = bank
    return banks


def from_device_tree(nodes):
    banks = gpio_banks(nodes)
    found = []
    for n in nodes:
        label = n.path + " " + n.string("compatible")
        if not WDT_WORDS.search(label):
            continue
        for prop in n.props:
            if not re.search(r"gpios?$", prop):
                continue
            cells = n.cells(prop)
            if not cells or len(cells) < 2:
                continue
            for i in range(0, len(cells) - 1, 3 if len(cells) % 3 == 0 else 2):
                bank = banks.get(cells[i])
                if bank:
                    found.append((bank, cells[i + 1],
                                  "%s: %s (compatible \"%s\")" % (n.path, prop,
                                                                   n.string("compatible"))))
    return found


def from_debugfs(path):
    found, chip_bank, chip_base = [], None, None
    for line in open(path, errors="replace"):
        h = re.match(r"gpiochip\d+: GPIOs (\d+)-\d+.*?(?:platform/)?([0-9a-f]{7,8})\.gpio", line)
        if h:
            chip_base = int(h.group(1))
            chip_bank = GPIO_BASES.get(int(h.group(2), 16))
            continue
        g = re.match(r"\s*gpio-(\d+)\s+\(([^)]*)\)\s*(.*)", line)
        if g and WDT_WORDS.search(g.group(2) + " " + g.group(3)):
            num = int(g.group(1))
            if chip_bank and chip_base is not None:
                bank, pin = chip_bank, num - chip_base
            else:                       # old kernels: bank = num/32 + 1
                bank, pin = num // 32 + 1, num % 32
            found.append((bank, pin, "%s: %s" % (os.path.basename(path), line.strip())))
    return found


def main(argv):
    if not argv:
        print(__doc__)
        return 2
    paths = []
    for a in argv:
        if os.path.isdir(a) and os.path.isdir(os.path.join(a, "device-tree")):
            paths += [os.path.join(a, p) for p in ("device-tree", "gpio-debugfs.txt", "live.dts")
                      if os.path.exists(os.path.join(a, p))]
            paths += sorted(os.path.join(a, p) for p in os.listdir(a)
                            if p.endswith((".dtb", ".bin")))
        else:
            paths.append(a)

    found = []
    for p in paths:
        if os.path.isdir(p):
            found += from_device_tree(load_fs(p))
        elif p.endswith(".txt"):
            found += from_debugfs(p)
        elif is_dtb(p):
            found += from_device_tree(load_dtb(p))
        elif p.endswith(".bin"):
            continue                    # boot loader dumps etc.
        else:
            found += from_device_tree(load_dts(p))

    if not found:
        print("No watchdog GPIO found. ZLG may feed it from a driver without a\n"
              "telling name: look through pinctrl-debugfs.txt / gpio-debugfs.txt for\n"
              "an output GPIO with an unexplained owner, or ask ZLG for the WDI pin.")
        return 1
    seen = set()
    for bank, pin, why in found:
        print("GPIO%d_IO%02d  <-  %s" % (bank, pin, why))
        seen.add((bank, pin))
    if len(seen) == 1:
        bank, pin = seen.pop()
        print("\nBuild with:  WDT_GPIO=%d:%d" % (bank, pin))
    else:
        print("\nSeveral candidates; check them against the factory configuration.")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
