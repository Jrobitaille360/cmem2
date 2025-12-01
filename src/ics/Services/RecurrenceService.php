<?php

namespace ICS\Services;

use ICS\Models\EventOccurrence;
use AuthGroups\Services\LogService;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;

/**
 * Service pour gérer les événements récurrents
 * Utilise la bibliothèque simshaun/recurr pour parser et calculer les occurrences RRULE
 * Maintient une table d'occurrences pré-calculées jusqu'en 2099-12-31
 * La table des occurrences est mise à jour via des triggers SQL lors de l'insertion/mise à jour/suppression des événements
 */
class RecurrenceService
{
    /**
     * Génère et sauvegarde toutes les occurrences d'un événement jusqu'en 2099-12-31
     * Pour les événements sans date de fin (RRULE sans UNTIL/COUNT), utilise 2099-12-31 comme limite
     * 
     * @param array $event L'événement récurrent
     * @return int Nombre d'occurrences générées
     */
    public static function generateAllOccurrences(array $event): int
    {
        if (empty($event['recurrence_rule'])) {
            return 0;
        }

        try {
            // Définir la date de début et la date limite (2099-12-31)
            $startDate = $event['start_datetime'];
            $maxDate = ICS_OCCURRENCES_MAX_DATE . ' 23:59:59';

            // Supprimer les anciennes occurrences de cet événement
            EventOccurrence::deleteByEventId($event['id']);

            // Générer toutes les occurrences jusqu'à la date limite (2099-12-31)
            // Plus de limite artificielle - génère toutes les occurrences définies par la RRULE
            $occurrences = self::calculateOccurrences($event, $startDate, $maxDate);

            if (empty($occurrences)) {
                return 0;
            }

            // Préparer les données pour l'insertion batch
            $occurrenceData = [];
            foreach ($occurrences as $occ) {
                $occurrenceData[] = [
                    'event_id' => $event['id'],
                    'calendar_id' => $event['calendar_id'],
                    'occurrence_date' => substr($occ['start_datetime'], 0, 10),
                    'start_datetime' => $occ['start_datetime'],
                    'end_datetime' => $occ['end_datetime'],
                    'recurrence_index' => $occ['recurrence_index']
                ];
            }

            // Insérer en batch
            EventOccurrence::createUpdateBatch($occurrenceData);

            LogService::info("Occurrences générées pour événement récurrent", [
                'event_id' => $event['id'],
                'count' => count($occurrenceData)
            ]);

            return count($occurrenceData);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la génération des occurrences", [
                'event_id' => $event['id'],
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Régénère les occurrences pour tous les événements récurrents
     * À utiliser lors de la maintenance ou migration
     */
    public static function regenerateAllOccurrences(): array
    {
        try {
            $db = EventOccurrence::getDbConnection();
            
            $stmt = $db->query("SELECT * FROM calendar_events 
                               WHERE recurrence_rule IS NOT NULL 
                               AND recurrence_rule != '' 
                               AND deleted_at IS NULL");
            $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $stats = [
                'total' => count($events),
                'success' => 0,
                'failed' => 0
            ];

            foreach ($events as $event) {
                $count = self::generateAllOccurrences($event);
                if ($count > 0) {
                    $stats['success']++;
                } else {
                    $stats['failed']++;
                }
            }

            LogService::info("Régénération globale des occurrences terminée", $stats);

            return $stats;
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la régénération globale", [
                'error' => $e->getMessage()
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Calcule les occurrences d'un événement récurrent (sans sauvegarder)
     * Utilisé pour la génération initiale et les calculs à la volée
     * Pour les événements sans UNTIL/COUNT, génère jusqu'à endDate (2099-12-31 par défaut)
     * 
     * @param array $event L'événement avec sa règle de récurrence
     * @param string $startDate Date de début de la période (Y-m-d H:i:s)
     * @param string $endDate Date de fin de la période (Y-m-d H:i:s)
     * @return array Tableau d'occurrences d'événements
     */
    private static function calculateOccurrences(array $event, string $startDate, string $endDate): array
    {
        if (empty($event['recurrence_rule'])) {
            return [];
        }

        try {
            $rrule = 'RRULE:' . $event['recurrence_rule'];
            $startDateTime = new \DateTime($event['start_datetime']);
            $endDateTime = new \DateTime($event['end_datetime']);
            
            // Calculer la durée de l'événement
            $duration = $startDateTime->diff($endDateTime);
            
            // Créer la règle de récurrence
            $rule = new Rule($rrule, $startDateTime);
            
            // Configurer le transformateur
            $config = new ArrayTransformerConfig();
            $config->enableLastDayOfMonthFix();
            // Augmenter la limite virtuelle pour permettre la génération de grandes séries
            // Par défaut la bibliothèque limite à 732 occurrences (~2 ans)
            // On met une limite haute pour couvrir jusqu'à 2099
            $config->setVirtualLimit(100000);
            
            $transformer = new ArrayTransformer();
            $transformer->setConfig($config);
            
            // Générer les occurrences
            $occurrences = $transformer->transform($rule);
            
            $periodStart = new \DateTime($startDate);
            $periodEnd = new \DateTime($endDate);
            
            $expandedEvents = [];
            $occurrenceIndex = 0;
            
            foreach ($occurrences as $occurrence) {
                $occurrenceStart = $occurrence->getStart();
                
                if ($occurrenceStart instanceof \DateTimeImmutable) {
                    $occurrenceEnd = $occurrenceStart->add($duration);
                } else {
                    // Clone pour DateTime mutable et créer une vraie instance DateTime
                    $clonedStart = \DateTime::createFromFormat('Y-m-d H:i:s', $occurrenceStart->format('Y-m-d H:i:s'));
                    $occurrenceEnd = $clonedStart->add($duration);
                }
                
                // Vérifier si l'occurrence est dans la période
                if ($occurrenceEnd >= $periodStart && $occurrenceStart <= $periodEnd) {
                    $expandedEvent = $event;
                    $expandedEvent['start_datetime'] = $occurrenceStart->format('Y-m-d H:i:s');
                    $expandedEvent['end_datetime'] = $occurrenceEnd->format('Y-m-d H:i:s');
                    $expandedEvent['occurrence_id'] = $event['id'] . '_' . $occurrenceStart->format('Ymd\THis');
                    $expandedEvent['is_recurring'] = true;
                    $expandedEvent['recurrence_index'] = $occurrenceIndex;
                    $expandedEvent['parent_event_id'] = $event['id'];
                    
                    $expandedEvents[] = $expandedEvent;
                }
                
                $occurrenceIndex++;
            }
            
            return $expandedEvents;
            
        } catch (\Exception $e) {
            LogService::error("Erreur lors du calcul des occurrences", [
                'event_id' => $event['id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Expanse un événement récurrent en ses occurrences pour une période donnée
     * (méthode publique qui utilise calculateOccurrences en interne)
     * 
     * @param array $event L'événement avec sa règle de récurrence
     * @param string|null $startDate Date de début de la période (Y-m-d H:i:s)
     * @param string|null $endDate Date de fin de la période (Y-m-d H:i:s)
     * @return array Tableau d'occurrences d'événements
     */
    public static function expandRecurrence(array $event, ?string $startDate = null, ?string $endDate = null): array
    {
        // Si pas de règle de récurrence, retourner l'événement original s'il est dans la période
        if (empty($event['recurrence_rule'])) {
            if ($startDate && $endDate) {
                if ($event['start_datetime'] <= $endDate && $event['end_datetime'] >= $startDate) {
                    return [$event];
                }
            } else {
                return [$event];
            }
            return [];
        }

        // Définir les dates par défaut si non fournies
        if (!$startDate) {
            $startDate = date('Y-m-d H:i:s');
        }
        if (!$endDate) {
            $endDate = date('Y-m-d H:i:s', strtotime('+2 years'));
        }

        return self::calculateOccurrences($event, $startDate, $endDate);
    }
    
    /**
     * Expanse plusieurs événements récurrents
     * 
     * @param array $events Tableau d'événements
     * @param string $startDate Date de début de la période
     * @param string $endDate Date de fin de la période
     * @return array Tableau d'occurrences triées par date de début
     */
    public static function expandMultipleEvents(array $events, ?string $startDate = null, ?string $endDate = null): array
    {
        $allOccurrences = [];
        
        foreach ($events as $event) {
            $occurrences = self::expandRecurrence($event, $startDate, $endDate);
            $allOccurrences = array_merge($allOccurrences, $occurrences);
        }
        
        // Trier par date de début
        usort($allOccurrences, function($a, $b) {
            return strcmp($a['start_datetime'], $b['start_datetime']);
        });
        
        return $allOccurrences;
    }
    
    /**
     * Obtient les occurrences d'un événement récurrent dans une période donnée
     * 
     * @param array $event L'événement récurrent
     * @param string|null $startDate Date de début (optionnelle)
     * @param string|null $endDate Date de fin (optionnelle)
     * @return array
     */
    public static function getOccurrences(array $event, ?string $startDate = null, ?string $endDate = null): array
    {
        if (empty($event['recurrence_rule'])) {
            return [];
        }
        
        // Par défaut, récupérer les occurrences pour les 2 prochaines années
        if (!$startDate) {
            $startDate = date('Y-m-d H:i:s');
        }
        
        if (!$endDate) {
            $endDate = date('Y-m-d H:i:s', strtotime('+2 years'));
        }
        
        return self::expandRecurrence($event, $startDate, $endDate);
    }

    public static function expandOneDay($event, ?string $startDatePeriod = null, ?string $endDatePeriod = null): array
    {
        if ($event['start_datetime'] === $event['end_datetime']) {
                return [$event];
        }

        if (!$startDatePeriod) {
            $startDatePeriod = date('Y-m-d H:i:s');
        }
        
        if (!$endDatePeriod) {
            $endDatePeriod = date('Y-m-d H:i:s', strtotime('+2 years'));
        }
        $occurences = [];

        $startDateTime = new \DateTime($event['start_datetime']);
        $endDateTime = new \DateTime($event['end_datetime']);
        $interval = new \DateInterval('P1D');
        $period = new \DatePeriod($startDateTime, $interval, $endDateTime->modify('+1 day'));
        foreach ($period as $date) {
            $occurrence = $event;
            $occurrence['start_datetime'] = $date->format('Y-m-d 00:00:00');
            $occurrence['end_datetime'] = $date->format('Y-m-d 23:59:59');
            if ( $occurrence['end_datetime'] < $startDatePeriod || $occurrence['start_datetime'] > $endDatePeriod) {
                continue;
            }
            $occurences[] = $occurrence;
        }
        if ($event['is_all_day']) {
            return $occurences;            
        }
        $occurences[0]['start_datetime'] = $event['start_datetime'];
        $occurences[count($occurences) - 1]['end_datetime'] = $event['end_datetime'];
        return $occurences;
    }

    /**
     * Obtient les occurrences d'un événement récurrent d'une journée dans une période donnée
     * 
     * @param array $event L'événement récurrent
     * @param string|null $startDate Date de début (optionnelle)
     * @param string|null $endDate Date de fin (optionnelle)
     * @return array
     */
    public static function getOneDayOccurrences(array $event, ?string $startDate = null, ?string $endDate = null): array
    {
        $events = self::getOccurrences($event, $startDate, $endDate);
        $allOccurrences = [];
        foreach ($events as $event) {
            $occurrences = self::expandOneDay($event, $startDate, $endDate);
            $allOccurrences = array_merge($allOccurrences, $occurrences);
        }
        return $allOccurrences;
    }
    
    
    /**
     * Compte le nombre total d'occurrences d'un événement récurrent
     * Attention: peut être infini si la règle n'a pas de COUNT ou UNTIL
     * 
     * @param array $event L'événement récurrent
     * @return int|string Nombre d'occurrences ou 'infinite'
     */
    public static function countOccurrences(array $event): int|string
    {
        if (empty($event['recurrence_rule'])) {
            return 1; // L'événement lui-même
        }
        
        // Vérifier si la règle a un COUNT ou UNTIL (limite)
        $rule = strtoupper($event['recurrence_rule']);
        if (!str_contains($rule, 'COUNT=') && !str_contains($rule, 'UNTIL=')) {
            return 'infinite';
        }
        
        try {
            // Calculer les occurrences jusqu'à la date limite
            $farFuture = ICS_OCCURRENCES_MAX_DATE . ' 23:59:59';
            $occurrences = self::expandRecurrence($event, $event['start_datetime'], $farFuture);
            
            return count($occurrences);
            
        } catch (\Exception $e) {
            return 1;
        }
    }
}
