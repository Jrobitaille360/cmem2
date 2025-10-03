# Migration Script - .env vers structure modulaire

## 🔍 Fichiers .env détectés

Vous avez plusieurs fichiers .env existants :
- `.env` (814 bytes)
- `.env.cmem1` (2506 bytes) 
- `.env.local` (1963 bytes)
- `.env.example` (3150 bytes)

## 🚀 Étapes de Migration

### **1. Sauvegarder vos configurations actuelles**
```bash
# Créer un dossier de sauvegarde
mkdir .env.backup
cp .env* .env.backup/
```

### **2. Identifier votre fichier .env principal**
Regardez le contenu de vos fichiers pour identifier celui en cours d'utilisation :
```bash
# Vérifier le contenu
cat .env
cat .env.cmem1  
cat .env.local
```

### **3. Migrer vers la structure modulaire**

#### **Option A : Migration manuelle (recommandée)**
1. Copiez les templates :
```bash
cp .env.auth_groups.example .env.auth_groups
cp .env.memories_elements.example .env.memories_elements
```

2. Transférez vos variables depuis votre .env principal vers les nouveaux fichiers selon cette répartition :

**→ .env.auth_groups :**
- `APP_*` (environnement)
- `DB_*` (base de données)  
- `JWT_*` (authentification)
- `AUTH_*` (authentification)
- `LOG_*` (logs)
- `ALLOWED_*` (CORS)
- `MAIL_*` (email)
- Avatar et groupe uploads

**→ .env.memories_elements :**
- `MEMORY_*` (mémoires)
- `MAX_MEMORY_*` (uploads mémoires)
- `MEMORIES_*` (pagination)
- `ALLOWED_MEMORY_*` (types de fichiers)
- Variables de traitement multimédia

#### **Option B : Migration semi-automatique**
```bash
# Extraire les variables auth_groups depuis votre .env principal
grep -E '^(APP_|DB_|JWT_|AUTH_|LOG_|ALLOWED_|MAIL_|MAX_AVATAR|GROUP_)' .env > .env.auth_groups.extracted

# Extraire les variables memories_elements
grep -E '^(MEMORY_|MEMORIES_|MAX_MEMORY|ALLOWED_MEMORY|ENABLE_MEMORY)' .env > .env.memories_elements.extracted

# Compléter manuellement avec les templates
```

### **4. Validation**

Testez votre configuration :
```bash
# Accéder à l'API
curl http://localhost/cmem2_API/

# Vérifier les logs si APP_DEBUG=true
tail -f logs/shared.log
```

### **5. Nettoyage (après validation)**
```bash
# Renommer l'ancien .env
mv .env .env.old

# Ou supprimer si tout fonctionne
# rm .env .env.cmem1 .env.local
```

## ⚠️ Points d'Attention

### **Variables critiques à vérifier :**
1. **JWT_SECRET** : Doit être unique et sécurisé (64+ caractères)
2. **DB_PASS** : Votre mot de passe de base de données
3. **ALLOWED_ORIGINS** : Domaines autorisés pour CORS
4. **APP_ENV** : development/production

### **Variables qui ont changé de nom :**
- Certaines variables ont été renommées pour plus de clarté
- Consultez les templates .example pour les nouveaux noms

### **Nouvelles variables disponibles :**
- Variables de rotation des logs
- Configuration avancée des uploads
- Paramètres de performance et cache

## 🆘 En cas de problème

### **L'API ne démarre pas :**
1. Vérifiez que `.env.auth_groups` existe
2. Contrôlez les variables obligatoires (DB_*, JWT_SECRET)
3. Regardez les logs d'erreur si LOG_ENABLED=true

### **Certaines fonctionnalités manquent :**
1. Vérifiez que `.env.memories_elements` existe pour les mémoires
2. Contrôlez que les variables sont bien migrées
3. Comparez avec les fichiers .example

### **Rollback temporaire :**
```bash
# Restaurer l'ancien système
cp .env.backup/.env .env
# Modifier config/auth_groups/shared/environment.php pour charger seulement .env
```

## 📞 Support

- Consultez `ENV_SETUP.md` pour le setup rapide
- Lisez `CONFIGURATION_ENV_MODULAIRE.md` pour la documentation complète
- Vérifiez les logs système dans `logs/`