<?php

declare(strict_types=1);

use App\Support\Dates;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Seeds the full settings registry of SPEC §10. Idempotent: metadata is
 * upserted, `value` is inserted only when the row does not exist (§10.10).
 */
final class SettingsSeeder
{
    /**
     * key => [type, default, public, nullable, secret, label_it, options]
     *
     * @return array<string, array<string, array{0:string,1:mixed,2:bool,3:bool,4:bool,5:string,6:?array}>>
     */
    private function registry(): array
    {
        $weekly = [
            ['weekday' => 0, 'closed' => true, 'open' => null, 'close' => null],
            ['weekday' => 1, 'closed' => false, 'open' => '09:00', 'close' => '17:00'],
            ['weekday' => 2, 'closed' => false, 'open' => '09:00', 'close' => '17:00'],
            ['weekday' => 3, 'closed' => false, 'open' => '09:00', 'close' => '17:00'],
            ['weekday' => 4, 'closed' => false, 'open' => '09:00', 'close' => '17:00'],
            ['weekday' => 5, 'closed' => false, 'open' => '09:00', 'close' => '14:00'],
            ['weekday' => 6, 'closed' => true, 'open' => null, 'close' => null],
        ];
        $pickupWindows = [
            ['weekday' => 1, 'from' => '09:00', 'to' => '12:30'],
            ['weekday' => 2, 'from' => '09:00', 'to' => '12:30'],
            ['weekday' => 3, 'from' => '09:00', 'to' => '12:30'],
            ['weekday' => 4, 'from' => '09:00', 'to' => '12:30'],
            ['weekday' => 5, 'from' => '09:00', 'to' => '12:00'],
        ];
        $returnWindows = [
            ['weekday' => 1, 'from' => '14:00', 'to' => '17:00'],
            ['weekday' => 2, 'from' => '14:00', 'to' => '17:00'],
            ['weekday' => 3, 'from' => '14:00', 'to' => '17:00'],
            ['weekday' => 4, 'from' => '14:00', 'to' => '17:00'],
            ['weekday' => 5, 'from' => '12:00', 'to' => '14:00'],
        ];
        $roleMap = [
            'cn=vlab-admin,ou=groups,dc=polito,dc=it' => 'admin',
            'cn=tecnici,ou=groups,dc=polito,dc=it' => 'technician',
            'cn=borsisti,ou=groups,dc=polito,dc=it' => 'assistant',
            'cn=studenti,ou=groups,dc=polito,dc=it' => 'student',
        ];
        $roles = ['student', 'technician', 'assistant', 'admin'];

        return [
            'lab' => [
                'lab.name' => ['string', 'Visionary Lab', true, false, false, 'Nome del laboratorio', null],
                'lab.subtitle' => ['string', 'Politecnico di Torino — Prestito attrezzature', true, false, false, 'Sottotitolo mostrato nell\'header', null],
                'lab.department' => ['string', 'DAUIN — Ingegneria del Cinema e dei Mezzi di Comunicazione', true, false, false, 'Dipartimento / corso di riferimento', null],
                'lab.email' => ['string', 'visionarylab@polito.it', true, false, false, 'Email di contatto', null],
                'lab.phone' => ['string', '', true, false, false, 'Telefono', null],
                'lab.address' => ['string', 'Corso Duca degli Abruzzi 24, 10129 Torino', true, false, false, 'Indirizzo', null],
                'lab.room' => ['string', '', true, false, false, 'Aula / locale di ritiro', null],
                'lab.website_url' => ['string', 'https://www.polito.it', true, false, false, 'Sito web istituzionale', null],
                'lab.logo_url' => ['string', '', true, false, false, 'URL del logo (vuoto = logo di default)', null],
                'lab.support_note_it' => ['string', 'Per assistenza scrivi a visionarylab@polito.it', true, false, false, 'Nota di supporto nel footer', null],
            ],
            'hours' => [
                'hours.timezone' => ['string', 'Europe/Rome', true, false, false, 'Fuso orario del laboratorio', null],
                'hours.weekly' => ['json', $weekly, true, false, false, 'Orari di apertura per giorno della settimana', null],
                'hours.pickup_windows' => ['json', $pickupWindows, true, false, false, 'Fasce orarie per il ritiro', null],
                'hours.return_windows' => ['json', $returnWindows, true, false, false, 'Fasce orarie per la riconsegna', null],
                'hours.slot_duration_minutes' => ['int', 30, true, false, false, 'Durata di ogni slot orario (minuti)', null],
            ],
            'booking' => [
                'booking.max_loan_days' => ['int', 7, true, false, false, 'Durata massima del prestito (giorni)', null],
                'booking.max_loan_days_hard_cap' => ['int', 30, true, true, false, 'Limite invalicabile di durata; null = nessun limite assoluto', null],
                'booking.max_orders_per_month' => ['int', 4, true, true, false, 'Numero massimo di prestiti al mese; null = illimitato', null],
                'booking.max_orders_per_year' => ['int', null, true, true, false, 'Numero massimo di prestiti all\'anno; null = illimitato', null],
                'booking.max_active_orders' => ['int', 2, true, true, false, 'Prestiti contemporaneamente attivi per studente; null = illimitato', null],
                'booking.max_items_per_order' => ['int', 10, true, true, false, 'Prodotti distinti per richiesta; null = illimitato', null],
                'booking.max_quantity_per_product_per_order' => ['int', 2, true, false, false, 'Quantità massima dello stesso prodotto in una richiesta', null],
                'booking.min_advance_days' => ['int', 1, true, false, false, 'Preavviso minimo per il ritiro (giorni)', null],
                'booking.max_advance_days' => ['int', 90, true, false, false, 'Anticipo massimo di prenotazione (giorni)', null],
                'booking.buffer_days_between_loans' => ['int', 0, false, false, false, 'Giorni di margine dopo la riconsegna prima di un nuovo prestito', null],
                'booking.pending_locks_stock' => ['bool', true, false, false, false, 'Le richieste in attesa impegnano già la disponibilità', null],
                'booking.allow_exceeding_limits' => ['bool', true, true, false, false, 'Consenti l\'invio di richieste fuori limite (con avviso)', null],
                'booking.cancellation_deadline_hours' => ['int', 24, true, false, false, 'Ore prima del ritiro entro cui lo studente può annullare', null],
                'booking.no_show_grace_hours' => ['int', 48, false, false, false, 'Ore dopo la data di ritiro oltre le quali la richiesta diventa "non ritirata"', null],
                'booking.overdue_grace_hours' => ['int', 0, false, false, false, 'Ore di tolleranza dopo la scadenza prima di segnalare il ritardo', null],
                'booking.require_motivation' => ['bool', true, true, false, false, 'La motivazione è obbligatoria', null],
                'booking.motivation_min_length' => ['int', 20, true, false, false, 'Lunghezza minima della motivazione (caratteri)', null],
                'booking.require_professor' => ['bool', false, true, false, false, 'Il docente di riferimento è obbligatorio', null],
                'booking.require_subject' => ['bool', true, true, false, false, 'La materia/corso è obbligatoria', null],
                'booking.cart_ttl_hours' => ['int', 72, false, false, false, 'Ore dopo cui un carrello inattivo viene svuotato', null],
                'booking.auto_assign_units_on_pickup' => ['bool', true, false, false, false, 'Assegna automaticamente le unità al momento della consegna', null],
            ],
            'regulations' => [
                'regulations.enforce_global_acceptance' => ['bool', true, true, false, false, 'Blocca l\'uso della piattaforma finché i regolamenti globali non sono accettati', null],
                'regulations.enforce_checkout_acceptance' => ['bool', true, true, false, false, 'Richiedi l\'accettazione dei regolamenti di prodotto/categoria al checkout', null],
                'regulations.reaccept_on_version_bump' => ['bool', true, false, false, false, 'Richiedi una nuova accettazione a ogni nuova versione', null],
            ],
            'ldap' => [
                'ldap.mode' => ['enum', 'fake', false, false, false, 'Modalità di autenticazione (l\'env LDAP_MODE ha priorità)', ['fake', 'real']],
                'ldap.host' => ['string', '', false, false, false, 'Host del server LDAP', null],
                'ldap.port' => ['int', 389, false, false, false, 'Porta', null],
                'ldap.encryption' => ['enum', 'none', false, false, false, 'Cifratura della connessione', ['none', 'ssl', 'tls']],
                'ldap.base_dn' => ['string', 'dc=polito,dc=it', false, false, false, 'Base DN per la ricerca utenti', null],
                'ldap.bind_dn' => ['string', '', false, false, false, 'DN dell\'account di servizio (vuoto = bind anonimo)', null],
                'ldap.bind_password' => ['secret', '', false, false, true, 'Password dell\'account di servizio', null],
                'ldap.user_filter' => ['string', '(uid=%s)', false, false, false, 'Filtro di ricerca utente; %s = username', null],
                'ldap.attr_uid' => ['string', 'uid', false, false, false, 'Attributo username', null],
                'ldap.attr_email' => ['string', 'mail', false, false, false, 'Attributo email', null],
                'ldap.attr_first_name' => ['string', 'givenName', false, false, false, 'Attributo nome', null],
                'ldap.attr_last_name' => ['string', 'sn', false, false, false, 'Attributo cognome', null],
                'ldap.attr_display_name' => ['string', 'cn', false, false, false, 'Attributo nome visualizzato', null],
                'ldap.attr_matricola' => ['string', 'employeeNumber', false, false, false, 'Attributo matricola', null],
                'ldap.attr_groups' => ['string', 'memberOf', false, false, false, 'Attributo dei gruppi sull\'utente', null],
                'ldap.group_base_dn' => ['string', '', false, false, false, 'Base DN per la ricerca gruppi (vuoto = usa attr_groups)', null],
                'ldap.group_filter' => ['string', '(&(objectClass=groupOfNames)(member=%s))', false, false, false, 'Filtro gruppi; %s = DN utente', null],
                'ldap.timeout_seconds' => ['int', 5, false, false, false, 'Timeout di connessione', null],
                'ldap.default_role' => ['enum', 'student', false, false, false, 'Ruolo assegnato quando nessun gruppo corrisponde', $roles],
                'ldap.role_map' => ['json', $roleMap, false, false, false, 'Mappa gruppo LDAP → ruolo applicativo', null],
            ],
            'security' => [
                'security.jwt_ttl_minutes' => ['int', 480, false, false, false, 'Durata del token di accesso (minuti)', null],
                'security.jwt_refresh_ttl_days' => ['int', 14, false, false, false, 'Durata del token di rinnovo (giorni)', null],
                'security.jwt_issuer' => ['string', 'vlab', false, false, false, 'Emittente dei token', null],
                'security.login_max_attempts' => ['int', 10, false, false, false, 'Tentativi di accesso consentiti per finestra', null],
                'security.login_window_minutes' => ['int', 15, false, false, false, 'Ampiezza della finestra anti-forza-bruta (minuti)', null],
                'security.audit_retention_days' => ['int', 730, false, true, false, 'Giorni di conservazione del registro attività; null = per sempre', null],
            ],
            'notifications' => [
                'notifications.enabled' => ['bool', false, false, false, false, 'Abilita l\'invio di email', null],
                'notifications.from_email' => ['string', 'noreply@polito.it', false, false, false, 'Mittente', null],
                'notifications.from_name' => ['string', 'Visionary Lab', false, false, false, 'Nome mittente', null],
                'notifications.staff_inbox' => ['string', '', false, false, false, 'Email dello staff per le nuove richieste', null],
                'notifications.events' => ['json', ['order.submitted', 'order.approved', 'order.rejected', 'order.overdue'], false, false, false, 'Eventi notificati', null],
                'notifications.reminder_days_before_return' => ['int', 1, false, false, false, 'Giorni di anticipo per il promemoria di riconsegna', null],
            ],
            'ui' => [
                'ui.primary_color' => ['string', '#00284B', true, false, false, 'Colore primario (blu Politecnico)', null],
                'ui.accent_color' => ['string', '#EF7B02', true, false, false, 'Colore d\'accento (arancione Politecnico)', null],
                'ui.highlight_color' => ['string', '#00C2CB', true, false, false, 'Colore secondario (accento VR/cinema)', null],
                'ui.locale' => ['string', 'it-IT', true, false, false, 'Lingua dell\'interfaccia', null],
                'ui.date_format' => ['string', 'dd/MM/yyyy', true, false, false, 'Formato data visualizzato', null],
                'ui.items_per_page' => ['int', 24, true, false, false, 'Elementi per pagina nel catalogo', null],
                'ui.catalog_default_view' => ['enum', 'grid', true, false, false, 'Vista predefinita del catalogo', ['grid', 'list']],
                'ui.show_unit_codes_to_students' => ['bool', false, true, false, false, 'Mostra le sigle delle unità agli studenti', null],
                'ui.allow_anonymous_catalog' => ['bool', true, true, false, false, 'Consenti la consultazione del catalogo senza login', null],
                'ui.hero_image_url' => ['string', '', true, false, false, 'Immagine di sfondo della homepage', null],
                'ui.banner_enabled' => ['bool', false, true, false, false, 'Mostra un avviso in cima al sito', null],
                'ui.banner_message_it' => ['string', '', true, false, false, 'Testo dell\'avviso', null],
                'ui.banner_level' => ['enum', 'info', true, false, false, 'Tipo di avviso', ['info', 'warning', 'danger']],
                'ui.footer_note_it' => ['string', '© Politecnico di Torino', true, false, false, 'Nota nel footer', null],
            ],
            'stats' => [
                'stats.default_range_days' => ['int', 90, false, false, false, 'Intervallo predefinito delle statistiche (giorni)', null],
                'stats.default_granularity' => ['enum', 'week', false, false, false, 'Granularità predefinita dei grafici', ['day', 'week', 'month']],
                'stats.top_products_limit' => ['int', 10, false, false, false, 'Numero di prodotti nella classifica', null],
            ],
        ];
    }

    public function run(?callable $out = null): void
    {
        $now = Dates::nowDb();
        $created = 0;
        $updated = 0;
        foreach ($this->registry() as $group => $keys) {
            $position = 10;
            foreach ($keys as $key => [$type, $default, $public, $nullable, $secret, $label, $options]) {
                $meta = [
                    'type' => $type,
                    'group' => $group,
                    'label_it' => $label,
                    'description_it' => $label,
                    'is_public' => $public,
                    'is_secret' => $secret,
                    'nullable' => $nullable,
                    'options' => $options !== null ? json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    'position' => $position,
                    'updated_at' => $now,
                ];
                $exists = Capsule::table('settings')->where('key', $key)->exists();
                if ($exists) {
                    // Upsert metadata only — never touch a configured value (§10.10).
                    Capsule::table('settings')->where('key', $key)->update($meta);
                    $updated++;
                } else {
                    Capsule::table('settings')->insert($meta + [
                        'key' => $key,
                        'value' => json_encode($default, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'created_at' => $now,
                    ]);
                    $created++;
                }
                $position += 10;
            }
        }
        if ($out !== null) {
            $out("Impostazioni: {$created} nuove, {$updated} aggiornate (metadati).");
        }
    }
}
