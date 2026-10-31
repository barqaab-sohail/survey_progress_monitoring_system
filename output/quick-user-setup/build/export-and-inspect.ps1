param([string]$DocumentPath,[string]$PdfPath)
$ErrorActionPreference='Stop'
if ((Split-Path $DocumentPath -Leaf) -ne 'Quick_User_Setup_and_Assignment_Guide.docx') { throw 'Only the new quick guide may be opened by this build script.' }
$guideWord = $null
$guideDocument = $null
try {
    $guideWord = New-Object -ComObject Word.Application
    if ($guideWord.Documents.Count -ne 0) { $guideWord = $null; throw 'Refusing to use an existing Word session containing documents.' }
    $guideWord.Visible = $false
    $guideWord.DisplayAlerts = 0
    $guideDocument = $guideWord.Documents.Open($DocumentPath,$false,$false,$false)
    $guideDocument.ActiveWindow.View.Type = 3
    # Keep the short guide clean: the title and numbered sections identify it;
    # a footer carries the date and page number, with no repeated running header.
    foreach ($guideSection in $guideDocument.Sections) {
        foreach ($guideHeader in $guideSection.Headers) { $guideHeader.Range.Text = '' }
    }
    $guideDocument.Fields.Update() | Out-Null
    $guideDocument.Repaginate()
    $guideDocument.ExportAsFixedFormat($PdfPath,17,$false,0,0,1,9999,0,$true,$true,1,$true,$true,$false)
    # Export forces full print pagination, including images. Cache it in the NEW DOCX only.
    $guideDocument.Fields.Update() | Out-Null
    foreach ($guideSection in $guideDocument.Sections) {
        foreach ($guideFooter in $guideSection.Footers) { $guideFooter.Range.Fields.Update() | Out-Null }
    }
    $guidePages = $guideDocument.ComputeStatistics(2)
    $guideDocument.Save()
    $guideDocument.ExportAsFixedFormat($PdfPath,17,$false,0,0,1,9999,0,$true,$true,1,$true,$true,$false)
    $report=@{pages=$guidePages;images=$guideDocument.InlineShapes.Count;words=$guideDocument.Words.Count;page_text=@()}
    for ($guidePage=1; $guidePage -le $guidePages; $guidePage++) {
        $start=$guideDocument.GoTo(1,1,$guidePage).Start
        $end=if ($guidePage -lt $guidePages) {$guideDocument.GoTo(1,1,$guidePage+1).Start} else {$guideDocument.Content.End}
        $report.page_text += @{page=$guidePage;text=$guideDocument.Range($start,$end).Text}
    }
    $report | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath (Join-Path (Split-Path $DocumentPath) 'Quick_User_Setup_Layout_Check.json') -Encoding UTF8
    Write-Output "Rendered new guide: $guidePages pages; $($report.images) images."
} finally {
    if ($null -ne $guideDocument) { $guideDocument.Close(0) }
    if ($null -ne $guideWord) { $guideWord.Quit() }
}
