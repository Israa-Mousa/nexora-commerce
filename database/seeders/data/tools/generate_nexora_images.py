"""
Nexora Beauty & Personal Care — demo artwork generator.

Renders original, illustrative product packshots (2 per product), category tiles and
homepage banners as WebP from database/seeders/data/nexora-beauty-catalog.json.
All artwork is generated (no third-party photos) and uses the fictional demo brands.

Usage:  python3 generate_nexora_images.py <catalog.json> <output_dir>
Output: <out>/products/<slug>-1.webp, <slug>-2.webp
        <out>/categories/<category-key>.webp
        <out>/banners/*.webp
"""

import json
import math
import os
import random
import sys

from PIL import Image, ImageDraw, ImageFilter, ImageFont

FONT_DIR = "/usr/share/fonts/truetype/google-fonts/"
SERIF = FONT_DIR + "Lora-Variable.ttf"
SANS = FONT_DIR + "Poppins-Medium.ttf"
SANS_BOLD = FONT_DIR + "Poppins-Bold.ttf"
SANS_LIGHT = FONT_DIR + "Poppins-Light.ttf"

SS = 2  # supersampling factor

PALETTES = {
    "women": {"bg": ("#fbf6f1", "#f2dfd9"), "disc": "#f6e6df", "accent": "#7a2e3e", "leaf": "#d9a7a4"},
    "men": {"bg": ("#f6f2ec", "#ddd3c6"), "disc": "#e9e1d6", "accent": "#4a3a2e", "leaf": "#9aa59a"},
    "kids": {"bg": ("#fbf8f1", "#e3eef3"), "disc": "#eef3f2", "accent": "#5c7f94", "leaf": "#bcd7c6"},
    "unisex": {"bg": ("#f8f3ec", "#e6dccd"), "disc": "#efe7dc", "accent": "#6b4a3a", "leaf": "#b9ad97"},
}
for _alias, _base in {"skincare": "women", "makeup": "women", "hair": "unisex", "body": "unisex", "fragrances": "unisex"}.items():
    PALETTES[_alias] = PALETTES[_base]

# Clean studio backdrops for product cards (ivory / sand / mist), keyed by audience.
STUDIO = {
    "women": ("#fbf8f4", "#efe6dc"),
    "men": ("#f7f4ef", "#e4ddd2"),
    "kids": ("#f9f8f4", "#e6ecec"),
    "unisex": ("#faf6f0", "#ebe2d5"),
}


def hex_rgb(value, alpha=None):
    value = value.lstrip("#")
    rgb = tuple(int(value[i:i + 2], 16) for i in (0, 2, 4))
    return rgb + (alpha,) if alpha is not None else rgb


def mix(a, b, t):
    return tuple(int(a[i] + (b[i] - a[i]) * t) for i in range(3))


def lum(rgb):
    return 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2]


def font(path, size):
    return ImageFont.truetype(path, int(size * SS))


def vertical_gradient(size, top, bottom):
    w, h = size
    base = Image.new("RGB", (1, h))
    px = base.load()
    for y in range(h):
        px[0, y] = mix(top, bottom, y / max(1, h - 1))
    return base.resize((w, h))


def diagonal_gradient(size, a, b):
    w, h = size
    small = Image.new("RGB", (64, 64))
    px = small.load()
    for y in range(64):
        for x in range(64):
            px[x, y] = mix(a, b, (x + y) / 126)
    return small.resize((w, h), Image.BICUBIC)


def shaded_body(size, color, radius, glass=False):
    """A vertical cylinder-like body: horizontal shading + highlight, masked to rounded rect."""
    w, h = size
    c = hex_rgb(color)
    dark = mix(c, (0, 0, 0), 0.22 if lum(c) > 90 else 0.35)
    light = mix(c, (255, 255, 255), 0.35)
    strip = Image.new("RGB", (w, 1))
    px = strip.load()
    for x in range(w):
        t = x / max(1, w - 1)
        # bright at ~30%, dark at edges
        k = math.cos((t - 0.32) * math.pi * 0.95)
        k = max(0.0, k)
        px[x, 0] = mix(dark, light, k ** 1.6)
    body = strip.resize((w, h))
    mask = Image.new("L", (w, h), 0)
    ImageDraw.Draw(mask).rounded_rectangle((0, 0, w - 1, h - 1), radius=radius, fill=255 if not glass else 225)
    out = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    out.paste(body, (0, 0), mask)
    # specular highlight line
    hl = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    hd = ImageDraw.Draw(hl)
    hx = int(w * 0.2)
    hd.rounded_rectangle((hx, int(h * 0.06), hx + max(4, w // 22), int(h * 0.9)), radius=w // 40, fill=(255, 255, 255, 70))
    hl = hl.filter(ImageFilter.GaussianBlur(w // 60 + 1))
    out = Image.alpha_composite(out, Image.composite(hl, Image.new("RGBA", (w, h), (0, 0, 0, 0)), mask))
    return out


def paste(canvas, layer, xy):
    canvas.alpha_composite(layer, (int(xy[0]), int(xy[1])))


def text_center(draw, cx, y, text, fnt, fill, spacing=0):
    if spacing:
        text = (" " * 0).join(text)
    bbox = draw.textbbox((0, 0), text, font=fnt)
    draw.text((cx - (bbox[2] - bbox[0]) / 2 - bbox[0], y), text, font=fnt, fill=fill)
    return bbox[3] - bbox[1]


def wrap(draw, text, fnt, max_w):
    words, lines, cur = text.split(), [], ""
    for word in words:
        trial = (cur + " " + word).strip()
        if draw.textlength(trial, font=fnt) <= max_w:
            cur = trial
        else:
            if cur:
                lines.append(cur)
            cur = word
    if cur:
        lines.append(cur)
    return lines


def draw_label(canvas, box, product, brand, scale=1.0):
    """Label panel with brand, short product title and size."""
    x0, y0, x1, y1 = [int(v) for v in box]
    w = x1 - x0
    tone = hex_rgb(product["tone"])
    dark_body = lum(tone) < 110
    label = Image.new("RGBA", (w, y1 - y0), (0, 0, 0, 0))
    ld = ImageDraw.Draw(label)
    panel = (255, 252, 247, 235) if not dark_body else (255, 255, 255, 0)
    ld.rounded_rectangle((0, 0, w - 1, y1 - y0 - 1), radius=int(10 * SS * scale), fill=panel)
    ink = (52, 38, 40) if not dark_body else (248, 240, 232)
    accent = hex_rgb(brand["color"]) if not dark_body else (226, 196, 150)
    cx = w / 2
    y = int(10 * SS * scale)
    fsize = 15 * scale
    fb = font(SERIF, fsize)
    while ld.textlength(brand["en"].upper(), font=fb) > w * 0.86 and fsize > 5:
        fsize -= 0.5
        fb = font(SERIF, fsize)
    y += text_center(ld, cx, y, brand["en"].upper(), fb, accent) + int(9 * SS * scale)
    ld.line((cx - w * 0.18, y, cx + w * 0.18, y), fill=accent, width=max(1, int(1.2 * SS)))
    y += int(10 * SS * scale)
    title = product["en"].split(" — ")[0]
    ft = font(SANS_BOLD, 13.5 * scale)
    for line in wrap(ld, title.upper(), ft, w * 0.84)[:3]:
        y += text_center(ld, cx, y, line, ft, ink) + int(5 * SS * scale)
    fs = font(SANS_LIGHT, 11.5 * scale)
    y += int(6 * SS * scale)
    text_center(ld, cx, y, product["size"], fs, ink)
    canvas.alpha_composite(label, (x0, y0))


def soft_shadow(canvas, cx, base_y, width, strength=90):
    sh = Image.new("RGBA", canvas.size, (0, 0, 0, 0))
    sd = ImageDraw.Draw(sh)
    sd.ellipse((cx - width / 2, base_y - width * 0.06, cx + width / 2, base_y + width * 0.08), fill=(60, 40, 35, strength))
    sh = sh.filter(ImageFilter.GaussianBlur(int(14 * SS)))
    canvas.alpha_composite(sh)


def cap(canvas, box, color, radius):
    x0, y0, x1, y1 = [int(v) for v in box]
    layer = shaded_body((x1 - x0, y1 - y0), color, radius)
    paste(canvas, layer, (x0, y0))


def draw_product(canvas, product, brand, cx, base_y, height):
    """Draw a packshot standing on base_y, centred on cx, of total height `height`."""
    shape = product["shape"]
    tone = product["tone"]
    accent = brand["color"]
    gold = "#c9a46a"
    cap_dark = "#2f2a2b" if product["audience"] != "kids" else "#ffffff"
    soft_shadow(canvas, cx, base_y, height * 0.62)

    def body(w, h, top, radius, color=tone, glass=False):
        layer = shaded_body((int(w), int(h)), color, int(radius), glass=glass)
        paste(canvas, layer, (cx - w / 2, top))

    if shape in ("bottle", "pump", "spray", "rollon"):
        w = height * 0.42
        bh = height * (0.70 if shape != "rollon" else 0.62)
        top = base_y - bh
        body(w, bh, top, w * 0.16)
        if shape == "bottle":
            cap(canvas, (cx - w * 0.22, top - height * 0.12, cx + w * 0.22, top + 4), accent if lum(hex_rgb(tone)) > 120 else gold, w * 0.06)
        elif shape == "pump":
            cap(canvas, (cx - w * 0.2, top - height * 0.08, cx + w * 0.2, top + 4), cap_dark, w * 0.05)
            cap(canvas, (cx - w * 0.06, top - height * 0.18, cx + w * 0.06, top - height * 0.08), cap_dark, w * 0.03)
            cap(canvas, (cx - w * 0.2, top - height * 0.235, cx + w * 0.33, top - height * 0.18), cap_dark, w * 0.03)
        elif shape == "spray":
            cap(canvas, (cx - w * 0.18, top - height * 0.07, cx + w * 0.18, top + 4), "#ffffff", w * 0.05)
            cap(canvas, (cx - w * 0.24, top - height * 0.2, cx + w * 0.2, top - height * 0.07), "#ffffff", w * 0.08)
            cap(canvas, (cx + w * 0.1, top - height * 0.19, cx + w * 0.42, top - height * 0.14), "#ffffff", w * 0.03)
        else:  # roll-on
            cap(canvas, (cx - w * 0.44, top - height * 0.18, cx + w * 0.44, top + 6), "#3d4a4f", w * 0.22)
        draw_label(canvas, (cx - w * 0.42, top + bh * 0.2, cx + w * 0.42, top + bh * 0.82), product, brand, height / 620)
    elif shape == "tube":
        w = height * 0.36
        bh = height * 0.82
        top = base_y - bh
        cap_h = height * 0.12
        # tube standing on cap: tapered body (wider at crimp top)
        layer = shaded_body((int(w), int(bh - cap_h)), tone, int(w * 0.08))
        mask = Image.new("L", layer.size, 0)
        md = ImageDraw.Draw(mask)
        lw, lh = layer.size
        md.polygon([(0, 0), (lw, 0), (lw * 0.86, lh), (lw * 0.14, lh)], fill=255)
        tl = Image.new("RGBA", layer.size, (0, 0, 0, 0))
        tl.paste(layer, (0, 0), mask)
        paste(canvas, tl, (cx - w / 2, top))
        d = ImageDraw.Draw(canvas)
        d.rectangle((cx - w / 2, top, cx + w / 2, top + height * 0.03), fill=hex_rgb(tone, 255))
        for i in range(6):
            yy = top + height * 0.006 + i * height * 0.0045
            d.line((cx - w / 2, yy, cx + w / 2, yy), fill=(0, 0, 0, 25), width=SS)
        cap(canvas, (cx - w * 0.36, base_y - cap_h, cx + w * 0.36, base_y), accent, w * 0.06)
        draw_label(canvas, (cx - w * 0.38, top + bh * 0.12, cx + w * 0.38, top + bh * 0.66), product, brand, height / 700)
    elif shape == "jar":
        w = height * 0.7
        bh = height * 0.38
        top = base_y - bh
        body(w, bh, top, w * 0.08)
        cap(canvas, (cx - w * 0.52, top - height * 0.16, cx + w * 0.52, top + 6), gold if product["audience"] == "women" else accent, w * 0.04)
        draw_label(canvas, (cx - w * 0.34, top + bh * 0.08, cx + w * 0.34, top + bh * 0.94), product, brand, height / 760)
    elif shape == "tin":
        w = height * 0.8
        bh = height * 0.2
        top = base_y - bh
        body(w * 0.96, bh, top, w * 0.04, color="#b9b2a8")
        lid_h = height * 0.17
        lid = (cx - w * 0.5, top - lid_h, cx + w * 0.5, top + 4)
        lid_layer = shaded_body((int(lid[2] - lid[0]), int(lid[3] - lid[1])), tone, int(w * 0.04))
        paste(canvas, lid_layer, (lid[0], lid[1]))
        d = ImageDraw.Draw(canvas)
        fb = font(SERIF, 20 * height / 600)
        text_center(d, cx, lid[1] + lid_h * 0.16, brand["en"].upper(), fb, (250, 240, 228))
        fs = font(SANS_BOLD, 11 * height / 600)
        title = product["en"].split(" — ")[0].upper()
        text_center(d, cx, lid[1] + lid_h * 0.56, title, fs, (240, 228, 214))
        fs2 = font(SANS_LIGHT, 11 * height / 600)
        text_center(d, cx, top + bh * 0.32, product["size"], fs2, (70, 60, 55))
    elif shape == "dropper":
        w = height * 0.36
        bh = height * 0.54
        top = base_y - bh
        body(w, bh, top, w * 0.14, glass=True)
        cap(canvas, (cx - w * 0.22, top - height * 0.1, cx + w * 0.22, top + 4), gold, w * 0.05)
        cap(canvas, (cx - w * 0.13, top - height * 0.3, cx + w * 0.13, top - height * 0.1), "#2b2426", w * 0.13)
        draw_label(canvas, (cx - w * 0.42, top + bh * 0.16, cx + w * 0.42, top + bh * 0.86), product, brand, height / 760)
    elif shape == "wand":
        w = height * 0.2
        bh = height * 0.5
        top = base_y - bh
        body(w, bh, top, w * 0.3, color=tone)
        cap(canvas, (cx - w * 0.5, top - height * 0.42, cx + w * 0.5, top + 4), "#2b2426" if product["audience"] != "kids" else accent, w * 0.3)
        d = ImageDraw.Draw(canvas)
        fb = font(SERIF, 11 * height / 600)
        name = brand["en"].upper()
        tmp = Image.new("RGBA", (int(height * 0.38), int(w * 0.6)), (0, 0, 0, 0))
        td = ImageDraw.Draw(tmp)
        text_center(td, tmp.size[0] / 2, tmp.size[1] * 0.18, name, fb, (232, 205, 160))
        tmp = tmp.rotate(90, expand=True)
        paste(canvas, tmp, (cx - tmp.size[0] / 2, top - height * 0.4))
        ink = (250, 244, 238) if lum(hex_rgb(tone)) < 140 else (70, 45, 45)
        title = product["en"].split(" — ")[0].upper()
        fsize = 11 * height / 600
        ft = font(SANS_BOLD, fsize)
        probe = ImageDraw.Draw(Image.new("RGBA", (10, 10)))
        while probe.textlength(title, font=ft) > bh * 0.86 and fsize > 4:
            fsize -= 0.5
            ft = font(SANS_BOLD, fsize)
        tmp = Image.new("RGBA", (int(bh * 0.92), int(w * 0.6)), (0, 0, 0, 0))
        td = ImageDraw.Draw(tmp)
        text_center(td, tmp.size[0] / 2, tmp.size[1] * 0.12, title, ft, ink)
        fs = font(SANS_LIGHT, 9 * height / 600)
        text_center(td, tmp.size[0] / 2, tmp.size[1] * 0.55, product["size"], fs, ink)
        tmp = tmp.rotate(90, expand=True)
        paste(canvas, tmp, (cx - tmp.size[0] / 2, top + bh * 0.04))
    elif shape == "perfume":
        w = height * 0.56
        bh = height * 0.6
        top = base_y - bh
        glass = Image.new("RGBA", (int(w), int(bh)), (0, 0, 0, 0))
        gd = ImageDraw.Draw(glass)
        gd.rounded_rectangle((0, 0, w - 1, bh - 1), radius=int(w * 0.08), fill=(255, 255, 255, 120), outline=(255, 255, 255, 200), width=3 * SS)
        liquid = shaded_body((int(w * 0.84), int(bh * 0.8)), tone, int(w * 0.06))
        liquid.putalpha(liquid.getchannel("A").point(lambda a: int(a * 0.85)))
        glass.alpha_composite(liquid, (int(w * 0.08), int(bh * 0.12)))
        paste(canvas, glass, (cx - w / 2, top))
        cap(canvas, (cx - w * 0.12, top - height * 0.06, cx + w * 0.12, top + 4), gold, w * 0.02)
        cap(canvas, (cx - w * 0.3, top - height * 0.3, cx + w * 0.3, top - height * 0.06), "#2b2426", w * 0.04)
        draw_label(canvas, (cx - w * 0.3, top + bh * 0.3, cx + w * 0.3, top + bh * 0.8), product, brand, height / 760)
    elif shape == "lipstick":
        w = height * 0.22
        case_h = height * 0.42
        top = base_y - case_h
        body(w * 1.05, case_h, top, w * 0.12, color="#2b2426")
        cap(canvas, (cx - w * 0.5, top - height * 0.1, cx + w * 0.5, top + 4), gold, w * 0.04)
        bullet = Image.new("RGBA", (int(w * 0.8), int(height * 0.26)), (0, 0, 0, 0))
        b = shaded_body(bullet.size, tone, int(w * 0.1))
        m = Image.new("L", bullet.size, 0)
        bw, bh2 = bullet.size
        ImageDraw.Draw(m).polygon([(0, bh2 * 0.35), (bw, 0), (bw, bh2), (0, bh2)], fill=255)
        bullet.paste(b, (0, 0), m)
        paste(canvas, bullet, (cx - bw / 2, top - height * 0.1 - bh2 + 6))
        d = ImageDraw.Draw(canvas)
        fb = font(SERIF, 10 * height / 600)
        tmp = Image.new("RGBA", (int(case_h * 0.9), int(w * 0.6)), (0, 0, 0, 0))
        text_center(ImageDraw.Draw(tmp), tmp.size[0] / 2, tmp.size[1] * 0.2, brand["en"].upper(), fb, (226, 196, 150))
        tmp = tmp.rotate(90, expand=True)
        paste(canvas, tmp, (cx - tmp.size[0] / 2, top + case_h * 0.05))
    elif shape == "compact":
        w = height * 0.78
        h = height * 0.17
        top = base_y - h
        body(w, h, top, h * 0.5, color="#2b2426")
        lid_h = height * 0.62
        lid = Image.new("RGBA", (int(w), int(lid_h)), (0, 0, 0, 0))
        ld = ImageDraw.Draw(lid)
        ld.ellipse((0, 0, w - 1, lid_h - 1), fill=hex_rgb("#2b2426", 255))
        inner = shaded_body((int(w * 0.8), int(lid_h * 0.8)), tone, int(w * 0.4))
        m = Image.new("L", inner.size, 0)
        ImageDraw.Draw(m).ellipse((0, 0, inner.size[0] - 1, inner.size[1] - 1), fill=255)
        pan = Image.new("RGBA", inner.size, (0, 0, 0, 0))
        pan.paste(inner, (0, 0), m)
        lid.alpha_composite(pan, (int(w * 0.1), int(lid_h * 0.1)))
        sheen = Image.new("RGBA", inner.size, (0, 0, 0, 0))
        ImageDraw.Draw(sheen).ellipse((inner.size[0] * 0.18, inner.size[1] * 0.12, inner.size[0] * 0.55, inner.size[1] * 0.42), fill=(255, 255, 255, 70))
        sheen = sheen.filter(ImageFilter.GaussianBlur(int(8 * SS)))
        lid.alpha_composite(sheen, (int(w * 0.1), int(lid_h * 0.1)))
        paste(canvas, lid, (cx - w / 2, top - lid_h * 0.86))
        d = ImageDraw.Draw(canvas)
        fb = font(SERIF, 12 * height / 600)
        text_center(d, cx, top + h * 0.28, brand["en"].upper(), fb, (226, 196, 150))
    elif shape == "brushes":
        d = ImageDraw.Draw(canvas)
        pouch_w, pouch_h = height * 0.86, height * 0.34
        top = base_y - pouch_h
        body(pouch_w, pouch_h, top, pouch_h * 0.18, color="#e9ddcc")
        fb = font(SERIF, 14 * height / 600)
        
        for i in range(5):
            bx = cx - pouch_w * 0.34 + i * pouch_w * 0.17
            handle_h = height * (0.5 + 0.06 * ((i * 7) % 3))
            hw = height * 0.045
            handle = shaded_body((int(hw), int(handle_h)), "#3a2b26", int(hw * 0.5))
            paste(canvas, handle, (bx - hw / 2, top - handle_h * 0.55))
            fer = shaded_body((int(hw * 1.15), int(height * 0.08)), tone, int(hw * 0.2))
            paste(canvas, fer, (bx - hw * 0.575, top - handle_h * 0.55 - height * 0.08))
            bw = hw * (1.6 + 0.5 * (i % 2))
            bh = height * (0.1 + 0.03 * (i % 3))
            bristle = Image.new("RGBA", (int(bw), int(bh)), (0, 0, 0, 0))
            ImageDraw.Draw(bristle).ellipse((0, 0, bw - 1, bh * 2), fill=hex_rgb("#f1e4d6" if i % 2 else "#d9c3ad", 255))
            paste(canvas, bristle, (bx - bw / 2, top - handle_h * 0.55 - height * 0.08 - bh + 2))
        body(pouch_w, pouch_h * 0.62, base_y - pouch_h * 0.62, pouch_h * 0.12, color="#e9ddcc")
        text_center(d, cx, base_y - pouch_h * 0.42, brand["en"].upper(), fb, hex_rgb("#7a2e3e"))
    elif shape == "sponge":
        for i, (dx, sc, rot) in enumerate(((-0.2, 1.0, -12), (0.2, 0.84, 14))):
            w = height * 0.36 * sc
            h = height * 0.52 * sc
            shade = shaded_body((int(w), int(h)), tone if i == 0 else "#f3d6c7", int(w * 0.5))
            m = Image.new("L", (int(w), int(h)), 0)
            md = ImageDraw.Draw(m)
            md.ellipse((0, h * 0.3, w - 1, h - 1), fill=255)
            md.ellipse((w * 0.18, 0, w * 0.82, h * 0.75), fill=255)
            m = m.filter(ImageFilter.GaussianBlur(int(4 * SS)))
            layer = Image.new("RGBA", (int(w), int(h)), (0, 0, 0, 0))
            layer.paste(shade, (0, 0), m)
            layer = layer.rotate(rot, expand=True, resample=Image.BICUBIC)
            soft_shadow(canvas, cx + dx * height, base_y, w * 1.1, 60)
            paste(canvas, layer, (cx + dx * height - layer.size[0] / 2, base_y - layer.size[1]))
    elif shape == "palette":
        w = height * 0.95
        h = height * 0.62
        top = base_y - h
        body(w, h, top, w * 0.05, color="#2b2426")
        d = ImageDraw.Draw(canvas)
        shades = ["#f1d9c4", "#e3b896", "#c99a7a", "#b07a5c", "#d9a38f", "#a8643f", "#8a4f3a", "#6b3b2c", "#4a2a22"]
        pw = w * 0.22
        for i, s in enumerate(shades):
            r, col = divmod(i, 3)
            px = cx - w * 0.36 + col * w * 0.25
            py = top + h * 0.12 + r * h * 0.27
            pan = shaded_body((int(pw), int(h * 0.22)), s, int(pw * 0.15))
            paste(canvas, pan, (px - pw * 0.0, py))
        fb = font(SERIF, 14 * height / 600)
        text_center(d, cx, top + h * 0.9 - 8 * SS, brand["en"].upper() + "  ·  DESERT NUDES", fb, (226, 196, 150))


def background(size, audience, seed, variant=1):
    pal = PALETTES[audience]
    a, b = hex_rgb(pal["bg"][0]), hex_rgb(pal["bg"][1])
    bg = diagonal_gradient(size, a, b) if variant == 1 else vertical_gradient(size, b, a)
    canvas = bg.convert("RGBA")
    w, h = size
    deco = Image.new("RGBA", size, (0, 0, 0, 0))
    dd = ImageDraw.Draw(deco)
    rnd = random.Random(seed)
    disc = hex_rgb(pal["disc"], 255)
    r = w * 0.36
    cx, cy = w * (0.5 + rnd.uniform(-0.05, 0.05)), h * 0.47
    dd.ellipse((cx - r, cy - r, cx + r, cy + r), fill=disc)
    dd.ellipse((cx - r * 1.18, cy - r * 1.18, cx + r * 1.18, cy + r * 1.18), outline=hex_rgb(pal["accent"], 40), width=2 * SS)
    # botanical/soft shapes
    leaf = hex_rgb(pal["leaf"], 120)
    for _ in range(3 if variant == 1 else 5):
        lx, ly = rnd.uniform(0.05, 0.95) * w, rnd.uniform(0.08, 0.6) * h
        lr = rnd.uniform(0.03, 0.07) * w
        if abs(lx - w / 2) < w * 0.2:
            lx += w * 0.3 * (1 if lx < w / 2 else -1) * -1
        dd.ellipse((lx - lr, ly - lr * 0.45, lx + lr, ly + lr * 0.45), fill=leaf)
    # podium
    pod = hex_rgb("#ffffff", 150)
    dd.ellipse((w * 0.18, h * 0.80, w * 0.82, h * 0.90), fill=pod)
    deco = deco.filter(ImageFilter.GaussianBlur(SS))
    canvas.alpha_composite(deco)
    return canvas


def soft_light(canvas, box, color, alpha, blur):
    """Blurred light/shade shape without dark fringes (blur the mask, not the colour)."""
    mask = Image.new("L", canvas.size, 0)
    ImageDraw.Draw(mask).ellipse(box, fill=alpha) if box[0] != "rect" else None
    mask = mask.filter(ImageFilter.GaussianBlur(blur))
    layer = Image.new("RGBA", canvas.size, color + (0,))
    layer.putalpha(mask)
    canvas.alpha_composite(layer)


def studio_background(size, audience, seed):
    """Clean studio backdrop for product cards: soft gradient wall + floor, no props."""
    top, bottom = STUDIO.get(audience, STUDIO["women"])
    w, h = size
    canvas = vertical_gradient(size, hex_rgb(top), hex_rgb(bottom)).convert("RGBA")
    floor_mask = Image.new("L", size, 0)
    ImageDraw.Draw(floor_mask).rectangle((0, int(h * 0.8), w, h), fill=110)
    floor_mask = floor_mask.filter(ImageFilter.GaussianBlur(int(30 * SS)))
    floor = Image.new("RGBA", size, mix(hex_rgb(bottom), (120, 95, 80), 0.25) + (0,))
    floor.putalpha(floor_mask)
    canvas.alpha_composite(floor)
    soft_light(canvas, (w * 0.15, h * 0.05, w * 0.85, h * 0.75), (255, 255, 255), 110, int(70 * SS))
    return canvas


def swatch(canvas, product, cx, cy, size):
    """A creamy product texture smear for the second image."""
    layer = Image.new("RGBA", canvas.size, (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    tone = hex_rgb(product["tone"])
    rnd = random.Random(product["sku"])
    for i in range(14):
        t = i / 13
        x = cx - size * 0.6 + t * size * 1.2
        y = cy + math.sin(t * math.pi * 1.3) * size * 0.12
        r = size * (0.16 + 0.08 * math.sin(t * math.pi))
        col = mix(tone, (255, 255, 255), 0.08 + rnd.uniform(0, 0.12))
        d.ellipse((x - r, y - r * 0.6, x + r, y + r * 0.6), fill=col + (255,))
    layer = layer.filter(ImageFilter.GaussianBlur(int(1 * SS)))
    shadow = layer.copy()
    shadow = Image.new("RGBA", canvas.size, (0, 0, 0, 0))
    shadow.putalpha(layer.getchannel("A").point(lambda a: int(a * 0.25)))
    shadow = shadow.filter(ImageFilter.GaussianBlur(int(10 * SS)))
    canvas.alpha_composite(shadow, (int(6 * SS), int(10 * SS)))
    canvas.alpha_composite(layer)


def product_images(product, brand, out_dir):
    size = (1200 * SS, 1200 * SS)
    # Image 1: hero packshot
    c = studio_background(size, product["audience"], product["sku"])
    draw_product(c, product, brand, size[0] / 2, size[1] * 0.84, size[1] * 0.7)
    save(c, os.path.join(out_dir, product["slug"] + "-1.webp"), (1200, 1200))
    # Image 2: the same product styled on a stone pedestal with a botanical sprig (hover image)
    c = studio_background(size, product["audience"], product["sku"] + "b")
    rnd = random.Random(product["sku"])
    stone = ["#efe2d3", "#e8dccf", "#e9e4dc"][rnd.randrange(3)]
    ped_w, ped_h = size[0] * 0.5, size[1] * 0.16
    floor_y = size[1] * 0.86
    soft_shadow(c, size[0] * 0.52, floor_y + 8 * SS, ped_w * 1.1, 70)
    paste(c, shaded_body((int(ped_w), int(ped_h)), stone, int(14 * SS)), (size[0] * 0.52 - ped_w / 2, floor_y - ped_h))
    leaf_sprig(c, size[0] * (0.2 if rnd.random() < 0.5 else 0.82), floor_y - ped_h * 0.2, size[1] * 0.55, math.radians(78 if rnd.random() < 0.5 else 102), "#9aa58e", "#e8bdb3" if product["audience"] != "men" else None, product["sku"])
    draw_product(c, product, brand, size[0] * 0.52, floor_y - ped_h + 6 * SS, size[1] * 0.58)
    save(c, os.path.join(out_dir, product["slug"] + "-2.webp"), (1200, 1200))


def save(canvas, path, size):
    img = canvas.convert("RGB").resize(size, Image.LANCZOS)
    img.save(path, "WEBP", quality=86, method=6)


def walk(nodes, parent=None, root=None):
    for node in nodes:
        yield node, parent, root or node
        yield from walk(node.get("children", []), node, root or node)


def category_images(catalog, out_dir, brands):
    products = catalog["products"]
    size = (900 * SS, 900 * SS)
    for node, parent, root in walk(catalog["categories"]):
        audience = root["key"] if root["key"] in PALETTES else "women"
        keys = {n["key"] for n, _, _ in walk([node])}
        picks = [p for p in products if set(p["categories"]) & keys][:3] or [p for p in products if p["audience"] == audience][:3]
        c = background(size, audience, node["key"], 1)
        positions = [(0.5, 0.86, 0.58)] if len(picks) == 1 else [(0.33, 0.86, 0.46), (0.67, 0.86, 0.46)] if len(picks) == 2 else [(0.25, 0.84, 0.4), (0.75, 0.84, 0.4), (0.5, 0.9, 0.5)]
        for p, (px, py, ph) in zip(picks, positions):
            draw_product(c, p, brands[p["brand"]], size[0] * px, size[1] * py, size[1] * ph)
        save(c, os.path.join(out_dir, node["key"] + ".webp"), (900, 900))


def banner(catalog, brands, out_path, audience, skus, size=(1600, 1000), layout="right"):
    S = (size[0] * SS, size[1] * SS)
    c = background(S, audience, out_path, 1)
    picks = [p for p in catalog["products"] if p["sku"] in skus]
    n = len(picks)
    for i, p in enumerate(picks):
        t = (i + 0.5) / n
        px = S[0] * (0.14 + 0.72 * t)
        spacing = S[0] * 0.72 / n
        h = min(S[1] * 0.66, spacing * 1.8) * (1.0 if i % 2 == 0 else 0.84)
        draw_product(c, p, brands[p["brand"]], px, S[1] * (0.88 if i % 2 == 0 else 0.92), h)
    save(c, out_path, size)


MOODS = {
    "ivory": {"wall": ("#faf9f6", "#ece6dc"), "arch": "#ffffff", "floor": "#ddd3c5", "stone": ["#f1ece4", "#e9e2d8", "#f6f3ee"], "leaf": "#9fb09d", "petal": "#d9b5b8"},
    "sage": {"wall": ("#eef2ec", "#d6e0d5"), "arch": "#f6f8f4", "floor": "#c4d0c2", "stone": ["#e9eee7", "#dce5dc", "#f2f5f0"], "leaf": "#8fa38c", "petal": "#e3c3c4"},
    "warmsand": {"wall": ("#f4efe8", "#e2d8cb"), "arch": "#faf7f2", "floor": "#d3c6b5", "stone": ["#ece4d9", "#e2d8cb", "#f3eee7"], "leaf": "#98a68f", "petal": "#cfa3a8"},
    "cream": {"wall": ("#f7efe6", "#ead9c8"), "arch": "#fbf5ee", "floor": "#e3cfba", "stone": ["#efe2d3", "#e5d3bf", "#f4eadf"], "leaf": "#9aa58e", "petal": "#e8bdb3"},
    "blush": {"wall": ("#f6e7e1", "#e9cfc6"), "arch": "#fbf1ec", "floor": "#dcbcb0", "stone": ["#f1dcd4", "#e8cbc0", "#f6e8e2"], "leaf": "#a8ad97", "petal": "#d99b93"},
    "burgundy": {"wall": ("#5a1f2c", "#3e141e"), "arch": "#6e2b39", "floor": "#331018", "stone": ["#7a3a46", "#6a2f3b", "#86505a"], "leaf": "#8a8f72", "petal": "#c98a8a"},
    "sand": {"wall": ("#efe5d8", "#dccab4"), "arch": "#f6eee4", "floor": "#cdb79d", "stone": ["#e6d7c4", "#dac7b0", "#efe4d6"], "leaf": "#8e9a80", "petal": "#d9b48f"},
    "mist": {"wall": ("#eef1ef", "#dbe3e3"), "arch": "#f6f8f6", "floor": "#c9d4d3", "stone": ["#e6ecea", "#dbe3e1", "#f0f3f1"], "leaf": "#a2b8a4", "petal": "#f0c9c0"},
}


def leaf_sprig(canvas, x, y, length, angle, color, petal=None, seed=0):
    rnd = random.Random(seed)
    layer = Image.new("RGBA", canvas.size, (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    pts = []
    for i in range(30):
        t = i / 29
        bend = math.sin(t * math.pi) * length * 0.12
        px = x + math.cos(angle) * length * t + math.cos(angle + math.pi / 2) * bend
        py = y - math.sin(angle) * length * t - math.sin(angle + math.pi / 2) * bend
        pts.append((px, py))
    d.line(pts, fill=hex_rgb("#7d6a55", 255), width=int(3 * SS), joint="curve")
    for i in range(3, 28, 4):
        px, py = pts[i]
        side = 1 if (i // 4) % 2 else -1
        lw, lh = length * 0.16, length * 0.06
        leaf = Image.new("RGBA", (int(lw * 2), int(lh * 2)), (0, 0, 0, 0))
        ImageDraw.Draw(leaf).ellipse((0, lh * 0.5, lw * 2 - 1, lh * 1.5), fill=hex_rgb(color, 235))
        leaf = leaf.rotate(math.degrees(angle) + side * (35 + rnd.uniform(-8, 8)), expand=True, resample=Image.BICUBIC)
        layer.alpha_composite(leaf, (int(px - leaf.size[0] / 2 + side * lw * 0.45), int(py - leaf.size[1] / 2)))
    if petal:
        px, py = pts[-1]
        for k in range(5):
            a = k * 2 * math.pi / 5
            r = length * 0.05
            d.ellipse((px + math.cos(a) * r - r, py + math.sin(a) * r - r, px + math.cos(a) * r + r, py + math.sin(a) * r + r), fill=hex_rgb(petal, 240))
        d.ellipse((px - r * 0.5, py - r * 0.5, px + r * 0.5, py + r * 0.5), fill=hex_rgb("#d6b06a", 255))
    canvas.alpha_composite(layer)


def editorial_scene(catalog, brands, out_path, size, skus, mood="cream", align="center", seed="x"):
    m = MOODS[mood]
    W, H = size[0] * SS, size[1] * SS
    c = vertical_gradient((W, H), hex_rgb(m["wall"][0]), hex_rgb(m["wall"][1])).convert("RGBA")
    d = ImageDraw.Draw(c)
    rnd = random.Random(seed)
    group_cx = {"center": 0.5, "right": 0.66, "left": 0.34}[align] * W
    # arch window
    aw, ah = min(W * 0.5, H * 0.82), H * 0.78
    arch = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    ad = ImageDraw.Draw(arch)
    ax0, ay0 = group_cx - aw / 2, H * 0.06
    ad.rounded_rectangle((ax0, ay0, ax0 + aw, ay0 + ah), radius=int(aw / 2), fill=hex_rgb(m["arch"], 255))
    arch = arch.filter(ImageFilter.GaussianBlur(int(2 * SS)))
    c.alpha_composite(arch)
    ring = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    ImageDraw.Draw(ring).rounded_rectangle((ax0 - 18 * SS, ay0 - 18 * SS, ax0 + aw + 18 * SS, ay0 + ah), radius=int(aw / 2 + 18 * SS), outline=hex_rgb(m["floor"], 90), width=int(2 * SS))
    c.alpha_composite(ring)
    # floor
    floor_y = H * 0.8
    fl = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    ImageDraw.Draw(fl).rectangle((0, floor_y, W, H), fill=hex_rgb(m["floor"], 200))
    fl = fl.filter(ImageFilter.GaussianBlur(int(6 * SS)))
    c.alpha_composite(fl)
    # light beam
    beam = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    ImageDraw.Draw(beam).polygon([(W * 0.08, 0), (W * 0.28, 0), (group_cx + aw * 0.4, floor_y), (group_cx - aw * 0.1, floor_y)], fill=(255, 255, 255, 40 if mood != "burgundy" else 18))
    beam = beam.filter(ImageFilter.GaussianBlur(int(40 * SS)))
    c.alpha_composite(beam)
    picks = [p for sku in skus for p in catalog["products"] if p["sku"] == sku]
    n = len(picks)
    span = min(W * 0.62, aw * 1.5)
    # botanicals behind
    leaf_sprig(c, group_cx - span * 0.55, floor_y, H * 0.62, math.radians(72), m["leaf"], m["petal"], seed + "a")
    leaf_sprig(c, group_cx + span * 0.52, floor_y, H * 0.45, math.radians(105), m["leaf"], None, seed + "b")
    heights = [0.16, 0.08, 0.22, 0.12, 0.18]
    for i, p in enumerate(picks):
        t = (i + 0.5) / n
        px = group_cx - span / 2 + span * t
        ped_h = H * heights[i % len(heights)]
        ped_w = span / n * 0.86
        top = floor_y - ped_h
        stone = shaded_body((int(ped_w), int(ped_h + 30 * SS)), m["stone"][i % 3], int(10 * SS))
        soft_shadow(c, px, floor_y + 10 * SS, ped_w * 1.1, 70)
        paste(c, stone, (px - ped_w / 2, top))
        ph = min(H * 0.58, ped_w * 1.9)
        draw_product(c, p, brands[p["brand"]], px, top + 6 * SS, ph)
    save(c, out_path, size)


def main():
    catalog = json.load(open(sys.argv[1], encoding="utf-8"))
    out = sys.argv[2]
    brands = catalog["brands"]
    for sub in ("products", "categories", "banners"):
        os.makedirs(os.path.join(out, sub), exist_ok=True)
    only = sys.argv[3] if len(sys.argv) > 3 else None
    if only in (None, "products"):
        rng = sys.argv[4] if len(sys.argv) > 4 else None
        items = catalog["products"]
        if rng:
            a, b = (int(x) for x in rng.split(":"))
            items = items[a:b]
        for p in items:
            product_images(p, brands[p["brand"]], os.path.join(out, "products"))
    if only in (None, "categories"):
        category_images(catalog, os.path.join(out, "categories"), brands)
    if only in (None, "banners"):
        banner(catalog, brands, os.path.join(out, "banners", "hero-women.webp"), "women", ["NXB-W-003", "NXB-W-005", "NXB-W-019", "NXB-W-009"])
        banner(catalog, brands, os.path.join(out, "banners", "hero-men.webp"), "men", ["NXB-M-003", "NXB-M-011", "NXB-M-002"])
        banner(catalog, brands, os.path.join(out, "banners", "hero-kids.webp"), "kids", ["NXB-K-001", "NXB-K-003", "NXB-K-006"])
        banner(catalog, brands, os.path.join(out, "banners", "hero-main.webp"), "women", ["NXB-W-004", "NXB-M-011", "NXB-W-019", "NXB-K-003"], (1600, 1300))
        banner(catalog, brands, os.path.join(out, "banners", "promo-offers.webp"), "women", ["NXB-W-011", "NXB-W-012", "NXB-W-017"], (1000, 900))
        banner(catalog, brands, os.path.join(out, "banners", "promo-grooming.webp"), "men", ["NXB-M-003", "NXB-M-005", "NXB-M-006"], (1000, 900))
    if only == "hero":
        os.makedirs(os.path.join(out, "hero"), exist_ok=True)
        hh = lambda name: os.path.join(out, "hero", name)
        editorial_scene(catalog, brands, hh("slide-skincare.webp"), (1400, 1500), ["NXB-W-020", "NXB-W-003", "NXB-W-005"], "sage", "center", "s1")
        editorial_scene(catalog, brands, hh("slide-fragrance.webp"), (1400, 1500), ["NXB-W-019", "NXB-U-001", "NXB-M-011"], "warmsand", "center", "s2")
        editorial_scene(catalog, brands, hh("slide-makeup.webp"), (1400, 1500), ["NXB-W-024", "NXB-W-011", "NXB-W-009"], "ivory", "center", "s3")
        editorial_scene(catalog, brands, hh("fragrance-band.webp"), (1800, 1000), ["NXB-W-019", "NXB-U-001", "NXB-M-011", "NXB-U-002"], "warmsand", "right", "fb")
        editorial_scene(catalog, brands, hh("promo-band.webp"), (1800, 900), ["NXB-W-014", "NXB-W-025", "NXB-W-022", "NXB-W-032"], "sage", "right", "pb")
    if only in (None, "editorial"):
        os.makedirs(os.path.join(out, "editorial"), exist_ok=True)
        e = lambda name: os.path.join(out, "editorial", name)
        editorial_scene(catalog, brands, e("hero.webp"), (1800, 1200), ["NXB-W-004", "NXB-W-019", "NXB-W-005", "NXB-W-021"], "cream", "center", "hero")
        editorial_scene(catalog, brands, e("skincare.webp"), (1200, 1400), ["NXB-W-020", "NXB-W-003", "NXB-W-005"], "mist", "center", "skin")
        editorial_scene(catalog, brands, e("makeup.webp"), (1200, 1400), ["NXB-W-024", "NXB-W-011", "NXB-W-009"], "blush", "center", "makeup")
        editorial_scene(catalog, brands, e("fragrance.webp"), (1800, 1000), ["NXB-W-019", "NXB-U-001", "NXB-M-011", "NXB-U-002"], "burgundy", "right", "frag")
        editorial_scene(catalog, brands, e("women.webp"), (1200, 1400), ["NXB-W-009", "NXB-W-019", "NXB-W-017"], "blush", "center", "women")
        editorial_scene(catalog, brands, e("men.webp"), (1200, 1400), ["NXB-M-003", "NXB-M-011", "NXB-M-002"], "sand", "center", "men")
        editorial_scene(catalog, brands, e("kids.webp"), (1200, 1400), ["NXB-K-001", "NXB-K-003", "NXB-K-006"], "mist", "center", "kids")
        editorial_scene(catalog, brands, e("tip-routine.webp"), (1200, 900), ["NXB-W-001", "NXB-W-004", "NXB-W-006"], "cream", "center", "tip1")
        editorial_scene(catalog, brands, e("tip-hair.webp"), (1200, 900), ["NXB-W-015", "NXB-W-029", "NXB-W-016"], "sand", "center", "tip2")
        editorial_scene(catalog, brands, e("tip-baby.webp"), (1200, 900), ["NXB-K-002", "NXB-K-005", "NXB-K-001"], "mist", "center", "tip3")
        editorial_scene(catalog, brands, e("promo.webp"), (1800, 900), ["NXB-W-014", "NXB-W-025", "NXB-W-022", "NXB-W-032"], "sand", "right", "promo")


if __name__ == "__main__":
    main()
