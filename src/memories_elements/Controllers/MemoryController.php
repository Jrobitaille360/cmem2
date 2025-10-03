<?php

namespace Memories\Controllers;

use Memories\Models\Memory;
use Memories\Services\LogService;
use Memories\Utils\Response;
use Memories\Utils\Validator;
use \Memories\Models\Element;
use Exception;
use Memories\Models\File;

/**
 * MemoryControllerSimplified - API v2
 * Contrôleur simplifié pour la gestion des mémoires
 * Architecture sans injection PDO, utilise Database::getInstance()
 */
class MemoryController {
    
    private $model;
    
    public function __construct() {
        // PLUS D'INJECTION PDO ! Auto-instantiation
        $this->model = new Memory();
    }
    
    /**
     * Récupérer toutes les mémoires avec filtres et pagination
     */
    public function getAll($userId, $userRole) {
        try {
            // Récupérer les paramètres selon la méthode HTTP
            $params = Response::getRequestParams();
            $pagination = Response::getPaginationParams();
            
            $filters = [
                'user_id' => $params['user_id'] ?? null,
                'is_public' => $params['is_public'] ?? null,
                'search' => $params['search'] ?? '',
                'order_by' => $params['order_by'] ?? 'created_at',
                'order_dir' => strtoupper($params['order_dir'] ?? 'DESC'),
                'limit' => $pagination['limit'],
                'offset' => ($pagination['page'] - 1) * $pagination['limit']
            ];
            
            // Si pas admin, filtrer selon les règles d'accès
            if ($userRole !== 'admin') {
                if (!$filters['user_id']) {
                    // Aucun user_id spécifié : retourner les mémoires publiques + privées de l'utilisateur
                    // On ne filtre pas ici, on laisse le modèle gérer les permissions
                    $filters['current_user_id'] = $userId; // Passer l'ID utilisateur pour le filtrage des permissions
                } elseif ($filters['user_id'] != $userId) {
                    // user_id spécifié différent de l'utilisateur courant : seulement les publiques
                    $filters['visibility'] = 'public';
                }
                // Si user_id == userId, on garde tel quel (mémoires de l'utilisateur)
            }
            
            $memories = $this->model->getAll($filters);
            $total = $this->model->count($filters);
            
            LogService::info('Récupération mémoires v2', [
                'user_id' => $userId,
                'role' => $userRole,
                'filters' => $filters,
                'total' => $total
            ]);
            
            Response::success('Récupération réussie',[
                'memories' => $memories,
                'pagination' => [
                    'page' => $pagination['page'],
                    'limit' => $pagination['limit'],
                    'total' => $total,
                    'pages' => ceil($total / $pagination['limit'])
                ]
            ]);
            
        } catch (Exception $e) {
            LogService::error('Erreur récupération mémoires v2', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            Response::error('Erreur lors de la récupération des mémoires');
        }
    }
    
    /**
     * Récupérer une mémoire par ID
     */
    public function getById($memoryId, $userId, $userRole) {
        try {
            $memory = $this->model->findById($memoryId);
            
            if (!$memory) {
                Response::error('Mémoire non trouvée', null, 404);
                return;
            }
            
            // Vérifier les permissions
            if (!$this->checkPermission($memory, $userId, $userRole, 'read')) {
                Response::error('Accès non autorisé à cette mémoire', null, 403);
                return;
            }
            
            // Récupérer les éléments associés
            $memory['elements'] = $this->model->getElements($memoryId);
            
            LogService::info('Récupération mémoire v2', [
                'memory_id' => $memoryId,
                'user_id' => $userId,
                'elements_count' => count($memory['elements'])
            ]);
            
            Response::success('memory récupérée avec succès', $memory);
            
        } catch (Exception $e) {
            LogService::error('Erreur récupération mémoire v2', [
                'error' => $e->getMessage(),
                'memory_id' => $memoryId,
                'user_id' => $userId
            ]);
            Response::error('Erreur lors de la récupération de la mémoire');
        }
    }
    
    /**
     * Créer une nouvelle mémoire
     */
    public function create($userId) {
        try {
            // Récupérer les données JSON
            $input = Response::getRequestParams();
            
            if (!$input) {
                Response::error('Données JSON invalides', null, 400);
                return;
            }
            
            // Validation des données
            $validator = new Validator();
            $validation = $validator->validate($input, [
                'title' => 'required|string|min:3|max:255',
                'content' => 'string|max:5000',
                'visibility' => 'string|in:private,shared,public',
                'location' => 'string|max:255',
                'latitude' => 'numeric',
                'longitude' => 'numeric',
                'date' => 'date',
                'tags' => 'array'
            ]);
            
            if (!$validation['valid']) {
                Response::error('Données invalides', $validation['errors'], 400);
                return;
            }
            
            // Préparer les données selon le schéma de la base
            $data = [
                'title' => trim($input['title']),
                'content' => trim($input['content'] ?? ''),
                'user_id' => $userId,
                'visibility' => $input['visibility'] ?? 'private',
                'location' => $input['location'] ?? null,
                'latitude' => $input['latitude'] ?? null,
                'longitude' => $input['longitude'] ?? null,
                'time_start' => $input['date'] ?? null,
                'time_end' => $input['date'] ?? null
            ];
            
            $memoryId = $this->model->createWithData($data);
            
            if (!$memoryId) {
                Response::error('Erreur lors de la création de la mémoire');
                return;
            }
            
            $memory = $this->model->findById($memoryId);
            
            LogService::info('Création mémoire v2', [
                'memory_id' => $memoryId,
                'user_id' => $userId,
                'title' => $data['title'],
                'visibility' => $data['visibility']
            ]);
            
            Response::success('Mémoire créée avec succès', $memory, 201);
            
        } catch (Exception $e) {
            LogService::error('Erreur création mémoire v2', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            Response::error('Erreur lors de la création de la mémoire');
        }
    }
    
    /**
     * Mettre à jour une mémoire
     */
    public function update($memoryId, $userId, $userRole) {
        try {
            $memory = $this->model->findById($memoryId);
            
            if (!$memory) {
                Response::error('Mémoire non trouvée', null, 404);
                return;
            }
            
            // Vérifier les permissions
            if (!$this->checkPermission($memory, $userId, $userRole, 'write')) {
                Response::error('Accès non autorisé pour modifier cette mémoire', null, 403);
                return;
            }
            
            // Récupérer les données JSON
            $input = Response::getRequestParams();
            
            if (!$input) {
                Response::error('Données JSON invalides', null, 400);
                return;
            }
            
            // Validation des données
            $validator = new Validator();
            $validation = $validator->validate($input, [
                'title' => 'string|max:255',
                'content' => 'string|max:5000',
                'visibility' => 'string|in:private,shared,public',
                'location' => 'string|max:255',
                'latitude' => 'numeric',
                'longitude' => 'numeric',
                'date' => 'date'
            ]);
            
            if (!$validation['valid']) {
                Response::error('Données invalides', $validation['errors'], 400);
                return;
            }
            
            // Préparer les données à mettre à jour
            $data = [];
            if (isset($input['title'])) $data['title'] = trim($input['title']);
            if (isset($input['content'])) $data['content'] = trim($input['content']);
            if (isset($input['visibility'])) $data['visibility'] = $input['visibility'];
            if (isset($input['location'])) $data['location'] = $input['location'];
            if (isset($input['latitude'])) $data['latitude'] = $input['latitude'];
            if (isset($input['longitude'])) $data['longitude'] = $input['longitude'];
            if (isset($input['date'])) {
                $data['time_start'] = $input['date'];
                $data['time_end'] = $input['date'];
            }
            
            if (empty($data)) {
                Response::error('Aucune donnée à mettre à jour', null, 400);
                return;
            }
            
            $success = $this->model->updateWithData($memoryId, $data);
            
            if (!$success) {
                Response::error('Erreur lors de la mise à jour de la mémoire');
                return;
            }
            
            $updatedMemory = $this->model->findById($memoryId);
            
            LogService::info('Mise à jour mémoire v2', [
                'memory_id' => $memoryId,
                'user_id' => $userId,
                'changes' => array_keys($data)
            ]);
            
            Response::success($updatedMemory, 'Mémoire mise à jour avec succès');
            
        } catch (Exception $e) {
            LogService::error('Erreur mise à jour mémoire v2', [
                'error' => $e->getMessage(),
                'memory_id' => $memoryId,
                'user_id' => $userId
            ]);
            Response::error('Erreur lors de la mise à jour de la mémoire');
        }
    }
    
    /**
     * Supprimer une mémoire (soft delete)
     */
    public function delete($memoryId, $userId, $userRole) {
        try {
            $memory = $this->model->findById($memoryId);
            
            if (!$memory) {
                Response::error('Mémoire non trouvée', null, 404);
                return;
            }
            
            // Vérifier les permissions
            if (!$this->checkPermission($memory, $userId, $userRole, 'delete')) {
                Response::error('Accès non autorisé pour supprimer cette mémoire', null, 403);
                return;
            }
            
            $success = $this->model->deleteById($memoryId);
            
            if (!$success) {
                Response::error('Erreur lors de la suppression de la mémoire');
                return;
            }
            
            LogService::info('Suppression mémoire v2', [
                'memory_id' => $memoryId,
                'user_id' => $userId,
                'title' => $memory['title']
            ]);
            
            Response::success(null, 'Mémoire supprimée avec succès');
            
        } catch (Exception $e) {
            LogService::error('Erreur suppression mémoire v2', [
                'error' => $e->getMessage(),
                'memory_id' => $memoryId,
                'user_id' => $userId
            ]);
            Response::error('Erreur lors de la suppression de la mémoire');
        }
    }
    
    /**
     * Associer un élément à une mémoire
     */
    public function associateElement($memoryId, $elementId, $userId, $userRole) {
        try {
            $memory = $this->model->findById($memoryId);
            
            if (!$memory) {
                Response::error('Mémoire non trouvée', null, 404);
                return;
            }
            
            // Vérifier les permissions
            if (!$this->checkPermission($memory, $userId, $userRole, 'write')) {
                Response::error('Accès non autorisé pour modifier cette mémoire', null, 403);
                return;
            }
            
            // Vérifier que l'élément existe
            $element = new Element();
            $element = $element->findById($elementId);
            
            if (!$element) {
                Response::error('Élément non trouvé', null, 404);
                return;
            }
            
            $success = $this->model->associateElement($memoryId, $elementId);
            
            if (!$success) {
                Response::error('Erreur lors de l\'association (peut-être déjà associé)');
                return;
            }
            
            LogService::info('Association élément-mémoire v2', [
                'memory_id' => $memoryId,
                'element_id' => $elementId,
                'user_id' => $userId
            ]);
            
            Response::success('Élément associé à la mémoire avec succès', [
                'memory_id' => $memoryId,
                'element_id' => $elementId,
                'association_date' => date('Y-m-d H:i:s')
            ]);
            
        } catch (Exception $e) {
            LogService::error('Erreur association élément v2', [
                'error' => $e->getMessage(),
                'memory_id' => $memoryId,
                'element_id' => $elementId,
                'user_id' => $userId
            ]);
            Response::error('Erreur lors de l\'association');
        }
    }
    
    /**
     * Dissocier un élément d'une mémoire
     */
    public function dissociateElement($memoryId, $elementId, $userId, $userRole) {
        try {
            $memory = $this->model->findById($memoryId);
            
            if (!$memory) {
                Response::error('Mémoire non trouvée', null, 404);
                return;
            }
            
            // Vérifier les permissions
            if (!$this->checkPermission($memory, $userId, $userRole, 'write')) {
                Response::error('Accès non autorisé pour modifier cette mémoire', null, 403);
                return;
            }
            
            // Vérifier que l'élément existe
            $element = new Element();
            $element = $element->findById($elementId);
            
            if (!$element) {
                Response::error('Élément non trouvé', null, 404);
                return;
            }
            
            $success = $this->model->dissociateElement($memoryId, $elementId);
            
            if (!$success) {
                Response::error('Erreur lors de la dissociation (peut-être pas associé)');
                return;
            }
            
            LogService::info('Dissociation élément-mémoire v2', [
                'memory_id' => $memoryId,
                'element_id' => $elementId,
                'user_id' => $userId
            ]);
            
            Response::success(null, 'Élément dissocié de la mémoire avec succès');
            
        } catch (Exception $e) {
            LogService::error('Erreur dissociation élément v2', [
                'error' => $e->getMessage(),
                'memory_id' => $memoryId,
                'element_id' => $elementId,
                'user_id' => $userId
            ]);
            Response::error('Erreur lors de la dissociation');
        }
    }
    
    /**
     * Récupérer les mémoires publiques (endpoint public)
     */
    public function getPublicMemories() {
        try {
            $input = Response::getRequestParams();

            $validator = new Validator();
            $validation = $validator->validate($input, [
                'q' => 'string|min:2|max:255',
                'page' => 'integer|min:1',
                'limit' => 'integer|min:1|max:100',
                'order_by' => 'string|in:created_at,title',
                'order_dir' => 'string|in:ASC,DESC'
            ]);
            if (!$validation['valid']) {
                Response::error('Paramètres invalides', $validation['errors'], 400);
                return;
            }
            $page = (int)($input['page'] ?? 1);
            $limit = min((int)($input['limit'] ?? 20), 100);
            $offset = ($page - 1) * $limit;
            
            $filters = [
                'visibility' => 'public',
                'search' => $input['q'] ?? '',
                'order_by' => $input['order_by'] ?? 'created_at',
                'order_dir' => strtoupper($input['order_dir'] ?? 'DESC'),
                'limit' => $limit,
                'offset' => $offset
            ];
            
            $memories = $this->model->getPublicMemories($filters);
            $total = $this->model->count($filters);
            
            LogService::info('Récupération mémoires publiques v2', [
                'total' => $total,
                'page' => $page
            ]);
            
            Response::success('Public memories retrieved successfully', [
                'memories' => $memories,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            
        } catch (Exception $e) {
            LogService::error('Erreur mémoires publiques v2', [
                'error' => $e->getMessage()
            ]);
            Response::error('Erreur lors de la récupération des mémoires publiques');
        }
    }
    
    /**
     * Récupérer mes mémoires
     */
    public function getMyMemories($userId) {
        try {
            $params = Response::getRequestParams();
            $pagination = Response::getPaginationParams();
            
            $filters = [
                'user_id' => $userId,
                'search' => $params['search'] ?? '',
                'order_by' => $params['order_by'] ?? 'created_at',
                'order_dir' => strtoupper($params['order_dir'] ?? 'DESC'),
                'limit' => $pagination['limit'],
                'offset' => ($pagination['page'] - 1) * $pagination['limit']
            ];
            
            $memories = $this->model->getAll($filters);
            $total = $this->model->count($filters);
            
            LogService::info('Récupération mes mémoires v2', [
                'user_id' => $userId,
                'total' => $total
            ]);
            
            Response::success([
                'memories' => $memories,
                'pagination' => [
                    'page' => $pagination['page'],
                    'limit' => $pagination['limit'],
                    'total' => $total,
                    'pages' => ceil($total / $pagination['limit'])
                ]
            ]);
            
        } catch (Exception $e) {
            LogService::error('Erreur récupération mes mémoires v2', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            Response::error('Erreur lors de la récupération de vos mémoires');
        }
    }

    public function search($userId, $userRole) {
        try {
            // Récupérer les paramètres de recherche
            // Supporter à la fois 'q' et 'search' pour compatibilité
            $input = Response::getRequestParams();
            if (!$input) {
                Response::error('Données JSON invalides', null, 400);
                return;
            }
            $validator = new Validator();
            $validation = $validator->validate($input, [
                'q' => 'required|string|min:2|max:255',
                'page' => 'integer|min:1',
                'limit' => 'integer|min:1|max:100',
                'order_by' => 'string|in:created_at,title',
                'order_dir' => 'string|in:ASC,DESC'
            ]);
            if (!$validation['valid']) {
                Response::error('Paramètres invalides', $validation['errors'], 400);
                return;
            }



            $query = $input['q'] ?? $input['search'] ?? '';
            
            if (empty($query)) {
                Response::error('Paramètre de recherche requis', null, 400);
                return;
            }
            
            $page = (int)($input['page'] ?? 1);
            $limit = min((int)($input['limit'] ?? 20), 100);
            $offset = ($page - 1) * $limit;
            
            $filters = [
                'search' => $query,
                'order_by' => $input['order_by'] ?? 'created_at',
                'order_dir' => strtoupper($input['order_dir'] ?? 'DESC'),
                'limit' => $limit,
                'offset' => $offset
            ];
            
            // Si pas admin, limiter aux mémoires de l'utilisateur ou publiques
            if ($userRole !== 'admin') {
                // Pour la recherche, on inclut les mémoires publiques ET celles de l'utilisateur
                $memories = $this->model->search($query, $userId, $filters);
            } else {
                $memories = $this->model->search($query, null, $filters);
            }
            
            $total = count($memories); // Approximation pour la recherche
            
            LogService::info('Recherche mémoires v2', [
                'query' => $query,
                'user_id' => $userId,
                'results' => $total
            ]);
            
            Response::success([
                'memories' => $memories,
                'search_query' => $query,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            
        } catch (Exception $e) {
            LogService::error('Erreur recherche mémoires v2', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            Response::error('Erreur lors de la recherche');
        }
    }
    
    /**
     * Obtenir les statistiques des mémoires
     */
    public function getStatistics($userId, $userRole) {
        try {
            // Admin peut voir toutes les stats, utilisateur normal seulement les siennes
            $statsUserId = ($userRole === 'admin') ? null : $userId;
            
            $stats = $this->model->getStatistics($statsUserId);
            
            LogService::info('Statistiques mémoires v2', [
                'user_id' => $userId,
                'role' => $userRole,
                'stats_for' => $statsUserId
            ]);
            
            Response::success([
                'statistics' => $stats,
                'scope' => ($userRole === 'admin') ? 'global' : 'user'
            ]);
            
        } catch (Exception $e) {
            LogService::error('Erreur statistiques mémoires v2', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            Response::error('Erreur lors de la récupération des statistiques');
        }
    }
    
    /**
     * Vérifier les permissions sur une mémoire
     */
    private function checkPermission($memory, $userId, $userRole, $action) {
        // Admin a tous les droits
        if ($userRole === 'ADMINISTRATEUR') {
            return true;
        }
        
        // Propriétaire a tous les droits
        if ($memory['user_id'] == $userId) {
            return true;
        }
        
        // Lecture seule pour les mémoires publiques
        if ($action === 'read' && $memory['visibility'] === 'public') {
            return true;
        }
        
        return false;
    }

}
