#!/usr/bin/env python3
"""Trägt die Zugangsdaten aus der Umgebung in dist/.htaccess ein.

Eigenes Skript statt sed: `&`, `/` und Leerzeichen im Passwort überstehen dort nicht.
Apache liest den Wert in Anführungszeichen, Anführungszeichen und Backslash werden maskiert.
"""
import os
import re
import sys


def quote(wert: str) -> str:
    if "\n" in wert or "\r" in wert:
        sys.exit("FEHLER: Zeilenumbruch in einer Einstellung, das kann .htaccess nicht darstellen.")
    return '"' + wert.replace("\\", "\\\\").replace('"', '\\"') + '"'


pfad = sys.argv[1]
with open(pfad, encoding="utf-8") as datei:
    text = datei.read()
for name in ("DB_USER", "DB_PASS", "DB_HOST", "DB_NAME"):
    zeile = "SetEnv " + name + " " + quote(os.environ[name])
    text, treffer = re.subn(r"^# SetEnv " + name + r"\s.*$", lambda _: zeile, text, count=1, flags=re.M)
    if treffer != 1:
        sys.exit("FEHLER: Zeile für " + name + " fehlt in " + pfad)
text = re.sub(r"^# SetEnv APP_FORCE_HTTPS\s.*$", "SetEnv APP_FORCE_HTTPS 1", text, count=1, flags=re.M)
with open(pfad, "w", encoding="utf-8") as datei:
    datei.write(text)
