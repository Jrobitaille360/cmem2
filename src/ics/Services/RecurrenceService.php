<?php

namespace ICS\Services;

use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;

/**
 * Service pour gérer les événements récurrents
 * Utilise la bibliothèque simshaun/recurr pour parser et calculer les occurrences RRULE
 */
class RecurrenceService
{
    /**
     * Expanse un événement récurrent en ses occurrences pour une période donnée
     * 
     * @param array $event L'événement avec sa règle de récurrence
     * @param string $startDate Date de début de la période (Y-m-d H:i:s)
     * @param string $endDate Date de fin de la période (Y-m-d H:i:s)
     * @param int $maxOccurrences Nombre maximum d'occurrences à retourner (défaut: 100)
     * @return array Tableau d'occurrences d'événements
     */
    public static function expandRecurrence(array $event, string $startDate, string $endDate, int $maxOccurrences = 100): array
    {
        // Si pas de règle de récurrence, retourner l'événement original s'il est dans la période
        if (empty($event['recurrence_rule'])) {
            // Vérifier si l'événement est dans la période demandée
            if ($event['start_datetime'] <= $endDate && $event['end_datetime'] >= $startDate) {
                return [$event];
            }
            return [];
        }

        try {
            // Parser la règle de récurrence
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
            
            // Limiter les occurrences dans la période demandée
            $transformer = new ArrayTransformer();
            $transformer->setConfig($config);
            
            // Calculer les occurrences
            $constraints = [];
            
            // Ajouter une contrainte de date de début
            $periodStart = new \DateTime($startDate);
            $periodEnd = new \DateTime($endDate);
            
            // Générer les occurrences
            $occurrences = $transformer->transform($rule);
            
            $expandedEvents = [];
            $occurrenceIndex = 0;
            
            foreach ($occurrences as $occurrence) {
                /** @var \DateTime|\DateTimeImmutable $occurrenceStart */
                $occurrenceStart = $occurrence->getStart();
                
                // Créer une nouvelle DateTime pour la fin
                if ($occurrenceStart instanceof \DateTimeImmutable) {
                    $occurrenceEnd = $occurrenceStart->add($duration);
                } else {
                    // Clone pour DateTime mutable
                    $occurrenceEnd = (clone $occurrenceStart)->add($duration);
                }
                
                // Vérifier si l'occurrence est dans la période demandée
                if ($occurrenceEnd >= $periodStart && $occurrenceStart <= $periodEnd) {
                    // Créer une copie de l'événement pour cette occurrence
                    $expandedEvent = $event;
                    $expandedEvent['start_datetime'] = $occurrenceStart->format('Y-m-d H:i:s');
                    $expandedEvent['end_datetime'] = $occurrenceEnd->format('Y-m-d H:i:s');
                    
                    // Ajouter un identifiant unique pour cette occurrence
                    $expandedEvent['occurrence_id'] = $event['id'] . '_' . $occurrenceStart->format('Ymd\THis');
                    $expandedEvent['is_recurring'] = true;
                    $expandedEvent['recurrence_index'] = $occurrenceIndex;
                    $expandedEvent['parent_event_id'] = $event['id'];
                    
                    $expandedEvents[] = $expandedEvent;
                    $occurrenceIndex++;
                }
            }
            
            return $expandedEvents;
            
        } catch (\Exception $e) {
            // En cas d'erreur, retourner l'événement original
            error_log("Erreur lors de l'expansion de la récurrence: " . $e->getMessage());
            return [$event];
        }
    }
    
    /**
     * Expanse plusieurs événements récurrents
     * 
     * @param array $events Tableau d'événements
     * @param string $startDate Date de début de la période
     * @param string $endDate Date de fin de la période
     * @param int $maxOccurrencesPerEvent Nombre max d'occurrences par événement
     * @return array Tableau d'occurrences triées par date de début
     */
    public static function expandMultipleEvents(array $events, string $startDate, string $endDate, int $maxOccurrencesPerEvent = 100): array
    {
        $allOccurrences = [];
        
        foreach ($events as $event) {
            $occurrences = self::expandRecurrence($event, $startDate, $endDate, $maxOccurrencesPerEvent);
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
     * @param int $limit Nombre maximum d'occurrences à retourner
     * @return array
     */
    public static function getOccurrences(array $event, ?string $startDate = null, ?string $endDate = null, int $limit = 100): array
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
        
        return self::expandRecurrence($event, $startDate, $endDate, $limit);
    }
    
    /**
     * Vérifie si un événement a des occurrences dans une période donnée
     * 
     * @param array $event L'événement
     * @param string $startDate Date de début de la période
     * @param string $endDate Date de fin de la période
     * @return bool
     */
    public static function hasOccurrencesInPeriod(array $event, string $startDate, string $endDate): bool
    {
        $occurrences = self::expandRecurrence($event, $startDate, $endDate, 1);
        return count($occurrences) > 0;
    }
    
    /**
     * Compte le nombre total d'occurrences d'un événement récurrent
     * Attention: peut être infini si la règle n'a pas de COUNT ou UNTIL
     * 
     * @param array $event L'événement récurrent
     * @param int $maxToCheck Nombre maximum d'occurrences à vérifier
     * @return int|string Nombre d'occurrences ou 'infinite'
     */
    public static function countOccurrences(array $event, int $maxToCheck = 1000): int|string
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
            // Calculer les occurrences sur une très longue période
            $farFuture = date('Y-m-d H:i:s', strtotime('+50 years'));
            $occurrences = self::expandRecurrence($event, $event['start_datetime'], $farFuture, $maxToCheck);
            
            if (count($occurrences) >= $maxToCheck) {
                return 'infinite'; // Probablement infini
            }
            
            return count($occurrences);
            
        } catch (\Exception $e) {
            return 1;
        }
    }
}
