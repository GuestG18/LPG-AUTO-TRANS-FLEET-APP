"""Exact outline of every traced part, for the explorer's selection outline and condition colour.

From the part renders of rasterize.js (trace/out/raster/parts/<veh>-<id>@2.png, 4 px per mockup pixel):
alpha -> external contours -> simplified polygons, written as SVG path data in mockup pixels (the same
coordinates as the part boxes, times 4: integers in quarter mockup pixels). Output: trace/out/silhouettes.json
  { "<veh>": { "<part id>": "M x y L ... Z M ..." } }
usage: python silhouettes.py
"""
import json
import os

import cv2
import numpy as np

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, 'trace', 'out')
RASTER = os.path.join(OUT, 'raster', 'parts')
man = json.load(open(os.path.join(OUT, 'parts.json'), encoding='utf-8'))
CROP = {'tractor': (14, 146), 'trailer': (699, 150)}
SCALE = 4.0          # raster px per mockup px (part SVG = 2 units / px, rendered at 2x)
MIN_AREA = 10 * 10   # raster px^2: specks of glow are not part of the silhouette
EPS = 2.5            # raster px (0.6 mockup px): polygon simplification, below the artwork's own pixel

result = {}
points = 0
for veh, entry in man.items():
    x0c, y0c = CROP.get(veh, tuple(entry.get('crop', [0, 146])[:2]))
    paths = {}
    for p in entry['parts']:
        if p['id'].startswith('tyre-'):
            continue
        png = os.path.join(RASTER, os.path.basename(p['file'])[:-4] + '@2.png')
        img = cv2.imread(png, cv2.IMREAD_UNCHANGED)
        if img is None or img.shape[2] < 4:
            continue
        mask = (img[:, :, 3] >= 128).astype(np.uint8) * 255
        mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, np.ones((3, 3), np.uint8))
        contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_NONE)
        # part box origin in mockup px (manifest x/y: 2 units per px, relative to the vehicle crop)
        bx = x0c + p['x'] / 2
        by = y0c + p['y'] / 2
        segs = []
        largest = max([cv2.contourArea(c) for c in contours] or [0])
        for c in contours:
            # islands under 3 % of the main piece are glow specks / fragments, not the part's outline
            if cv2.contourArea(c) < max(MIN_AREA, largest * 0.03):
                continue
            poly = cv2.approxPolyDP(c, EPS, True).reshape(-1, 2)
            if len(poly) < 3:
                continue
            points += len(poly)
            # integers in quarter mockup pixels (the explorer scales the path by 0.25): short and exact enough
            segs.append('M' + ' '.join('%d %d' % (int(round(bx * SCALE + px)), int(round(by * SCALE + py))) for px, py in poly) + 'Z')
        if segs:
            paths[p['id']] = ' '.join(segs)
    result[veh] = paths

with open(os.path.join(OUT, 'silhouettes.json'), 'w', encoding='utf-8') as f:
    json.dump(result, f, separators=(',', ':'))
print('silhouettes', sum(len(v) for v in result.values()), 'parts,', points, 'points,', os.path.getsize(os.path.join(OUT, 'silhouettes.json')) // 1024, 'KB')
