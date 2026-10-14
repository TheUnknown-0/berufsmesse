<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;
use App\Services\ExhibitorHistory;
use App\Services\ExhibitorProfile;
use App\Services\HeldRegistrations;
use App\Services\Uploads;

/**
 * Aussteller-Verwaltung (Admin) inkl. Branchen-Stammdaten.
 *
 * Alle Aussteller-Queries sind an die aktive Edition gebunden; Branchen
 * (Tabelle `industries`) gehören der Schule und gelten für alle ihre Editionen.
 */
final class ExhibitorsAdminController extends Controller
{
    /** Profilfelder, deren Sichtbarkeit für Schüler einzeln steuerbar ist. */
    public const VISIBLE_FIELDS = [
        'contact_person' => 'Ansprechpartner:in',
        'email' => 'E-Mail-Adresse',
        'phone' => 'Telefonnummer',
        'website' => 'Website',
        'jobs' => 'Berufe & Tätigkeiten',
        'features' => 'Besonderheiten',
    ];

    // ---------- Liste ----------

    /** GET /{school}/admin/aussteller */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_SEHEN);
        $edition = $this->ctx->requireEdition();

        $search = trim((string) ($_GET['q'] ?? ''));
        $status = (string) ($_GET['status'] ?? 'alle');
        if (!in_array($status, ['alle', 'aktiv', 'inaktiv'], true)) {
            $status = 'alle';
        }

        $where = ['e.edition_id = ?'];
        $args = [(int) $edition['id']];

        if ($search !== '') {
            $where[] = '(e.name LIKE ? OR e.short_description LIKE ? OR e.categories LIKE ?)';
            $like = '%' . $search . '%';
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }
        if ($status === 'aktiv') {
            $where[] = 'e.active = 1';
        } elseif ($status === 'inaktiv') {
            $where[] = 'e.active = 0';
        }

        $rows = $this->ctx->db->fetchAll(
            'SELECT e.id, e.name, e.categories, e.logo, e.active, e.total_slots, e.room_id,
                    r.room_number, r.room_name,
                    (SELECT COUNT(*) FROM registrations reg WHERE reg.exhibitor_id = e.id) AS registration_count,
                    (SELECT COUNT(*) FROM exhibitor_documents d WHERE d.exhibitor_id = e.id) AS document_count
             FROM exhibitors e
             LEFT JOIN rooms r ON r.id = e.room_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY e.name ASC',
            $args,
        );

        foreach ($rows as $i => $row) {
            $rows[$i]['category_list'] = self::decodeList($row['categories']);
        }

        $totals = $this->ctx->db->fetchOne(
            'SELECT COUNT(*) AS gesamt, SUM(active = 1) AS aktiv, SUM(room_id IS NOT NULL) AS mit_raum
             FROM exhibitors WHERE edition_id = ?',
            [(int) $edition['id']],
        ) ?? ['gesamt' => 0, 'aktiv' => 0, 'mit_raum' => 0];

        return $this->render('pages/exhibitors-admin/index', [
            'title' => 'Aussteller',
            'rows' => $rows,
            'search' => $search,
            'status' => $status,
            'totals' => $totals,
        ]);
    }

    // ---------- Anlegen & Bearbeiten ----------

    /** GET /{school}/admin/aussteller/neu */
    public function create(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_ERSTELLEN);
        $this->ctx->requireEdition();

        return $this->render('pages/exhibitors-admin/form', [
            'title' => 'Neuer Aussteller',
            'exhibitor' => null,
            'values' => $this->formValues(null),
            'industries' => $this->industryNames(),
            'offerTypes' => ExhibitorProfile::OFFER_TYPES,
            'visibleFieldLabels' => self::VISIBLE_FIELDS,
            'documents' => [],
            'stages' => ExhibitorPipelineController::STAGES,
        ]);
    }

    /** POST /{school}/admin/aussteller/neu */
    public function store(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_ERSTELLEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $back = $this->ctx->schoolUrl('/admin/aussteller/neu');
        $data = $this->readInput($back);
        // Sichtbarkeit folgt immer dem Pipeline-Status (siehe ExhibitorPipelineController).
        $stage = (string) ($_POST['pipeline_status'] ?? 'confirmed');
        if (!isset(ExhibitorPipelineController::STAGES[$stage])) {
            $stage = 'confirmed';
        }
        $logo = $this->storeLogo($back);

        $this->ctx->db->run(
            'INSERT INTO exhibitors
                (edition_id, name, short_description, description, categories, logo, contact_person,
                 email, phone, website, jobs, features, offer_types, equipment, visible_fields,
                 total_slots, active, pipeline_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $edition['id'],
                $data['name'],
                $data['short_description'],
                $data['description'],
                $data['categories'],
                $logo,
                $data['contact_person'],
                $data['email'],
                $data['phone'],
                $data['website'],
                $data['jobs'],
                $data['features'],
                $data['offer_types'],
                $data['equipment'],
                $data['visible_fields'],
                $data['total_slots'],
                ExhibitorPipelineController::VISIBILITY[$stage],
                $stage,
            ],
        );
        $id = $this->ctx->db->lastInsertId();

        $this->ctx->audit->log(
            'Aussteller erstellt',
            'info',
            'Aussteller: ' . $data['name'] . ' (ID ' . $id . ')',
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Der Aussteller wurde angelegt.');
        $this->redirect($this->ctx->schoolUrl('/admin/aussteller/' . $id));
    }

    /** GET /{school}/admin/aussteller/{id} */
    public function edit(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_SEHEN);
        $this->ctx->requireEdition();

        $exhibitor = $this->findExhibitor((int) $params['id']);
        $documents = [];
        if ($this->ctx->auth->can(Permissions::AUSSTELLER_DOKUMENTE_VERWALTEN, $this->ctx->schoolId())) {
            $documents = $this->ctx->db->fetchAll(
                'SELECT * FROM exhibitor_documents WHERE exhibitor_id = ? ORDER BY uploaded_at DESC',
                [(int) $exhibitor['id']],
            );
        }

        // Akquise-Verlauf und Teilnahmen früherer Jahrgänge
        $notes = $this->ctx->db->fetchAll(
            'SELECT n.*, u.firstname, u.lastname, u.username
             FROM exhibitor_notes n
             LEFT JOIN users u ON u.id = n.user_id
             WHERE n.exhibitor_id = ?
             ORDER BY n.created_at DESC, n.id DESC',
            [(int) $exhibitor['id']],
        );

        return $this->render('pages/exhibitors-admin/form', [
            'title' => $exhibitor['name'],
            'exhibitor' => $exhibitor,
            'values' => $this->formValues($exhibitor),
            'industries' => $this->industryNames(self::decodeList($exhibitor['categories'])),
            'offerTypes' => ExhibitorProfile::OFFER_TYPES,
            'visibleFieldLabels' => self::VISIBLE_FIELDS,
            'documents' => $documents,
            'notes' => $notes,
            'history' => (new ExhibitorHistory($this->ctx->db))->previous((int) $exhibitor['id']),
            'stages' => ExhibitorPipelineController::STAGES,
            'liveCount' => (int) $this->ctx->db->fetchValue(
                'SELECT COUNT(*) FROM registrations WHERE exhibitor_id = ?',
                [(int) $exhibitor['id']],
            ),
            'heldCount' => (new HeldRegistrations($this->ctx->db))->countForExhibitor(
                (int) $exhibitor['edition_id'],
                (int) $exhibitor['id'],
            ),
        ]);
    }

    /** POST /{school}/admin/aussteller/{id} */
    public function update(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_BEARBEITEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $exhibitor = $this->findExhibitor((int) $params['id']);
        $back = $this->ctx->schoolUrl('/admin/aussteller/' . (int) $exhibitor['id']);
        $data = $this->readInput($back);
        $newLogo = $this->storeLogo($back);

        $sql = 'UPDATE exhibitors SET name = ?, short_description = ?, description = ?, categories = ?,
                       contact_person = ?, email = ?, phone = ?, website = ?, jobs = ?, features = ?,
                       offer_types = ?, equipment = ?, visible_fields = ?, total_slots = ?';
        $args = [
            $data['name'],
            $data['short_description'],
            $data['description'],
            $data['categories'],
            $data['contact_person'],
            $data['email'],
            $data['phone'],
            $data['website'],
            $data['jobs'],
            $data['features'],
            $data['offer_types'],
            $data['equipment'],
            $data['visible_fields'],
            $data['total_slots'],
        ];
        if ($newLogo !== null) {
            $sql .= ', logo = ?';
            $args[] = $newLogo;
        }
        $sql .= ' WHERE id = ? AND edition_id = ?';
        $args[] = (int) $exhibitor['id'];
        $args[] = (int) $edition['id'];

        $this->ctx->db->run($sql, $args);

        // Altes Logo erst nach erfolgreichem Update entfernen — und nur, wenn
        // keine andere Edition dieselbe Datei referenziert (Edition-Klonen
        // uebernimmt den Dateinamen, nicht die Datei).
        if ($newLogo !== null) {
            $this->uploads()->deleteLogoIfUnused(
                $this->ctx->db,
                is_string($exhibitor['logo']) ? $exhibitor['logo'] : null,
                (int) $exhibitor['id'],
            );
        }

        $this->ctx->audit->log(
            'Aussteller bearbeitet',
            'info',
            'Aussteller: ' . $data['name'] . ' (ID ' . (int) $exhibitor['id'] . ')',
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Die Änderungen wurden gespeichert.');
        $this->redirect($back);
    }

    /** POST /{school}/admin/aussteller/{id}/logo-loeschen */
    public function deleteLogo(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_BEARBEITEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $exhibitor = $this->findExhibitor((int) $params['id']);
        $this->ctx->db->run(
            'UPDATE exhibitors SET logo = NULL WHERE id = ? AND edition_id = ?',
            [(int) $exhibitor['id'], (int) $edition['id']],
        );
        $this->uploads()->deleteLogoIfUnused(
            $this->ctx->db,
            is_string($exhibitor['logo']) ? $exhibitor['logo'] : null,
            (int) $exhibitor['id'],
        );

        $this->ctx->audit->log(
            'Aussteller-Logo entfernt',
            'info',
            'Aussteller: ' . (string) $exhibitor['name'],
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Das Logo wurde entfernt.');
        $this->redirect($this->ctx->schoolUrl('/admin/aussteller/' . (int) $exhibitor['id']));
    }

    /** POST /{school}/admin/aussteller/{id}/loeschen */
    public function destroy(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_LOESCHEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $exhibitor = $this->findExhibitor((int) $params['id']);
        $exhibitorId = (int) $exhibitor['id'];
        $editionId = (int) $edition['id'];
        $uploads = $this->uploads();

        // Löschen kaskadiert über Fremdschlüssel auf Anmeldungen UND
        // Anwesenheiten. Sind bereits Check-ins erfasst, wären hinterher
        // Anwesenheitsbericht und Jahresvergleich rückwirkend falsch — ohne
        // dass es jemandem auffällt. Für diesen Fall ist das Absagen der
        // richtige Weg: Es benachrichtigt, bucht um und erhält die Historie.
        $checkins = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM attendance WHERE exhibitor_id = ? AND edition_id = ?',
            [$exhibitorId, $editionId],
        );
        if ($checkins > 0) {
            $this->flash('error', sprintf(
                'Für diesen Aussteller sind bereits %d Check-ins erfasst. Löschen würde sie mitsamt den '
                . 'Anwesenheitsberichten unwiederbringlich entfernen. Bitte stattdessen absagen.',
                $checkins,
            ));
            $this->redirect($this->ctx->schoolUrl('/admin/aussteller/' . $exhibitorId));
        }

        // Löschen würde Buchungen und ruhende Buchungen per CASCADE still
        // entfernen, ohne dass die Schüler:innen es erfahren. Der Weg dafür
        // ist die Absage (benachrichtigt, Buchungen ruhen) und danach die
        // Neuzuteilung; erst ein Aussteller ohne beides darf weg.
        $registrations = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM registrations WHERE exhibitor_id = ? AND edition_id = ?',
            [$exhibitorId, $editionId],
        );
        $held = (new HeldRegistrations($this->ctx->db))->countForExhibitor($editionId, $exhibitorId);
        if ($registrations > 0 || $held > 0) {
            $this->flash('error', sprintf(
                'Löschen ist gesperrt: %d Buchung(en) und %d ruhende Buchung(en) hängen an diesem Aussteller. '
                . 'Sage ihn zuerst ab und teile die Betroffenen neu zu.',
                $registrations,
                $held,
            ));
            $this->redirect($this->ctx->schoolUrl('/admin/aussteller/' . $exhibitorId));
        }

        // Dateinamen vor dem CASCADE-Delete einsammeln
        $documents = $this->ctx->db->fetchAll(
            'SELECT filename FROM exhibitor_documents WHERE exhibitor_id = ?',
            [$exhibitorId],
        );

        $this->ctx->db->run(
            'DELETE FROM exhibitors WHERE id = ? AND edition_id = ?',
            [$exhibitorId, $editionId],
        );

        foreach ($documents as $document) {
            $uploads->delete('documents', (string) $document['filename']);
        }
        $uploads->deleteLogoIfUnused(
            $this->ctx->db,
            is_string($exhibitor['logo']) ? $exhibitor['logo'] : null,
            (int) $exhibitor['id'],
        );

        $this->ctx->audit->log(
            'Aussteller gelöscht',
            'warning',
            'Aussteller: ' . (string) $exhibitor['name'] . ' (ID ' . $exhibitorId . ')',
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Der Aussteller wurde gelöscht.');
        $this->redirect($this->ctx->schoolUrl('/admin/aussteller'));
    }

    // ---------- Branchen ----------

    /** GET /{school}/admin/branchen */
    public function industries(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::BRANCHEN_SEHEN);
        $edition = $this->ctx->requireEdition();

        $industries = $this->ctx->db->fetchAll(
            'SELECT id, name, sort_order FROM industries WHERE school_id = ? ORDER BY sort_order ASC, name ASC',
            [(int) $this->ctx->schoolId()],
        );

        // Nutzung innerhalb der aktuellen Edition zählen (categories ist JSON)
        $usage = [];
        $unknown = [];
        $known = array_column($industries, 'name');
        $rows = $this->ctx->db->fetchAll(
            'SELECT categories FROM exhibitors WHERE edition_id = ?',
            [(int) $edition['id']],
        );
        foreach ($rows as $row) {
            foreach (self::decodeList($row['categories']) as $name) {
                $usage[$name] = ($usage[$name] ?? 0) + 1;
                if (!in_array($name, $known, true)) {
                    $unknown[$name] = ($unknown[$name] ?? 0) + 1;
                }
            }
        }
        ksort($unknown);

        return $this->render('pages/exhibitors-admin/industries', [
            'title' => 'Branchen',
            'industries' => $industries,
            'usage' => $usage,
            'unknown' => $unknown,
        ]);
    }

    /** POST /{school}/admin/branchen */
    public function storeIndustry(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::BRANCHEN_BEARBEITEN);
        $this->requireCsrf();

        $name = trim((string) ($_POST['name'] ?? ''));
        $sortOrder = $this->clampInt($_POST['sort_order'] ?? 0, 0, 65535);
        $back = $this->ctx->schoolUrl('/admin/branchen');

        if ($name === '' || mb_strlen($name) > 150) {
            $this->flash('error', 'Bitte gib einen Branchennamen mit maximal 150 Zeichen an.');
            $this->redirect($back);
        }
        $schoolId = (int) $this->ctx->schoolId();
        $exists = $this->ctx->db->fetchValue(
            'SELECT 1 FROM industries WHERE school_id = ? AND name = ?',
            [$schoolId, $name],
        );
        if ($exists !== null) {
            $this->flash('error', 'Diese Branche existiert bereits.');
            $this->redirect($back);
        }

        $this->ctx->db->run(
            'INSERT INTO industries (school_id, name, sort_order) VALUES (?, ?, ?)',
            [$schoolId, $name, $sortOrder],
        );
        $this->ctx->audit->log('Branche erstellt', 'info', 'Branche: ' . $name, $this->ctx->schoolId());
        $this->flash('success', 'Die Branche wurde angelegt.');
        $this->redirect($back);
    }

    /** POST /{school}/admin/branchen/{id} */
    public function updateIndustry(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::BRANCHEN_BEARBEITEN);
        $this->requireCsrf();

        $back = $this->ctx->schoolUrl('/admin/branchen');
        $industry = $this->findIndustry((int) $params['id']);
        $id = (int) $industry['id'];
        $schoolId = (int) $this->ctx->schoolId();

        $name = trim((string) ($_POST['name'] ?? ''));
        $sortOrder = $this->clampInt($_POST['sort_order'] ?? 0, 0, 65535);
        if ($name === '' || mb_strlen($name) > 150) {
            $this->flash('error', 'Bitte gib einen Branchennamen mit maximal 150 Zeichen an.');
            $this->redirect($back);
        }
        $clash = $this->ctx->db->fetchValue(
            'SELECT 1 FROM industries WHERE school_id = ? AND name = ? AND id <> ?',
            [$schoolId, $name, $id],
        );
        if ($clash !== null) {
            $this->flash('error', 'Eine Branche mit diesem Namen existiert bereits.');
            $this->redirect($back);
        }

        $this->ctx->db->run(
            'UPDATE industries SET name = ?, sort_order = ? WHERE id = ? AND school_id = ?',
            [$name, $sortOrder, $id, $schoolId],
        );

        // Umbenennung in allen Editionen dieser Schule nachziehen
        $renamed = 0;
        $oldName = (string) $industry['name'];
        if ($oldName !== $name) {
            $renamed = $this->renameCategory($schoolId, $oldName, $name);
        }

        $this->ctx->audit->log(
            'Branche bearbeitet',
            'info',
            'Branche: ' . $oldName . ' → ' . $name . ($renamed > 0 ? " ({$renamed} Aussteller angepasst)" : ''),
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Die Branche wurde aktualisiert.');
        $this->redirect($back);
    }

    /** POST /{school}/admin/branchen/{id}/loeschen */
    public function destroyIndustry(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::BRANCHEN_BEARBEITEN);
        $this->requireCsrf();

        $back = $this->ctx->schoolUrl('/admin/branchen');
        $industry = $this->findIndustry((int) $params['id']);

        // Bewusst: bereits zugeordnete Namen bleiben in exhibitors.categories stehen.
        $this->ctx->db->run(
            'DELETE FROM industries WHERE id = ? AND school_id = ?',
            [(int) $industry['id'], (int) $this->ctx->schoolId()],
        );
        $this->ctx->audit->log(
            'Branche gelöscht',
            'warning',
            'Branche: ' . (string) $industry['name'],
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Die Branche wurde gelöscht. Bereits zugeordnete Aussteller behalten den Namen.');
        $this->redirect($back);
    }

    // ---------- Öffentliche Helfer (auch für andere Controller) ----------

    /**
     * Dekodiert eine JSON-Liste (Array von Strings) mit Fallback auf [].
     *
     * @return list<string>
     */
    public static function decodeList(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            // Altdaten: einzelner Name als reiner String
            return [trim($raw)];
        }

        $out = [];
        foreach ($decoded as $value) {
            if (is_string($value) && trim($value) !== '') {
                $out[] = trim($value);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Dekodiert offer_types zu einer flachen Liste anzeigbarer Angebote.
     *
     * @return list<string>
     */
    public static function decodeOffers(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ((array) ($decoded['selected'] ?? []) as $value) {
            if (is_string($value) && trim($value) !== '') {
                $out[] = trim($value);
            }
        }
        $custom = $decoded['custom'] ?? '';
        if (is_string($custom) && trim($custom) !== '') {
            $out[] = trim($custom);
        }

        return array_values(array_unique($out));
    }

    /**
     * Rohform von offer_types: ['selected' => list<string>, 'custom' => string].
     *
     * @return array{selected: list<string>, custom: string}
     */
    public static function decodeOfferParts(mixed $raw): array
    {
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $selected = [];
        $custom = '';
        if (is_array($decoded)) {
            foreach ((array) ($decoded['selected'] ?? []) as $value) {
                if (is_string($value)) {
                    $selected[] = $value;
                }
            }
            $custom = is_string($decoded['custom'] ?? null) ? (string) $decoded['custom'] : '';
        }

        return ['selected' => $selected, 'custom' => $custom];
    }

    /**
     * Sichtbare Profilfelder eines Ausstellers (Fallback: nur Website).
     *
     * @return list<string>
     */
    public static function decodeVisibleFields(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return ['website'];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['website'];
        }

        $out = [];
        foreach ($decoded as $value) {
            if (is_string($value) && isset(self::VISIBLE_FIELDS[$value])) {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    // ---------- Interne Helfer ----------

    private function uploads(): Uploads
    {
        return new Uploads((string) $this->ctx->config['uploads']['dir']);
    }

    /** @return array<string, mixed> Aussteller der aktiven Edition (sonst 404). */
    private function findExhibitor(int $id): array
    {
        $exhibitor = $this->ctx->db->fetchOne(
            'SELECT * FROM exhibitors WHERE id = ? AND edition_id = ?',
            [$id, (int) $this->ctx->requireEdition()['id']],
        );
        if ($exhibitor === null) {
            throw new HttpException(404, 'Dieser Aussteller existiert nicht.');
        }

        return $exhibitor;
    }

    /**
     * Auswahlliste der Branchennamen: Stammdaten + bereits zugeordnete
     * (auch gelöschte) Namen, damit nichts unbemerkt verloren geht.
     *
     * @param list<string> $extra
     * @return list<string>
     */
    private function industryNames(array $extra = []): array
    {
        $rows = $this->ctx->db->fetchAll(
            'SELECT name FROM industries WHERE school_id = ? ORDER BY sort_order ASC, name ASC',
            [(int) $this->ctx->schoolId()],
        );
        $names = array_map(static fn (array $row): string => (string) $row['name'], $rows);
        foreach ($extra as $name) {
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return array_values($names);
    }

    /**
     * Liest und validiert das Aussteller-Formular. Bei Fehlern wird mit
     * Flash-Meldung und gemerkten Eingaben zurückgeleitet.
     *
     * @return array<string, mixed>
     */
    private function readInput(string $back): array
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 200) {
            $this->ctx->session->rememberInput($_POST);
            $this->flash('error', 'Bitte gib einen Namen mit maximal 200 Zeichen an.');
            $this->redirect($back);
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->ctx->session->rememberInput($_POST);
            $this->flash('error', 'Die E-Mail-Adresse ist ungültig.');
            $this->redirect($back);
        }

        // Branchen: nur Namen, die es gibt oder die bereits zugeordnet waren
        $allowedCategories = $this->industryNames(
            self::decodeList($_POST['known_categories'] ?? null),
        );
        $categories = [];
        foreach ((array) ($_POST['categories'] ?? []) as $value) {
            if (is_string($value) && in_array($value, $allowedCategories, true)) {
                $categories[] = $value;
            }
        }
        $categories = array_values(array_unique($categories));

        // Sichtbare Profilfelder
        $visible = [];
        foreach ((array) ($_POST['visible_fields'] ?? []) as $value) {
            if (is_string($value) && isset(self::VISIBLE_FIELDS[$value])) {
                $visible[] = $value;
            }
        }
        $visible = array_values(array_unique($visible));

        return [
            'name' => $name,
            'short_description' => ExhibitorProfile::text($_POST['short_description'] ?? null, 'short_description'),
            'description' => ExhibitorProfile::text($_POST['description'] ?? null, 'description'),
            'categories' => $categories === [] ? null : json_encode($categories, JSON_UNESCAPED_UNICODE),
            'contact_person' => ExhibitorProfile::text($_POST['contact_person'] ?? null, 'contact_person'),
            'email' => $email === '' ? null : mb_substr($email, 0, ExhibitorProfile::MAX_LENGTHS['email']),
            'phone' => ExhibitorProfile::text($_POST['phone'] ?? null, 'phone'),
            'website' => ExhibitorProfile::website($_POST['website'] ?? null),
            'jobs' => ExhibitorProfile::text($_POST['jobs'] ?? null, 'jobs'),
            'features' => ExhibitorProfile::text($_POST['features'] ?? null, 'features'),
            'offer_types' => ExhibitorProfile::encodeOffers(
                $_POST['offer_types_selected'] ?? [],
                $_POST['offer_types_custom'] ?? null,
            ),
            'equipment' => ExhibitorProfile::text($_POST['equipment'] ?? null, 'equipment'),
            'visible_fields' => json_encode($visible, JSON_UNESCAPED_UNICODE),
            'total_slots' => $this->clampInt($_POST['total_slots'] ?? 25, 0, 9999),
        ];
    }

    /** Speichert ein hochgeladenes Logo und gibt den Dateinamen zurück. */
    private function storeLogo(string $back): ?string
    {
        $file = $_FILES['logo'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        try {
            $stored = $this->uploads()->store(
                $file,
                'logos',
                ExhibitorProfile::LOGO_EXTENSIONS,
                (int) ($this->ctx->config['uploads']['max_logo_bytes'] ?? 2097152),
            );
        } catch (HttpException $e) {
            $this->ctx->session->rememberInput($_POST);
            $this->flash('error', $e->getMessage() !== '' ? $e->getMessage() : 'Das Logo konnte nicht gespeichert werden.');
            $this->redirect($back);
        }

        return $stored['filename'];
    }

    /**
     * Baut die Formularwerte aus Datensatz und gemerkten Eingaben.
     *
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    private function formValues(?array $row): array
    {
        $offers = self::decodeOfferParts($row['offer_types'] ?? null);
        $values = [
            'name' => (string) ($row['name'] ?? ''),
            'short_description' => (string) ($row['short_description'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'categories' => self::decodeList($row['categories'] ?? null),
            'contact_person' => (string) ($row['contact_person'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'website' => (string) ($row['website'] ?? ''),
            'jobs' => (string) ($row['jobs'] ?? ''),
            'features' => (string) ($row['features'] ?? ''),
            'offer_types_selected' => $offers['selected'],
            'offer_types_custom' => $offers['custom'],
            'equipment' => (string) ($row['equipment'] ?? ''),
            'visible_fields' => $row === null ? ['website'] : self::decodeVisibleFields($row['visible_fields']),
            'total_slots' => (int) ($row['total_slots'] ?? 25),
            'pipeline_status' => (string) ($row['pipeline_status'] ?? 'confirmed'),
        ];

        $old = $this->ctx->session->pullOldInput();
        if ($old === []) {
            return $values;
        }

        foreach (['name', 'short_description', 'description', 'contact_person', 'email',
                  'phone', 'website', 'jobs', 'features', 'equipment', 'offer_types_custom'] as $key) {
            if (isset($old[$key]) && is_string($old[$key])) {
                $values[$key] = $old[$key];
            }
        }
        foreach (['categories', 'offer_types_selected', 'visible_fields'] as $key) {
            if (isset($old[$key]) && is_array($old[$key])) {
                $values[$key] = array_values(array_filter($old[$key], 'is_string'));
            } elseif (array_key_exists('name', $old)) {
                // Formular wurde abgeschickt, Gruppe war leer
                $values[$key] = [];
            }
        }
        if (isset($old['total_slots'])) {
            $values['total_slots'] = $this->clampInt($old['total_slots'], 0, 9999);
        }
        if (isset($old['pipeline_status']) && isset(ExhibitorPipelineController::STAGES[$old['pipeline_status']])) {
            $values['pipeline_status'] = (string) $old['pipeline_status'];
        }

        return $values;
    }

    /**
     * Benennt eine Branche in den categories-JSONs aller Editionen der Schule um.
     * Branchen gehören der Schule, also gilt der neue Name auch für frühere Jahrgänge.
     */
    private function renameCategory(int $schoolId, string $oldName, string $newName): int
    {
        $rows = $this->ctx->db->fetchAll(
            'SELECT e.id, e.categories
             FROM exhibitors e
             JOIN messe_editions me ON me.id = e.edition_id
             WHERE me.school_id = ? AND e.categories IS NOT NULL',
            [$schoolId],
        );

        $count = 0;
        foreach ($rows as $row) {
            $list = self::decodeList($row['categories']);
            if (!in_array($oldName, $list, true)) {
                continue;
            }
            $list = array_values(array_unique(array_map(
                static fn (string $n): string => $n === $oldName ? $newName : $n,
                $list,
            )));
            $this->ctx->db->run(
                'UPDATE exhibitors SET categories = ? WHERE id = ?',
                [json_encode($list, JSON_UNESCAPED_UNICODE), (int) $row['id']],
            );
            $count++;
        }

        return $count;
    }

    /** @return array<string, mixed> Branche dieser Schule (sonst 404). */
    private function findIndustry(int $id): array
    {
        $industry = $this->ctx->db->fetchOne(
            'SELECT * FROM industries WHERE id = ? AND school_id = ?',
            [$id, (int) $this->ctx->schoolId()],
        );
        if ($industry === null) {
            throw new HttpException(404, 'Diese Branche existiert nicht.');
        }

        return $industry;
    }

    private function clampInt(mixed $value, int $min, int $max): int
    {
        $int = (int) $value;

        return max($min, min($max, $int));
    }
}
