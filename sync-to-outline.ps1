$sourceDir = "C:\Users\cjcar\Documents\Capstone_3rd_Year\System"
$targetDir = "C:\Users\cjcar\Documents\Capstone_3rd_Year\Outline\sources\system"
$filesToSync = @("PRD_PROGRESS_AUDIT.md", "PRD_V1.md")

Write-Host "Starting File Watcher..." -ForegroundColor Green
Write-Host "Monitoring $sourceDir for changes to PRD files."
Write-Host "Press Ctrl+C to stop.`n"

# Create FileSystemWatcher
$watcher = New-Object System.IO.FileSystemWatcher
$watcher.Path = $sourceDir
$watcher.Filter = "*.md"
$watcher.IncludeSubdirectories = $false
$watcher.EnableRaisingEvents = $true
$watcher.NotifyFilter = [System.IO.NotifyFilters]::LastWrite

# Define the action to take when a file changes
$action = {
    $changedFile = $Event.SourceEventArgs.Name
    $fullPath = $Event.SourceEventArgs.FullPath

    if ($filesToSync -contains $changedFile) {
        $targetPath = Join-Path $targetDir $changedFile
        try {
            # Slight delay to allow the editor to release the file lock
            Start-Sleep -Milliseconds 100 
            Copy-Item -Path $fullPath -Destination $targetPath -Force
            Write-Host "[$(Get-Date -Format 'HH:mm:ss')] Synced: $changedFile -> Outline folder" -ForegroundColor Cyan
        } catch {
            Write-Host "[$(Get-Date -Format 'HH:mm:ss')] Failed to sync $changedFile : $_" -ForegroundColor Red
        }
    }
}

# Register events
$changedEvent = Register-ObjectEvent $watcher "Changed" -Action $action
$createdEvent = Register-ObjectEvent $watcher "Created" -Action $action

try {
    # Keep the script running
    while ($true) {
        Start-Sleep -Seconds 1
    }
} finally {
    # Cleanup if exited
    Unregister-Event -SourceIdentifier $changedEvent.Name
    Unregister-Event -SourceIdentifier $createdEvent.Name
    $watcher.Dispose()
}
