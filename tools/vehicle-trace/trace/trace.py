"""Vectorize the tractor and the LPG trailer from the mockup, 1:1.

1. Remove only the UI overlays that sit on the vehicles (axle label pills, leader lines, callout
   dots) by inpainting their few pixels from the surrounding artwork.
2. Crop each vehicle; make the page background transparent (flood fill from the crop border).
3. Upscale (Lanczos) so the tracer gets smooth edges, then trace to colour SVG with vtracer.
All coordinates are pixels of the original 2000x667 mockup.
"""
import os
import sys
import cv2
import numpy as np
import vtracer

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = sys.argv[1]
UPSCALE = int(os.environ.get('UPSCALE', 3))
PREC = int(os.environ.get('PREC', 6))
LDIFF = int(os.environ.get('LDIFF', 16))
SPECKLE = int(os.environ.get('SPECKLE', 8))
SUFFIX = os.environ.get('SUFFIX', '')

img = cv2.imread(SRC, cv2.IMREAD_COLOR)
mask = np.zeros(img.shape[:2], np.uint8)

def seg(x0, y0, x1, y1, w=9):
    cv2.line(mask, (x0, y0), (x1, y1), 255, w)

def box(x0, y0, x1, y1):
    cv2.rectangle(mask, (x0, y0), (x1, y1), 255, -1)

# --- tractor: axle leader lines (top/bottom) + the two inner-dual pills on the rear axle ---
for x in (191, 567, 606):
    seg(x, 140, x, 186)
    seg(x + (1 if x == 191 else 0), 432, x + (1 if x == 191 else 0), 472)
# "A2-R-IN" / "A2-L-IN": only vertical frame rails run under them, so each pixel column is
# rebuilt by blending the artwork just above and just below the pill (keeps the rails straight).
PILLS = [(634, 237, 703, 269), (634, 338, 703, 370)]
HUBS = [(618.75, 238.75), (618.75, 371.0)]  # inner brake hubs partly covered by the pills' left ends
HUB_R = 15.5
for (px0, py0, px1, py1), (hcx, hcy) in zip(PILLS, HUBS):
    top = img[py0 - 2, px0:px1].astype(np.float32)
    bot = img[py1 + 2, px0:px1].astype(np.float32)
    for yy in range(py0, py1 + 1):
        t = (yy - (py0 - 2)) / ((py1 + 2) - (py0 - 2))
        img[yy, px0:px1] = np.clip(top * (1 - t) + bot * t, 0, 255).astype(np.uint8)
    # covered right part of the round hub: mirror its own left half
    for yy in range(py0 - 1, py1 + 2):
        for xx in range(int(hcx), px0):
            if (xx - hcx) ** 2 + (yy - hcy) ** 2 <= HUB_R ** 2:
                img[yy, xx] = img[yy, int(round(2 * hcx - xx))]
            else:
                mask[yy, xx] = 255  # the few pixels between hub and rails: inpaint

# --- trailer: axle leader lines ---
for x in (1327, 1454, 1579):
    seg(x, 140, x, 186)
    seg(x, 436, x, 470)
# --- trailer: callout leader lines + their end dots ---
seg(820, 142, 864, 213); cv2.circle(mask, (863, 212), 7, 255, -1)    # manhole
seg(983, 142, 990, 196); cv2.circle(mask, (990, 194), 7, 255, -1)    # safety valve
seg(1148, 142, 1119, 203); cv2.circle(mask, (1120, 202), 7, 255, -1)  # level gauge
seg(777, 378, 752, 470); cv2.circle(mask, (777, 378), 7, 255, -1)    # coupling lines
seg(946, 423, 934, 470); cv2.circle(mask, (946, 423), 7, 255, -1)    # valve cabinet
seg(1067, 405, 1070, 470); cv2.circle(mask, (1067, 405), 7, 255, -1)  # emergency valve
seg(1142, 423, 1172, 470); cv2.circle(mask, (1142, 423), 7, 255, -1)  # grounding

clean = cv2.inpaint(img, mask, 4, cv2.INPAINT_TELEA)
cv2.imwrite(os.path.join(HERE, 'clean.png'), clean)
cv2.imwrite(os.path.join(HERE, 'mask.png'), mask)

VEHICLES = {
    # name: crop box (x0, y0, x1, y1) in mockup pixels
    'tractor': (14, 146, 703, 472),
    'trailer': (699, 150, 1702, 466),
}

def cutout(crop):
    """BGRA crop with the page background (connected to the border) made transparent."""
    h, w = crop.shape[:2]
    ff = np.zeros((h + 2, w + 2), np.uint8)
    work = crop.copy()
    flags = 4 | cv2.FLOODFILL_MASK_ONLY | cv2.FLOODFILL_FIXED_RANGE | (255 << 8)
    for sx, sy in [(0, 0), (w - 1, 0), (0, h - 1), (w - 1, h - 1), (w // 2, 0), (w // 2, h - 1)]:
        if ff[sy + 1, sx + 1] == 0:
            cv2.floodFill(work, ff, (sx, sy), 0, (14, 14, 14), (14, 14, 14), flags)
    bg = ff[1:-1, 1:-1] > 0
    alpha = np.where(bg, 0, 255).astype(np.uint8)
    # soften the cut a little so the glow keeps a clean edge
    alpha = cv2.GaussianBlur(alpha, (3, 3), 0)
    # the outer glow fades into the page: fade it out towards the crop border so no straight edge shows
    yy, xx = np.mgrid[0:h, 0:w]
    edge = np.minimum(np.minimum(xx, w - 1 - xx), np.minimum(yy, h - 1 - yy)).astype(np.float32)
    alpha = (alpha.astype(np.float32) * np.clip(edge / 9.0, 0, 1)).astype(np.uint8)
    return np.dstack([crop, alpha])

for name, (x0, y0, x1, y1) in VEHICLES.items():
    crop = clean[y0:y1, x0:x1]
    rgba = cutout(crop)
    big = cv2.resize(rgba, ((x1 - x0) * UPSCALE, (y1 - y0) * UPSCALE), interpolation=cv2.INTER_LANCZOS4)
    png = os.path.join(HERE, f'{name}-clean.png')
    cv2.imwrite(png, big)
    out = os.path.join(HERE, f'{name}-raw{SUFFIX}.svg')
    vtracer.convert_image_to_svg_py(
        png, out,
        colormode='color', hierarchical='stacked', mode='spline',
        filter_speckle=SPECKLE, color_precision=PREC, layer_difference=LDIFF,
        corner_threshold=60, length_threshold=4.0, max_iterations=10,
        splice_threshold=45, path_precision=1,
    )
    print(name, os.path.getsize(out) // 1024, 'KB')
