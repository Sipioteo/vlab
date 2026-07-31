<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\FakeLdapAuthenticator;
use App\Domain\Auth\LdapAuthenticatorInterface;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Settings\SettingsValidator;
use App\Http\Resources\SettingResource;
use App\Support\ApiException;
use App\Support\AuditLogger;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SettingsController extends Controller
{
    private const GROUPS = [
        ['key' => 'lab', 'label_it' => 'Laboratorio', 'position' => 10],
        ['key' => 'hours', 'label_it' => 'Orari e chiusure', 'position' => 20],
        ['key' => 'booking', 'label_it' => 'Prenotazioni e limiti', 'position' => 30],
        ['key' => 'regulations', 'label_it' => 'Regolamenti', 'position' => 40],
        ['key' => 'ldap', 'label_it' => 'LDAP', 'position' => 50],
        ['key' => 'security', 'label_it' => 'Sicurezza', 'position' => 60],
        ['key' => 'notifications', 'label_it' => 'Notifiche', 'position' => 70],
        ['key' => 'ui', 'label_it' => 'Aspetto', 'position' => 80],
        ['key' => 'stats', 'label_it' => 'Statistiche', 'position' => 90],
    ];

    private const ADMIN_ONLY_GROUPS = ['ldap', 'security'];

    public function __construct(
        private SettingsRepository $settings,
        private SettingsValidator $validator,
        private LdapAuthenticatorInterface $ldap,
        private array $config,
    ) {
    }

    /** GET /settings/public — flat key→value map, no auth (SPEC §7.6 #8). */
    public function publicSettings(Request $request, Response $response): Response
    {
        $out = [];
        foreach ($this->settings->rows() as $key => $row) {
            if ((bool) $row['is_public'] && !(bool) $row['is_secret']) {
                $out[$key] = SettingsRepository::decodeValue($row['value']);
            }
        }
        return $this->json($response, $out);
    }

    /** GET /settings — T/B/AD; ldap/security groups and secrets admin-only. */
    public function index(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $isAdmin = $user->isAdmin();
        $query = $request->getQueryParams();
        $groupFilter = isset($query['group']) && $query['group'] !== '' ? (string) $query['group'] : null;
        $rows = [];
        foreach ($this->settings->rows() as $row) {
            if (!$isAdmin && (in_array($row['group'], self::ADMIN_ONLY_GROUPS, true) || (bool) $row['is_secret'])) {
                continue;
            }
            if ($groupFilter !== null && $row['group'] !== $groupFilter) {
                continue;
            }
            $rows[] = $row;
        }
        usort($rows, static fn ($a, $b) => [$a['group'], (int) $a['position'], $a['key']] <=> [$b['group'], (int) $b['position'], $b['key']]);
        $groups = array_values(array_filter(
            self::GROUPS,
            static fn ($g) => $isAdmin || !in_array($g['key'], self::ADMIN_ONLY_GROUPS, true)
        ));
        return $this->json($response, [
            'data' => array_map(static fn ($r) => SettingResource::toArray($r), $rows),
            'meta' => null,
            'groups' => $groups,
        ]);
    }

    /** PUT /settings — admin bulk update, atomic (SPEC §7.11 #66). */
    public function bulkUpdate(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $updates = $body['settings'] ?? null;
        if (!is_array($updates) || $updates === []) {
            throw ApiException::validation(['settings' => ['Specificare almeno una impostazione.']]);
        }
        $rows = $this->settings->rows();
        $unknown = [];
        $errors = [];
        $toWrite = [];
        foreach ($updates as $key => $value) {
            if (!isset($rows[$key])) {
                $unknown[] = $key;
                continue;
            }
            $row = $rows[$key];
            if ((bool) $row['is_secret'] && $value === '********') {
                continue; // leave unchanged
            }
            $messages = $this->validator->validate($row, $value);
            if ($messages !== []) {
                $errors[$key] = $messages;
                continue;
            }
            $toWrite[$key] = $value;
        }
        if ($unknown !== []) {
            throw new ApiException(422, 'unknown_setting_key', 'Chiave di impostazione sconosciuta.', ['keys' => $unknown]);
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        Capsule::connection()->transaction(function () use ($toWrite, $rows, $user) {
            foreach ($toWrite as $key => $value) {
                $before = SettingsRepository::decodeValue($rows[$key]['value']);
                $this->settings->set($key, $value, (int) $user->id);
                $isSecret = (bool) $rows[$key]['is_secret'];
                AuditLogger::log($user, 'settings.update', 'Setting', $key, [
                    'before' => ['value' => $isSecret ? '********' : $before],
                    'after' => ['value' => $isSecret ? '********' : $value],
                ]);
            }
        });
        $this->settings->invalidate();
        return $this->index($request, $response);
    }

    /** PUT /settings/{key}. */
    public function updateOne(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $key = (string) $args['key'];
        $rows = $this->settings->rows();
        if (!isset($rows[$key])) {
            throw new ApiException(422, 'unknown_setting_key', 'Chiave di impostazione sconosciuta.', ['keys' => [$key]]);
        }
        $body = $this->body($request);
        if (!array_key_exists('value', $body)) {
            throw ApiException::validation(['value' => ['Il campo value è obbligatorio.']]);
        }
        $value = $body['value'];
        $row = $rows[$key];
        if (!((bool) $row['is_secret'] && $value === '********')) {
            $messages = $this->validator->validate($row, $value);
            if ($messages !== []) {
                throw ApiException::validation([$key => $messages]);
            }
            $before = SettingsRepository::decodeValue($row['value']);
            $this->settings->set($key, $value, (int) $user->id);
            $isSecret = (bool) $row['is_secret'];
            AuditLogger::log($user, 'settings.update', 'Setting', $key, [
                'before' => ['value' => $isSecret ? '********' : $before],
                'after' => ['value' => $isSecret ? '********' : $value],
            ]);
        }
        $fresh = $this->settings->rows()[$key];
        return $this->json($response, SettingResource::toArray($fresh));
    }

    /** POST /settings/ldap/test — admin; never persists anything. */
    public function ldapTest(Request $request, Response $response): Response
    {
        $this->requireUser($request);
        $body = $this->body($request);
        $mode = $this->settings->ldapMode($this->config);

        if ($mode === 'fake') {
            $result = (new FakeLdapAuthenticator())->testConnection();
            return $this->json($response, [
                'ok' => $result->ok,
                'message' => $result->message,
                'latency_ms' => $result->latencyMs,
                'entries_found' => $result->entriesFound,
                'mode' => 'fake',
            ]);
        }

        if (!extension_loaded('ldap')) {
            return $this->json($response, [
                'ok' => false,
                'message' => 'Estensione php-ldap non installata.',
                'latency_ms' => null,
                'entries_found' => null,
                'mode' => 'real',
            ]);
        }

        $host = (string) ($body['host'] ?? ($this->settings->get('ldap.host', '') ?: ($this->config['ldap']['host'] ?? '')));
        $port = (int) ($body['port'] ?? ($this->settings->get('ldap.port', 389) ?? 389));
        $encryption = (string) ($body['encryption'] ?? ($this->settings->get('ldap.encryption', 'none') ?? 'none'));
        $bindDn = (string) ($body['bind_dn'] ?? ($this->settings->get('ldap.bind_dn', '') ?? ''));
        $bindPassword = (string) ($body['bind_password'] ?? ($this->settings->get('ldap.bind_password', '') ?? ''));
        $baseDn = (string) ($body['base_dn'] ?? ($this->settings->get('ldap.base_dn', '') ?? ''));
        $userFilter = (string) ($body['user_filter'] ?? ($this->settings->get('ldap.user_filter', '(uid=%s)') ?? '(uid=%s)'));
        $testUsername = isset($body['test_username']) ? (string) $body['test_username'] : null;

        $start = microtime(true);
        $uri = ($encryption === 'ssl' ? 'ldaps://' : 'ldap://') . $host . ':' . $port;
        $conn = @ldap_connect($uri);
        if ($conn === false || $host === '') {
            return $this->json($response, ['ok' => false, 'message' => 'Impossibile connettersi al server LDAP.', 'latency_ms' => null, 'entries_found' => null, 'mode' => 'real']);
        }
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, (int) ($this->settings->get('ldap.timeout_seconds', 5) ?? 5));
        if ($encryption === 'tls' && !@ldap_start_tls($conn)) {
            return $this->json($response, ['ok' => false, 'message' => 'start_tls fallito: ' . ldap_error($conn), 'latency_ms' => null, 'entries_found' => null, 'mode' => 'real']);
        }
        $bound = $bindDn !== '' ? @ldap_bind($conn, $bindDn, $bindPassword) : @ldap_bind($conn);
        if (!$bound) {
            return $this->json($response, ['ok' => false, 'message' => 'Bind fallito: ' . ldap_error($conn), 'latency_ms' => null, 'entries_found' => null, 'mode' => 'real']);
        }
        $entriesFound = null;
        if ($testUsername !== null && $baseDn !== '') {
            $search = @ldap_search($conn, $baseDn, sprintf($userFilter, ldap_escape($testUsername, '', LDAP_ESCAPE_FILTER)));
            if ($search !== false) {
                $entries = ldap_get_entries($conn, $search);
                $entriesFound = (int) ($entries['count'] ?? 0);
            }
        }
        $latency = (int) round((microtime(true) - $start) * 1000);
        $message = $entriesFound !== null
            ? "Connessione riuscita, {$entriesFound} utente trovato."
            : 'Connessione riuscita.';
        return $this->json($response, [
            'ok' => true,
            'message' => $message,
            'latency_ms' => $latency,
            'entries_found' => $entriesFound,
            'mode' => 'real',
        ]);
    }
}
