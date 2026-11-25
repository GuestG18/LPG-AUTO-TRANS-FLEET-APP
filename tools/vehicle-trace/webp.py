"""PNG renders (rasterize.js) -> WebP with alpha for the app.

usage: python webp.py <src_dir> <dst_dir> [quality] [--gray] [--skip <subdir>]
--gray: neutral grayscale (luminance, alpha kept), done once here instead of a runtime CSS filter.
--skip: leave a sub-folder out (e.g. the part renders, not shipped since the explorer uses silhouettes).
"""
import os
import sys

import cv2
import numpy as np

args = sys.argv[1:]
gray = '--gray' in args
skip = None
if '--skip' in args:
    skip = args[args.index('--skip') + 1]
plain = [a for i, a in enumerate(args) if not a.startswith('--') and (i == 0 or args[i - 1] != '--skip')]
src, dst = plain[0], plain[1]
quality = int(plain[2]) if len(plain) > 2 else 90
total = 0
for root, dirs, files in os.walk(src):
    if skip and skip in dirs:
        dirs.remove(skip)
    for name in files:
        if not name.endswith('.png'):
            continue
        img = cv2.imread(os.path.join(root, name), cv2.IMREAD_UNCHANGED)
        if gray:
            g = cv2.cvtColor(img[:, :, :3], cv2.COLOR_BGR2GRAY)
            img = np.dstack([g, g, g, img[:, :, 3]]) if img.shape[2] == 4 else cv2.cvtColor(g, cv2.COLOR_GRAY2BGR)
        out_dir = os.path.join(dst, os.path.relpath(root, src))
        os.makedirs(out_dir, exist_ok=True)
        out = os.path.join(out_dir, name[:-4] + '.webp')
        cv2.imwrite(out, img, [cv2.IMWRITE_WEBP_QUALITY, quality])
        total += os.path.getsize(out)
print('webp total', total // 1024, 'KB', '(grayscale)' if gray else '')
