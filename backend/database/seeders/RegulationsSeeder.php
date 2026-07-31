<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Regulation;
use App\Models\RegulationTarget;
use App\Support\Dates;

/**
 * Seeds the three published regulations of SPEC §15.4. Idempotent by slug.
 */
final class RegulationsSeeder
{
    public function run(?callable $out = null): void
    {
        $created = 0;

        $created += $this->upsert([
            'slug' => 'regolamento-generale',
            'title' => 'Regolamento per il prestito delle attrezzature',
            'summary' => 'Regole generali del servizio di prestito: durate, responsabilità, danni e sanzioni.',
            'scope' => 'global',
            'requires_acceptance' => true,
            'position' => 10,
            'body' => <<<'MD'
# Regolamento per il prestito delle attrezzature

## 1. Ambito
Il presente regolamento disciplina il prestito delle attrezzature del Visionary Lab agli studenti del Politecnico di Torino.

## 2. Durata del prestito
La durata massima standard del prestito è indicata nella pagina di prenotazione e viene calcolata in giorni consecutivi, estremi inclusi. Richieste di durata superiore possono essere inviate ma sono soggette ad approvazione esplicita da parte del personale.

## 3. Ritiro e riconsegna
Il ritiro e la riconsegna avvengono presso il laboratorio negli orari pubblicati. Il mancato ritiro entro il periodo di tolleranza comporta l'annullamento della richiesta.

## 4. Responsabilità
Lo studente è personalmente responsabile delle attrezzature ricevute dal momento della consegna fino alla riconsegna. È vietato cedere le attrezzature a terzi.

## 5. Danni e smarrimenti
Danni, malfunzionamenti o smarrimenti devono essere segnalati immediatamente al personale. Il laboratorio si riserva di richiedere il risarcimento del valore di sostituzione.

## 6. Ritardi e sanzioni
La riconsegna in ritardo comporta la sospensione temporanea dal servizio di prestito, secondo le decisioni del personale del laboratorio.

## 7. Accettazione
L'utilizzo della piattaforma richiede l'accettazione del presente regolamento a ogni nuova versione pubblicata.
MD,
        ], []);

        $vrCategory = Category::where('slug', 'tecnologie-interattive')->first();
        $created += $this->upsert([
            'slug' => 'avvertenze-vr',
            'title' => 'Avvertenze per l\'uso dei visori VR',
            'summary' => 'Rischi fotosensibilità ed epilessia, igiene, età minima e durata delle sessioni.',
            'scope' => 'category',
            'requires_acceptance' => true,
            'position' => 20,
            'body' => <<<'MD'
# Avvertenze per l'uso dei visori VR

## Fotosensibilità ed epilessia
Una piccola percentuale di persone può manifestare vertigini, convulsioni, attacchi epilettici o svenimenti se esposta a luci intermittenti o pattern visivi intensi, anche senza precedenti diagnosi. Se hai mai avuto sintomi di questo tipo consulta un medico prima di usare un visore VR. **Interrompi immediatamente l'uso** in caso di vertigini, nausea, disturbi visivi o spasmi.

## Igiene
Utilizza sempre le mascherine igieniche monouso fornite dal laboratorio e riconsegna il visore pulito. Segnala qualsiasi problema igienico riscontrato al ritiro.

## Età minima
L'uso dei visori è riservato a persone di età pari o superiore a 13 anni, secondo le indicazioni dei produttori.

## Durata delle sessioni
Si raccomandano pause di 10-15 minuti ogni 30 minuti di utilizzo. Non utilizzare il visore in movimento o in ambienti non sicuri.
MD,
        ], $vrCategory !== null ? [['target_type' => 'category', 'target_id' => (int) $vrCategory->id]] : []);

        $videoCategory = Category::where('slug', 'video')->first();
        $created += $this->upsert([
            'slug' => 'uso-attrezzature-video',
            'title' => 'Cura e trasporto delle attrezzature video',
            'summary' => 'Indicazioni per il trasporto, la pulizia e la conservazione delle attrezzature video.',
            'scope' => 'category',
            'requires_acceptance' => false,
            'position' => 30,
            'body' => <<<'MD'
# Cura e trasporto delle attrezzature video

- Trasporta sempre le attrezzature nelle custodie fornite.
- Non lasciare mai le attrezzature incustodite o in auto.
- Proteggi corpi macchina e ottiche da pioggia, sabbia e polvere.
- Non pulire le lenti con panni non idonei: usa solo il kit di pulizia.
- Riconsegna le batterie cariche e i cavi riavvolti correttamente.
- Segnala subito qualsiasi malfunzionamento riscontrato durante l'uso.
MD,
        ], $videoCategory !== null ? [['target_type' => 'category', 'target_id' => (int) $videoCategory->id]] : []);

        if ($out !== null) {
            $out("Regolamenti: {$created} nuovi (3 totali).");
        }
    }

    /** @param array<string,mixed> $data @param array<int,array<string,mixed>> $targets */
    private function upsert(array $data, array $targets): int
    {
        $existing = Regulation::withTrashed()->where('slug', $data['slug'])->first();
        if ($existing !== null) {
            return 0;
        }
        $reg = Regulation::create($data + [
            'content_type' => 'markdown',
            'is_active' => true,
            'version' => 1,
            'published_at' => Dates::nowDb(),
        ]);
        foreach ($targets as $target) {
            RegulationTarget::create($target + ['regulation_id' => $reg->id]);
        }
        return 1;
    }
}
