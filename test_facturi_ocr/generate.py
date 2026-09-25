"""Genereaza facturi de test (fictive) pentru ?page=ocr_piese.

Rulare:  python test_facturi_ocr/generate.py
Iesire:  test_facturi_ocr/*.pdf / *.png
"""
import io
import os
import random

from PIL import Image, ImageFilter
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas

OUT = os.path.dirname(os.path.abspath(__file__))
pdfmetrics.registerFont(TTFont("Arial", "C:/Windows/Fonts/arial.ttf"))
pdfmetrics.registerFont(TTFont("ArialB", "C:/Windows/Fonts/arialbd.ttf"))

CLIENT = ["TRANSPORT FLOTA TEST SRL", "CUI: RO11223344", "Reg. Com.: J40/1234/2015",
          "Sos. Test nr. 1, Bucuresti, Sector 6"]

SUPPLIERS = {
    "apc": ["AUTO PIESE CAMION SRL", "CUI: RO12345678", "Reg. Com.: J23/456/2010",
            "Str. Industriilor nr. 10, Chiajna, jud. Ilfov", "Tel: 0721 000 111",
            "IBAN: RO49AAAA1B31007593840000", "Banca Transilvania"],
    "srv": ["SERVICE TIR EXPERT S.R.L.", "C.I.F.: RO23456789", "J40/7890/2012",
            "Sos. Centurii nr. 5, Mogosoaia, jud. Ilfov", "Tel: 0744 222 333",
            "IBAN: RO09BCYP0000001234567890"],
    "anv": ["ANVELOPE GREU SA", "Cod fiscal: RO34567890", "J12/321/2008",
            "Str. Fabricii nr. 3, Cluj-Napoca, jud. Cluj", "www.anvelope-greu.test"],
    "de": ["NUTZFAHRZEUGE TEILE GMBH", "USt-IdNr: DE123456789",
           "Industriestr. 12, 86150 Augsburg, Germany", "IBAN: DE89370400440532013000"],
}


def ro(v):
    """1234.5 -> '1.234,50'"""
    s = f"{abs(v):,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")
    return ("-" if v < 0 else "") + s


def en(v):
    return f"{v:,.2f}"


class Invoice:
    def __init__(self, fname, supplier, series, number, date, due, currency="RON",
                 fmt=ro, vat=21, title="FACTURA FISCALA", lang="ro", note=None):
        self.fname, self.sup, self.series, self.number = fname, supplier, series, number
        self.date, self.due, self.cur, self.fmt, self.vat = date, due, currency, fmt, vat
        self.title, self.lang, self.note = title, lang, note
        self.rows = []  # ("item", name, code, um, qty, price) | ("section", text)

    def item(self, name, code, um, qty, price):
        self.rows.append(("item", name, code, um, qty, price))
        return self

    def section(self, text):
        self.rows.append(("section", text))
        return self

    def net(self):
        return round(sum(r[4] * r[5] for r in self.rows if r[0] == "item"), 2)


def draw(inv, path):
    c = canvas.Canvas(path, pagesize=A4)
    W, H = A4
    L = 15 * mm
    labels = {
        "ro": dict(sup="Furnizor", cli="Cumparator", nr="Nr. crt", den="Denumire produse / servicii",
                   cod="Cod", um="U.M.", q="Cant.", pu="Pret unitar", val="Valoare", tva="TVA",
                   sub="Total fara TVA", tt=f"TVA {inv.vat}%", tot="TOTAL DE PLATA",
                   ser="Seria", no="nr.", d="Data", sc="Scadenta"),
        "de": dict(sup="Lieferant", cli="Kunde", nr="Pos", den="Bezeichnung", cod="Art.-Nr.",
                   um="ME", q="Menge", pu="Einzelpreis", val="Betrag", tva="MwSt",
                   sub="Netto", tt=f"MwSt {inv.vat}%", tot="Gesamtbetrag",
                   ser="Rechnung", no="Nr.", d="Datum", sc="Faellig"),
    }[inv.lang]

    def header():
        y = H - 18 * mm
        c.setFont("ArialB", 9); c.drawString(L, y, labels["sup"] + ":")
        c.drawString(W / 2 + 5 * mm, y, labels["cli"] + ":")
        c.setFont("Arial", 9)
        for i, line in enumerate(SUPPLIERS[inv.sup]):
            c.drawString(L, y - (i + 1) * 11, line)
        for i, line in enumerate(CLIENT):
            c.drawString(W / 2 + 5 * mm, y - (i + 1) * 11, line)
        y -= 95
        c.setFont("ArialB", 15); c.drawCentredString(W / 2, y, inv.title)
        c.setFont("Arial", 10)
        c.drawCentredString(W / 2, y - 16,
                            f"{labels['ser']} {inv.series} {labels['no']} {inv.number}   "
                            f"{labels['d']}: {inv.date}   {labels['sc']}: {inv.due}   Moneda: {inv.cur}")
        return y - 40

    cols = [(L, labels["nr"], "l"), (L + 12 * mm, labels["den"], "l"), (L + 86 * mm, labels["cod"], "l"),
            (L + 112 * mm, labels["um"], "l"), (L + 132 * mm, labels["q"], "r"),
            (L + 156 * mm, labels["pu"], "r"), (L + 180 * mm, labels["val"], "r")]

    def table_head(y):
        c.setFillColor(colors.HexColor("#e6e6e6"))
        c.rect(L - 2, y - 4, W - 2 * L + 4, 15, stroke=0, fill=1)
        c.setFillColor(colors.black); c.setFont("ArialB", 8.5)
        for x, t, a in cols:
            (c.drawRightString if a == "r" else c.drawString)(x, y, t)
        return y - 18

    y = table_head(header())
    n = 0
    for r in inv.rows:
        if y < 45 * mm:
            c.setFont("Arial", 8); c.drawRightString(W - L, 12 * mm, "continuare pe pagina urmatoare")
            c.showPage(); y = table_head(H - 20 * mm)
        if r[0] == "section":
            c.setFont("ArialB", 9); c.drawString(L + 12 * mm, y, r[1]); y -= 14
            continue
        _, name, code, um, q, p = r
        n += 1
        c.setFont("Arial", 8.5)
        qs = (str(int(q)) if float(q).is_integer() else inv.fmt(q))
        vals = [str(n), name, code, um, qs, inv.fmt(p), inv.fmt(round(q * p, 2))]
        for (x, _, a), v in zip(cols, vals):
            (c.drawRightString if a == "r" else c.drawString)(x, y, v)
        y -= 14

    net = inv.net(); tva = round(net * inv.vat / 100, 2)
    y -= 6; c.line(L, y + 8, W - L, y + 8)
    c.setFont("Arial", 10)
    for lbl, v, bold in [(labels["sub"], net, False), (labels["tt"], tva, False),
                         (labels["tot"], net + tva, True)]:
        c.setFont("ArialB" if bold else "Arial", 11 if bold else 10)
        c.drawRightString(L + 150 * mm, y - 6, lbl + ":")
        c.drawRightString(L + 180 * mm, y - 6, f"{inv.fmt(net + tva if bold else v)} {inv.cur}")
        y -= 16
    if inv.note:
        c.setFont("Arial", 8.5)
        for i, line in enumerate(inv.note):
            c.drawString(L, y - 12 - i * 11, line)
    c.setFont("Arial", 7.5)
    c.drawString(L, 12 * mm, "Document de TEST generat automat - date fictive. Semnatura si stampila: ________")
    c.save()
    return net, tva


def scan(pdf_bytes_path, png_path, angle=1.3, big=False):
    """Randare PDF -> imagine 'scanata' (rotita, zgomot, gri) folosind pdfium daca exista, altfel reportlab."""
    try:
        import pypdfium2 as pdfium
        page = pdfium.PdfDocument(pdf_bytes_path)[0]
        img = page.render(scale=5 if big else 2.2).to_pil()
    except ImportError:
        raise SystemExit("pip install pypdfium2  (necesar pentru varianta PNG scanata)")
    img = img.convert("L").rotate(angle, expand=True, fillcolor=235)
    img = img.filter(ImageFilter.GaussianBlur(0.6))
    px = img.load(); w, h = img.size
    rnd = random.Random(7)
    for _ in range(w * h // (6 if big else 60)):
        x, y = rnd.randrange(w), rnd.randrange(h)
        px[x, y] = max(0, min(255, px[x, y] + rnd.randint(-70, 70)))
    img.save(png_path, optimize=not big)


def build():
    out = []

    # 1. Simplu: un vehicul, doar piese, format RO
    inv = Invoice("01_simplu_un_vehicul_piese.pdf", "apc", "APC", "10234", "02.09.2026", "02.10.2026",
                  note=["Auto: B 315 NET"])
    inv.item("Filtru ulei DAF CF", "1310901", "buc", 1, 142.50)
    inv.item("Filtru motorina", "1397766", "buc", 2, 98.00)
    inv.item("Filtru aer", "1638061", "buc", 1, 385.40)
    inv.item("Ulei motor 10W40 Total Rubia", "RUB1040", "l", 38, 27.90)
    out.append(inv)

    # 2. Piese + manopera, un vehicul, garantie si KM
    inv = Invoice("02_piese_si_manopera_garantie.pdf", "srv", "STE", "2026-0871", "05.09.2026", "20.09.2026",
                  note=["Auto: B 335 NET   KM bord: 612.450", "Garantie piese: 12 luni. Garantie manopera: 3 luni.",
                        "Data montaj: 05.09.2026"])
    inv.item("Set placute frana fata MAN TGA", "81508206095", "set", 1, 689.00)
    inv.item("Disc frana fata", "81508030035", "buc", 2, 1240.00)
    inv.item("Senzor uzura placute", "81271206233", "buc", 2, 64.50)
    inv.item("Manopera inlocuire placute si discuri", "MAN-FR", "ora", 3.5, 180.00)
    inv.item("Manopera verificare sistem franare", "MAN-VER", "ora", 1, 180.00)
    out.append(inv)

    # 3. Multi-vehicul: trei camioane pe aceeasi factura
    inv = Invoice("03_multi_vehicul_3_camioane.pdf", "srv", "STE", "2026-0902", "10.09.2026", "10.10.2026",
                  note=["Lucrari efectuate pe 3 autovehicule conform devizelor anexate."])
    inv.section("Auto B 395 NET - VOLVO FH - KM 845.120")
    inv.item("Kit ambreiaj Volvo FH", "3400700408", "kit", 1, 5850.00)
    inv.item("Rulment presiune", "3151000395", "buc", 1, 720.00)
    inv.item("Manopera inlocuire ambreiaj", "MAN-AMB", "ora", 8, 180.00)
    inv.section("Auto B 325 NET - DAF CF - KM 598.300")
    inv.item("Bec H7 24V", "H7-24", "buc", 4, 18.50)
    inv.item("Stergatoare parbriz 650mm", "WB650", "per", 1, 120.00)
    inv.item("Manopera electrica", "MAN-EL", "ora", 1.5, 160.00)
    inv.section("Auto B 218 NET - SCANIA R380 - KM 1.102.400")
    inv.item("Pompa apa Scania", "1508532", "buc", 1, 1320.00)
    inv.item("Antigel G12 concentrat", "G12-5L", "l", 10, 32.00)
    inv.item("Manopera inlocuire pompa apa", "MAN-PA", "ora", 4, 180.00)
    out.append(inv)

    # 4. Anvelope: sume mari cu separator de mii, perechi
    inv = Invoice("04_anvelope_sume_mari.pdf", "anv", "AGS", "55012", "12.09.2026", "12.11.2026",
                  note=["Auto: B 805 NET", "Garantie anvelope: 24 luni de la montaj."])
    inv.item("Anvelopa 315/70 R22.5 directie", "M315DIR", "buc", 2, 2450.00)
    inv.item("Anvelopa 315/70 R22.5 tractiune", "M315TR", "buc", 4, 2310.00)
    inv.item("Montaj + echilibrare roata camion", "MONT-C", "buc", 6, 95.00)
    inv.item("Valva metalica", "VALV-M", "buc", 6, 22.00)
    out.append(inv)

    # 5. Doar manopera (service fara piese)
    inv = Invoice("05_doar_manopera.pdf", "srv", "STE", "2026-0915", "15.09.2026", "30.09.2026",
                  note=["Auto: B 315 NET   KM bord: 640.010"])
    inv.item("Diagnoza computerizata", "DIAG", "buc", 1, 250.00)
    inv.item("Reglaj geometrie 3 axe", "GEOM3", "buc", 1, 650.00)
    inv.item("Manopera revizie generala", "REV", "ora", 5, 180.00)
    out.append(inv)

    # 6. Factura externa EUR, format numeric englezesc, TVA 0 (taxare inversa)
    inv = Invoice("06_extern_eur_format_en.pdf", "de", "NT", "RE-2026-4411", "08.09.2026", "08.10.2026",
                  currency="EUR", fmt=en, vat=0, title="RECHNUNG / INVOICE", lang="de",
                  note=["Reverse charge - Art. 196 Directive 2006/112/EC", "Vehicle: B 395 NET"])
    inv.item("Turbolader Volvo D13", "21527863", "Stk", 1, 1485.00)
    inv.item("Dichtungssatz Turbo", "21527900", "Set", 1, 96.40)
    inv.item("Oelzulaufleitung", "21527911", "Stk", 1, 142.75)
    out.append(inv)

    # 7. Storno (valori negative)
    inv = Invoice("07_storno_negativ.pdf", "apc", "APC", "10301", "18.09.2026", "18.09.2026",
                  title="FACTURA STORNO", note=["Storno partial la factura APC 10234 din 02.09.2026",
                                                 "Motiv: filtru aer returnat (cod gresit)."])
    inv.item("Filtru aer (retur)", "1638061", "buc", -1, 385.40)
    out.append(inv)

    # 8. Factura lunga, 2 pagini, stoc (fara vehicul)
    inv = Invoice("08_lunga_2_pagini_stoc.pdf", "apc", "APC", "10355", "20.09.2026", "20.10.2026",
                  note=["Marfa pentru stoc - depozit Chiajna.", "Garantie: 6 luni."])
    parts = [("Filtru ulei", "FU"), ("Filtru motorina", "FM"), ("Filtru aer", "FA"), ("Filtru polen", "FP"),
             ("Bec H4 24V", "H4"), ("Bec W5W 24V", "W5W"), ("Siguranta 15A", "S15"), ("Siguranta 30A", "S30"),
             ("Colier metalic 60mm", "C60"), ("Furtun apa 32mm", "F32"), ("Lichid parbriz", "LP"),
             ("Vaselina rulmenti", "VR"), ("Placute frana spate", "PFS"), ("Garnitura capac culbutori", "GCC"),
             ("Curea transmisie", "CT"), ("Rola intinzator", "RI"), ("Amortizor cabina", "AC"),
             ("Burduf suspensie", "BS"), ("Capat bara directie", "CBD"), ("Bieleta antiruliu", "BA"),
             ("Oglinda retrovizoare", "OR"), ("Lampa stop spate", "LSS"), ("Senzor ABS", "SABS"),
             ("Releu bujii", "RB"), ("Termostat", "TS"), ("AdBlue", "ADB")]
    rnd = random.Random(3)
    for name, code in parts:
        um = "l" if name in ("Lichid parbriz", "AdBlue") else "buc"
        inv.item(name, f"{code}-{rnd.randint(1000, 9999)}", um,
                 rnd.choice([1, 2, 4, 5]) if um == "buc" else 20, round(rnd.uniform(8, 250), 2))
    out.append(inv)

    # 9. Reduceri + text "zgomot" (IBAN, adrese) intre linii
    inv = Invoice("09_reduceri_si_zgomot.pdf", "apc", "APC", "10360", "22.09.2026", "22.10.2026",
                  note=["Auto: B 325 NET", "Punct de lucru: Str. Depozitelor nr. 2, Chitila, jud. Ilfov",
                        "Program: Luni - Vineri 08-17", "Discount fidelitate aplicat conform contract."])
    inv.item("Compresor aer DAF", "1695563", "buc", 1, 4200.00)
    inv.item("Discount 10% compresor", "DISC", "buc", 1, -420.00)
    inv.item("Uscator aer cartus", "1696444", "buc", 1, 310.00)
    inv.item("Manopera montaj compresor", "MAN-CMP", "ora", 3, 180.00)
    out.append(inv)

    for inv in out:
        net, tva = draw(inv, os.path.join(OUT, inv.fname))
        print(f"{inv.fname:40s} net={inv.fmt(net):>12s} tva={inv.fmt(tva):>10s} total={inv.fmt(net + tva):>12s} {inv.cur}")

    # 10. Scan PNG (<1MB) al facturii 2 si 11. scan mare (>1MB -> testeaza compresia GD)
    scan(os.path.join(OUT, out[1].fname), os.path.join(OUT, "10_scan_piese_manopera.png"))
    scan(os.path.join(OUT, out[2].fname), os.path.join(OUT, "11_scan_mare_multi_vehicul_peste_1MB.png"),
         angle=-2.0, big=True)
    for f in ("10_scan_piese_manopera.png", "11_scan_mare_multi_vehicul_peste_1MB.png"):
        print(f"{f:40s} {os.path.getsize(os.path.join(OUT, f)) / 1024:.0f} KB")


if __name__ == "__main__":
    build()
