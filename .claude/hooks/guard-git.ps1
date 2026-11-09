# PreToolUse (Bash) - garde-fous git. ASCII seulement : PowerShell 5.1 lit ce fichier en ANSI.
#  - git add -f : toujours bloque (contourne .gitignore).
#  - creation de tag (git tag -a/-s/-u/--annotate/--sign, ou git tag vX.Y.Z) : autorisee
#    seulement si la PR release/vX.Y.Z est fusionnee. Sans version reconnaissable, repli sur
#    la PR de la branche courante. Lister/lire les tags (git tag -l, --sort, --list,
#    --contains, git ls-remote --tags...) n'est jamais bloque.
$j = [Console]::In.ReadToEnd() | ConvertFrom-Json
$cmd = [string]$j.tool_input.command

function Block([string]$reason) {
    @{ continue = $false; stopReason = $reason } | ConvertTo-Json -Compress
    exit 1
}

if ($cmd -match 'git\s+add\s+(-f|--force)\b') {
    Block 'BLOCKED: git add -f bypasses .gitignore. Ask user before force-adding files.'
}

$creates = ($cmd -match 'git\s+tag\s+[^|;&\r\n]*(?<![\w-])(-a|-s|-u|--annotate|--sign|--local-user)\b') -or
           ($cmd -match 'git\s+tag\s+v?\d+\.\d+')
if (-not $creates) { exit 0 }

if ($cmd -match 'git\s+tag\s+[^|;&\r\n]*?\b(v\d+\.\d+\.\d+[\w.-]*)') {
    $version = $Matches[1]
    $merged = gh pr list --head "release/$version" --state merged --json number -q 'length' 2>$null
    if ([int]("0$merged") -gt 0) { exit 0 }
    Block ('BLOCKED: tag ' + $version + ' - PR release/' + $version + ' not merged.')
}

$state = gh pr view --json state -q '.state' 2>$null
if ($state -eq 'MERGED') { exit 0 }
Block 'BLOCKED: tag creation before PR merge, and no vX.Y.Z version found in the command. Confirm PR is merged first.'
