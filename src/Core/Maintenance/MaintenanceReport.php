<?php

namespace Core\Maintenance;

use AuthGroups\Services\EmailService;
use AuthGroups\Services\LogService;

class MaintenanceReport
{
    private const SUPPORT_EMAIL = 'support@journauxdebord.com';

    /** @var array[] Résultats par tâche : [name, duration, rows_deleted, rows_updated, rows_counted, errors, warnings] */
    private array $results = [];
    private float $startTime;
    private string $runDate;

    public function __construct(float $startTime)
    {
        $this->startTime  = $startTime;
        $this->runDate    = date('Y-m-d H:i:s');
    }

    public function addResult(array $taskResult): void
    {
        $this->results[] = $taskResult;
    }

    public function send(\PDO $db): void
    {
        $totalDuration = round(microtime(true) - $this->startTime, 2);
        $hasErrors     = $this->hasErrors();
        $subject       = sprintf(
            '[cmem2 API] Maintenance — %s — %s',
            $this->runDate,
            $hasErrors ? '✗ ERREURS' : '✓ OK'
        );

        $body = $this->buildHtml($totalDuration);

        LogService::info('MaintenanceReport: envoi du rapport', [
            'to'       => self::SUPPORT_EMAIL,
            'subject'  => $subject,
            'has_errors' => $hasErrors,
            'duration' => $totalDuration,
        ]);

        $mailer = new EmailService($db);
        $sent   = $mailer->sendEmail(self::SUPPORT_EMAIL, $subject, $body, true);

        if (!$sent) {
            LogService::error('MaintenanceReport: échec envoi courriel de rapport');
        }
    }

    // -------------------------------------------------------------------------

    private function hasErrors(): bool
    {
        foreach ($this->results as $r) {
            if (!empty($r['errors'])) {
                return true;
            }
        }
        return false;
    }

    private function buildHtml(float $totalDuration): string
    {
        $rows = '';
        foreach ($this->results as $r) {
            $rows .= $this->buildModuleSection($r);
        }

        $statusColor = $this->hasErrors() ? '#c0392b' : '#27ae60';
        $statusLabel = $this->hasErrors() ? '✗ Erreurs détectées' : '✓ Succès';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="fr">
        <head>
          <meta charset="UTF-8">
          <style>
            body { font-family: Arial, sans-serif; font-size: 14px; color: #222; }
            h1   { font-size: 18px; }
            h2   { font-size: 15px; margin-top: 24px; border-bottom: 1px solid #ddd; padding-bottom: 4px; }
            table { border-collapse: collapse; width: 100%; margin-bottom: 12px; }
            th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: left; }
            th   { background: #f5f5f5; }
            .ok  { color: #27ae60; }
            .err { color: #c0392b; font-weight: bold; }
            .warn { color: #e67e22; }
            .meta { color: #666; font-size: 12px; }
          </style>
        </head>
        <body>
          <h1>Rapport de maintenance — cmem2 API</h1>
          <p class="meta">Date : {$this->runDate} &nbsp;|&nbsp; Durée totale : {$totalDuration} s</p>
          <p style="color:{$statusColor};font-weight:bold">{$statusLabel}</p>
          {$rows}
          <p class="meta" style="margin-top:32px">Généré automatiquement par <em>private/maintenance.php</em></p>
        </body>
        </html>
        HTML;
    }

    private function buildModuleSection(array $r): string
    {
        $name     = htmlspecialchars($r['name']);
        $duration = isset($r['duration']) ? round($r['duration'], 3) . ' s' : '—';
        $hasErr   = !empty($r['errors']);
        $statusCss = $hasErr ? 'err' : 'ok';
        $statusTxt = $hasErr ? '✗ Erreur' : '✓ OK';

        $html  = "<h2><span class=\"{$statusCss}\">{$statusTxt}</span> — {$name} <span class=\"meta\">({$duration})</span></h2>\n";

        // Tableau des lignes supprimées
        if (!empty($r['rows_deleted'])) {
            $html .= $this->buildTable('Suppressions', $r['rows_deleted'], 'Lignes supprimées');
        }
        if (!empty($r['rows_updated'])) {
            $html .= $this->buildTable('Mises à jour', $r['rows_updated'], 'Lignes modifiées');
        }
        if (!empty($r['rows_counted'])) {
            $html .= $this->buildTable('Comptages', $r['rows_counted'], 'Lignes');
        }

        // Avertissements
        if (!empty($r['warnings'])) {
            $html .= '<p class="warn">Avertissements :</p><ul>';
            foreach ($r['warnings'] as $w) {
                $html .= '<li>' . htmlspecialchars($w) . '</li>';
            }
            $html .= '</ul>';
        }

        // Erreurs
        if (!empty($r['errors'])) {
            $html .= '<p class="err">Erreurs :</p><ul>';
            foreach ($r['errors'] as $e) {
                $html .= '<li>' . htmlspecialchars($e) . '</li>';
            }
            $html .= '</ul>';
        }

        return $html;
    }

    private function buildTable(string $title, array $data, string $colLabel): string
    {
        $html = "<p><strong>{$title}</strong></p><table><tr><th>Table</th><th>{$colLabel}</th></tr>";
        foreach ($data as $table => $count) {
            $html .= '<tr><td>' . htmlspecialchars($table) . '</td><td>' . (int)$count . '</td></tr>';
        }
        $html .= '</table>';
        return $html;
    }
}
