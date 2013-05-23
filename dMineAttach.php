#!/usr/bin/php
<?php

require_once('/usr/share/jpgraph/jpgraph.php');
require_once('/usr/share/jpgraph/jpgraph_line.php');

$tFile = 'terms.txt';
$GSAHost = 'hostname.goes.here';
$cmds = array();

// Parse args and determine proper operation
if($argc < 2) usage();
if($argv[1] == 'multi'){
  print "Category:multi";
  for($ii=0; $ii < $argc - 2; $ii++){
    print "\nProcessing \"" . $argv[$ii+2] . "\" in multi mode";
    $cmds[$ii]['category'] = 'multi';
    $cmds[$ii]['term'] = "\"" . $argv[$ii+2] . "\"";
  }
}elseif($argv[1] == 'file'){
  if($argc > 2) usage();
  print "Processing static terms file\n";
  $tf = file($tFile);
  foreach($tf as $t){
    if(strstr($t, "#")) continue;
    if(strlen($t) < 4) continue;
    if((strpos(trim($t), "@") === 0)){
      $cat = trim(trim($t, '@'));
      if($cat == 'multi') goto parseArgsEnd;
      continue;
    }
    $term = trim(rtrim($t, "\r\n")); // Remove trailing CRs and newlines
    array_push($cmds, array('term' => "\"" . $term . "\"", 'category' => $cat));
  }
}else{
  if($argc == 2){
    $terms = getTerms($argv[1]);
    if(! $terms){
      print "Category " . $argv[1] . " not found in terms file\n";
      usage();
    }else{
      print "Processing static terms file for category " . $argv[1] . "\n";
      for($ii=0; $ii < count($terms); $ii++){
        $cmds[$ii]['category'] = $argv[1];
        $cmds[$ii]['term'] = $terms[$ii];
      }
    }
  }else{
    print "Category:" . $argv[1];
    for($ii=2; $ii< $argc; $ii++){
      $cmds[$ii-2]['category'] = $argv[1];
      $cmds[$ii-2]['term'] = "\"" . $argv[$ii] . "\"";
      print "\nProcessing \"" . $argv[$ii] . "\"";
    }
    print "\n";
  }
}
parseArgsEnd:

// Make our quarters and ranges arrays
$ranges = $quarters = array();
for($kk=2009; $kk<=date('Y'); $kk++){
  for($jj=1; $jj<11; $jj = $jj+3){
    if($jj < 10){
      $day = "01";
      $month = $jj + 3;
    }else{
      $day = "31";
      $month = $jj + 2;
    }
    if($kk == date('Y') && $month >= date('n')) break;
    array_push($ranges, $kk . "-" . $jj . "-01.." . $kk . "-" . $month . "-" . $day);

    if($jj == 1) $q = 1;
    else{
      $q++;
    }
    array_push($quarters, $kk . "-Q" . $q);
  }
}

// Execute queries on GSA
for($ii=0; $ii < count($cmds); $ii++){
  $cmds[$ii]['results'] = array();
  foreach($ranges as $r){
    $rv = dMine("SRAttach", $cmds[$ii]['term'], $r);
    if($rv !== false){ 
      array_push($cmds[$ii]['results'], $rv);
    }else{
      die("Fatal error:GSA unreachable");
    }
  }

  if($cmds[$ii]['category'] == 'multi'){
    foreach(getTerms('multi') as $t){
      if(trim($cmds[$ii]['term'], "\"") == $t) continue;
      $cmds[$ii][$t] = array();
      $cmds[$ii][$t]['results'] = array();
      $cmds[$ii][$t]['term'] = "\"" . $t . "\"";

      foreach($ranges as $r){
        $rv = dMine("SRAttach", $cmds[$ii]['term'] . " " . $cmds[$ii][$t]['term'], $r);
        if($rv !== false){
          array_push($cmds[$ii][$t]['results'], $rv);
        }else{
          die("Fatal error:GSA unreachable");
        }
      }
    }

    $csvDir = "csv/multi/" . trim(str_replace(" ", "_", $cmds[$ii]['term']), "\"");
    if(! file_exists($csvDir)) makeDir($csvDir);
    $pngDir = "png/multi/" . trim(str_replace(" ", "_", $cmds[$ii]['term']), "\"");
    if(! file_exists($pngDir)) makeDir($pngDir);

    foreach(getTerms('multi') as $t){
      if(trim($cmds[$ii]['term'], "\"") == $t) continue;
      $data = array(array('header' => "Quarters", 'results' => $quarters), array('header' => $cmds[$ii]['term'], 'results' => $cmds[$ii]['results']),
                    array('header' => $cmds[$ii]['term'] . " and " . $cmds[$ii][$t]['term'], 'results' => $cmds[$ii][$t]['results']));

      genCSV($data, $csvDir . "/" . trim(str_replace(" ", "_", $cmds[$ii][$t]['term']), "\"") . ".csv") or
        die("\nFatal Error generating CSV");

      $title = $cmds[$ii]['term'] . "\n" . $cmds[$ii]['term'] . " && " . $cmds[$ii][$t]['term'];
      genGraph($data, $title, $pngDir . "/" . trim(str_replace(" ", "_", $cmds[$ii][$t]['term']), "\"") . ".png") or
        die("Fatal Error generating graph");
    }
  }else{
    $data = array(array('header' => "Quarters", 'results' => $quarters), array('header' => $cmds[$ii]['term'], 'results' => $cmds[$ii]['results']));

    genCSV($data, "csv/" . $cmds[$ii]['category'] . "/" . trim(str_replace(" ", "_", $cmds[$ii]['term']), "\"") . ".csv") or
      die("\nFatal Error generating CSV");

    genGraph($data, $cmds[$ii]['term'], "png/" . $cmds[$ii]['category'] . "/" . trim(str_replace(" ", "_", $cmds[$ii]['term']), "\"") . ".png") or
      die("\nFatal Error generating graph");
  }
}
//print_r($cmds);
print "\n";

///////////////////////////
// END PROGRAM EXECUTION //
///////////////////////////

// Generates our line graph png and writes it to file
//function genGraph($dx, $dy, $title, $f){
function genGraph($data, $title, $f){
  foreach($data as $d){
    if(count($d) != count($data[0])){
      print "\nInconsistent values passed to genGraph. All arrays MUST have equal quantity of values.";
      return false;
    }
  }

  $graph = new Graph(800,400);
  $graph->title->Set($title);
  $graph->title->SetFont(FF_FONT1,FS_BOLD);
  $graph->img->SetMargin(40,40,40,80);

  $values = array();
  for($ii=0; $ii < count($data); $ii++){
    if(strtolower($data[$ii]['header']) == 'quarters') continue;
    for($jj=0; $jj < count($data[$ii]['results']); $jj++){
      if($data[$ii]['results'][$jj] < 0){
        print "\nNegative Y-axis values not permitted";
        return false;
      }
      array_push($values, $data[$ii]['results'][$jj]);
    }
  }

  // Due to a bug in jpGraph we cannot accept all of $values to be the same integer unless that integer is zero
  $max = max($values);
  $test = true;
  foreach($values as $v){
    if($v != $max) $test = false;
  }
  if($test){
    if($max != 0){
      print "\nAll Y-axis values cannot be same unless zero";
      return false;
    }
  }
  unset($test);

  $graph->SetScale("textlin", 0, $max);
  $graph->xaxis->SetTextTickInterval(1,0);
  $graph->xaxis->SetTextLabelInterval(1);
  $graph->xaxis->SetLabelAngle(90);

  if(count(array_filter($values, "notZero"))){ // Check if y-axis contains any non-Zero values
    $yTicks = ceil(standardDeviation($values) / 2);
    $graph->yaxis->scale->ticks->Set($yTicks, $yTicks);
  }

  if(count($data) < 3){
    $colors = array('orange', 'black');
  }else{
    $colors = array('orange', 'green', 'black', 'red', 'blue');
  }

  $p = array();
  for($ii=0; $ii < count($data); $ii++){
    if(strtolower($data[$ii]['header']) == 'quarters'){
      $graph->xaxis->SetTickLabels($data[$ii]['results']);      
    }else{
      $p[$ii] = new LinePlot($data[$ii]['results']);
      $p[$ii]->SetColor($colors[$ii]);
      $graph->Add($p[$ii]);
    }
  }

  @$graph->Stroke(_IMG_HANDLER);
  $graph->img->Stream($f);
  return setFileAtt($f);
}

// Generates our CSV data and writes it to file
// Takes a 2 dimensional array and an output file
function genCSV($data, $f){
  foreach($data as $d){
    if(count($d) != count($data[0])){
      print "\nInconsistent values passed to genCSV. All arrays MUST have equal number of values.";
      return false;
    }
  }

  $out = fopen($f, 'w');
  $str = "";
  foreach($data as $d){
    $str .= $d['header'] . ",";
  }
  fwrite($out, trim($str, ",") . "\n");

  for($ii=0; $ii < count($data[0]['results']); $ii++){
    $str = "";
    for($jj=0; $jj < count($data); $jj++){
      $str .= $data[$jj]['results'][$ii] . ",";
    }
    fwrite($out, trim($str, ",") . "\n");
  }
  fclose($out);

  return setFileAtt($f);
}

// Performs data mining query on GSA
// Takes the site to search on, query term and a date range(optional)
// $dRange takes the format that the GSA expects for Dynamic Navigation
// Returns number of times term appears in corpus
// Returns false on failure
function dMine($site, $term, $dRange=false){
  // If the GSA frontend extr_dMine changes this offset may need to be updated. 
  $fOffset = 170;
  
  // How many times we retry before giving up
  $retries = 10;

  // We must set dMine=1 so that this query doesn't get counted later by our statistics schtuff
  $qStrFront = "http://" . $GLOBALS['GSAHost'] . "/search?q=";
  $qStrBack = "&btnG=Search&client=extr_dMine&output=xml_no_dtd&proxystylesheet=extr_dMine&filter=0&dMine=1";
  if($dRange){
    $qStrMid = urlencode($term) . "+daterange%3A" . urlencode($dRange) . "&site=" . $site;
  }else{
    $qStrMid = urlencode($term) . "&site=" . $site;
  }
  $qUrl = $qStrFront . $qStrMid . $qStrBack;
  $f = @file($qUrl);

  if($f === false){
    $fSafe = 0;
    do{
      print "\nTransient Failure, Retrying:FailSafe:" . $fSafe . " Term:" . $term . " Range:" . $dRange;
      $fSafe++;
      sleep($fSafe);
      $f = @file($qUrl);
    }while(($f === false) && ($fSafe < $retries));
  }
  if($f === false) return false;

  if(strpos($f[$fOffset], $term)) $rv = trim($f[$fOffset]);
  else{
    return 0;
  }

  list($junk, $junk, $junk, $rv) = split("<b>", $rv);
  list($rv) = split("<", $rv);
  return $rv;
}

// Returns all terms for a specific category as an array
function getTerms($cat){
  $rv = array();
  $tf = file($GLOBALS['tFile']);
  $found = $neverFound = false;
  foreach($tf as $t){
    if(strstr($t, "#")) continue;
    if(strlen($t) < 4) continue;
    if($found){
      if((strpos(trim($t), "@") === 0)) $found = false;
      else{
        array_push($rv, trim($t));
      }
    }else{
      if((strpos(trim($t), "@" . $cat) === 0)) $found = $neverFound = true;
    }
  }
  if(! $neverFound) return false;
  else{
    return $rv;
  }
}

function makeDir($name){
  if (! mkdir($name, 0775)) return false;
  return setFileAtt($name);
}

function setFileAtt($name){
  if(! chmod($name, 0775)) return false;
  if(! chown($name, "www-data")) return false;
  if(! chgrp($name, "www-data")) return false;
  return true;
}

// Returns true if passed value is NOT 0
// Rather stupid that PHP requires such a thing
function notZero($x){
  if($x == 0) return false;
  return true;
}

// Calculate standard deviation for an array of values
// Stolen from http://php.net/manual/en/function.stats-standard-deviation.php
function standardDeviation($aValues, $bSample = false){
  $fMean = array_sum($aValues) / count($aValues);
  $fVariance = 0.0;
  foreach ($aValues as $i){
    $fVariance += pow($i - $fMean, 2);
  }
  $fVariance /= ( $bSample ? count($aValues) - 1 : count($aValues) );
  return (float) sqrt($fVariance);
}

// Prints usage and exits
function usage(){
  print "dMineAttach.php category [ term ... ]";
  print "\nExample: dmineAttach.php cli \"enable stp\"";
  print "\n\n(Category)\t(Meaning)";
  print "\ncli\t\tCLI commands";
  print "\nversions\tsoftware versions";
  print "\nerrors\t\terror messages";
  print "\nplatforms\thardware platforms";
  print "\nfile\t\t{special}Read terms from file terms.txt";
  print "\nmulti\t\t{special}Process given term in combination with others taken from file terms.txt";
  print "\n";
  exit();
}
?>
