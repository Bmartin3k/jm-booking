#!/usr/bin/env python3
"""Build dist/jm-booking-<version>.zip, ready for WordPress → Plugins → Add New → Upload Plugin."""
import pathlib, re, zipfile

root = pathlib.Path(__file__).resolve().parent.parent
plugin = root / "jm-booking"
version = re.search(r"^\s*\*\s*Version:\s*([\d.]+)", (plugin / "jm-booking.php").read_text(), re.M).group(1)
dist = root / "dist"
dist.mkdir(exist_ok=True)
out = dist / f"jm-booking-{version}.zip"
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for f in sorted(plugin.rglob("*")):
        if f.is_file():
            z.write(f, f.relative_to(root))
print(out)
