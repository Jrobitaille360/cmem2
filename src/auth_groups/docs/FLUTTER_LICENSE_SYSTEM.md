# Guide Complet - Application Flutter avec Système de Licences API

## 📋 Vue d'ensemble

Ce guide explique comment créer une application Flutter mondiale avec un système de licences payantes. Chaque utilisateur reçoit une **API Key personnalisée** après paiement qui lui donne accès à l'API selon ses droits.

## 🏗️ Architecture

```
Utilisateur → Paiement → API Key générée → Application Flutter → API Backend
```

### Flux Utilisateur Complet

1. Utilisateur télécharge l'app → Écran d'activation
2. Utilisateur paie → Webhook déclenché (Stripe/PayPal)
3. API génère une API Key → Envoyée par email
4. Utilisateur entre l'API Key dans l'app
5. App valide la clé → Accès complet à l'API
6. Toutes les requêtes utilisent automatiquement la clé

---

## 🚀 Partie 1 : Application Flutter

### 1.1 Création du Projet

```bash
# Créer le projet
flutter create cmem_client_app
cd cmem_client_app

# Ajouter les dépendances nécessaires
flutter pub add http
flutter pub add flutter_secure_storage
flutter pub add provider
flutter pub add shared_preferences
```

### 1.2 Structure de Dossiers

```
lib/
├── main.dart
├── config/
│   └── api_config.dart          # Configuration API
├── models/
│   ├── user.dart                # Modèle utilisateur
│   └── api_response.dart        # Modèle de réponse
├── services/
│   ├── api_service.dart         # Service HTTP principal
│   ├── license_service.dart     # Gestion des licences/API Keys
│   └── storage_service.dart     # Stockage sécurisé
├── screens/
│   ├── activation_screen.dart   # Activation de licence
│   ├── home_screen.dart         # Écran principal
│   └── login_screen.dart        # Login (optionnel)
└── widgets/
    └── api_status_widget.dart   # Widget de statut API
```

---

## 📝 1.3 Fichiers de Configuration

### `lib/config/api_config.dart`

```dart
class ApiConfig {
  // URL de votre API
  static const String baseUrl = 'https://cmem1.journauxdebord.com';
  // OU pour développement local:
  // static const String baseUrl = 'http://10.0.2.2/cmem2_API'; // Android emulator
  // static const String baseUrl = 'http://localhost/cmem2_API'; // iOS simulator
  
  static const String apiVersion = 'v1';
  
  // Endpoints
  static const String activateLicense = '/api-keys/validate';
  static const String getUserProfile = '/users/me';
  static const String getGroups = '/groups';
  
  // Timeout
  static const Duration timeout = Duration(seconds: 30);
  
  // Headers
  static Map<String, String> get headers => {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  };
}
```

---

## 🔐 1.4 Service de Stockage Sécurisé

### `lib/services/storage_service.dart`

```dart
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class StorageService {
  static final StorageService _instance = StorageService._internal();
  factory StorageService() => _instance;
  StorageService._internal();

  final _storage = const FlutterSecureStorage();
  
  // Clés de stockage
  static const String _apiKeyKey = 'api_key';
  static const String _userIdKey = 'user_id';
  static const String _licenseInfoKey = 'license_info';

  // Sauvegarder l'API Key
  Future<void> saveApiKey(String apiKey) async {
    await _storage.write(key: _apiKeyKey, value: apiKey);
  }

  // Récupérer l'API Key
  Future<String?> getApiKey() async {
    return await _storage.read(key: _apiKeyKey);
  }

  // Vérifier si une licence est active
  Future<bool> hasActiveLicense() async {
    final apiKey = await getApiKey();
    return apiKey != null && apiKey.isNotEmpty;
  }

  // Sauvegarder les infos utilisateur
  Future<void> saveUserId(String userId) async {
    await _storage.write(key: _userIdKey, value: userId);
  }

  Future<String?> getUserId() async {
    return await _storage.read(key: _userIdKey);
  }

  // Supprimer toutes les données (logout/désactivation)
  Future<void> clearAll() async {
    await _storage.deleteAll();
  }

  // Sauvegarder les infos de licence complètes
  Future<void> saveLicenseInfo(String info) async {
    await _storage.write(key: _licenseInfoKey, value: info);
  }

  Future<String?> getLicenseInfo() async {
    return await _storage.read(key: _licenseInfoKey);
  }
}
```

---

## 🌐 1.5 Service HTTP avec API Key

### `lib/services/api_service.dart`

```dart
import 'dart:convert';
import 'package:http/http.dart' as http;
import '../config/api_config.dart';
import 'storage_service.dart';

class ApiService {
  static final ApiService _instance = ApiService._internal();
  factory ApiService() => _instance;
  ApiService._internal();

  final _storage = StorageService();

  // Obtenir les headers avec API Key
  Future<Map<String, String>> _getAuthHeaders() async {
    final headers = Map<String, String>.from(ApiConfig.headers);
    final apiKey = await _storage.getApiKey();
    
    if (apiKey != null && apiKey.isNotEmpty) {
      // Votre API accepte les deux formats
      headers['X-API-Key'] = apiKey;
      // OU headers['Authorization'] = 'Bearer $apiKey';
    }
    
    return headers;
  }

  // GET Request
  Future<Map<String, dynamic>> get(String endpoint) async {
    try {
      final url = Uri.parse('${ApiConfig.baseUrl}$endpoint');
      final headers = await _getAuthHeaders();
      
      final response = await http.get(
        url,
        headers: headers,
      ).timeout(ApiConfig.timeout);

      return _handleResponse(response);
    } catch (e) {
      throw ApiException('Erreur de connexion: $e');
    }
  }

  // POST Request
  Future<Map<String, dynamic>> post(String endpoint, Map<String, dynamic> body) async {
    try {
      final url = Uri.parse('${ApiConfig.baseUrl}$endpoint');
      final headers = await _getAuthHeaders();
      
      final response = await http.post(
        url,
        headers: headers,
        body: jsonEncode(body),
      ).timeout(ApiConfig.timeout);

      return _handleResponse(response);
    } catch (e) {
      throw ApiException('Erreur de connexion: $e');
    }
  }

  // PUT Request
  Future<Map<String, dynamic>> put(String endpoint, Map<String, dynamic> body) async {
    try {
      final url = Uri.parse('${ApiConfig.baseUrl}$endpoint');
      final headers = await _getAuthHeaders();
      
      final response = await http.put(
        url,
        headers: headers,
        body: jsonEncode(body),
      ).timeout(ApiConfig.timeout);

      return _handleResponse(response);
    } catch (e) {
      throw ApiException('Erreur de connexion: $e');
    }
  }

  // DELETE Request
  Future<Map<String, dynamic>> delete(String endpoint) async {
    try {
      final url = Uri.parse('${ApiConfig.baseUrl}$endpoint');
      final headers = await _getAuthHeaders();
      
      final response = await http.delete(
        url,
        headers: headers,
      ).timeout(ApiConfig.timeout);

      return _handleResponse(response);
    } catch (e) {
      throw ApiException('Erreur de connexion: $e');
    }
  }

  // Traiter la réponse
  Map<String, dynamic> _handleResponse(http.Response response) {
    final data = jsonDecode(response.body);
    
    if (response.statusCode >= 200 && response.statusCode < 300) {
      return data;
    } else {
      final errorMessage = data['error']?['message'] ?? 'Erreur inconnue';
      throw ApiException(errorMessage, statusCode: response.statusCode);
    }
  }
}

// Exception personnalisée
class ApiException implements Exception {
  final String message;
  final int? statusCode;

  ApiException(this.message, {this.statusCode});

  @override
  String toString() => message;
}
```

---

## 🎫 1.6 Service de Gestion des Licences

### `lib/services/license_service.dart`

```dart
import 'dart:convert';
import 'api_service.dart';
import 'storage_service.dart';

class LicenseService {
  static final LicenseService _instance = LicenseService._internal();
  factory LicenseService() => _instance;
  LicenseService._internal();

  final _api = ApiService();
  final _storage = StorageService();

  // Activer une licence avec l'API Key fournie après paiement
  Future<Map<String, dynamic>> activateLicense(String apiKey, String userId) async {
    try {
      // 1. Sauvegarder temporairement l'API Key
      await _storage.saveApiKey(apiKey);
      
      // 2. Tester la clé en récupérant le profil utilisateur
      final response = await _api.get('/users/me');
      
      if (response['success'] == true) {
        // 3. Sauvegarder les infos utilisateur
        final user = response['data']['user'];
        await _storage.saveUserId(userId);
        await _storage.saveLicenseInfo(jsonEncode(user));
        
        return {
          'success': true,
          'message': 'Licence activée avec succès',
          'user': user,
        };
      } else {
        throw Exception('Réponse invalide du serveur');
      }
    } catch (e) {
      // En cas d'erreur, supprimer la clé invalide
      await _storage.clearAll();
      throw Exception('Échec de l\'activation: $e');
    }
  }

  // Vérifier le statut de la licence
  Future<bool> verifyLicense() async {
    try {
      if (!await _storage.hasActiveLicense()) {
        return false;
      }
      
      // Tester l'API Key en faisant une requête
      final response = await _api.get('/users/me');
      return response['success'] == true;
    } catch (e) {
      return false;
    }
  }

  // Obtenir les infos de l'utilisateur
  Future<Map<String, dynamic>?> getUserInfo() async {
    try {
      final licenseInfo = await _storage.getLicenseInfo();
      if (licenseInfo != null) {
        return jsonDecode(licenseInfo);
      }
      return null;
    } catch (e) {
      return null;
    }
  }

  // Désactiver la licence (logout)
  Future<void> deactivateLicense() async {
    await _storage.clearAll();
  }
}
```

---

## 📱 1.7 Écran d'Activation de Licence

### `lib/screens/activation_screen.dart`

```dart
import 'package:flutter/material.dart';
import '../services/license_service.dart';
import 'home_screen.dart';

class ActivationScreen extends StatefulWidget {
  const ActivationScreen({Key? key}) : super(key: key);

  @override
  State<ActivationScreen> createState() => _ActivationScreenState();
}

class _ActivationScreenState extends State<ActivationScreen> {
  final _formKey = GlobalKey<FormState>();
  final _apiKeyController = TextEditingController();
  final _userIdController = TextEditingController();
  final _licenseService = LicenseService();
  
  bool _isLoading = false;
  String? _errorMessage;

  Future<void> _activateLicense() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final result = await _licenseService.activateLicense(
        _apiKeyController.text.trim(),
        _userIdController.text.trim(),
      );

      if (!mounted) return;

      // Activation réussie, naviguer vers l'écran principal
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(builder: (_) => const HomeScreen()),
      );

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(result['message'] ?? 'Licence activée!'),
          backgroundColor: Colors.green,
        ),
      );
    } catch (e) {
      setState(() {
        _errorMessage = e.toString();
        _isLoading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Activer votre licence'),
      ),
      body: Padding(
        padding: const EdgeInsets.all(24.0),
        child: Form(
          key: _formKey,
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // Logo ou image
              Icon(
                Icons.vpn_key,
                size: 80,
                color: Theme.of(context).primaryColor,
              ),
              const SizedBox(height: 32),
              
              // Titre
              Text(
                'Bienvenue !',
                style: Theme.of(context).textTheme.headlineMedium,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 8),
              Text(
                'Entrez votre clé de licence reçue après paiement',
                style: Theme.of(context).textTheme.bodyMedium,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 32),
              
              // Champ User ID
              TextFormField(
                controller: _userIdController,
                decoration: const InputDecoration(
                  labelText: 'Votre ID utilisateur',
                  prefixIcon: Icon(Icons.person),
                  border: OutlineInputBorder(),
                ),
                keyboardType: TextInputType.number,
                validator: (value) {
                  if (value == null || value.isEmpty) {
                    return 'Veuillez entrer votre ID';
                  }
                  return null;
                },
              ),
              const SizedBox(height: 16),
              
              // Champ API Key
              TextFormField(
                controller: _apiKeyController,
                decoration: const InputDecoration(
                  labelText: 'Clé API',
                  prefixIcon: Icon(Icons.key),
                  border: OutlineInputBorder(),
                  hintText: 'ag_live_...',
                ),
                obscureText: true,
                validator: (value) {
                  if (value == null || value.isEmpty) {
                    return 'Veuillez entrer votre clé API';
                  }
                  if (!value.startsWith('ag_live_') && !value.startsWith('ag_test_')) {
                    return 'Format de clé invalide';
                  }
                  return null;
                },
              ),
              const SizedBox(height: 24),
              
              // Message d'erreur
              if (_errorMessage != null)
                Container(
                  padding: const EdgeInsets.all(12),
                  margin: const EdgeInsets.only(bottom: 16),
                  decoration: BoxDecoration(
                    color: Colors.red.shade50,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: Colors.red.shade200),
                  ),
                  child: Text(
                    _errorMessage!,
                    style: TextStyle(color: Colors.red.shade900),
                  ),
                ),
              
              // Bouton d'activation
              ElevatedButton(
                onPressed: _isLoading ? null : _activateLicense,
                style: ElevatedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(vertical: 16),
                ),
                child: _isLoading
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Text('Activer la licence', style: TextStyle(fontSize: 16)),
              ),
              
              const SizedBox(height: 16),
              
              // Lien d'aide
              TextButton(
                onPressed: () {
                  // Ouvrir l'aide ou le support
                },
                child: const Text('Besoin d\'aide ?'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  void dispose() {
    _apiKeyController.dispose();
    _userIdController.dispose();
    super.dispose();
  }
}
```

---

## 🏠 1.8 Écran Principal (Exemple)

### `lib/screens/home_screen.dart`

```dart
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../services/license_service.dart';
import 'activation_screen.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({Key? key}) : super(key: key);

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  final _api = ApiService();
  final _licenseService = LicenseService();
  
  Map<String, dynamic>? _userInfo;
  List<dynamic> _groups = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  Future<void> _loadData() async {
    setState(() => _isLoading = true);
    
    try {
      // Charger les infos utilisateur
      _userInfo = await _licenseService.getUserInfo();
      
      // Charger les groupes (exemple d'utilisation de l'API)
      final response = await _api.get('/groups');
      if (response['success'] == true) {
        setState(() {
          _groups = response['data']['groups'] ?? [];
        });
      }
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Erreur: $e')),
      );
    } finally {
      setState(() => _isLoading = false);
    }
  }

  Future<void> _logout() async {
    await _licenseService.deactivateLicense();
    if (!mounted) return;
    
    Navigator.of(context).pushReplacement(
      MaterialPageRoute(builder: (_) => const ActivationScreen()),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Mon Application'),
        actions: [
          IconButton(
            icon: const Icon(Icons.logout),
            onPressed: _logout,
            tooltip: 'Déconnexion',
          ),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _loadData,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  // Carte utilisateur
                  if (_userInfo != null)
                    Card(
                      child: ListTile(
                        leading: const CircleAvatar(
                          child: Icon(Icons.person),
                        ),
                        title: Text(_userInfo!['name'] ?? 'Utilisateur'),
                        subtitle: Text(_userInfo!['email'] ?? ''),
                        trailing: Chip(
                          label: Text(_userInfo!['role'] ?? 'USER'),
                        ),
                      ),
                    ),
                  
                  const SizedBox(height: 16),
                  const Text(
                    'Mes Groupes',
                    style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 8),
                  
                  // Liste des groupes
                  if (_groups.isEmpty)
                    const Center(
                      child: Padding(
                        padding: EdgeInsets.all(32),
                        child: Text('Aucun groupe pour le moment'),
                      ),
                    )
                  else
                    ..._groups.map((group) => Card(
                          child: ListTile(
                            title: Text(group['name'] ?? ''),
                            subtitle: Text(group['description'] ?? ''),
                            trailing: const Icon(Icons.arrow_forward_ios),
                            onTap: () {
                              // Naviguer vers les détails du groupe
                            },
                          ),
                        )),
                ],
              ),
            ),
      floatingActionButton: FloatingActionButton(
        onPressed: () {
          // Créer un nouveau groupe
        },
        child: const Icon(Icons.add),
      ),
    );
  }
}
```

---

## 🎯 1.9 Point d'Entrée Principal

### `lib/main.dart`

```dart
import 'package:flutter/material.dart';
import 'services/storage_service.dart';
import 'screens/activation_screen.dart';
import 'screens/home_screen.dart';

void main() {
  runApp(const MyApp());
}

class MyApp extends StatelessWidget {
  const MyApp({Key? key}) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'CMEM Client',
      theme: ThemeData(
        primarySwatch: Colors.blue,
        useMaterial3: true,
      ),
      home: const SplashScreen(),
      debugShowCheckedModeBanner: false,
    );
  }
}

// Écran de démarrage qui vérifie la licence
class SplashScreen extends StatefulWidget {
  const SplashScreen({Key? key}) : super(key: key);

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> {
  final _storage = StorageService();

  @override
  void initState() {
    super.initState();
    _checkLicense();
  }

  Future<void> _checkLicense() async {
    // Attendre un peu pour l'effet splash
    await Future.delayed(const Duration(seconds: 2));
    
    // Vérifier si une licence existe
    final hasLicense = await _storage.hasActiveLicense();
    
    if (!mounted) return;
    
    // Naviguer vers l'écran approprié
    Navigator.of(context).pushReplacement(
      MaterialPageRoute(
        builder: (_) => hasLicense 
            ? const HomeScreen() 
            : const ActivationScreen(),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              Icons.cloud_circle,
              size: 100,
              color: Theme.of(context).primaryColor,
            ),
            const SizedBox(height: 24),
            const Text(
              'CMEM Client',
              style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 16),
            const CircularProgressIndicator(),
          ],
        ),
      ),
    );
  }
}
```

---

## 🔧 Partie 2 : Modifications Côté API Backend

### 2.1 Modifications Base de Données

```sql
-- Ajouter des colonnes pour le statut de paiement
ALTER TABLE users ADD COLUMN payment_status ENUM('pending', 'paid', 'expired') DEFAULT 'pending';
ALTER TABLE users ADD COLUMN license_expires_at DATETIME NULL;
ALTER TABLE users ADD COLUMN payment_plan ENUM('basic', 'standard', 'premium', 'lifetime') DEFAULT 'basic';
ALTER TABLE users ADD COLUMN payment_date DATETIME NULL;
```

### 2.2 Contrôleur de Licence

Créez `src/auth_groups/Controllers/LicenseController.php` :

```php
<?php
namespace AuthGroups\Controllers;

use AuthGroups\Models\ApiKey;
use AuthGroups\Models\User;
use AuthGroups\Utils\Response;
use AuthGroups\Services\EmailService;

class LicenseController {
    
    /**
     * Générer une licence après paiement (webhook Stripe/PayPal)
     * 
     * @param int $userId ID de l'utilisateur
     * @param string $plan Plan de paiement (basic, standard, premium, lifetime)
     * @return array
     */
    public function generateLicenseAfterPayment($userId, $plan = 'standard') {
        try {
            // 1. Récupérer l'utilisateur
            $userModel = new User();
            $user = $userModel->find($userId);
            
            if (!$user) {
                return Response::error('Utilisateur introuvable', null, 404);
            }
            
            // 2. Mettre à jour le statut de paiement
            $expiryDate = $this->calculateExpiry($plan);
            $userModel->update($userId, [
                'payment_status' => 'paid',
                'payment_plan' => $plan,
                'payment_date' => date('Y-m-d H:i:s'),
                'license_expires_at' => $expiryDate
            ]);
            
            // 3. Créer une API Key pour cet utilisateur
            $apiKeyModel = new ApiKey();
            $scopes = $this->getScopesForPlan($plan);
            $rateLimit = $this->getRateLimitForPlan($plan);
            
            $apiKeyData = $apiKeyModel->create([
                'user_id' => $userId,
                'name' => "License {$plan} - User {$userId}",
                'scopes' => json_encode($scopes),
                'environment' => 'production',
                'expires_at' => $expiryDate,
                'rate_limit_per_minute' => $rateLimit,
                'rate_limit_per_hour' => $rateLimit * 60,
            ]);
            
            // 4. Envoyer par email
            $this->sendLicenseEmail($user['email'], $user['name'], $apiKeyData['api_key'], $plan);
            
            // 5. Logger l'événement (sans la clé complète!)
            \AuthGroups\Services\LogService::info('Licence générée', [
                'user_id' => $userId,
                'plan' => $plan,
                'key_prefix' => substr($apiKeyData['api_key'], 0, 12) . '...',
            ]);
            
            return [
                'success' => true,
                'data' => [
                    'user_id' => $userId,
                    'plan' => $plan,
                    'expires_at' => $expiryDate,
                    'scopes' => $scopes,
                    // NE JAMAIS retourner la clé complète dans les logs!
                    'api_key_prefix' => substr($apiKeyData['api_key'], 0, 12) . '...'
                ],
                'message' => 'Licence générée avec succès'
            ];
            
        } catch (\Exception $e) {
            \AuthGroups\Services\LogService::error('Erreur génération licence', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            return Response::error('Erreur génération licence', $e->getMessage(), 500);
        }
    }
    
    /**
     * Calculer la date d'expiration selon le plan
     */
    private function calculateExpiry($plan) {
        switch ($plan) {
            case 'monthly': 
                return date('Y-m-d H:i:s', strtotime('+1 month'));
            case 'basic':
            case 'standard':
            case 'yearly': 
                return date('Y-m-d H:i:s', strtotime('+1 year'));
            case 'premium':
                return date('Y-m-d H:i:s', strtotime('+2 years'));
            case 'lifetime': 
                return null; // Jamais expirer
            default: 
                return date('Y-m-d H:i:s', strtotime('+1 year'));
        }
    }
    
    /**
     * Obtenir les scopes selon le plan
     */
    private function getScopesForPlan($plan) {
        switch ($plan) {
            case 'basic':
                return ['read'];
            case 'standard':
            case 'yearly':
            case 'monthly':
                return ['read', 'write'];
            case 'premium':
            case 'lifetime':
                return ['read', 'write', 'delete'];
            default:
                return ['read'];
        }
    }
    
    /**
     * Obtenir le rate limit selon le plan
     */
    private function getRateLimitForPlan($plan) {
        switch ($plan) {
            case 'basic': 
                return 60;
            case 'standard':
            case 'monthly':
            case 'yearly': 
                return 200;
            case 'premium': 
                return 500;
            case 'lifetime': 
                return 1000;
            default: 
                return 100;
        }
    }
    
    /**
     * Envoyer l'email avec la clé API
     */
    private function sendLicenseEmail($email, $name, $apiKey, $plan) {
        $emailService = new EmailService();
        
        $subject = "🎉 Votre licence CMEM est activée!";
        
        $body = "
        <h2>Bienvenue {$name}!</h2>
        <p>Merci d'avoir choisi notre service. Votre licence <strong>{$plan}</strong> est maintenant active.</p>
        
        <div style='background: #f5f5f5; padding: 20px; border-radius: 8px; margin: 20px 0;'>
            <h3>Votre clé API:</h3>
            <code style='background: #fff; padding: 10px; display: block; font-size: 14px;'>{$apiKey}</code>
        </div>
        
        <h3>Comment activer votre application:</h3>
        <ol>
            <li>Ouvrez l'application mobile</li>
            <li>Entrez votre ID utilisateur et cette clé API</li>
            <li>Profitez de toutes les fonctionnalités!</li>
        </ol>
        
        <p><strong>⚠️ Important:</strong> Conservez cette clé en lieu sûr. Ne la partagez avec personne.</p>
        
        <hr>
        <p style='color: #666; font-size: 12px;'>
            Support: support@cmem.com<br>
            Documentation: https://docs.cmem.com
        </p>
        ";
        
        try {
            $emailService->send($email, $subject, $body);
        } catch (\Exception $e) {
            \AuthGroups\Services\LogService::error('Erreur envoi email licence', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Vérifier et renouveler une licence
     */
    public function renewLicense($userId, $plan) {
        return $this->generateLicenseAfterPayment($userId, $plan);
    }
    
    /**
     * Révoquer une licence (annulation)
     */
    public function revokeLicense($userId, $reason = 'Cancelled by user') {
        try {
            $apiKeyModel = new ApiKey();
            $userModel = new User();
            
            // Révoquer toutes les API Keys de l'utilisateur
            $apiKeys = $apiKeyModel->getByUserId($userId);
            foreach ($apiKeys as $key) {
                $apiKeyModel->revoke($key['id'], $reason);
            }
            
            // Mettre à jour le statut utilisateur
            $userModel->update($userId, [
                'payment_status' => 'expired'
            ]);
            
            return Response::success(['message' => 'Licence révoquée']);
            
        } catch (\Exception $e) {
            return Response::error('Erreur révocation', $e->getMessage(), 500);
        }
    }
}
```

### 2.3 Handler de Webhooks

Le handler de webhooks a été créé dans `src/auth_groups/Routing/WebhookRouteHandler.php`.

**Endpoints disponibles :**

- `POST /webhook/payment` - Webhook générique
- `POST /webhook/stripe` - Webhook Stripe avec vérification de signature
- `POST /webhook/paypal` - Webhook PayPal avec vérification de signature

**Fichiers créés :**

1. ✅ `src/auth_groups/Routing/WebhookRouteHandler.php` - Handler principal
2. ✅ `src/auth_groups/Controllers/LicenseController.php` - Contrôleur de licences
3. ✅ `docs/WEBHOOKS_CONFIGURATION.md` - Documentation complète
4. ✅ `tests/test_webhooks.php` - Tests automatisés

**Configuration requise :**

Ajoutez ces variables dans `.env.auth_groups` :

```bash
# Stripe
STRIPE_WEBHOOK_SECRET=whsec_votre_secret_ici

# PayPal
PAYPAL_WEBHOOK_ID=votre_webhook_id_ici
```

**Voir la documentation complète :** `docs/WEBHOOKS_CONFIGURATION.md`

---

## 🔒 Partie 3 : Sécurité

### 3.1 Bonnes Pratiques

#### Côté Flutter:
1. **Stockage sécurisé** : `flutter_secure_storage` utilise:
   - Keychain (iOS)
   - Keystore (Android)

2. **Ne jamais exposer les clés dans le code**:
   ```dart
   // ❌ MAL
   const String API_KEY = 'ag_live_abc123';
   
   // ✅ BON
   final apiKey = await StorageService().getApiKey();
   ```

3. **HTTPS obligatoire en production**:
   ```dart
   static const String baseUrl = 'https://cmem1.journauxdebord.com'; // Pas HTTP!
   ```

#### Côté API:
1. **Ne jamais logger les API Keys complètes**:
   ```php
   // ❌ MAL
   LogService::info('API Key: ' . $apiKey);
   
   // ✅ BON
   LogService::info('Key prefix: ' . substr($apiKey, 0, 12) . '...');
   ```

2. **Vérifier les webhooks**:
   ```php
   // Vérifier la signature Stripe/PayPal
   if (!$this->verifyWebhookSignature($payload, $signature)) {
       return Response::error('Invalid signature', null, 401);
   }
   ```

3. **Rate limiting par plan**:
   ```php
   'rate_limit_per_minute' => $this->getRateLimitForPlan($plan)
   ```

### 3.2 Configuration CORS

Dans `config/environment.php` :

```php
// Autoriser l'application mobile (tous les domaines)
// OU restreindre aux domaines spécifiques
define('ALLOWED_ORIGINS', ['*']); // Pour mobile
// OU
define('ALLOWED_ORIGINS', ['https://app.cmem.com']);
```

---

## 📋 Partie 4 : Checklist de Déploiement

### ✅ Côté Flutter

- [ ] Installer les dépendances (`http`, `flutter_secure_storage`)
- [ ] Créer la structure de dossiers
- [ ] Implémenter `StorageService`
- [ ] Implémenter `ApiService`
- [ ] Implémenter `LicenseService`
- [ ] Créer `ActivationScreen`
- [ ] Créer `HomeScreen`
- [ ] Configurer `ApiConfig` avec l'URL de production
- [ ] Tester le flux complet
- [ ] Configurer les permissions Android/iOS pour secure storage
- [ ] Générer les builds release (APK/AAB pour Android, IPA pour iOS)

### ✅ Côté API

- [ ] Modifier la table `users` (ajouter colonnes payment)
- [ ] Créer `LicenseController`
- [ ] Ajouter les routes webhook
- [ ] Intégrer Stripe/PayPal SDK
- [ ] Configurer les webhooks chez Stripe/PayPal
- [ ] Configurer CORS pour mobile
- [ ] Tester la génération d'API Keys
- [ ] Configurer l'envoi d'emails (EmailService)
- [ ] Tester le flux de paiement end-to-end
- [ ] Configurer le monitoring des licences expirées

### ✅ Intégration Paiement

- [ ] Créer compte Stripe/PayPal
- [ ] Configurer les plans de paiement
- [ ] Implémenter le webhook
- [ ] Tester les paiements en mode sandbox
- [ ] Passer en mode production

---

## 🧪 Partie 5 : Tests

### 5.1 Test Manuel - Flutter

```bash
# 1. Lancer l'app
flutter run

# 2. Tester l'activation avec une clé de test
# User ID: 1
# API Key: ag_test_xxx (générer via l'API)

# 3. Vérifier le stockage
# L'app doit se souvenir de la clé après redémarrage
```

### 5.2 Test Manuel - API

```bash
# 1. Créer un utilisateur test
curl -X POST http://localhost/cmem2_API/users/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test User",
    "email": "test@example.com",
    "password": "Test123!"
  }'

# 2. Simuler un paiement (appeler directement le contrôleur)
curl -X POST http://localhost/cmem2_API/admin/generate-license \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 1,
    "plan": "standard"
  }'

# 3. Vérifier la clé générée (dans les logs ou l'email)

# 4. Tester la clé
curl -X GET http://localhost/cmem2_API/users/me \
  -H "X-API-Key: ag_live_xxx"
```

---

## 📊 Partie 6 : Plans de Paiement Suggérés

| Plan | Prix | Durée | Scopes | Rate Limit | Fonctionnalités |
|------|------|-------|--------|------------|-----------------|
| **Basic** | 9.99€/mois | 1 mois | read | 60/min | Lecture seule |
| **Standard** | 99€/an | 1 an | read, write | 200/min | Lecture + Écriture |
| **Premium** | 199€/an | 2 ans | read, write, delete | 500/min | Toutes fonctionnalités |
| **Lifetime** | 499€ | Illimité | read, write, delete | 1000/min | Accès à vie |

---

## 🚀 Partie 7 : Déploiement

### 7.1 Flutter

```bash
# Android
flutter build apk --release
flutter build appbundle --release

# iOS
flutter build ios --release

# Upload sur Google Play / App Store
```

### 7.2 API

```bash
# Déployer sur le serveur de production
# Configurer les variables d'environnement
# Activer HTTPS
# Configurer les webhooks Stripe/PayPal
```

---

## 📞 Support et Documentation

- **Documentation API**: https://cmem1.journauxdebord.com/docs
- **Support**: support@cmem.com
- **Webhook Stripe**: https://dashboard.stripe.com/webhooks
- **Webhook PayPal**: https://developer.paypal.com/dashboard/webhooks

---

## 📝 Notes Importantes

1. **Sécurité**: Les API Keys sont des données sensibles. Ne jamais les logger ou les exposer.
2. **Emails**: Utiliser un service d'email fiable (SendGrid, Mailgun, etc.)
3. **Monitoring**: Surveiller les licences expirées et envoyer des rappels
4. **Backup**: Sauvegarder régulièrement la table `api_keys`
5. **RGPD**: Permettre aux utilisateurs de supprimer leurs données

---

**Version**: 1.0.0  
**Date**: 8 octobre 2025  
**Auteur**: CMEM Team
