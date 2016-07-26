<?php
namespace Formulas/Parser;
/**
 * Lexical tokenizer for the formula parsers.
 *
 * Both strict and relaxed use the same lexer - they pass a parameter
 * that controls the "relaxed mode".  In relaxed mode errors are ignored.
 */
/**
 * Lexer - takes in input source string containing the formula and returns a stream of tokens.
 */
class Lexer {
  protected $index;
  protected $src;
  protected $chars;
  protected $state;
  protected $debug;
  protected $errorMsg;
  protected $errors;
  protected $relaxedMode;
  const END="END";
  const ERROR="ERROR";
  const WHITESPACE="WHITESPACE";
  const SYMBOL="SYMBOL";
  const VARIABLE="VARIABLE";
  const OPERATOR="OPERATOR";
  const NUMBER="NUMBER";
  // Symbols that start with @ are NPO constructs.
  const SYMBOL_START_PATTERN = "/[A-Za-z_@]/";
  const SYMBOL_CONTINUE_PATTERN = "/[A-Za-z0-9_\.\$]/";
  const OPERATOR_PATTERN = "/[\[\]\(\)\+\-\*%\/\^=∑~,#:]/";
  const WHITESPACE_PATTERN = "/[\s \x{a0}]/";
  const END_CHAR = "&";

  public function __construct($src,$relaxed=false,$debug=false) {
    $this->errorMsg = '';
    $this->errors = array();
    $this->relaxed = $relaxed;
    $this->src = $src."  ".self::END_CHAR; // append some whitespace at the end to force a final state transition to WHITESPACE.
    $this->chars = $this->str_split_unicode($this->src);
    $this->debug = $debug;
    if ($this->debug) {
      WSA::hex_dump($this->src,"<br>\n",true);
      WSA::dump($this->chars);
    }
    $this->state = self::WHITESPACE;
    $this->index = 0;
  }

  protected function getNextToken() {
    $debug = $this->debug;
    $done = false;
    $tokens = array();
    $token='';
    $startingOffset = 0;
    do {
      $c = $this->chars[$this->index];
      if ($debug) "Processing $c in state {$this->state}\n";
      switch ($this->state) {
        case self::WHITESPACE:
          if (preg_match(self::WHITESPACE_PATTERN,$c)) {
            // stay in this state.
          } else if (preg_match(self::SYMBOL_START_PATTERN,$c)) {
            $newState = self::SYMBOL;
          } else if (preg_match("/[0-9\.]/",$c)) {
            $newState = self::NUMBER;
          } else if (preg_match(self::OPERATOR_PATTERN,$c)) {
            $newState = self::OPERATOR;
          } else if ($c == "$") {
            $newState = self::VARIABLE;
          } else if ($c == self::END_CHAR) {
            $newState= self::END;
          }
          break;

        case self::SYMBOL:
          if (preg_match(self::WHITESPACE_PATTERN,$c)) {
            $newState = self::WHITESPACE;
          } else if (preg_match(self::SYMBOL_CONTINUE_PATTERN,$c)) { // Note: We allow $ within a symbol.
            // stay in this state
          } else if (preg_match(self::OPERATOR_PATTERN,$c)) {
            $newState = self::OPERATOR;
          } else if ($c == "$") {
            $newState = self::ERROR;
          } else if ($c == self::END_CHAR) {
            $newState= self::END;
          } else {
            $newState = self::ERROR;
          }
          break;

        case self::VARIABLE:
          if (preg_match(self::WHITESPACE_PATTERN,$c)) {
            $newState = self::WHITESPACE;
          } else if (preg_match("/[A-Za-z0-9_\.]/",$c)) {
            // stay in this state
          } else if (preg_match(self::OPERATOR_PATTERN,$c)) {
            $newState = self::OPERATOR;
          } else if ($c == "$") {
            $newState = self::ERROR;
          } else if ($c == self::END_CHAR) {
            $newState= self::END;
          } else {
            $newState = self::ERROR;
          }
          break;

        case self::OPERATOR:
          if (preg_match(self::OPERATOR_PATTERN,$c)) {
            // stay in this state
          } else if (preg_match(self::WHITESPACE_PATTERN,$c)) {
            $newState = self::WHITESPACE;
          } else if (preg_match(self::SYMBOL_START_PATTERN,$c)) {
            $newState = self::SYMBOL;
          } else if (preg_match("/[0-9\.]/",$c)) {
            $newState = self::NUMBER;
          } else if ($c == "$") {
            $newState = self::VARIABLE;
          } else if ($c == self::END_CHAR) {
            $newState= self::END;
          } else {
            $newState = self::ERROR;
            if ($debug) {
              echo "OPERATOR state detected error at index {$this->index}\n";
              WSA::hex_dump($c,"<br>\n",true);
              if (preg_match(self::WHITESPACE_PATTERN,$c)) {
                echo "Whitespace does match.\n";
              } else {
                echo "Whitespace does not match\n";
              }
              WSA::hex_dump($this->src,"<br>\n",true);
              WSA::dump($this->chars);
            }
          }
          break;

        case self::NUMBER:
          if (preg_match("/[0-9.]/",$c)) {
            // stay in this state
          } else  if (preg_match(self::OPERATOR_PATTERN,$c)) {
            $newState = self::OPERATOR;
          } else if (preg_match(self::WHITESPACE_PATTERN,$c)) {
            $newState = self::WHITESPACE;
          } else if (preg_match("/[A-Za-z_]/",$c)) {
            $newState = self::ERROR;
          } else if ($c == self::END_CHAR) {
            $newState= self::END;
          } else if ($c == "$") {
            $newState = self::ERROR;
          }
          break;

      }
      $this->index++;
      if ($this->index >= count($this->chars)) {
        $done = true;
      }
      if (($newState != $this->state) || ($newState == self::OPERATOR)) {
        if ($debug) echo "$c State change From:{$this->state} To: $newState\n";
        $endingOffset = $this->index-2;
        if ($this->state != self::WHITESPACE) {
          $tokens[] = $this->makeToken($this->state, $token, $startingOffset, $endingOffset);
        }
        if ($this->relaxedMode) {
          // Relax:  if we hit an error just act like it was whitespace:
          if ($this->state == self::ERROR) {
            $this->state = self::WHITESPACE;
          }
        } else if ($newState == self::ERROR) {
          $lengthErrorSnippet = 32;
          $startErrorSnippet = $this->index - 1 - $lengthErrorSnippet;
          if ($startErrorSnippet < 0) {
            $lengthErrorSnippet += $startErrorSnippet + 1;
            $startErrorSnippet = 0;
          }
          $this->error("Lexical error at offset {$this->index}: [...".substr($this->src,$startErrorSnippet,$lengthErrorSnippet)."]");
        }
        $token=$c;
        $startingOffset = $this->index-1;
        $this->state = $newState;
      } else {
        $token.=$c;
        if ($done) {
          // This happens when we reach the end of the input string.
          $endingOffset = $this->index-2;
          if ($this->state != self::WHITESPACE) {
            $tokens[] = $this->makeToken($this->state, $token, $startingOffset, $endingOffset);
          }
        }
      }
    } while (!$done);
    return $tokens;
  }

  protected function error($msg) {
    $this->errorMsg = $msg;
    $this->errors[] = $msg;
  }

  public function getErrorMsg() {
    return implode(", ",$this->errors);
  }

  /**
   * Make a token, including doing any last minute mappings:
   *
   * @param $tokenType
   * @param $tokenValue
   * @param $startingOffset
   * @param $endingOffset
   * @return array
   */
  protected function makeToken($tokenType,$tokenValue,$startingOffset,$endingOffset) {
    // First do any mappings:
    switch ($tokenType) {
      case self::SYMBOL:
        $tokenValueLower = strtolower($tokenValue);
        switch ($tokenValueLower) {
          case "to":
            // We map SYMBOLs with value "to" to Operator minus:
            $tokenType  = self::OPERATOR;
            $tokenValue = "-";
        }
    }
    return array('TOKEN'=>$tokenType,'VALUE'=>$tokenValue, 'START'=>$startingOffset, 'END'=>$endingOffset);
  }

  public function getTokens() {
    if ($this->debug) echo "getTokens for: ".$this->src."(end)\n";
    return $this->getNextToken();
  }

  /**
   * We now convert everything to latin1 so the unicode bit really goes away.
   * @param $str
   * @param int $l
   * @return array
   */
  protected function str_split_unicode($str, $l = 0) {
    $ret = str_split($str);
    return $ret;
  }
}
