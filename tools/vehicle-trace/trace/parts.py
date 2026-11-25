"""Cut every vehicle part out of the (overlay-free) mockup pixels and trace it on its own.

A part mask = its location in the mockup (rect / circle / capsule / polyline, in mockup pixels),
optionally narrowed to the part's own colour (HSV) so neighbouring artwork is not included, then
holes filled (icons inside a part stay with it). Big container parts (tank, chassis) are not split
out: the full traced vehicle stays the base layer, parts are traced overlays at the same coords.
Output units: 2 SVG units per mockup pixel, same as the full-vehicle trace (UPSCALE=2).
"""
import json
import os
import cv2
import numpy as np
import vtracer

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, 'out')
os.makedirs(os.path.join(OUT, 'parts'), exist_ok=True)
UP = 2

clean = hsv = None
H = W = 0
ALPHA_SRC = None  # optional precomputed vehicle alpha (composed vehicles carry their own)

def load(image, alpha=None):
    """Point the tracer at an image (BGR array or path): the mockup, or a composed vehicle."""
    global clean, hsv, H, W, ALPHA_SRC
    clean = cv2.imread(image, cv2.IMREAD_COLOR) if isinstance(image, str) else image
    hsv = cv2.cvtColor(clean, cv2.COLOR_BGR2HSV)
    H, W = clean.shape[:2]
    ALPHA_SRC = alpha
    MASKS.clear()

VEHICLES = {'tractor': (14, 146, 703, 472), 'trailer': (699, 150, 1702, 466)}

COLORS = {  # OpenCV HSV ranges (H 0-180)
    'orange': [((6, 90, 90), (24, 255, 255))],
    'purple': [((122, 50, 60), (165, 255, 255))],
    'blue': [((92, 70, 115), (124, 255, 255))],
    'green': [((38, 70, 50), (88, 255, 255))],
    'yellow': [((17, 90, 110), (38, 255, 255))],
    'gold': [((10, 55, 95), (40, 255, 255))],
    'red': [((0, 90, 70), (9, 255, 255)), ((166, 90, 70), (180, 255, 255))],
    'warm': [((0, 90, 80), (40, 255, 255)), ((150, 90, 80), (180, 255, 255)), ((92, 110, 80), (124, 255, 255))],
    'glow': [((95, 150, 150), (120, 255, 255)), ((121, 110, 60), (165, 255, 255)), ((0, 90, 70), (9, 255, 255)), ((166, 90, 70), (180, 255, 255))],
    'notlav': None,  # everything except the lavender tank body (see below)
}

def shape_mask(shape):
    m = np.zeros((H, W), np.uint8)
    kind = shape[0]
    if kind == 'rect':
        _, x0, y0, x1, y1 = shape
        cv2.rectangle(m, (x0, y0), (x1, y1), 255, -1)
    elif kind == 'rrect':
        _, x0, y0, x1, y1, r = shape
        cv2.rectangle(m, (x0 + r, y0), (x1 - r, y1), 255, -1)
        cv2.rectangle(m, (x0, y0 + r), (x1, y1 - r), 255, -1)
        for cx, cy in ((x0 + r, y0 + r), (x1 - r, y0 + r), (x0 + r, y1 - r), (x1 - r, y1 - r)):
            cv2.circle(m, (cx, cy), r, 255, -1)
    elif kind == 'circle':
        _, cx, cy, r = shape
        cv2.circle(m, (cx, cy), r, 255, -1)
    elif kind == 'poly':
        cv2.fillPoly(m, [np.array(shape[1], np.int32)], 255)
    elif kind == 'line':
        _, pts, w = shape
        cv2.polylines(m, [np.array(pts, np.int32)], False, 255, w)
    return m

def color_mask(name):
    if name == 'notlav':
        h, s, v = cv2.split(hsv)
        lav = (h >= 100) & (h <= 156) & (s >= 20) & (s < 150) & (v > 95)
        return np.where(lav, 0, 255).astype(np.uint8)
    m = np.zeros((H, W), np.uint8)
    for lo, hi in COLORS[name]:
        m |= cv2.inRange(hsv, np.array(lo), np.array(hi))
    return m

def fill_holes(m):
    ff = m.copy()
    pad = np.zeros((H + 2, W + 2), np.uint8)
    cv2.floodFill(ff, pad, (0, 0), 255)
    return m | cv2.bitwise_not(ff)

def vehicle_alpha(x0, y0, x1, y1):
    if ALPHA_SRC is not None:
        full = np.zeros((H, W), np.uint8)
        full[y0:y1, x0:x1] = ALPHA_SRC[y0:y1, x0:x1]
        return full
    crop = clean[y0:y1, x0:x1]
    h, w = crop.shape[:2]
    ff = np.zeros((h + 2, w + 2), np.uint8)
    flags = 4 | cv2.FLOODFILL_MASK_ONLY | cv2.FLOODFILL_FIXED_RANGE | (255 << 8)
    for sx, sy in [(0, 0), (w - 1, 0), (0, h - 1), (w - 1, h - 1), (w // 2, 0), (w // 2, h - 1)]:
        if ff[sy + 1, sx + 1] == 0:
            cv2.floodFill(crop.copy(), ff, (sx, sy), 0, (14, 14, 14), (14, 14, 14), flags)
    full = np.zeros((H, W), np.uint8)
    full[y0:y1, x0:x1] = np.where(ff[1:-1, 1:-1] > 0, 0, 255)
    return full

# id, label (RO), category (explorer key), shapes (unioned), colour filter or None
TRACTOR = [
    ('tyre-a1-r', 'Anvelopă A1-R', 'anvelope', [('rrect', 140, 171, 253, 216, 16)], None),
    ('tyre-a1-l', 'Anvelopă A1-L', 'anvelope', [('rrect', 140, 395, 253, 443, 16)], None),
    # dual wheels split at the seam between the two tyres (mockup rows 197 / 411)
    # the pair's rounded outline (fitted to the tyre edge) is cut into two at the seam row
    ('tyre-a2-r-out', 'Anvelopă A2-R exterior', 'anvelope', [('rrect', 517, 171, 659, 226, 15)], None, {'rows': (171, 197), 'drop': 'glow'}),
    ('tyre-a2-r-in', 'Anvelopă A2-R interior', 'anvelope', [('rrect', 517, 171, 659, 226, 15)], None, {'rows': (198, 224), 'drop': 'glow'}),
    ('tyre-a2-l-in', 'Anvelopă A2-L interior', 'anvelope', [('rrect', 517, 387, 659, 443, 15)], None, {'rows': (387, 410), 'drop': 'glow'}),
    ('tyre-a2-l-out', 'Anvelopă A2-L exterior', 'anvelope', [('rrect', 517, 387, 659, 443, 15)], None, {'rows': (411, 443), 'drop': 'glow'}),
    ('radiator', 'Radiator', 'racire', [('rect', 55, 250, 96, 365)], 'red'),
    ('fan', 'Ventilator', 'racire', [('rrect', 81, 268, 119, 305, 6)], None),
    ('cooling-hose', 'Furtun răcire', 'racire', [('poly', [(190, 228), (232, 228), (262, 248), (262, 262), (214, 262), (210, 345), (262, 362), (264, 382), (232, 382), (190, 352)])], 'red'),
    ('electrical', 'Instalație electrică', 'electrica', [('rrect', 86, 225, 116, 254, 5)], None),
    ('battery', 'Baterii', 'electrica', [('rrect', 396, 188, 496, 241, 6)], None),
    ('steering', 'Direcție', 'directie', [('rect', 116, 212, 205, 397)], 'green', {'fill': False, 'close': 3}),
    ('engine', 'Motor', 'motor', [('rect', 200, 248, 346, 354)], 'orange'),
    ('exhaust', 'Evacuare', 'evacuare', [('rrect', 281, 192, 396, 237, 10)], None),
    ('gearbox', 'Cutie de viteze', 'transmisie', [('rect', 338, 264, 462, 344)], 'purple'),
    ('driveshaft', 'Arbore cardanic', 'transmisie', [('rect', 456, 288, 556, 321)], 'blue'),
    ('differential', 'Diferențial', 'diferential', [('rect', 558, 268, 656, 342)], 'blue', {'fill': False, 'close': 7}),
    ('air-spring-a2-r', 'Pernă aer A2-R', 'suspensie', [('rect', 573, 232, 608, 276)], 'yellow'),
    ('air-spring-a2-l', 'Pernă aer A2-L', 'suspensie', [('rect', 573, 338, 608, 381)], 'yellow'),
    ('brake-a2-r-out', 'Frână A2-R', 'franare', [('circle', 561, 237, 15)], None),
    ('brake-a2-r-in', 'Frână A2-R (int.)', 'franare', [('circle', 617, 237, 15)], None),
    ('brake-a2-l-out', 'Frână A2-L', 'franare', [('circle', 561, 371, 15)], None),
    ('brake-a2-l-in', 'Frână A2-L (int.)', 'franare', [('circle', 617, 371, 15)], None),
    ('fuel-tank', 'Rezervor combustibil', 'rezervor_combustibil', [('rrect', 304, 362, 429, 437, 10)], None),
    ('adblue', 'Rezervor AdBlue', 'adblue', [('rrect', 430, 363, 493, 430, 8)], None),
    ('coupling', 'Racorduri cuplare', 'racorduri', [('rect', 664, 281, 703, 327)], 'warm'),
]
TRAILER = [
    ('manhole', 'Manșon de acces', 'instalatie_gpl', [('circle', 874, 232, 36)], None),
    ('safety-valve', 'Supapă de siguranță', 'siguranta_gpl', [('rect', 965, 176, 1037, 245)], 'notlav', {'close': 3}),
    ('level-gauge', 'Indicator nivel', 'instalatie_gpl', [('circle', 1107, 218, 24)], None),
    ('hazard-plate', 'Plăcuță pericol', 'siguranta_gpl', [('poly', [(1022, 250), (1055, 283), (1022, 316), (989, 283)])], None),
    ('tank-supports', 'Suporți cisternă', 'cisterna', [('rrect', 836, 311, 868, 404, 5), ('rrect', 1097, 311, 1128, 404, 5)], None),
    ('gpl-pipes', 'Conducte GPL', 'instalatie_gpl', [('rect', 870, 336, 1236, 396)], 'gold', {'fill': False, 'close': 3}),
    ('valve-cabinet', 'Dulap armături GPL', 'instalatie_gpl', [('rect', 886, 359, 998, 438)], None),
    ('emergency-valve', 'Robinet de urgență', 'siguranta_gpl', [('rect', 998, 359, 1092, 438)], None),
    ('grounding', 'Împământare', 'siguranta_gpl', [('rrect', 1128, 405, 1157, 437, 4)], None),
    ('coupling-hoses', 'Racorduri de cuplare', 'racorduri', [('line', [(699, 288), (726, 300), (748, 330), (770, 365), (800, 392), (840, 404)], 30)], 'warm'),
]
for i, ax in enumerate((1327, 1454, 1577), start=1):
    TRAILER += [
        # dual wheels split at the seam between the two tyres (mockup rows 197 / 419)
        (f'tyre-a{i}-r-out', f'Anvelopă A{i}-R exterior', 'anvelope', [('rrect', ax - 55, 174, ax + 56, 226, 14)], None, {'rows': (174, 197), 'drop': 'glow'}),
        (f'tyre-a{i}-r-in', f'Anvelopă A{i}-R interior', 'anvelope', [('rrect', ax - 55, 174, ax + 56, 226, 14)], None, {'rows': (198, 224), 'drop': 'glow'}),
        (f'tyre-a{i}-l-in', f'Anvelopă A{i}-L interior', 'anvelope', [('rrect', ax - 55, 398, ax + 56, 443, 14)], None, {'rows': (398, 419), 'drop': 'glow'}),
        (f'tyre-a{i}-l-out', f'Anvelopă A{i}-L exterior', 'anvelope', [('rrect', ax - 55, 398, ax + 56, 443, 14)], None, {'rows': (420, 443), 'drop': 'glow'}),
        (f'hub-a{i}-r', f'Butuc / frână A{i}-R', 'franare', [('circle', ax, 237, 17)], None),
        (f'hub-a{i}-l', f'Butuc / frână A{i}-L', 'franare', [('circle', ax, 387, 17)], None),
        (f'air-spring-a{i}', f'Pernă aer A{i}', 'suspensie', [('rect', ax - 15, 270, ax + 15, 312)], 'yellow'),
        (f'bearing-a{i}', f'Rulment A{i}', 'rulare', [('circle', ax, 340, 14)], None),
        (f'axle-a{i}', f'Axă A{i}', 'rulare', [('rect', ax - 8, 255, ax + 8, 372)], 'notlav', {'close': 3, 'minus': [f'air-spring-a{i}', f'bearing-a{i}', f'hub-a{i}-r', f'hub-a{i}-l']}),
    ]

MASKS = {}  # (veh, id) -> final mask, so later parts can subtract earlier ones

def trace_part(veh, vbox, alpha, spec):
    pid, label, cat, shapes, colour = spec[:5]
    opts = spec[5] if len(spec) > 5 else {}
    m = np.zeros((H, W), np.uint8)
    for sh in shapes:
        m |= shape_mask(sh)
    region = m.copy()
    if colour:
        m &= color_mask(colour)
        k = opts.get('close', 5)
        m = cv2.morphologyEx(m, cv2.MORPH_CLOSE, np.ones((k, k), np.uint8))
        if opts.get('fill', True):
            m = fill_holes(m)
        m &= region
    if opts.get('drop'):  # strip a neighbour's saturated glow / hub colour that bleeds into the box
        m &= cv2.bitwise_not(cv2.dilate(color_mask(opts['drop']), np.ones((2, 2), np.uint8)))
        m = fill_holes(cv2.morphologyEx(m, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8)))
    for other in opts.get('minus', []):
        m &= cv2.bitwise_not(MASKS[(veh, other)])
    m &= alpha
    MASKS[(veh, pid)] = m
    m = cv2.dilate(m, np.ones((2, 2), np.uint8))
    if 'rows' in opts:  # keep only these mockup rows (one tyre of a dual pair, cut at the seam)
        ra, rb = opts['rows']
        m[:ra] = 0
        m[rb + 1:] = 0
        MASKS[(veh, pid)] = m
    ys, xs = np.nonzero(m)
    if not len(xs):
        print('EMPTY', veh, pid)
        return None
    x0, y0, x1, y1 = xs.min(), ys.min(), xs.max() + 1, ys.max() + 1
    rgba = np.dstack([clean[y0:y1, x0:x1], m[y0:y1, x0:x1]])
    big = cv2.resize(rgba, ((x1 - x0) * UP, (y1 - y0) * UP), interpolation=cv2.INTER_LANCZOS4)
    png = os.path.join(OUT, 'parts', f'{veh}-{pid}.png')
    svg = os.path.join(OUT, 'parts', f'{veh}-{pid}.svg')
    cv2.imwrite(png, big)
    vtracer.convert_image_to_svg_py(png, svg, colormode='color', hierarchical='stacked', mode='spline',
                                    filter_speckle=4, color_precision=6, layer_difference=20,
                                    corner_threshold=60, length_threshold=4.0, max_iterations=10,
                                    splice_threshold=45, path_precision=1)
    os.remove(png)
    if 'rows' in opts:
        # the tracer paints small transparent corners with a neighbouring colour: clip the SVG to the
        # exact mask outline so each tyre keeps its own rounded silhouette (no glow / hub slivers)
        a = (big[:, :, 3] >= 128).astype(np.uint8)
        cnts, _ = cv2.findContours(a, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        d = ' '.join('M' + ' L'.join(f'{px} {py}' for px, py in c.reshape(-1, 2)) + ' Z' for c in cnts)
        txt = open(svg, encoding='utf-8').read()
        head_end = txt.index('>', txt.index('<svg')) + 1
        tail = txt.rindex('</svg>')
        txt = (txt[:head_end] + f'<defs><clipPath id="c-{veh}-{pid}"><path d="{d}"/></clipPath></defs>'
               + f'<g clip-path="url(#c-{veh}-{pid})">' + txt[head_end:tail] + '</g>' + txt[tail:])
        open(svg, 'w', encoding='utf-8').write(txt)
    vx0, vy0 = vbox[0], vbox[1]
    return {'id': pid, 'label': label, 'category': cat, 'file': f'parts/{veh}-{pid}.svg',
            # placement in the vehicle SVG's units (2 per mockup pixel)
            'x': int(x0 - vx0) * UP, 'y': int(y0 - vy0) * UP, 'w': int(x1 - x0) * UP, 'h': int(y1 - y0) * UP,
            'kb': round(os.path.getsize(svg) / 1024)}

def trace_vehicle(veh, vbox, specs):
    alpha = vehicle_alpha(*vbox)
    x0, y0, x1, y1 = vbox
    entry = {'width': (x1 - x0) * UP, 'height': (y1 - y0) * UP, 'parts': []}
    for spec in specs:
        info = trace_part(veh, vbox, alpha, spec)
        if info:
            entry['parts'].append(info)
    print(veh, len(entry['parts']), 'parts,', sum(p['kb'] for p in entry['parts']), 'KB')
    return entry

def write_manifest(update):
    """Merge into out/parts.json (the mockup vehicles and the composed ones share it)."""
    path = os.path.join(OUT, 'parts.json')
    manifest = json.load(open(path, encoding='utf-8')) if os.path.exists(path) else {}
    manifest.update(update)
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(manifest, f, ensure_ascii=False, indent=1)

if __name__ == '__main__':
    load(os.path.join(HERE, 'clean.png'))
    result = {}
    for veh, specs in (('tractor', TRACTOR), ('trailer', TRAILER)):
        MASKS.clear()
        result[veh] = trace_vehicle(veh, VEHICLES[veh], specs)
    write_manifest(result)
