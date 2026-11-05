#!/usr/bin/env python3
"""Verkleinert die Icon-Assets im dist/-Verzeichnis.

Tabler Icons liefert 4908 Icons in vier Font-Formaten aus. Die App nutzt davon
gut hundert Icons, und von den Formaten nur woff2 (eot/ttf/woff sind für
IE <=11 bzw. Android <=4.3 und werden von keinem aktuellen Browser geladen).

Das Skript scannt dist/ nach benutzten .ti-*-Klassen, subsettet die woff2 auf
genau diese Glyphen, schreibt ein passend reduziertes CSS und entfernt die
Legacy-Formate.

Bricht ab, wenn eine benutzte ti-*-Klasse im Icon-Set unbekannt ist - das ist
entweder ein Tippfehler oder ein dynamisch zusammengebauter Klassenname, der
sonst nach dem Subsetting stumm als leeres Kästchen erschiene.
"""

from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path

# ti-Klassen, die keine Icons sind, sondern Layout-Modifier aus tabler.min.css.
KEINE_ICONS = {"ti-sm", "ti-md", "ti-lg", "ti-xl"}

ICON_REGEX = re.compile(r"\bti-[a-z0-9][a-z0-9-]*")
CSS_REGEL_REGEX = re.compile(r"\.(ti-[a-z0-9-]+):before\{content:\"\\([0-9a-f]+)\"\}")

FONT_FACE = (
    '@font-face{font-family:"tabler-icons";font-style:normal;font-weight:400;'
    'src:url("../fonts/tabler-icons.woff2?v{version}") format("woff2")}'
)
BASIS_REGEL = (
    '.ti{font-family:"tabler-icons" !important;speak:none;font-style:normal;'
    "font-weight:normal;font-variant:normal;text-transform:none;line-height:1;"
    "-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}"
)
LEGACY_FORMATE = ("tabler-icons.eot", "tabler-icons.ttf", "tabler-icons.woff")


def fehler(nachricht: str) -> None:
    print(f"FEHLER: {nachricht}", file=sys.stderr)
    sys.exit(1)


def icon_katalog(css: str) -> dict[str, int]:
    """Bildet .ti-name -> Unicode-Codepoint aus dem vollständigen Icon-CSS."""
    return {name: int(hexcode, 16) for name, hexcode in CSS_REGEL_REGEX.findall(css)}


def benutzte_klassen(dist: Path) -> set[str]:
    """Sammelt alle ti-*-Klassen aus PHP- und projekteigenen CSS-Dateien."""
    gefunden: set[str] = set()
    quellen = list(dist.rglob("*.php"))
    quellen += [
        pfad
        for pfad in dist.rglob("*.css")
        if not pfad.name.startswith("tabler")
    ]
    for pfad in quellen:
        gefunden |= set(ICON_REGEX.findall(pfad.read_text(encoding="utf-8")))
    return gefunden - KEINE_ICONS


def subsette_font(quelle: Path, ziel: Path, codepoints: set[int]) -> None:
    unicodes = ",".join(f"U+{cp:04x}" for cp in sorted(codepoints))
    ergebnis = subprocess.run(
        [
            "pyftsubset",
            str(quelle),
            f"--unicodes={unicodes}",
            "--flavor=woff2",
            "--layout-features=",
            "--no-hinting",
            "--desubroutinize",
            f"--output-file={ziel}",
        ],
        capture_output=True,
        text=True,
    )
    if ergebnis.returncode != 0:
        letzte_zeilen = "\n".join(ergebnis.stderr.strip().splitlines()[-5:])
        sys.exit(f"FEHLER: pyftsubset ist gescheitert, siehe README zur Version:\n{letzte_zeilen}")


def main() -> None:
    dist = Path(sys.argv[1] if len(sys.argv) > 1 else "dist").resolve()
    if not dist.is_dir():
        fehler(f"dist-Verzeichnis nicht gefunden: {dist}")

    css_pfad = dist / "assets/css/tabler-icons.min.css"
    woff2_pfad = dist / "assets/fonts/tabler-icons.woff2"
    if not css_pfad.is_file() or not woff2_pfad.is_file():
        fehler("tabler-icons.min.css oder tabler-icons.woff2 fehlt in dist/")

    css_original = css_pfad.read_text(encoding="utf-8")
    katalog = icon_katalog(css_original)
    if not katalog:
        fehler("Keine Icon-Regeln in tabler-icons.min.css gefunden - Format geändert?")

    version_treffer = re.search(r"tabler-icons\.woff2\?v([0-9.]+)", css_original)
    version = version_treffer.group(1) if version_treffer else "1"

    benutzt = benutzte_klassen(dist)
    unbekannt = sorted(benutzt - katalog.keys())
    if unbekannt:
        fehler(
            "Unbekannte ti-*-Klassen (Tippfehler oder dynamisch erzeugt):\n  "
            + "\n  ".join(unbekannt)
            + "\n\nEntweder korrigieren oder in KEINE_ICONS aufnehmen."
        )
    if not benutzt:
        fehler("Keine Icon-Klassen gefunden - Scan greift nicht.")

    codepoints = {katalog[name] for name in benutzt}

    groesse_css_vorher = css_pfad.stat().st_size
    groesse_font_vorher = woff2_pfad.stat().st_size

    subsette_font(woff2_pfad, woff2_pfad, codepoints)

    regeln = "".join(
        f'.{name}:before{{content:"\\{katalog[name]:x}"}}' for name in sorted(benutzt)
    )
    css_pfad.write_text(
        f"/*! Tabler Icons {version} - subgesettet auf {len(benutzt)} Icons "
        f"(scripts/optimize_assets.py) */"
        + FONT_FACE.replace("{version}", version)
        + BASIS_REGEL
        + regeln,
        encoding="utf-8",
    )

    entfernt = 0
    for name in LEGACY_FORMATE:
        pfad = dist / "assets/fonts" / name
        if pfad.is_file():
            entfernt += pfad.stat().st_size
            pfad.unlink()

    karte = dist / "assets/css/tabler-icons.min.css.map"
    if karte.is_file():
        entfernt += karte.stat().st_size
        karte.unlink()

    def kb(anzahl: int) -> str:
        return f"{anzahl / 1024:.0f} KB"

    gespart = (
        groesse_css_vorher
        - css_pfad.stat().st_size
        + groesse_font_vorher
        - woff2_pfad.stat().st_size
        + entfernt
    )
    print(f"    Icons:        {len(benutzt)} von {len(katalog)} behalten")
    print(f"    woff2:        {kb(groesse_font_vorher)} -> {kb(woff2_pfad.stat().st_size)}")
    print(f"    Icon-CSS:     {kb(groesse_css_vorher)} -> {kb(css_pfad.stat().st_size)}")
    print(f"    Legacy-Fonts: {kb(entfernt)} entfernt")
    print(f"    Ersparnis:    {kb(gespart)}")


if __name__ == "__main__":
    main()
