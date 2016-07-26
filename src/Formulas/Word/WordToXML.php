<?php
namespace Formulas/Word;
/**
 * Class WordToXML - Converts a Microsoft Word document to XML, then extracts key information from the XML.
 * The actual conversion to XML is performed by the 'abiword' Linux-based text editor, executed in command
 * line mode via PHP exec().
 *
 */
class WordToXML {
  private $path2docfile;
  private $path2xmlfile;
  private $expectedRelease;
  private $validColumns;
  private $docVersion;
  private $docState;
  private $tracer;
  private $rows;

  public function __construct($path2docfile,$expectedRelease,$validColumns,$docVersion,$docState,$tracer) {
    $this->path2docfile = $path2docfile;
    $this->tracer = $tracer;
    $this->expectedRelease = $expectedRelease;
    $this->validColumns = $validColumns;
    $this->docVersion = $docVersion;
    $this->docState = $docState;
    $this->rows = array();
    $this->path2xmlfile = $this->convertDocToXML($this->path2docfile);
  }

  protected function log($msg,$severity='info') {
    $this->tracer->log($msg,$severity);
  }

  protected function convertDocToXML($path2docfile) {
    $path2xmlfile = '';
    if (file_exists($path2docfile)) {
      $cmd = "/usr/bin/abiword --to=xml \"$path2docfile\" 2>&1";
      $this->log("Performing conversion to XML: $cmd");
      exec($cmd,$output,$ret);
      if ($ret==0) {
        $path2xmlfile = preg_replace('/\.doc$/','.xml',$path2docfile);
        if (file_exists($path2xmlfile)) {
          $this->log('Conversion to XML completed successfully.');
        } else {
          $this->log("Error: XML file was not created ($path2xmlfile).");
          $path2xmlfile = '';
        }
      } else {
        $this->log("Conversion to XML failed. ret: $ret",'error');
        $this->log(print_r($output,true),'error');
      }
    } else {
      $this->log("File not found: $path2docfile",'error');
    }
    return $path2xmlfile;
  }

  public function getRows() {
    return $this->rows;
  }

  public function parse() {
    $result = false;
    $path = $this->path2xmlfile;
    setlocale(LC_ALL, 'en_US.UTF8');
    if (file_exists($path)) {
      $result = true;
      $expectedRelease = $this->expectedRelease;
      // Load the XML document:
      $this->tracer->log("Opening XML file: $path");
      $xml = simplexml_load_file($path);
      if (!$xml) {
        $this->tracer->log("Failed to open XML file: $path");
        $result = false;
      } else {
        // Keep count of number of KPI defs we find:
        $numKPIs = 0;
        $insertCount = $skipCount = $unknownCount = 0;
        // Find all tables in the document:
        $tables = $xml->xpath("//*/informaltable");
        $foundTable = false;
        $kpilist = array();
        // Loop over the tables:
        foreach ($tables as $table) {
          // Get the rows of this table:
          $rows = $table->xpath("tgroup/tbody/row");
          // Create a caption for the table:
          $numRows = count($rows);
          $cap = "Rows: ".$numRows;
          $r = 1;
          unset($rec);
          $rec = array();
          foreach ($rows as $row) {
            // Get the columns of the row:
            $cols = $row->xpath('entry');
            $colTxt = array();
            foreach ($cols as $col) {
              $paras = $col->xpath('para');
              $s = '';
              foreach ($paras as $para) {
                $phrases = $para->xpath('phrase/descendant-or-self::text()'); // 'phrase/text()'
                foreach ($phrases as $phrase) {
                  $s .= (string)$phrase." ";
                }
                $s .= "\n\n";
              }
              // Convert from UTF-8 to latin1 that we store in the DB.
              // $s = iconv("UTF-8","latin1//TRANSLIT",$s);
              $colTxt[] = trim($s);
            }
            $field = $this->mapFieldName($colTxt[0]);
            $rec[$field] = $colTxt[1];
          }

          if (($numRows < 33) || ($numRows > 34) || !isset($rec['KPI_ID'])) {
            // $this->tracer->log("Not a KPI Def - wrong number of rows: $numRows");
            if (isset($rec['KPI_ID'])) {
              $this->tracer->log("Skipping table that has KPI_ID ({$rec['KPI_ID']}) but wrong number of rows ($numRows) for KPI");
              $skipCount++;
            }
          } else {
            $numKPIs++;
            // We want to change field name for BW_Applicability_Suffix:
            // Fix for typo:
            if (isset($rec['BW_Applicability__Suffix'])) {
              $rec['BW_Applicability_Suffix_Text'] = $rec['BW_Applicability__Suffix'];
              unset($rec['BW_Applicability__Suffix']);
            } else if (isset($rec['BW_Applicability_Suffix'])) {
              // Give it a different name in DB:
              $rec['BW_Applicability_Suffix_Text'] = $rec['BW_Applicability_Suffix'];
              unset($rec['BW_Applicability_Suffix']);
            }
            $msg = $this->checkRecord($rec);
            if (empty($msg)) {
              $insertCount++;
              // Insert the doc version and state:
              $rec['KPI_DOC_Version'] = $this->docVersion;
              $rec['KPI_DOC_State'] = $this->docState;
              // $this->rows[] = $rec;
              $this->appendKPIs($this->rows, $rec);
            } else {
              $unknownCount++;
              $this->tracer->log($msg,'error');
            }
          }
        }
      }
      $this->tracer->log("Found $numKPIs KPIs in this document.  Collected: $insertCount, Skipped: $skipCount, Unexpected Release: $unknownCount");
    }
    return $result;
  }

  private function appendKPIs(&$rows, $kpiRec) {
    // If this KPI record spawns child KPIs, append them to $rows here, else just append the single kpi rec
    $childrenKpis = $this->getChildrenKpis($kpiRec);
    if (!empty($childrenKpis)) {
      foreach ($childrenKpis as $kpi) {
        $rows[] = $kpi;
      }
    } else {
      $rows[] = $kpiRec;
    }
  }

  /**
   * Some KPIs have multiple formulas.  This code generates "children" KPIs so that in the
   * database they are represented by separate KPIs.
   *
   * @param $kpiRec
   * @return array
   */
  private function getChildrenKpis($kpiRec) {
    $childRows = array();
    $kpiId = trim($kpiRec['KPI_ID']);
    $childList = preg_replace("/^[^\(]*/","",$kpiId);
    $baseKpi = trim(str_replace($childList,"",$kpiId));
    if (!empty($childList)) {
      // May have children:
      $childList = str_replace("(","",$childList);
      $childList = str_replace(")","",$childList);
      $childListArray = explode(";",$childList);
      foreach ($childListArray as $child) {
        // echo "Processing child: $child\n";
        $child = trim($child);
        $childRec = $this->getChild($kpiRec,$baseKpi,$child,$childListArray);
        $childRows[] = $childRec;
      }
    }
    return $childRows;
  }

  private function getChild($kpiRec,$baseKpi,$child,$childListArray) {
    $childRec = $kpiRec;
    $childRec['KPI_ID'] = $baseKpi . " ($child)";
    // We need to split the target as well as the measurement method fields
    $childRec['Internal_Target'] = $this->getChildComponent($childRec['Internal_Target'], $child);
    $childRec['Meas_Method'] = $this->chopNonChildComponents($childRec['Meas_Method'], $child, $childListArray);
    return $childRec;
  }

  private function chopNonChildComponents($field,$child,$childList) {
    foreach ($childList as $childToChop) {
      $childToChop = trim($childToChop);
      if ($childToChop != $child) {
        // chop it!
        // $regex = "/eNB Counter Method \(\\\$\\\${$childToChop}\)[.\r\n: \t\w\(\/\)+]*END/";
        // Note: the /s modifier allows the . to match newlines as well as all other chars.
        //       the ? after .* causes it to match as few chars as possible.
        $regex = "/eNB Counter Method \(\\\$\\\${$childToChop}\).*?\sEND/s";
        $field = preg_replace($regex,"",$field);
      }
    }
    // Remove the "($$CA)" "($$Non-CA)" from the formula header.
    $regex = "/Method[\s]*\(\\\$\\\${$child}\)[\s]*:/";
    $field = preg_replace($regex,"Method:",$field);
    return $field;
  }

  private function getChildComponent($field,$child) {
    $component = "";
    $allParts = explode("($$",$field);
    $component = $allParts[0];
    $childStr = $child . ")";
    $childLength = strlen($childStr);
    for ($i=1; $i<count($allParts); $i++) {
      $childPart = substr($allParts[$i],0,$childLength);
      if ($childPart == $childStr) {
        $part = substr($allParts[$i],$childLength);
        $component .= $part;
      }
    }

    return $component;
  }

  private function checkRecord($rec) {
    $msg = '';
    if ($rec['KPI_Release'] !== $this->expectedRelease) {
      $msg = "{$rec['KPI_ID']} : Unexpected value in KPI Release field: ".$rec['KPI_Release']." expecting: {$this->expectedRelease} - not uploaded.";
    } else {
      $invalidCols = '';
      foreach ($rec as $key=>$val) {
        $keyLower = strtolower($key);
        if (!in_array($keyLower,$this->validColumns)) {
          $invalidCols .= empty($key) ? "(blank)" : $key;
        }
      }
      if (!empty($invalidCols)) {
        $msg = "{$rec['KPI_ID']} : The following field names are invalid: $invalidCols - not uploaded";
      }
    }
    return $msg;
  }

  private function mapFieldName($s) {
    $map = array(
      "KPI_Description"=>"KPI_Desc",
      "Measurement_Method" => "Meas_Method",
      "Application" => "App",
      "Dashboard_Priority" => "Priority",
    );
    $k = str_replace(" ","_",$s);
    if (isset($map[$k])) {
      $k = $map[$k];
    }
    return $k;
  }
}
