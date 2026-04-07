param(
  [string]$RootPath = 'I:\My Drive\Grosses',
  [string]$OutputCsv = '',
  [string]$WarningsCsv = '',
  [string[]]$IncludeYears = @(),
  [switch]$IncludeZeroRows
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ManualDateOverrides = @{
  'I:\My Drive\Grosses\Archive\2017\Roxy Dunkirk Gross.xlsx' = '2017-07-21'
  'I:\My Drive\Grosses\Archive\2018\Alpha Grosses.xlsx' = '2018-08-17'
  'I:\My Drive\Grosses\Archive\2018\Ant Man and the Wasp Grosses.xlsx' = '2018-07-06'
  'I:\My Drive\Grosses\Archive\2018\Ant Man and the Wasp Wk 2.xlsx' = '2018-07-13'
  'I:\My Drive\Grosses\Archive\2018\Christopher Robin Roxy Grosses.xlsx' = '2018-08-03'
  'I:\My Drive\Grosses\Archive\2018\Incredibles 2 Roxy Grosses.xlsx' = '2018-06-15'
  'I:\My Drive\Grosses\Archive\2018\Jurassic World Wk 2.xlsx' = '2018-06-29'
  'I:\My Drive\Grosses\Archive\2018\Mamma Mia Here We Go Again Roxy Grosses.xlsx' = '2018-07-20'
  'I:\My Drive\Grosses\Archive\2018\Mission Impossible Fallout Grosses.xlsx' = '2018-07-27'
  'I:\My Drive\Grosses\Archive\2018\Searching Grosses.xlsx' = '2018-08-24'
  'I:\My Drive\Grosses\Archive\2018\the Meg Grosses.xlsx' = '2018-08-10'
  'I:\My Drive\Grosses\Archive\2018\The Nun Grosses.xlsx' = '2018-09-07'
  'I:\My Drive\Grosses\Archive\2018\The Predator Grosses.xlsx' = '2018-09-14'
  'I:\My Drive\Grosses\Archive\2018\Venom Grosses Week1.xlsx' = '2018-10-05'
  'I:\My Drive\Grosses\Archive\2018\Venom Grosses Week2.xlsx' = '2018-10-12'
  'I:\My Drive\Grosses\Archive\2021\8- 20 Free Guy.xlsx' = '2021-08-20'
}

function Get-ExcelApp {
  $excel = New-Object -ComObject Excel.Application
  $excel.Visible = $false
  $excel.DisplayAlerts = $false
  $excel.AskToUpdateLinks = $false
  return $excel
}

function Close-ExcelWorkbook {
  param($Workbook)

  if ($null -ne $Workbook) {
    $Workbook.Close($false) | Out-Null
    [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($Workbook)
  }
}

function Release-ComObjectSafe {
  param($Object)

  if ($null -ne $Object) {
    try {
      [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($Object)
    } catch {
    }
  }
}

function Get-CellText {
  param(
    $Worksheet,
    [int]$Row,
    [int]$Column
  )

  return [string]$Worksheet.Cells.Item($Row, $Column).Text
}

function Get-CellValue {
  param(
    $Worksheet,
    [int]$Row,
    [int]$Column
  )

  return $Worksheet.Cells.Item($Row, $Column).Value2
}

function Get-TrimmedText {
  param(
    $Worksheet,
    [int]$Row,
    [int]$Column
  )

  return (Get-CellText -Worksheet $Worksheet -Row $Row -Column $Column).Trim()
}

function Convert-ExcelDate {
  param([object]$Value)

  if ($null -eq $Value) {
    return $null
  }

  if ($Value -is [double] -or $Value -is [int]) {
    return [DateTime]::FromOADate([double]$Value)
  }

  $text = ([string]$Value).Trim()
  if ($text -eq '') {
    return $null
  }

  $patterns = @(
    'M/d/yy',
    'M/d/yyyy',
    'MM/dd/yy',
    'MM/dd/yyyy',
    'M-d-yy',
    'M-d-yyyy',
    'MM-dd-yy',
    'MM-dd-yyyy'
  )

  foreach ($pattern in $patterns) {
    try {
      return [DateTime]::ParseExact($text, $pattern, [System.Globalization.CultureInfo]::InvariantCulture)
    } catch {
    }
  }

  try {
    return [DateTime]::Parse($text, [System.Globalization.CultureInfo]::InvariantCulture)
  } catch {
    return $null
  }
}

function Get-YearFromPath {
  param([string]$Path)

  if ($Path -match '(?<!\d)(20\d{2})(?!\d)') {
    return [int]$Matches[1]
  }

  return 0
}

function Align-DateToArchiveYear {
  param(
    [datetime]$Date,
    [int]$YearFromPath
  )

  if ($YearFromPath -gt 0 -and [math]::Abs($Date.Year - $YearFromPath) -gt 1) {
    return (Get-Date -Year $YearFromPath -Month $Date.Month -Day $Date.Day).Date
  }

  return $Date.Date
}

function Get-DateAnchor {
  param(
    $Worksheet,
    [string]$FilePath
  )

  if ($ManualDateOverrides.ContainsKey($FilePath)) {
    return [datetime]::ParseExact($ManualDateOverrides[$FilePath], 'yyyy-MM-dd', [System.Globalization.CultureInfo]::InvariantCulture)
  }

  $fileName = [System.IO.Path]::GetFileName($FilePath)
  $yearFromPath = Get-YearFromPath -Path $FilePath

  if ($fileName -match '(\d{1,2})[-/](\d{1,2})[-/](\d{2,4})') {
    $candidate = '{0}/{1}/{2}' -f $Matches[1], $Matches[2], $Matches[3]
    $date = Convert-ExcelDate -Value $candidate
    if ($null -ne $date) {
      return Align-DateToArchiveYear -Date $date -YearFromPath $yearFromPath
    }
  }

  if ($yearFromPath -gt 0 -and $fileName -match '(^|[^\d])(\d{1,2})[-/](\d{1,2})([^\d]|$)') {
    $candidate = '{0}/{1}/{2}' -f $Matches[2], $Matches[3], $yearFromPath
    $date = Convert-ExcelDate -Value $candidate
    if ($null -ne $date) {
      return Align-DateToArchiveYear -Date $date -YearFromPath $yearFromPath
    }
  }

  $candidateCells = @(
    @{ Row = 4; Column = 5 },
    @{ Row = 4; Column = 2 },
    @{ Row = 4; Column = 3 },
    @{ Row = 3; Column = 5 },
    @{ Row = 3; Column = 2 },
    @{ Row = 3; Column = 3 }
  )

  foreach ($cell in $candidateCells) {
    $text = Get-TrimmedText -Worksheet $Worksheet -Row $cell.Row -Column $cell.Column
    if ($yearFromPath -gt 0 -and $text -match '(\d{1,2})[/-](\d{1,2})\s*-\s*(\d{1,2})[/-](\d{1,2})$') {
      $candidate = '{0}/{1}/{2}' -f $Matches[1], $Matches[2], $yearFromPath
      $date = Convert-ExcelDate -Value $candidate
      if ($null -ne $date) {
        return Align-DateToArchiveYear -Date $date -YearFromPath $yearFromPath
      }
    }

    if ($text -match '(\d{1,2})[/-](\d{1,2})\s*-\s*(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})') {
      $candidate = '{0}/{1}/{2}' -f $Matches[1], $Matches[2], $Matches[5]
      $date = Convert-ExcelDate -Value $candidate
      if ($null -ne $date) {
        return Align-DateToArchiveYear -Date $date -YearFromPath $yearFromPath
      }
    }

    if ($text -match '(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})') {
      $candidate = '{0}/{1}/{2}' -f $Matches[1], $Matches[2], $Matches[3]
      $date = Convert-ExcelDate -Value $candidate
      if ($null -ne $date) {
        return Align-DateToArchiveYear -Date $date -YearFromPath $yearFromPath
      }
    }

    if ($yearFromPath -gt 0 -and $text -match '(\d{1,2})[/-](\d{1,2})(?![/-]\d)') {
      $candidate = '{0}/{1}/{2}' -f $Matches[1], $Matches[2], $yearFromPath
      $date = Convert-ExcelDate -Value $candidate
      if ($null -ne $date) {
        return Align-DateToArchiveYear -Date $date -YearFromPath $yearFromPath
      }
    }

    $value = Get-CellValue -Worksheet $Worksheet -Row $cell.Row -Column $cell.Column
    $date = Convert-ExcelDate -Value $value
    if ($null -ne $date) {
      return Align-DateToArchiveYear -Date $date -YearFromPath $yearFromPath
    }
  }

  return $null
}

function Normalize-Title {
  param([string]$Value)

  if ($null -eq $Value) {
    $title = ''
  } else {
    $title = $Value.Trim()
  }
  if ($title -eq '') {
    return ''
  }

  $title = $title -replace '\.xlsx$', ''
  $title = $title -replace '^\d{1,2}[-/]\d{1,2}[-/]\d{2,4}\s*', ''
  $title = $title -replace '\bGrosses\b', ''
  $title = $title -replace '\(\d+\)', ''
  $title = $title -replace '\s{2,}', ' '
  return $title.Trim(' ', '-', '_')
}

function Get-MovieTitle {
  param(
    $Worksheet,
    [string]$FileName
  )

  $ignore = @('Title', 'Film', 'Daily Tickets Sold', 'Equation Key')
  $fileTitle = Normalize-Title ([System.IO.Path]::GetFileNameWithoutExtension($FileName))
  $candidateA4 = Normalize-Title (Get-TrimmedText -Worksheet $Worksheet -Row 4 -Column 1)
  if ($candidateA4 -ne '' -and $ignore -notcontains $candidateA4) {
    return $candidateA4
  }

  $candidateA3 = Normalize-Title (Get-TrimmedText -Worksheet $Worksheet -Row 3 -Column 1)
  if ($candidateA3 -ne '' -and $ignore -notcontains $candidateA3) {
    return $candidateA3
  }

  return $fileTitle
}

function Convert-ToDecimal {
  param([object]$Value)

  if ($null -eq $Value) {
    return 0.0
  }

  try {
    return [double]$Value
  } catch {
    $text = ([string]$Value) -replace '[^\d\.\-]', ''
    if ($text -eq '') {
      return 0.0
    }

    try {
      return [double]$text
    } catch {
      return 0.0
    }
  }
}

function Get-EquationPrices {
  param($Worksheet)

  $general = Convert-ToDecimal (Get-CellValue -Worksheet $Worksheet -Row 18 -Column 2)
  $discount = Convert-ToDecimal (Get-CellValue -Worksheet $Worksheet -Row 18 -Column 3)
  $group = Convert-ToDecimal (Get-CellValue -Worksheet $Worksheet -Row 18 -Column 4)

  if ($general -le 0 -and $discount -le 0 -and $group -le 0) {
    $general = Convert-ToDecimal (Get-TrimmedText -Worksheet $Worksheet -Row 6 -Column 2)
    $discount = Convert-ToDecimal (Get-TrimmedText -Worksheet $Worksheet -Row 6 -Column 3)
    $group = Convert-ToDecimal (Get-TrimmedText -Worksheet $Worksheet -Row 6 -Column 4)
  }

  if ($general -le 0) {
    $general = 8.5
  }

  if ($discount -le 0) {
    $discount = 6.5
  }

  if ($group -le 0) {
    $group = 4.0
  }

  return @{
    general  = $general
    discount = $discount
    group    = $group
  }
}

function Get-DayRows {
  return @(
    @{ Row = 7; Name = 'Friday';    Offset = 0; Showtime = '7:30 PM' },
    @{ Row = 8; Name = 'Saturday';  Offset = 1; Showtime = '7:30 PM' },
    @{ Row = 9; Name = 'Sunday';    Offset = 2; Showtime = '2:30 PM' },
    @{ Row = 10; Name = 'Monday';   Offset = 3; Showtime = '7:30 PM' },
    @{ Row = 11; Name = 'Tuesday';  Offset = 4; Showtime = '7:30 PM' },
    @{ Row = 12; Name = 'Wednesday';Offset = 5; Showtime = '7:30 PM' },
    @{ Row = 13; Name = 'Thursday'; Offset = 6; Showtime = '7:30 PM' }
  )
}

function Get-LegacyGrossesFiles {
  param(
    [string]$Path,
    [string[]]$Years
  )

  $files = Get-ChildItem -Path $Path -Recurse -Filter *.xlsx -File | Where-Object {
    $_.Name -notmatch '^~\$' -and
    $_.Name -notmatch 'Blank' -and
    $_.Name -notmatch 'Weekly Gross' -and
    $_.Name -notmatch 'Dollars?' -and
    $_.Name -notmatch 'Box_Office'
  }

  if ($Years.Count -gt 0) {
    $wanted = @{}
    foreach ($year in $Years) {
      $wanted[$year] = $true
    }

    $files = $files | Where-Object {
      foreach ($segment in $_.FullName.Split([System.IO.Path]::DirectorySeparatorChar)) {
        if ($wanted.ContainsKey($segment)) {
          return $true
        }
      }
      return $false
    }
  }

  return $files | Sort-Object FullName
}

function Is-DollarOnlyLayout {
  param($Worksheet)

  $headerB = Get-TrimmedText -Worksheet $Worksheet -Row 6 -Column 2
  $headerC = Get-TrimmedText -Worksheet $Worksheet -Row 6 -Column 3
  $headerD = Get-TrimmedText -Worksheet $Worksheet -Row 6 -Column 4

  return ($headerB -eq 'Total Daily Receipts' -or (($headerC -eq '') -and ($headerD -eq '')))
}

function Get-RowQuality {
  param($Row)

  $ticketBreakdown = ([int]$Row.general_qty + [int]$Row.discount_qty + [int]$Row.group_qty + [int]$Row.live_qty)
  if ($ticketBreakdown -gt 0) {
    return 2
  }

  if ([double]$Row.gross_total -gt 0) {
    return 1
  }

  return 0
}

if ($OutputCsv -eq '') {
  $OutputCsv = Join-Path -Path (Split-Path -Parent $PSCommandPath) -ChildPath 'legacy-grosses-import.csv'
}

if ($WarningsCsv -eq '') {
  $WarningsCsv = Join-Path -Path (Split-Path -Parent $PSCommandPath) -ChildPath 'legacy-grosses-import-warnings.csv'
}

$excel = $null
$worksheet = $null
$workbook = $null
$rows = New-Object System.Collections.Generic.List[object]
$warnings = New-Object System.Collections.Generic.List[object]

try {
  $excel = Get-ExcelApp
  $files = Get-LegacyGrossesFiles -Path $RootPath -Years $IncludeYears

  foreach ($file in $files) {
    $worksheet = $null
    $workbook = $null

    try {
      $workbook = $excel.Workbooks.Open($file.FullName, 0, $true)
      $worksheet = $workbook.Worksheets.Item(1)

      $anchorDate = Get-DateAnchor -Worksheet $worksheet -FilePath $file.FullName
      $movieTitle = Get-MovieTitle -Worksheet $worksheet -FileName $file.Name
      $prices = Get-EquationPrices -Worksheet $worksheet
      $isDollarOnly = Is-DollarOnlyLayout -Worksheet $worksheet

      if ($null -eq $anchorDate) {
        throw "Could not determine anchor date."
      }

      if ($movieTitle -eq '') {
        throw "Could not determine movie title."
      }

      if ($prices.general -le 0 -and $prices.discount -le 0 -and $prices.group -le 0) {
        throw "Could not determine equation-key ticket prices."
      }

      foreach ($day in Get-DayRows) {
        $label = Get-TrimmedText -Worksheet $worksheet -Row $day.Row -Column 1
        if ($label -eq '') {
          continue
        }

        $reportDate = $anchorDate.AddDays([int]$day.Offset)
        $generalQty = 0
        $discountQty = 0
        $groupQty = 0
        $totalTickets = 0
        $gross = 0.0
        $notes = 'Imported from legacy weekly grosses workbook'

        if ($isDollarOnly) {
          $gross = [math]::Round((Convert-ToDecimal (Get-CellValue -Worksheet $worksheet -Row $day.Row -Column 2)), 2)
          if (-not $IncludeZeroRows -and $gross -le 0) {
            continue
          }
          $notes = 'Imported from legacy dollar-only grosses workbook; ticket counts unavailable'
        } else {
          $generalQty = [int](Convert-ToDecimal (Get-CellValue -Worksheet $worksheet -Row $day.Row -Column 2))
          $discountQty = [int](Convert-ToDecimal (Get-CellValue -Worksheet $worksheet -Row $day.Row -Column 3))
          $groupQty = [int](Convert-ToDecimal (Get-CellValue -Worksheet $worksheet -Row $day.Row -Column 4))
          $totalTickets = $generalQty + $discountQty + $groupQty

          if (-not $IncludeZeroRows -and $totalTickets -le 0) {
            continue
          }

          $gross = [math]::Round(
            ($generalQty * $prices.general) +
            ($discountQty * $prices.discount) +
            ($groupQty * $prices.group),
            2
          )
        }

        $rows.Add([pscustomobject]@{
          report_date    = $reportDate.ToString('yyyy-MM-dd')
          movie_title    = $movieTitle
          show_time      = $day.Showtime
          general_qty    = $generalQty
          discount_qty   = $discountQty
          group_qty      = $groupQty
          live_qty       = 0
          total_tickets  = $totalTickets
          gross_total    = ('{0:F2}' -f $gross)
          source_type    = 'legacy_excel_import'
          source_ref     = 'legacy_excel'
          source_file    = $file.FullName
          price_general  = ('{0:F2}' -f $prices.general)
          price_discount = ('{0:F2}' -f $prices.discount)
          price_group    = ('{0:F2}' -f $prices.group)
          notes          = $notes
        })
      }
    } catch {
      $warnings.Add([pscustomobject]@{
        file    = $file.FullName
        warning = $_.Exception.Message
      })
    } finally {
      if ($null -ne $worksheet) {
        Release-ComObjectSafe -Object $worksheet
        $worksheet = $null
      }

      if ($null -ne $workbook) {
        Close-ExcelWorkbook -Workbook $workbook
        $workbook = $null
      }
    }
  }
} finally {
  if ($null -ne $excel) {
    $excel.Quit() | Out-Null
    Release-ComObjectSafe -Object $excel
  }

  [GC]::Collect()
  [GC]::WaitForPendingFinalizers()
}

$dedupedRows = $rows |
  Group-Object report_date, movie_title, show_time |
  ForEach-Object {
    $_.Group |
      Sort-Object `
        @{ Expression = { Get-RowQuality $_ }; Descending = $true }, `
        @{ Expression = { [int]$_.total_tickets }; Descending = $true }, `
        @{ Expression = { [double]$_.gross_total }; Descending = $true }, `
        @{ Expression = { $_.source_file }; Descending = $false } |
      Select-Object -First 1
  }

$dedupedRows | Sort-Object report_date, movie_title, show_time | Export-Csv -Path $OutputCsv -NoTypeInformation -Encoding UTF8

$warnings | Export-Csv -Path $WarningsCsv -NoTypeInformation -Encoding UTF8

Write-Output ("Wrote {0} rows to {1}" -f $dedupedRows.Count, $OutputCsv)
Write-Output ("Wrote {0} warnings to {1}" -f $warnings.Count, $WarningsCsv)
