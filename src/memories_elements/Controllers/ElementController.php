<?php

namespace Memories\Controllers;

use Memories\Models\Element;
use Memories\Utils\Response;
use Memories\Utils\Validator;
use Memories\Services\LogService;
use Memories\Middleware\LoggingMiddleware;
use Exception;

/**
 * Contrôleur Element simplifié utilisant ElementSimplified
 * Version simplifiée sans injection de dépendance PDO
 */
class ElementController {
    
    public function __construct() {
        // Plus besoin d'injection PDO !
    }
    
    /**
     * Obtenir tous les éléments d'un utilisateur
     */
    public function getUserElements($userId, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            // Vérifier les permissions (admin ou utilisateur lui-même)
            if ($currentUserRole !== 'ADMINISTRATEUR' && $currentUserId != $userId) {
                LogService::warning("Tentative d'accès non autorisé aux éléments", [
                    'requested_user_id' => $userId,
                    'current_user_id' => $currentUserId,
                    'role' => $currentUserRole
                ]);
                LoggingMiddleware::logExit(403);
                return Response::error('Accès non autorisé', null, 403);
            }
            
            $pagination = Response::getPaginationParams();
            
            $element = new Element(); // Instantiation simplifiée !
            $elements = $element->getAccessibleElements($userId, $pagination['limit'], ($pagination['page'] - 1) * $pagination['limit']);
            
            // Compter le total
            $total = $element->countByOwner($userId);
            
            LogService::info("Éléments utilisateur récupérés", [
                'user_id' => $userId,
                'elements_count' => count($elements),
                'total' => $total,
                'page' => $pagination['page']
            ]);
            
            LoggingMiddleware::logExit(200);
            return Response::success('éléments récupérés avec succès',[
                'elements' => $elements,
                'pagination' => [
                    'total' => $total,
                    'page' => $pagination['page'],
                    'limit' => $pagination['limit'],
                    'total_pages' => ceil($total / $pagination['limit'])
                ]
            ]);
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des éléments utilisateur", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la récupération des éléments');
        }
    }
    
    /**
     * Obtenir les éléments publics
     */
    public function getPublicElements() {
        try {
            LoggingMiddleware::logEntry();
            
            $pagination = Response::getPaginationParams();
            
            $element = new Element();
            $elements = $element->getPublicElements($pagination['limit'], ($pagination['page'] - 1) * $pagination['limit']);
            
            LogService::info("Éléments publics récupérés", [
                'elements_count' => count($elements),
                'page' => $pagination['page']
            ]);
            $data = [
                'elements' => $elements,
                'pagination' => [
                    'page' => $pagination['page'],
                    'limit' => $pagination['limit']
                ]
            ];
            LoggingMiddleware::logExit(200);
            return Response::success('Éléments publics récupérés', $data);
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des éléments publics", [
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la récupération des éléments publics');
        }
    }
    
    /**
     * Obtenir un élément par ID
     */
    public function getById($id, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            $element = new Element();
            $elementData = $element->findById($id);
            
            if (!$elementData) {
                LogService::info("Élément non trouvé", ['id' => $id]);
                LoggingMiddleware::logExit(404);
                return Response::error('Élément non trouvé', null, 404);
            }
            
            // Vérifier les permissions d'accès
            if (!$element->canAccess($id, $currentUserId) && $currentUserRole !== 'ADMINISTRATEUR') {
                LogService::warning("Tentative d'accès non autorisé à l'élément", [
                    'element_id' => $id,
                    'user_id' => $currentUserId,
                    'visibility' => $elementData['visibility']
                ]);
                LoggingMiddleware::logExit(403);
                return Response::error('Accès non autorisé', null, 403);
            }
            
            LogService::info("Données d'élément récupérées", [
                'element_id' => $id,
                'accessed_by' => $currentUserId,
                'owner_id' => $elementData['owner_id']
            ]);
            
            LoggingMiddleware::logExit(200);
            return Response::success('Élément récupéré', $elementData);
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération de l'élément", [
                'element_id' => $id,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la récupération de l\'élément');
        }
    }
    
    /**
     * Créer un nouvel élément
     */
    public function create($currentUserId) {
        try {
            LoggingMiddleware::logEntry();
            
            $input = Response::getRequestParams();
            
            // Validation
            $validator = new Validator();
            $validation = $validator->validate($input, [
                'title' => 'required|string|min:2|max:255',
                'content' => 'string|max:10000',
                'media_type' => 'in:text,image,audio,video,document,gpx,ical',
                'visibility' => 'in:private,shared,public',
                'filename' => 'string|max:255'
            ]);
            
            if (!$validation['valid']) {
                LogService::warning("Données de création d'élément invalides", [
                    'errors' => $validation['errors']
                ]);
                LoggingMiddleware::logExit(400);
                return Response::error('Données invalides', $validation['errors'], 400);
            }
            /* if( ($input['media_type'] ?? 'text') !== 'text' && empty($input['filename'])) {
                LogService::warning("Données de création d'élément invalides - filename requis pour media_type non text", [
                    'media_type' => $input['media_type'] ?? 'text'
                ]);
                LoggingMiddleware::logExit(400);
                return Response::error('Données invalides: filename est requis pour les media_type autres que text', null, 400);
            }
 */
            $element = new Element();
            
            // Préparer les données
            $element->title = $input['title'];
            $element->content = $input['content'] ?? '';
            $element->media_type = $input['media_type'] ?? 'text';
            $element->visibility = $input['visibility'] ?? 'private';
            $element->filename = $input['filename'] ?? null;
            $element->owner_id = $currentUserId;
            
            if ($element->create()) {
                LogService::info("Nouvel élément créé", [
                    'element_id' => $element->id,
                    'title' => $element->title,
                    'media_type' => $element->media_type,
                    'owner_id' => $currentUserId
                ]);
                
                // Préparer la réponse
                $responseData = [
                    'id' => $element->id,
                    'title' => $element->title,
                    'content' => $element->content,
                    'media_type' => $element->media_type,
                    'visibility' => $element->visibility,
                    'filename' => $element->filename,
                    'owner_id' => $element->owner_id
                ];
                
                LoggingMiddleware::logExit(201);
                return Response::success('Élément créé avec succès', $responseData, 201);
            } else {
                LogService::error("Échec de la création de l'élément", [
                    'title' => $element->title,
                    'owner_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(500);
                return Response::error('Erreur lors de la création de l\'élément');
            }
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la création de l'élément", [
                'error' => $e->getMessage(),
                'owner_id' => $currentUserId
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la création de l\'élément');
        }
    }
    
    /**
     * Mettre à jour un élément
     */
    public function update($id, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            $element = new Element();
            $elementData = $element->findById($id);
            
            if (!$elementData) {
                LogService::info("Élément à modifier non trouvé", ['id' => $id]);
                LoggingMiddleware::logExit(404);
                return Response::error('Élément non trouvé', null, 404);
            }
            
            // Vérifier les permissions (propriétaire ou admin)
            if ($elementData['owner_id'] != $currentUserId && $currentUserRole !== 'ADMINISTRATEUR') {
                LogService::warning("Tentative de modification non autorisée", [
                    'element_id' => $id,
                    'owner_id' => $elementData['owner_id'],
                    'current_user_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(403);
                return Response::error('Accès non autorisé', null, 403);
            }
            
            $input = Response::getRequestParams();
            
            // Validation
            $validator = new Validator();
            $validation = $validator->validate($input, [
                'title' => 'string|min:2|max:255',
                'content' => 'string|max:10000',
                'media_type' => 'in:text,image,audio,video,document,gpx,ical',
                'visibility' => 'in:private,shared,public',
                'filename' => 'string|max:255'
            ]);
            
            if (!$validation['valid']) {
                LogService::warning("Données de mise à jour d'élément invalides", [
                    'element_id' => $id,
                    'errors' => $validation['errors']
                ]);
                LoggingMiddleware::logExit(400);
                return Response::error('Données invalides', $validation['errors'], 400);
            }
            /* if( isset($input['media_type']) && $input['media_type'] !== 'text' 
            && empty($input['filename']) && (empty($elementData['filename']) 
            || (isset($input['filename']) && $input['filename'] === '')) ) {
                LogService::warning("Données de mise à jour d'élément invalides - filename requis pour media_type non text", [
                    'element_id' => $id,
                    'media_type' => $input['media_type']
                ]);
                LoggingMiddleware::logExit(400);
                return Response::error('Données invalides: filename est requis pour les media_type autres que text', null, 400);
            } */
            // Mettre à jour les propriétés
            $element->id = $id;
            $element->title = $input['title'] ?? $elementData['title'];
            $element->content = $input['content'] ?? $elementData['content'];
            $element->media_type = $input['media_type'] ?? $elementData['media_type'];
            $element->visibility = $input['visibility'] ?? $elementData['visibility'];
            $element->filename = $input['filename'] ?? $elementData['filename'];
            
            if ($element->update()) {
                LogService::info("Élément mis à jour", [
                    'element_id' => $id,
                    'updated_by' => $currentUserId,
                    'fields_updated' => array_keys($input)
                ]);
                
                // Récupérer les données mises à jour
                $updatedData = $element->findById($id);
                
                LoggingMiddleware::logExit(200);
                return Response::success('Élément mis à jour avec succès', $updatedData);
            } else {
                LogService::error("Échec de la mise à jour de l'élément", [
                    'element_id' => $id
                ]);
                LoggingMiddleware::logExit(500);
                return Response::error('Erreur lors de la mise à jour de l\'élément');
            }
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la mise à jour de l'élément", [
                'element_id' => $id,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la mise à jour de l\'élément');
        }
    }
    
    /**
     * Supprimer un élément (soft delete)
     */
    public function delete($id, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            $element = new Element();
            $elementData = $element->findById($id);
            
            if (!$elementData) {
                LogService::info("Élément à supprimer non trouvé", ['id' => $id]);
                LoggingMiddleware::logExit(404);
                return Response::error('Élément non trouvé', null, 404);
            }
            
            // Définir l'ID pour le soft delete
            $element->id = $id;
            
            // Vérifier les permissions (propriétaire ou admin)
            if ($elementData['owner_id'] != $currentUserId && $currentUserRole !== 'ADMINISTRATEUR') {
                LogService::warning("Tentative de suppression non autorisée", [
                    'element_id' => $id,
                    'owner_id' => $elementData['owner_id'],
                    'current_user_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(403);
                return Response::error('Accès non autorisé', null, 403);
            }
            
            if ($element->softDelete()) {
                LogService::info("Élément supprimé (soft delete)", [
                    'element_id' => $id,
                    'deleted_by' => $currentUserId,
                    'element_title' => $elementData['title']
                ]);
                
                LoggingMiddleware::logExit(200);
                return Response::success('Élément supprimé avec succès');
            } else {
                LogService::error("Échec de la suppression de l'élément", [
                    'element_id' => $id
                ]);
                LoggingMiddleware::logExit(500);
                return Response::error('Erreur lors de la suppression de l\'élément');
            }
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la suppression de l'élément", [
                'element_id' => $id,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la suppression de l\'élément');
        }
    }
    
    /**
     * Obtenir les éléments par type de média
     */
    public function getByMediaType($mediaType, $currentUserId) {
        try {
            LoggingMiddleware::logEntry();
            
            // Validation du type de média
            $validTypes = ['text', 'image', 'audio', 'video', 'document', 'gpx', 'ical'];
            if (!in_array($mediaType, $validTypes)) {
                LoggingMiddleware::logExit(400);
                return Response::error('Type de média invalide', null, 400);
            }
            
            $pagination = Response::getPaginationParams();
            
            $element = new Element();
            $elements = $element->getByMediaType($mediaType, $currentUserId, $pagination['limit'], ($pagination['page'] - 1) * $pagination['limit']);
            
            LogService::info("Éléments par type de média récupérés", [
                'media_type' => $mediaType,
                'user_id' => $currentUserId,
                'elements_count' => count($elements)
            ]);
            
            LoggingMiddleware::logExit(200);
            return Response::success($elements, [
                'media_type' => $mediaType,
                'page' => $pagination['page'],
                'limit' => $pagination['limit']
            ]);
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des éléments par type", [
                'media_type' => $mediaType,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la récupération des éléments');
        }
    }
    
    /**
     * Associer un élément à une mémoire
     */
    public function associateWithMemory($elementId, $memoryId, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            $element = new Element();
            
            // Vérifier que l'élément existe et que l'utilisateur y a accès
            if (!$element->canAccess($elementId, $currentUserId) && $currentUserRole !== 'ADMINISTRATEUR') {
                LogService::warning("Tentative d'association d'élément non autorisée", [
                    'element_id' => $elementId,
                    'memory_id' => $memoryId,
                    'user_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(403);
                return Response::error('Accès non autorisé à cet élément', null, 403);
            }
            
            $input = Response::getRequestParams();
            $position = $input['position'] ?? null;
            
            if ($element->associateWithMemory($elementId, $memoryId, $position)) {
                LogService::info("Élément associé à la mémoire", [
                    'element_id' => $elementId,
                    'memory_id' => $memoryId,
                    'position' => $position,
                    'associated_by' => $currentUserId
                ]);
                
                LoggingMiddleware::logExit(200);
                return Response::success(['message' => 'Élément associé à la mémoire avec succès']);
            } else {
                LogService::warning("Échec de l'association élément-mémoire", [
                    'element_id' => $elementId,
                    'memory_id' => $memoryId
                ]);
                LoggingMiddleware::logExit(400);
                return Response::error('Association déjà existante ou erreur lors de l\'association', null, 400);
            }
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de l'association élément-mémoire", [
                'element_id' => $elementId,
                'memory_id' => $memoryId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de l\'association');
        }
    }
    
    /**
     * Dissocier un élément d'une mémoire
     */
    public function dissociateFromMemory($elementId, $memoryId, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            $element = new Element();
            
            // Vérifier que l'élément existe et que l'utilisateur y a accès
            if (!$element->canAccess($elementId, $currentUserId) && $currentUserRole !== 'ADMINISTRATEUR') {
                LogService::warning("Tentative de dissociation d'élément non autorisée", [
                    'element_id' => $elementId,
                    'memory_id' => $memoryId,
                    'user_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(403);
                return Response::error('Accès non autorisé à cet élément', null, 403);
            }
            
            if ($element->dissociateFromMemory($elementId, $memoryId)) {
                LogService::info("Élément dissocié de la mémoire", [
                    'element_id' => $elementId,
                    'memory_id' => $memoryId,
                    'dissociated_by' => $currentUserId
                ]);
                
                LoggingMiddleware::logExit(200);
                return Response::success(['message' => 'Élément dissocié de la mémoire avec succès']);
            } else {
                LogService::error("Échec de la dissociation élément-mémoire", [
                    'element_id' => $elementId,
                    'memory_id' => $memoryId
                ]);
                LoggingMiddleware::logExit(500);
                return Response::error('Erreur lors de la dissociation');
            }
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la dissociation élément-mémoire", [
                'element_id' => $elementId,
                'memory_id' => $memoryId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la dissociation');
        }
    }
    
    /**
     * Obtenir les éléments d'une mémoire
     */
    public function getByMemoryId($memoryId, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            $pagination = Response::getPaginationParams();
            
            $element = new Element();
            $elements = $element->getByMemoryId($memoryId, $pagination['limit'], ($pagination['page'] - 1) * $pagination['limit']);
            
            LogService::info("Éléments de mémoire récupérés", [
                'memory_id' => $memoryId,
                'elements_count' => count($elements),
                'accessed_by' => $currentUserId
            ]);
            
            LoggingMiddleware::logExit(200);
            return Response::success($elements, [
                'memory_id' => $memoryId,
                'page' => $pagination['page'],
                'limit' => $pagination['limit']
            ]);
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des éléments de mémoire", [
                'memory_id' => $memoryId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la récupération des éléments');
        }
    }
    
    /**
     * Rechercher des éléments
     */
    public function search($currentUserId) {
        try {
            LoggingMiddleware::logEntry();
            
            $params = Response::getRequestParams();
            $searchTerm = $params['q'] ?? '';
            $limit = min((int)($params['limit'] ?? 20), 100);
            
            if (empty($searchTerm)) {
                LoggingMiddleware::logExit(400);
                return Response::error('Terme de recherche requis', null, 400);
            }
            
            $element = new Element();
            $results = $element->search($searchTerm, $currentUserId, $limit);
            
            LogService::info("Recherche d'éléments effectuée", [
                'search_term' => $searchTerm,
                'user_id' => $currentUserId,
                'results_count' => count($results)
            ]);
            
            LoggingMiddleware::logExit(200);
            return Response::success('Résultats de recherche', $results);
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la recherche d'éléments", [
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur serveur lors de la recherche');
        }
    }

    /**
     * Récupérer tous les éléments (pour le gestionnaire de routes)
     * Liste tous les éléments avec filtrage optionnel
     */
    public function getAll(int $userId, string $role) {
        try {
            LoggingMiddleware::logEntry();
            
            $params = Response::getRequestParams();
            $pagination = Response::getPaginationParams();
            
            // Paramètres de filtrage
            $mediaType = $params['media_type'] ?? null;
            $searchTerm = $params['q'] ?? null;
            
            $element = new Element();
            
            // Si un type de média est spécifié, utiliser getByMediaType
            if ($mediaType) {
                $validTypes = ['text', 'image', 'audio', 'video', 'document', 'gpx', 'ical'];
                if (!in_array($mediaType, $validTypes)) {
                    LoggingMiddleware::logExit(400);
                    return Response::error('Type de média invalide', null, 400);
                }
                
                $elements = $element->getByMediaType($mediaType, $userId, $pagination['limit'], ($pagination['page'] - 1) * $pagination['limit']);
                $total = $element->countByMediaType($mediaType, $userId);
                
                LogService::info('Éléments récupérés par type de média', [
                    'user_id' => $userId,
                    'media_type' => $mediaType,
                    'count' => count($elements)
                ]);
                
            } elseif ($searchTerm) {
                // Si un terme de recherche est fourni, utiliser search
                $elements = $element->search($searchTerm, $userId, $pagination['limit']);
                $total = count($elements); // La méthode search ne retourne pas de count séparé
                
                LogService::info('Éléments récupérés par recherche', [
                    'user_id' => $userId,
                    'search_term' => $searchTerm,
                    'count' => count($elements)
                ]);
                
            } else {
                // Récupérer tous les éléments de l'utilisateur
                $elements = $element->getByOwnerId($userId, $pagination['limit'], ($pagination['page'] - 1) * $pagination['limit']);
                $total = $element->countByOwner($userId);
                
                LogService::info('Tous les éléments utilisateur récupérés', [
                    'user_id' => $userId,
                    'count' => count($elements),
                    'total' => $total
                ]);
            }
            
            $data = [
                'elements' => $elements,
                'pagination' => [
                    'page' => $pagination['page'],
                    'limit' => $pagination['limit'],
                    'total' => $total,
                    'total_pages' => ceil($total / $pagination['limit'])
                ]
            ];
            
            if ($mediaType) {
                $data['media_type'] = $mediaType;
            }
            if ($searchTerm) {
                $data['search_term'] = $searchTerm;
            }
            
            LoggingMiddleware::logExit(200);
            return Response::success('Liste des éléments récupérée', $data);
            
        } catch (Exception $e) {
            LogService::error('Erreur récupération éléments', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            return Response::error('Erreur lors de la récupération des éléments', null, 500);
        }
    }
}
