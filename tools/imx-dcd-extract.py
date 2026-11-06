#!/usr/bin/env python3
"""
Find i.MX6 boot images (IVT + DCD) in a binary - ZLG's u-boot.imx, a NAND
dump made with tools/collect-zlg-hwinfo.sh, or a raw SD-card image - and
print the DDR set-up they contain.

The factory boot loader's DCD holds DDR settings that were calibrated for this
board. This tool decodes the MMDC configuration and prints the four
calibration registers in the form scripts/01-uboot.sh accepts:

    ./tools/imx-dcd-extract.py zlg-hwinfo/mtd0.bin
    DDR_MPWLDECTRL0=0x... DDR_MPDGCTRL0=0x... ... ./build.sh uboot

Usage: imx-dcd-extract.py [--all] [--imximage] FILE
"""

import argparse
import struct
import sys

IVT_TAG, DCD_TAG = 0xD1, 0xD2
CMD_WRITE, CMD_CHECK, CMD_NOP, CMD_UNLOCK = 0xCC, 0xCF, 0xC0, 0xB2

MMDC = 0x021B0000
REGS = {
    MMDC + 0x000: "MDCTL", MMDC + 0x004: "MDPDC", MMDC + 0x008: "MDOTC",
    MMDC + 0x00C: "MDCFG0", MMDC + 0x010: "MDCFG1", MMDC + 0x014: "MDCFG2",
    MMDC + 0x018: "MDMISC", MMDC + 0x01C: "MDSCR", MMDC + 0x020: "MDREF",
    MMDC + 0x02C: "MDRWD", MMDC + 0x030: "MDOR", MMDC + 0x040: "MDASP", MMDC + 0x404: "MAPSR", MMDC + 0x800: "MPZQHWCTRL",
    MMDC + 0x80C: "MPWLDECTRL0", MMDC + 0x810: "MPWLDECTRL1",
    MMDC + 0x818: "MPODTCTRL", MMDC + 0x81C: "MPRDDQBY0DL",
    MMDC + 0x820: "MPRDDQBY1DL", MMDC + 0x82C: "MPWRDQBY0DL",
    MMDC + 0x830: "MPWRDQBY1DL", MMDC + 0x83C: "MPDGCTRL0",
    MMDC + 0x840: "MPDGCTRL1", MMDC + 0x848: "MPRDDLCTL",
    MMDC + 0x850: "MPWRDLCTL", MMDC + 0x890: "MPPDCMPR2",
    MMDC + 0x8B8: "MPMUR0",
    0x020C4068: "CCM_CCGR0", 0x020C406C: "CCM_CCGR1", 0x020C4070: "CCM_CCGR2",
    0x020C4074: "CCM_CCGR3", 0x020C4078: "CCM_CCGR4", 0x020C407C: "CCM_CCGR5",
    0x020C4080: "CCM_CCGR6",
    0x020E04B4: "IOMUXC_GRP_DDR_TYPE", 0x020E04AC: "IOMUXC_GRP_DDRPKE",
    0x020E027C: "IOMUXC_DRAM_SDCLK0", 0x020E0250: "IOMUXC_DRAM_CAS",
    0x020E024C: "IOMUXC_DRAM_RAS", 0x020E0490: "IOMUXC_GRP_ADDDS",
    0x020E0288: "IOMUXC_DRAM_RESET", 0x020E0270: "IOMUXC_DRAM_SDBA2",
    0x020E0260: "IOMUXC_DRAM_ODT0", 0x020E0264: "IOMUXC_DRAM_ODT1",
    0x020E04A0: "IOMUXC_GRP_CTLDS", 0x020E0494: "IOMUXC_GRP_DDRMODE_CTL",
    0x020E0280: "IOMUXC_DRAM_SDQS0", 0x020E0284: "IOMUXC_DRAM_SDQS1",
    0x020E04B0: "IOMUXC_GRP_DDRMODE", 0x020E0498: "IOMUXC_GRP_B0DS",
    0x020E04A4: "IOMUXC_GRP_B1DS", 0x020E0244: "IOMUXC_DRAM_DQM0",
    0x020E0248: "IOMUXC_DRAM_DQM1",
}
CALIB = ("MPWLDECTRL0", "MPDGCTRL0", "MPRDDLCTL", "MPWRDLCTL")


def be16(b, o):
    return struct.unpack_from(">H", b, o)[0]


def be32(b, o):
    return struct.unpack_from(">I", b, o)[0]


def le32(b, o):
    return struct.unpack_from("<I", b, o)[0]


def find_ivts(data):
    """Yield (offset, ivt dict) for every plausible IVT in data."""
    pos = 0
    while True:
        pos = data.find(bytes([IVT_TAG, 0x00, 0x20]), pos)
        if pos < 0 or pos + 32 > len(data):
            return
        ver = data[pos + 3]
        if 0x40 <= ver <= 0x43:
            entry, _, dcd, boot_data, self_ptr, csf = struct.unpack_from(
                "<6I", data, pos + 4)
            if self_ptr and (dcd == 0 or 0 < dcd - self_ptr < 0x2000):
                yield pos, dict(entry=entry, dcd=dcd, boot_data=boot_data,
                                self=self_ptr, csf=csf, version=ver)
        pos += 1


def parse_dcd(data, off):
    """Return a list of (command, params) from the DCD at data[off]."""
    if data[off] != DCD_TAG:
        raise ValueError("no DCD header at 0x%x" % off)
    length = be16(data, off + 1)
    end = off + length
    if end > len(data) or length < 4:
        raise ValueError("bad DCD length %d" % length)
    cmds, p = [], off + 4
    while p < end:
        tag, clen, param = data[p], be16(data, p + 1), data[p + 3]
        if clen < 4 or p + clen > end:
            raise ValueError("bad DCD command at 0x%x" % p)
        body = data[p + 4:p + clen]
        if tag == CMD_WRITE:
            width, flags = param & 7, param >> 3
            pairs = [(be32(body, i), be32(body, i + 4))
                     for i in range(0, len(body) - 7, 8)]
            cmds.append(("write", dict(width=width, flags=flags, pairs=pairs)))
        elif tag == CMD_CHECK:
            addr, mask = be32(body, 0), be32(body, 4)
            count = be32(body, 8) if len(body) >= 12 else None
            cmds.append(("check", dict(width=param & 7, flags=param >> 3,
                                       addr=addr, mask=mask, count=count)))
        elif tag == CMD_NOP:
            cmds.append(("nop", {}))
        elif tag == CMD_UNLOCK:
            cmds.append(("unlock", dict(param=param, body=body.hex())))
        else:
            cmds.append(("unknown", dict(tag=tag, body=body.hex())))
        p += clen
    return cmds


def final_writes(cmds):
    """Last value written to each address (plain 32-bit writes only)."""
    regs = {}
    for name, c in cmds:
        if name == "write" and c["flags"] == 0:
            for a, v in c["pairs"]:
                regs[a] = v
    return regs


def decode_mmdc(regs):
    out = []
    mdctl = regs.get(MMDC + 0x000)
    if mdctl is not None:
        rows = {0: 11, 1: 12, 2: 13, 3: 14, 4: 15, 5: 16}.get((mdctl >> 24) & 7)
        cols = {0: 9, 1: 10, 2: 11, 3: 8, 4: 12}.get((mdctl >> 20) & 7)
        bl = 8 if mdctl & (1 << 19) else 4
        width = {0: 16, 1: 32, 2: 64}.get((mdctl >> 16) & 3)
        cs = ("CS0" if mdctl >> 31 & 1 else "") + (" CS1" if mdctl >> 30 & 1 else "")
        out.append("MDCTL  0x%08x: %s, %s row / %s col bits, BL%d, %s-bit bus"
                   % (mdctl, cs.strip() or "no CS", rows, cols, bl, width))
        misc = regs.get(MMDC + 0x018)
        banks = 4 if (misc is not None and misc & (1 << 5)) else 8
        if rows and cols and width:
            size = (1 << (rows + cols)) * banks * (width // 8)
            out.append("       => %d MiB per chip select (%d banks)"
                       % (size >> 20, banks))
    mdasp = regs.get(MMDC + 0x040)
    if mdasp is not None:
        end_mb = ((mdasp & 0x7F) + 1) * 32          # CS0_END in 32 MiB units
        out.append("MDASP  0x%08x: CS0 ends at 0x%08x => %d MiB of DRAM"
                   % (mdasp, (end_mb << 20) & 0xFFFFFFFF, end_mb - 2048))
    cfg0 = regs.get(MMDC + 0x00C)
    if cfg0 is not None:
        out.append("MDCFG0 0x%08x: tRFC=%d tXS=%d tCL=%d (clocks)"
                   % (cfg0, (cfg0 >> 24) + 1, ((cfg0 >> 16) & 0xFF) + 1,
                      (cfg0 & 0xF) + 3))
    return out


def report(data, path, show_all, imximage):
    found = 0
    for off, ivt in find_ivts(data):
        if not ivt["dcd"]:
            continue
        dcd_off = off + (ivt["dcd"] - ivt["self"])
        try:
            cmds = parse_dcd(data, dcd_off)
        except (ValueError, struct.error, IndexError):
            continue
        found += 1
        regs = final_writes(cmds)
        print("== %s: IVT v%x at file offset 0x%x, entry 0x%08x, DCD at 0x%x "
              "(%d commands)" % (path, ivt["version"], off, ivt["entry"],
                                 dcd_off, len(cmds)))
        for line in decode_mmdc(regs):
            print("   " + line)
        print("   Calibration (board/zlg/m6g2c/m6g2c.c):")
        for name in CALIB:
            addr = next(a for a, n in REGS.items() if n == name)
            val = regs.get(addr)
            print("     %-12s %s" % (name, "0x%08x" % val if val is not None
                                     else "(not set by this DCD)"))
        env = " ".join("DDR_%s=0x%08x" % (n, regs[a]) for a, n in REGS.items()
                       if n in CALIB and a in regs)
        if env:
            print("   Build with:\n     %s ./build.sh uboot" % env)
        if show_all or imximage:
            print("   DCD commands:")
            for name, c in cmds:
                if name == "write":
                    op = {0: "DATA", 1: "CLR_BIT", 3: "SET_BIT"}.get(
                        c["flags"], "WRITE(flags=%d)" % c["flags"])
                    for a, v in c["pairs"]:
                        label = REGS.get(a, "")
                        if imximage:
                            print("     %s %d 0x%08x 0x%08x%s" % (
                                op, c["width"], a, v,
                                ("  /* %s */" % label) if label else ""))
                        else:
                            print("     %-8s 0x%08x = 0x%08x  %s" % (op, a, v, label))
                elif name == "check":
                    print("     CHECK    0x%08x mask 0x%08x flags %d count %s"
                          % (c["addr"], c["mask"], c["flags"], c["count"]))
                else:
                    print("     %s %s" % (name.upper(), c))
        print()
    return found


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("files", nargs="+")
    ap.add_argument("--all", action="store_true", help="list every DCD command")
    ap.add_argument("--imximage", action="store_true",
                    help="list commands in imximage.cfg syntax")
    a = ap.parse_args()
    total = 0
    for path in a.files:
        with open(path, "rb") as f:
            total += report(f.read(), path, a.all, a.imximage)
    if not total:
        print("No IVT with a DCD found. If this is a NAND dump, make sure it was "
              "read through the MTD driver (nanddump/dd on /dev/mtdN), not raw.")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
