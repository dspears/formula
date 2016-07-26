<?php
namespace Formulas/Parser;
/**
 * Formula Parser.
 *
 * Example usage:
 *
 *  foreach ($uploader->getRows() as $row) {
 *    $parser = new FormulaParser($row['Formula'],'Begin:');
 *    $parser->run();
 *    echo $parser->report();
 *    $parser->dumpSymbolTable();
 *  }
 *
 */

require_once "Lexer.php";

/**
 * Parser for System Performance formulas.  This is the strict parser that expects a specific format.
 */
class FormulaParser {
  protected $src;
  protected $formulaSrc;
  protected $cleanFormulaSrc;
  protected $symbolTable;
  protected $startPhrase;
  protected $status;
  protected $errors;
  protected $severity;
  protected $BEGIN;
  protected $END;
  protected $kpiRows;
  protected $variableValues;
  const NORMAL = 0;
  const WARNING = 1;
  const ERROR = 2;

  // debugLevel 0 = no error or debug output
  // debugLevel 1 = error message output
  // debugLevel 2 = errors plus debug from Parser
  // debugLevel 3 = all of Level 2, plus debug from Lexer
  public function __construct($src,$startPhrase,$debugLevel=0,$pmSrsTable=null,$pmSrsRelease='',$kpiRows=array()) {
    $this->errors = array();
    $this->pmSrsTable = $pmSrsTable;
    $this->pmSrsRelease = $pmSrsRelease;
    $this->kpiRows = $kpiRows;
    // Hack in a fix for weird space before colon from the MS Word doc:
    $this->src = str_replace('eNB Counter Method :','eNB Counter Method:',$src);
    $this->startPhrase = $startPhrase;
    $this->status = "No formula found.";
    $this->BEGIN = "BEGIN";
    $this->END = "END";
    $this->severity = self::NORMAL;
    $this->formulaSrc = $this->cleanFormula = '';
    $this->symbolTable = array();
    $this->variableValues = array();
    $this->debugLevel= $debugLevel;
    if ($debugLevel > 1) {
      $this->debug = true;
    } else {
      $this->debug = false;
    }
  }

  public function run() {
    $this->getFormulaSrc();
    $this->cleanFormula();
    if ($this->foundFormula()) {
      $lexerDebug = ($this->debugLevel > 2) ? true : false;
      // Run pass 0:  Initial lexical analysis
      $this->pass0src = $this->cleanFormulaSrc;
      $lexer = new Lexer($this->pass0src, false, $lexerDebug);
      $pass0tokens = $lexer->getTokens();
      $this->error($lexer->getErrorMsg());
      if ($this->debugLevel > 1) {
        echo "Pass 0 tokens:\n";
        print_r($pass0tokens);
        echo "<br>\n";
      }

      // Run pass 1: Handle Formula:// references before processing variables
      if ($this->containsFormulaRefs($this->pass0src)) {
        if ($this->debugLevel > 1) echo "Found formula reference.  Expanding.";
        $this->pass1src = $this->substituteFormulas($this->pass0src, $pass0tokens);
        $pass1lexer = new Lexer($this->pass1src, false, $lexerDebug);
        $pass1tokens = $pass1lexer->getTokens();
        $this->error($pass1lexer->getErrorMsg());
      } else {
        if ($this->debugLevel > 1) echo "No formula references.";
        $this->pass1src = $this->pass0src;
        $pass1tokens = $pass0tokens;
      }

      // Run pass 2:  Handle variables
      $this->variables = $this->extractVariableAssignments($pass1tokens);
      if ($this->debugLevel > 1) {
        echo "Variables after assignment extraction:\n";
        print_r($this->variables);
        echo "<br>\n";
      }
      $this->pass2src = $this->substituteVariables($this->pass1src, $this->variables);
      if ($this->debugLevel > 1) {
        echo "Pass 2 src after substitutions:\n";
        print_r($this->pass2src);
        echo "<br>\n";
      }
      $pass2lexer = new Lexer($this->pass2src, false, $lexerDebug);
      $pass2tokens = $pass2lexer->getTokens();
      $this->error($pass2lexer->getErrorMsg());

      // Pass number 3 for expanding summations:
      $this->pass3src = $this->expandSummations($this->pass2src, $pass2tokens);
      $pass3lexer = new Lexer($this->pass3src, false, $lexerDebug);
      $pass3tokens = $pass3lexer->getTokens();
      $this->error($pass3lexer->getErrorMsg());

      // Finally, build the symbol table from the results of pass 3:
      $this->symbolTable = $this->buildSymbolTable($pass3tokens);
    }
  }

  /**
   * Return the expanded source of the entire formula.
   */
  public function getExpandedSrc() {
    return $this->pass3src;
  }

  /**
   * Return the expanded source of the given variable.
   *
   * @param $variableName
   * @return string
   */
  public function getVariableSrc($variableName) {
    if (isset($this->variables[$variableName])) {
      /*
      // Use index 2 since we are skipping over variable name and equal sign:
      $startIndex = $this->variables[$variableName][2]['START'];
      $c = count($this->variables[$variableName]);
      $endIndex = $this->variables[$variableName][$c-1]['END'];
      $src = substr($this->pass1src,$startIndex,$endIndex);
      */
      $src = $this->variableValues[$variableName];
      foreach ($this->variableValues as $variableName=>$variableValue) {
        $src = str_replace($variableName,$variableValue,$src);
      }
    } else {
      $src = "ERROR_Variable_NotFound_{$variableName}";
    }
    return $src;
  }

  protected function containsFormulaRefs($src) {
    return stripos($src,"Formula://") !== false;
  }

  protected function substituteFormulas($src, $tokens) {
    $length = count($tokens);
    // Loop in reverse order through the tokens so we can do substitutions at the end of the src first.
    for ($i=$length-1; $i>=0; $i--) {
      if ($this->isSymbol($tokens[$i],'Formula') && $this->debugLevel > 1) {
        echo "Formula symbol seen.";
      }
      if ($this->isSymbol($tokens[$i],'Formula') && $this->isOperator($tokens[$i+1],':') &&
        $this->isOperator($tokens[$i+2],'/') && $this->isOperator($tokens[$i+3],'/')) {
        if ($this->debugLevel > 1) echo "Found a formula\n";
        // We've got a live one.
        $startingTokenIndex = $i;
        $endingTokenIndex = $i+1; // for now
        $kpi_id = '';
        $variable = '';
        $index = $i+4;
        if ($this->isSymbol($tokens[$index],'EnbCounterMethod')) {
          if ($this->debugLevel > 1) echo "Found a formula EnbCounterMethod ref\n";
          // looking good
          $index++;
          if ($this->isOperator($tokens[$index],'/')) {
            $index++;
            if ($this->isSymbol($tokens[$index])) {
              // Ok, this should be the KPI ID
              $kpi_id = $tokens[$index]['VALUE'];
              $index++;
              if ($this->isOperator($tokens[$index], '/')) {
                // Looks like there will be a variable reference
                $index++;
                if ($this->isVariable($tokens[$index])) {
                  // Get the variable name
                  $variable = $tokens[$index]['VALUE'];
                  // Now look for closing paren
                  $index++;
                  if ($this->isOperator($tokens[$index], ')')) {
                    // We've reached the end
                    // We want the token just prior to the closing paren:
                    $endingTokenIndex = $index - 1;
                    $src = $this->replaceFormulaSrc($src, $tokens, $startingTokenIndex, $endingTokenIndex, $kpi_id, $variable);
                  } else {
                    $this->error('Expected closing paren in Formula reference.');
                  }
                } else {
                  $this->error('Expected variable name at end of Formula reference');
                }
              } else if ($this->isOperator($tokens[$index], ')')) {
                // We've reached the end
                // We want the token just prior to the closing paren:
                $endingTokenIndex = $index - 1;
                $src = $this->replaceFormulaSrc($src, $tokens, $startingTokenIndex, $endingTokenIndex, $kpi_id);
              } else {
                $this->error('Expected closing paren in Formula reference');
              }
            } else {
              $this->error('Expected KPI ID after Formula://EnbCounterMethod/');
            }
          } else {
            $this->error('Expected slash after Formula:://EnbCounterMethod');
          }
        } else {
          $this->error('Unrecognized Formula type: '.$tokens[$index]['VALUE']);
        }

      }
    }
    return $src;
  }

  protected function replaceFormulaSrc($src,$tokens,$startingTokenIndex,$endingTokenIndex,$kpi_id,$variable='') {
    $newFormulaTxt = $this->getKpiFormulaText($kpi_id,$variable);
    $segmentStart = $tokens[$startingTokenIndex]['START'];
    $segmentEnd = $tokens[$endingTokenIndex]['END'];
    // Now clip out the original source segment, and replace it with the generated expression:
    $src = substr($src,0,$segmentStart).$newFormulaTxt.substr($src,$segmentEnd+1);
    return $src;
  }

  protected function getKpiFormulaText($kpi_id,$variable='') {
    if (isset($this->kpiRows[$kpi_id])) {
      $formula = $this->kpiRows[$kpi_id]['ALU_Meas_Method'];
      // Recursively create a new Formula Parser:
      $parser = new FormulaParser($formula,'eNB Counter Method:',$this->debugLevel, $this->pmSrsTable, $this->pmSrsRelease, $this->kpiRows);
      // Could be infinite recursion if the KPI DOC has circular Formula:// references
      $parser->run();
      $parserErrors = $parser->getErrorMsg();
      if (!empty($parserErrors)) {
        $this->error("Referenced formula has errors.");
      }
      // Handle variable reference
      if (!empty($variable)) {
        $formulaTxt = $parser->getVariableSrc($variable);
      } else {
        $formulaTxt = $parser->getExpandedSrc();
      }
    } else {
      $formulaTxt = "Formula_for_KPI_{$kpi_id}_{$variable}";
      $this->error("Could not resolve KPI_ID in Forumla reference: $kpi_id");
    }
    return $formulaTxt;
  }

  protected function isSymbol($token,$value=null) {
    return $token['TOKEN']==Lexer::SYMBOL && ($value==null || $token['VALUE']==$value);
  }

  protected function isOperator($token,$value=null) {
    return $token['TOKEN']==Lexer::OPERATOR && ($value==null || $token['VALUE']==$value);
  }

  protected function isVariable($token,$value=null) {
    return $token['TOKEN']==Lexer::VARIABLE && ($value==null || $token['VALUE']==$value);
  }

  protected function extractVariableAssignments($tokens) {
    $variables = array();
    // Examine token stream identifying variables assignments and extracting them into variables table
    $i=0;
    for ($i=0; $i<count($tokens); $i++) {
      if ($tokens[$i] == Lexer::ERROR) {
        $this->error("ERROR token ($i) detected.");
      }
      if ($tokens[$i]['TOKEN']==Lexer::OPERATOR && $tokens[$i]['VALUE']=='=') {
        // Found an assignment operator.
        if ($i<1) {
          $this->error("Assignment operator can not be first token.");
        } else {
          if ($tokens[$i-1]['TOKEN'] == Lexer::VARIABLE) {
            // We've found a variable assignment
            $variableName = $tokens[$i-1]['VALUE'];
            if (strlen($variableName) < 2) {
              $this->error("Variable name is too short: $variableName");
            } else {
              if (isset($variables[$variableName])) {
                $this->error("Multiple definitions of variable $variableName detected.");
              } else {
                // Scan ahead in token stream until we either run out of tokens or hit another variable assignment:
                unset($variableTokens);
                // Put in the tokens for the variable itself, and the assignment operator:
                $variableTokens = array($tokens[$i-1],$tokens[$i]);
                $done = false;
                do {
                  // Advance to next token
                  $i++;
                  // See if we hit the end of the token stream:
                  if ($i >= count($tokens)) {
                    $done = true;
                  } else {
                    if (($tokens[$i]['TOKEN'] == Lexer::VARIABLE) && ($tokens[$i+1]['VALUE'] == '=')) {
                      // We've come to a variable token in the stream that is followed by an =, so we are done with this variable.
                      $done = true;
                    } else {
                      $variableTokens[] = $tokens[$i];
                    }
                  }
                } while (!$done);
                $variables[$variableName] = $variableTokens;
              }
            }
          } else {
            $this->error("Assignment operator can only occur after a variable.");
          }
        }
      }
    }
    return $variables;
  }

  protected function error($msg) {
    if (!empty($msg)) {
      $this->errorMsg = $msg;
      if ($this->debugLevel > 0) echo "\nERROR DETECTED: $msg\n<br>\n";
      $this->errors[] =  $msg;
    }
  }

  public function getErrorMsg() {
    return empty($this->errors) ? '' : implode("\n",$this->errors);
  }

  protected function expandSummations($src, $tokens) {
    $length = count($tokens);
    $tokens[$length] = array('TOKEN'=>'END','VALUE'=>''); // Add a dummy token at the end.
    // Loop in reverse order through the tokens so we can do substitutions at the end of the src first.
    for ($i=$length-1; $i>=0; $i--) {
      $min_i = $i;
      // See if we've got a summation operator followed by a symbol.
      if (($tokens[$i]['TOKEN']=="OPERATOR") && ($tokens[$i]['VALUE']=="~") &&
        ($tokens[$i+1]['TOKEN']=="SYMBOL")) {
        // We've got a live one.
        $startingTokenIndex = $i;
        $endingTokenIndex = $i+1;
        unset($numbers);
        $numbers = array();
        unset($symbolicScreeningNames);
        $symbolicScreeningNames = array();
        // See if there an a opening paren following the symbol:
        if ($tokens[$i+2]['TOKEN']=="OPERATOR" && $tokens[$i+2]['VALUE']=="(") {
          // Need to grab the number ranges and individual numbers (or screening names) from inside the parens.
          $done = false;
          $i = $i + 3;
          $state = 0;
          $startingRange = -1;
          do {
            switch ($tokens[$i]['TOKEN']) {
              case 'NUMBER':
                switch ($state) {
                  case 0:
                    $startingRange = (int)$tokens[$i]['VALUE'];
                    $state = 1;
                    break;
                  case 2:
                    $endingRange = (int)$tokens[$i]['VALUE'];
                    for ($r=$startingRange; $r<=$endingRange; $r++) {
                      $numbers[] = $r;
                    }
                    $state = 0;
                    break;
                  default:
                    // error: numbers should only be seen in states 0 and 2.  This implies two numbers in a row
                    // with no operator in between.
                    $this->error("Two consecutive numbers seen in summation range");
                }
                break;
              case 'OPERATOR':
                switch ($tokens[$i]['VALUE']) {
                  case '-':
                    $state = 2;
                    break;
                  case ',':
                    if ($state == 1) {
                      $numbers[] =  $startingRange;
                      $state = 0;
                    } else if ($state == 0) {
                      // Do nothing, we're waiting for another number to arrive
                    } else {
                      // error: was expecting number after a dash, but got a comma instead.
                      $this->error("In summation range: expecting number, got a comma instead.");
                    }
                    break;
                  case ')':
                    $done = true;
                    if ($state == 1) {
                      $numbers[] =  $startingRange;
                    }
                    break;
                  default:
                    // Error: unexpected operator
                    $this->error("Unexpected operator in summation range.");
                }
                break;
              case 'SYMBOL':
                // This could be a symbolic screening name.  Save it.
                $symbolicScreeningNames[] = $tokens[$i]['VALUE'];
                break;
              default:
                // Error: expecting only numbers and operators
                $this->error("Unexpected syntax in summation range.");
            }
            $i++;
          } while (($i < $length) && (!$done));
          $endingTokenIndex = $i-1;
        } else {
          // Default number range to 0 thru 8.
          for ($r=0; $r<=8; $r++) {
            $numbers[] = $r;
          }
        }
        if ($this->debugLevel > 2) WSA::dump($numbers,'Summation Range Numbers:');
        //
        // Now we need to substitute the actual counter names (with suffix) for the summation statement in formula:
        // We know the starting and ending token index value.
        // From those we know the beginning and ending character positions in the source string.
        // We will clip out that segment, and replace it with a generated string that includes the expanded summation
        // expression (which we will generate using the $numbers array).
        //
        // First generate the expanded expression (this will require database reads from PM SRS):
        $counterName = $tokens[$startingTokenIndex+1]['VALUE'];
        $expandedSum = $this->expandCounterSum($counterName,$numbers,$symbolicScreeningNames);
        if ($this->debugLevel > 2) echo "ExpandedSum: $expandedSum<br>\n";
        $segmentStart = $tokens[$startingTokenIndex]['START'];
        $segmentEnd = $tokens[$endingTokenIndex]['END'];
        // Now clip out the original source segment, and replace it with the generated expression:
        $src = substr($src,0,$segmentStart).$expandedSum.substr($src,$segmentEnd+1);
      }
      $i = $min_i;
    }
    return $src;
  }

  /**
   * Given a release and a PM SRS table, return a list of all versions for that release.
   *
   * @param $release
   * @param $pm_srs_table
   * @return mixed
   */
  protected function getPmSrsVersionsForRelease($release, $pm_srs_table) {
    $allVersionsForRelease = $pm_srs_table->where("`release`='$release' ")->orderby('version DESC')->distinct('version');
    return $allVersionsForRelease;
  }

  /**
   * Given a counter name, a list of numeric screening IDs, and a list of screening Names, return a string that is the
   * expanded summation of all the individual screenings (where each individual screening includes the counter name + the screening
   * name).
   *
   * @param $counterName
   * @param $screenings
   * @param $symbolicScreeningNames
   * @return string
   */
  protected function expandCounterSum($counterName,$screenings,$symbolicScreeningNames) {
    $result = '';
    if ($this->debugLevel > 2) echo "Expanding summation: $counterName with numeric screening ids.<br>\n";
    if ($this->pmSrsTable !== null) {
      $pmSrsVersions = $this->getPmSrsVersionsForRelease($this->pmSrsRelease,$this->pmSrsTable);
      $version = $pmSrsVersions[0];
      $whereVersion = empty($version) ? '' : "AND (`version`='$version')";
      $rows = $this->pmSrsTable->where("counter_name3GPP='$counterName' AND `release`='{$this->pmSrsRelease}'{$whereVersion}")->get();
      if ($rows) {
        unset($foundScreeningIds);
        $foundScreeningIds = array();
        unset($foundScreeningNames);
        $foundScreeningNames = array();
        foreach ($rows as $row) {
          $screeningId = (int)$row['counter_screening_scrid_id'];
          $screeningName = $row['counter_screening_scrid_suffix3GPP'];
          if (in_array($screeningId,$screenings)) {
            $result .= $counterName.'.'.$screeningName.'+';
            $foundScreeningIds[] = $screeningId;
          }
          if (in_array($screeningName,$symbolicScreeningNames)) {
            $result .= $counterName.'.'.$screeningName.'+';
            $foundScreeningNames[] = $screeningName;
          }
        }
        // Generate error messages for any screening IDs or names that were not found:
        $screeningIdErrors = array_diff($screenings,$foundScreeningIds);
        $screeningNameErrors = array_diff($symbolicScreeningNames,$foundScreeningNames);
        if (!empty($screeningIdErrors)) {
          $this->error("Invalid Screening IDs found: ".implode(', ',$screeningIdErrors));
        }
        if (!empty($screeningNameErrors)) {
          $this->error("Invalid Screening Names found: ".implode(', ',$screeningNameErrors));
        }
      } else {
        $this->error("$counterName not in PM SRS for release {$this->pmSrsRelease}.");
      }
    } else $this->error("PM SRS is not set - can not expand summation.");
    if (!empty($result)) {
      // remove trailing plus sign:
      $result = '('.substr($result,0,-1).')';
    }
    return $result;
  }


  /**
   * @param $src
   * @param $variables
   * @param bool $clipSrc - set to false if the src parameter does not contain the variable source.
   * @return mixed|string
   */
  protected function substituteVariables($src, $variables) {
    if (count($variables) > 0) {
      if ($this->debug) $this->dumpVariables();
      $variableValues = array();
      if ($this->debug) {
        echo "Cleaned src: $src\n";
        echo "Hex dump:\n";
        WSA::hex_dump($src,"\n",true);
      }

      // Loop through array extracting the value of each variable
      foreach ($variables as $variable) {
        $nameToken = $variable[0];
        $name = $nameToken['VALUE'];
        $valueFirstToken = $variable[2];
        $valueLastToken = end($variable);
        $offset = $valueFirstToken['START'];
        $length = $valueLastToken['END'] - $valueFirstToken['START'] + 1;
        $value = mb_substr($src,$offset,$length);
        $variableValues[$name] = $value;
        if ($this->debug) echo "For: $name Got: $value\n(Offset $offset, Length $length)\n";
      }
      // An ugly hack to record the variable values for later use:
      $this->variableValues = $variableValues;
      // Now clip out all the variable definitions out of the src:
      $firstVariable = reset($variables);
      $firstVariableToken = $firstVariable[0];
      $src = mb_substr($src,0,$firstVariableToken['START']-1);
      if ($this->debug) echo "Clipped src: $src\n";
      if ($this->debug) echo "Variable values:\n";
      if ($this->debug) print_r($variableValues);
      // Now we have the source with all the variables clipped out, and we can make the substitutions:
      // Note that this will handle variables used within other variable definitions so long as the
      // referenced variable is defined later in the source from where it is referenced.
      // (It will break if the user defines $X=something, then later does $Y=$X+2, will work if those are reversed).
      foreach ($variableValues as $variableName=>$variableValue) {
        $src = str_replace($variableName,$variableValue,$src);
      }
      if ($this->debug) echo "Expanded src: $src\n";
    }
    return $src;
  }

  protected function buildSymbolTable($tokens)  {
    $symTbl = array();
    foreach ($tokens as $token) {
      switch ($token['TOKEN']) {
        case Lexer::VARIABLE:
          $this->error("Variable not defined ({$token['VALUE']})");
          break;
        case Lexer::SYMBOL:
          $symTbl[$token['VALUE']] = $token;
          break;
      }
    }
    return $symTbl;
  }

  public function displayTokens($tokens) {
    echo "TOKENS:\n";
    foreach ($tokens as $token) {
      echo $token['TOKEN']." -> ".$token['VALUE']." [{$token['START']}, {$token['END']}]\n";
    }
    echo "End of TOKENS.\n";
  }

  public function dumpSymbolTable() {
    if (!empty($this->symbolTable)) {
      echo "SYMBOLS:\n";
      foreach ($this->symbolTable as $symbol=>$token) {
        echo "  $symbol\n";
      }
      //echo "End of SYMBOL TABLE.\n";
    }
  }

  public function getSymbolTable() {
    return $this->symbolTable;
  }

  public function getSymbolNames() {
    return array_keys($this->symbolTable);
  }

  public function getFormula() {
    return str_replace("~","∑",$this->cleanFormulaSrc);
  }

  public function dumpVariables() {
    echo "VARIABLES:\n";
    print_r($this->variables);
    echo "End of VARIABLES.\n";
  }

  public function getSymbolList() {

  }

  public function foundFormula() {
    return (($this->severity !== self::ERROR) && (!empty($this->formulaSrc)));
  }

  /**
   * Since the source of the formula is a pre-existing Word document that we convert to XML, we sometimes
   * encounter some strange formatting.  This function cleans things up.
   */
  protected function cleanFormula() {
    mb_internal_encoding('UTF-8');
    if ($this->debugLevel > 1) echo "\nformulaSrc:\n".$this->formulaSrc;
    $this->cleanFormulaSrc = $this->formulaSrc;
    if ($this->debugLevel > 1) echo "\nclean 1:\n".$this->cleanFormulaSrc;
    // Replace sigma with tilde:
    $this->cleanFormulaSrc = str_replace("∑","~",$this->cleanFormulaSrc);
    if ($this->debugLevel > 1) echo "\nclean 2:\n".$this->cleanFormulaSrc;
    $this->cleanFormulaSrc = iconv("UTF-8","latin1//TRANSLIT",$this->cleanFormulaSrc);
    if ($this->debugLevel > 1) echo "\nclean 3:\n".$this->cleanFormulaSrc;
    $this->cleanFormulaSrc = preg_replace("/\([0-9 \-]*\)/","",$this->cleanFormulaSrc);
    if ($this->debugLevel > 1) echo "\nclean 4:\n".$this->cleanFormulaSrc;
    $this->cleanFormulaSrc = str_replace("SumOverCellPLMN","",$this->cleanFormulaSrc);
    $this->cleanFormulaSrc = str_replace("TIME_SHIFT","",$this->cleanFormulaSrc);
    // Remove any whitespace between a dollar sign and a number or letter:
    $this->cleanFormulaSrc = preg_replace('/(\$)[ ]+([A-Za-z0-9])/','${1}${2}',$this->cleanFormulaSrc);
    // Remove any whitespace between a Formula:// reference and closing param:
    $this->cleanFormulaSrc = preg_replace('/(Formula:\/\/[A-Za-z0-9\/_]*)[ ]+([A-Za-z0-9\/_]*)/','${1}${2}',$this->cleanFormulaSrc);
    if ($this->debugLevel > 1) echo "\nclean 5:\n".$this->cleanFormulaSrc;
    // Transform any double # sequences into single #:
    $this->cleanFormulaSrc = preg_replace('/[\+\-\*\(][\s ]*#[\s ]*#[\s ]*[A-Za-z0-9_\.\$]*/','',$this->cleanFormulaSrc);
    if ($this->debugLevel > 1) echo "\nclean 6:\n".$this->cleanFormulaSrc;
    // Replace any period followed by spaces into just a period (we tend to see these a lot from the KPI doc for some reason).
    $this->cleanFormulaSrc = preg_replace('/\.[ ]+/','.',$this->cleanFormulaSrc);
    if (strpos($this->cleanFormulaSrc,'#') !== false) {
      // A # remains.  Flag an error.
      $this->error("## must be preceded by a plus, minus, open paren, or multiplication symbol");
    }
  }

  protected function getFormulaSrc() {
    $ix = mb_stripos($this->src,$this->startPhrase);
    if ($ix !== false) {
      // Find BEGIN to END block of text
      $ix = mb_strpos($this->src,$this->BEGIN,$ix);
      if ($ix !== false) {
        $ix += mb_strlen($this->BEGIN);
        $end = mb_strpos($this->src,$this->END,$ix);
        if ($end !== false) {
          $this->formulaSrc = trim(mb_substr($this->src,$ix,$end-$ix));
          if (!empty($this->formulaSrc)) {
            $this->status = "Formula text found.";
          } else {
            $this->status = "Found empty BEGIN/END block";
            $this->severity = self::ERROR;
          }
        } else {
          $this->status ="Found BEGIN but no END";
          $this->severity = self::ERROR;
        }
      } else {
        $this->status = "BEGIN not found.";
        $this->severity = self::ERROR;
      }
    } else {
      $this->status = "Start phrase not found.";
    }
  }

  public function getStatus() {
    return $this->status;
  }

  public function report() {
    $m = '';
    if ($this->severity == self::ERROR) {
      $m .= "ERROR: ";
    }
    $m .= "Status: ".$this->status."\n";
    if (!empty($this->formulaSrc)) {
      $m .= "Formula Source:\n".$this->formulaSrc."\n(end)\n";
      $m .= "Cleaned Formula:\n".$this->cleanFormulaSrc."\n(end)\n";
    }
    return $m;
  }
}
