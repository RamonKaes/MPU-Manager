<?php
declare(strict_types=1);

/**
 * Master-Liste aller möglichen Klienten-Aufgaben.
 *
 * Struktur pro Eintrag:
 *   'task_key'    => eindeutiger Bezeichner (wird in client_tasks.task_key gespeichert)
 *   'label'       => Anzeigename in der Akte
 *   'description' => kurze Erläuterung für den Berater (Tabellenhinweis)
 *   'info'        => ausführlicher Beratertext für das Info-Modal
 *   'priority'    => 1 (hoch) bis 3 (niedrig) – steuert die Reihenfolge in der Anzeige
 *
 * Änderungen an BK-5 oder gesetzlichen Anforderungen:
 * Nur diese Datei anpassen. Bereits in client_tasks gespeicherte Einträge
 * bleiben unberührt; neue Tasks werden beim nächsten Laden der Akte ergänzt.
 */

return [

    // ── Für alle Klienten ────────────────────────────────────────────────────
    'alle' => [
        [
            'task_key'       => 'akteneinsicht',
            'label'          => 'Akteneinsicht',
            'description'    => 'Vollständige Behördenakte angefordert und fotokopiert.',
            'info'           => "Rechtsgrundlage: § 29 VwVfG (Akteneinsichtsrecht). Der Klient hat Anspruch auf vollständige Einsicht in alle bei der Fahrerlaubnisbehörde gespeicherten Unterlagen.\n\nVorgehen: Schriftlicher Antrag bei der zuständigen Fahrerlaubnisbehörde. Die Behörde hat i.d.R. bis zu einem Monat Zeit zur Bearbeitung. Bei Verzögerung: schriftliche Mahnung mit Fristsetzung.\n\nAuf Vollständigkeit prüfen: Bußgeldbescheid oder Urteil, Blut- bzw. Atemalkohol-Befund (BAK/AAK), Polizeibericht, ggf. frühere MPU-Gutachten, Schriftwechsel mit der Behörde, Fahrerlaubnisakte inkl. Registerauszüge.\n\nPraxistipp: Von jedem Dokument eine Kopie anfertigen und chronologisch abheften. Unvollständige Akten sofort anmahnen – fehlende Unterlagen können die MPU-Vorbereitung erheblich verzögern.",
            'priority'       => 1,
            'group'          => 'Vorbereitung',
            'group_priority' => 1,
        ],
        [
            'task_key'       => 'erste_hilfe',
            'label'          => 'Erste-Hilfe-Nachweis',
            'description'    => 'Gültiger Erste-Hilfe-Kurs (nicht älter als 3 Jahre bei BKF, sonst kein Pflichtdatum – aber Gutachter fragen oft danach).',
            'info'           => "Rechtliche Grundlage: Für Berufskraftfahrer (BKF) ist ein gültiger Erste-Hilfe-Nachweis nach § 5 BKrFQV Pflicht (max. 3 Jahre alt). Bei allen anderen Fahrerlaubnisklassen gibt es kein gesetzliches Ablaufdatum, jedoch fragen Gutachter im MPU-Gespräch regelmäßig danach.\n\nEmpfehlung: Kurs nicht älter als 2 Jahre zum MPU-Termin. Anbieter: ADAC, DRK, Malteser, Johanniter, ASB – Dauer i.d.R. 9 Stunden (1 Tag).\n\nNachweis: Originalzertifikat mit Datum, Anbieterstempel und Unterschrift des Kursleiters. Kopie für die Akte anfertigen.\n\nPraxistipp: Den Kurs frühzeitig buchen – Plätze sind oft mehrere Wochen im Voraus ausgebucht. Ein aktueller Nachweis zeigt dem Gutachter Verantwortungsbewusstsein und Vorbereitung.",
            'priority'       => 2,
            'group'          => 'Vorbereitung',
            'group_priority' => 1,
        ],
        [
            'task_key'       => 'sehtest',
            'label'          => 'Sehtest-Nachweis',
            'description'    => 'Aktueller Sehtest vom Optiker oder Augenarzt.',
            'info'           => "Rechtsgrundlage: § 12 FeV i.V.m. Anlage 6 – Mindestanforderungen an das Sehvermögen.\n\nAnforderungen Klasse B: Visus mind. 0,5 auf jedem Auge (ggf. mit Sehhilfe), beidäugiges Gesichtsfeld mind. 120° horizontal. Für LKW/Bus (C/D) gelten strengere Werte (je 0,8 bzw. 0,1).\n\nWer stellt den Nachweis aus: Optiker (einfacher Sehtest, ausreichend für Klasse B), Augenarzt (erforderlich bei Auffälligkeiten oder Klassen C/D/E).\n\nAktualität: Empfehlung max. 2 Jahre alt zum MPU-Termin. Bei bekannten Erkrankungen (Grauer Star, Glaukom etc.) Augenarztbefund bevorzugen.\n\nPraxistipp: Trägt der Klient eine Brille oder Kontaktlinsen, muss der Nachweis mit Sehhilfe erstellt werden. Den Klienten darauf hinweisen, die Sehhilfe auch zum MPU-Termin mitzubringen.",
            'priority'       => 2,
            'group'          => 'Vorbereitung',
            'group_priority' => 1,
        ],
    ],

    // ── Alkohol-spezifische Tasks ────────────────────────────────────────────
    'alkohol' => [
        [
            'task_key'       => 'abstinenzvertrag',
            'label'          => 'Abstinenzvertrag mit Labor',
            'description'    => 'Schriftlicher Vertrag mit dem beauftragten Labor liegt vor (Frequenz, Methode, Kostentragung geregelt). Bei Mischkonsum muss der Vertrag beide Substanzen abdecken – ein kombinierter Vertrag ist ausreichend.',
            'info'           => "Der Abstinenzvertrag ist das Rückgrat der Abstinenzdokumentation. Ohne lückenloses, vertraglich geregeltes Screening wird kein Gutachter eine positive Prognose stellen.\n\nVertragsinhalt muss regeln: beauftragtes Labor (Name, Adresse), Screening-Methoden (Urin und/oder Haar), Häufigkeit (s.u.), Spontanproben auf Anforderung, Kostenübernahme durch den Klienten, Weitergabe der Befunde an den Berater.\n\nCTU-Mindestfrequenzen (Beurteilungskriterien für die Fahreignung – Alkohol): Urinproben max. 14 Tage Abstand, Haaranalysen max. 95 Tage Abstand. Lücken werden vom Gutachter wie positive Befunde gewertet.\n\nAkkreditierte Labore in der Region (forensische Screenings nach CTU-Kriterien):\n– MLD Medizinische Laboratorien Düsseldorf: Nordstr. 44, 40477 Düsseldorf · Tel: 0211 49780\n– UKD Institut für Rechtsmedizin: Moorenstr. 5, 40225 Düsseldorf · Tel: 0211 8104555\n– TOLAB: Hüttenstr. 31a, 40215 Düsseldorf · Tel: 0211 377384\n– Invitalab (Neuss): Stresemannallee 4b, 41460 Neuss · Tel: 02131 1259690\n\nPraxistipp: Vertrag im Original aufbewahren und Kopie in die Beratungsakte nehmen. Dem Klienten erklären, dass nicht erscheinen zur Probe (auch bei Krankheit) einer positiven Probe gleichgestellt werden kann.",
            'priority'       => 1,
            'group'          => 'Vorbereitung',
            'group_priority' => 1,
        ],
        [
            'task_key'       => 'leberwerte_peth',
            'label'          => 'Leberwerte / PEth-Basisbefund',
            'description'    => 'Aktueller Laborbefund (GOT, GPT, GGT, CDT bzw. PEth) als Ausgangswert zu Beginn der Abstinenzphase.',
            'info'           => "Der Basisbefund dokumentiert den Zustand der Leber und den Alkohol-Biomarker-Status zu Beginn der Abstinenzphase. Er ist unverzichtbar als Vergleichswert für spätere Verlaufsbefunde.\n\nRelevante Parameter:\n– GOT (AST), GPT (ALT), GGT: klassische Leberwerte, erhöht bei chronischem Alkoholkonsum\n– CDT (Carbohydrat-defizientes Transferrin): Marker für regelmäßigen Konsum > 50–60 g Alkohol/Tag über mind. 2 Wochen; Halbwertszeit ca. 2 Wochen\n– PEth (Phosphatidylethanol): direktester und spezifischster Alkohol-Biomarker; PEth > 20 ng/ml: Hinweis auf regelmäßigen Konsum; PEth > 210 ng/ml: starker Hinweis auf Hochrisikokonsum; Halbwertszeit ca. 2–4 Wochen\n– MCV (mittleres Erythrozytenvolumen): erhöht bei chronischem Alkoholabusus, normalisiert sich langsam\n\nBefund einholen: Hausarzt oder internistisches Labor – Blutabnahme genügt. Kosten übernimmt i.d.R. die Krankenkasse bei medizinischer Indikation.\n\nPraxistipp: Befund so früh wie möglich (Tag 1 der Abstinenz) einholen. Spätere Verlaufsbefunde nach 3, 6 und 12 Monaten zeigen die Normalisierung und belegen die Abstinenz biochemisch.",
            'priority'       => 1,
            'group'          => 'Vorbereitung',
            'group_priority' => 1,
        ],
        [
            'task_key'       => 'selbsthilfegruppe',
            'label'          => 'Selbsthilfegruppe (AA/Kreuzbund)',
            'description'    => 'Regelmäßige Teilnahme dokumentiert. Gutachter werten dies als Stabilitätsindikator (BK-5, Kap. 3.12).',
            'info'           => "Selbsthilfegruppen sind nach BK-5 (Beurteilungskriterien für die Fahreignung, Kapitel 3.12) ein anerkannter Stabilitäts- und Rückfallpräventionsindikator. Gutachter legen großen Wert auf regelmäßige, freiwillige Teilnahme.\n\nGeeignete Gruppen: AA (Anonyme Alkoholiker) – weit verbreitet, kostenlos, anonym; Kreuzbund – konfessionell, aber offen für alle; Guttempler – abstinenzorientiert.\n\nDokumentation: Regelmäßige Teilnahme (mind. 1× pro Woche empfohlen) durch Unterschriftenliste des Gruppenbetreuers oder offizielle Bestätigung der Geschäftsstelle belegen. Viele Gruppen stellen auf Anfrage eine schriftliche Teilnahmebestätigung aus.\n\nWas der Gutachter erwartet: Nicht nur formale Teilnahme, sondern eine glaubwürdige Auseinandersetzung mit den eigenen Mustern. Der Klient sollte erklären können, was er in der Gruppe gelernt hat und wie dies seinen Alltag verändert.\n\nPraxistipp: Früh beginnen – je länger die dokumentierte Teilnahme, desto überzeugender. Gruppenbestätigungen sammeln und chronologisch abheften.",
            'priority'       => 3,
            'group'          => 'Vorbereitung',
            'group_priority' => 1,
        ],
    ],

    // ── Drogen-spezifische Tasks ─────────────────────────────────────────────
    'drogen' => [
        [
            'task_key'       => 'abstinenzvertrag_drogen',
            'label'          => 'Abstinenzvertrag mit Labor (Drogen)',
            'description'    => 'Schriftlicher Vertrag mit dem beauftragten Labor liegt vor (inkl. Haaranalyse-Vereinbarung).',
            'info'           => "Der Abstinenzvertrag ist das zentrale Dokument für die Drogen-Abstinenzdokumentation und Grundvoraussetzung für eine positive MPU-Prognose.\n\nVertragsinhalt muss regeln: beauftragtes Labor (Name, Adresse), Screening-Methoden (Urin-Schnelltest und/oder Haaranalyse), Häufigkeit (s.u.), Spontanproben auf Anforderung, Kostenübernahme, Weitergabe der Befunde an den Berater.\n\nCTU-Mindestfrequenzen: Urinproben max. 14 Tage Abstand, Haaranalysen max. 95 Tage Abstand. Jede Lücke wird vom Gutachter wie ein positiver Befund gewertet.\n\nBesonderheit Drogen: Haaranalysen sind bei Drogenproblematik besonders aussagekräftig – 3 cm Haar entspricht ca. 3 Monaten Konsum-/Abstinenzhistorie. Eine 6-cm-Probe deckt 6 Monate ab.\n\nAkkreditierte Labore in der Region (forensische Screenings nach CTU-Kriterien):\n– MLD Medizinische Laboratorien Düsseldorf: Nordstr. 44, 40477 Düsseldorf · Tel: 0211 49780\n– UKD Institut für Rechtsmedizin: Moorenstr. 5, 40225 Düsseldorf · Tel: 0211 8104555\n– TOLAB: Hüttenstr. 31a, 40215 Düsseldorf · Tel: 0211 377384\n– Invitalab (Neuss): Stresemannallee 4b, 41460 Neuss · Tel: 02131 1259690\n\nPraxistipp: Dem Klienten klar machen, dass auch nicht erscheinen zur Probe (selbst bei Krankheit oder Urlaub) einer positiven Probe gleichgestellt werden kann. Im Zweifelsfall Probe trotz Erkrankung durchführen lassen und ärztliches Attest beifügen.",
            'priority'       => 1,
            'group'          => 'Vorbereitung',
            'group_priority' => 1,
        ],
        [
            'task_key'       => 'drogenscreening_basis',
            'label'          => 'Drogen-Basis-Screening',
            'description'    => 'Erster Urin- oder Haar-Befund als Ausgangswert (negativ) zu Beginn der Abstinenzphase dokumentiert.',
            'info'           => "Das Basis-Screening dokumentiert den Einstiegspunkt der Abstinenzphase und ist der erste Nachweis gegenüber dem Gutachter.\n\nMethoden im Vergleich:\n– Urinprobe: schnell und günstig, Nachweisfenster je nach Substanz 2–7 Tage (Cannabis bei Gelegenheitskonsumenten bis 30 Tage, bei chronischem Konsum bis 90 Tage möglich);\n– Haaranalyse: teurer, aber aussagekräftiger – 1 cm Haar entspricht ca. 1 Monat; 3-cm-Probe (Standard) = ca. 3 Monate; 6-cm-Probe = ca. 6 Monate Konsum-/Abstinenzhistorie.\n\nBesonderheit: Auch ein zu Beginn positiver Befund sollte aufbewahrt werden – er belegt den Startzeitpunkt und die anschließende Entwicklung. Die Abstinenzphase beginnt formal jedoch erst mit dem ersten negativen Befund.\n\nPraxistipp: Haarlänge prüfen – mindestens 3 cm (am Hinterkopf) sind für eine aussagekräftige Analyse erforderlich. Ist das Haar zu kurz, zunächst mit Urinproben beginnen und beim nächsten Haartermin ausreichend Länge sicherstellen.",
            'priority'       => 1,
            'group'          => 'Vorbereitung',
            'group_priority' => 1,
        ],
        [
            'task_key'       => 'selbsthilfegruppe_drogen',
            'label'          => 'Selbsthilfegruppe (NA / Freundeskreis)',
            'description'    => 'Regelmäßige Teilnahme dokumentiert (NA, Freundeskreis, KISS o.ä.). BK-5, Kap. 3.12.',
            'info'           => "Selbsthilfegruppen sind nach BK-5 (Kapitel 3.12) ein anerkannter Stabilitäts- und Rückfallpräventionsindikator – auch bei Drogenproblematik.\n\nGeeignete Gruppen: NA (Narcotics Anonymous) – spezialisiert auf illegale Drogen und Medikamente, kostenlos, anonym; Freundeskreise – regionale Selbsthilfeverbände; KISS-Gruppen – niedrigschwellige Kontakt- und Informationsstellen für Selbsthilfe.\n\nDokumentation: Regelmäßige Teilnahme (mind. 1× pro Woche empfohlen) durch Unterschriftenliste des Gruppenbetreuers oder schriftliche Bestätigung der Gruppe belegen.\n\nWas der Gutachter erwartet: Glaubwürdige Auseinandersetzung mit der eigenen Suchtproblematik, nicht nur formale Anwesenheit. Der Klient sollte erklären können, was er in der Gruppe gelernt hat und wie es seinen Alltag verändert.\n\nPraxistipp: Früh beginnen – je länger die dokumentierte Teilnahme, desto überzeugender. NA-Meetings sind in vielen Städten täglich verfügbar; Termine unter na.org.",
            'priority'       => 3,
            'group'          => 'Vorbereitung',
            'group_priority' => 1,
        ],
        [
            'task_key'       => 'substitutionsnachweis',
            'label'          => 'Substitutions-/Therapienachweis (falls relevant)',
            'description'    => 'Bei laufender oder abgeschlossener Substitutionsbehandlung: Arztbericht und aktueller Stand.',
            'info'           => "Relevant bei: laufender oder abgeschlossener Substitutionsbehandlung mit Methadon, Buprenorphin (Subutex/Suboxone) oder anderen Opioiden.\n\nBei laufender Substitution: aktueller Arztbericht des substituierenden Arztes mit Angabe von Substanz, Dosis, Behandlungsdauer, Compliance und Prognose. Hinweis: Eine laufende Substitution schließt die Fahreignung nicht grundsätzlich aus – Gutachter beurteilen Stabilität, Dosiskonstanz und Alltagsfunktion.\n\nBei abgeschlossener Substitution: Entlassungsbericht mit Datum des Behandlungsendes und Bewertung des Verlaufs.\n\nWas der Gutachter prüft: Therapietreue (Compliance), emotionale und soziale Stabilität, Dauer der Abstinenz von Beikonsum, Perspektive auf dauerhafte Abstinenz.\n\nPraxistipp: Den behandelnden Arzt frühzeitig einbeziehen und einen für die MPU-Vorbereitung günstigen Bericht abstimmen. Bei laufender Behandlung: Gutachter benötigen oft Stellungnahme des Arztes zur Fahrtauglichkeit unter Substitution.",
            'priority'       => 2,
            'group'          => 'Vorbereitung',
            'group_priority' => 1,
        ],
    ],

    // ── Punkte-spezifische Tasks ─────────────────────────────────────────────
    'punkte' => [
        [
            'task_key'       => 'faer_auszug',
            'label'          => 'FAER-Auszug aus Flensburg',
            'description'    => 'Aktueller Auszug aus dem Fahreignungsregister (FAER) angefordert und vorgelegt.',
            'info'           => "Das Fahreignungsregister (FAER) beim Kraftfahrt-Bundesamt (KBA) enthält alle relevanten Eintragungen: Punkte, Fahrverbote, Straftaten und Entziehungen.\n\nAuszug beantragen:\n– Online: kba.de → Bürgerservices → FAER-Auskunft (mit BundID/ELSTER-Konto), Kosten 10,20 €\n– Postalisch: Formular auf kba.de, Personalausweis-Kopie beifügen, Kosten 13 €; Bearbeitungszeit ca. 1–2 Wochen\n– Persönlich: KBA Flensburg oder über Straßenverkehrsamt\n\nGültigkeitsdauer: Der Auszug sollte zum MPU-Termin nicht älter als 3 Monate sein. Einen aktuellen Auszug rechtzeitig vor dem Termin neu beantragen.\n\nPraxistipp: Den Auszug sofort nach Erhalt gemeinsam mit dem Klienten durchgehen – jeder Eintrag muss biografisch eingeordnet und reflektiert werden. Unklare Einträge beim KBA oder dem Gericht, das die Strafe verhängt hat, nachfragen.",
            'priority'       => 1,
            'group'          => 'Vorbereitung',
            'group_priority' => 1,
        ],
        [
            'task_key'       => 'faer_analyse',
            'label'          => 'FAER-Delikte biografisch analysiert',
            'description'    => 'Jedes einzelne Delikt im FAER ist mit dem Klienten durchgearbeitet worden (Datum, Kontext, Einsicht). Gutachter prüfen dies intensiv.',
            'info'           => "Die biografische Analyse der FAER-Einträge ist ein Kernthema jedes MPU-Gesprächs. Gutachter erwarten, dass der Klient jeden Eintrag kennt, kontextualisieren und reflektieren kann.\n\nVorgehen in der Beratung: Für jeden Eintrag: Datum, Ort, Art des Verstoßes, äußere Umstände (Stress, Ablenkung, Tageszeit), innere Haltung zum Zeitpunkt, heutige Einschätzung und gezogene Konsequenzen.\n\nMuster erkennen: Häufen sich bestimmte Deliktarten (z.B. Geschwindigkeit)? Gibt es zeitliche Cluster (z.B. Stress­phasen)? Welche Schutzmaßnahmen hat der Klient seitdem ergriffen?\n\nEmpfohlene Arbeitshilfe: Chronologische Tabelle erstellen (Datum | Delikt | Punkte | Strafe | Kontext | Einsicht heute). Diese Tabelle kann der Klient als Gedächtnisstütze in der MPU nutzen.\n\nPraxistipp: Klient muss souverän über alle Einträge sprechen können, ohne nach Daten suchen zu müssen. Mind. 1–2 Übungsgespräche in der Beratung einplanen. Gutachter reagieren skeptisch auf ausweichende oder beschönigende Antworten.",
            'priority'       => 1,
            'group'          => 'Vorbereitung',
            'group_priority' => 1,
        ],
        [
            'task_key'       => 'fahrschule_auffrischung',
            'label'          => 'Fahrschule / Auffrischungskurs (falls relevant)',
            'description'    => 'Bei technischen Delikten (z.B. Rotlicht, Überholen) kann ein dokumentierter Auffrischungskurs die Prognose verbessern.',
            'info'           => "Ein Auffrischungskurs in der Fahrschule oder ein Fahrsicherheitstraining ist nicht gesetzlich vorgeschrieben, kann aber die Prognose des Gutachters spürbar verbessern – besonders bei gehäuften technischen Delikten.\n\nSinnvoll bei: wiederholten Geschwindigkeitsverstößen, Rotlichtverstößen, Überholdelikten, Abstandsdelikten oder mangelnder Fahrpraxis (z.B. nach langer Fahrpause).\n\nAnbieter: ADAC Fahrsicherheitszentren, Fahrschulen (Fahrstunden + Bescheinigung), TÜV/DEKRA-Fahrkurse.\n\nNachweis: Kurzbescheinigung der Fahrschule oder des Veranstalters mit Datum, Inhalt und Unterschrift. Kopie in die Akte nehmen.\n\nWas der Gutachter bewertet: Eigeninitiative und Bereitschaft zur Verhaltensänderung. Der Klient sollte erklären können, was er im Kurs gelernt hat und wie er dieses Wissen im Alltag anwendet.\n\nPraxistipp: Kurs möglichst vor dem MPU-Termin abschließen und den Nachweis zum Gespräch mitbringen.",
            'priority'       => 3,
            'group'          => 'Vorbereitung',
            'group_priority' => 1,
        ],
    ],

    // ── Wiedererteilungs-Checkliste ──────────────────────────────────────────
    // Wird provisioniert wenn suspension_end_date gesetzt oder Antrag bereits eingeleitet.
    'wiedererteilung' => [
        [
            'task_key'       => 'antrag_stellen',
            'label'          => 'Antrag auf Wiedererteilung stellen',
            'description'    => 'Persönliche Vorsprache bei der Fahrerlaubnisbehörde (§ 20 FeV). Kreis Mettmann: Düsseldorfer Str. 26, 40822 Mettmann – mind. 10 Wochen vor Sperrfristende. Düsseldorf: Höherweg 101, 40233 Düsseldorf – Online-Termin zwingend, bargeldlose Zahlung, mind. 12–14 Wochen einplanen.',
            'info'           => "Rechtsgrundlage: § 20 FeV – Neuerteilung der Fahrerlaubnis nach Entziehung. Der Antrag muss persönlich bei der zuständigen Fahrerlaubnisbehörde gestellt werden (zuständig ist die Behörde des Hauptwohnsitzes).\n\nZeitpunkt: Mind. 10–12 Wochen vor Sperrfristende, da die Bearbeitungskette (Antrag → Führungszeugnis → BfF-Auswahl → Aktenversand → MPU-Termin → Gutachten → Bescheid) mehrere Wochen in Anspruch nimmt.\n\nKreis Mettmann – Straßenverkehrsamt Mettmann (Führerscheinstelle):\nAnschrift: Düsseldorfer Str. 26, 40822 Mettmann · Tel: 02104 99-1760 · sva.fuehrerschein@kreis-mettmann.de\nOnline-Termin empfohlen. Dezentrale Abgabe in Bürgerbüros der Städte möglich – Ausnahme: Ratingen und Velbert (dort nur direkt in Mettmann). Achtung 2026: Online-Dienste ab 13.02.2026 zeitweise eingeschränkt; in dieser Phase direkt ins Bürgerbüro.\n\nLandeshauptstadt Düsseldorf – Straßenverkehrsamt, Abt. 33/3:\nAnschrift: Höherweg 101, 40233 Düsseldorf · Tel: 0211 89-91 · neuerteilung@duesseldorf.de\nOnline-Termin zwingend erforderlich (keine Spontanvorsprache). Nur bargeldlose Zahlung vor Ort (Girocard, Kreditkarte, Smartphone – kein Bargeld). Bearbeitungszeit aktuell ca. 8–12 Wochen → mindestens 12–14 Wochen vor Sperrfristende einplanen!\n\nMitzubringen (beide Behörden): Personalausweis oder Reisepass, biometrisches Lichtbild (35 × 45 mm), ggf. alter Führerschein (falls vorhanden), Antragsgebühr 35–50 € (Düsseldorf: nur bargeldlos).\n⚠ ACHTUNG Kreis Mettmann: Nur physische biometrische Passfotos (35 × 45 mm) mitbringen! QR-Codes und digitale Fotos werden von der Behörde nicht akzeptiert – Klienten werden sonst abgewiesen. Foto vorab beim Fotografen oder im Supermarktautomaten machen lassen.\n\nWas passiert nach dem Antrag: Die Behörde prüft, ob eine MPU angeordnet wird (§ 11 FeV). Bei Alkohol- oder Drogenproblematik ist dies in der Regel der Fall. Die Akte wird an die gewählte BfF versandt.\n\nFormulare & Downloads:\nDüsseldorf – Informationsseite: https://service.duesseldorf.de/suche/-/egov-bis-detail/dienstleistung/283/show\nDüsseldorf – Antragsformular PDF: https://fahrschule-black.de/wp-content/uploads/2025/10/Fahrerlaubnisantrag-Stadt-Duesseldorf.pdf\nDüsseldorf – Merkblatt Neuerteilung: https://www.duesseldorf.de/kfz/fuehrerschein/dienste/merkblatt-neuerteilung/\nMettmann – Informationsseite: https://www.kreis-mettmann.de/Kreisverwaltung/Dienstleistungen/Entzogener-F%C3%BChrerschein.php\nMettmann – Ärztl. Bescheinigung Anlage 5.1 FeV (Klassen C/D): https://www.kreis-mettmann.de/loadDocument.phtml?FID=2023.2351.1&Ext=PDF\nMettmann – Gesundheitsfragebogen: https://www.kreis-mettmann.de/loadDocument.phtml?FID=2023.2201.1&Ext=PDF\nMettmann – Online-Antrag Klasse B (⚠ ab 13.02.2026 zeitweise außer Betrieb): https://www5.kreis-mettmann.de/sva-formulare/fs-umtausch/\n\nPraxistipp: Den Klienten auf die Behördenkommunikation vorbereiten: sachlich, kooperativ, keine Diskussionen über die MPU-Anordnung. Termin frühzeitig buchen – bei Düsseldorf teils mehrere Wochen Vorlaufzeit.",
            'days_before_end' => 84, // 12 Wochen (allg. Empfehlung); Mettmann: mind. 70 Tage
            'priority'       => 1,
            'group'          => 'Wiedererteilung',
            'group_priority' => 2,
        ],
        [
            'task_key'       => 'fuehrungszeugnis_o',
            'label'          => 'Führungszeugnis Belegart O',
            'description'    => 'Im selben Termin beim Bürgeramt beantragen (direkt an Fahrerlaubnisbehörde). Bearbeitungszeit ca. 2–3 Wochen.',
            'info'           => "Das Führungszeugnis Belegart 'O' (behördliches Führungszeugnis) wird nicht an den Antragsteller, sondern direkt an die Fahrerlaubnisbehörde gesandt. Es ist ein Pflichtbestandteil des Wiedererteilungsverfahrens.\n\nBeantragen: Beim örtlichen Bürgeramt (Einwohnermeldeamt), idealerweise am selben Tag wie der Antrag auf Wiedererteilung, um Zeit zu sparen. Personalausweis und Angabe der Empfängerbehörde (Name und Adresse der Fahrerlaubnisbehörde) mitbringen.\n\nAlternativ online: Über das BundID-Portal des Bundesamts für Justiz (bundesjustizamt.de), sofern ein ELSTER-Zertifikat oder BundID-Konto vorhanden ist. Das Zeugnis wird dann postalisch an die Behörde versandt.\n\nKosten: 13 €. Bearbeitungszeit: i.d.R. 2–3 Wochen über das Bundesamt für Justiz.\n\nPraxistipp: Nicht vergessen – ohne Führungszeugnis kann die Behörde das Verfahren nicht abschließen. Klient darauf hinweisen, die Bearbeitungszeit einzuplanen und ggf. nachzuhaken, wenn nach 3 Wochen keine Rückmeldung der Behörde erfolgt.",
            'days_before_end' => 84, // gleicher Termin wie Antragstellung
            'priority'       => 1,
            'group'          => 'Wiedererteilung',
            'group_priority' => 2,
        ],
        [
            'task_key'       => 'abstinenz_check',
            'label'          => 'Abstinenz-Screenings vollständig prüfen',
            'description'    => 'Lückenlose Screenings nach CTU-Kriterien sicherstellen. Bei Haaranalyse: finalen Probentermin vereinbaren sobald erforderliche Haarlänge erreicht.',
            'info'           => "Vollständige und lückenlose Abstinenzdokumentation ist die wichtigste Voraussetzung für ein positives MPU-Gutachten. Jede Lücke oder Unregelmäßigkeit wird vom Gutachter kritisch bewertet – im Zweifel wie ein positiver Befund.\n\nCTU-Mindestanforderungen:\n– Urinproben: max. 14 Tage Abstand zwischen zwei Proben\n– Haaranalysen: max. 95 Tage Abstand (ca. 3 Monate)\n– Empfohlene Gesamtdauer: mind. 12 Monate lückenloser Nachweise\n\nJetzt prüfen: Alle Befunde chronologisch auflisten. Gibt es Lücken? Stimmen die Abstände? Liegen alle Originalbefunde vor?\n\nHaarprobe-Timing: Die letzte Haarprobe muss vor dem MPU-Termin liegen und eine ausreichende Haarlänge (mind. 3 cm = 3 Monate, besser 6 cm) aufweisen. Termin für die finale Probe frühzeitig vereinbaren, sobald die erforderliche Haarlänge erreicht ist.\n\nPraxistipp: Alle Originalbefunde in einem Ordner gesammelt und sortiert zum MPU-Termin mitbringen. Lücken, die unvermeidbar waren (z.B. stationärer Krankenhausaufenthalt), müssen mit Dokumenten belegt werden.",
            'days_before_end' => 98, // 14 Wochen – muss vor MPU-Termin abgeschlossen sein
            'priority'       => 1,
            'group'          => 'Wiedererteilung',
            'group_priority' => 2,
        ],
        [
            'task_key'       => 'bff_waehlen',
            'label'          => 'Begutachtungsstelle (BfF) wählen & benennen',
            'description'    => 'Amtlich anerkannte BfF auswählen und der Fahrerlaubnisbehörde mitteilen, damit die Akte versandt werden kann.',
            'info'           => "Die Begutachtungsstelle für Fahreignung (BfF) muss amtlich anerkannt sein (§ 66 FeV). Die Wahl liegt beim Klienten – die Fahrerlaubnisbehörde kann keine bestimmte Stelle vorschreiben. Da der Kreis Mettmann keine eigenen BfF-Standorte hat, nutzen Klienten aus Mettmann und Düsseldorf in der Regel die Stellen in Düsseldorf.\n\nBfF-Standorte Düsseldorf (alle amtlich anerkannt):\n– TÜV NORD Mobilität: Graf-Adolf-Str. 59, 40210 Düsseldorf · Tel: 0211 9365670\n– pima-mpu GmbH: Konrad-Adenauer-Platz 12, 40210 Düsseldorf · Tel: 0211 9365437\n– AVUS GmbH: Friedrich-Ebert-Str. 32, 40210 Düsseldorf · Tel: 0211 17930280\n– 1A Begutachtungsstelle: Oststr. 84, 40210 Düsseldorf · Tel: 0211 92323064\n– ABV GmbH: Charlottenstr. 49, 40210 Düsseldorf · Tel: 0211 83022074\n\nKriterien bei der Auswahl: Spezialisierung auf die Fragestellung (Alkohol, Drogen, Punkte), Terminverfügbarkeit und Wartezeiten, Anfahrtsweg für den Klienten, Ruf und Erfahrung (Empfehlungen aus dem Netzwerk).\n\nVorgehen: Klient wählt BfF aus, teilt die Wahl schriftlich (oder persönlich) der Fahrerlaubnisbehörde mit. Die Behörde versendet die Akte dann i.d.R. innerhalb von 1–2 Wochen.\n\nPraxistipp: BfF-Wahl nicht zu lange hinauszögern – Wartezeiten für Termine betragen oft 4–8 Wochen. Empfehlung: Direkt nach der Antragstellung auswählen und benennen.",
            'days_before_end' => 77, // 11 Wochen – direkt nach Antragstellung
            'priority'       => 2,
            'group'          => 'Wiedererteilung',
            'group_priority' => 2,
        ],
        [
            'task_key'       => 'bff_akteneingang',
            'label'          => 'Akteneingang bei BfF bestätigt',
            'description'    => 'Ca. 2–4 Wochen nach Antragstellung bei der Begutachtungsstelle nachfragen, ob die Akte eingegangen ist.',
            'info'           => "Ohne Akteneingang bei der BfF kann kein Untersuchungstermin vereinbart werden. Dieser Schritt wird häufig unterschätzt und führt zu vermeidbaren Verzögerungen.\n\nVorgehen: Ca. 2–4 Wochen nach Antragstellung telefonisch bei der gewählten BfF nachfragen, ob die Akte eingegangen ist. Falls nein: bei der Fahrerlaubnisbehörde nachfragen, ob die Akte bereits versandt wurde.\n\nHäufige Ursachen für Verzögerungen: Behörde hat Akte noch nicht versandt (z.B. weil Führungszeugnis noch aussteht), Akte an falsche Adresse versandt, Bearbeitungsrückstand bei der BfF.\n\nEskalation: Falls nach 4 Wochen weder Behörde noch BfF den Aktenversand bestätigen können, schriftliche Nachfrage mit Fristsetzung bei der Fahrerlaubnisbehörde.\n\nPraxistipp: Den Klienten ermutigen, diesen Schritt selbst zu erledigen und zu dokumentieren (Datum, Uhrzeit, Gesprächspartner notieren). Die Kontrolle stärkt das Bewusstsein für die eigene Verantwortung im Verfahren.",
            'days_before_end' => 56, // 8 Wochen – ca. 2–4 Wo nach Antrag
            'priority'       => 2,
            'group'          => 'Wiedererteilung',
            'group_priority' => 2,
        ],
        [
            'task_key'       => 'mpu_termin',
            'label'          => 'MPU-Termin vereinbart',
            'description'    => 'Unverzüglich nach Akteneingang bei der BfF einen Untersuchungstermin vereinbaren. Wartezeit typisch 4–8 Wochen.',
            'info'           => "Nach Bestätigung des Akteneingangs bei der BfF sollte der Klient unverzüglich einen Termin vereinbaren – Wartezeiten betragen typischerweise 4–8 Wochen, in stark nachgefragten Stellen auch länger.\n\nZur Terminvereinbarung: Direkt bei der BfF anrufen oder online anfragen. Name, Aktenzeichen und Fragestellung (z.B. Alkohol, Drogen, Punkte) angeben. Die BfF koordiniert die einzelnen Prüfungsbausteine (ärztliche Untersuchung, Leistungstests, psychologisches Gespräch).\n\nZeitpuffer einplanen: Zwischen MPU-Termin und Sperrfristende sollten mind. 3–4 Wochen liegen, damit das Gutachten rechtzeitig erstellt, zugestellt und bei der Behörde eingereicht werden kann.\n\nVorbereitung auf den Termin: Alle Unterlagen geordnet mitbringen (Abstinenzbelege, ärztliche Befunde, FAER-Auszug, Erste-Hilfe-Nachweis, Sehtest). Pünktliches Erscheinen – Verspätungen oder Nichterscheinen können als Ablehnung gewertet werden.\n\nPraxistipp: In einer abschließenden Beratungssitzung den gesamten Ablauf des MPU-Tages durchsprechen: Begrüßung, Dokumentenvorlage, Testablauf, psychologisches Gespräch. Häufig auftretende Fragen im Gespräch gemeinsam üben.",
            'days_before_end' => 42, // 6 Wochen – nach Akteneingang
            'priority'       => 2,
            'group'          => 'Wiedererteilung',
            'group_priority' => 2,
        ],
        [
            'task_key'       => 'gutachten_selbst',
            'label'          => 'Gutachten zunächst an Klient zugestellt',
            'description'    => 'Schweigepflichtentbindung so formulieren, dass das Gutachten zuerst an den Klienten geht. Bei negativem Ergebnis Antrag ggf. zurückziehen.',
            'info'           => "Dieser Punkt hat erhebliche rechtliche Konsequenzen und muss frühzeitig mit dem Klienten besprochen werden.\n\nHintergrund: Der Klient kann in der Schweigepflichtentbindungserklärung (die er gegenüber der BfF abgibt) festlegen, dass das Gutachten zunächst an ihn selbst zugestellt wird – und nicht direkt an die Fahrerlaubnisbehörde.\n\nBedeutung: Bei einem negativen Gutachten hat der Klient dann die Möglichkeit, den Antrag auf Wiedererteilung zurückzuziehen, bevor die Behörde das Gutachten erhält. Liegt das Gutachten der Behörde erst vor, ist ein Rückzug wirkungslos und führt automatisch zur ablehnenden Entscheidung (§ 11 Abs. 8 FeV – Nichtvorlage gilt als Eignungsmangel).\n\nVorgehen: Bei der Unterzeichnung der Schweigepflichtentbindung explizit vermerken lassen: 'Gutachten ist zunächst mir als Auftraggeber zu uebersenden.' Viele BfFs bieten dieses Verfahren standardmäßig an.\n\nPraxistipp: Klient darüber aufklären, dass ein zurückgezogener Antrag keine dauerhafte Sperre bedeutet – er kann nach weiterer Vorbereitung erneut einen Antrag stellen. Besser kein Gutachten als ein negatives Gutachten in der Akte.",
            // kein days_before_end – hängt vom tatsächlichen MPU-Termin ab
            'priority'       => 3,
            'group'          => 'Wiedererteilung',
            'group_priority' => 2,
        ],
        [
            'task_key'       => 'gutachten_einreichen',
            'label'          => 'Positives Gutachten bei Behörde eingereicht',
            'description'    => 'Original-Gutachten persönlich oder per Einschreiben bei der Fahrerlaubnisbehörde einreichen. Abholung des neuen Führerscheins klären.',
            'info'           => "Nach Erhalt des positiven Gutachtens muss der Klient dieses unverzüglich bei der Fahrerlaubnisbehörde einreichen – das Verfahren wird erst dadurch abgeschlossen.\n\nEinreichung: Persönlich mit Empfangsbestätigung oder per Einschreiben mit Rückschein. Kopie des Gutachtens für die eigene Akte behalten.\n\nBearbeitungszeit: I.d.R. 2–4 Wochen nach Einreichung. Die Behörde prüft das Gutachten und erlässt den Wiedererteilungsbescheid.\n\nFührererscheinausgabe: Neue Fahrerlaubnis darf erst nach Ablauf der Sperrfrist ausgehändigt werden (§ 69a Abs. 1 StGB). Klient frühzeitig nach dem Abhol-Termin fragen und ggf. Termin beim Straßenverkehrsamt buchen.\n\nPraxistipp: Klient darauf hinweisen, den Führerschein nicht zu früh abzuholen – erst wenn der schriftliche Wiedererteilungsbescheid vorliegt und die Sperrfrist abgelaufen ist. Bei Unklarheiten zur Sperrfrist (z.B. wenn Sperrfrist mit Untersuchungshaft verrechnet wurde) vorab rechtliche Beratung einholen.",
            'days_before_end' => 0, // bis Sperrfristende
            'priority'       => 3,
            'group'          => 'Wiedererteilung',
            'group_priority' => 2,
        ],
    ],

];
