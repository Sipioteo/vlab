<?php
/**
 * ============================================================================
 *  MODULO DI PRESTITO — TEMPLATE DI STAMPA (PDF)
 * ============================================================================
 *
 *  >>> QUESTO È IL FILE DA SOSTITUIRE PER CAMBIARE IL LAYOUT DEL MODULO. <<<
 *
 *  Nessuna logica applicativa vive qui: i dati arrivano già pronti da
 *  App\Domain\Orders\OrderPdfService::data(). Per adottare il modulo
 *  istituzionale basta riscrivere questo file (HTML + CSS supportati da
 *  dompdf) mantenendo le stesse chiavi.
 *
 *  Variabili disponibili:
 *
 *   $h    callable(mixed): string   — escape HTML (usare SEMPRE sui valori)
 *   $data array {
 *     lab: {name, subtitle, department, email, phone, address, room, website_url}
 *     order: {
 *       id, code, status, status_label, subject, professor, motivation, notes,
 *       pickup_date, pickup_time, return_date, return_time,
 *       submitted_at, picked_up_at, returned_at, items_count, distinct_products
 *     }
 *     user: {display_name, username, email, matricola, course, phone}
 *     items: [ {position, name, brand, category, quantity, units[], units_label, notes} ]
 *     generated_at: string
 *   }
 *
 *  Vincoli tecnici (dompdf):
 *   - solo font di sistema (Helvetica): nessun font remoto, nessuna immagine
 *     esterna (isRemoteEnabled = false);
 *   - niente flexbox/grid: usare tabelle e float;
 *   - le regole di impaginazione in fondo al foglio di stile evitano che una
 *     riga della tabella o il blocco firme vengano spezzati fra due pagine:
 *     non rimuoverle.
 *
 *  @var callable $h
 *  @var array<string,mixed> $data
 */

$lab = $data['lab'];
$order = $data['order'];
$student = $data['user'];
$items = $data['items'];

/** Riga "etichetta: valore" del blocco anagrafico. */
$row = static function (string $label, ?string $value) use ($h): string {
    return '<tr><th>' . $h($label) . '</th><td>' . $h(($value ?? null) !== null && $value !== '' ? $value : '—') . '</td></tr>';
};

/** Contatti del laboratorio, solo quelli valorizzati nelle impostazioni. */
$contacts = array_values(array_filter([
    $lab['address'] ?? '',
    ($lab['room'] ?? '') !== '' ? 'Locale: ' . $lab['room'] : '',
    ($lab['phone'] ?? '') !== '' ? 'Tel. ' . $lab['phone'] : '',
    $lab['email'] ?? '',
], static fn ($v) => is_string($v) && trim($v) !== ''));
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Modulo di prestito <?= $h($order['code']) ?></title>
<style>
    /* ---------------------------------------------------------- impostazioni
       Margini ampi: il contenuto non tocca mai il bordo di stampa.
       Il margine inferiore lascia spazio al piè di pagina fisso.            */
    @page {
        size: A4 portrait;
        margin: 16mm 15mm 22mm 15mm;
    }

    body {
        font-family: Helvetica, Arial, sans-serif;
        font-size: 9.5pt;
        line-height: 1.42;
        color: #16181d;
        margin: 0;
    }

    h1, h2, h3 { margin: 0; font-weight: bold; }

    .muted { color: #55596a; }
    .mono { font-family: "DejaVu Sans Mono", Courier, monospace; }

    /* ------------------------------------------------------------- testata */
    .head { width: 100%; border-collapse: collapse; margin-bottom: 6mm; }
    .head td { vertical-align: top; padding: 0; border: 0; }
    .head .lab-name { font-size: 15pt; letter-spacing: 0.2pt; }
    .head .lab-sub { font-size: 9pt; margin-top: 1mm; }
    .head .lab-contacts { font-size: 8pt; margin-top: 2mm; color: #55596a; }
    .head .code-box {
        width: 52mm;
        border: 1.2pt solid #16181d;
        padding: 2.5mm 3mm;
        text-align: center;
    }
    .head .code-box .code-label {
        font-size: 7.5pt;
        text-transform: uppercase;
        letter-spacing: 0.6pt;
        color: #55596a;
    }
    .head .code-box .code-value { font-size: 14pt; font-weight: bold; margin-top: 1mm; }
    .head .code-box .code-status { font-size: 8pt; margin-top: 1mm; color: #55596a; }

    .rule { border-bottom: 1.2pt solid #16181d; margin-bottom: 4mm; }

    .doc-title { font-size: 12pt; text-transform: uppercase; letter-spacing: 0.8pt; }
    .doc-title-sub { font-size: 8.5pt; color: #55596a; margin-top: 1mm; margin-bottom: 5mm; }

    /* ------------------------------------------------- blocco dati (2 col.) */
    .cols { width: 100%; border-collapse: separate; border-spacing: 4mm 0; margin: 0 0 5mm -4mm; }
    .cols > tbody > tr > td { width: 50%; vertical-align: top; padding: 0; }

    .panel { border: 0.7pt solid #b9bdca; padding: 2.5mm 3mm; }
    .panel-title {
        font-size: 8pt;
        text-transform: uppercase;
        letter-spacing: 0.7pt;
        color: #55596a;
        margin-bottom: 1.5mm;
    }
    table.kv { width: 100%; border-collapse: collapse; }
    table.kv th, table.kv td { text-align: left; vertical-align: top; padding: 0.6mm 0; font-size: 8.8pt; }
    table.kv th { width: 43%; font-weight: normal; color: #55596a; padding-right: 2mm; font-size: 8.2pt; }

    /* ------------------------------------------------- tabella attrezzature */
    .section-title {
        font-size: 9pt;
        text-transform: uppercase;
        letter-spacing: 0.7pt;
        margin-bottom: 1.5mm;
    }
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 5mm; }
    table.items th, table.items td {
        border: 0.7pt solid #b9bdca;
        padding: 1.6mm 2mm;
        text-align: left;
        vertical-align: top;
        font-size: 8.8pt;
    }
    table.items thead th {
        background-color: #eceef3;
        font-size: 8pt;
        text-transform: uppercase;
        letter-spacing: 0.4pt;
    }
    table.items .col-n { width: 8mm; text-align: center; }
    table.items .col-qty { width: 14mm; text-align: center; }
    table.items .col-cat { width: 34mm; }
    table.items .col-units { width: 38mm; }
    table.items .item-brand { font-size: 8pt; color: #55596a; }
    table.items tfoot td { background-color: #f5f6f9; font-size: 8.5pt; }

    .note-block { margin-bottom: 5mm; }
    .note-block .note-body {
        border: 0.7pt solid #b9bdca;
        padding: 2.5mm 3mm;
        min-height: 12mm;
        font-size: 9pt;
    }

    /* -------------------------------------------------------- blocco firme */
    .signatures { width: 100%; border-collapse: separate; border-spacing: 4mm 0; margin: 2mm 0 0 -4mm; }
    .signatures > tbody > tr > td { width: 50%; vertical-align: top; padding: 0; }
    /* ~5 cm di altezza utile per la firma, come da modulo cartaceo. */
    .sign-box { border: 1pt solid #16181d; padding: 2.5mm 3mm; height: 55mm; }
    .sign-box .sign-title {
        font-size: 8.6pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.4pt;
        margin-bottom: 0.8mm;
    }
    .sign-box .sign-hint { font-size: 7pt; color: #55596a; margin-bottom: 3mm; }
    .sign-line { border-bottom: 0.7pt solid #16181d; height: 7mm; }
    .sign-label { font-size: 7.2pt; color: #55596a; padding-top: 0.6mm; margin-bottom: 2.5mm; }
    .sign-label--last { margin-bottom: 0; }
    .sign-date { width: 45%; }

    .declaration { font-size: 7.5pt; color: #55596a; margin-top: 3mm; text-align: justify; }

    /* -------------------------------------------------- piè di pagina fisso */
    .page-footer {
        position: fixed;
        left: 0;
        right: 0;
        bottom: -14mm;
        height: 10mm;
        border-top: 0.7pt solid #b9bdca;
        padding-top: 1.5mm;
        font-size: 7.5pt;
        color: #55596a;
    }
    .page-footer table { width: 100%; border-collapse: collapse; }
    .page-footer td { border: 0; padding: 0; font-size: 7.5pt; }
    .page-footer .right { text-align: right; }
    .page-footer .center { text-align: center; }
    /* dompdf risolve counter(page); il totale pagine non è disponibile via CSS. */
    .page-footer .pagenum:after { content: counter(page); }

    /* =====================================================================
       IMPAGINAZIONE — NON RIMUOVERE
       Una riga non viene mai spezzata a metà fra due pagine, l'intestazione
       della tabella si ripete su ogni pagina e il blocco firme resta intero
       (se non ci sta, passa tutto alla pagina successiva).
       ===================================================================== */
    table       { page-break-inside: auto; }
    thead       { display: table-header-group; }
    tfoot       { display: table-row-group; }
    tr          { page-break-inside: avoid; page-break-after: auto; }
    td, th      { page-break-inside: avoid; }
    .keep       { page-break-inside: avoid; }
    .signatures,
    .signatures > tbody > tr,
    .sign-box   { page-break-inside: avoid; }
    .page-footer { page-break-inside: avoid; }
</style>
</head>
<body>

<!-- piè di pagina ripetuto su ogni pagina -->
<div class="page-footer">
    <table>
        <tr>
            <td>Generato il <?= $h($data['generated_at'] ?? '—') ?></td>
            <td class="center"><?= $h($lab['name']) ?> — <?= $h($order['code']) ?></td>
            <td class="right">Pag. <span class="pagenum"></span></td>
        </tr>
    </table>
</div>

<!-- testata -->
<table class="head">
    <tr>
        <td>
            <div class="lab-name"><?= $h($lab['name']) ?></div>
            <?php if (($lab['subtitle'] ?? '') !== '') : ?>
                <div class="lab-sub"><?= $h($lab['subtitle']) ?></div>
            <?php endif; ?>
            <?php if (($lab['department'] ?? '') !== '') : ?>
                <div class="lab-sub muted"><?= $h($lab['department']) ?></div>
            <?php endif; ?>
            <?php if ($contacts !== []) : ?>
                <div class="lab-contacts"><?= $h(implode(' · ', $contacts)) ?></div>
            <?php endif; ?>
        </td>
        <td class="code-box">
            <div class="code-label">Richiesta n.</div>
            <div class="code-value mono"><?= $h($order['code']) ?></div>
            <div class="code-status"><?= $h($order['status_label']) ?></div>
        </td>
    </tr>
</table>

<div class="rule"></div>

<h1 class="doc-title">Modulo di prestito attrezzature</h1>
<div class="doc-title-sub">
    Da firmare al ritiro e alla riconsegna del materiale. Una copia resta agli atti del laboratorio.
</div>

<!-- dati studente / dati richiesta -->
<table class="cols">
    <tr>
        <td>
            <div class="panel keep">
                <div class="panel-title">Dati dello studente</div>
                <table class="kv">
                    <?= $row('Nome e cognome', $student['display_name']) ?>
                    <?= $row('Username', $student['username']) ?>
                    <?= $row('Email', $student['email']) ?>
                    <?= $row('Matricola', $student['matricola']) ?>
                    <?= $row('Corso di studi', $student['course']) ?>
                    <?= $row('Telefono', $student['phone']) ?>
                </table>
            </div>
        </td>
        <td>
            <div class="panel keep">
                <div class="panel-title">Dati della richiesta</div>
                <table class="kv">
                    <?= $row('Materia / corso', $order['subject']) ?>
                    <?= $row('Docente di riferimento', $order['professor']) ?>
                    <?= $row('Ritiro previsto', trim(($order['pickup_date'] ?? '—') . ' ' . ($order['pickup_time'] ?? ''))) ?>
                    <?= $row('Riconsegna prevista', trim(($order['return_date'] ?? '—') . ' ' . ($order['return_time'] ?? ''))) ?>
                    <?= $row('Richiesta inviata il', $order['submitted_at']) ?>
                    <?= $row('Pezzi totali', (string) $order['items_count']) ?>
                </table>
            </div>
        </td>
    </tr>
</table>

<!-- attrezzature -->
<div class="section-title">Attrezzature in prestito</div>
<table class="items">
    <thead>
        <tr>
            <th class="col-n">#</th>
            <th>Attrezzatura</th>
            <th class="col-cat">Categoria</th>
            <th class="col-units">Unità assegnate</th>
            <th class="col-qty">Q.tà</th>
        </tr>
    </thead>
    <tbody>
    <?php if ($items === []) : ?>
        <tr>
            <td class="col-n">—</td>
            <td colspan="4" class="muted">Nessuna attrezzatura associata alla richiesta.</td>
        </tr>
    <?php else : ?>
        <?php foreach ($items as $item) : ?>
        <tr>
            <td class="col-n"><?= $h($item['position']) ?></td>
            <td>
                <?= $h($item['name']) ?>
                <?php if (($item['brand'] ?? null) !== null) : ?>
                    <div class="item-brand"><?= $h($item['brand']) ?></div>
                <?php endif; ?>
                <?php if (($item['notes'] ?? null) !== null) : ?>
                    <div class="item-brand"><?= $h($item['notes']) ?></div>
                <?php endif; ?>
            </td>
            <td class="col-cat"><?= $h($item['category'] ?? '—') ?></td>
            <td class="col-units mono"><?= $h($item['units_label']) ?></td>
            <td class="col-qty"><?= $h($item['quantity']) ?></td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="4">Totale pezzi</td>
            <td class="col-qty"><strong><?= $h($order['items_count']) ?></strong></td>
        </tr>
    </tfoot>
</table>

<!-- motivazione -->
<div class="note-block keep">
    <div class="section-title">Motivazione della richiesta</div>
    <div class="note-body"><?= $h($order['motivation'] ?? '—') ?></div>
</div>

<?php if (($order['notes'] ?? null) !== null) : ?>
<div class="note-block keep">
    <div class="section-title">Note dello studente</div>
    <div class="note-body"><?= $h($order['notes']) ?></div>
</div>
<?php endif; ?>

<!-- firme: il blocco resta sempre integro su una sola pagina -->
<div class="signatures-block keep">
    <div class="section-title">Firme</div>
    <table class="signatures">
        <tr>
            <td>
                <div class="sign-box">
                    <div class="sign-title">Firma dello studente al RITIRO</div>
                    <div class="sign-hint">
                        Dichiaro di aver ricevuto le attrezzature elencate, in buono stato,
                        e di impegnarmi a restituirle entro la data indicata.
                    </div>
                    <table class="kv">
                        <tr>
                            <td class="sign-date">
                                <div class="sign-line"></div>
                                <div class="sign-label">Data</div>
                            </td>
                            <td></td>
                        </tr>
                    </table>
                    <div class="sign-line"></div>
                    <div class="sign-label">Firma dello studente</div>
                    <div class="sign-line"></div>
                    <div class="sign-label sign-label--last">Firma dell'operatore</div>
                </div>
            </td>
            <td>
                <div class="sign-box">
                    <div class="sign-title">Firma dello studente alla RICONSEGNA</div>
                    <div class="sign-hint">
                        Dichiaro di aver riconsegnato le attrezzature elencate; eventuali
                        anomalie sono annotate dal personale del laboratorio.
                    </div>
                    <table class="kv">
                        <tr>
                            <td class="sign-date">
                                <div class="sign-line"></div>
                                <div class="sign-label">Data</div>
                            </td>
                            <td></td>
                        </tr>
                    </table>
                    <div class="sign-line"></div>
                    <div class="sign-label">Firma dello studente</div>
                    <div class="sign-line"></div>
                    <div class="sign-label sign-label--last">Firma dell'operatore</div>
                </div>
            </td>
        </tr>
    </table>
    <div class="declaration">
        Il materiale è concesso in uso didattico e resta di proprietà del laboratorio. In caso di
        danneggiamento, smarrimento o ritardo nella riconsegna si applica quanto previsto dal
        regolamento del laboratorio, accettato al momento dell'invio della richiesta.
    </div>
</div>

</body>
</html>
