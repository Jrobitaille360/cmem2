<?php

namespace Access\Controllers;

use Access\Services\AccessApprovalService;
use AuthGroups\Models\User;
use AuthGroups\Services\LogService;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\RoleHelper;
use Stripe\Config\CmemModules;

/**
 * Accès sur approbation (plan #269, Phase 2) — endpoints admin (ADMINISTRATEUR+).
 *
 *   PUT    /users/{id}/access-status  {status: pending|approved|suspended}
 *   POST   /invitations               {email, modules?, ai_budget_usd?, expires_in_days?}
 *   GET    /invitations
 *   DELETE /invitations/{id}
 */
class AccessApprovalController
{
    private AccessApprovalService $service;

    public function __construct(?AccessApprovalService $service = null)
    {
        $this->service = $service ?? new AccessApprovalService();
    }

    private function requireAdmin(array $user): bool
    {
        if (!RoleHelper::isAtLeast($user['role'] ?? null, 'ADMINISTRATEUR')) {
            LogService::warning('Accès admin approbation refusé', [
                'user_id' => $user['user_id'] ?? null,
                'role'    => $user['role'] ?? null,
            ]);
            Response::error('Accès non autorisé', null, 403);
            return false;
        }
        return true;
    }

    public function updateStatus(int $targetId, array $user): void
    {
        if (!$this->requireAdmin($user)) {
            return;
        }

        $input  = Response::getRequestParams();
        $status = $input['status'] ?? null;
        if (!is_string($status) || !in_array($status, AccessApprovalService::STATUSES, true)) {
            Response::error('Données de validation invalides',
                ['status' => ['valeurs permises : ' . implode(', ', AccessApprovalService::STATUSES)]], 422);
            return;
        }

        $userModel = new User();
        $target    = $userModel->findById($targetId);
        if (!$target || !empty($target['deleted_at'])) {
            Response::error('Utilisateur non trouvé', null, 404);
            return;
        }

        // Même hiérarchie que la révocation : seul un SUPERADMIN touche un ADMINISTRATEUR,
        // personne ne touche un SUPERADMIN.
        $targetRole = $target['role'] ?? 'UTILISATEUR';
        $allowed = $targetRole === 'SUPERADMINISTRATEUR'
            ? false
            : ($targetRole === 'ADMINISTRATEUR' ? ($user['role'] ?? null) === 'SUPERADMINISTRATEUR' : true);
        if (!$allowed) {
            Response::error('Accès non autorisé', null, 403);
            return;
        }

        $this->service->setStatus($targetId, $status);
        LogService::info('Statut d\'accès modifié', [
            'user_id'    => $targetId,
            'status'     => $status,
            'updated_by' => $user['user_id'],
        ]);

        $updated = $userModel->findById($targetId);
        unset($updated['password_hash']);

        if ($status === 'approved' && ($target['access_status'] ?? null) === 'pending') {
            $this->service->notifyUserApproved($updated);
        }
        Response::success('Statut d\'accès mis à jour', ['user' => $updated]);
    }

    public function createInvitation(array $user): void
    {
        if (!$this->requireAdmin($user)) {
            return;
        }

        $input  = Response::getRequestParams();
        $errors = [];

        $email = is_string($input['email'] ?? null) ? AccessApprovalService::normalizeEmail($input['email']) : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = ['courriel invalide'];
        }

        $modules = $input['modules'] ?? null;
        if ($modules !== null) {
            if (!is_array($modules)) {
                $errors['modules'] = ['tableau de codes de modules attendu'];
            } else {
                $bad = array_filter($modules, fn($m) => !is_string($m) || !CmemModules::isValidKey($m));
                if ($bad) {
                    $errors['modules'] = ['module(s) inconnu(s) : ' . implode(', ', array_map('strval', $bad))];
                }
                $modules = array_values(array_unique($modules));
            }
        }

        $budget = $input['ai_budget_usd'] ?? null;
        if ($budget !== null) {
            if (!is_numeric($budget) || (float) $budget < 0) {
                $errors['ai_budget_usd'] = ['nombre positif ou nul attendu'];
            } else {
                $budget = round((float) $budget, 2);
            }
        }

        $days = $input['expires_in_days'] ?? AccessApprovalService::DEFAULT_INVITATION_DAYS;
        if (!is_numeric($days) || (int) $days < 1 || (int) $days > 365) {
            $errors['expires_in_days'] = ['entier de 1 à 365 attendu'];
        }

        if ($errors) {
            Response::error('Données de validation invalides', $errors, 422);
            return;
        }

        if ($this->service->findValidInvitation($email)) {
            Response::error('Une invitation active existe déjà pour ce courriel', ['email' => ['invitation active']], 409);
            return;
        }

        $invitation = $this->service->createInvitation($email, (int) $user['user_id'], $modules, $budget, (int) $days);

        // Compte déjà existant et en attente : l'approuver tout de suite.
        $existing = (new User())->findByEmail($email);
        if ($existing && empty($existing['deleted_at'])) {
            if ($this->service->applyInvitationFor((int) $existing['id'])) {
                $invitation = $this->service->getInvitation((int) $invitation['id']);
            }
        }

        LogService::info('Invitation d\'accès créée', [
            'invitation_id' => $invitation['id'] ?? null,
            'invited_by'    => $user['user_id'],
        ]);
        Response::success('Invitation créée', ['invitation' => $invitation], 201);
    }

    public function listInvitations(array $user): void
    {
        if (!$this->requireAdmin($user)) {
            return;
        }
        Response::success('Invitations', ['invitations' => $this->service->listInvitations()]);
    }

    public function deleteInvitation(int $id, array $user): void
    {
        if (!$this->requireAdmin($user)) {
            return;
        }
        if (!$this->service->deleteInvitation($id)) {
            Response::error('Invitation non trouvée', null, 404);
            return;
        }
        LogService::info('Invitation d\'accès supprimée', ['invitation_id' => $id, 'deleted_by' => $user['user_id']]);
        Response::success('Invitation supprimée', ['id' => $id]);
    }
}
