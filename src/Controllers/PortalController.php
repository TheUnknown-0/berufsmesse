<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;
use App\Services\CancellationService;
use App\Services\Capacity;
use App\Services\ExhibitorInvitations;
use App\Services\ExhibitorProfile;
use App\Services\Uploads;

/**
 * Aussteller-Portal (/{school}/portal…) sowie die Verwaltungsseite
 * „Aussteller-Konten & Einladungen" (/{school}/admin/aussteller-konten).
 *
 * Isolation: Ein Aussteller-Konto erreicht ein Unternehmen ausschließlich
 * über eine aktive, angenommene Verknüpfung in exhibitor_users, deren
 * Unternehmen zur aktiven Edition DIESER Schule gehört. IDs aus dem Request
 * werden nie ungeprüft übernommen.
 */
final class PortalController extends Controller
{
    // ================= Portal =================

    /** GET /{school}/portal */
    public function index(array $params): string
    {
        $edition = $this->requirePortal($params['school']);
        $exhibitors = $this->myExhibitors();
        $cancellation = new CancellationService($this->ctx);

        $requests = [];
        $equipment = [];
        foreach ($exhibitors as $exhibitor) {
            $id = (int) $exhibitor['id'];
            $requests[$id] = $cancellation->pendingRequest($id);
            $equipment[$id] = $this->ctx->db->fetchAll(
                'SELECT r.id, r.quantity, r.status, r.custom_text, o.name AS option_name
                 FROM exhibitor_equipment_requests r
                 LEFT JOIN equipment_options o ON o.id = r.equipment_option_id
                 WHERE r.exhibitor_id = ? AND r.edition_id = ?
                 ORDER BY r.created_at DESC',
                [$id, (int) $edition['id']],
            );
        }

        return $this->render('pages/portal/index', [
            'title' => 'Aussteller-Portal',
            'exhibitors' => $exhibitors,
            'pendingRequests' => $requests,
            'equipmentByExhibitor' => $equipment,
            'daysUntilEvent' => $cancellation->daysUntilEvent($edition),
            'noticeDays' => CancellationService::NOTICE_DAYS,
            'pageScripts' => ['portal.js'],
        ]);
    }

    /** GET /{school}/portal/profil/{id} */
    public function profile(array $params): string
    {
        $this->requirePortal($params['school']);
        $exhibitor = $this->requireMyExhibitor((int) $params['id']);
        if ((int) $exhibitor['can_edit_profile'] !== 1) {
            throw new HttpException(403, 'Für dieses Unternehmen darfst du das Profil nicht bearbeiten.');
        }

        $offer = $this->decodeOfferTypes($exhibitor['offer_types'] ?? null);

        return $this->render('pages/portal/profil', [
            'title' => 'Profil: ' . (string) $exhibitor['name'],
            'exhibitor' => $exhibitor,
            'offerTypes' => ExhibitorProfile::OFFER_TYPES,
            'offerSelected' => $offer['selected'],
            'offerCustom' => $offer['custom'],
        ]);
    }

    /** POST /{school}/portal/profil/{id} */
    public function saveProfile(array $params): string
    {
        $this->requirePortal($params['school']);
        $this->requireCsrf();
        $exhibitor = $this->requireMyExhibitor((int) $params['id']);
        if ((int) $exhibitor['can_edit_profile'] !== 1) {
            throw new HttpException(403, 'Für dieses Unternehmen darfst du das Profil nicht bearbeiten.');
        }

        $exhibitorId = (int) $exhibitor['id'];
        $back = $this->ctx->schoolUrl('/portal/profil/' . $exhibitorId);

        // Feldregeln und Angebotstypen kommen aus ExhibitorProfile, damit das
        // Portal nichts verwirft, was die Schule im Admin-Formular gepflegt hat.
        $shortDescription = ExhibitorProfile::text($_POST['short_description'] ?? null, 'short_description');
        $description = ExhibitorProfile::text($_POST['description'] ?? null, 'description');
        $contactPerson = ExhibitorProfile::text($_POST['contact_person'] ?? null, 'contact_person');
        $email = ExhibitorProfile::text($_POST['email'] ?? null, 'email');
        $phone = ExhibitorProfile::text($_POST['phone'] ?? null, 'phone');
        $website = ExhibitorProfile::website($_POST['website'] ?? null);
        $jobs = ExhibitorProfile::text($_POST['jobs'] ?? null, 'jobs');
        $features = ExhibitorProfile::text($_POST['features'] ?? null, 'features');

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->flash('error', 'Bitte gib eine gültige E-Mail-Adresse an.');
            $this->redirect($back);
        }

        $offerTypes = ExhibitorProfile::encodeOffers($_POST['offer_types'] ?? [], $_POST['offer_types_custom'] ?? null);

        // Logo (optional)
        $oldLogo = is_string($exhibitor['logo']) && $exhibitor['logo'] !== '' ? $exhibitor['logo'] : null;
        $logo = $oldLogo;
        $uploads = new Uploads($this->ctx->config['uploads']['dir']);
        if (isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $stored = $uploads->store(
                    $_FILES['logo'],
                    'logos',
                    ExhibitorProfile::LOGO_EXTENSIONS,
                    (int) ($this->ctx->config['uploads']['max_logo_bytes'] ?? 2097152),
                );
            } catch (HttpException $e) {
                $this->flash('error', $e->getMessage() !== '' ? $e->getMessage() : 'Das Logo konnte nicht gespeichert werden.');
                $this->redirect($back);
            }
            $logo = $stored['filename'];
        }

        $this->ctx->db->run(
            'UPDATE exhibitors
             SET short_description = ?, description = ?, contact_person = ?, email = ?, phone = ?,
                 website = ?, jobs = ?, features = ?, offer_types = ?, logo = ?
             WHERE id = ? AND edition_id = ?',
            [
                $shortDescription, $description, $contactPerson, $email, $phone,
                $website, $jobs, $features, $offerTypes, $logo,
                $exhibitorId, (int) $this->ctx->requireEdition()['id'],
            ],
        );

        // Altes Logo erst nach dem Update entfernen, und nur, wenn keine andere
        // Edition dieselbe Datei nutzt (Klonen übernimmt den Dateinamen).
        if ($oldLogo !== null && $logo !== $oldLogo) {
            $uploads->deleteLogoIfUnused($this->ctx->db, $oldLogo, $exhibitorId);
        }

        $this->ctx->audit->log(
            'Aussteller-Profil im Portal bearbeitet',
            'info',
            'Aussteller: ' . (string) $exhibitor['name'],
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Profil gespeichert.');
        $this->redirect($back);
    }

    /** GET /{school}/portal/slots */
    public function slots(array $params): string
    {
        $edition = $this->requirePortal($params['school']);
        $editionId = (int) $edition['id'];
        $exhibitors = $this->myExhibitors();

        $timeslots = $this->ctx->db->fetchAll(
            'SELECT * FROM timeslots
             WHERE edition_id = ? AND is_managed = 1 AND is_break = 0
             ORDER BY slot_number',
            [$editionId],
        );

        // Kapazitätslogik zentral aus dem Capacity-Service (siehe ARCHITECTURE.md)
        $capacityService = new Capacity($this->ctx->db);
        $capacityService->preload($editionId);

        $rows = [];
        foreach ($exhibitors as $exhibitor) {
            $exhibitorId = (int) $exhibitor['id'];

            $slots = [];
            foreach ($timeslots as $slot) {
                $slotId = (int) $slot['id'];
                $slots[] = $slot + [
                    'registered' => $capacityService->used($editionId, $exhibitorId, $slotId),
                    'capacity' => $capacityService->capacity($editionId, $exhibitorId, $slotId),
                ];
            }

            $rows[] = [
                'exhibitor' => $exhibitor,
                'slots' => $slots,
                'unassigned' => (int) $this->ctx->db->fetchValue(
                    'SELECT COUNT(*) FROM registrations
                     WHERE exhibitor_id = ? AND edition_id = ? AND timeslot_id IS NULL',
                    [$exhibitorId, $editionId],
                ),
                'total' => (int) $this->ctx->db->fetchValue(
                    'SELECT COUNT(*) FROM registrations WHERE exhibitor_id = ? AND edition_id = ?',
                    [$exhibitorId, $editionId],
                ),
                'classes' => $this->ctx->db->fetchAll(
                    'SELECT COALESCE(NULLIF(u.class, \'\'), \'ohne Klasse\') AS class_name, COUNT(*) AS anzahl
                     FROM registrations r
                     JOIN users u ON u.id = r.user_id
                     WHERE r.exhibitor_id = ? AND r.edition_id = ?
                     GROUP BY class_name
                     ORDER BY class_name',
                    [$exhibitorId, $editionId],
                ),
            ];
        }

        return $this->render('pages/portal/slots', [
            'title' => 'Slots & Anmeldungen',
            'rows' => $rows,
        ]);
    }

    /** GET /{school}/portal/ausstattung */
    public function equipment(array $params): string
    {
        $edition = $this->requirePortal($params['school']);
        $exhibitors = $this->myExhibitors();

        $options = $this->ctx->db->fetchAll(
            'SELECT * FROM equipment_options
             WHERE school_id = ? AND is_active = 1
             ORDER BY sort_order, name',
            [(int) $this->ctx->schoolId()],
        );

        $requests = [];
        foreach ($exhibitors as $exhibitor) {
            $requests[(int) $exhibitor['id']] = $this->ctx->db->fetchAll(
                'SELECT r.*, o.name AS option_name
                 FROM exhibitor_equipment_requests r
                 LEFT JOIN equipment_options o ON o.id = r.equipment_option_id
                 WHERE r.exhibitor_id = ? AND r.edition_id = ?
                 ORDER BY r.created_at DESC, r.id DESC',
                [(int) $exhibitor['id'], (int) $edition['id']],
            );
        }

        return $this->render('pages/portal/ausstattung', [
            'title' => 'Ausstattung',
            'exhibitors' => $exhibitors,
            'options' => $options,
            'requests' => $requests,
        ]);
    }

    /** POST /{school}/portal/ausstattung */
    public function equipmentStore(array $params): string
    {
        $edition = $this->requirePortal($params['school']);
        $this->requireCsrf();
        $exhibitor = $this->requireMyExhibitor((int) ($_POST['exhibitor_id'] ?? 0));

        $back = $this->ctx->schoolUrl('/portal/ausstattung');
        $optionId = (int) ($_POST['equipment_option_id'] ?? 0);
        $customText = $this->text('custom_text', 500);
        $quantity = max(1, min(99, (int) ($_POST['quantity'] ?? 1)));

        if ($optionId > 0) {
            $valid = $this->ctx->db->fetchValue(
                'SELECT 1 FROM equipment_options WHERE id = ? AND school_id = ? AND is_active = 1',
                [$optionId, (int) $this->ctx->schoolId()],
            );
            if ($valid === null) {
                $this->flash('error', 'Diese Ausstattungsoption ist nicht verfügbar.');
                $this->redirect($back);
            }
            $customText = null;
        } elseif ($customText === null) {
            $this->flash('error', 'Bitte wähle eine Option oder beschreibe deinen Wunsch im Freitextfeld.');
            $this->redirect($back);
        }

        $this->ctx->db->run(
            'INSERT INTO exhibitor_equipment_requests
                (exhibitor_id, edition_id, equipment_option_id, custom_text, quantity, status, requested_by)
             VALUES (?, ?, ?, ?, ?, \'pending\', ?)',
            [
                (int) $exhibitor['id'],
                (int) $edition['id'],
                $optionId > 0 ? $optionId : null,
                $customText,
                $quantity,
                (int) $this->ctx->auth->id(),
            ],
        );

        $this->ctx->audit->log(
            'Ausstattung angefragt',
            'info',
            'Aussteller: ' . (string) $exhibitor['name'] . ' — Menge: ' . $quantity,
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Deine Anfrage wurde übermittelt.');
        $this->redirect($back);
    }

    /** POST /{school}/portal/ausstattung/{id}/stornieren */
    public function equipmentCancel(array $params): string
    {
        $edition = $this->requirePortal($params['school']);
        $this->requireCsrf();

        $request = $this->ctx->db->fetchOne(
            'SELECT * FROM exhibitor_equipment_requests WHERE id = ? AND edition_id = ?',
            [(int) $params['id'], (int) $edition['id']],
        );
        if ($request === null) {
            throw new HttpException(404, 'Diese Anfrage existiert nicht.');
        }
        $exhibitor = $this->requireMyExhibitor((int) $request['exhibitor_id']);

        if ($request['status'] !== 'pending') {
            $this->flash('error', 'Nur offene Anfragen können storniert werden.');
            $this->redirect($this->ctx->schoolUrl('/portal/ausstattung'));
        }

        $this->ctx->db->run(
            'DELETE FROM exhibitor_equipment_requests WHERE id = ? AND exhibitor_id = ? AND status = \'pending\'',
            [(int) $request['id'], (int) $exhibitor['id']],
        );

        $this->ctx->audit->log(
            'Ausstattungsanfrage storniert',
            'info',
            'Aussteller: ' . (string) $exhibitor['name'],
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Anfrage storniert.');
        $this->redirect($this->ctx->schoolUrl('/portal/ausstattung'));
    }

    /** GET /{school}/portal/dokumente */
    public function documents(array $params): string
    {
        $this->requirePortal($params['school']);
        $exhibitors = array_values(array_filter(
            $this->myExhibitors(),
            static fn (array $e): bool => (int) $e['can_manage_documents'] === 1,
        ));

        $documents = [];
        foreach ($exhibitors as $exhibitor) {
            $documents[(int) $exhibitor['id']] = $this->ctx->db->fetchAll(
                'SELECT * FROM exhibitor_documents WHERE exhibitor_id = ? ORDER BY uploaded_at DESC, id DESC',
                [(int) $exhibitor['id']],
            );
        }

        return $this->render('pages/portal/dokumente', [
            'title' => 'Dokumente',
            'exhibitors' => $exhibitors,
            'documents' => $documents,
        ]);
    }

    /** POST /{school}/portal/dokumente */
    public function documentUpload(array $params): string
    {
        $this->requirePortal($params['school']);
        $this->requireCsrf();
        $exhibitor = $this->requireMyExhibitor((int) ($_POST['exhibitor_id'] ?? 0), true);

        $back = $this->ctx->schoolUrl('/portal/dokumente');
        if (!isset($_FILES['document']) || ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->flash('error', 'Bitte wähle eine Datei aus.');
            $this->redirect($back);
        }

        // Gleiche Dateitypen, Größe und Ablage wie beim Upload durch die Schule
        $uploads = new Uploads($this->ctx->config['uploads']['dir']);
        try {
            $stored = $uploads->store(
                $_FILES['document'],
                'documents',
                ExhibitorProfile::DOCUMENT_EXTENSIONS,
                (int) ($this->ctx->config['uploads']['max_document_bytes'] ?? 10485760),
            );
        } catch (HttpException $e) {
            $this->flash('error', $e->getMessage() !== '' ? $e->getMessage() : 'Das Dokument konnte nicht gespeichert werden.');
            $this->redirect($back);
        }
        $extension = strtolower(pathinfo($stored['original_name'], PATHINFO_EXTENSION));

        $this->ctx->db->run(
            'INSERT INTO exhibitor_documents
                (exhibitor_id, filename, original_name, file_type, file_size, visible_for_students)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                (int) $exhibitor['id'],
                $stored['filename'],
                mb_substr($stored['original_name'], 0, 255),
                $extension !== '' ? $extension : null,
                $stored['size'],
                isset($_POST['visible_for_students']) ? 1 : 0,
            ],
        );

        $this->ctx->audit->log(
            'Dokument im Portal hochgeladen',
            'info',
            'Aussteller: ' . (string) $exhibitor['name'] . ' — Datei: ' . $stored['original_name'],
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Dokument hochgeladen.');
        $this->redirect($back);
    }

    /** POST /{school}/portal/dokumente/{id}/loeschen */
    public function documentDelete(array $params): string
    {
        $this->requirePortal($params['school']);
        $this->requireCsrf();

        $document = $this->requireMyDocument((int) $params['id']);
        $this->ctx->db->run('DELETE FROM exhibitor_documents WHERE id = ?', [(int) $document['id']]);
        (new Uploads($this->ctx->config['uploads']['dir']))->delete('documents', (string) $document['filename']);

        $this->ctx->audit->log(
            'Dokument im Portal gelöscht',
            'warning',
            'Datei: ' . (string) $document['original_name'],
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Dokument gelöscht.');
        $this->redirect($this->ctx->schoolUrl('/portal/dokumente'));
    }

    /** POST /{school}/portal/dokumente/{id}/sichtbarkeit */
    public function documentToggle(array $params): string
    {
        $this->requirePortal($params['school']);
        $this->requireCsrf();

        $document = $this->requireMyDocument((int) $params['id']);
        $visible = (int) $document['visible_for_students'] === 1 ? 0 : 1;
        $this->ctx->db->run(
            'UPDATE exhibitor_documents SET visible_for_students = ? WHERE id = ?',
            [$visible, (int) $document['id']],
        );

        $this->ctx->audit->log(
            'Dokument-Sichtbarkeit geändert',
            'info',
            'Datei: ' . (string) $document['original_name'] . ' — sichtbar: ' . ($visible === 1 ? 'ja' : 'nein'),
            $this->ctx->schoolId(),
        );
        $this->flash('success', $visible === 1 ? 'Dokument ist jetzt für Schüler:innen sichtbar.' : 'Dokument ist nicht mehr sichtbar.');
        $this->redirect($this->ctx->schoolUrl('/portal/dokumente'));
    }

    /** GET /{school}/portal/dokumente/{id}/download */
    public function documentDownload(array $params): string
    {
        $this->requirePortal($params['school']);
        $document = $this->requireMyDocument((int) $params['id']);

        (new Uploads($this->ctx->config['uploads']['dir']))
            ->stream('documents', (string) $document['filename'], (string) $document['original_name']);
    }

    /** POST /{school}/portal/absage/{id} */
    public function cancel(array $params): string
    {
        $edition = $this->requirePortal($params['school']);
        $this->requireCsrf();
        $exhibitor = $this->requireMyExhibitor((int) $params['id']);

        $back = $this->ctx->schoolUrl('/portal');
        $reason = $this->text('reason', 500);
        if ($reason === null) {
            $this->flash('error', 'Bitte gib eine Begründung für die Absage an.');
            $this->redirect($back);
        }
        if (($_POST['confirm'] ?? '') !== '1') {
            $this->flash('error', 'Bitte bestätige die Absage über das Kontrollkästchen.');
            $this->redirect($back);
        }

        $service = new CancellationService($this->ctx);
        $schoolId = (int) $this->ctx->schoolId();

        if ($service->requiresApproval($edition)) {
            $service->requestCancellation($exhibitor, $schoolId, (int) $this->ctx->auth->id(), $reason);
            $this->flash('info', 'Deine Absage wurde an die Schule übermittelt und wartet auf Bestätigung. Bis dahin bleibst du eingeplant.');
            $this->redirect($back);
        }

        $affected = $service->execute($exhibitor, $edition, $schoolId, $reason, 'cancelled_by_exhibitor', (int) $this->ctx->auth->id());
        $this->flash('success', $affected > 0
            ? 'Die Teilnahme wurde abgesagt. ' . $affected . ' betroffene Schüler:innen wurden benachrichtigt.'
            : 'Die Teilnahme wurde abgesagt.');
        $this->redirect($back);
    }

    // ================= Admin: Aussteller-Konten =================

    /** GET /{school}/admin/aussteller-konten */
    public function accounts(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_KONTEN_VERWALTEN);
        $edition = $this->ctx->requireEdition();

        $exhibitors = $this->ctx->db->fetchAll(
            'SELECT id, name, active FROM exhibitors WHERE edition_id = ? ORDER BY name',
            [(int) $edition['id']],
        );
        // Nur Konten, die eine Einladung dieser Schule angenommen haben.
        // Konten anderer Schulen bleiben unsichtbar.
        $links = $this->ctx->db->fetchAll(
            'SELECT eu.*, u.username, u.email, u.firstname, u.lastname, e.name AS exhibitor_name
             FROM exhibitor_users eu
             JOIN exhibitors e ON e.id = eu.exhibitor_id
             JOIN users u ON u.id = eu.user_id
             WHERE e.edition_id = ?
             ORDER BY e.name, u.username',
            [(int) $edition['id']],
        );

        $byExhibitor = [];
        foreach ($links as $link) {
            $byExhibitor[(int) $link['exhibitor_id']][] = $link;
        }

        $invitesByExhibitor = [];
        foreach ((new ExhibitorInvitations($this->ctx->db))->openForEdition((int) $edition['id']) as $invite) {
            $invitesByExhibitor[(int) $invite['exhibitor_id']][] = $invite;
        }

        $requests = $this->ctx->db->fetchAll(
            'SELECT cr.*, e.name AS exhibitor_name, u.username
             FROM cancellation_requests cr
             JOIN exhibitors e ON e.id = cr.exhibitor_id
             LEFT JOIN users u ON u.id = cr.user_id
             WHERE cr.school_id = ? AND cr.status = \'pending\' AND e.edition_id = ?
             ORDER BY cr.created_at',
            [(int) $this->ctx->schoolId(), (int) $edition['id']],
        );

        return $this->render('pages/portal/konten', [
            'title' => 'Aussteller-Konten',
            'exhibitors' => $exhibitors,
            'linksByExhibitor' => $byExhibitor,
            'invitesByExhibitor' => $invitesByExhibitor,
            'requests' => $requests,
            'inviteBaseUrl' => $this->ctx->publicUrl('/aussteller-einladung?token='),
            'pageScripts' => ['portal.js'],
        ]);
    }

    /**
     * POST /{school}/admin/aussteller-konten/einladen
     *
     * Erzeugt nur einen Link für das Unternehmen. Welches Konto ihn annimmt,
     * entscheidet der Empfänger; die Schule legt keine Konten an.
     */
    public function invite(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_KONTEN_VERWALTEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $back = $this->ctx->schoolUrl('/admin/aussteller-konten');
        $exhibitor = $this->ctx->db->fetchOne(
            'SELECT * FROM exhibitors WHERE id = ? AND edition_id = ?',
            [(int) ($_POST['exhibitor_id'] ?? 0), (int) $edition['id']],
        );
        if ($exhibitor === null) {
            $this->flash('error', 'Bitte wähle ein Unternehmen dieser Messe aus.');
            $this->redirect($back);
        }

        $note = $this->text('note', 200);
        (new ExhibitorInvitations($this->ctx->db))->create((int) $exhibitor['id'], $note, $this->ctx->auth->id());

        $this->ctx->audit->log(
            'Aussteller eingeladen',
            'info',
            'Unternehmen: ' . (string) $exhibitor['name'] . ($note !== null ? ', Notiz: ' . $note : ''),
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Einladungslink erstellt. Er steht beim Unternehmen zum Kopieren bereit und gilt '
            . ExhibitorInvitations::VALID_DAYS . ' Tage.');
        $this->redirect($back);
    }

    /** POST /{school}/admin/aussteller-konten/einladung/{id}/zurueckziehen */
    public function revokeInvite(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_KONTEN_VERWALTEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $invite = $this->ctx->db->fetchOne(
            'SELECT i.id, e.name AS exhibitor_name
             FROM exhibitor_invitations i
             JOIN exhibitors e ON e.id = i.exhibitor_id
             WHERE i.id = ? AND e.edition_id = ?',
            [(int) $params['id'], (int) $edition['id']],
        );
        if ($invite === null) {
            throw new HttpException(404, 'Diese Einladung existiert nicht.');
        }

        (new ExhibitorInvitations($this->ctx->db))->revoke((int) $invite['id']);
        $this->ctx->audit->log(
            'Aussteller-Einladung zurückgezogen',
            'info',
            'Unternehmen: ' . (string) $invite['exhibitor_name'],
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Einladung zurückgezogen. Der Link ist ab sofort ungültig.');
        $this->redirect($this->ctx->schoolUrl('/admin/aussteller-konten'));
    }

    /** POST /{school}/admin/aussteller-konten/{id}/rechte */
    public function updateRights(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_KONTEN_VERWALTEN);
        $this->requireCsrf();

        $link = $this->requireLink((int) $params['id']);
        $this->ctx->db->run(
            'UPDATE exhibitor_users SET can_edit_profile = ?, can_manage_documents = ? WHERE id = ?',
            [
                isset($_POST['can_edit_profile']) ? 1 : 0,
                isset($_POST['can_manage_documents']) ? 1 : 0,
                (int) $link['id'],
            ],
        );

        $this->ctx->audit->log(
            'Rechte einer Aussteller-Verknüpfung geändert',
            'info',
            'Benutzer: ' . (string) $link['username'] . ' — Unternehmen: ' . (string) $link['exhibitor_name'],
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Rechte gespeichert.');
        $this->redirect($this->ctx->schoolUrl('/admin/aussteller-konten'));
    }

    /** POST /{school}/admin/aussteller-konten/{id}/entfernen */
    public function removeLink(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_KONTEN_VERWALTEN);
        $this->requireCsrf();

        $link = $this->requireLink((int) $params['id']);
        $this->ctx->db->run(
            'UPDATE exhibitor_users
             SET status = \'removed_by_admin\', cancelled_at = NOW()
             WHERE id = ?',
            [(int) $link['id']],
        );

        $this->ctx->audit->log(
            'Aussteller-Verknüpfung entfernt',
            'warning',
            'Benutzer: ' . (string) $link['username'] . ' — Unternehmen: ' . (string) $link['exhibitor_name'],
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Verknüpfung entfernt.');
        $this->redirect($this->ctx->schoolUrl('/admin/aussteller-konten'));
    }

    /** POST /{school}/admin/aussteller-konten/absage/{id}/bestaetigen */
    public function confirmCancellation(array $params): string
    {
        [$request, $exhibitor, $edition] = $this->requireCancellationRequest($params);

        $affected = (new CancellationService($this->ctx))->execute(
            $exhibitor,
            $edition,
            (int) $this->ctx->schoolId(),
            (string) ($request['reason'] ?? ''),
            'cancelled_by_exhibitor',
            (int) $this->ctx->auth->id(),
        );

        $this->flash('success', 'Absage bestätigt. ' . $affected
            . ' Schüler:innen haben jetzt eine ruhende Buchung und warten auf eine Neuzuteilung.');
        $this->redirect($this->ctx->schoolUrl('/admin/aussteller-konten'));
    }

    /** POST /{school}/admin/aussteller-konten/absage/{id}/ablehnen */
    public function rejectCancellation(array $params): string
    {
        [$request, $exhibitor] = $this->requireCancellationRequest($params);

        (new CancellationService($this->ctx))->rejectRequest(
            $request,
            $exhibitor,
            (int) $this->ctx->schoolId(),
            (int) $this->ctx->auth->id(),
        );

        $this->flash('success', 'Absage abgelehnt. Der Aussteller bleibt eingeplant.');
        $this->redirect($this->ctx->schoolUrl('/admin/aussteller-konten'));
    }

    // ================= Helfer =================

    /**
     * Portal-Zugang: Schule laden, Rolle prüfen, aktive Edition liefern.
     * Schul-Admins dürfen zur Vorschau/Anordnung hinein (ihre Unternehmens-
     * Listen sind leer, die Seiten zeigen dann Empty-States).
     */
    private function requirePortal(string $slug): array
    {
        $this->requireSchool($slug);
        if ($this->ctx->auth->role() !== 'exhibitor' && !\App\Core\PageBlocks::adminPreviewAllowed()) {
            throw new HttpException(403, 'Das Aussteller-Portal steht nur Aussteller-Konten offen.');
        }

        return $this->ctx->requireEdition();
    }

    /**
     * Unternehmen des eingeloggten Aussteller-Kontos in der aktiven Edition
     * dieser Schule — inklusive der Rechte aus der Verknüpfung.
     *
     * @return list<array<string, mixed>>
     */
    private function myExhibitors(): array
    {
        return $this->ctx->db->fetchAll(
            'SELECT e.*, eu.id AS link_id, eu.can_edit_profile, eu.can_manage_documents,
                    r.room_number, r.room_name, r.capacity AS room_capacity,
                    (SELECT COUNT(*) FROM registrations reg
                      WHERE reg.exhibitor_id = e.id AND reg.edition_id = e.edition_id) AS registration_count
             FROM exhibitor_users eu
             JOIN exhibitors e ON e.id = eu.exhibitor_id
             JOIN messe_editions me ON me.id = e.edition_id
             LEFT JOIN rooms r ON r.id = e.room_id
             WHERE eu.user_id = ? AND eu.status = \'active\' AND eu.invite_accepted = 1
               AND me.school_id = ? AND e.edition_id = ?
             ORDER BY e.name',
            [
                (int) $this->ctx->auth->id(),
                (int) $this->ctx->schoolId(),
                (int) $this->ctx->requireEdition()['id'],
            ],
        );
    }

    /** Ein einzelnes Unternehmen mit geprüfter Verknüpfung (sonst 403/404). */
    private function requireMyExhibitor(int $exhibitorId, bool $needsDocumentRight = false): array
    {
        foreach ($this->myExhibitors() as $exhibitor) {
            if ((int) $exhibitor['id'] === $exhibitorId) {
                if ($needsDocumentRight && (int) $exhibitor['can_manage_documents'] !== 1) {
                    throw new HttpException(403, 'Für dieses Unternehmen darfst du keine Dokumente verwalten.');
                }

                return $exhibitor;
            }
        }

        throw new HttpException(403, 'Dieses Unternehmen ist deinem Konto nicht zugeordnet.');
    }

    /** Dokument eines eigenen Unternehmens (mit Dokumentenrecht). */
    private function requireMyDocument(int $documentId): array
    {
        $document = $this->ctx->db->fetchOne(
            'SELECT * FROM exhibitor_documents WHERE id = ?',
            [$documentId],
        );
        if ($document === null) {
            throw new HttpException(404, 'Dieses Dokument existiert nicht.');
        }
        $this->requireMyExhibitor((int) $document['exhibitor_id'], true);

        return $document;
    }

    /** Verknüpfung, deren Unternehmen zur aktiven Edition dieser Schule gehört. */
    private function requireLink(int $linkId): array
    {
        $link = $this->ctx->db->fetchOne(
            'SELECT eu.*, u.username, e.name AS exhibitor_name
             FROM exhibitor_users eu
             JOIN exhibitors e ON e.id = eu.exhibitor_id
             JOIN users u ON u.id = eu.user_id
             WHERE eu.id = ? AND e.edition_id = ?',
            [$linkId, (int) $this->ctx->requireEdition()['id']],
        );
        if ($link === null) {
            throw new HttpException(404, 'Diese Verknüpfung existiert nicht.');
        }

        return $link;
    }

    /**
     * Gemeinsame Prüfung für Bestätigen/Ablehnen einer Absage-Anfrage.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function requireCancellationRequest(array $params): array
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_KONTEN_VERWALTEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $request = $this->ctx->db->fetchOne(
            'SELECT cr.* FROM cancellation_requests cr
             JOIN exhibitors e ON e.id = cr.exhibitor_id
             WHERE cr.id = ? AND cr.school_id = ? AND cr.status = \'pending\' AND e.edition_id = ?',
            [(int) $params['id'], (int) $this->ctx->schoolId(), (int) $edition['id']],
        );
        if ($request === null) {
            throw new HttpException(404, 'Diese Absage-Anfrage existiert nicht oder wurde bereits bearbeitet.');
        }

        $exhibitor = $this->ctx->db->fetchOne(
            'SELECT * FROM exhibitors WHERE id = ? AND edition_id = ?',
            [(int) $request['exhibitor_id'], (int) $edition['id']],
        );
        if ($exhibitor === null) {
            throw new HttpException(404, 'Das Unternehmen existiert nicht mehr.');
        }

        return [$request, $exhibitor, $edition];
    }

    /** Getrimmtes POST-Feld; leerer String wird zu null. */
    private function text(string $key, int $maxLength): ?string
    {
        $value = trim((string) ($_POST[$key] ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    /**
     * @return array{selected: list<string>, custom: string}
     */
    private function decodeOfferTypes(mixed $raw): array
    {
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return ['selected' => [], 'custom' => ''];
        }

        $selected = [];
        foreach ((array) ($decoded['selected'] ?? []) as $value) {
            if (is_string($value)) {
                $selected[] = $value;
            }
        }

        return [
            'selected' => $selected,
            'custom' => is_string($decoded['custom'] ?? null) ? $decoded['custom'] : '',
        ];
    }
}
