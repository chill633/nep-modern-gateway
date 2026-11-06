#!/usr/bin/env python3
"""
Turn the numeric i.MX6UL pin settings of a *compiled* device tree back into
MX6UL_PAD_* names, so the factory ZLG configuration can be copied into
board-files/linux/imx6ul-zlg-m6g2c.dts.

Input is either
  * a /proc/device-tree copy (directory; collect-zlg-hwinfo.sh makes one), or
  * a .dts produced by "dtc -I fs /proc/device-tree" or "dtc -I dtb x.dtb", or
  * a .dtb, or the factory "dtb" NAND partition dump (zlg-hwinfo/mtd1.bin).

Each fsl,pins entry is 6 cells: mux_reg conf_reg input_reg mux_mode
input_val pad_config. The first five identify the MX6UL_PAD_* macro.

Usage:
  decode-pinctrl.py --pinfunc work/linux/arch/arm/boot/dts/imx6ul-pinfunc.h \\
                    zlg-hwinfo/device-tree
"""

import argparse
import os
import re
import struct
import sys

NUM = r"(0x[0-9a-fA-F]+|[0-9]+)"
MACRO_RE = re.compile(r"#define\s+(MX6UL_PAD_\w+)" + (r"\s+" + NUM) * 5 + r"\s*$")


def num(text):
    return int(text, 16) if text.lower().startswith("0x") else int(text, 10)


def load_pinfunc(path):
    table = {}
    with open(path) as f:
        for line in f:
            m = MACRO_RE.match(line.strip())
            if m:
                key = tuple(num(x) for x in m.groups()[1:])
                table.setdefault(key, m.group(1))
    if not table:
        sys.exit("no MX6UL_PAD_* macros found in %s" % path)
    return table


def groups_from_fs(root):
    """Walk a /proc/device-tree copy, yield (node path, [cells])."""
    for dirpath, _dirs, files in os.walk(root):
        if "fsl,pins" in files:
            with open(os.path.join(dirpath, "fsl,pins"), "rb") as f:
                raw = f.read()
            cells = list(struct.unpack(">%dI" % (len(raw) // 4), raw))
            yield "/" + os.path.relpath(dirpath, root), cells


def groups_from_dtb(path):
    """Flattened DT blob (trailing data, e.g. a whole NAND partition, is fine)."""
    b = open(path, "rb").read()
    b = b[b.find(b"\xd0\x0d\xfe\xed"):]
    total, off_struct, off_str = struct.unpack(">III", b[4:16])
    b, stack, p = b[:total], [], off_struct
    while p + 4 <= len(b):
        tag = struct.unpack(">I", b[p:p + 4])[0]
        p += 4
        if tag == 1:
            e = b.index(b"\0", p)
            stack.append(b[p:e].decode(errors="replace"))
            p = (e + 4) & ~3
        elif tag == 2:
            stack.pop()
        elif tag == 3:
            ln, nameoff = struct.unpack(">II", b[p:p + 8])
            p += 8
            name = b[off_str + nameoff:b.index(b"\0", off_str + nameoff)]
            if name == b"fsl,pins" and ln % 4 == 0:
                yield ("/".join(stack) or "/"), list(
                    struct.unpack(">%dI" % (ln // 4), b[p:p + ln]))
            p = (p + ln + 3) & ~3
        elif tag == 9:
            break


def is_dtb(path):
    with open(path, "rb") as f:
        return b"\xd0\x0d\xfe\xed" in f.read(1 << 20)


def groups_from_dts(path):
    """Parse 'name { ... fsl,pins = <...>; ... }' out of decompiled dts text."""
    text = open(path).read()
    stack, pos = [], 0
    token = re.compile(r"([\w,.@+-]+)\s*\{|\}|fsl,pins\s*=\s*<([^>]*)>\s*;", re.S)
    for m in token.finditer(text):
        if m.group(0) == "}":
            if stack:
                stack.pop()
        elif m.group(1):
            stack.append(m.group(1))
        else:
            cells = [int(c, 0) for c in m.group(2).split()]
            yield "/" + "/".join(stack), cells


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--pinfunc", required=True, help="imx6ul-pinfunc.h from the kernel")
    ap.add_argument("source", help="/proc/device-tree copy (dir) or decompiled .dts")
    a = ap.parse_args()

    table = load_pinfunc(a.pinfunc)
    if os.path.isdir(a.source):
        groups = groups_from_fs(a.source)
    elif is_dtb(a.source):
        groups = groups_from_dtb(a.source)
    else:
        groups = groups_from_dts(a.source)

    n = 0
    for node, cells in groups:
        if len(cells) % 6:
            print("// %s: %d cells, not a multiple of 6 - skipped" % (node, len(cells)))
            continue
        n += 1
        name = node.rstrip("/").split("/")[-1].split("@")[0]
        print("// %s" % node)
        print("%s {\n\tfsl,pins = <" % name)
        for i in range(0, len(cells), 6):
            key, conf = tuple(cells[i:i + 5]), cells[i + 5]
            macro = table.get(key)
            if macro is None:
                macro = "/* unknown: 0x%x 0x%x 0x%x 0x%x 0x%x */" % key
            print("\t\t%-48s 0x%x" % (macro, conf))
        print("\t>;\n};\n")
    if not n:
        print("no fsl,pins properties found in %s" % a.source, file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
