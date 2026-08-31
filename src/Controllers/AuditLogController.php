<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Permissions;

/**
 * Audit-Log: schulintern (/{school}/admin/audit-log, inkl. TXT-Export) und
 * schulübergreifend im Global-Admin (/global-admin/logs).
 *
 * Hinweis zu LIMIT/OFFSET: Beide Werte stammen ausschließlich aus
 * validierten Integern und werden per sprintf('%d') eingesetzt, da MariaDB
 * für LIMIT keine gebundenen String-Parameter akzeptiert. Alle inhaltlichen
 * Filterwerte laufen weiterhin als Prepared-Statement-Parameter.
 */
final class AuditLogController extends Controller
{
    private const PER_PAGE = 100;
    private const SEVERITIES = ['info', 'warning', 'error'];

    /** GET /{school}/admin/audit-log */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUDIT_LOGS_SEHEN);

        $filters = $this->readFilters();
        [$where, $args] = $this->buildWhere($filters, (int) $this->ctx->schoolId());

        $total = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM audit_logs al WHERE ' . $where,
            $args,
        );
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($_GET['seite'] ?? 1)), $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = $this->ctx->db->fetchAll(
            'SELECT al.* FROM audit_logs al WHERE ' . $where
            . ' ORDER BY al.created_at DESC, al.id DESC'
            . sprintf(' LIMIT %d OFFSET %d', self::PER_PAGE, $offset),
            $args,
        );

        return $this->render('pages/audit/index', [
            'title' => 'Audit-Log',
            'rows' => $rows,
            'filters' => $filters,
            'severities' => self::SEVERITIES,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'errorCount' => (int) $this->ctx->db->fetchValue(
                'SELECT COUNT(*) FROM audit_logs al WHERE ' . $where . ' AND al.severity = \'error\'',
                $args,
            ),
        ]);
    }

    /** GET /{school}/admin/audit-log/export */
    public function export(array $params): string
    {
        $school = $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUDIT_LOGS_SEHEN);

        $filters = $this->readFilters();
        [$where, $args] = $this->buildWhere($filters, (int) $this->ctx->schoolId());

        $rows = $this->ctx->db->fetchAll(
            'SELECT al.* FROM audit_logs al WHERE ' . $where . ' ORDER BY al.created_at DESC, al.id DESC LIMIT 20000',
            $args,
        );

        $lines = [
            'Audit-Log — ' . (string) $school['name'],
            'Export: ' . date('d.m.Y H:i:s'),
            'Filter: ' . $this->describeFilters($filters),
            'Einträge: ' . count($rows),
            str_repeat('=', 78),
            '',
        ];

        $currentDay = null;
        foreach ($rows as $row) {
            $day = format_date($row['created_at']);
            if ($day !== $currentDay) {
                $currentDay = $day;
                $lines[] = '--- ' . $day . ' ' . str_repeat('-', max(0, 70 - mb_strlen($day)));
            }
            $lines[] = sprintf(
                '%s  %s  %s',
                format_date($row['created_at'], 'H:i:s'),
                str_pad(mb_substr((string) ($row['username'] ?? 'System'), 0, 24), 26),
                str_pad(mb_substr((string) $row['action'], 0, 40), 42),
            );
            $lines[] = sprintf(
                '           Stufe: %-8s IP: %-16s %s',
                (string) $row['severity'],
                (string) ($row['ip_address'] ?? '-'),
                $row['details'] !== null && $row['details'] !== ''
                    ? 'Details: ' . str_replace(["\r", "\n"], ' ', (string) $row['details'])
                    : '',
            );
        }

        $lines[] = '';
        $lines[] = str_repeat('=', 78);
        $lines[] = 'Ende des Exports.';

        $this->ctx->audit->log('Audit-Log exportiert', 'info', count($rows) . ' Einträge', $this->ctx->schoolId());

        $filename = 'audit-log-' . preg_replace('/[^a-z0-9-]/', '', (string) $school['slug']) . '-' . date('Y-m-d_His') . '.txt';
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        echo "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
        exit;
    }

    /** GET /global-admin/logs */
    public function globalIndex(array $params): string
    {
        $this->requireAdmin();

        $filters = $this->readFilters();
        $filters['school'] = (string) ($_GET['schule'] ?? '');

        $where = '1 = 1';
        $args = [];

        if ($filters['school'] === 'global') {
            $where .= ' AND al.school_id IS NULL';
        } elseif ($filters['school'] !== '' && ctype_digit($filters['school'])) {
            $where .= ' AND al.school_id = ?';
            $args[] = (int) $filters['school'];
        }

        [$where, $args] = $this->applyCommonFilters($where, $args, $filters);

        $total = (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM audit_logs al WHERE ' . $where, $args);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($_GET['seite'] ?? 1)), $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = $this->ctx->db->fetchAll(
            'SELECT al.*, s.name AS school_name FROM audit_logs al
             LEFT JOIN schools s ON s.id = al.school_id
             WHERE ' . $where
            . ' ORDER BY al.created_at DESC, al.id DESC'
            . sprintf(' LIMIT %d OFFSET %d', self::PER_PAGE, $offset),
            $args,
        );

        return $this->render('pages/audit/global', [
            'title' => 'Globale Logs',
            'rows' => $rows,
            'filters' => $filters,
            'severities' => self::SEVERITIES,
            'schools' => $this->ctx->db->fetchAll('SELECT id, name FROM schools ORDER BY name'),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    // ---------- Helfer ----------

    /** @return array{severity: string, von: string, bis: string, suche: string, school: string} */
    private function readFilters(): array
    {
        $severity = (string) ($_GET['stufe'] ?? '');

        return [
            'severity' => in_array($severity, self::SEVERITIES, true) ? $severity : '',
            'von' => $this->dateInput((string) ($_GET['von'] ?? '')),
            'bis' => $this->dateInput((string) ($_GET['bis'] ?? '')),
            'suche' => mb_substr(trim((string) ($_GET['suche'] ?? '')), 0, 100),
            'school' => '',
        ];
    }

    /**
     * @param array{severity: string, von: string, bis: string, suche: string, school: string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(array $filters, int $schoolId): array
    {
        return $this->applyCommonFilters('al.school_id = ?', [$schoolId], $filters);
    }

    /**
     * @param list<mixed> $args
     * @param array{severity: string, von: string, bis: string, suche: string, school: string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function applyCommonFilters(string $where, array $args, array $filters): array
    {
        if ($filters['severity'] !== '') {
            $where .= ' AND al.severity = ?';
            $args[] = $filters['severity'];
        }
        if ($filters['von'] !== '') {
            $where .= ' AND al.created_at >= ?';
            $args[] = $filters['von'] . ' 00:00:00';
        }
        if ($filters['bis'] !== '') {
            $where .= ' AND al.created_at <= ?';
            $args[] = $filters['bis'] . ' 23:59:59';
        }
        if ($filters['suche'] !== '') {
            $where .= ' AND (al.action LIKE ? OR al.username LIKE ?)';
            $like = '%' . $filters['suche'] . '%';
            $args[] = $like;
            $args[] = $like;
        }

        return [$where, $args];
    }

    /** Akzeptiert nur ein Datum im Format YYYY-MM-DD. */
    private function dateInput(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    /** @param array{severity: string, von: string, bis: string, suche: string, school: string} $filters */
    private function describeFilters(array $filters): string
    {
        $parts = [];
        if ($filters['severity'] !== '') {
            $parts[] = 'Stufe = ' . $filters['severity'];
        }
        if ($filters['von'] !== '') {
            $parts[] = 'ab ' . format_date($filters['von']);
        }
        if ($filters['bis'] !== '') {
            $parts[] = 'bis ' . format_date($filters['bis']);
        }
        if ($filters['suche'] !== '') {
            $parts[] = 'Suche „' . $filters['suche'] . '"';
        }

        return $parts === [] ? 'keine' : implode(', ', $parts);
    }
}
