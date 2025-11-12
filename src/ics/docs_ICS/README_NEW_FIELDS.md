# Mise à jour du plugin ICS - Nouveaux champs d'événement

## 📋 Vue d'ensemble

Cette mise à jour ajoute 4 nouveaux champs aux événements du calendrier pour améliorer l'intégration avec le frontend Flutter :

- **timezone** : Fuseau horaire de l'événement
- **meeting_link** : Lien de réunion virtuelle (Zoom, Teams, Google Meet, etc.)
- **notifications** : Liste des notifications configurées
- **color** : Couleur personnalisée de l'événement

## 🚀 Installation

### 1. Migration de la base de données

Exécutez le script de migration pour ajouter les nouveaux champs :

```bash
mysql -u votre_utilisateur -p votre_base_de_donnees < src/ics/docs_ICS/Proc_add_new_event_fields.sql
```

Ou via PhpMyAdmin, exécutez le contenu du fichier `Proc_add_new_event_fields.sql`.

### 2. Vérification

Pour vérifier que les colonnes ont été ajoutées :

```sql
DESCRIBE calendar_events;
```

Vous devriez voir les nouveaux champs :

- `timezone` VARCHAR(100) DEFAULT 'America/Montreal'
- `meeting_link` TEXT
- `notifications` JSON
- `color` VARCHAR(7)

## 📖 Documentation API

### Créer un événement avec les nouveaux champs

**Endpoint** : `POST /calendars/{id}/events`

**Corps de la requête** :

```json
{
  "title": "Réunion d'équipe",
  "description": "Discussion des objectifs du trimestre",
  "location": "Salle de conférence A",
  "start_datetime": "2025-11-15T09:00:00",
  "end_datetime": "2025-11-15T10:00:00",
  "all_day": false,
  "status": "confirmed",
  
  "timezone": "America/Montreal",
  "meeting_link": "https://zoom.us/j/123456789",
  "color": "#4285F4",
  "notifications": "[{\"type\":\"notification\",\"minutes\":15},{\"type\":\"e-mail\",\"minutes\":30}]"
}
```

**Réponse (201 Created)** :

```json
{
  "success": true,
  "message": "Événement créé avec succès",
  "data": {
    "id": 1,
    "calendar_id": 1,
    "title": "Réunion d'équipe",
    "start_datetime": "2025-11-15T09:00:00",
    "end_datetime": "2025-11-15T10:00:00",
    "timezone": "America/Montreal",
    "meeting_link": "https://zoom.us/j/123456789",
    "color": "#4285F4",
    "notifications": [
      {"type": "notification", "minutes": 15},
      {"type": "e-mail", "minutes": 30}
    ]
  }
}
```

### Mettre à jour un événement

**Endpoint** : `PUT /calendars/{calendarId}/events/{eventId}`

**Corps de la requête** :

```json
{
  "title": "Réunion d'équipe - Mise à jour",
  "timezone": "America/Toronto",
  "meeting_link": "https://teams.microsoft.com/l/meetup/...",
  "color": "#FF5733",
  "notifications": "[{\"type\":\"notification\",\"minutes\":10}]"
}
```

### Récupérer les événements

**Endpoint** : `GET /calendars/{id}/events`

**Réponse** :

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "calendar_id": 1,
      "title": "Réunion d'équipe",
      "description": "Discussion des objectifs",
      "start_datetime": "2025-11-15T09:00:00",
      "end_datetime": "2025-11-15T10:00:00",
      "timezone": "America/Montreal",
      "meeting_link": "https://zoom.us/j/123456789",
      "color": "#4285F4",
      "notifications": [
        {"type": "notification", "minutes": 15},
        {"type": "e-mail", "minutes": 30}
      ]
    }
  ]
}
```

## ✅ Validation

### Timezone

- **Valeurs acceptées** : `America/Montreal`, `America/Toronto`, `America/New_York`, `America/Chicago`, `America/Denver`, `America/Los_Angeles`, `America/Vancouver`, `Europe/Paris`, `Europe/London`, `Europe/Berlin`, `UTC`
- **Valeur par défaut** : `America/Montreal`
- **Erreur** : `400 Bad Request` si le timezone n'est pas dans la liste

### Meeting Link

- **Format** : URL valide commençant par `http://` ou `https://`
- **Optionnel** : Peut être `null`
- **Erreur** : `400 Bad Request` si l'URL est invalide

### Color

- **Format** : Hexadécimal `#RRGGBB` (ex: `#4285F4`)
- **Optionnel** : Peut être `null`
- **Erreur** : `400 Bad Request` si le format est incorrect

### Notifications

- **Format** : Array JSON d'objets avec `type` et `minutes`
- **Types acceptés** : `notification`, `e-mail`
- **Minutes** : Nombre entier >= 0
- **Optionnel** : Peut être `null` ou `[]`
- **Erreur** : `400 Bad Request` si le format est incorrect

**Exemple valide** :

```json
[
  {"type": "notification", "minutes": 15},
  {"type": "e-mail", "minutes": 30},
  {"type": "notification", "minutes": 60}
]
```

## 🧪 Tests

Un script de test complet est disponible : `tests_new/test_new_event_fields.php`

### Exécution des tests

```bash
php tests_new/test_new_event_fields.php
```

**Configuration** : Avant d'exécuter les tests, modifiez les variables suivantes dans le fichier :

```php
$API_BASE_URL = 'http://localhost/cmem2_API'; // Votre URL
$API_KEY = 'votre_cle_api_ici';               // Votre clé API
```

### Tests couverts

1. ✓ Création d'un calendrier de test
2. ✓ Création d'événement avec tous les nouveaux champs
3. ✓ Rétrocompatibilité (événement sans nouveaux champs)
4. ✓ Mise à jour avec nouveaux champs
5. ✓ Récupération des événements
6. ✓ Validation timezone invalide
7. ✓ Validation meeting link invalide
8. ✓ Validation couleur invalide
9. ✓ Validation notifications invalides

## 📁 Fichiers modifiés

### Nouveaux fichiers

- `src/ics/docs_ICS/Proc_add_new_event_fields.sql` - Script de migration
- `src/ics/Utils/EventValidator.php` - Classe de validation
- `tests_new/test_new_event_fields.php` - Tests automatisés

### Fichiers modifiés

- `src/ics/docs_ICS/Proc_create_tables_ICS.sql` - Ajout des champs dans CREATE TABLE
- `src/ics/Models/CalendarEvent.php` - Ajout des propriétés et mise à jour des méthodes
- `src/ics/Controllers/CalendarController.php` - Mise à jour de `createEvent()` et `updateEvent()`

## 🔄 Rétrocompatibilité

✅ **Tous les nouveaux champs sont optionnels**

Les anciennes requêtes continuent de fonctionner :

```json
{
  "title": "Événement simple",
  "start_datetime": "2025-11-15T10:00:00",
  "end_datetime": "2025-11-15T11:00:00"
}
```

**Valeurs par défaut** :

- `timezone` : `"America/Montreal"`
- `meeting_link` : `null`
- `notifications` : `null`
- `color` : `null` (utilisera la couleur du calendrier parent)

## 🐛 Dépannage

### Erreur : Column 'timezone' not found

➡️ Exécutez la migration SQL : `Proc_add_new_event_fields.sql`

### Erreur : Class 'ICS\Utils\EventValidator' not found

➡️ Vérifiez que le fichier `src/ics/Utils/EventValidator.php` existe et que l'autoloader est à jour.

### Les notifications ne sont pas décodées

➡️ Assurez-vous d'envoyer le champ `notifications` en tant que **string JSON encodée** :

```json
"notifications": "[{\"type\":\"notification\",\"minutes\":15}]"
```

## 📞 Support

Pour toute question ou problème :

1. Vérifiez les logs dans `logs/`
2. Testez avec le script `test_new_event_fields.php`
3. Consultez la documentation API ci-dessus

## 📝 Notes de version

**Version** : 2.0  
**Date** : 12 novembre 2025  
**Compatibilité** : Rétrocompatible avec la version 1.x

### Changements

- ✨ Ajout du champ `timezone` pour les événements
- ✨ Ajout du champ `meeting_link` pour les liens de réunion virtuelle
- ✨ Ajout du champ `notifications` pour la gestion des alertes
- ✨ Ajout du champ `color` pour personnaliser la couleur des événements
- 🔒 Validation stricte des nouveaux champs
- 📚 Documentation API complète
- 🧪 Suite de tests automatisés

## 🎯 Prochaines étapes

1. Exécuter la migration SQL
2. Tester avec le script de test
3. Mettre à jour le frontend Flutter pour utiliser les nouveaux champs
4. Déployer en production

---

**Auteur** : API cmem2  
**Licence** : Voir LICENSE
