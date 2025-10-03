<?php

namespace Memories\Controllers;

use Memories\Models\Tag;
use Memories\Utils\Response;
use Memories\Utils\Validator;
use Memories\Services\LogService;
use Memories\Middleware\LoggingMiddleware;
use Exception;

/**
 * Contrôleur Tag pour la gestion des tags
 * Gère les opérations CRUD et les fonctionnalités avancées des tags
 */
class TagController {
    
    public function __construct() {
        // Pas d'injection nécessaire avec BaseModel
    }
    
    /**
     * Obtenir tous les tags d'un utilisateur
     */
    public function getUserTags($userId, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            // Vérifier les permissions (admin ou utilisateur lui-même)
            if ($currentUserRole !== 'ADMINISTRATEUR' && $currentUserId != $userId) {
                LogService::warning("Tentative d'accès non autorisé aux tags", [
                    'requested_user_id' => $userId,
                    'current_user_id' => $currentUserId,
                    'role' => $currentUserRole
                ]);
                LoggingMiddleware::logExit(403);
                return Response::error('Accès non autorisé', null, 403);
            }
            
            $pagination = Response::getPaginationParams();
            $tag = new Tag();
            $tags = $tag->findByOwner($userId, $pagination['page'], $pagination['limit']);
            
            LogService::info("Tags utilisateur récupérés", [
                'user_id' => $userId,
                'tags_count' => count($tags),
                'page' => $pagination['page']
            ]);
            
            $data = [
                'tags' => $tags,
                'page' => $pagination['page'],
                'limit' => $pagination['limit'],
                'user_id' => $userId
            ];
            
            LoggingMiddleware::logExit(200);
            return Response::success("Liste des tags récupérée", $data);
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des tags utilisateur", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la récupération des tags');
        }
    }
    
    /**
     * Obtenir les tags par table associée
     */
    public function getTagsByTable($tableAssociate, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            $pagination = Response::getPaginationParams();
            $tag = new Tag();
            
            // Seuls les tags de l'utilisateur actuel (sauf admin qui peut voir tous)
            $tagOwner = ($currentUserRole === 'ADMINISTRATEUR') ? null : $currentUserId;
            $tags = $tag->findByTable($tableAssociate, $tagOwner, $pagination['page'], $pagination['limit']);
            
            LogService::info("Tags récupérés par table", [
                'table_associate' => $tableAssociate,
                'user_id' => $currentUserId,
                'tags_count' => count($tags)
            ]);
            
            $data = [
                'tags' => $tags,
                'table_associate' => $tableAssociate,
                'page' => $pagination['page'],
                'limit' => $pagination['limit']
            ];
            
            LoggingMiddleware::logExit(200);
            return Response::success("Tags récupérés pour {$tableAssociate}", $data);
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des tags par table", [
                'table_associate' => $tableAssociate,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la récupération des tags');
        }
    }
    
    /**
     * Rechercher des tags
     */
    public function search($currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            $params = Response::getRequestParams();
            $searchTerm = $params['q'] ?? '';
            $tableAssociate = $params['table_associate'] ?? null;
            
            /* if (empty($searchTerm)) {
                LoggingMiddleware::logExit(400);
                return Response::error('Terme de recherche requis', null, 400);
            } */
            
            $pagination = Response::getPaginationParams();
            $tag = new Tag();
            
            // Seuls les tags de l'utilisateur actuel (sauf admin)
            $tagOwner = ($currentUserRole === 'ADMINISTRATEUR') ? null : $currentUserId;
            $tags = $tag->search($searchTerm, $tableAssociate, $tagOwner, $pagination['page'], $pagination['limit']);
            
            LogService::info("Recherche de tags effectuée", [
                'search_term' => $searchTerm,
                'table_associate' => $tableAssociate,
                'user_id' => $currentUserId,
                'results_count' => count($tags)
            ]);
            
            $data = [
                'tags' => $tags,
                'search_term' => $searchTerm,
                'table_associate' => $tableAssociate,
                'page' => $pagination['page'],
                'limit' => $pagination['limit']
            ];
            
            LoggingMiddleware::logExit(200);
            return Response::success("Résultats de recherche", $data);
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la recherche de tags", [
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la recherche');
        }
    }
    
    /**
     * Obtenir un tag par ID
     */
    public function getById($id, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            $tag = new Tag();
            $tagData = $tag->findById($id);
            
            if (!$tagData) {
                LogService::info("Tag non trouvé", ['id' => $id]);
                LoggingMiddleware::logExit(404);
                return Response::error('Tag non trouvé', null, 404);
            }
            
            // Vérifier les permissions
            if (!$tag->canView($currentUserId) && $currentUserRole !== 'ADMINISTRATEUR') {
                LogService::warning("Tentative d'accès non autorisé au tag", [
                    'tag_id' => $id,
                    'user_id' => $currentUserId,
                    'tag_owner' => $tagData['tag_owner']
                ]);
                LoggingMiddleware::logExit(403);
                return Response::error('Accès non autorisé', null, 403);
            }
            // add usage count
            $tagData['usage_count'] = $tag->getUsageCount();
            LogService::info("Données de tag récupérées", [
                'tag_id' => $id,
                'accessed_by' => $currentUserId
            ]);
            
            LoggingMiddleware::logExit(200);
            return Response::success('Détails du tag récupérés', $tagData);
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération du tag", [
                'tag_id' => $id,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la récupération du tag');
        }
    }
    
    /**
     * Créer un nouveau tag
     */
    public function create($currentUserId) {
        try {
            LoggingMiddleware::logEntry();
            
            $input = Response::getRequestParams();
            
            // Validation
            $validator = new Validator();
            $validation = $validator->validate($input, [
                'name' => 'required|string|min:1|max:100',
                'table_associate' => 'required|in:groups,memories,elements,files,all',
                'color' => 'string|regex:/^#[0-9A-Fa-f]{6}$/'
            ]);
            
            if (!$validation['valid']) {
                LogService::warning("Données de création de tag invalides", [
                    'errors' => $validation['errors']
                ]);
                LoggingMiddleware::logExit(400);
                return Response::error('Données invalides', $validation['errors'], 400);
            }
            
            $tag = new Tag();
            $tag->name = trim($input['name']);
            $tag->table_associate = $input['table_associate'] ?? 'memories';
            $tag->color = $input['color'] ?? '#3498db';
            $tag->tag_owner = $currentUserId;

            // Valider les données du modèle 
            // TODO  inutile je crois
            $errors = $tag->validate();
            if (!empty($errors)) {
                LogService::warning("Validation du tag échouée", ['errors' => $errors]);
                LoggingMiddleware::logExit(400);
                return Response::error('Données invalides', $errors, 400);
            }

            // si le tag existe déjà pour cet utilisateur et cette table, renvoyer une erreur 409
            if ($tag->exists()) {
                LogService::warning("Tentative de création d'un tag existant", [
                    'name' => $tag->name,
                    'table_associate' => $tag->table_associate,
                    'owner_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(409);
                return Response::error('Un tag avec ce nom existe déjà pour cette table', null, 409);
            }

            if ($tag->create()) {
                $createdTag = $tag->toArray();
                
                LogService::info("Tag créé avec succès", [
                    'tag_id' => $tag->id,
                    'name' => $tag->name,
                    'owner_id' => $currentUserId
                ]);
                
                LoggingMiddleware::logExit(201);
                return Response::success('Tag créé avec succès', $createdTag, 201);
            } else {
                LogService::error("Échec de création du tag", [
                    'name' => $tag->name,
                    'owner_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(500);
                return Response::error('Erreur lors de la création du tag');
            }
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la création du tag", [
                'error' => $e->getMessage(),
                'user_id' => $currentUserId
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la création du tag');
        }
    }
    
    /**
     * Mettre à jour un tag
     */
    public function update($id, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            $tag = new Tag();
            $tagData = $tag->findById($id);
            
            if (!$tagData) {
                LogService::info("Tag non trouvé pour modification", ['id' => $id]);
                LoggingMiddleware::logExit(404);
                return Response::error('Tag non trouvé', null, 404);
            }
            
            // Vérifier les permissions
            if (!$tag->canEdit($currentUserId) && $currentUserRole !== 'ADMINISTRATEUR') {
                LogService::warning("Tentative de modification non autorisée", [
                    'tag_id' => $id,
                    'user_id' => $currentUserId,
                    'tag_owner' => $tagData['tag_owner']
                ]);
                LoggingMiddleware::logExit(403);
                return Response::error('Accès non autorisé', null, 403);
            }
            
            $input = Response::getRequestParams();
            
            // Validation
            $validator = new Validator();
            $validation = $validator->validate($input, [
                'name' => 'string|min:1|max:100',
                'table_associate' => 'in:groups,memories,elements,files,all',
                'color' => 'string|regex:/^#[0-9A-Fa-f]{6}$/'
            ]);
            
            if (!$validation['valid']) {
                LogService::warning("Données de modification de tag invalides", [
                    'errors' => $validation['errors']
                ]);
                LoggingMiddleware::logExit(400);
                return Response::error('Données invalides', $validation['errors'], 400);
            }
            
            // Mettre à jour les propriétés modifiées
            if (isset($input['name'])) {
                $tag->name = trim($input['name']);
            }
            if (isset($input['table_associate'])) {
                $tag->table_associate = $input['table_associate'];
            }
            if (isset($input['color'])) {
                $tag->color = $input['color'];
            }
            
            // Valider les nouvelles données
            // todo inutile selon moi
            $errors = $tag->validate();
            if (!empty($errors)) {
                LogService::warning("Validation du tag modifié échouée", ['errors' => $errors]);
                LoggingMiddleware::logExit(400);
                return Response::error('Données invalides', $errors, 400);
            }
            
            if ($tag->update()) {
                $updatedTag = $tag->toArray();
                
                LogService::info("Tag modifié avec succès", [
                    'tag_id' => $id,
                    'modified_by' => $currentUserId
                ]);
                
                LoggingMiddleware::logExit(200);
                return Response::success('Tag modifié avec succès', $updatedTag);
            } else {
                LogService::error("Échec de modification du tag", [
                    'tag_id' => $id,
                    'user_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(500);
                return Response::error('Erreur lors de la modification du tag');
            }
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la modification du tag", [
                'tag_id' => $id,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la modification du tag');
        }
    }
    
    /**
     * Supprimer un tag (soft delete)
     */
    public function delete($id, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            $tag = new Tag();
            $tagData = $tag->findById($id);
            
            if (!$tagData) {
                LogService::info("Tag non trouvé pour suppression", ['id' => $id]);
                LoggingMiddleware::logExit(404);
                return Response::error('Tag non trouvé', null, 404);
            }
            
            // Vérifier les permissions
            if (!$tag->canEdit($currentUserId) && $currentUserRole !== 'ADMINISTRATEUR') {
                LogService::warning("Tentative de suppression non autorisée", [
                    'tag_id' => $id,
                    'user_id' => $currentUserId,
                    'tag_owner' => $tagData['tag_owner']
                ]);
                LoggingMiddleware::logExit(403);
                return Response::error('Accès non autorisé', null, 403);
            }
            
            // Vérifier l'utilisation du tag avant suppression
            $usageCount = $tag->getUsageCount();
            if ($usageCount > 0) {
                LogService::warning("Tentative de suppression d'un tag utilisé", [
                    'tag_id' => $id,
                    'usage_count' => $usageCount
                ]);
                LoggingMiddleware::logExit(409);
                return Response::error('Impossible de supprimer ce tag car il est encore utilisé', [
                    'usage_count' => $usageCount
                ], 409);
            }
            
            if ($tag->delete()) {
                LogService::info("Tag supprimé avec succès", [
                    'tag_id' => $id,
                    'deleted_by' => $currentUserId
                ]);
                
                LoggingMiddleware::logExit(200);
                return Response::success('Tag supprimé avec succès');
            } else {
                LogService::error("Échec de suppression du tag", [
                    'tag_id' => $id,
                    'user_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(500);
                return Response::error('Erreur lors de la suppression du tag');
            }
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la suppression du tag", [
                'tag_id' => $id,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la suppression du tag');
        }
    }
    
    /**
     * Obtenir les tags les plus utilisés
     */
    public function getMostUsed($currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            $params = Response::getRequestParams();
            $tableAssociate = $params['table_associate'] ?? 'memories';
            $limit = min((int)($params['limit'] ?? 10), 50); // Maximum 50
            
            // Valider la table associée
            $validTables = ['memories', 'elements', 'files', 'groups', 'all'];
            if (!in_array($tableAssociate, $validTables)) {
                LogService::warning("Table associée invalide pour getMostUsed", [
                    'table_associate' => $tableAssociate,
                    'valid_tables' => $validTables
                ]);
                LoggingMiddleware::logExit(400);
                return Response::error('Table associée invalide. Tables valides: ' . implode(', ', $validTables), null, 400);
            }
            
            $tag = new Tag();
            
            // Seuls les tags de l'utilisateur actuel (sauf admin)
            $tagOwner = ($currentUserRole === 'ADMINISTRATEUR') ? null : $currentUserId;
            $tags = $tag->getMostUsed($tableAssociate, $tagOwner, $limit);
            
            LogService::info("Tags les plus utilisés récupérés", [
                'table_associate' => $tableAssociate,
                'user_id' => $currentUserId,
                'tags_count' => count($tags)
            ]);
            
            $data = [
                'tags' => $tags,
                'table_associate' => $tableAssociate,
                'limit' => $limit
            ];
            
            LoggingMiddleware::logExit(200);
            return Response::success("Tags les plus utilisés", $data);
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des tags populaires", [
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la récupération des tags populaires');
        }
    }
    
    /**
     * Obtenir ou créer un tag
     */
    public function getOrCreate($currentUserId) {
        try {
            LoggingMiddleware::logEntry();
            
            $input = Response::getRequestParams();
            
            // Validation
            $validator = new Validator();
            $validation = $validator->validate($input, [
                'name' => 'required|string|min:1|max:100',
                'table_associate' => 'in:groups,memories,elements,files,all',
                'color' => 'string|regex:/^#[0-9A-Fa-f]{6}$/'
            ]);
            
            if (!$validation['valid']) {
                LogService::warning("Données invalides pour getOrCreate", [
                    'errors' => $validation['errors']
                ]);
                LoggingMiddleware::logExit(400);
                return Response::error('Données invalides', $validation['errors'], 400);
            }
            
            $tag = new Tag();
            $result = $tag->getOrCreate(
                trim($input['name']),
                $currentUserId,
                $input['table_associate'] ?? 'memories',
                $input['color'] ?? '#3498db'
            );
            
            if ($result) {
                LogService::info("Tag récupéré ou créé", [
                    'tag_name' => $input['name'],
                    'user_id' => $currentUserId,
                    'tag_id' => $result['id']
                ]);
                
                LoggingMiddleware::logExit(200);
                return Response::success('Tag récupéré ou créé', $result);
            } else {
                LogService::error("Échec de getOrCreate pour tag", [
                    'name' => $input['name'],
                    'user_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(500);
                return Response::error('Erreur lors de la récupération/création du tag');
            }
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de getOrCreate tag", [
                'error' => $e->getMessage(),
                'user_id' => $currentUserId
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la récupération/création du tag');
        }
    }
    
    /**
     * Restaurer un tag supprimé
     */
    public function restore($id, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            $tag = new Tag();
            
            // Chercher le tag même s'il est supprimé
            $tagData = $tag->findById($id, true); // withTrashed = true
            
            if (!$tagData) {
                LogService::info("Tag non trouvé pour restauration", ['id' => $id]);
                LoggingMiddleware::logExit(404);
                return Response::error('Tag non trouvé', null, 404);
            }
            
            if (empty($tagData['deleted_at'])) {
                LogService::info("Tag non supprimé, restauration inutile", ['id' => $id]);
                LoggingMiddleware::logExit(400);
                return Response::error('Ce tag n\'est pas supprimé', null, 400);
            }
            
            // Vérifier les permissions
            if ($tagData['tag_owner'] != $currentUserId && $currentUserRole !== 'ADMINISTRATEUR') {
                LogService::warning("Tentative de restauration non autorisée", [
                    'tag_id' => $id,
                    'user_id' => $currentUserId,
                    'tag_owner' => $tagData['tag_owner']
                ]);
                LoggingMiddleware::logExit(403);
                return Response::error('Accès non autorisé', null, 403);
            }
            
            if ($tag->restore()) {
                LogService::info("Tag restauré avec succès", [
                    'tag_id' => $id,
                    'restored_by' => $currentUserId
                ]);
                
                LoggingMiddleware::logExit(200);
                return Response::success('Tag restauré avec succès');
            } else {
                LogService::error("Échec de restauration du tag", [
                    'tag_id' => $id,
                    'user_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(500);
                return Response::error('Erreur lors de la restauration du tag');
            }
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la restauration du tag", [
                'tag_id' => $id,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la restauration du tag');
        }
    }
    
    /**
     * Associer ou dissocier un tag à/d'un élément
     */
    public function associateOrDissociate($tagId, $itemId, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            // Récupérer les données de la requête
            $input = Response::getRequestParams();
            
            // Validation
            $validator = new Validator();
            $validation = $validator->validate($input, [
                'table_associate' => 'required|in:groups,memories,elements,files'
            ]);
            
            if (!$validation['valid']) {
                LogService::warning("Données invalides pour association de tag", [
                    'errors' => $validation['errors'],
                    'tag_id' => $tagId,
                    'item_id' => $itemId
                ]);
                LoggingMiddleware::logExit(400);
                return Response::error('Données invalides', $validation['errors'], 400);
            }
            
            $tableAssociate = $input['table_associate'];
            
            // Récupérer le tag
            $tag = new Tag();
            $tagData = $tag->findById($tagId);
            
            if (!$tagData) {
                LogService::info("Tag non trouvé pour association", ['id' => $tagId]);
                LoggingMiddleware::logExit(404);
                return Response::error('Tag non trouvé', null, 404);
            }
            
            // Vérifier les permissions sur le tag
            if (!$tag->canEdit($currentUserId) && $currentUserRole !== 'ADMINISTRATEUR') {
                LogService::warning("Tentative d'association de tag non autorisée", [
                    'tag_id' => $tagId,
                    'item_id' => $itemId,
                    'user_id' => $currentUserId,
                    'tag_owner' => $tagData['tag_owner']
                ]);
                LoggingMiddleware::logExit(403);
                return Response::error('Accès non autorisé', null, 403);
            }
            
            // Vérifier que le tag peut être utilisé avec cette table
            // Si le tag est spécifique à une table, il doit correspondre
            // Si le tag est pour 'all', il peut être utilisé avec n'importe quelle table
            if ($tagData['table_associate'] !== 'all' && $tagData['table_associate'] !== $tableAssociate) {
                LogService::warning("Tag incompatible avec la table demandée", [
                    'tag_id' => $tagId,
                    'tag_table_associate' => $tagData['table_associate'],
                    'requested_table' => $tableAssociate
                ]);
                LoggingMiddleware::logExit(400);
                return Response::error('Ce tag ne peut pas être utilisé avec cette table', null, 400);
            }
            
            // TODO: Vérifier les permissions sur l'élément cible
            // Cette vérification devrait être faite selon le type d'élément
            // Pour l'instant, on assume que l'utilisateur a accès à l'élément
            
            // Vérifier si le tag est déjà associé
            $isAssociated = $tag->isAssociatedToItem($itemId, $tableAssociate);
            $action = '';
            
            if ($isAssociated) {
                // Dissocier le tag
                if ($tag->dissociateFromItem($itemId, $tableAssociate)) {
                    $action = 'dissocié';
                    LogService::info("Tag dissocié avec succès", [
                        'tag_id' => $tagId,
                        'item_id' => $itemId,
                        'table' => $tableAssociate,
                        'user_id' => $currentUserId
                    ]);
                } else {
                    LogService::error("Échec de dissociation du tag", [
                        'tag_id' => $tagId,
                        'item_id' => $itemId,
                        'table' => $tableAssociate
                    ]);
                    LoggingMiddleware::logExit(500);
                    return Response::error('Erreur lors de la dissociation du tag');
                }
            } else {
                // Associer le tag
                if ($tag->associateToItem($itemId, $tableAssociate)) {
                    $action = 'associé';
                    LogService::info("Tag associé avec succès", [
                        'tag_id' => $tagId,
                        'item_id' => $itemId,
                        'table' => $tableAssociate,
                        'user_id' => $currentUserId
                    ]);
                } else {
                    LogService::error("Échec d'association du tag", [
                        'tag_id' => $tagId,
                        'item_id' => $itemId,
                        'table' => $tableAssociate
                    ]);
                    LoggingMiddleware::logExit(500);
                    return Response::error('Erreur lors de l\'association du tag');
                }
            }
            
            $data = [
                'tag_id' => (int)$tagId,
                'item_id' => (int)$itemId,
                'action' => $action
            ];
            
            LoggingMiddleware::logExit(200);
            return Response::success("Tag {$action} avec succès", $data);
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de l'association/dissociation de tag", [
                'tag_id' => $tagId,
                'item_id' => $itemId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de l\'association/dissociation du tag');
        }
    }
}