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
     * Envoyer un email d'inscription avec API key gratuite et invitation aux plans
     */
    public function sendRegistrationWithApiKeyAndPlanInvitation($email, $username, $verificationToken, $apiKey, $planInvitationToken) {
        LogService::info("EmailService: Envoi email inscription avec API key et invitation plan", [
            'email' => $email,
            'username' => $username,
            'has_api_key' => !empty($apiKey)
        ]);
        
        $subject = "🎉 Bienvenue sur AuthGroups API - Votre clé gratuite et invitation aux plans premium";
        
        $verificationUrl = $_ENV['APP_URL'] . '/users/verify-email?token=' . $verificationToken;
        $planInvitationUrl = $_ENV['APP_URL'] . '/users/choose-plan?token=' . $planInvitationToken;
        
        $body = $this->buildRegistrationWithApiKeyTemplate([
            'username' => $username,
            'email' => $email,
            'apiKey' => $apiKey,
            'verificationUrl' => $verificationUrl,
            'planInvitationUrl' => $planInvitationUrl,
            'planInvitationToken' => $planInvitationToken
        ]);
        
        $result = $this->sendEmail($email, $subject, $body, true);
        
        if ($result) {
            LogService::info("EmailService: Email d'inscription avec API key envoyé avec succès", [
                'email' => $email
            ]);
        } else {
            LogService::error("EmailService: Échec d'envoi email d'inscription avec API key", [
                'email' => $email
            ]);
        }
        
        return $result;
    }
    
    /**
     * Envoyer un email de félicitations après confirmation avec rappel des plans
     */
    public function sendEmailConfirmedWithPlanReminder($email, $username, $planInvitationToken, $extendedDays) {
        LogService::info("EmailService: Envoi email félicitations avec rappel plans", [
            'email' => $email,
            'username' => $username,
            'extended_days' => $extendedDays
        ]);
        
        $subject = "🎉 Email confirmé ! Votre plan gratuit est étendu + Invitation premium";
        
        $planInvitationUrl = $_ENV['APP_URL'] . '/users/choose-plan?token=' . $planInvitationToken;
        
        $body = $this->buildEmailConfirmedWithPlanReminderTemplate([
            'username' => $username,
            'email' => $email,
            'extendedDays' => $extendedDays,
            'planInvitationUrl' => $planInvitationUrl,
            'planInvitationToken' => $planInvitationToken
        ]);
        
        $result = $this->sendEmail($email, $subject, $body, true);
        
        if ($result) {
            LogService::info("EmailService: Email félicitations avec rappel plans envoyé avec succès", [
                'email' => $email
            ]);
        } else {
            LogService::error("EmailService: Échec d'envoi email félicitations", [
                'email' => $email
            ]);
        }
        
        return $result;
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
     * Template pour email d'inscription avec API key gratuite et invitation plans
     */
    private function buildRegistrationWithApiKeyTemplate($data) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Bienvenue sur AuthGroups API</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 700px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #00BCD4, #4CAF50); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { padding: 30px; background-color: #f9f9f9; }
                .api-key-box { 
                    background: #e3f2fd; 
                    border: 2px solid #2196F3; 
                    padding: 20px; 
                    margin: 20px 0; 
                    border-radius: 8px;
                    font-family: monospace;
                    word-break: break-all;
                }
                .plan-section { 
                    background: #fff3e0; 
                    border-left: 4px solid #FF9800; 
                    padding: 20px; 
                    margin: 20px 0; 
                    border-radius: 0 8px 8px 0;
                }
                .plan-grid {
                    display: grid;
                    grid-template-columns: repeat(3, 1fr);
                    gap: 15px;
                    margin: 20px 0;
                }
                .plan-card {
                    background: white;
                    border: 2px solid #ddd;
                    padding: 15px;
                    text-align: center;
                    border-radius: 8px;
                    transition: all 0.3s ease;
                }
                .plan-bronze { border-color: #CD7F32; }
                .plan-argent { border-color: #C0C0C0; }
                .plan-platine { border-color: #E5E4E2; }
                .button { 
                    display: inline-block; 
                    background: linear-gradient(135deg, #4CAF50, #45a049); 
                    color: white; 
                    padding: 15px 30px; 
                    text-decoration: none; 
                    border-radius: 25px; 
                    margin: 15px 10px;
                    font-weight: bold;
                    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
                }
                .button-secondary {
                    background: linear-gradient(135deg, #FF9800, #F57C00);
                }
                .warning { 
                    background: #fff3cd; 
                    border: 1px solid #ffeaa7; 
                    padding: 15px; 
                    border-radius: 5px; 
                    margin: 15px 0; 
                    color: #856404;
                }
                .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
                .highlight { background: #ffeb3b; padding: 2px 6px; border-radius: 3px; }
                .steps {
                    counter-reset: step-counter;
                    list-style: none;
                    padding: 0;
                }
                .steps li {
                    counter-increment: step-counter;
                    margin: 15px 0;
                    padding: 15px;
                    background: white;
                    border-left: 4px solid #4CAF50;
                    border-radius: 0 8px 8px 0;
                    position: relative;
                }
                .steps li::before {
                    content: counter(step-counter);
                    position: absolute;
                    left: -20px;
                    top: 15px;
                    background: #4CAF50;
                    color: white;
                    width: 30px;
                    height: 30px;
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-weight: bold;
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🎉 Bienvenue {$data['username']} !</h1>
                    <p>Votre compte AuthGroups API est créé avec succès</p>
                </div>
                <div class='content'>
                    <h2>🚀 Commencez immédiatement avec votre clé API gratuite</h2>
                    <p>Nous avons généré pour vous une <strong>clé API gratuite</strong> pour commencer à utiliser notre service immédiatement :</p>
                    
                    <div class='api-key-box'>
                        <h3 style='margin-top: 0; color: #2196F3;'>🔑 Votre clé API gratuite :</h3>
                        <code style='font-size: 16px; font-weight: bold; color: #1976D2;'>{$data['apiKey']}</code>
                        <p style='margin-bottom: 0; font-size: 14px; color: #666;'>
                            <strong>⚠️ Sauvegardez cette clé maintenant !</strong> Elle ne sera plus jamais affichée.
                        </p>
                    </div>
                    
                    <div class='warning'>
                        <strong>🕐 Limitations du plan gratuit :</strong>
                        <ul>
                            <li>📊 <strong>10 requêtes/minute</strong> maximum</li>
                            <li>📖 <strong>Lecture seule</strong> (scope: read)</li>
                            <li>⏰ <strong>Expire dans 7 jours</strong></li>
                            <li>🎯 Parfait pour tester notre API !</li>
                        </ul>
                    </div>
                    
                    <h2>💎 Passez à un plan premium pour plus de fonctionnalités</h2>
                    <div class='plan-section'>
                        <p>Débloquez tout le potentiel de l'API avec nos plans premium :</p>
                        
                        <div class='plan-grid'>
                            <div class='plan-card plan-bronze'>
                                <h4>🥉 Bronze</h4>
                                <div style='font-size: 20px; font-weight: bold; color: #CD7F32;'>9.99€/mois</div>
                                <ul style='text-align: left; font-size: 14px;'>
                                    <li>100 req/min</li>
                                    <li>Lecture + Écriture</li>
                                    <li>Support email</li>
                                </ul>
                            </div>
                            <div class='plan-card plan-argent'>
                                <h4>🥈 Argent</h4>
                                <div style='font-size: 20px; font-weight: bold; color: #C0C0C0;'>19.99€/mois</div>
                                <ul style='text-align: left; font-size: 14px;'>
                                    <li>300 req/min</li>
                                    <li>Toutes opérations</li>
                                    <li>Support prioritaire</li>
                                    <li>Webhooks</li>
                                </ul>
                            </div>
                            <div class='plan-card plan-platine'>
                                <h4>🏆 Platine</h4>
                                <div style='font-size: 20px; font-weight: bold; color: #E5E4E2;'>49.99€/mois</div>
                                <ul style='text-align: left; font-size: 14px;'>
                                    <li>1000 req/min</li>
                                    <li>Accès admin</li>
                                    <li>Support dédié</li>
                                    <li>Intégrations custom</li>
                                </ul>
                            </div>
                        </div>
                        
                        <p style='text-align: center;'>
                            <a href='{$data['planInvitationUrl']}' class='button button-secondary'>
                                🎯 Choisir mon plan premium
                            </a>
                        </p>
                    </div>
                    
                    <h2>📋 Prochaines étapes</h2>
                    <ol class='steps'>
                        <li>
                            <strong>Confirmez votre email</strong><br>
                            <span style='color: #666;'>Cliquez sur le bouton ci-dessous pour activer votre compte</span>
                        </li>
                        <li>
                            <strong>Testez votre API key gratuite</strong><br>
                            <span style='color: #666;'>Utilisez votre clé pour faire vos premiers appels API</span>
                        </li>
                        <li>
                            <strong>Choisissez un plan premium</strong><br>
                            <span style='color: #666;'>Débloquez toutes les fonctionnalités avant expiration</span>
                        </li>
                    </ol>
                    
                    <p style='text-align: center; margin: 40px 0;'>
                        <a href='{$data['verificationUrl']}' class='button'>
                            ✅ Confirmer mon email
                        </a>
                        <a href='{$data['planInvitationUrl']}' class='button button-secondary'>
                            💎 Voir les plans premium
                        </a>
                    </p>
                    
                    <h3>📖 Documentation et support</h3>
                    <p>
                        • <strong>Documentation API :</strong> <a href='{$_ENV['APP_URL']}/docs'>Guide complet</a><br>
                        • <strong>Exemples de code :</strong> <a href='{$_ENV['APP_URL']}/examples'>Démarrage rapide</a><br>
                        • <strong>Support :</strong> <a href='mailto:support@authgroups.com'>support@authgroups.com</a>
                    </p>
                    
                    <div style='background: #e8f5e8; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                        <p style='margin: 0;'><strong>💡 Astuce :</strong> 
                        Utilisez le header <code>X-API-Key: {$data['apiKey']}</code> 
                        dans vos requêtes pour vous authentifier avec votre clé gratuite.</p>
                    </div>
                </div>
                <div class='footer'>
                    <p>© AuthGroups API - Votre plateforme de développement</p>
                    <p>Email envoyé à {$data['email']} | <a href='{$_ENV['APP_URL']}/unsubscribe'>Se désabonner</a></p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Template pour email de félicitations après confirmation avec rappel plans
     */
    private function buildEmailConfirmedWithPlanReminderTemplate($data) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Email confirmé - Plan étendu !</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 700px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #4CAF50, #8BC34A); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { padding: 30px; background-color: #f9f9f9; }
                .success-box { 
                    background: #e8f5e8; 
                    border: 2px solid #4CAF50; 
                    padding: 20px; 
                    margin: 20px 0; 
                    border-radius: 8px;
                }
                .upgrade-section { 
                    background: linear-gradient(135deg, #fff3e0, #fce4ec); 
                    border-left: 4px solid #FF9800; 
                    padding: 25px; 
                    margin: 25px 0; 
                    border-radius: 0 8px 8px 0;
                }
                .plan-comparison {
                    display: grid;
                    grid-template-columns: repeat(2, 1fr);
                    gap: 20px;
                    margin: 20px 0;
                }
                .plan-card {
                    background: white;
                    border: 2px solid #ddd;
                    padding: 20px;
                    text-align: center;
                    border-radius: 8px;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                }
                .plan-free { border-color: #4CAF50; }
                .plan-premium { border-color: #FF9800; background: linear-gradient(135deg, #fff3e0, #fce4ec); }
                .button { 
                    display: inline-block; 
                    background: linear-gradient(135deg, #FF9800, #F57C00); 
                    color: white; 
                    padding: 15px 30px; 
                    text-decoration: none; 
                    border-radius: 25px; 
                    margin: 15px 10px;
                    font-weight: bold;
                    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
                }
                .button:hover {
                    background: linear-gradient(135deg, #F57C00, #FF9800);
                    transform: translateY(-2px);
                    transition: all 0.3s ease;
                }
                .improvement-list {
                    background: #e3f2fd;
                    padding: 20px;
                    border-radius: 8px;
                    margin: 20px 0;
                }
                .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
                .celebration { font-size: 48px; text-align: center; margin: 20px 0; }
                .countdown {
                    background: #ffeb3b;
                    border: 2px solid #fbc02d;
                    padding: 15px;
                    border-radius: 8px;
                    text-align: center;
                    margin: 20px 0;
                    font-weight: bold;
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div class='celebration'>🎉🎊✨</div>
                    <h1>Félicitations {$data['username']} !</h1>
                    <p>Votre email est confirmé et votre plan gratuit est étendu !</p>
                </div>
                <div class='content'>
                    <div class='success-box'>
                        <h2 style='color: #4CAF50; margin-top: 0;'>✅ Email confirmé avec succès !</h2>
                        <p>Votre compte est maintenant pleinement activé. En bonus, nous avons automatiquement étendu votre plan gratuit !</p>
                    </div>
                    
                    <h2>🚀 Votre plan gratuit a été amélioré !</h2>
                    <div class='improvement-list'>
                        <h3 style='color: #2196F3; margin-top: 0;'>Nouvelles limites améliorées :</h3>
                        <ul style='font-size: 16px;'>
                            <li>⏰ <strong>Durée :</strong> Étendu à <strong>{$data['extendedDays']} jours</strong> (au lieu de 7)</li>
                            <li>📊 <strong>Requêtes :</strong> <strong>30/minute</strong> (au lieu de 10)</li>
                            <li>🔄 <strong>Quota horaire :</strong> <strong>1,800/heure</strong> (au lieu de 600)</li>
                            <li>📖 <strong>Accès :</strong> Toujours en lecture seule</li>
                        </ul>
                    </div>
                    
                    <div class='countdown'>
                        ⏰ Vous avez maintenant <strong>{$data['extendedDays']} jours</strong> pour explorer notre API avec des limites étendues !
                    </div>
                    
                    <h2>💎 Prêt à débloquer toute la puissance ?</h2>
                    <div class='upgrade-section'>
                        <p>Votre période d'essai étendue est parfaite pour tester, mais pourquoi ne pas débloquer tout le potentiel dès maintenant ?</p>
                        
                        <div class='plan-comparison'>
                            <div class='plan-card plan-free'>
                                <h4>🆓 Plan Gratuit (Actuel)</h4>
                                <div style='font-size: 18px; color: #4CAF50; margin: 10px 0;'><strong>{$data['extendedDays']} jours</strong></div>
                                <ul style='text-align: left; font-size: 14px; color: #666;'>
                                    <li>30 requêtes/minute</li>
                                    <li>Lecture seule</li>
                                    <li>Support communautaire</li>
                                    <li>Expire bientôt ⏰</li>
                                </ul>
                            </div>
                            <div class='plan-card plan-premium'>
                                <h4>🏆 Plans Premium</h4>
                                <div style='font-size: 18px; color: #FF9800; margin: 10px 0;'><strong>Dès 9.99€/mois</strong></div>
                                <ul style='text-align: left; font-size: 14px;'>
                                    <li>✅ Jusqu'à 1000 req/min</li>
                                    <li>✅ Lecture + Écriture + Admin</li>
                                    <li>✅ Support prioritaire</li>
                                    <li>✅ Pas d'expiration</li>
                                    <li>✅ Webhooks & intégrations</li>
                                </ul>
                            </div>
                        </div>
                        
                        <p style='text-align: center; margin: 30px 0;'>
                            <a href='{$data['planInvitationUrl']}' class='button'>
                                🚀 Découvrir les plans premium
                            </a>
                        </p>
                        
                        <p style='text-align: center; font-size: 14px; color: #666;'>
                            💡 <strong>Astuce :</strong> Passez au premium maintenant et gardez tous vos paramètres actuels !
                        </p>
                    </div>
                    
                    <h3>📚 Ressources pour bien commencer</h3>
                    <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                        <p>
                            • <strong>Documentation complète :</strong> <a href='{$_ENV['APP_URL']}/docs'>Guide d'utilisation</a><br>
                            • <strong>Exemples de code :</strong> <a href='{$_ENV['APP_URL']}/examples'>Tutoriels pratiques</a><br>
                            • <strong>Dashboard :</strong> <a href='{$_ENV['APP_URL']}/dashboard'>Gérer vos API keys</a><br>
                            • <strong>Support :</strong> <a href='mailto:support@authgroups.com'>Nous contacter</a>
                        </p>
                    </div>
                    
                    <div style='background: #e8f5e8; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #4CAF50;'>
                        <h4 style='color: #4CAF50; margin-top: 0;'>🎯 Conseil d'expert</h4>
                        <p style='margin-bottom: 0;'>
                            Profitez de vos {$data['extendedDays']} jours étendus pour développer votre intégration. 
                            Quand vous serez prêt à passer en production, 
                            <a href='{$data['planInvitationUrl']}'>choisissez le plan premium</a> qui correspond à vos besoins !
                        </p>
                    </div>
                </div>
                <div class='footer'>
                    <p>© AuthGroups API - Merci de nous faire confiance !</p>
                    <p>Email envoyé à {$data['email']} | <a href='{$_ENV['APP_URL']}/unsubscribe'>Se désabonner</a></p>
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
