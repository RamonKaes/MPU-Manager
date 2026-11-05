<?php
declare(strict_types=1);

/**
 * config/praxis_config.php – Angaben zur eigenen Beratungsstelle
 * -----------------------------------------------------------------------
 * Diese Werte erscheinen auf den Druckdokumenten (*_druck.php), also auf
 * Vertrag, Datenschutzerklärung, Widerrufsbelehrung und Strategie-Vereinbarung.
 * Sie sind rechtlich relevant: Datenschutzerklärung und Widerrufsbelehrung
 * müssen den tatsächlichen Verantwortlichen und die zuständige
 * Aufsichtsbehörde nennen.
 *
 * Vor dem ersten Einsatz alle Werte in eckigen Klammern ersetzen. Die Klammern
 * bleiben bewusst sichtbar im Ausdruck, damit ein nicht ausgefülltes Dokument
 * sofort auffällt.
 *
 * Die Vertrags-, Datenschutz-, Widerrufs- und Vereinbarungstexte fehlen bewusst. Jeder Fork setzt
 * eigene, rechtlich geprüfte Texte in die Druckseiten ein.
 */
return [
    'bezeichnung'       => 'MPU-Beratungsstelle',
    'name'              => '[Name der Beratungsstelle oder verantwortlichen Person]',
    'berufsbezeichnung' => '[Berufsbezeichnung]',
    'strasse'           => '[Straße und Hausnummer]',
    'plz'               => '[PLZ]',
    'ort'               => '[Ort]',
    'telefon'           => '[Telefonnummer]',
    'email'             => '[E-Mail-Adresse]',

    // Die Zuständigkeit richtet sich nach dem Sitz der Beratungsstelle
    // (Landesdatenschutzbehörde des jeweiligen Bundeslandes).
    'aufsichtsbehoerde' => '[Name, Anschrift und Kontaktdaten der zuständigen Datenschutz-Aufsichtsbehörde]',
];
