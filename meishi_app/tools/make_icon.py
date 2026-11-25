#!/usr/bin/env python3
"""名刺まるっと のアイコンを作る。白い丸の中に、重なった名刺のマーク。
実行: python3 tools/make_icon.py   （Pillowが必要）"""
import os
from PIL import Image, ImageDraw

BLUE = (21, 101, 192)
LIGHT = (144, 190, 238)
RING = (222, 228, 238)
SS = 4  # 作画は4倍の大きさで行って縮小する（輪郭をなめらかにするため）


def card(size, w, h, angle, fill, detail):
    """角丸の名刺1枚を、透明な正方形の画像に描いて回転する。"""
    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    x0, y0 = (size - w) / 2, (size - h) / 2
    box = [x0, y0, x0 + w, y0 + h]
    d.rounded_rectangle(box, radius=h * 0.12, fill=fill)
    if detail:
        # 白い部分は別の層に描き、名刺の形で切り取ってから重ねる（肩が名刺の外にはみ出さない）
        layer = Image.new("RGBA", (size, size), (0, 0, 0, 0))
        ld = ImageDraw.Draw(layer)
        white = (255, 255, 255, 255)
        hr = h * 0.14  # 人の頭
        cx, cy = x0 + w * 0.26, y0 + h * 0.38
        ld.ellipse([cx - hr, cy - hr, cx + hr, cy + hr], fill=white)
        ld.pieslice([cx - hr * 2.1, y0 + h * 0.58, cx + hr * 2.1, y0 + h * 1.40], 180, 360, fill=white)
        for i, ratio in enumerate((0.36, 0.30, 0.22)):  # 文字の行
            yy = y0 + h * (0.28 + i * 0.19)
            ld.rounded_rectangle([x0 + w * 0.48, yy, x0 + w * (0.48 + ratio * 1.2), yy + h * 0.075], radius=h * 0.04, fill=white)
        mask = Image.new("L", (size, size), 0)
        ImageDraw.Draw(mask).rounded_rectangle([x0 + h * 0.06, y0 + h * 0.06, x0 + w - h * 0.06, y0 + h - h * 0.06], radius=h * 0.08, fill=255)
        clipped = Image.new("RGBA", (size, size), (0, 0, 0, 0))
        clipped.paste(layer, (0, 0), mask)
        img.alpha_composite(clipped)
    return img.rotate(angle, resample=Image.BICUBIC)


def mark(diameter):
    """丸の直径 diameter に収まる、重なった2枚の名刺。"""
    s = int(diameter * 0.95)
    w, h = diameter * 0.62, diameter * 0.40
    back = card(s, w, h, 14, LIGHT + (255,), False)
    front = card(s, w, h, -9, BLUE + (255,), True)
    out = Image.new("RGBA", (s, s), (0, 0, 0, 0))
    out.alpha_composite(back, (int(diameter * 0.03), int(-diameter * 0.045)))
    out.alpha_composite(front, (int(-diameter * 0.02), int(diameter * 0.04)))
    return out


def disc(canvas, diameter):
    """canvas の中央に、白い丸（薄いふち付き）と名刺のマークを描く。"""
    big = canvas * SS
    img = Image.new("RGBA", (big, big), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    r = diameter * SS / 2
    c = big / 2
    d.ellipse([c - r, c - r, c + r, c + r], fill=RING + (255,))
    r2 = r - diameter * SS * 0.012
    d.ellipse([c - r2, c - r2, c + r2, c + r2], fill=(255, 255, 255, 255))
    m = mark(int(diameter * SS * 0.92))
    img.alpha_composite(m, (int(c - m.width / 2), int(c - m.height / 2)))
    return img.resize((canvas, canvas), Image.LANCZOS)


def save(img, path):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    img.save(path)


root = os.path.join(os.path.dirname(__file__), "..")
res = os.path.join(root, "android/app/src/main/res")

# 元になる大きな絵（ストア掲載用にも使える）
master = disc(1024, 1024)
save(master, os.path.join(root, "assets/icon/icon_1024.png"))

# Android 旧式アイコン（丸）
for name, px in {"mdpi": 48, "hdpi": 72, "xhdpi": 96, "xxhdpi": 144, "xxxhdpi": 192}.items():
    img = disc(px, px)
    save(img, f"{res}/mipmap-{name}/ic_launcher.png")
    save(img, f"{res}/mipmap-{name}/ic_launcher_round.png")

# Android 適応型アイコン: 背景は透明、前景に白い丸。
# 表示される範囲は 108dp のうち中央の 72dp なので、丸の直径をそれに合わせる。
# 端末の形（丸・角丸）に関わらず、白い丸として見える。
for name, px in {"mdpi": 108, "hdpi": 162, "xhdpi": 216, "xxhdpi": 324, "xxxhdpi": 432}.items():
    save(disc(px, round(px * 70 / 108)), f"{res}/mipmap-{name}/ic_launcher_foreground.png")

os.makedirs(f"{res}/mipmap-anydpi-v26", exist_ok=True)
with open(f"{res}/mipmap-anydpi-v26/ic_launcher.xml", "w") as f:
    f.write('''<?xml version="1.0" encoding="utf-8"?>
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@android:color/transparent"/>
    <foreground android:drawable="@mipmap/ic_launcher_foreground"/>
</adaptive-icon>
''')
with open(f"{res}/mipmap-anydpi-v26/ic_launcher_round.xml", "w") as f:
    f.write('''<?xml version="1.0" encoding="utf-8"?>
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@android:color/transparent"/>
    <foreground android:drawable="@mipmap/ic_launcher_foreground"/>
</adaptive-icon>
''')

# iPhone: 全面を塗る正方形で、透明は使えない。白地に丸い絵を置く（角はiPhoneが丸める）
ios = os.path.join(root, "ios/Runner/Assets.xcassets/AppIcon.appiconset")
if os.path.isdir(ios):
    sq = Image.new("RGB", (1024, 1024), (255, 255, 255))
    d = disc(1024, 960)
    sq.paste(d, (0, 0), d)
    for fn in os.listdir(ios):
        if fn.startswith("Icon-App-") and fn.endswith(".png"):
            stem = fn[len("Icon-App-"):-4]          # 例: 83.5x83.5@2x
            size, scale = stem.split("@")
            px = round(float(size.split("x")[0]) * int(scale[0]))
            sq.resize((px, px), Image.LANCZOS).save(os.path.join(ios, fn))
print("done")
