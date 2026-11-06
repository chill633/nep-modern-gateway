#!/usr/bin/env python3
"""
Recompute ext4 group-descriptor checksums the way Linux does
(fs/ext4/super.c: ext4_group_desc_csum) and compare them with the stored
ones. That's the check behind "ext4_check_descriptors: Checksum for group N
failed" at boot.

Run it on the image and on the card, to see where a mismatch comes from:
  sudo tools/check-ext4-gdt.py out/m6g2c-debian-trixie-armhf.img   (finds p2)
  sudo tools/check-ext4-gdt.py /dev/sdX2                            (the card)
"""
import struct, sys

POLY = 0x82F63B78
TAB = []
for i in range(256):
    c = i
    for _ in range(8):
        c = (c >> 1) ^ POLY if c & 1 else c >> 1
    TAB.append(c)

def crc32c(seed, data):          # kernel ext4_chksum: raw update, no final xor
    c = seed
    for b in data:
        c = TAB[(c ^ b) & 0xFF] ^ (c >> 8)
    return c

if len(sys.argv) != 2:
    sys.exit(__doc__)
img = open(sys.argv[1], 'rb')
base = 0
mbr = img.read(512)
if mbr[510:512] == b'\x55\xaa' and mbr[0x1C2 + 16] == 0x83:   # whole image: use partition 2
    base = struct.unpack_from('<I', mbr, 0x1C6 + 16)[0] * 512
    print('whole disk image: checking partition 2 at byte offset %d' % base)
img.seek(base + 1024); sb = img.read(1024)
if struct.unpack_from('<H', sb, 0x38)[0] != 0xEF53:
    sys.exit('no ext4 superblock found')
blocks_lo, = struct.unpack_from('<I', sb, 0x04)
log_bs, = struct.unpack_from('<I', sb, 0x18)
bpg, = struct.unpack_from('<I', sb, 0x20)
compat, incompat, ro = struct.unpack_from('<III', sb, 0x5C)
uuid = sb[0x68:0x78]
desc_size, = struct.unpack_from('<H', sb, 0xFE)
blocks_hi, = struct.unpack_from('<I', sb, 0x150)
bs = 1024 << log_bs
ngroups = -(-((blocks_hi << 32) | blocks_lo) // bpg)
metadata_csum = bool(ro & 0x400); csum_seed_feat = bool(incompat & 0x2000)
seed = struct.unpack_from('<I', sb, 0x270)[0] if csum_seed_feat else crc32c(0xFFFFFFFF, uuid)
gdt = (1 if bs > 1024 else 2) * bs
img.seek(base + gdt); raw = img.read(ngroups * desc_size)
bad = 0
for g in range(ngroups):
    d = raw[g*desc_size:(g+1)*desc_size]
    stored, = struct.unpack_from('<H', d, 0x1E)
    c = crc32c(seed, struct.pack('<I', g))
    c = crc32c(c, d[:0x1E]); c = crc32c(c, b'\0\0'); c = crc32c(c, d[0x20:desc_size])
    calc = c & 0xFFFF
    if calc != stored: bad += 1
    if g < 3 or calc != stored:
        print('group %2d stored %5d  kernel-calc %5d  %s' % (g, stored, calc, 'ok' if calc == stored else 'MISMATCH'))
print('UUID %s  ' % uuid.hex() + 'metadata_csum=%s csum_seed=%s desc_size=%d groups=%d -> %d mismatches' % (metadata_csum, csum_seed_feat, desc_size, ngroups, bad))
sys.exit(1 if bad else 0)
