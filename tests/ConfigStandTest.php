<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Hält den Stand aller Fachwerte fest.
 *
 * Diese Liste ist die einzige erlaubte zweite Fundstelle der Werte aus config/. Sie macht eine
 * Änderung sichtbar: Wer in einem Fork einen Wert anpasst, passt ihn hier bewusst mit an und
 * prüft dabei die Quelle. Die Verhaltenstests lesen ihre Werte weiter aus der Config.
 */
final class ConfigStandTest extends TestCase
{
    /** @return array<string,int|float> */
    private static function flach(array $werte, string $pfad = ''): array
    {
        $aus = [];
        foreach ($werte as $schluessel => $wert) {
            $name = $pfad === '' ? (string)$schluessel : "{$pfad}.{$schluessel}";
            if (is_array($wert)) {
                $aus += self::flach($wert, $name);
            } elseif (is_int($wert) || is_float($wert)) {
                $aus[$name] = $wert;
            }
        }
        return $aus;
    }

    public function testTriageConfig(): void
    {
        $this->assertSame([
            'icd.abhaengigkeit_min' => 3,
            'icd.gefaehrdung' => 2,
            'bak.a2_hoch' => 2.5,
            'bak.giftfestigkeit' => 2.0,
            'bak.a3_ausgepraegt' => 1.6,
            'bak.a3_grenze' => 1.1,
            'stvg_24a.promille' => 0.5,
            'stvg_24a.atem_mg_l' => 0.25,
            'fev_13.promille' => 1.6,
            'thc.grenzwert_ng_ml' => 3.5,
            'thc.trennvermoegen_ng_ml' => 11.0,
            'thc.cooh_gefaehrdung' => 75.0,
            'thc.cooh_regelkonsum' => 150.0,
            'punkte.schwer' => 8,
            'punkte.kritisch' => 7,
            'punkte.eingabe_max' => 99,
            'A1.monate_therapie' => 15,
            'A1.monate_selbstheiler' => 15,
            'A1.karenz' => 9,
            'A2_hoch.monate_therapie' => 12,
            'A2_hoch.monate_selbstheiler' => 15,
            'A2_hoch.karenz' => 6,
            'A2_gefaehrdung.monate_therapie' => 12,
            'A2_gefaehrdung.monate_selbstheiler' => 15,
            'A2_gefaehrdung.karenz' => 6,
            'A4.monate' => 6,
            'A4.karenz' => 3,
            'A_bak_fehlt_erschwerend.monate' => 12,
            'A_bak_fehlt_erschwerend.karenz' => 6,
            'A_bak_fehlt.monate' => 6,
            'A_bak_fehlt.karenz' => 3,
            'D1.monate_stationaer' => 15,
            'D1.monate_therapie' => 15,
            'D1.monate_selbstheiler' => 15,
            'D1.karenz' => 9,
            'D2_gefaehrdung.monate_therapie' => 12,
            'D2_gefaehrdung.monate_selbstheiler' => 15,
            'D2_gefaehrdung.karenz' => 6,
            'D2_kokain.standard_monate' => 12,
            'D2_kokain.erschwerend_monate' => 15,
            'D2_kokain.karenz' => 6,
            'D2_hart.standard_monate' => 12,
            'D2_hart.erschwerend_monate' => 15,
            'D2_hart.karenz' => 6,
            'D2_cannabis.standard_monate' => 12,
            'D2_cannabis.erschwerend_monate' => 15,
            'D2_cannabis.karenz' => 3,
            'D3_cannabis.monate' => 6,
            'D3_cannabis.karenz' => 3,
            'D4.monate' => 0,
            'D4.karenz' => 3,
            'V1.monate' => 0,
            'V1.karenz' => 12,
            'V.monate' => 0,
            'V.karenz_standard' => 3,
            'V.karenz_aggression' => 6,
            'M1.monate' => 0,
            'M1.karenz' => 0,
            'M2.monate_therapie' => 12,
            'M2.monate_selbstheiler' => 15,
            'M2.karenz' => 6,
            'M3.monate' => 0,
            'M3.karenz' => 6,
            'M.monate' => 0,
            'M.karenz' => 6,
        ], self::flach(require __DIR__ . '/../config/triage_config.php'));
    }

    public function testCtuConfig(): void
    {
        $this->assertSame([
            'kreatinin.verwertbar_ab_mg_dl' => 20.0,
            'kreatinin.kein_negativbefund_bis_mg_dl' => 5.6,
            'peth.abstinenz_unter_ng_ml' => 20.0,
            'urin_soll.15' => 7,
            'urin_soll.12' => 6,
            'urin_soll.0' => 4,
            'haar.segment_monate_alkohol' => 3,
            'haar.segment_monate_drogen' => 6,
            'haar.luecke_puffer_tage' => 5,
            'haar.tage_je_monat' => 30,
        ], self::flach(require __DIR__ . '/../config/ctu_config.php'));
    }

    public function testBehoerdenConfig(): void
    {
        $this->assertSame([
            'vorlauf_tage.duesseldorf' => 84,
            'vorlauf_tage.mettmann' => 70,
            'vorlauf_tage.standard' => 70,
            'vorwarnung_tage' => 30,
            'radar_wochen' => 12,
        ], self::flach(require __DIR__ . '/../config/behoerden_config.php'));
    }
}
