# Guide d'installation rapide - Nouveaux champs ICS

## 🚀 Installation en 3 étapes

### Étape 1 : Migration de la base de données

Exécutez le script SQL suivant :

```bash
mysql -u votre_user -p votre_db < src/ics/docs_ICS/Proc_add_new_event_fields.sql
```

Ou via PhpMyAdmin, copiez-collez le contenu de `Proc_add_new_event_fields.sql`.

### Étape 2 : Vérification

Exécutez cette commande SQL pour vérifier :

```sql
DESCRIBE calendar_events;
```

Vous devez voir les 4 nouveaux champs :

- ✅ `timezone`
- ✅ `meeting_link`
- ✅ `notifications`
- ✅ `color`

### Étape 3 : Test

Exécutez le script de test :

```bash
cd tests_new
php test_new_event_fields.php
```

**Avant de lancer le test**, modifiez ces variables dans le fichier :

```php
$API_BASE_URL = 'http://localhost/cmem2_API';
$API_KEY = 'VOTRE_CLE_API';
```

## ✅ C'est terminé

Les nouveaux champs sont maintenant disponibles sur vos endpoints :

- `POST /calendars/{id}/events`
- `PUT /calendars/{calendarId}/events/{eventId}`
- `GET /calendars/{id}/events`

## 📚 Documentation complète

Consultez `README_NEW_FIELDS.md` pour :

- Documentation API détaillée
- Exemples d'utilisation
- Guide de validation
- Dépannage

## 🔗 Frontend Flutter

Pour intégrer les nouveaux champs dans Flutter, ajoutez à votre modèle `CalendarEvent` :

```dart
class CalendarEvent {
  // ... champs existants ...
  
  final String? timezone;
  final String? meetingLink;
  final List<EventNotification>? notifications;
  final String? color;
  
  CalendarEvent({
    // ... paramètres existants ...
    this.timezone,
    this.meetingLink,
    this.notifications,
    this.color,
  });
  
  factory CalendarEvent.fromJson(Map<String, dynamic> json) {
    return CalendarEvent(
      // ... mapping existant ...
      timezone: json['timezone'] as String?,
      meetingLink: json['meeting_link'] as String?,
      notifications: json['notifications'] != null
          ? (json['notifications'] as List)
              .map((n) => EventNotification.fromJson(n))
              .toList()
          : null,
      color: json['color'] as String?,
    );
  }
  
  Map<String, dynamic> toJson() {
    return {
      // ... mapping existant ...
      if (timezone != null) 'timezone': timezone,
      if (meetingLink != null) 'meeting_link': meetingLink,
      if (notifications != null)
        'notifications': jsonEncode(
          notifications!.map((n) => n.toJson()).toList()
        ),
      if (color != null) 'color': color,
    };
  }
}

class EventNotification {
  final String type; // 'notification' ou 'e-mail'
  final int minutes;
  
  EventNotification({required this.type, required this.minutes});
  
  factory EventNotification.fromJson(Map<String, dynamic> json) {
    return EventNotification(
      type: json['type'] as String,
      minutes: json['minutes'] as int,
    );
  }
  
  Map<String, dynamic> toJson() {
    return {
      'type': type,
      'minutes': minutes,
    };
  }
}
```

## ⚠️ Notes importantes

1. **Notifications** : Envoyez-les en tant que **string JSON** :

   ```json
   "notifications": "[{\"type\":\"notification\",\"minutes\":15}]"
   ```

2. **Color** : Format hexadécimal uniquement : `#RRGGBB`

3. **Timezone** : Utilisez uniquement les valeurs listées dans `EventValidator.php`

4. **Meeting Link** : URL complète avec `http://` ou `https://`

---

**Besoin d'aide ?** Consultez `CHANGELOG_NEW_FIELDS.md` et `README_NEW_FIELDS.md`
