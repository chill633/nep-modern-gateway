#!/usr/bin/env python3
"""
Work out which BCH ECC layout was used to write an i.MX6 GPMI NAND page, from a
raw dump (no ECC) of that page. Use it when Linux reports "ECC error" (-74)
for every page: the flash was written with a different BCH strength than the
one the kernel is using, or the reads themselves are bad.

Make the dump on the board, of the first page of the UBI partition (it holds
the UBI erase-counter header) and, for a second opinion, of the dtb partition:

  sudo nanddump --noecc --oob -s 0 -l 2048 -f /tmp/mtd3-raw.bin /dev/mtd3
  sudo nanddump --noecc --oob -s 0 -l 2048 -f /tmp/mtd1-raw.bin /dev/mtd1
  tools/gpmi-raw-analyze.py /tmp/mtd3-raw.bin /tmp/mtd1-raw.bin

GPMI raw page layout (S34ML02G2: 2048 data + 128 OOB bytes):
  10 bytes metadata | 512 data | ECC0 | 512 data | ECC1 | ... (4 chunks)
Each ECC block is gf_len (13) x strength bits, packed with no byte alignment.

Linux's gpmi driver doesn't return that layout for "raw" reads. It splits the
page with its *own* BCH geometry (gpmi_ecc_read_page_raw): data chunks
packed into 2048 bytes; metadata, ECC bits and the rest in the OOB; the
bad-block-marker byte swapped back. This tool undoes that split
(--kernel-strength, default 18, the 6.1 legacy layout for this chip) to get
the physical page back, then analyzes it.
"""
import sys

PAGE, OOB = 2048, 128
RAW = PAGE + OOB
META, CHUNK, GF = 10, 512, 13
NCHUNK = PAGE // CHUNK
SIGS = {b"UBI#": "UBI erase-counter header",
        b"UBI!": "UBI volume-ID header",
        b"\xd0\x0d\xfe\xed": "device tree blob"}


def get_bits(buf, off, n):
    return [(buf[(off + i) >> 3] >> ((off + i) & 7)) & 1 for i in range(n)]


def put_bits(buf, off, bits):
    for i, b in enumerate(bits):
        j = off + i
        buf[j >> 3] = (buf[j >> 3] & ~(1 << (j & 7))) | (b << (j & 7))


def physical_from_linux_raw(dump, kstrength):
    """Invert gpmi_ecc_read_page_raw() (Linux 6.1) for strength kstrength."""
    data, oob = dump[:PAGE], dump[PAGE:RAW]
    raw = bytearray(RAW)
    raw[:META] = oob[:META]
    oob_bit = src_bit = META * 8
    eccbits = GF * kstrength
    for step in range(NCHUNK):
        put_bits(raw, src_bit, get_bits(data, step * CHUNK * 8, CHUNK * 8))
        src_bit += CHUNK * 8
        if step == NCHUNK - 1 and (oob_bit + eccbits) % 8:
            eccbits += 8 - (oob_bit + eccbits) % 8
        put_bits(raw, src_bit, get_bits(oob, oob_bit, eccbits))
        src_bit += eccbits
        oob_bit += eccbits
    ob = oob_bit // 8
    if ob < OOB:
        raw[PAGE + ob:] = oob[ob:]
    raw[0], raw[PAGE] = raw[PAGE], raw[0]       # undo the block-mark swap
    return bytes(raw)


def bits_of(page):
    """LSB-first bit list of the raw page (the order the GPMI BCH engine uses)."""
    return [(page[i >> 3] >> (i & 7)) & 1 for i in range(len(page) * 8)]


def analyze(path, kstrength):
    data = open(path, "rb").read()
    if len(data) < RAW:
        print("%s: only %d bytes; need one page with OOB (%d). Use nanddump --oob."
              % (path, len(data), RAW))
        return
    page = data[:RAW]
    print("== %s" % path)
    if any(page.startswith(s) for s in SIGS):
        print("  Data starts at offset 0: this is a Linux gpmi 'raw' read, split with"
              " the kernel's strength-%d layout. Rebuilding the physical page." % kstrength)
        page = physical_from_linux_raw(page, kstrength)

    hits = [(page.find(s), s, d) for s, d in SIGS.items() if page.find(s) >= 0]
    if not hits:
        print("  No UBI or device-tree signature anywhere in the raw page.")
        print("  => the raw read itself looks garbled: suspect NAND timing or")
        print("     pinmux rather than the ECC layout (or the page is erased: %s)."
              % ("it is all 0xFF" if page == b"\xff" * RAW else "it is not erased"))
        return
    off, sig, desc = min(hits)
    print("  %s found at raw byte offset %d" % (desc, off))
    if off != META:
        print("  Expected offset %d (after the %d-byte metadata). The layout differs"
              " more than in ECC strength; send this output." % (META, META))
        return
    print("  => raw reads are clean (chunk 0 sits where expected), so wiring and")
    print("     timing are fine.")

    if sig not in (b"UBI#", b"UBI!"):
        print("  (A device-tree page is full of data, so the strength can't be read")
        print("   from it; use the UBI partition's first page for that.)")
        return

    # UBI headers are 64 bytes, then the rest of the 2048-byte page is filler.
    fill = page[META + 64:META + CHUNK]
    fv = fill[0]
    if fill != bytes([fv]) * len(fill):
        print("  The header page isn't padded uniformly; can't infer the strength.")
        return
    fbit = fv & 1
    bits = bits_of(page)
    # The bad-block-marker byte (raw byte 2048) holds 0xFF regardless: ignore it.
    skip = set(range(PAGE * 8, PAGE * 8 + 8))

    def region_is(lo, hi, val):
        return all(bits[i] == val for i in range(lo, hi) if i not in skip)

    fits = []
    for t in range(2, 42, 2):
        ecc_bits = GF * t
        total = META * 8 + NCHUNK * (CHUNK * 8 + ecc_bits)
        if total > RAW * 8:
            break
        ok = True
        pos = META * 8 + CHUNK * 8            # end of chunk 0 data
        ecc0 = bits[pos:pos + ecc_bits]
        if all(b == fbit for b in ecc0):      # ECC over a real header can't be filler
            ok = False
        pos += ecc_bits
        for _ in range(1, NCHUNK):            # chunks 1..3 hold only filler
            if not region_is(pos, pos + CHUNK * 8, fbit):
                ok = False
                break
            pos += CHUNK * 8 + ecc_bits
        if ok:
            fits.append(t)

    print("  Header padding byte: 0x%02x" % fv)
    if not fits:
        print("  No even BCH strength 2..40 fits this page; send this output.")
        return
    print("  BCH strengths consistent with this page: %s" % ", ".join(map(str, fits)))
    best = fits[0] if len(fits) == 1 else None
    legacy = ((OOB - META) * 8 // (GF * NCHUNK)) // 2 * 2
    print("  (Linux 6.1's default 'legacy' layout for this chip is strength %d;"
          " the chip's own minimum is 4.)" % legacy)
    if best == legacy:
        print("  => written with strength %d, the same as Linux 6.1 uses. The cause is"
              " something else in the BCH setup; send this output." % best)
    elif best:
        print("  => the flash was written with strength %d; Linux 6.1 reads it with %d."
              " That mismatch causes the ECC errors." % (best, legacy))
    else:
        print("  => several strengths fit; dump a page with more content to narrow"
              " it down.")


if __name__ == "__main__":
    args = sys.argv[1:]
    ks = 18
    if args[:1] == ["--kernel-strength"] and len(args) > 1:
        ks, args = int(args[1]), args[2:]
    if not args:
        sys.exit(__doc__)
    for p in args:
        analyze(p, ks)
