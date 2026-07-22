<?php

namespace ICS\Services;

use ICS\Models\EventOccurrence;
use AuthGroups\Services\LogService;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;
use Recurr\Transformer\Constraint\BetweenConstraint;

/**
 * Service pour gérer les événements récurrents
 * 
 * Utilise la bibliothèque simshaun/recurr pour parser et calculer les occurrences RRULE.
 * Maintient une table d'occurrences pré-calculées jusqu'en 2099-12-31.
 * La table des occurrences est mise à jour via une tâche CRON 
 * qui régénère périodiquement les occurrences.
 */
class RecurrenceService
{
    /**
     * Génère et sauvegarde toutes les occurrences d'un événement jusqu'en 2099-12-31.
     * 
     * Pour les événements sans date de fin (RRULE sans UNTIL/COUNT), utilise 2099-12-31 comme limite.
     * Préserve les modifications apportées aux occurrences individuelles (annulations, modifications).
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

            // Récupérer les occurrences existantes qui ont été modifiées ou annulées
            $db = EventOccurrence::getDbConnection();
            $stmt = $db->prepare("SELECT * FROM event_occurrences 
                                 WHERE event_id = ? AND (is_modified = 1 OR is_cancelled = 1)");
            $stmt->execute([$event['id']]);
            $existingExceptions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Créer un index des exceptions par date d'occurrence
            $exceptionsByDate = [];
            foreach ($existingExceptions as $exception) {
                $exceptionsByDate[$exception['occurrence_date']] = $exception;
            }

            // Supprimer seulement les occurrences non modifiées (pas d'exceptions)
            $stmt = $db->prepare("DELETE FROM event_occurrences 
                                 WHERE event_id = ? AND is_modified = 0 AND is_cancelled = 0");
            $stmt->execute([$event['id']]);

            // Générer toutes les occurrences jusqu'à la date limite (2099-12-31)
            $occurrences = self::calculateOccurrences($event, $startDate, $maxDate);

            LogService::info("Occurrences générées pour événement récurrent", [
                'event_id' => $event['id'],
                'count' => count($occurrences),
                'recurrence_rule' => $event['recurrence_rule'],
                'start_date' => $startDate,
                'max_date' => $maxDate
            ]);

            if (empty($occurrences)) {
                return 0;
            }

            // Préparer les données pour l'insertion batch
            $occurrenceData = array_map(function($occ) use ($event, $exceptionsByDate) {
                $occurrenceDate = substr($occ['start_datetime'], 0, 10);
                $exception = $exceptionsByDate[$occurrenceDate] ?? null;
                
                $data = [
                    'event_id' => $event['id'],
                    'calendar_id' => $event['calendar_id'],
                    'occurrence_date' => $occurrenceDate,
                    'start_datetime' => $exception['modified_start_datetime'] ?? $occ['start_datetime'],
                    'end_datetime' => $exception['modified_end_datetime'] ?? $occ['end_datetime'],
                    'recurrence_index' => $occ['recurrence_index']
                ];
                
                // Ajouter les données d'exception si elles existent
                if ($exception) {
                    $data += [
                        'is_modified' => $exception['is_modified'],
                        'is_cancelled' => $exception['is_cancelled'],
                        'modified_title' => $exception['modified_title'],
                        'modified_description' => $exception['modified_description'],
                        'modified_location' => $exception['modified_location']
                    ];
                }
                
                return $data;
            }, $occurrences);

            // Insérer en batch (mettra à jour les occurrences existantes si nécessaire)
            EventOccurrence::createUpdateBatch($occurrenceData);

            LogService::info("Occurrences régénérées avec préservation des exceptions", [
                'event_id' => $event['id'],
                'total_generated' => count($occurrenceData),
                'exceptions_preserved' => count($existingExceptions)
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
     * Régénère les occurrences pour tous les événements récurrents.
     * 
     * À utiliser lors de la maintenance ou migration.
     * 
     * @return array Statistiques de la régénération
     */
    public static function regenerateAllOccurrences(): array
    {
        return self::regenerateOccurrencesWithFilter();
    }

    /**
     * Régénère les occurrences seulement pour les événements modifiés depuis une date.
     * 
     * Optimisation pour la maintenance incrémentale.
     * 
     * @param string $since Date depuis laquelle vérifier les modifications (Y-m-d H:i:s)
     * @return array Statistiques de la régénération
     */
    public static function regenerateModifiedOccurrences(string $since): array
    {
        return self::regenerateOccurrencesWithFilter($since);
    }
    
    /**
     * Régénère les occurrences avec un filtre optionnel sur la date de modification.
     * 
     * @param string|null $since Date optionnelle pour filtrer les événements modifiés
     * @return array Statistiques de la régénération
     */
    private static function regenerateOccurrencesWithFilter(?string $since = null): array
    {
        try {
            $db = EventOccurrence::getDbConnection();
            
            $query = "SELECT * FROM calendar_events 
                     WHERE recurrence_rule IS NOT NULL 
                     AND recurrence_rule != '' 
                     AND deleted_at IS NULL";
            
            if ($since) {
                $query .= " AND updated_at >= ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$since]);
            } else {
                $stmt = $db->query($query);
            }
            
            $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $stats = [
                'total' => count($events),
                'success' => 0,
                'failed' => 0
            ];
            
            if ($since) {
                $stats['since'] = $since;
            }

            if (empty($events)) {
                $message = $since ? "Aucun événement modifié depuis la dernière maintenance" : "Aucun événement récurrent trouvé";
                LogService::info($message, $stats);
                return $stats;
            }

            foreach ($events as $event) {
                $stats[self::generateAllOccurrences($event) > 0 ? 'success' : 'failed']++;
            }

            $logMessage = $since ? "Régénération incrémentale des occurrences terminée" : "Régénération globale des occurrences terminée";
            LogService::info($logMessage, $stats);

            return $stats;
        } catch (\Exception $e) {
            $context = ['error' => $e->getMessage()];
            if ($since) {
                $context['since'] = $since;
            }
            
            $logMessage = $since ? "Erreur lors de la régénération incrémentale" : "Erreur lors de la régénération globale";
            LogService::error($logMessage, $context);
            
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Calcule les occurrences d'un événement récurrent (sans sauvegarder).
     * 
     * Utilisé pour la génération initiale et les calculs à la volée.
     * Pour les événements sans UNTIL/COUNT, génère jusqu'à endDate (2099-12-31 par défaut).
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
            // Par défaut, la bibliothèque limite à 732 occurrences (~2 ans)
            // On met une limite haute pour couvrir jusqu'à 2099
            $config->setVirtualLimit(10000);
            
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
                
                // Vérifier que getStart() retourne bien un objet DateTime
                if (!$occurrenceStart instanceof \DateTimeInterface) {
                    LogService::warning("Occurrence invalide détectée", [
                        'event_id' => $event['id'] ?? 'unknown'
                    ]);
                    continue;
                }
                
                // Calculer la date de fin en fonction du type de DateTime
                try {
                    /** @var \DateTime|\DateTimeImmutable $occurrenceStart */
                    $occurrenceEnd = $occurrenceStart instanceof \DateTimeImmutable 
                        ? $occurrenceStart->add($duration)
                        : (clone $occurrenceStart)->add($duration);
                } catch (\Exception $e) {
                    continue;
                }
                
                // Vérifier si l'occurrence est dans la période
                if ($occurrenceEnd >= $periodStart && $occurrenceStart <= $periodEnd) {
                    $expandedEvents[] = array_merge($event, [
                        'start_datetime' => $occurrenceStart->format('Y-m-d H:i:s'),
                        'end_datetime' => $occurrenceEnd->format('Y-m-d H:i:s'),
                        'occurrence_id' => $event['id'] . '_' . $occurrenceStart->format('Ymd\THis'),
                        'is_recurring' => true,
                        'recurrence_index' => $occurrenceIndex,
                        'parent_event_id' => $event['id']
                    ]);
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
     * Expanse une RRULE à la volée sur une plage donnée, dans le TZID de l'événement.
     *
     * Contrairement à calculateOccurrences() (chemin CRON, timezone serveur implicite),
     * cette méthode construit la Rule avec le fuseau de l'événement et une BetweenConstraint
     * pour ne matérialiser que les occurrences de la plage demandée (pas de virtual limit).
     * Ne lit/écrit jamais event_occurrences — usage endpoint "à la demande" uniquement.
     *
     * @param array  $event L'événement récurrent (doit contenir recurrence_rule, start_datetime,
     *                      end_datetime, timezone)
     * @param string $start Début de la plage (Y-m-d H:i:s ou Y-m-d)
     * @param string $end   Fin de la plage (Y-m-d H:i:s ou Y-m-d)
     * @return array Tableau d'occurrences (mêmes clés que $event + start_datetime/end_datetime/occurrence_date/recurrence_index)
     * @throws \Recurr\Exception Si la RRULE est invalide ou hors du sous-ensemble supporté par recurr
     */
    public static function expandInRangeTzAware(array $event, string $start, string $end): array
    {
        $hasRrule = !empty($event['recurrence_rule']);
        $hasRdate = !empty($event['rdate']);

        if (!$hasRrule && !$hasRdate) {
            return [];
        }

        $timezone = $event['timezone'] ?? 'America/Montreal';
        $tz = new \DateTimeZone($timezone);

        $startDateTime = new \DateTime($event['start_datetime'], $tz);
        $endDateTime = new \DateTime($event['end_datetime'], $tz);
        $duration = $startDateTime->diff($endDateTime);

        $rangeStart = new \DateTime($start, $tz);
        $rangeEnd = new \DateTime($end, $tz);

        $expanded = [];
        $index = 0;

        // Occurrences RRULE
        if ($hasRrule) {
            $rule = new Rule('RRULE:' . $event['recurrence_rule'], $startDateTime, null, $timezone);

            $constraint = new BetweenConstraint($rangeStart, $rangeEnd, true);

            // Filet de sécurité pour une règle sans fin sur une très large plage.
            $config = new \Recurr\Transformer\ArrayTransformerConfig();
            $config->setVirtualLimit(200000);
            $transformer = new ArrayTransformer($config);

            // countConstraintFailures = false : les occurrences hors plage (avant $start)
            // ne comptent PAS contre le virtualLimit ; la transformation s'arrête via
            // BetweenConstraint::stopsTransformer() une fois $end dépassé. Indispensable
            // pour les règles sans fin projetées loin dans le futur (ex. plage en 2100) —
            // remplace l'ancienne limite de matérialisation 2099.
            $recurrences = $transformer->transform($rule, $constraint, false);

            foreach ($recurrences as $recurrence) {
                $occurrenceStart = $recurrence->getStart();
                $occurrenceEnd = \DateTime::createFromInterface($occurrenceStart)->add($duration);

                $occurrence = $event;
                unset($occurrence['id']);
                $expanded[] = array_merge($occurrence, [
                    'event_id'         => $event['id'],
                    'start_datetime'   => $occurrenceStart->format('Y-m-d H:i:s'),
                    'end_datetime'     => $occurrenceEnd->format('Y-m-d H:i:s'),
                    'occurrence_date'  => $occurrenceStart->format('Y-m-d'),
                    'recurrence_index' => $index,
                ]);
                $index++;
            }
        }

        // Occurrences RDATE (RFC 5545 §3.8.5.2) — à la volée depuis event.rdate (CSV).
        // Remplace l'ancienne matérialisation (generateRdateOccurrences). recurrence_index = -1.
        if ($hasRdate) {
            $rdateParts = array_filter(array_map('trim', explode(',', $event['rdate'])));
            foreach ($rdateParts as $rdateDatetime) {
                $occurrenceStart = new \DateTime($rdateDatetime, $tz);
                if ($occurrenceStart < $rangeStart || $occurrenceStart > $rangeEnd) {
                    continue;
                }
                $occurrenceEnd = (clone $occurrenceStart)->add($duration);

                $occurrence = $event;
                unset($occurrence['id']);
                $expanded[] = array_merge($occurrence, [
                    'event_id'         => $event['id'],
                    'start_datetime'   => $occurrenceStart->format('Y-m-d H:i:s'),
                    'end_datetime'     => $occurrenceEnd->format('Y-m-d H:i:s'),
                    'occurrence_date'  => $occurrenceStart->format('Y-m-d'),
                    'recurrence_index' => -1,
                ]);
            }
        }

        return $expanded;
    }

    /**
     * Expanse un événement récurrent en ses occurrences pour une période donnée.
     *
     * Méthode publique qui utilise calculateOccurrences en interne.
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
            if (!$startDate || !$endDate || 
                ($event['start_datetime'] <= $endDate && $event['end_datetime'] >= $startDate)) {
                return [$event];
            }
            return [];
        }

        // Définir les dates par défaut si non fournies
        $startDate = $startDate ?? date('Y-m-d H:i:s');
        $endDate = $endDate ?? date('Y-m-d H:i:s', strtotime('+2 years'));

        return self::calculateOccurrences($event, $startDate, $endDate);
    }
    
    /**
     * Expanse plusieurs événements récurrents.
     * 
     * @param array $events Tableau d'événements
     * @param string|null $startDate Date de début de la période
     * @param string|null $endDate Date de fin de la période
     * @return array Tableau d'occurrences triées par date de début
     */
    public static function expandMultipleEvents(array $events, ?string $startDate = null, ?string $endDate = null): array
    {
        $allOccurrences = array_reduce(
            $events,
            fn($carry, $event) => array_merge($carry, self::expandRecurrence($event, $startDate, $endDate)),
            []
        );
        
        usort($allOccurrences, fn($a, $b) => strcmp($a['start_datetime'], $b['start_datetime']));
        
        return $allOccurrences;
    }
    
    /**
     * Obtient les occurrences d'un événement récurrent dans une période donnée.
     * 
     * @param array $event L'événement récurrent
     * @param string|null $startDate Date de début (optionnelle)
     * @param string|null $endDate Date de fin (optionnelle)
     * @return array Tableau d'occurrences
     */
    public static function getOccurrences(array $event, ?string $startDate = null, ?string $endDate = null): array
    {
        return empty($event['recurrence_rule']) ? [] : self::expandRecurrence($event, $startDate, $endDate);
    }

    /**
     * Expanse un événement qui s'étend sur plusieurs jours en occurrences d'une journée.
     * 
     * @param array $event L'événement à expanser
     * @param string|null $startDatePeriod Date de début de la période (optionnelle)
     * @param string|null $endDatePeriod Date de fin de la période (optionnelle)
     * @param int $limit Limite du nombre d'occurrences (par défaut 100)
     * @return array Tableau d'occurrences d'une journée
     */
    public static function expandOneDay(array $event, ?string $startDatePeriod = null, ?string $endDatePeriod = null, int $limit = 100): array
    {
        $startDateTime = new \DateTime($event['start_datetime']);
        $endDateTime = new \DateTime($event['end_datetime']);
        
        // Si l'événement commence et finit le même jour, retourner l'événement original
        if ($startDateTime->format('Y-m-d') === $endDateTime->format('Y-m-d')) {
            return [$event];
        }

        $startDatePeriod = $startDatePeriod ?? date('Y-m-d H:i:s', strtotime('-2 years'));
        $endDatePeriod = $endDatePeriod ?? date('Y-m-d H:i:s', strtotime('+2 years'));
        
        $period = new \DatePeriod($startDateTime, new \DateInterval('P1D'), $endDateTime);
        $occurences = [];
        
        foreach ($period as $date) {
            $startDt = $date->format('Y-m-d 00:00:00');
            $endDt = $date->format('Y-m-d 23:59:59');
            
            // Filtrer par période
            if ($endDt < $startDatePeriod || $startDt > $endDatePeriod) {
                continue;
            }
            
            $occurrence = $event;
            $occurrence['start_datetime'] = $startDt;
            $occurrence['end_datetime'] = $endDt;
            $occurences[] = $occurrence;
            
            if (count($occurences) >= $limit) {
                break;
            }
        }
        
        // Pour les événements non all-day, conserver les heures d'origine
        if (!empty($occurences) && !$event['all_day']) {
            $occurences[0]['start_datetime'] = $event['start_datetime'];
            $occurences[count($occurences) - 1]['end_datetime'] = $event['end_datetime'];
        }
        
        return $occurences;
    }

    /**
     * Obtient les occurrences d'un événement récurrent expansées par jour dans une période donnée.
     * 
     * @param array $event L'événement récurrent
     * @param string|null $startDate Date de début (optionnelle)
     * @param string|null $endDate Date de fin (optionnelle)
     * @return array Tableau d'occurrences expansées par jour
     */
    public static function getOneDayOccurrences(array $event, ?string $startDate = null, ?string $endDate = null): array
    {
        $events = self::getOccurrences($event, $startDate, $endDate);
        
        return array_reduce(
            $events,
            fn($carry, $evt) => array_merge($carry, self::expandOneDay($evt, $startDate, $endDate)),
            []
        );
    }

    /**
     * Compte le nombre total d'occurrences d'un événement récurrent.
     * 
     * Attention: peut être infini si la règle n'a pas de COUNT ou UNTIL.
     * 
     * @param array $event L'événement récurrent
     * @return int|string Nombre d'occurrences ou 'infinite'
     */
    public static function countOccurrences(array $event): int|string
    {
        if (empty($event['recurrence_rule'])) {
            return 1;
        }
        
        $rule = strtoupper($event['recurrence_rule']);
        if (!str_contains($rule, 'COUNT=') && !str_contains($rule, 'UNTIL=')) {
            return 'infinite';
        }
        
        try {
            return count(self::expandRecurrence(
                $event,
                $event['start_datetime'],
                ICS_OCCURRENCES_MAX_DATE . ' 23:59:59'
            ));
        } catch (\Exception $e) {
            return 1;
        }
    }

    /**
     * Phase 4.1 — Marque des occurrences comme annulées à partir d'une liste de datetimes EXDATE.
     *
     * Appelé à l'import ICS quand EXDATE est présent.
     * Crée les lignes event_occurrences avec is_cancelled = 1 si elles n'existent pas déjà.
     *
     * @param array    $event      Résultat de CalendarEvent::create() (doit contenir id, calendar_id)
     * @param string[] $datetimes  Liste de datetimes locaux au format 'Y-m-d H:i:s'
     */
    public static function cancelOccurrencesByDatetimes(array $event, array $datetimes): void
    {
        if (empty($datetimes) || empty($event['id'])) {
            return;
        }

        try {
            $db = EventOccurrence::getDbConnection();

            foreach ($datetimes as $datetime) {
                $occurrenceDate = substr($datetime, 0, 10);

                // Insérer ou mettre à jour l'occurrence comme annulée
                $stmt = $db->prepare(
                    "INSERT INTO event_occurrences
                        (event_id, calendar_id, occurrence_date, start_datetime, end_datetime,
                         recurrence_index, is_cancelled)
                     VALUES (?, ?, ?, ?, ?, 0, 1)
                     ON DUPLICATE KEY UPDATE is_cancelled = 1"
                );
                $stmt->execute([
                    $event['id'],
                    $event['calendar_id'],
                    $occurrenceDate,
                    $datetime,
                    $datetime,
                ]);
            }

            LogService::info("Occurrences annulées via EXDATE", [
                'event_id' => $event['id'],
                'count'    => count($datetimes),
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de l'annulation des occurrences EXDATE", [
                'event_id' => $event['id'],
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Phase 4.2 — Génère les occurrences additionnelles depuis la colonne rdate.
     *
     * Appelé après CalendarEvent::create() ou RecurrenceService::generateAllOccurrences().
     * Les dates RDATE sont stockées en CSV dans event.rdate (ex: '2026-04-15 14:00:00,2026-04-22 14:00:00').
     *
     * @param array $event Ligne DB de l'événement (doit contenir id, calendar_id, rdate, start_datetime, end_datetime)
     * @return int Nombre d'occurrences RDATE insérées
     */
    public static function generateRdateOccurrences(array $event): int
    {
        if (empty($event['rdate']) || empty($event['id'])) {
            return 0;
        }

        try {
            $rdateParts = array_filter(array_map('trim', explode(',', $event['rdate'])));
            if (empty($rdateParts)) {
                return 0;
            }

            // Calculer la durée de l'événement pour déduire end_datetime des occurrences RDATE
            $startDt = new \DateTime($event['start_datetime']);
            $endDt   = new \DateTime($event['end_datetime']);
            $duration = $startDt->diff($endDt);

            $db = EventOccurrence::getDbConnection();
            $inserted = 0;

            foreach ($rdateParts as $rdateDatetime) {
                $occurrenceDate = substr($rdateDatetime, 0, 10);
                $occurrenceStart = new \DateTime($rdateDatetime);
                $occurrenceEnd   = (clone $occurrenceStart)->add($duration);

                $stmt = $db->prepare(
                    "INSERT INTO event_occurrences
                        (event_id, calendar_id, occurrence_date, start_datetime, end_datetime, recurrence_index)
                     VALUES (?, ?, ?, ?, ?, -1)
                     ON DUPLICATE KEY UPDATE id = id"
                );
                $stmt->execute([
                    $event['id'],
                    $event['calendar_id'],
                    $occurrenceDate,
                    $occurrenceStart->format('Y-m-d H:i:s'),
                    $occurrenceEnd->format('Y-m-d H:i:s'),
                ]);
                $inserted++;
            }

            LogService::info("Occurrences RDATE générées", [
                'event_id' => $event['id'],
                'count'    => $inserted,
            ]);

            return $inserted;
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la génération des occurrences RDATE", [
                'event_id' => $event['id'],
                'error'    => $e->getMessage(),
            ]);
            return 0;
        }
    }
}
