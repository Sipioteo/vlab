<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Support\Dates;
use App\Support\Enums;

/**
 * Type/shape validation for setting values (SPEC §7.11 #66, §10).
 */
final class SettingsValidator
{
    /**
     * @param array<string,mixed> $row settings table row (metadata)
     * @return string[] list of Italian error messages; empty = valid
     */
    public function validate(array $row, mixed $value): array
    {
        $key = (string) $row['key'];
        $type = (string) $row['type'];
        $nullable = (bool) $row['nullable'];

        if ($value === null) {
            return $nullable ? [] : ['Il valore non può essere nullo.'];
        }

        $errors = [];
        switch ($type) {
            case 'int':
                if (!is_int($value)) {
                    $errors[] = 'Il valore deve essere un numero intero.';
                }
                break;
            case 'bool':
                if (!is_bool($value)) {
                    $errors[] = 'Il valore deve essere booleano.';
                }
                break;
            case 'string':
            case 'secret':
                if (!is_string($value)) {
                    $errors[] = 'Il valore deve essere una stringa.';
                }
                break;
            case 'time':
                if (!is_string($value) || !Dates::isValidTime($value)) {
                    $errors[] = 'Il valore deve essere un orario nel formato HH:MM.';
                }
                break;
            case 'date':
                if (!is_string($value) || !Dates::isValidDate($value)) {
                    $errors[] = 'Il valore deve essere una data nel formato YYYY-MM-DD.';
                }
                break;
            case 'enum':
                $options = $row['options'] !== null ? json_decode((string) $row['options'], true) : [];
                if (!in_array($value, (array) $options, true)) {
                    $errors[] = 'Valore non ammesso.';
                }
                break;
            case 'json':
                $errors = array_merge($errors, $this->validateJsonShape($key, $value));
                break;
        }
        return $errors;
    }

    /** @return string[] */
    private function validateJsonShape(string $key, mixed $value): array
    {
        if ($key === 'hours.weekly') {
            return $this->validateWeekly($value);
        }
        if ($key === 'hours.pickup_windows' || $key === 'hours.return_windows') {
            return $this->validateWindows($value);
        }
        if ($key === 'ldap.role_map') {
            if (!is_array($value)) {
                return ['La mappa dei ruoli deve essere un oggetto.'];
            }
            foreach ($value as $group => $role) {
                if (!is_string($group) || !in_array($role, Enums::ROLES, true)) {
                    return ['Ogni valore della mappa deve essere un ruolo valido.'];
                }
            }
            return [];
        }
        if (!is_array($value)) {
            return ['Il valore deve essere un array o un oggetto JSON.'];
        }
        return [];
    }

    /** @return string[] */
    private function validateWeekly(mixed $value): array
    {
        if (!is_array($value) || count($value) !== 7) {
            return ['Sono richieste esattamente 7 voci, una per giorno della settimana.'];
        }
        $seen = [];
        foreach ($value as $entry) {
            if (!is_array($entry) || !array_key_exists('weekday', $entry) || !array_key_exists('closed', $entry)) {
                return ['Ogni voce deve avere weekday e closed.'];
            }
            $wd = $entry['weekday'];
            if (!is_int($wd) || $wd < 0 || $wd > 6 || isset($seen[$wd])) {
                return ['I giorni della settimana devono essere 0..6 senza duplicati.'];
            }
            $seen[$wd] = true;
            if (!is_bool($entry['closed'])) {
                return ['Il campo closed deve essere booleano.'];
            }
            if ($entry['closed'] === false) {
                $open = $entry['open'] ?? null;
                $close = $entry['close'] ?? null;
                if (!is_string($open) || !is_string($close) || !Dates::isValidTime($open) || !Dates::isValidTime($close)) {
                    return ['Nei giorni aperti open e close devono essere orari HH:MM.'];
                }
                if ($open >= $close) {
                    return ['L\'orario di apertura deve precedere quello di chiusura.'];
                }
            }
        }
        return [];
    }

    /** @return string[] */
    private function validateWindows(mixed $value): array
    {
        if (!is_array($value)) {
            return ['Il valore deve essere un array di fasce orarie.'];
        }
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                return ['Ogni fascia deve essere un oggetto.'];
            }
            $wd = $entry['weekday'] ?? null;
            $from = $entry['from'] ?? null;
            $to = $entry['to'] ?? null;
            if (!is_int($wd) || $wd < 0 || $wd > 6) {
                return ['weekday deve essere 0..6.'];
            }
            if (!is_string($from) || !is_string($to) || !Dates::isValidTime($from) || !Dates::isValidTime($to) || $from >= $to) {
                return ['Ogni fascia deve avere from < to nel formato HH:MM.'];
            }
        }
        return [];
    }
}
