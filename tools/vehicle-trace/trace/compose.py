"""Compose the other fleet vehicles only from pieces of the approved, traced mockup (no new art).

The approved tractor 4x2 and LPG trailer (3 axles) are cut into vertical slices (cab + engine,
drive axle, tank section, each trailer axle, rear end). A vehicle = slices laid side by side with a
short cross-fade at each seam. Both mockup vehicles share the same vertical band (centreline row 308),
so slices line up without any vertical shift. Trucks get the trailer's tank recoloured from
trailer-violet to motor-vehicle blue; single-tyre axles reuse the tractor's front tyre.

Every part outline from parts.py travels with its slice (shifted, axle renumbered), so each composed
vehicle gets the same per-part SVGs as the approved ones.

usage: python compose.py [name ...]      (default: all)
"""
import copy
import os
import re
import sys

import cv2
import numpy as np
import vtracer

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import parts as P  # noqa: E402

OUT = os.path.join(HERE, 'out')
SRC = cv2.imread(os.path.join(HERE, 'clean.png'), cv2.IMREAD_COLOR)
Y0, Y1 = 146, 472          # vertical band of both vehicles in the mockup
OVERLAP = 10               # cross-fade width at each seam (mockup px)
MARGIN = 14                # page background left/right of the vehicle
BAND = {'T': (146, 468), 'R': (150, 466)}  # rows of each source vehicle (labels outside)

# slice name: (source, x0, x1) in mockup pixels. T = tractor parts list, R = trailer parts list.
SLICES = {
    'T_front': ('T', 14, 505),     # cab, steering axle A1, radiator, engine, gearbox, battery, fuel tank, AdBlue
    'T_drive': ('T', 505, 665),    # drive axle (dual), differential, brakes, air springs
    'R_front': ('R', 699, 1265),   # kingpin end, tank front, LPG equipment, cabinets
    'R_tank': ('R', 800, 1250),    # tank section with the LPG equipment and the full "GPL (LPG)" sign (truck body)
    'R_ax1': ('R', 1265, 1391),
    'R_ax2': ('R', 1391, 1516),
    'R_ax3': ('R', 1516, 1641),
    'R_rear': ('R', 1641, 1702),   # tank rear end
}

# Each step: (slice, {source axle -> new axle}, options). 'blue' = recolour trailer violet to blue,
# 'single' = replace the dual tyres of a trailer axle column by the tractor's single front tyre.
RECIPES = {
    'camion-2': [('T_front', {}, {}), ('R_tank', {}, {'blue': True}), ('T_drive', {}, {}), ('R_rear', {}, {'blue': True})],
    'camion-3': [('T_front', {}, {}), ('R_tank', {}, {'blue': True}), ('T_drive', {}, {}),
                 ('R_ax2', {2: 3}, {'blue': True, 'single': True}), ('R_rear', {}, {'blue': True})],
    'camion-4': [('T_front', {}, {}), ('R_ax2', {2: 2}, {'blue': True, 'single': True}), ('R_tank', {}, {'blue': True}),
                 ('T_drive', {2: 3}, {}), ('R_ax2', {2: 4}, {'blue': True, 'single': True}), ('R_rear', {}, {'blue': True})],
    'semi-2': [('R_front', {}, {}), ('R_ax1', {}, {}), ('R_ax3', {3: 2}, {}), ('R_rear', {}, {})],
    'semi-4': [('R_front', {}, {}), ('R_ax1', {}, {}), ('R_ax2', {}, {}), ('R_ax2', {2: 3}, {}), ('R_ax3', {3: 4}, {}), ('R_rear', {}, {})],
    'semi-6': [('R_front', {}, {}), ('R_ax1', {}, {}), ('R_ax2', {}, {}), ('R_ax2', {2: 3}, {}), ('R_ax2', {2: 4}, {}),
               ('R_ax2', {2: 5}, {}), ('R_ax3', {3: 6}, {}), ('R_rear', {}, {})],
}

# tractor front tyres (single), used for single-tyre axles: source rrect, mask radius
SINGLE_TYRE = {'R': (140, 171, 253, 216), 'L': (140, 395, 253, 443)}
# where a trailer axle column has its dual pair, relative to the axle centre
PAIR_BOX = {'R': (-55, 174, 56, 226), 'L': (-55, 398, 56, 443)}
TRAILER_AXLE_X = {1: 1327, 2: 1454, 3: 1577}


def to_blue(bgr):
    """Trailer violet / lavender -> motor-vehicle blue (hue only; light and detail unchanged)."""
    hsv = cv2.cvtColor(bgr, cv2.COLOR_BGR2HSV).astype(np.int32)
    h, s = hsv[..., 0], hsv[..., 1]
    sel = (h >= 117) & (h <= 165) & (s >= 15)
    h[sel] = 100 + (h[sel] - 117) * 12 // 48
    return cv2.cvtColor(hsv.astype(np.uint8), cv2.COLOR_HSV2BGR)


def shape_xrange(shape):
    kind = shape[0]
    if kind in ('rect', 'rrect'):
        return shape[1], shape[3]
    if kind == 'circle':
        return shape[1] - shape[3], shape[1] + shape[3]
    pts = shape[1]
    pad = shape[2] / 2 if kind == 'line' else 0
    return min(p[0] for p in pts) - pad, max(p[0] for p in pts) + pad


def move_shape(shape, dx, x0, x1):
    """Shift a mask shape by dx; a plain rect crossing the slice edge is clipped to the slice."""
    kind = shape[0]
    if kind == 'rect':
        return ('rect', max(shape[1], x0) + dx, shape[2], min(shape[3], x1) + dx, shape[4])
    if kind == 'rrect':
        return ('rrect', shape[1] + dx, shape[2], shape[3] + dx, shape[4], shape[5])
    if kind == 'circle':
        return ('circle', shape[1] + dx, shape[2], shape[3])
    if kind == 'poly':
        return ('poly', [(x + dx, y) for x, y in shape[1]])
    return ('line', [(x + dx, y) for x, y in shape[1]], shape[2])


def renumber(text, axles):
    if not axles:
        return text
    text = re.sub(r'-a(\d)(?=-|$)', lambda m: '-a%d' % axles.get(int(m.group(1)), int(m.group(1))), text)
    return re.sub(r'\bA(\d)\b', lambda m: 'A%d' % axles.get(int(m.group(1)), int(m.group(1))), text)


def carried_specs(source, x0, x1, dx, axles, single):
    """Part specs lying in [x0, x1] of the source vehicle, moved to the composed vehicle."""
    specs = []
    for spec in (P.TRACTOR if source == 'T' else P.TRAILER):
        pid = spec[0]
        if single and pid.startswith('tyre-'):
            continue  # replaced by single tyres below
        ranges = [shape_xrange(s) for s in spec[3]]
        inside = all(a >= x0 - 2 and b <= x1 + 2 for a, b in ranges)
        # a plain rect crossing the seam is clipped, if a real piece of it (>= 12 px) is in the slice
        clippable = all(s[0] == 'rect' for s in spec[3]) and any(min(b, x1) - max(a, x0) >= 12 for a, b in ranges)
        if not inside and not clippable:
            continue
        shapes = [move_shape(s, dx, x0, x1) for s in spec[3] if shape_xrange(s)[1] > x0 and shape_xrange(s)[0] < x1]
        new = [renumber(pid, axles), renumber(spec[1], axles), spec[2], shapes, spec[4]]
        if len(spec) > 5:
            opts = copy.deepcopy(spec[5])
            if 'minus' in opts:
                opts['minus'] = [renumber(m, axles) for m in opts['minus']]
            new.append(opts)
        specs.append(tuple(new))
    return specs


def compose(name):
    steps = RECIPES[name]
    width = sum(SLICES[s][2] - SLICES[s][1] for s, _, _ in steps) - OVERLAP * (len(steps) - 1) + 2 * MARGIN
    bg = SRC[5, 5].astype(np.float32)
    canvas = np.empty((SRC.shape[0], width, 3), np.float32)
    canvas[:] = bg
    specs = []
    cursor = MARGIN
    for index, (slice_name, axles, opts) in enumerate(steps):
        source, x0, x1 = SLICES[slice_name]
        piece = SRC[:, x0:x1].copy()
        # the mockup's label pills start just outside each vehicle's band: keep only the vehicle
        top, bottom = BAND[source]
        piece[:top] = SRC[5, 5]
        piece[bottom:] = SRC[5, 5]
        if opts.get('blue'):
            piece = to_blue(piece)
        dest = cursor - (OVERLAP if index else 0)
        dx = dest - x0
        if opts.get('single'):
            ax_src = TRAILER_AXLE_X[[k for k, v in TRAILER_AXLE_X.items() if x0 <= v <= x1][0]]
            for side in ('R', 'L'):
                sx0, sy0, sx1, sy1 = SINGLE_TYRE[side]
                px0, py0, px1, py1 = PAIR_BOX[side]
                tw, th = px1 - px0, py1 - py0
                tyre = cv2.resize(SRC[sy0:sy1, sx0:sx1], (tw, th), interpolation=cv2.INTER_CUBIC)
                m = np.zeros((th, tw), np.uint8)
                r = 12
                cv2.rectangle(m, (r, 0), (tw - r - 1, th - 1), 255, -1)
                cv2.rectangle(m, (0, r), (tw - 1, th - r - 1), 255, -1)
                for cx, cy in ((r, r), (tw - r - 1, r), (r, th - r - 1), (tw - r - 1, th - r - 1)):
                    cv2.circle(m, (cx, cy), r, 255, -1)
                a = (cv2.GaussianBlur(m, (3, 3), 0).astype(np.float32) / 255)[..., None]
                lx = ax_src + px0 - x0
                region = piece[py0:py1, lx:lx + tw].astype(np.float32)
                piece[py0:py1, lx:lx + tw] = (tyre * a + region * (1 - a)).astype(np.uint8)
                new_axle = axles.get([k for k, v in TRAILER_AXLE_X.items() if v == ax_src][0])
                cx = ax_src + dx
                specs.append(('tyre-a%d-%s' % (new_axle, side.lower()), 'Anvelopă A%d-%s' % (new_axle, side), 'anvelope',
                              [('rrect', cx + px0, py0, cx + px1, py1, 12)], None))
        w = x1 - x0
        piece = piece.astype(np.float32)
        if index:
            ramp = np.linspace(0, 1, OVERLAP, dtype=np.float32)[None, :, None]
            canvas[:, dest:dest + OVERLAP] = canvas[:, dest:dest + OVERLAP] * (1 - ramp) + piece[:, :OVERLAP] * ramp
            canvas[:, dest + OVERLAP:dest + w] = piece[:, OVERLAP:]
        else:
            canvas[:, dest:dest + w] = piece
        specs += carried_specs(source, x0, x1, dx, axles, opts.get('single'))
        cursor = dest + w
    # a part cut by a seam (driveshaft) arrives once from each slice: one part with both pieces
    merged = {}
    for spec in specs:
        if spec[0] in merged:
            first = merged[spec[0]]
            merged[spec[0]] = (first[0], first[1], first[2], first[3] + spec[3]) + tuple(first[4:])
        else:
            merged[spec[0]] = spec
    specs = list(merged.values())
    img = np.clip(canvas, 0, 255).astype(np.uint8)
    # outside the vehicle band: plain page background (the mockup's labels live there)
    img[:Y0] = bg.astype(np.uint8)
    img[Y1:] = bg.astype(np.uint8)
    return img, (0, Y0, width, Y1), specs


def cutout(crop):
    """Same as trace.py: page background (connected to the border) transparent, soft edge, edge fade."""
    h, w = crop.shape[:2]
    ff = np.zeros((h + 2, w + 2), np.uint8)
    flags = 4 | cv2.FLOODFILL_MASK_ONLY | cv2.FLOODFILL_FIXED_RANGE | (255 << 8)
    for sx, sy in [(0, 0), (w - 1, 0), (0, h - 1), (w - 1, h - 1), (w // 2, 0), (w // 2, h - 1)]:
        if ff[sy + 1, sx + 1] == 0:
            cv2.floodFill(crop.copy(), ff, (sx, sy), 0, (14, 14, 14), (14, 14, 14), flags)
    alpha = np.where(ff[1:-1, 1:-1] > 0, 0, 255).astype(np.uint8)
    alpha = cv2.GaussianBlur(alpha, (3, 3), 0)
    yy, xx = np.mgrid[0:h, 0:w]
    edge = np.minimum(np.minimum(xx, w - 1 - xx), np.minimum(yy, h - 1 - yy)).astype(np.float32)
    alpha = (alpha.astype(np.float32) * np.clip(edge / 9.0, 0, 1)).astype(np.uint8)
    return np.dstack([crop, alpha])


def trace_full(name, img, box):
    """Full vehicle SVG, same cut-out and tracer settings as the approved vehicles (trace.py, variant b)."""
    x0, y0, x1, y1 = box
    rgba = cutout(img[y0:y1, x0:x1])
    big = cv2.resize(rgba, ((x1 - x0) * 2, (y1 - y0) * 2), interpolation=cv2.INTER_LANCZOS4)
    png = os.path.join(HERE, name + '-clean.png')
    cv2.imwrite(png, big)
    out = os.path.join(OUT, name + '.svg')
    vtracer.convert_image_to_svg_py(png, out, colormode='color', hierarchical='stacked', mode='spline',
                                    filter_speckle=8, color_precision=6, layer_difference=20,
                                    corner_threshold=60, length_threshold=4.0, max_iterations=10,
                                    splice_threshold=45, path_precision=1)
    os.remove(png)
    print(name, os.path.getsize(out) // 1024, 'KB')


if __name__ == '__main__':
    names = sys.argv[1:] or list(RECIPES)
    os.makedirs(os.path.join(OUT, 'composed'), exist_ok=True)
    result = {}
    for name in names:
        img, box, specs = compose(name)
        cv2.imwrite(os.path.join(OUT, 'composed', name + '.png'), img[box[1]:box[3], box[0]:box[2]])
        trace_full(name, img, box)
        P.load(img)
        result[name] = P.trace_vehicle(name, box, specs)
        result[name]['crop'] = list(box)
    P.write_manifest(result)
