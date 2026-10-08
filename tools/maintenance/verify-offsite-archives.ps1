param(
    [Parameter(Mandatory=$true)][string]$ManifestPath,
    [Parameter(Mandatory=$true)][string[]]$BackupDirectories
)
$ErrorActionPreference='Stop'
$taskManifest=Get-Content -LiteralPath $ManifestPath -Raw | ConvertFrom-Json
$taskResults=@()
$taskIndex=0
foreach($taskEntry in $taskManifest){
    if($taskEntry.name -notmatch '^backup_[A-Za-z0-9_-]+\.(zip|gz)$' -or $taskEntry.sha256 -notmatch '^[a-f0-9]{64}$'){throw 'Invalid archive manifest entry'}
    $taskVerifiedPath=$null
    foreach($taskDirectory in $BackupDirectories){
        $taskRoot=[IO.Path]::GetFullPath($taskDirectory).TrimEnd('\')+'\'
        $taskCandidate=[IO.Path]::GetFullPath((Join-Path $taskRoot $taskEntry.name))
        if(-not $taskCandidate.StartsWith($taskRoot,[StringComparison]::OrdinalIgnoreCase)){throw 'Archive outside allowed backup directory'}
        if(-not (Test-Path -LiteralPath $taskCandidate -PathType Leaf)){continue}
        $taskFile=Get-Item -LiteralPath $taskCandidate
        if($taskFile.Length -ne [long]$taskEntry.size){continue}
        $taskHash=(Get-FileHash -LiteralPath $taskCandidate -Algorithm SHA256).Hash.ToLowerInvariant()
        if($taskHash -eq $taskEntry.sha256){$taskVerifiedPath=$taskCandidate;break}
    }
    $taskResults += [pscustomobject]@{name=$taskEntry.name;size=[long]$taskEntry.size;sha256=$taskEntry.sha256;verified=($null -ne $taskVerifiedPath);offsitePath=$taskVerifiedPath}
    $taskIndex++
    if($taskIndex%10 -eq 0){Write-Host "Checked $taskIndex of $($taskManifest.Count) archive copies"}
}
Write-Output ('ARCHIVE_RECEIPT='+ (ConvertTo-Json -InputObject $taskResults -Depth 3 -Compress))
