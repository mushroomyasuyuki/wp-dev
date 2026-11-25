#!/usr/bin/env python3
"""Play Console の開発者プロフィール用ヘッダー画像（4096x2304）を作る。
ロゴ画像（透明PNG）を受け取り、白地にロゴと屋号を置く。
実行: python3 tools/make_developer_header.py ロゴ.png 出力.jpg"""
import sys, os
from PIL import Image, ImageDraw, ImageFont, ImageFilter

logo_path, out_path = sys.argv[1], sys.argv[2]
W, H = 4096, 2304
FONT = "/usr/share/fonts/opentype/ipafont-gothic/ipag.ttf"
NAVY = (31, 58, 95)
ORANGE = (240, 138, 36)
GRAY = (95, 108, 125)

bg = Image.new("RGB", (W, H), (255, 255, 255))
d = ImageDraw.Draw(bg)
# うっすらした帯（単調にならないように）
d.rectangle([0, H - 340, W, H], fill=(244, 247, 251))
d.rectangle([0, H - 340, W, H - 328], fill=ORANGE)

logo = Image.open(logo_path).convert("RGBA")
bgl = Image.new("RGBA", logo.size, (255, 255, 255, 255))
bgl.alpha_composite(logo)
logo = bgl.convert("RGB")
lh = 820
lw = round(logo.width * lh / logo.height)
logo = logo.resize((lw, lh), Image.LANCZOS).filter(ImageFilter.UnsharpMask(radius=3, percent=60, threshold=2))

f1 = ImageFont.truetype(FONT, 270)
f2 = ImageFont.truetype(FONT, 104)
t1, t2 = "マッシュオフィス", "ウェブプログラム作ってみませんか"
w1 = d.textlength(t1, font=f1)
w2 = d.textlength(t2, font=f2)
gap = 190
text_w = max(w1, w2)
total = lw + gap + text_w
x0 = (W - total) / 2          # ロゴ+文字の全体を中央に置く（スマホでは左右が切られても残る）
ly = (H - 340 - lh) // 2 + 20
bg.paste(logo, (round(x0), ly))
tx = x0 + lw + gap
ty = ly + lh // 2 - 215
d.text((tx, ty), t1, font=f1, fill=NAVY)
d.rectangle([tx, ty + 365, tx + 360, ty + 381], fill=ORANGE)
d.text((tx, ty + 440), t2, font=f2, fill=GRAY)

os.makedirs(os.path.dirname(out_path) or ".", exist_ok=True)
q = 92
while True:
    bg.save(out_path, "JPEG", quality=q, optimize=True, subsampling=0)
    if os.path.getsize(out_path) <= 900_000 or q <= 60:
        break
    q -= 4
print(out_path, bg.size, os.path.getsize(out_path), "bytes, quality", q)
