# Nouvel Endpoint : POST /groups/{group_id}/members/{user_id}

## Description

Cet endpoint permet à un administrateur (système ou du groupe) d'ajouter directement un utilisateur à un groupe sans nécessiter d'invitation.

## Authentification

- Authentification JWT requise
- Permissions : Administrateur système OU Administrateur du groupe

## URL

```text
POST /groups/{group_id}/members/{user_id}
```

## Paramètres

- `group_id` : ID du groupe (requis, entier)
- `user_id` : ID de l'utilisateur à ajouter (requis, entier)

## Body (JSON)

```json
{
  "role": "member"  // optionnel, défaut: "member", valeurs: "member", "moderator", "admin"
}
```

## Réponses

### Succès (201)

```json
{
  "success": true,
  "message": "Membre ajouté au groupe avec succès",
  "data": {
    "group_id": 1,
    "user_id": 2,
    "role": "member"
  }
}
```

### Erreurs

- **400** : L'utilisateur est déjà membre de ce groupe
- **401** : Authentification requise
- **403** : Permissions insuffisantes
- **404** : Groupe ou utilisateur non trouvé
- **500** : Erreur serveur

## Fichiers modifiés

### 1. GroupMemberController.php

- Ajout de la méthode `addMember($groupId, $userId, $currentUserId, $currentUserRole)`
- Import du modèle `User` pour vérifier l'existence de l'utilisateur cible
- Validation des permissions et des données
- Logging des opérations

### 2. Group.php (Modèle)

- Ajout de la méthode `addMemberDirect($groupId, $userId, $role, $addedBy)`
- Insertion directe dans la table `group_members` sans passer par le système d'invitation

### 3. GroupRouteHandler.php

- Import du `GroupMemberController`
- Ajout de la route POST `/groups/{group_id}/members/{user_id}`
- Instanciation du `GroupMemberController` dans le constructeur

### 4. ENDPOINTS_GROUPS.md

- Documentation complète du nouvel endpoint
- Exemples de requêtes et réponses
- Liste des codes d'erreur possibles

### 5. API_ENDPOINTS_v1_3_0.json

- Ajout de l'endpoint dans la documentation JSON de l'API
- Spécification des paramètres et du body

### 6. test_group_add_member.php (Nouveau)

- Tests complets de l'endpoint
- Cas de tests : authentification, permissions, validations, erreurs

## Utilisation

### Ajouter un membre avec le rôle par défaut (member)

```bash
curl -X POST "http://localhost/groups/1/members/2" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json"
```

### Ajouter un membre avec un rôle spécifique

```bash
curl -X POST "http://localhost/groups/1/members/2" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"role": "moderator"}'
```

## Sécurité

- Vérification de l'existence du groupe et de l'utilisateur
- Contrôle des permissions (seuls les admins peuvent ajouter des membres)
- Validation que l'utilisateur n'est pas déjà membre
- Validation du rôle fourni
- Logging de toutes les opérations pour audit

## Base de données

L'endpoint utilise la table `group_members` existante avec les colonnes :

- `group_id` : ID du groupe
- `user_id` : ID de l'utilisateur
- `role` : Rôle dans le groupe ('member', 'moderator', 'admin')
- `invited_by` : ID de l'utilisateur qui a ajouté ce membre
- `joined_at` : Date/heure d'ajout automatique
