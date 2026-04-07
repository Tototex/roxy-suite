param(
  [string]$WorkbookPath = 'I:\My Drive\Old Roxy Data\Roxy stats 011916.xlsx',
  [string]$OutputCsv = '',
  [string]$SourceFileLabel = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($OutputCsv)) {
  $OutputCsv = Join-Path $PSScriptRoot 'old-roxy-weekly-import.csv'
}

if ([string]::IsNullOrWhiteSpace($SourceFileLabel)) {
  $SourceFileLabel = $WorkbookPath
}

function Normalize-Title {
  param([string]$Value)

  $title = (([string]$Value) -replace '\s+', ' ').Trim()
  return $title
}

function Normalize-MonthToken {
  param([string]$Value)

  $token = ([string]$Value).ToLowerInvariant()
  $token = $token -replace '0', 'o'
  $token = $token -replace '[^A-Za-z]', ''
  switch -Regex ($token) {
    '^jan' { return 1 }
    '^feb' { return 2 }
    '^mar' { return 3 }
    '^apr' { return 4 }
    '^may' { return 5 }
    '^jun' { return 6 }
    '^jul' { return 7 }
    '^aug' { return 8 }
    '^sep' { return 9 }
    '^oct' { return 10 }
    '^nov' { return 11 }
    '^dec' { return 12 }
    default { return 0 }
  }
}

function Convert-ToInt {
  param([object]$Value)

  $text = ([string]$Value).Trim()
  if ($text -eq '') { return 0 }
  $text = $text -replace '[^0-9-]', ''
  if ($text -eq '') { return 0 }
  return [int]$text
}

function Parse-WeekInfo {
  param([string]$Text)

  $value = (([string]$Text) -replace '\s+', ' ').Trim()
  if ($value -eq '' -or $value -match '^(?i:total)\b') {
    return $null
  }

  $exactDate = $null
  foreach ($pattern in @('M/d/yyyy', 'M/d/yy', 'MM/dd/yyyy', 'MM/dd/yy')) {
    try {
      $exactDate = [datetime]::ParseExact($value, $pattern, [System.Globalization.CultureInfo]::InvariantCulture)
      break
    } catch {
    }
  }

  if ($null -ne $exactDate) {
    return [pscustomobject]@{
      Kind = 'exact'
      WeekLabel = $value
      StartMonth = $exactDate.Month
      StartDay = $exactDate.Day
      StartYear = $exactDate.Year
      EndMonth = $exactDate.Month
      EndDay = $exactDate.Day + 6
      StartDate = $exactDate.Date
      EndDate = $exactDate.Date.AddDays(6)
    }
  }

  if ($value -match '^(?<sm>[A-Za-z0-9]+)\s+(?<sd>\d{1,2})\s*-\s*(?<em>[A-Za-z0-9]+)\s*(?<ed>\d{1,2})$') {
    $sm = Normalize-MonthToken $Matches['sm']
    $em = Normalize-MonthToken $Matches['em']
    if ($sm -gt 0 -and $em -gt 0) {
      return [pscustomobject]@{
        Kind = 'range'
        WeekLabel = $value
        StartMonth = $sm
        StartDay = [int]$Matches['sd']
        StartYear = 0
        EndMonth = $em
        EndDay = [int]$Matches['ed']
        StartDate = $null
        EndDate = $null
      }
    }
  }

  if ($value -match '^(?<sm>[A-Za-z0-9]+)\s+(?<sd>\d{1,2})\s*-\s*(?<ed>\d{1,2})$') {
    $sm = Normalize-MonthToken $Matches['sm']
    if ($sm -gt 0) {
      return [pscustomobject]@{
        Kind = 'range'
        WeekLabel = $value
        StartMonth = $sm
        StartDay = [int]$Matches['sd']
        StartYear = 0
        EndMonth = $sm
        EndDay = [int]$Matches['ed']
        StartDate = $null
        EndDate = $null
      }
    }
  }

  return $null
}

function Resolve-WeekDates {
  param([System.Collections.Generic.List[object]]$Rows)

  for ($i = $Rows.Count - 1; $i -ge 0; $i--) {
    $row = $Rows[$i]
    if ($null -ne $row.StartDate) {
      continue
    }

    $nextResolved = $null
    for ($j = $i + 1; $j -lt $Rows.Count; $j++) {
      if ($null -ne $Rows[$j].StartDate) {
        $nextResolved = $Rows[$j]
        break
      }
    }

    if ($null -eq $nextResolved) {
      continue
    }

    $year = $nextResolved.StartDate.Year
    if ($row.StartMonth -gt $nextResolved.StartDate.Month) {
      $year--
    }

    $startDate = Get-Date -Year $year -Month $row.StartMonth -Day $row.StartDay
    $endYear = $year
    if ($row.EndMonth -lt $row.StartMonth) {
      $endYear++
    }
    $endDate = Get-Date -Year $endYear -Month $row.EndMonth -Day $row.EndDay
    $row.StartDate = $startDate.Date
    $row.EndDate = $endDate.Date
  }

  for ($i = 0; $i -lt $Rows.Count; $i++) {
    $row = $Rows[$i]
    if ($null -ne $row.StartDate) {
      continue
    }

    $prevResolved = $null
    for ($j = $i - 1; $j -ge 0; $j--) {
      if ($null -ne $Rows[$j].StartDate) {
        $prevResolved = $Rows[$j]
        break
      }
    }

    if ($null -eq $prevResolved) {
      continue
    }

    $year = $prevResolved.StartDate.Year
    if ($row.StartMonth -lt $prevResolved.StartDate.Month) {
      $year++
    }

    $startDate = Get-Date -Year $year -Month $row.StartMonth -Day $row.StartDay
    $endYear = $year
    if ($row.EndMonth -lt $row.StartMonth) {
      $endYear++
    }
    $endDate = Get-Date -Year $endYear -Month $row.EndMonth -Day $row.EndDay
    $row.StartDate = $startDate.Date
    $row.EndDate = $endDate.Date
  }
}

$excel = $null
$workbook = $null
$worksheet = $null

try {
  $excel = New-Object -ComObject Excel.Application
  $excel.Visible = $false
  $excel.DisplayAlerts = $false
  $workbook = $excel.Workbooks.Open($WorkbookPath)
  $worksheet = $workbook.Worksheets.Item('Wkly')
  $used = $worksheet.UsedRange
  $rowCount = [int]$used.Rows.Count

  $parsedRows = New-Object 'System.Collections.Generic.List[object]'

  for ($row = 2; $row -le $rowCount; $row++) {
    $dateText = ([string]$worksheet.Cells.Item($row, 1).Text).Trim()
    $movieTitle = Normalize-Title ([string]$worksheet.Cells.Item($row, 2).Text)
    if ($dateText -eq '' -or $movieTitle -eq '' -or $dateText -match '^(?i:total)\b') {
      continue
    }

    $weekInfo = Parse-WeekInfo -Text $dateText
    if ($null -eq $weekInfo) {
      continue
    }

    $general = Convert-ToInt $worksheet.Cells.Item($row, 5).Value2
    $discount = Convert-ToInt $worksheet.Cells.Item($row, 6).Value2
    $freeA = Convert-ToInt $worksheet.Cells.Item($row, 7).Value2
    $freeB = Convert-ToInt $worksheet.Cells.Item($row, 8).Value2
    $freeC = Convert-ToInt $worksheet.Cells.Item($row, 9).Value2
    $total = Convert-ToInt $worksheet.Cells.Item($row, 10).Value2
    $free = $freeA + $freeB + $freeC
    if ($total -le 0) {
      $total = $general + $discount + $free
    } elseif ($free -le 0) {
      $free = [Math]::Max(0, $total - $general - $discount)
    }

    if (($general + $discount + $free + $total) -le 0) {
      continue
    }

    $parsedRows.Add([pscustomobject]@{
      RowNumber = $row
      WeekLabel = $weekInfo.WeekLabel
      StartMonth = $weekInfo.StartMonth
      StartDay = $weekInfo.StartDay
      EndMonth = $weekInfo.EndMonth
      EndDay = $weekInfo.EndDay
      StartDate = $weekInfo.StartDate
      EndDate = $weekInfo.EndDate
      MovieTitle = $movieTitle
      Rating = ([string]$worksheet.Cells.Item($row, 3).Text).Trim()
      WeeksRun = ([string]$worksheet.Cells.Item($row, 4).Text).Trim()
      GeneralQty = $general
      DiscountQty = $discount
      FreeQty = $free
      TotalAttendance = $total
    })
  }

  Resolve-WeekDates -Rows $parsedRows

  $outputRows = foreach ($row in $parsedRows) {
    if ($null -eq $row.StartDate) {
      continue
    }

    [pscustomobject]@{
      week_start_date = $row.StartDate.ToString('yyyy-MM-dd')
      week_end_date = if ($null -ne $row.EndDate) { $row.EndDate.ToString('yyyy-MM-dd') } else { '' }
      week_label = $row.WeekLabel
      movie_title = $row.MovieTitle
      rating = $row.Rating
      weeks_run = $row.WeeksRun
      general_qty = $row.GeneralQty
      discount_qty = $row.DiscountQty
      free_qty = $row.FreeQty
      total_attendance = $row.TotalAttendance
      source_type = 'old_owner_weekly_import'
      source_file = $SourceFileLabel
      notes = 'Imported from prior owner weekly workbook'
    }
  }

  $deduped = $outputRows |
    Group-Object week_start_date, movie_title |
    ForEach-Object {
      $_.Group | Sort-Object @{ Expression = { [int]$_.total_attendance }; Descending = $true } | Select-Object -First 1
    } |
    Sort-Object week_start_date, movie_title

  $deduped | Export-Csv -Path $OutputCsv -NoTypeInformation -Encoding UTF8
  Write-Output ("Exported {0} weekly legacy rows to {1}" -f @($deduped).Count, $OutputCsv)
} finally {
  if ($null -ne $workbook) {
    $workbook.Close($false) | Out-Null
    [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($workbook)
  }
  if ($null -ne $worksheet) {
    [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($worksheet)
  }
  if ($null -ne $excel) {
    $excel.Quit()
    [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($excel)
  }
}
