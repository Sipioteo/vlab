<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Central enumeration registry with Italian labels (SPEC Appendix A, §7.6 #2).
 */
final class Enums
{
    public const ROLES = ['student', 'technician', 'assistant', 'admin'];
    public const STAFF_ROLES = ['technician', 'assistant', 'admin'];

    public const ORDER_STATUSES = [
        'draft', 'pending', 'approved', 'rejected', 'cancelled',
        'picked_up', 'overdue', 'returned', 'returned_late', 'no_show',
    ];

    public const TERMINAL_STATUSES = ['rejected', 'cancelled', 'returned', 'returned_late', 'no_show'];

    /** `pending` locks stock only when booking.pending_locks_stock (SPEC §5.2). */
    public const LOCKING_STATUSES = ['pending', 'approved', 'picked_up', 'overdue'];

    public const PRODUCT_STATUSES = ['available', 'maintenance', 'retired'];
    public const UNIT_STATUSES = ['available', 'maintenance', 'missing', 'retired', 'internal_use'];
    public const LOAN_MODES = ['takeaway', 'on_site_only'];
    public const LOG_TYPES = ['damage', 'maintenance', 'inspection', 'note', 'loss', 'repair'];
    public const LOG_SEVERITIES = ['info', 'warning', 'critical'];
    public const CONDITIONS = ['ok', 'damaged', 'incomplete', 'missing'];
    public const REGULATION_SCOPES = ['global', 'category', 'product'];
    public const REGULATION_CONTENT_TYPES = ['markdown', 'pdf'];
    public const RECOMMENDATION_RELATIONS = ['accessory', 'alternative', 'required_with'];
    public const SETTING_TYPES = ['string', 'int', 'bool', 'json', 'time', 'date', 'enum', 'secret'];
    public const SETTING_GROUPS = ['lab', 'hours', 'booking', 'regulations', 'ldap', 'security', 'notifications', 'ui', 'stats'];

    public const ORDER_STATUS_LABELS = [
        'draft' => 'Bozza',
        'pending' => 'In attesa',
        'approved' => 'Approvato',
        'rejected' => 'Respinto',
        'cancelled' => 'Annullato',
        'picked_up' => 'Ritirato',
        'overdue' => 'In ritardo',
        'returned' => 'Restituito',
        'returned_late' => 'Restituito in ritardo',
        'no_show' => 'Non ritirato',
    ];

    public const PRODUCT_STATUS_LABELS = [
        'available' => 'Disponibile',
        'maintenance' => 'In manutenzione',
        'retired' => 'Dismesso',
    ];

    public const UNIT_STATUS_LABELS = [
        'available' => 'Prestabile',
        'maintenance' => 'In manutenzione',
        'missing' => 'Mancante',
        'retired' => 'Dismesso',
        'internal_use' => 'In uso interno',
    ];

    public const LOAN_MODE_LABELS = [
        'takeaway' => 'Asportabile',
        'on_site_only' => 'Solo in sede',
    ];

    public const LOG_TYPE_LABELS = [
        'damage' => 'Danno',
        'maintenance' => 'Manutenzione',
        'inspection' => 'Collaudo',
        'note' => 'Nota',
        'loss' => 'Smarrimento',
        'repair' => 'Riparazione',
    ];

    public const LOG_SEVERITY_LABELS = [
        'info' => 'Informazione',
        'warning' => 'Attenzione',
        'critical' => 'Critico',
    ];

    public const ROLE_LABELS = [
        'student' => 'Studente',
        'technician' => 'Tecnico',
        'assistant' => 'Borsista',
        'admin' => 'Amministratore',
    ];

    public const REGULATION_SCOPE_LABELS = [
        'global' => 'Globale',
        'category' => 'Categoria',
        'product' => 'Prodotto',
    ];

    public const RECOMMENDATION_RELATION_LABELS = [
        'accessory' => 'Accessorio',
        'alternative' => 'Alternativa',
        'required_with' => 'Necessario insieme',
    ];

    public const CONDITION_LABELS = [
        'ok' => 'Integro',
        'damaged' => 'Danneggiato',
        'incomplete' => 'Incompleto',
        'missing' => 'Mancante',
    ];

    public const ACTION_LABELS = [
        'submit' => 'Inviato',
        'create' => 'Creato',
        'approve' => 'Approvato',
        'reject' => 'Rifiutato',
        'cancel' => 'Annullato',
        'pickup' => 'Ritirato',
        'return' => 'Restituito',
        'mark_no_show' => 'Segnato come non ritirato',
        'mark_overdue' => 'Segnato in ritardo',
        'reopen' => 'Riaperto',
        'note' => 'Nota aggiunta',
        'edit' => 'Modificato',
    ];

    /**
     * Payload for GET /api/v1/meta/enums (SPEC §7.6 #2).
     *
     * @return array<string,mixed>
     */
    public static function metaPayload(): array
    {
        $orderStatus = [];
        foreach (self::ORDER_STATUSES as $s) {
            $orderStatus[] = [
                'value' => $s,
                'label' => self::ORDER_STATUS_LABELS[$s],
                'is_terminal' => in_array($s, self::TERMINAL_STATUSES, true),
                'locks_stock' => in_array($s, self::LOCKING_STATUSES, true),
            ];
        }
        $simple = static function (array $values, array $labels): array {
            $out = [];
            foreach ($values as $v) {
                $out[] = ['value' => $v, 'label' => $labels[$v]];
            }
            return $out;
        };
        return [
            'order_status' => $orderStatus,
            'product_status' => $simple(self::PRODUCT_STATUSES, self::PRODUCT_STATUS_LABELS),
            'unit_status' => $simple(self::UNIT_STATUSES, self::UNIT_STATUS_LABELS),
            'loan_mode' => $simple(self::LOAN_MODES, self::LOAN_MODE_LABELS),
            'log_type' => $simple(self::LOG_TYPES, self::LOG_TYPE_LABELS),
            'log_severity' => $simple(self::LOG_SEVERITIES, self::LOG_SEVERITY_LABELS),
            'role' => $simple(self::ROLES, self::ROLE_LABELS),
            'regulation_scope' => $simple(self::REGULATION_SCOPES, self::REGULATION_SCOPE_LABELS),
            'recommendation_relation' => $simple(self::RECOMMENDATION_RELATIONS, self::RECOMMENDATION_RELATION_LABELS),
            'condition' => $simple(self::CONDITIONS, self::CONDITION_LABELS),
        ];
    }
}
