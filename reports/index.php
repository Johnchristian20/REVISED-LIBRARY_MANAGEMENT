<?php
require __DIR__.'/../includes/auth.php'; require_admin(); require __DIR__.'/../database.php';
require __DIR__.'/../includes/loan_fines.php'; refreshOverdueFines($conn);
$kind=$_GET['type']??'loans'; $q=trim($_GET['q']??'');
$status=trim($_GET['status']??'');
$reportStatuses=[
 'books'=>['Available','Checked Out','Reserved','Under Maintenance','Lost'],
 'members'=>['Active','Suspended','Expired','Pending'],
 'loans'=>['Ongoing','Returned','Overdue','Lost'],
 'branches'=>[]
];
$cityResult=mysqli_query($conn,'SELECT DISTINCT city FROM branches ORDER BY city');
if($cityResult) while($city=mysqli_fetch_assoc($cityResult)) $reportStatuses['branches'][]=$city['city'];
$reports=[
 'books'=>['title'=>'Book inventory','headers'=>['ISBN','Title','Author','Genre','Year','Branch','Status'],'sql'=>'SELECT b.isbn,b.title,b.author_name,b.genre,b.publication_year,br.branch_name,b.status FROM books b JOIN branches br ON br.branch_id=b.allocated_branch_id WHERE (?="" OR b.title LIKE CONCAT("%",?,"%") OR b.author_name LIKE CONCAT("%",?,"%") OR b.isbn LIKE CONCAT("%",?,"%")) AND (?="" OR b.status=?) ORDER BY b.title'],
 'members'=>['title'=>'Member directory','headers'=>['Member no.','Name','Email','Type','Branch','Status','Expires'],'sql'=>'SELECT m.membership_number,CONCAT(m.first_name," ",m.last_name),m.email,m.membership_type,br.branch_name,m.account_status,m.expiration_date FROM members m JOIN branches br ON br.branch_id=m.home_branch_id WHERE (?="" OR m.first_name LIKE CONCAT("%",?,"%") OR m.last_name LIKE CONCAT("%",?,"%") OR m.membership_number LIKE CONCAT("%",?,"%")) AND (?="" OR m.account_status=?) ORDER BY m.last_name,m.first_name'],
 'loans'=>['title'=>'Loan activity','headers'=>['Reference','Book','Member','Branch','Checkout','Due','Returned','Status','Fine'],'sql'=>'SELECT l.loan_reference,b.title,CONCAT(m.first_name," ",m.last_name),br.branch_name,l.checkout_date,l.due_date,COALESCE(l.return_date,"-"),l.loan_status,l.late_fine_amount FROM book_loans l JOIN books b ON b.book_id=l.book_id JOIN members m ON m.member_id=l.member_id JOIN branches br ON br.branch_id=l.checkout_branch_id WHERE (?="" OR l.loan_reference LIKE CONCAT("%",?,"%") OR b.title LIKE CONCAT("%",?,"%") OR m.last_name LIKE CONCAT("%",?,"%")) AND (?="" OR l.loan_status=?) ORDER BY l.checkout_date DESC'],
 'branches'=>['title'=>'Branch directory','headers'=>['Code','Branch','Street address','City','Province','Phone','Email','Capacity'],'sql'=>'SELECT br.branch_code,br.branch_name,br.street_address,br.city,br.state_province,br.contact_phone,br.contact_email,br.capacity FROM branches br WHERE (?="" OR br.branch_name LIKE CONCAT("%",? ,"%") OR br.city LIKE CONCAT("%",? ,"%") OR br.branch_code LIKE CONCAT("%",? ,"%")) AND (?="" OR br.city=?) ORDER BY br.branch_name']
];
if(!isset($reports[$kind])) $kind='loans'; $report=$reports[$kind];
if(!in_array($status,$reportStatuses[$kind],true)) $status='';
$stmt=mysqli_prepare($conn,$report['sql']);
mysqli_stmt_bind_param($stmt,'ssssss',$q,$q,$q,$q,$status,$status);
mysqli_stmt_execute($stmt); $result=mysqli_stmt_get_result($stmt); $rows=[]; while($row=mysqli_fetch_row($result)) $rows[]=$row; mysqli_stmt_close($stmt);
$layout = ($_GET['layout'] ?? '') === 'portrait' ? 'portrait' : 'landscape';
if(($_GET['format']??'')==='csv') { header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="'.$kind.'-report-'.date('Y-m-d').'.csv"'); echo "\xEF\xBB\xBF"; $out=fopen('php://output','w'); fputcsv($out,$report['headers']); foreach($rows as $row) fputcsv($out,$row); fclose($out); exit; }
if(($_GET['format']??'')==='docx') {
  if(!class_exists('ZipArchive')) { http_response_code(500); exit('DOCX export needs the PHP ZIP extension enabled in Laragon.'); }
  $xmlEscape=static fn($value)=>htmlspecialchars((string)$value,ENT_XML1|ENT_QUOTES,'UTF-8');
  $columnCount=count($report['headers']); $pageWidth=$layout==='landscape'?15840:12240; $pageHeight=$layout==='landscape'?12240:15840; $usableWidth=$pageWidth-1440; $columnWidth=(int)floor($usableWidth/max(1,$columnCount));
  $table='<w:tbl><w:tblPr><w:tblW w:w="'.$usableWidth.'" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders><w:top w:val="single" w:sz="6" w:color="CCD5CC"/><w:left w:val="single" w:sz="6" w:color="CCD5CC"/><w:bottom w:val="single" w:sz="6" w:color="CCD5CC"/><w:right w:val="single" w:sz="6" w:color="CCD5CC"/><w:insideH w:val="single" w:sz="4" w:color="DDE3DC"/><w:insideV w:val="single" w:sz="4" w:color="DDE3DC"/></w:tblBorders><w:tblCellMar><w:top w:w="90" w:type="dxa"/><w:start w:w="100" w:type="dxa"/><w:bottom w:w="90" w:type="dxa"/><w:end w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
  for($i=0;$i<$columnCount;$i++) $table.='<w:gridCol w:w="'.$columnWidth.'"/>';
  $table.='</w:tblGrid>';
  foreach(array_merge([$report['headers']],$rows) as $rowIndex=>$record) {
    $table.='<w:tr>'.($rowIndex===0?'<w:trPr><w:tblHeader/></w:trPr>':'');
    foreach($record as $value) {
      $bold=$rowIndex===0; $shade=$bold?'<w:shd w:fill="E8EFE8"/>':'';
      $runProps=$bold?'<w:rPr><w:b/><w:color w:val="183D32"/></w:rPr>':'';
      $table.='<w:tc><w:tcPr><w:tcW w:w="'.$columnWidth.'" w:type="dxa"/>'.$shade.'</w:tcPr><w:p><w:pPr><w:spacing w:after="0"/><w:keepLines/></w:pPr><w:r>'.$runProps.'<w:t xml:space="preserve">'.$xmlEscape($value).'</w:t></w:r></w:p></w:tc>';
    }
    $table.='</w:tr>';
  }
  if(!$rows) $table.='<w:tr><w:tc><w:tcPr><w:gridSpan w:val="'.$columnCount.'"/></w:tcPr><w:p><w:r><w:t>No records found.</w:t></w:r></w:p></w:tc></w:tr>';
  $table.='</w:tbl>';
  $docXml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:pPr><w:spacing w:after="100"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="32"/><w:color w:val="183D32"/></w:rPr><w:t>'.$xmlEscape('The Reading Room · '.$report['title']).'</w:t></w:r></w:p><w:p><w:r><w:rPr><w:color w:val="748078"/><w:sz w:val="18"/></w:rPr><w:t>'.$xmlEscape('Generated '.date('Y-m-d H:i').' · '.count($rows).' records').'</w:t></w:r></w:p>'.$table.'<w:sectPr><w:pgSz w:w="'.$pageWidth.'" w:h="'.$pageHeight.'" w:orient="'.$layout.'"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="360" w:footer="360" w:gutter="0"/></w:sectPr></w:body></w:document>';
  $contentTypes='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>';
  $rootRels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>';
  $documentRels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
  $styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="18"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="80"/></w:pPr></w:pPrDefault></w:docDefaults></w:styles>';
  $temp=tempnam(sys_get_temp_dir(),'readingroom-docx-'); $zip=new ZipArchive();
  if($zip->open($temp,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) { @unlink($temp); http_response_code(500); exit('Could not prepare the DOCX report.'); }
  $zip->addFromString('[Content_Types].xml',$contentTypes); $zip->addFromString('_rels/.rels',$rootRels); $zip->addFromString('word/document.xml',$docXml); $zip->addFromString('word/_rels/document.xml.rels',$documentRels); $zip->addFromString('word/styles.xml',$styles); $zip->close();
  header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document'); header('Content-Disposition: attachment; filename="'.$kind.'-report-'.date('Y-m-d').'.docx"'); header('Content-Length: '.filesize($temp)); readfile($temp); @unlink($temp); exit;
}
if(($_GET['format']??'')==='pdf') {
  function report_pdf_ascii($value): string {
    $value=(string)$value;
    if(function_exists('iconv')) $value=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);
    $value=preg_replace('/[^\x20-\x7E]/','',$value);
    return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$value);
  }
  $widths = $kind==='books' ? [70,180,115,90,48,145,100] : ($kind==='members' ? [85,105,135,75,125,80,90] : ($kind==='branches' ? [55,115,110,65,80,75,125,50] : [72,110,95,100,85,85,85,75,60]));
  $pageWidth=$layout==='landscape'?842:595; $pageHeight=$layout==='landscape'?595:842; $left=$layout==='landscape'?30:24; $usableWidth=$pageWidth-($left*2); $baseWidth=array_sum($widths); if($baseWidth>$usableWidth) $widths=array_map(static fn($width)=>max(24,(int)floor($width*$usableWidth/$baseWidth)),$widths); $tableWidth=array_sum($widths); $tableTop=$layout==='landscape'?540:785; $headerHeight=25; $bottomMargin=34; $availableHeight=$tableTop-$headerHeight-$bottomMargin;
  $preparedRows=[];
  foreach($rows as $record) {
    $cells=[]; $maxLines=1;
    foreach($report['headers'] as $column=>$unused) {
      $value=report_pdf_ascii($record[$column]??''); $limit=max(5,(int)floor(($widths[$column]-10)/4.2));
      $cellLines=explode("\n",wordwrap($value,$limit,"\n",true));
      $cells[]=$cellLines; $maxLines=max($maxLines,count($cellLines));
    }
    $preparedRows[]=['cells'=>$cells,'height'=>max(22,8+($maxLines*10))];
  }
  if(!$preparedRows) { $cells=array_fill(0,count($report['headers']),['']); $cells[0]=['No records found.']; $preparedRows[]=['cells'=>$cells,'height'=>22]; }
  $pageRows=[]; $currentRows=[]; $usedHeight=0;
  foreach($preparedRows as $prepared) {
    if($currentRows && $usedHeight+$prepared['height']>$availableHeight) { $pageRows[]=$currentRows; $currentRows=[]; $usedHeight=0; }
    $currentRows[]=$prepared; $usedHeight+=$prepared['height'];
  }
  if($currentRows) $pageRows[]=$currentRows;
  $pageCount=count($pageRows); $objects=[]; $pageIds=[]; $fontRegular=3+2*$pageCount; $fontBold=$fontRegular+1; $objects[1]='<< /Type /Catalog /Pages 2 0 R >>';
  foreach($pageRows as $pageIndex=>$pageData) {
    $pageId=3+2*$pageIndex; $contentId=$pageId+1; $pageIds[]="$pageId 0 R";
    $stream="0.12 0.22 0.17 rg\nBT /F2 15 Tf $left ".($tableTop+30)." Td (THE READING ROOM - ".report_pdf_ascii($report['title']).") Tj ET\n";
    $pageLabel='Generated '.date('Y-m-d H:i').'    Page '.($pageIndex+1).' of '.$pageCount;
    $stream.="0.38 0.43 0.39 rg\nBT /F1 8 Tf $left ".($tableTop+15)." Td (".report_pdf_ascii($pageLabel).") Tj ET\n";
    $stream.="0.91 0.94 0.90 rg $left ".($tableTop-$headerHeight)." $tableWidth $headerHeight re f\n";
    $x=$left;
    foreach($report['headers'] as $column=>$heading) {
      $label=report_pdf_ascii($heading); $stream.="0.13 0.22 0.17 rg\nBT /F2 8 Tf ".($x+5).' '.($tableTop-16)." Td ($label) Tj ET\n"; $x+=$widths[$column];
    }
    $rowTop=$tableTop-$headerHeight;
    foreach($pageData as $rowIndex=>$prepared) {
      $rowHeight=$prepared['height'];
      if($rowIndex%2===1) $stream.="0.985 0.985 0.97 rg $left ".($rowTop-$rowHeight)." $tableWidth $rowHeight re f\n";
      $x=$left;
      foreach($prepared['cells'] as $column=>$cellLines) {
        foreach($cellLines as $lineIndex=>$line) {
          $baseline=$rowTop-12-($lineIndex*10);
          $stream.="0.18 0.21 0.19 rg\nBT /F1 8 Tf ".($x+5).' '.$baseline." Td ($line) Tj ET\n";
        }
        $x+=$widths[$column];
      }
      $rowTop-=$rowHeight;
    }
    $tableBottom=$rowTop;
    $stream.="0.72 0.76 0.72 RG 0.55 w\n";
    $y=$tableTop; $stream.="$left $y m ".($left+$tableWidth)." $y l S\n";
    $y=$tableTop-$headerHeight; $stream.="$left $y m ".($left+$tableWidth)." $y l S\n";
    foreach($pageData as $prepared) { $y-=$prepared['height']; $stream.="$left $y m ".($left+$tableWidth)." $y l S\n"; }
    $x=$left; $stream.="$x $tableTop m $x $tableBottom l S\n"; foreach($widths as $width){$x+=$width;$stream.="$x $tableTop m $x $tableBottom l S\n";}
    $objects[$pageId]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$pageWidth.' '.$pageHeight.'] /Resources << /Font << /F1 '.$fontRegular.' 0 R /F2 '.$fontBold.' 0 R >> >> /Contents '.$contentId.' 0 R >>';
    $objects[$contentId]='<< /Length '.strlen($stream).">>\nstream\n".$stream."\nendstream";
  }
  $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$pageIds).'] /Count '.$pageCount.' >>';
  $objects[$fontRegular]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'; $objects[$fontBold]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
  ksort($objects); $pdf="%PDF-1.4\n"; $offsets=[0]; foreach($objects as $id=>$object){$offsets[$id]=strlen($pdf);$pdf.="$id 0 obj\n$object\nendobj\n";}
  $xref=strlen($pdf); $max=max(array_keys($objects)); $pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n"; for($i=1;$i<=$max;$i++) $pdf.=sprintf('%010d 00000 n ',$offsets[$i]??0)."\n";
  $pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
  header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="'.$kind.'-report-'.date('Y-m-d').'.pdf"'); header('Content-Length: '.strlen($pdf)); echo $pdf; exit;
}
$pageTitle='Reports & exports'; $basePath='../'; require __DIR__.'/../includes/header.php';
?>
<section class="panel"><div class="panel-heading"><div><div class="eyebrow">LIBRARY INSIGHTS</div><h2>Reports & exports</h2><p>Filter your library records, preview the table, and save it as PDF, CSV, or Word.</p></div></div>
<form class="filter-bar" method="get"><label>Report<select name="type" onchange="this.form.submit()"><?php foreach($reports as $key=>$item): ?><option value="<?=e($key)?>" <?=$kind===$key?'selected':''?>><?=e($item['title'])?></option><?php endforeach; ?></select></label><label>Search<input type="search" name="q" value="<?=e($q)?>" placeholder="Search report records"></label><label><?=e($kind==='books'?'Book status':($kind==='members'?'Member status':($kind==='branches'?'Branch city':'Loan status')))?><select name="status"><option value=""><?=e($kind==='branches'?'All cities':'All statuses')?></option><?php foreach($reportStatuses[$kind] as $optionStatus): ?><option value="<?=e($optionStatus)?>" <?=$status===$optionStatus?'selected':''?>><?=e($optionStatus)?></option><?php endforeach; ?></select></label><button class="button button-outline" type="submit">Apply filters</button><button class="button button-primary" type="button" data-open-report-preview>File preview</button></form>
<div class="results-line"><strong><?=number_format(count($rows))?></strong> records · <?=e($report['title'])?></div><div class="table-wrap" id="reportPreviewSource"><table><thead><tr><?php foreach($report['headers'] as $h): ?><th><?=e(strtoupper($h))?></th><?php endforeach; ?></tr></thead><tbody><?php foreach($rows as $r): ?><tr><?php foreach($r as $cell): ?><td><?=e($cell)?></td><?php endforeach; ?></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td class="empty-state" colspan="<?=count($report['headers'])?>">No report records found.</td></tr><?php endif; ?></tbody></table></div></section>
<dialog class="file-preview-dialog" id="filePreviewDialog" aria-labelledby="filePreviewTitle">
  <div class="file-preview-header"><div><div class="eyebrow">REPORT EXPORT</div><h2 id="filePreviewTitle">File preview</h2><p><?=e($report['title'])?> · <?=number_format(count($rows))?> filtered records</p></div><button class="file-preview-close" type="button" data-close-file-preview aria-label="Close preview">×</button></div>
  <div class="file-preview-controls"><label>Save file as<select id="filePreviewFormat"><option value="pdf">PDF document</option><option value="csv">CSV spreadsheet</option><option value="docx">Word document (DOCX)</option></select></label><label>Page layout<select id="filePreviewLayout"><option value="landscape">Landscape</option><option value="portrait">Portrait</option></select></label><span class="file-preview-hint" id="filePreviewHint">Landscape is recommended for wide report tables.</span></div>
  <div class="file-preview-sheet landscape" id="filePreviewSheet"><div class="file-preview-sheet-title"><strong>THE READING ROOM</strong><span><?=e($report['title'])?></span></div><div class="file-preview-table" id="filePreviewTable"></div><div class="file-preview-sheet-footer">Filtered report · <?=number_format(count($rows))?> records</div></div>
  <div class="file-preview-actions"><button class="button button-outline" type="button" data-close-file-preview>Cancel</button><a class="button button-primary" id="saveReportFile" data-export-download href="#">Save file</a></div>
</dialog>
<?php require __DIR__.'/../includes/footer.php'; ?>
