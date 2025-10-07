<?php

namespace AuthGroups\Services;

use Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use AuthGroups\Services\LogService;

/**
 * Service pour gérer l'envoi d'emails avec SMTP
 */
class EmailService {
    private $smtpHost;
    private $smtpPort;
    private $smtpUsername;
    private $smtpPassword;
    private $smtpSecure;
    private $fromEmail;
    private $fromName;
    private $isDevMode;
    private $useSMTP;
    private $apiStatus;
    private $db;
    
    public function __construct($database = null) {
        // Configuration depuis les variables d'environnement
        $this->smtpHost = $_ENV['SMTP_HOST'] ?? $_ENV['MAIL_HOST'] ?? 'localhost';
        $this->smtpPort = (int)($_ENV['SMTP_PORT'] ?? $_ENV['MAIL_PORT'] ?? 587);
        $this->smtpUsername = $_ENV['SMTP_USERNAME'] ?? $_ENV['MAIL_USERNAME'] ?? '';
        $this->smtpPassword = $_ENV['SMTP_PASSWORD'] ?? $_ENV['MAIL_PASSWORD'] ?? '';
        $this->smtpSecure = $_ENV['SMTP_SECURE'] ?? 'tls'; // tls, ssl, ou false
        $this->fromEmail = $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@authgroups.local';
        $this->fromName = $_ENV['MAIL_FROM_NAME'] ?? 'AuthGroups API';
        $this->isDevMode = ($_ENV['APP_ENV'] ?? 'production') === 'development';
        $this->useSMTP = $_ENV['USE_SMTP'] ?? 'true'; // true par défaut pour utiliser SMTP
        
        // Initialiser l'état de l'API et la base de données
        $this->db = $database;
        $this->apiStatus = $this->checkAPIStatus();
    }
    
    /**
     * Vérifier l'état de l'API et des services
     */
    private function checkAPIStatus() {
        $status = [
            'api_operational' => true,
            'database_connected' => false,
            'smtp_available' => false,
            'environment' => $this->isDevMode ? 'development' : 'production',
            'last_check' => date('Y-m-d H:i:s')
        ];
        
        // Test de connexion à la base de données
        if ($this->db) {
            try {
                $stmt = $this->db->query('SELECT 1');
                $status['database_connected'] = $stmt !== false;
            } catch (Exception $e) {
                $status['database_connected'] = false;
                LogService::error('EmailService: Échec de connexion à la base de données', [
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
        }
        
        // Test SMTP en mode rapide (ne teste la connexion qu'en production)
        if (!$this->isDevMode && $this->useSMTP === 'true') {
            $smtpTest = $this->testSMTPConnection();
            $status['smtp_available'] = $smtpTest['success'] ?? false;
        } else {
            $status['smtp_available'] = true; // En dev mode, toujours considéré comme disponible
        }
        
        $status['api_operational'] = $status['database_connected']; // API opérationnelle si DB connectée
        
        return $status;
    }
    
    /**
     * Obtenir l'état actuel de l'API
     */
    public function getAPIStatus() {
        if (empty($this->apiStatus)) {
            $this->apiStatus = $this->checkAPIStatus();
        }
        return $this->apiStatus;
    }
    
    /**
     * Rafraîchir l'état de l'API
     */
    public function refreshAPIStatus() {
        $this->apiStatus = $this->checkAPIStatus();
        return $this->apiStatus;
    }
    
    /**
     * Envoyer un email avec SMTP ou mode développement
     */
    public function sendEmail($to, $subject, $body, $isHtml = true) {
        try {
            // Validation de l'email
            if (!$this->isValidEmail($to)) {
                LogService::warning("EmailService: Tentative d'envoi vers une adresse email invalide", [
                    'invalid_email' => $to,
                    'subject' => $subject
                ]);
                return false;
            }
            
            LogService::info("EmailService: Début d'envoi d'email", [
                'to' => $to,
                'subject' => $subject,
                'method' => $this->isDevMode ? 'dev_log' : ($this->useSMTP === 'true' ? 'smtp' : 'mail_function')
            ]);
            
            if ($this->isDevMode) {
                // En développement, juste logger
                return $this->logEmail($to, $subject, $body);
            }
            
            if ($this->useSMTP === 'true') {
                // Utiliser PHPMailer avec SMTP
                return $this->sendViaSMTP($to, $subject, $body, $isHtml);
            } else {
                // Fallback vers la fonction mail() native
                return $this->sendViaMailFunction($to, $subject, $body, $isHtml);
            }
            
        } catch (Exception $e) {
            LogService::error('EmailService: Erreur lors de l\'envoi d\'email', [
                'to' => $to,
                'subject' => $subject,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return false;
        }
    }
    
    /**
     * Envoyer via SMTP avec PHPMailer
     */
    private function sendViaSMTP($to, $subject, $body, $isHtml = true) {
        try {
            $mail = new PHPMailer(true);
            
            // Configuration du serveur SMTP
            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->SMTPAuth = !empty($this->smtpUsername);
            $mail->Username = $this->smtpUsername;
            $mail->Password = $this->smtpPassword;
            $mail->Port = $this->smtpPort;
            
            // Configuration sécurité
            if ($this->smtpSecure === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($this->smtpSecure === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
            
            // Configuration pour développement local
            if ($this->smtpHost === 'localhost' || $this->smtpHost === '127.0.0.1') {
                $mail->SMTPAuth = false;
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            }
            
            // Expéditeur et destinataire
            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->addAddress($to);
            
            // Contenu
            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->CharSet = 'UTF-8';
            
            // Si texte HTML, créer version texte automatiquement
            if ($isHtml) {
                $mail->AltBody = strip_tags($body);
            }
            
            $result = $mail->send();
            
            if ($result) {
                LogService::info("EmailService: Email envoyé avec succès via SMTP", [
                    'to' => $to,
                    'subject' => $subject,
                    'smtp_host' => $this->smtpHost
                ]);
            }
            
            return $result;
            
        } catch (PHPMailerException $e) {
            LogService::error("EmailService: Erreur SMTP lors de l'envoi d'email", [
                'to' => $to,
                'subject' => $subject,
                'smtp_host' => $this->smtpHost,
                'smtp_port' => $this->smtpPort,
                'exception' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Envoyer via la fonction mail() native (fallback)
     */
    private function sendViaMailFunction($to, $subject, $body, $isHtml = true) {
        try {
            // Configuration des headers
            $headers = $this->buildHeaders($isHtml);
            
            // Envoyer l'email
            $result = mail($to, $subject, $body, implode("\r\n", $headers));
            
            if ($result) {
                LogService::info("EmailService: Email envoyé via mail() function", [
                    'to' => $to,
                    'subject' => $subject
                ]);
            }
            
            return $result;
            
        } catch (Exception $e) {
            LogService::error('EmailService: Erreur avec la fonction mail()', [
                'to' => $to,
                'subject' => $subject,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return false;
        }
    }
    
    /**
     * Envoyer un email d'invitation de groupe
     */
    public function sendGroupInvitation($email, $groupName, $inviterName, $role, $inviteUrl) {
        LogService::info("EmailService: Envoi d'invitation de groupe", [
            'email' => $email,
            'group_name' => $groupName,
            'inviter_name' => $inviterName,
            'role' => $role
        ]);
        
        $subject = "Invitation à rejoindre le groupe: " . $groupName;
        
        $body = $this->buildGroupInvitationTemplate([
            'groupName' => $groupName,
            'inviterName' => $inviterName,
            'inviteUrl' => $inviteUrl,
            'email' => $email,
            'role' => $role
        ]);
        
        $result = $this->sendEmail($email, $subject, $body, true);
        
        if ($result) {
            LogService::info("EmailService: Invitation de groupe envoyée avec succès", [
                'email' => $email,
                'group_name' => $groupName
            ]);
        } else {
            LogService::error("EmailService: Échec d'envoi d'invitation de groupe", [
                'email' => $email,
                'group_name' => $groupName
            ]);
        }
        
        return $result;
    }
    
    /**
     * Envoyer un email de bienvenue
     */
    public function sendWelcomeEmail($email, $username) {
        LogService::info("EmailService: Envoi d'email de bienvenue", [
            'email' => $email,
            'username' => $username
        ]);
        
        $subject = "Bienvenue sur AuthGroups API !";
        
        $body = $this->buildWelcomeTemplate([
            'username' => $username,
            'email' => $email,
            'loginUrl' => $_ENV['APP_URL'] . '/login'
        ]);
        
        $result = $this->sendEmail($email, $subject, $body, true);
        
        if ($result) {
            LogService::info("EmailService: Email de bienvenue envoyé avec succès", [
                'email' => $email,
                'username' => $username
            ]);
        } else {
            LogService::error("EmailService: Échec d'envoi d'email de bienvenue", [
                'email' => $email,
                'username' => $username
            ]);
        }
        
        return $result;
    }
    
    /**
     * Envoyer un email de réinitialisation de mot de passe
     */
    public function sendPasswordReset($email, $resetToken) {
        LogService::warning("EmailService: Demande de réinitialisation de mot de passe", [
            'email' => $email,
            'token_length' => strlen($resetToken)
        ]);
        
        $subject = "Réinitialisation de votre mot de passe";
        
        $resetUrl = $_ENV['APP_URL'] . "/reset-password?token=" . $resetToken;
        
        $body = $this->buildPasswordResetTemplate([
            'email' => $email,
            'resetUrl' => $resetUrl
        ]);
        
        $result = $this->sendEmail($email, $subject, $body, true);
        
        if ($result) {
            LogService::info("EmailService: Email de réinitialisation envoyé avec succès", [
                'email' => $email
            ]);
        } else {
            LogService::error("EmailService: Échec d'envoi d'email de réinitialisation", [
                'email' => $email
            ]);
        }
        
        return $result;
    }
    
    /**
     * Envoyer une notification de nouvelle mémoire dans un groupe
     */
    public function sendNewMemoryNotification($email, $groupName, $memoryTitle, $authorName) {
        $subject = "Nouvelle mémoire dans " . $groupName;
        
        $body = $this->buildNewMemoryTemplate([
            'groupName' => $groupName,
            'memoryTitle' => $memoryTitle,
            'authorName' => $authorName,
            'groupUrl' => $_ENV['APP_URL'] . '/groups'
        ]);
        
        return $this->sendEmail($email, $subject, $body, true);
    }
    
    public function send2AFCode($email, $code){
        LogService::info("EmailService: Envoi de code 2AF", [
            'email' => $email,
            'code_length' => strlen($code)
        ]);
        
        $result = $this->sendEmail($email, "Code de connexion 2AF", "Votre code de connexion est : {$code}", true);
        
        if ($result) {
            LogService::info("EmailService: Code 2AF envoyé avec succès", [
                'email' => $email
            ]);
        } else {
            LogService::error("EmailService: Échec d'envoi de code 2AF", [
                'email' => $email
            ]);
        }
        
        return $result;
    }

    /**
     * Envoyer un email de vérification d'adresse email
     */
    public function sendEmailVerification($email, $username, $verificationToken) {
        $subject = "Vérifiez votre adresse email - AuthGroups API";
        
        $verificationUrl = APP_URL . "/verify-email?token=" . $verificationToken;
        
        $body = $this->buildEmailVerificationTemplate([
            'username' => $username,
            'email' => $email,
            'verificationUrl' => $verificationUrl
        ]);
        
        return $this->sendEmail($email, $subject, $body, true);
    }
    
    /**
     * Envoyer une notification de changement de rôle dans un groupe
     */
    public function sendRoleChangeNotification($email, $username, $groupName, $newRole, $changedBy) {
        $subject = "Votre rôle a été modifié dans " . $groupName;
        
        $body = $this->buildRoleChangeTemplate([
            'username' => $username,
            'groupName' => $groupName,
            'newRole' => $newRole,
            'changedBy' => $changedBy,
            'groupUrl' => $_ENV['APP_URL'] . '/groups'
        ]);
        
        return $this->sendEmail($email, $subject, $body, true);
    }
    
    /**
     * Envoyer une notification lorsqu'un utilisateur rejoint un groupe
     */
    public function sendMemberJoinedNotification($email, $groupName, $newMemberName, $role) {
        $subject = "Nouveau membre dans " . $groupName;
        
        $body = $this->buildMemberJoinedTemplate([
            'groupName' => $groupName,
            'newMemberName' => $newMemberName,
            'role' => $role,
            'groupUrl' => $_ENV['APP_URL'] . '/groups'
        ]);
        
        return $this->sendEmail($email, $subject, $body, true);
    }
    
    /**
     * Envoyer un rapport d'activité périodique
     */
    public function sendActivityDigest($email, $username, $digestData) {
        $subject = "Votre résumé d'activité - AuthGroups API";
        
        $body = $this->buildActivityDigestTemplate([
            'username' => $username,
            'digestData' => $digestData,
            'period' => $digestData['period'] ?? 'cette semaine',
            'appUrl' => $_ENV['APP_URL']
        ]);
        
        return $this->sendEmail($email, $subject, $body, true);
    }
    
    /**
     * Envoyer une alerte d'activité suspecte
     */
    public function sendSecurityAlert($email, $username, $alertData) {
        LogService::critical("EmailService: Envoi d'alerte de sécurité", [
            'email' => $email,
            'username' => $username,
            'alert_type' => $alertData['type'] ?? 'unknown',
            'source_ip' => $alertData['ip'] ?? 'unknown'
        ]);
        
        $subject = "Alerte de sécurité - AuthGroups API";
        
        $body = $this->buildSecurityAlertTemplate([
            'username' => $username,
            'alertData' => $alertData,
            'timestamp' => date('Y-m-d H:i:s'),
            'supportUrl' => $_ENV['APP_URL'] . '/support'
        ]);
        
        $result = $this->sendEmail($email, $subject, $body, true);
        
        if ($result) {
            LogService::info("EmailService: Alerte de sécurité envoyée avec succès", [
                'email' => $email,
                'alert_type' => $alertData['type'] ?? 'unknown'
            ]);
        } else {
            LogService::error("EmailService: Échec d'envoi d'alerte de sécurité", [
                'email' => $email,
                'alert_type' => $alertData['type'] ?? 'unknown'
            ]);
        }
        
        return $result;
    }



    /**
     * Template pour invitation de groupe
     */
    private function buildGroupInvitationTemplate($data) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Invitation au groupe</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #4CAF50; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .button { display: inline-block; background-color: #4CAF50; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 15px 0; }
                .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Invitation au groupe</h1>
                </div>
                <div class='content'>
                    <h2>Vous êtes invité(e) à rejoindre un groupe !</h2>
                    <p><strong>{$data['inviterName']}</strong> vous invite à rejoindre le groupe <strong>{$data['groupName']}</strong> à titre de <strong>{$data['role']}</strong>.</p>
                    <p>Partagez vos souvenirs et créez de nouvelles mémoires ensemble !</p>
                    <p style='text-align: center;'>
                        <a href='{$data['inviteUrl']}' class='button'>Accepter l'invitation</a>
                    </p>
                    <p>Ou copiez ce lien dans votre navigateur :</p>
                    <p style='word-break: break-all; background: #fff; padding: 10px; border: 1px solid #ddd;'>{$data['inviteUrl']}</p>
                </div>
                <div class='footer'>
                    <p>Cette invitation expire dans 7 jours.</p>
                    <p>Si vous n'êtes pas {$data['email']}, ignorez cet email.</p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Template pour email de bienvenue
     */
    private function buildWelcomeTemplate($data) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Bienvenue !</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #2196F3; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .button { display: inline-block; background-color: #2196F3; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Bienvenue sur AuthGroups API !</h1>
                </div>
                <div class='content'>
                    <h2>Bonjour {$data['username']} !</h2>
                    <p>Votre compte a été créé avec succès. Vous pouvez maintenant :</p>
                    <ul>
                        <li>Créer vos premières mémoires</li>
                        <li>Rejoindre des groupes</li>
                        <li>Partager vos souvenirs</li>
                        <li>Inviter vos proches</li>
                    </ul>
                    <p style='text-align: center;'>
                        <a href='{$data['loginUrl']}' class='button'>Se connecter</a>
                    </p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Template pour réinitialisation de mot de passe
     */
    private function buildPasswordResetTemplate($data) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Réinitialisation mot de passe</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #FF9800; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .button { display: inline-block; background-color: #FF9800; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Réinitialisation de mot de passe</h1>
                </div>
                <div class='content'>
                    <h2>Demande de réinitialisation</h2>
                    <p>Une demande de réinitialisation de mot de passe a été effectuée pour {$data['email']}.</p>
                    <p>Cliquez sur le bouton ci-dessous pour créer un nouveau mot de passe :</p>
                    <p style='text-align: center;'>
                        <a href='{$data['resetUrl']}' class='button'>Réinitialiser le mot de passe</a>
                    </p>
                    <p>Ce lien expire dans 1 heure pour des raisons de sécurité.</p>
                    <p><strong>Si vous n'avez pas demandé cette réinitialisation, ignorez cet email.</strong></p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Template pour notification de nouvelle mémoire
     */
    private function buildNewMemoryTemplate($data) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Nouvelle mémoire</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #9C27B0; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .button { display: inline-block; background-color: #9C27B0; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Nouvelle mémoire partagée</h1>
                </div>
                <div class='content'>
                    <h2>{$data['memoryTitle']}</h2>
                    <p><strong>{$data['authorName']}</strong> a partagé une nouvelle mémoire dans le groupe <strong>{$data['groupName']}</strong>.</p>
                    <p>Découvrez cette nouvelle mémoire et partagez vos réactions !</p>
                    <p style='text-align: center;'>
                        <a href='{$data['groupUrl']}' class='button'>Voir le groupe</a>
                    </p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Template pour vérification d'email
     */
    private function buildEmailVerificationTemplate($data) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Vérification d'email</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #00BCD4; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .button { display: inline-block; background-color: #00BCD4; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 15px 0; }
                .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🔐 Vérification d'email</h1>
                </div>
                <div class='content'>
                    <h2>Bonjour {$data['username']} !</h2>
                    <p>Merci de confirmer votre adresse email pour activer votre compte AuthGroups API.</p>
                    <p style='text-align: center;'>
                        <a href='{$data['verificationUrl']}' class='button'>Vérifier mon email</a>
                    </p>
                    <p>Ou copiez ce lien dans votre navigateur :</p>
                    <p style='word-break: break-all; background: #fff; padding: 10px; border: 1px solid #ddd;'>{$data['verificationUrl']}</p>
                </div>
                <div class='footer'>
                    <p>Ce lien expire dans 24 heures.</p>
                    <p>Si vous n'avez pas créé de compte, ignorez cet email.</p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Template pour changement de rôle
     */
    private function buildRoleChangeTemplate($data) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Changement de rôle</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #FF5722; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .button { display: inline-block; background-color: #FF5722; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 15px 0; }
                .role-badge { background: #fff; padding: 10px; border-left: 4px solid #FF5722; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>👤 Changement de rôle</h1>
                </div>
                <div class='content'>
                    <h2>Bonjour {$data['username']} !</h2>
                    <p>Votre rôle dans le groupe <strong>{$data['groupName']}</strong> a été modifié par <strong>{$data['changedBy']}</strong>.</p>
                    <div class='role-badge'>
                        <strong>Nouveau rôle :</strong> {$data['newRole']}
                    </div>
                    <p style='text-align: center;'>
                        <a href='{$data['groupUrl']}' class='button'>Voir le groupe</a>
                    </p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Template pour notification de nouveau membre
     */
    private function buildMemberJoinedTemplate($data) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Nouveau membre</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #8BC34A; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .button { display: inline-block; background-color: #8BC34A; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 15px 0; }
                .member-info { background: #fff; padding: 15px; border: 1px solid #ddd; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🎉 Nouveau membre !</h1>
                </div>
                <div class='content'>
                    <h2>Bonne nouvelle !</h2>
                    <p><strong>{$data['newMemberName']}</strong> a rejoint le groupe <strong>{$data['groupName']}</strong>.</p>
                    <div class='member-info'>
                        <strong>Rôle :</strong> {$data['role']}
                    </div>
                    <p>Souhaitez-lui la bienvenue et partagez vos plus belles mémoires ensemble !</p>
                    <p style='text-align: center;'>
                        <a href='{$data['groupUrl']}' class='button'>Voir le groupe</a>
                    </p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Template pour résumé d'activité
     */
    private function buildActivityDigestTemplate($data) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Résumé d'activité</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #673AB7; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .button { display: inline-block; background-color: #673AB7; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 15px 0; }
                .stats { background: #fff; padding: 15px; border: 1px solid #ddd; margin: 15px 0; }
                .stat-item { margin: 10px 0; padding: 8px; background: #f5f5f5; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>📊 Votre activité {$data['period']}</h1>
                </div>
                <div class='content'>
                    <h2>Bonjour {$data['username']} !</h2>
                    <p>Voici un résumé de votre activité sur AuthGroups API :</p>
                    <div class='stats'>
                        <div class='stat-item'> <strong>" . (isset($data['digestData']['groups_joined']) ? $data['digestData']['groups_joined'] : 0) . "</strong> nouveaux groupes rejoints</div>
                        <div class='stat-item'>💬 <strong>" . (isset($data['digestData']['interactions']) ? $data['digestData']['interactions'] : 0) . "</strong> interactions</div>
                    </div>
                    <p style='text-align: center;'>
                        <a href='{$data['appUrl']}' class='button'>Voir l'application</a>
                    </p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Template pour alerte de sécurité
     */
    private function buildSecurityAlertTemplate($data) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Alerte de sécurité</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #F44336; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .button { display: inline-block; background-color: #F44336; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 15px 0; }
                .alert { background: #ffebee; border-left: 4px solid #F44336; padding: 15px; margin: 15px 0; }
                .security-info { background: #fff; padding: 15px; border: 1px solid #ddd; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🚨 Alerte de sécurité</h1>
                </div>
                <div class='content'>
                    <h2>Bonjour {$data['username']},</h2>
                    <div class='alert'>
                        <strong>Activité suspecte détectée</strong> sur votre compte à {$data['timestamp']}.
                    </div>
                    <div class='security-info'>
                        <p><strong>Type d'activité :</strong> " . (isset($data['alertData']['type']) ? $data['alertData']['type'] : 'Activité inhabituelle') . "</p>
                        <p><strong>Adresse IP :</strong> " . (isset($data['alertData']['ip']) ? $data['alertData']['ip'] : 'Non disponible') . "</p>
                        <p><strong>Localisation :</strong> " . (isset($data['alertData']['location']) ? $data['alertData']['location'] : 'Non disponible') . "</p>
                    </div>
                    <p>Si cette activité ne vous semble pas familière, changez immédiatement votre mot de passe.</p>
                    <p style='text-align: center;'>
                        <a href='{$data['supportUrl']}' class='button'>Contacter le support</a>
                    </p>
                </div>
            </div>
        </body>
        </html>";
    }

    /**
     * Construire les headers d'email
     */
    private function buildHeaders($isHtml = true) {
        $headers = [
            'From: ' . $this->fromName . ' <' . $this->fromEmail . '>',
            'Reply-To: ' . $this->fromEmail,
            'X-Mailer: PHP/' . phpversion()
        ];
        
        if ($isHtml) {
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-type: text/html; charset=UTF-8';
        }
        
        return $headers;
    }
    
    /**
     * Logger un email en développement
     */
    private function logEmail($to, $subject, $body) {
        LogService::info("EmailService: Email simulé en mode développement", [
            'to' => $to,
            'subject' => $subject,
            'body_length' => strlen($body),
            'mode' => 'development'
        ]);
        
        return true;
    }
    
    /**
     * Valider une adresse email
     */
    public function isValidEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Envoyer des emails en lot (pour les notifications)
     */
    public function sendBulkEmails($emails, $subject, $body, $isHtml = true) {
        LogService::info("EmailService: Début d'envoi en lot", [
            'email_count' => count($emails),
            'subject' => $subject
        ]);
        
        $results = [];
        $successCount = 0;
        
        foreach ($emails as $email) {
            if ($this->isValidEmail($email)) {
                $results[$email] = $this->sendEmail($email, $subject, $body, $isHtml);
                if ($results[$email]) {
                    $successCount++;
                }
            } else {
                $results[$email] = false;
                LogService::warning("EmailService: Email invalide dans l'envoi en lot", [
                    'invalid_email' => $email,
                    'subject' => $subject
                ]);
            }
        }
        
        LogService::info("EmailService: Envoi en lot terminé", [
            'total_emails' => count($emails),
            'success_count' => $successCount,
            'failure_count' => count($emails) - $successCount,
            'subject' => $subject
        ]);
        
        return $results;
    }
    
    /**
     * Tester la configuration SMTP
     */
    public function testSMTPConnection() {
        try {
            // En mode développement, retourner un test simulé
            if ($this->isDevMode) {
                return [
                    'success' => true,
                    'message' => 'Mode développement - test simulé',
                    'config' => [
                        'host' => $this->smtpHost,
                        'port' => $this->smtpPort,
                        'secure' => $this->smtpSecure,
                        'auth' => !empty($this->smtpUsername),
                        'mode' => 'development'
                    ]
                ];
            }
            
            $mail = new PHPMailer(true);
            
            // Configuration du serveur SMTP
            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->SMTPAuth = !empty($this->smtpUsername);
            $mail->Username = $this->smtpUsername;
            $mail->Password = $this->smtpPassword;
            $mail->Port = $this->smtpPort;
            $mail->Timeout = 10; // Timeout de 10 secondes
            
            // Configuration sécurité
            if ($this->smtpSecure === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($this->smtpSecure === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
            
            // Configuration pour développement local
            if ($this->smtpHost === 'localhost' || $this->smtpHost === '127.0.0.1') {
                $mail->SMTPAuth = false;
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            }
            
            // Test de connexion uniquement
            $mail->SMTPDebug = 0; // Pas de debug
            $result = $mail->smtpConnect();
            
            if ($result) {
                $mail->smtpClose();
                return [
                    'success' => true,
                    'message' => 'Connexion SMTP réussie',
                    'config' => [
                        'host' => $this->smtpHost,
                        'port' => $this->smtpPort,
                        'secure' => $this->smtpSecure,
                        'auth' => !empty($this->smtpUsername)
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Impossible de se connecter au serveur SMTP',
                    'config' => [
                        'host' => $this->smtpHost,
                        'port' => $this->smtpPort,
                        'secure' => $this->smtpSecure,
                        'auth' => !empty($this->smtpUsername)
                    ]
                ];
            }
            
        } catch (PHPMailerException $e) {
            return [
                'success' => false,
                'message' => 'Erreur SMTP: ' . $e->getMessage(),
                'config' => [
                    'host' => $this->smtpHost,
                    'port' => $this->smtpPort,
                    'secure' => $this->smtpSecure,
                    'auth' => !empty($this->smtpUsername)
                ]
            ];
        }
    }
    
    /**
     * Envoyer un email de test
     */
    public function sendTestEmail($to = null) {
        $testEmail = $to ?? $this->fromEmail;
        
        $subject = 'Test SMTP - AuthGroups API';
        $body = $this->buildTestEmailTemplate([
            'timestamp' => date('Y-m-d H:i:s'),
            'config' => [
                'host' => $this->smtpHost,
                'port' => $this->smtpPort,
                'secure' => $this->smtpSecure,
                'from' => $this->fromEmail
            ]
        ]);
        
        return $this->sendEmail($testEmail, $subject, $body, true);
    }
    
    /**
     * Envoyer une notification de maintenance programmée
     */
    public function sendMaintenanceNotification($emails, $maintenanceData) {
        $subject = "Maintenance programmée - AuthGroups API";
        
        $body = $this->buildMaintenanceTemplate([
            'startTime' => $maintenanceData['start_time'],
            'duration' => $maintenanceData['duration'],
            'reason' => $maintenanceData['reason'] ?? 'Amélioration des services',
            'appUrl' => $_ENV['APP_URL']
        ]);
        
        if (is_array($emails)) {
            return $this->sendBulkEmails($emails, $subject, $body, true);
        } else {
            return $this->sendEmail($emails, $subject, $body, true);
        }
    }
    
    /**
     * Envoyer un email de confirmation d'action critique
     */
    public function sendActionConfirmation($email, $username, $action, $confirmationUrl) {
        $subject = "Confirmation d'action requise - AuthGroups API";
        
        $body = $this->buildActionConfirmationTemplate([
            'username' => $username,
            'action' => $action,
            'confirmationUrl' => $confirmationUrl,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        return $this->sendEmail($email, $subject, $body, true);
    }
    
    /**
     * Vérifier si les emails peuvent être envoyés (API opérationnelle)
     */
    public function canSendEmails() {
        $status = $this->getAPIStatus();
        
        // En mode développement, toujours autorisé
        if ($this->isDevMode) {
            return true;
        }
        
        // En production, vérifier l'état des services
        return $status['api_operational'] && $status['smtp_available'];
    }
    
    /**
     * Envoyer un email avec vérification préalable de l'état de l'API
     */
    public function sendEmailSafely($to, $subject, $body, $isHtml = true) {
        if (!$this->canSendEmails()) {
            LogService::error("EmailService: Impossible d'envoyer l'email - API non opérationnelle", [
                'to' => $to,
                'subject' => $subject,
                'api_status' => $this->getAPIStatus()
            ]);
            return false;
        }
        
        return $this->sendEmail($to, $subject, $body, $isHtml);
    }
    
    /**
     * Template pour maintenance programmée
     */
    private function buildMaintenanceTemplate($data) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Maintenance programmée</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #FF9800; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .maintenance-info { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 15px 0; }
                .button { display: inline-block; background-color: #FF9800; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🔧 Maintenance programmée</h1>
                </div>
                <div class='content'>
                    <h2>Information importante</h2>
                    <p>Nous effectuerons une maintenance de AuthGroups API selon les détails suivants :</p>
                    <div class='maintenance-info'>
                        <p><strong>📅 Début :</strong> {$data['startTime']}</p>
                        <p><strong>⏱️ Durée estimée :</strong> {$data['duration']}</p>
                        <p><strong>🎯 Objectif :</strong> {$data['reason']}</p>
                    </div>
                    <p>Durant cette période, l'application sera temporairement indisponible.</p>
                    <p>Merci de votre compréhension !</p>
                    <p style='text-align: center;'>
                        <a href='{$data['appUrl']}' class='button'>Accéder à l'application</a>
                    </p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Template pour confirmation d'action
     */
    private function buildActionConfirmationTemplate($data) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Confirmation d'action</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #2196F3; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .action-info { background: #e3f2fd; border-left: 4px solid #2196F3; padding: 15px; margin: 15px 0; }
                .button { display: inline-block; background-color: #2196F3; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>✅ Confirmation requise</h1>
                </div>
                <div class='content'>
                    <h2>Bonjour {$data['username']},</h2>
                    <p>Une confirmation est nécessaire pour l'action suivante :</p>
                    <div class='action-info'>
                        <strong>Action :</strong> {$data['action']}<br>
                        <strong>Demandée le :</strong> {$data['timestamp']}
                    </div>
                    <p>Cliquez sur le bouton ci-dessous pour confirmer cette action :</p>
                    <p style='text-align: center;'>
                        <a href='{$data['confirmationUrl']}' class='button'>Confirmer l'action</a>
                    </p>
                    <p><small>Ce lien expire dans 24 heures. Si vous n'avez pas demandé cette action, ignorez cet email.</small></p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Template pour email de test
     */
    private function buildTestEmailTemplate($data) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Test SMTP</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #607D8B; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .config { background: #fff; padding: 15px; border: 1px solid #ddd; margin: 10px 0; }
                .success { color: #4CAF50; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🚀 Test SMTP Réussi</h1>
                </div>
                <div class='content'>
                    <p class='success'>✅ Votre configuration SMTP fonctionne correctement !</p>
                    <p>Cet email a été envoyé le <strong>{$data['timestamp']}</strong></p>
                    
                    <h3>Configuration utilisée :</h3>
                    <div class='config'>
                        <p><strong>Serveur SMTP :</strong> {$data['config']['host']}:{$data['config']['port']}</p>
                        <p><strong>Sécurité :</strong> {$data['config']['secure']}</p>
                        <p><strong>Expéditeur :</strong> {$data['config']['from']}</p>
                    </div>
                    
                    <p>Votre service EmailService est maintenant prêt à envoyer des emails professionnels.</p>
                </div>
            </div>
        </body>
        </html>";
    }
}
