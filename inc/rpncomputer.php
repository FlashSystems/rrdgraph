<?php
/**
 * RRDGraph Plugin: Simple RPN parser for rules.
 *
 * @author Daniel Goß <developer@flashsystems.de>
 * @license MIT
 */

class RPNComputer {
    /**
     * Contains the constants that are passed to addConst.
     * @var Array
     */
    private $constants = array ();

    /**
     * Defines a  constant that can be used within an RPN expression.
     * @param String $name Name of the constant.
     * @param Multi $value The value to use.
     * @throws Exception
     */
    public function addConst($name, $value) {
        $name = strtolower(trim($name));
        
        if (strspn($name, "abcdefghijklmnopqrstuvwxyz_.") != strlen($name)) throw new Exception("Invalid variable name");
        
        if ($value === null)
            unset($this->constants[$name]);
        else
            $this->constants[$name] = $value;
    }

    /**
     * Checks if the given Value contains only numbers and optionally a sign.
     * @param String $v The value to check.
     * @return boolean Returns true if the string only contains numbers.
     */
    private function is_integer($v) {
        return (strspn($v, "0123456789-") == strlen($v));
    }

    /**
     * Compares any PHP variable in a sensfull manner.
     * If a string contains only digits, it is compared as a number. If it contains anlything else, it is compared as a string.
     * @param Multi $a The first value.
     * @param Multi $b The second value.
     * @return Returns 0 if $a and $b are equal. -1 if $a is less than $b and 1 if $a is more than $b.
     */
    private function compare($a, $b) {
        //-- Comapring anything to null return false
        if (is_null($a)) return false;
        if (is_null($b)) return false;
        
        //-- Convert boolean values into integer values 0 and 1 
        if (is_bool($a)) $a = $a?1:0;
        if (is_bool($b)) $b = $b?1:0;
        
        //-- If both values are numeric, their content is compared
        if (ctype_digit($a) && ctype_digit($b)) {
            //-- Convert a and b to integer or float and then compare them.
            $a = $this->is_integer($a)?intval($a):floatval($a);
            $b = $this->is_integer($b)?intval($b):floatval($b);
            
            if ($a < $b)
                return - 1;
            else if ($a > $b)
                return 1;
            else
                return 0;
        } else {
            return strcasecmp(strval($a), strval($b));
        }
    }

    /**
     * Processes a RPN expression in rrdtool style. The only supported operators are |, &, >, <, =
     * @param String $expression RPN expression.
     * @throws Exception An exception is thrown if the RPN expression could not be parsed.
     * @return mixed Returns the result of the RPN computation.
     */
    public function compute($expression) {
        $stack = array ();
        
        foreach (explode(",", $expression) as $part) {
            switch (trim($part)) {
            case '|' :
                if (count($stack) < 2) throw new Exception("RPN stack underflow"); //FIXME: Position
                

                $b = array_pop($stack);
                $a = array_pop($stack);
                $r = ($a || $b);
                array_push($stack, $r);
                break;
            
            case '&' :
                if (count($stack) < 2) throw new Exception("RPN stack underflow"); //FIXME: Position
                

                $b = array_pop($stack);
                $a = array_pop($stack);
                $r = ($a && $b);
                array_push($stack, $r);
                break;
            
            case '>' :
                if (count($stack) < 2) throw new Exception("RPN stack underflow"); //FIXME: Position
                

                $b = array_pop($stack);
                $a = array_pop($stack);
                $r = ($this->compare($a, $b) > 0);
                array_push($stack, $r);
                break;
            
            case '<' :
                if (count($stack) < 2) throw new Exception("RPN stack underflow"); //FIXME: Position
                

                $b = array_pop($stack);
                $a = array_pop($stack);
                $r = ($this->compare($a, $b) < 0);
                
                array_push($stack, $r);
                break;
            
            case '=' :
                if (count($stack) < 2) throw new Exception("RPN stack underflow"); //FIXME: Position
                

                $b = array_pop($stack);
                $a = array_pop($stack);
                $r = ($this->compare($a, $b) == 0);
                
                array_push($stack, $r);
                break;
            
            //-- Variable or Value
            default :
                $v = strtolower(trim($part));
                
                if (array_key_exists($v, $this->constants)) {
                    array_push($stack, $this->constants[$v]);
                } else {
                    array_push($stack, $v);
                }
            }
        }
        
        if (count($stack) > 1) throw new Exception("Unused parameters on RPN stack.");
        
        return array_pop($stack);
    }
}