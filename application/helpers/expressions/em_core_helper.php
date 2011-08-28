<?php
/**
 * Description of ExpressionManager
 * (1) Does safe evaluation of PHP expressions.  Only registered Functions, and known Variables are allowed.
 *   (a) Functions include any math, string processing, conditional, formatting, etc. functions
 *   (b) Variables are typically the question name (question.title) - they can be read/write
 * (2) This class can replace LimeSurvey's current process of resolving strings that contain LimeReplacementFields
 *   (a) String is split by expressions (by curly braces, but safely supporting strings and escaped curly braces)
 *   (b) Expressions (things surrounded by curly braces) are evaluated - thereby doing LimeReplacementField substitution and/or more complex calculations
 *   (c) Non-expressions are left intact
 *   (d) The array of stringParts are re-joined to create the desired final string.
 *
 * At present, all variables are read-only, but this could be extended to support creation  of temporary variables and/or read-write access to registered variables
 *
 * @author Thomas M. White
 */

//require_once('em_functions_helper.php');

class ExpressionManager {
    // These three variables are effectively static once constructed
    private $sExpressionRegex;
    private $asTokenType;
    private $sTokenizerRegex;
    private $asCategorizeTokensRegex;
    private $amValidFunctions; // names and # params of valid functions
    private $amVars;    // names and values of valid variables

    // Thes variables are used while  processing the equation
    private $expr;  // the source expression
    private $tokens;    // the list of generated tokens
    private $count; // total number of $tokens
    private $pos;   // position within the $token array while processing equation
    private $errs;    // array of syntax errors
    private $onlyparse;
    private $stack; // stack of intermediate results
    private $result;    // final result of evaluating the expression;
    private $evalStatus;    // true if $result is a valid result, and  there are no serious errors
    private $varsUsed;  // list of variables referenced in the equation

    // These  variables are only used by sProcessStringContainingExpressions
    private $allVarsUsed;   // full list of variables used within the string, even if contains multiple expressions
    private $prettyPrintSource; // HTML formatted output of running sProcessStringContainingExpressions
    private $substitutionNum; // Keeps track of number of substitions performed XXX
    private $substitutionInfo; // array of JavaScripts to managing dynamic substitution
    private $jsExpression;  // caches computation of JavaScript equivalent for an Expression

    function __construct()
    {
        global $exprmgr_functions;  // so can access variables from ExpressionManagerFunctions.php

        // List of token-matching regular expressions
        $regex_dq_string = '(?<!\\\\)".*?(?<!\\\\)"';
        $regex_sq_string = '(?<!\\\\)\'.*?(?<!\\\\)\'';
        $regex_whitespace = '\s+';
        $regex_lparen = '\(';
        $regex_rparen = '\)';
        $regex_comma = ',';
        $regex_not = '!';
        $regex_inc_dec = '\+\+|--';
        $regex_binary = '[+*/-]';
        $regex_compare = '<=|<|>=|>|==|!=|\ble\b|\blt\b|\bge\b|\bgt\b|\beq\b|\bne\b';
        $regex_assign = '=|\+=|-=|\*=|/=';
        $regex_sgqa = '[0-9]+X[0-9]+X[0-9]+[A-Z0-9_]*\#?[12]?';
        $regex_word = '[A-Z][A-Z0-9_]*:?[A-Z0-9_]*\.?[A-Z0-9_]*\.?[A-Z0-9_]*\.?[A-Z0-9_]*';
        $regex_number = '[0-9]+\.?[0-9]*|\.[0-9]+';
        $regex_andor = '\band\b|\bor\b|&&|\|\|';

        $this->sExpressionRegex = '#((?<!\\\\)' . '{' . '(?!\s*\n\|\s*\r\|\s*\r\n|\s+)' .
                '(' . $regex_dq_string . '|' . $regex_sq_string . '|.*?)*' .
                '(?<!\\\\)(?<!\n|\r|\r\n|\s)' . '}' . ')#';


        // asTokenRegex and t_tokey_type must be kept in sync  (same number and order)
        $asTokenRegex = array(
            $regex_dq_string,
            $regex_sq_string,
            $regex_whitespace,
            $regex_lparen,
            $regex_rparen,
            $regex_comma,
            $regex_andor,
            $regex_compare,
            $regex_sgqa,
            $regex_word,
            $regex_number,
            $regex_not,
            $regex_inc_dec,
            $regex_assign,
            $regex_binary,
            );

        $this->asTokenType = array(
            'DQ_STRING',
            'SQ_STRING',
            'SPACE',
            'LP',
            'RP',
            'COMMA',
            'AND_OR',
            'COMPARE',
            'SGQA',
            'WORD',
            'NUMBER',
            'NOT',
            'OTHER',
            'ASSIGN',
            'BINARYOP',
           );

        // $sTokenizerRegex - a single regex used to split and equation into tokens
        $this->sTokenizerRegex = '#(' . implode('|',$asTokenRegex) . ')#i';

        // $asCategorizeTokensRegex - an array of patterns so can categorize the type of token found - would be nice if could get this from preg_split
        // Adding ability to capture 'OTHER' type, which indicates an error - unsupported syntax element
        $this->asCategorizeTokensRegex = preg_replace("#^(.*)$#","#^$1$#i",$asTokenRegex);
        $this->asCategorizeTokensRegex[] = '/.+/';
        $this->asTokenType[] = 'OTHER';
        
        // Each allowed function is a mapping from local name to external name + number of arguments
        // Functions can have a list of serveral allowable #s of arguments.
        // If the value is -1, the function must have a least one argument but can have an unlimited number of them
        $this->amValidFunctions = array(
            'abs'			=>array('abs','Math.abs','Absolute value',1),
            'acos'			=>array('acos','Math.acos','Arc cosine',1),
//            'acosh'			=>array('acosh','acosh','Inverse hyperbolic cosine',1),
            'asin'			=>array('asin','Math.asin','Arc sine',1),
//            'asinh'			=>array('asinh','asinh','Inverse hyperbolic sine',1),
            'atan2'			=>array('atan2','Math.atan2','Arc tangent of two variables',2),
            'atan'			=>array('atan','Math.atan','Arc tangent',1),
//            'atanh'			=>array('atanh','atanh','Inverse hyperbolic tangent',1),
//            'base_convert'	=>array('base_convert','base_convert','Convert a number between arbitrary bases',3),
//            'bindec'		=>array('bindec','bindec','Binary to decimal',1),
            'ceil'			=>array('ceil','Math.ceil','Round fractions up',1),
            'cos'			=>array('cos','Math.cos','Cosine',1),
//            'cosh'			=>array('cosh','cosh','Hyperbolic cosine',1),
//            'decbin'		=>array('decbin','decbin','Decimal to binary',1),
//            'dechex'		=>array('dechex','dechex','Decimal to hexadecimal',1),
//            'decoct'		=>array('decoct','decoct','Decimal to octal',1),
//            'deg2rad'		=>array('deg2rad','deg2rad','Converts the number in degrees to the radian equivalent',1),
            'exp'			=>array('exp','Math.exp','Calculates the exponent of e',1),
//            'expm1'			=>array('expm1','expm1','Returns exp(number) - 1, computed in a way that is accurate even when the value of number is close to zero',1),
            'floor'			=>array('floor','Math.floor','Round fractions down',1),
//            'fmod'			=>array('fmod','fmod','Returns the floating point remainder (modulo) of the division of the arguments',2),
//            'getrandmax'	=>array('getrandmax','getrandmax','Show largest possible random value',0),
//            'hexdec'		=>array('hexdec','hexdec','Hexadecimal to decimal',1),
//            'hypot'			=>array('hypot','hypot','Calculate the length of the hypotenuse of a right-angle triangle',2),
//            'is_finite'		=>array('is_finite','is_finite','Finds whether a value is a legal finite number',1),
//            'is_infinite'	=>array('is_infinite','is_infinite','Finds whether a value is infinite',1),
            'is_nan'		=>array('is_nan','isNaN','Finds whether a value is not a number',1),
//            'lcg_value'		=>array('lcg_value','NA','Combined linear congruential generator',0),
//            'log10'			=>array('log10','log10','Base-10 logarithm',1),
//            'log1p'			=>array('log1p','log1p','Returns log(1 + number), computed in a way that is accurate even when the value of number is close to zero',1),
            'log'			=>array('log','Math.log','Natural logarithm',1,2),
            'max'			=>array('max','Math.max','Find highest value',-1),
            'min'			=>array('min','Math.min','Find lowest value',-1),
//            'mt_getrandmax'	=>array('mt_getrandmax','mt_getrandmax','Show largest possible random value',0),
//            'mt_rand'		=>array('mt_rand','mt_rand','Generate a better random value',0,2),
//            'mt_srand'		=>array('mt_srand','NA','Seed the better random number generator',0,1),
//            'octdec'		=>array('octdec','octdec','Octal to decimal',1),
            'pi'			=>array('pi','LEMpi','Get value of pi',0),
            'pow'			=>array('pow','Math.pow','Exponential expression',2),
//            'rad2deg'		=>array('rad2deg','rad2deg','Converts the radian number to the equivalent number in degrees',1),
            'rand'			=>array('rand','Math.random','Generate a random integer',0,2),
            'round'			=>array('round','LEMround','Rounds a number to an optional precision',1,2),
            'sin'			=>array('sin','Math.sin','Sine',1),
//            'sinh'			=>array('sinh','sinh','Hyperbolic sine',1),
            'sqrt'			=>array('sqrt','Math.sqrt','Square root',1),
            'srand'			=>array('srand','NA','Seed the random number generator',0,1),
            'sum'           =>array('array_sum','LEMsum','Calculate the sum of values in an array',-1),
            'tan'			=>array('tan','Math.tan','Tangent',1),
//            'tanh'			=>array('tanh','tanh','Hyperbolic tangent',1),

            'intval'		=>array('intval','LEMintval','Get the integer value of a variable',1,2),
            'is_bool'		=>array('is_bool','is_bool','Finds out whether a variable is a boolean',1),
            'is_float'		=>array('is_float','LEMis_float','Finds whether the type of a variable is float',1),
            'is_int'		=>array('is_int','LEMis_int','Find whether the type of a variable is integer',1),
            'is_null'		=>array('is_null','LEMis_null','Finds whether a variable is NULL',1),
            'is_numeric'	=>array('is_numeric','LEMis_numeric','Finds whether a variable is a number or a numeric string',1),
//            'is_scalar'		=>array('is_scalar','is_scalar','Finds whether a variable is a scalar',1),
            'is_string'		=>array('is_string','LEMis_string','Find whether the type of a variable is string',1),

//            'addcslashes'	=>array('addcslashes','addcslashes','Quote string with slashes in a C style',2),
            'addslashes'	=>array('addslashes','addslashes','Quote string with slashes',1),
//            'bin2hex'		=>array('bin2hex','bin2hex','Convert binary data into hexadecimal representation',1),
//            'chr'			=>array('chr','chr','Return a specific character',1),
//            'chunk_split'	=>array('chunk_split','chunk_split','Split a string into smaller chunks',1,2,3),
//            'convert_uudecode'			=>array('convert_uudecode','NA','Decode a uuencoded string',1),
//            'convert_uuencode'			=>array('convert_uuencode','convert_uuencode','Uuencode a string',1),
//            'count_chars'	=>array('count_chars','count_chars','Return information about characters used in a string',1,2),
//            'crc32'			=>array('crc32','crc32','Calculates the crc32 polynomial of a string',1),
//            'crypt'			=>array('crypt','NA','One-way string hashing',1,2),
//            'hebrev'		=>array('hebrev','NA','Convert logical Hebrew text to visual text',1,2),
//            'hebrevc'		=>array('hebrevc','NA','Convert logical Hebrew text to visual text with newline conversion',1,2),
            'html_entity_decode'        =>array('html_entity_decode','html_entity_decode','Convert all HTML entities to their applicable characters',1,2,3),
            'htmlentities'	=>array('htmlentities','htmlentities','Convert all applicable characters to HTML entities',1,2,3),
            'htmlspecialchars_decode'	=>array('expr_mgr_htmlspecialchars_decode','htmlspecialchars_decode','Convert special HTML entities back to characters',1,2),
            'htmlspecialchars'			=>array('expr_mgr_htmlspecialchars','htmlspecialchars','Convert special characters to HTML entities',1,2,3,4),
            'implode'		=>array('exprmgr_implode','LEMimplode','Join array elements with a string',-1),
//            'lcfirst'		=>array('lcfirst','lcfirst','Make a string\'s first character lowercase',1),
//            'levenshtein'	=>array('levenshtein','levenshtein','Calculate Levenshtein distance between two strings',2,5),
            'ltrim'			=>array('ltrim','ltrim','Strip whitespace (or other characters) from the beginning of a string',1,2),
//            'md5'			=>array('md5','md5','Calculate the md5 hash of a string',1),
//            'metaphone'		=>array('metaphone','metaphone','Calculate the metaphone key of a string',1,2),
//            'money_format'	=>array('money_format','money_format','Formats a number as a currency string',1,2),
            'nl2br'			=>array('nl2br','nl2br','Inserts HTML line breaks before all newlines in a string',1,2),
            'number_format'	=>array('number_format','number_format','Format a number with grouped thousands',1,2,4),
//            'ord'			=>array('ord','ord','Return ASCII value of character',1),
            'quoted_printable_decode'			=>array('quoted_printable_decode','quoted_printable_decode','Convert a quoted-printable string to an 8 bit string',1),
            'quoted_printable_encode'			=>array('quoted_printable_encode','quoted_printable_encode','Convert a 8 bit string to a quoted-printable string',1),
            'quotemeta'		=>array('quotemeta','quotemeta','Quote meta characters',1),
            'rtrim'			=>array('rtrim','rtrim','Strip whitespace (or other characters) from the end of a string',1,2),
//            'sha1'			=>array('sha1','sha1','Calculate the sha1 hash of a string',1),
//            'similar_text'	=>array('similar_text','similar_text','Calculate the similarity between two strings',1,2),
//            'soundex'		=>array('soundex','soundex','Calculate the soundex key of a string',1),
            'sprintf'		=>array('sprintf','sprintf','Return a formatted string',-1),
//            'str_ireplace'  =>array('str_ireplace','str_ireplace','Case-insensitive version of str_replace',3),
            'str_pad'		=>array('str_pad','str_pad','Pad a string to a certain length with another string',2,3,4),
            'str_repeat'	=>array('str_repeat','str_repeat','Repeat a string',2),
            'str_replace'	=>array('str_replace','LEMstr_replace','Replace all occurrences of the search string with the replacement string',3),
//            'str_rot13'		=>array('str_rot13','str_rot13','Perform the rot13 transform on a string',1),
//            'str_shuffle'	=>array('str_shuffle','str_shuffle','Randomly shuffles a string',1),
//            'str_word_count'	=>array('str_word_count','str_word_count','Return information about words used in a string',1),
            'strcasecmp'	=>array('strcasecmp','strcasecmp','Binary safe case-insensitive string comparison',2),
            'strcmp'		=>array('strcmp','strcmp','Binary safe string comparison',2),
//            'strcoll'		=>array('strcoll','strcoll','Locale based string comparison',2),
//            'strcspn'		=>array('strcspn','strcspn','Find length of initial segment not matching mask',2,3,4),
            'strip_tags'	=>array('strip_tags','strip_tags','Strip HTML and PHP tags from a string',1,2),
//            'stripcslashes'	=>array('stripcslashes','NA','Un-quote string quoted with addcslashes',1),
            'stripos'		=>array('stripos','stripos','Find position of first occurrence of a case-insensitive string',2,3),
            'stripslashes'	=>array('stripslashes','stripslashes','Un-quotes a quoted string',1),
            'stristr'		=>array('stristr','stristr','Case-insensitive strstr',2,3),
            'strlen'		=>array('strlen','LEMstrlen','Get string length',1),
//            'strnatcasecmp'	=>array('strnatcasecmp','strnatcasecmp','Case insensitive string comparisons using a "natural order" algorithm',2),
//            'strnatcmp'		=>array('strnatcmp','strnatcmp','String comparisons using a "natural order" algorithm',2),
//            'strncasecmp'	=>array('strncasecmp','strncasecmp','Binary safe case-insensitive string comparison of the first n characters',3),
//            'strncmp'		=>array('strncmp','strncmp','Binary safe string comparison of the first n characters',3),
//            'strpbrk'		=>array('strpbrk','strpbrk','Search a string for any of a set of characters',2),
            'strpos'		=>array('strpos','LEMstrpos','Find position of first occurrence of a string',2,3),
//            'strrchr'		=>array('strrchr','strrchr','Find the last occurrence of a character in a string',2),
            'strrev'		=>array('strrev','strrev','Reverse a string',1),
//            'strripos'		=>array('strripos','strripos','Find position of last occurrence of a case-insensitive string in a string',2,3),
//            'strrpos'		=>array('strrpos','strrpos','Find the position of the last occurrence of a substring in a string',2,3),
//            'strspn'        =>array('strspn','strspn','Finds the length of the initial segment of a string consisting entirely of characters contained within a given mask.',2,3,4),
            'strstr'		=>array('strstr','strstr','Find first occurrence of a string',2,3),
            'strtolower'	=>array('strtolower','LEMstrtolower','Make a string lowercase',1),
            'strtoupper'	=>array('strtoupper','LEMstrtoupper','Make a string uppercase',1),
//            'strtr'			=>array('strtr','strtr','Translate characters or replace substrings',3),
//            'substr_compare'=>array('substr_compare','substr_compare','Binary safe comparison of two strings from an offset, up to length characters',3,4,5),
//            'substr_count'	=>array('substr_count','substr_count','Count the number of substring occurrences',2,3,4),
//            'substr_replace'=>array('substr_replace','substr_replace','Replace text within a portion of a string',3,4),
            'substr'		=>array('substr','substr','Return part of a string',2,3),
            'trim'			=>array('trim','trim','Strip whitespace (or other characters) from the beginning and end of a string',1,2),
//            'ucfirst'		=>array('ucfirst','ucfirst','Make a string\'s first character uppercase',1),
            'ucwords'		=>array('ucwords','ucwords','Uppercase the first character of each word in a string',1),

            'if'            => array('exprmgr_if','LEMif','Excel-style if(test,result_if_true,result_if_false)',3),
            'list'          => array('exprmgr_list','LEMlist','Return comma-separated list of values',-1),
            'is_empty'         => array('exprmgr_empty','LEMempty','Determine whether a variable is considered to be empty',1),
            'stddev'        => array('exprmgr_stddev','LEMstddev','Calculate the  Sample Standard  Deviation for the list of numbers',-1),

            'checkdate'     => array('checkdate','checkdate','Returns true(1) if it is a valid date in gregorian calendar',3),
            'date'          => array('date','date','Format a local date/time',2),
            'gmdate'        => array('gmdate','gmdate','Format a GMT date/time',2),
            'idate'         => array('idate','idate','Format a local time/date as integer',2),
            'mktime'        => array('mktime','mktime','Get UNIX timestamp for a date',1,2,3,4,5,6),
            'time'          => array('time','time','Return current UNIX timestamp',0),
        );

        $this->amVars = array();
        
        if (isset($exprmgr_functions) && is_array($exprmgr_functions) && count($exprmgr_functions) > 0)
        {
            $this->amValidFunctions = array_merge($this->amValidFunctions, $exprmgr_functions);
        }
    }

    /**
     * Add an error to the error log
     *
     * @param <type> $errMsg
     * @param <type> $token
     */
    private function AddError($errMsg, $token)
    {
        $this->errs[] = array($errMsg, $token);
    }

    /**
     * EvalBinary() computes binary expressions, such as (a or b), (c * d), popping  the top two entries off the
     * stack and pushing the result back onto the stack.
     *
     * @param array $token
     * @return boolean - false if there is any error, else true
     */

    private function EvalBinary(array $token)
    {
        if (count($this->stack) < 2)
        {
            $this->AddError("Unable to evaluate binary operator - fewer than 2 entries on stack", $token);
            return false;
        }
        $arg2 = $this->StackPop();
        $arg1 = $this->StackPop();
        if (is_null($arg1) or is_null($arg2))
        {
            $this->AddError("Invalid value(s) on the stack", $token);
            return false;
        }
        // TODO:  try to determine datatype?
        switch(strtolower($token[0]))
        {
            case 'or':
            case '||':
                $result = array(($arg1[0] or $arg2[0]),$token[1],'NUMBER');
                break;
            case 'and':
            case '&&':
                $result = array(($arg1[0] and $arg2[0]),$token[1],'NUMBER');
                break;
            case '==':
            case 'eq':
                $result = array(($arg1[0] == $arg2[0]),$token[1],'NUMBER');
                break;
            case '!=':
            case 'ne':
                $result = array(($arg1[0] != $arg2[0]),$token[1],'NUMBER');
                break;
            case '<':
            case 'lt':
                $result = array(($arg1[0] < $arg2[0]),$token[1],'NUMBER');
                break;
            case '<=';
            case 'le':
                $result = array(($arg1[0] <= $arg2[0]),$token[1],'NUMBER');
                break;
            case '>':
            case 'gt':
                $result = array(($arg1[0] > $arg2[0]),$token[1],'NUMBER');
                break;
            case '>=';
            case 'ge':
                $result = array(($arg1[0] >= $arg2[0]),$token[1],'NUMBER');
                break;
            case '+':
                $result = array(($arg1[0] + $arg2[0]),$token[1],'NUMBER');
                break;
            case '-':
                $result = array(($arg1[0] - $arg2[0]),$token[1],'NUMBER');
                break;
            case '*':
                $result = array(($arg1[0] * $arg2[0]),$token[1],'NUMBER');
                break;
            case '/';
                $result = array(($arg1[0] / $arg2[0]),$token[1],'NUMBER');
                break;
        }
        $this->StackPush($result);
        return true;
    }

    /**
     * Processes operations like +a, -b, !c
     * @param array $token
     * @return boolean - true if success, false if any error occurred
     */

    private function EvalUnary(array $token)
    {
        if (count($this->stack) < 1)
        {
            $this->AddError("Unable to evaluate unary operator - no entries on stack", $token);
            return false;
        }
        $arg1 = $this->StackPop();
        if (is_null($arg1))
        {
            $this->AddError("Invalid value(s) on the stack", $token);
            return false;
        }
        // TODO:  try to determine datatype?
        switch($token[0])
        {
            case '+':
                $result = array((+$arg1[0]),$token[1],'NUMBER');
                break;
            case '-':
                $result = array((-$arg1[0]),$token[1],'NUMBER');
                break;
            case '!';
                $result = array((!$arg1[0]),$token[1],'NUMBER');
                break;
        }
        $this->StackPush($result);
        return true;
    }


    /**
     * Main entry function
     * @param <type> $expr
     * @param <type> $onlyparse - if true, then validate the syntax without computing an answer
     * @return boolean - true if success, false if any error occurred
     */

    public function Evaluate($expr, $onlyparse=false)
    {
        $this->expr = $expr;
        $this->tokens = $this->amTokenize($expr);
        $this->count = count($this->tokens);
        $this->pos = -1; // starting position within array (first act will be to increment it)
        $this->errs = array();
        $this->onlyparse = $onlyparse;
        $this->stack = array();
        $this->evalStatus = false;
        $this->result = NULL;
        $this->varsUsed = array();
        $this->jsExpression = NULL;

        if ($this->HasSyntaxErrors()) {
            return false;
        }
        else if ($this->EvaluateExpressions())
        {
            if ($this->pos < $this->count)
            {
                $this->AddError("Extra tokens found", $this->tokens[$this->pos]);
                return false;
            }
            $this->result = $this->StackPop();
            if (is_null($this->result))
            {
                return false;
            }
            if (count($this->stack) == 0)
            {
                $this->evalStatus = true;
                return true;
            }
            else
            {
                $this-AddError("Unbalanced equation - values left on stack",NULL);
                return false;
            }
        }
        else
        {
            $this->AddError("Not a valid expression",NULL);
            return false;
        }
    }


    /**
     * Process "a op b" where op in (+,-,concatenate)
     * @return boolean - true if success, false if any error occurred
     */
    private function EvaluateAdditiveExpression()
    {
        if (!$this->EvaluateMultiplicativeExpression())
        {
            return false;
        }
        while (($this->pos + 1) < $this->count)
        {
            $token = $this->tokens[++$this->pos];
            if ($token[2] == 'BINARYOP')
            {
                switch ($token[0])
                {
                    case '+':
                    case '-';
                        if ($this->EvaluateMultiplicativeExpression())
                        {
                            if (!$this->EvalBinary($token))
                            {
                                return false;
                            }
                            // else continue;
                        }
                        else
                        {
                            return false;
                        }
                        break;
                    default:
                        --$this->pos;
                        return true;
                }
            }
            else
            {
                --$this->pos;
                return true;
            }
        }
        return true;
    }

    /**
     * Process a Constant (number of string), retrieve the value of a known variable, or process a function, returning result on the stack.
     * @return boolean - true if success, false if any error occurred
     */

    private function EvaluateConstantVarOrFunction()
    {
        if ($this->pos + 1 >= $this->count)
        {
             $this->AddError("Poorly terminated expression - expected a constant or variable", NULL);
             return false;
        }
        $token = $this->tokens[++$this->pos];
        switch ($token[2])
        {
            case 'NUMBER':
            case 'DQ_STRING':
            case 'SQ_STRING':
                $this->StackPush($token);
                return true;
                break;
            case 'WORD':
            case 'SGQA':
                if (($this->pos + 1) < $this->count and $this->tokens[($this->pos + 1)][2] == 'LP')
                {
                    return $this->EvaluateFunction();
                }
                else
                {
                    if ($this->isValidVariable($token[0]))
                    {
                        $this->varsUsed[] = $token[0];  // add this variable to list of those used in this equation
                        if (isset($this->amVars[$token[0]]['relevanceStatus'])) {
                            $relStatus = $this->amVars[$token[0]]['relevanceStatus'];
                        }
                        else {
                            $relStatus = 1;   // default
                        }
                        if ($relStatus==1)
                        {
                            $result = array($this->amVars[$token[0]]['codeValue'],$token[1],'NUMBER');
                        }
                        else
                        {
                            $result = array(0,$token[1],'NUMBER');
                        }
                        $this->StackPush($result);
                        return true;
                    }
                    else
                    {
                        $this->AddError("Undefined variable", $token);
                        return false;
                    }
                }
                break;
            case 'COMMA':
                --$this->pos;
                $this->AddError("Should never  get to this line?",$token);
                return false;
            default:
                return false;
                break;
        }
    }
    
    /**
     * Process "a == b", "a eq b", "a != b", "a ne b"
     * @return boolean - true if success, false if any error occurred
     */
    private function EvaluateEqualityExpression()
    {
        if (!$this->EvaluateRelationExpression())
        {
            return false;
        }
        while (($this->pos + 1) < $this->count)
        {
            $token = $this->tokens[++$this->pos];
            switch (strtolower($token[0]))
            {
                case '==':
                case 'eq':
                case '!=':
                case 'ne':
                    if ($this->EvaluateRelationExpression())
                    {
                        if (!$this->EvalBinary($token))
                        {
                            return false;
                        }
                        // else continue;
                    }
                    else
                    {
                        return false;
                    }
                    break;
                default:
                    --$this->pos;
                    return true;
            }
        }
        return true;
    }

    /**
     * Process a single expression (e.g. without commas)
     * @return boolean - true if success, false if any error occurred
     */

    private function EvaluateExpression()
    {
        if ($this->pos + 2 < $this->count)
        {
            $token1 = $this->tokens[++$this->pos];
            $token2 = $this->tokens[++$this->pos];
            if ($token2[2] == 'ASSIGN')
            {
                if ($this->isValidVariable($token1[0]))
                {
                    $this->varsUsed[] = $token1[0];  // add this variable to list of those used in this equation
                    if ($this->isWritableVariable($token1[0]))
                    {
                        $evalStatus = $this->EvaluateLogicalOrExpression();
                        if ($evalStatus)
                        {
                            $result = $this->StackPop();
                            if (!is_null($result))
                            {
                                $newResult = $token2;
                                $newResult[2] = 'NUMBER';
                                $newResult[0] = $this->setVariableValue($token2[0], $token1[0], $result[0]);
                                $this->StackPush($newResult);
                            }
                            else
                            {
                                $evalStatus = false;
                            }
                        }
                        return $evalStatus;
                    }
                    else
                    {
                        $this->AddError('The value of this variable can not be changed', $token1);
                        return false;
                    }
                }
                else
                {
                    $this->AddError('Only variables can be assigned values', $token1);
                    return false;
                }
            }
            else
            {
                // not an assignment expression, so try something else
                $this->pos -= 2;
                return $this->EvaluateLogicalOrExpression();
            }
        }
        else
        {
            return $this->EvaluateLogicalOrExpression();
        }
    }

    /**
     * Process "expression [, expression]*
     * @return boolean - true if success, false if any error occurred
     */

    private function EvaluateExpressions()
    {
        $evalStatus = $this->EvaluateExpression();
        if (!$evalStatus)
        {
            return false;
        }

        while (++$this->pos < $this->count) {  
            $token = $this->tokens[$this->pos];
            if ($token[2] == 'RP')
            {
                return true;    // presumbably the end of an expression
            }
            else if ($token[2] == 'COMMA')
            {
                if ($this->EvaluateExpression())
                {
                    $secondResult = $this->StackPop();
                    $firstResult = $this->StackPop();
                    if (is_null($firstResult))
                    {
                        return false;
                    }
                    $this->StackPush($secondResult);
                    $evalStatus = true;
                }
                else
                {
                    return false;   // an error must have occurred
                }
            }
            else
            {
                $this->AddError("Expected expressions separated by commas",$token);
                $evalStatus = false;
                break;
            }
        }
        while (++$this->pos < $this->count)
        {
            $token = $this->tokens[$this->pos];
            $this->AddError("Extra token found after Expressions",$token);
            $evalStatus = false;
        }
        return $evalStatus;
    }

    /**
     * Process a function call
     * @return boolean - true if success, false if any error occurred
     */
    private function EvaluateFunction()
    {
        $funcNameToken = $this->tokens[$this->pos]; // note that don't need to increment position for functions
        $funcName = $funcNameToken[0];
        if (!$this->isValidFunction($funcName))
        {
            $this->AddError("Undefined Function", $funcNameToken);
            return false;
        }
        $token2 = $this->tokens[++$this->pos];
        if ($token2[2] != 'LP')
        {
            $this->AddError("Expected left parentheses after function name", $token);
        }
        $params = array();  // will just store array of values, not tokens
        while ($this->pos + 1 < $this->count)
        {
            $token3 = $this->tokens[$this->pos + 1];  
            if (count($params) > 0)
            {
                // should have COMMA or RP
                if ($token3[2] == 'COMMA')
                {
                    ++$this->pos;   // consume the token so can process next clause
                    if ($this->EvaluateExpression())
                    {
                        $value = $this->StackPop();
                        if (is_null($value))
                        {
                            return false;
                        }
                        $params[] = $value[0];
                        continue;
                    }
                    else
                    {
                        $this->AddError("Extra comma found in function", $token3);
                        return false;
                    }
                }
            }
            if ($token3[2] == 'RP')
            {
                ++$this->pos;   // consume the token so can process next clause
                return $this->RunFunction($funcNameToken,$params);
            }
            else
            {
                if ($this->EvaluateExpression())
                {
                    $value = $this->StackPop();
                    if (is_null($value))
                    {
                        return false;
                    }
                    $params[] = $value[0];
                    continue;
                }
                else
                {
                    return false;
                }
            }
        }
    }

    /**
     * Process "a && b" or "a and b"
     * @return boolean - true if success, false if any error occurred
     */
    
    private function EvaluateLogicalAndExpression()
    {
        if (!$this->EvaluateEqualityExpression())
        {
            return false;
        }
        while (($this->pos + 1) < $this->count)
        {
            $token = $this->tokens[++$this->pos];
            switch (strtolower($token[0]))
            {
                case '&&':
                case 'and':
                    if ($this->EvaluateEqualityExpression())
                    {
                        if (!$this->EvalBinary($token))
                        {
                            return false;
                        }
                        // else continue
                    }
                    else
                    {
                        return false;   // an error must have occurred
                    }
                    break;
                default:
                    --$this->pos;
                    return true;
            }
        }
        return true;
    }

    /**
     * Process "a || b" or "a or b"
     * @return boolean - true if success, false if any error occurred
     */
    private function EvaluateLogicalOrExpression()
    {
        if (!$this->EvaluateLogicalAndExpression())
        {
            return false;
        }
        while (($this->pos + 1) < $this->count)
        {
            $token = $this->tokens[++$this->pos];
            switch (strtolower($token[0]))
            {
                case '||':
                case 'or':
                    if ($this->EvaluateLogicalAndExpression())
                    {
                        if (!$this->EvalBinary($token))
                        {
                            return false;
                        }
                        // else  continue
                    }
                    else
                    {
                        // an error must have occurred
                        return false;
                    }
                    break;
                default:
                    // no more expressions being  ORed together, so continue parsing
                    --$this->pos;
                    return true;
            }
        }
        // no more tokens to parse
        return true;
    }

    /**
     * Process "a op b" where op in (*,/)
     * @return boolean - true if success, false if any error occurred
     */
    
    private function EvaluateMultiplicativeExpression()
    {
        if (!$this->EvaluateUnaryExpression())
        {
            return  false;
        }
        while (($this->pos + 1) < $this->count)
        {
            $token = $this->tokens[++$this->pos];
            if ($token[2] == 'BINARYOP')
            {
                switch ($token[0])
                {
                    case '*':
                    case '/';
                        if ($this->EvaluateUnaryExpression())
                        {
                            if (!$this->EvalBinary($token))
                            {
                                return false;
                            }
                            // else  continue
                        }
                        else
                        {
                            // an error must have occurred
                            return false;
                        }
                        break;
                        break;
                    default:
                        --$this->pos;
                        return true;
                }
            }
            else
            {
                --$this->pos;
                return true;
            }
        }
        return true;
    }
    
    /**
     * Process expressions including functions and parenthesized blocks
     * @return boolean - true if success, false if any error occurred
     */

    private function EvaluatePrimaryExpression()
    {
        if (($this->pos + 1) >= $this->count) {
            $this->AddError("Poorly terminated expression - expected a constant or variable", NULL);
            return false;
        }
        $token = $this->tokens[++$this->pos];
        if ($token[2] == 'LP')
        {
            if (!$this->EvaluateExpressions())
            {
                return false;
            }
            $token = $this->tokens[$this->pos];
            if ($token[2] == 'RP')
            {
                return true;
            }
            else
            {
                $this->AddError("Expected right parentheses", $token);
                return false;
            }
        }
        else
        {
            --$this->pos;
            return $this->EvaluateConstantVarOrFunction();
        }
    }

    /**
     * Process "a op b" where op in (lt, gt, le, ge, <, >, <=, >=)
     * @return boolean - true if success, false if any error occurred
     */
    private function EvaluateRelationExpression()
    {
        if (!$this->EvaluateAdditiveExpression())
        {
            return false;
        }
        while (($this->pos + 1) < $this->count)
        {
            $token = $this->tokens[++$this->pos];
            switch (strtolower($token[0]))
            {
                case '<':
                case 'lt':
                case '<=';
                case 'le':
                case '>':
                case 'gt':
                case '>=';
                case 'ge':
                    if ($this->EvaluateAdditiveExpression())
                    {
                        if (!$this->EvalBinary($token))
                        {
                            return false;
                        }
                        // else  continue
                    }
                    else
                    {
                        // an error must have occurred
                        return false;
                    }
                    break;
                default:
                    --$this->pos;
                    return true;
            }
        }
        return true;
    }

    /**
     * Process "op a" where op in (+,-,!)
     * @return boolean - true if success, false if any error occurred
     */

    private function EvaluateUnaryExpression()
    {
        if (($this->pos + 1) >= $this->count) {
            $this->AddError("Poorly terminated expression - expected a constant or variable", NULL);
            return false;
        }
        $token = $this->tokens[++$this->pos];
        if ($token[2] == 'NOT' || $token[2] == 'BINARYOP')
        {
            switch ($token[0])
            {
                case '+':
                case '-':
                case '!':
                    if (!$this->EvaluatePrimaryExpression())
                    {
                        return false;
                    }
                    return $this->EvalUnary($token);
                    break;
                default:
                    --$this->pos;
                    return $this->EvaluatePrimaryExpression();
            }
        }
        else
        {
            --$this->pos;
            return $this->EvaluatePrimaryExpression();
        }
    }

    /**
     * Returns array of all JavaScript-equivalent variable names used when parsing a string via sProcessStringContainingExpressions
     * @return <type>
     */
    public function GetAllJsVarsUsed()
    {
        if (is_null($this->allVarsUsed)){
            return array();
        }
        $names = array_unique($this->allVarsUsed);
        if (is_null($names)) {
            return array();
        }
        $jsNames = array();
        foreach ($names as $name)
        {
            if (isset($this->amVars[$name]['jsName']) && $this->amVars[$name]['jsName'] != '')
            {
                $jsNames[] = $this->amVars[$name]['jsName'];
            }
        }
        return array_unique($jsNames);
    }

    /**
     * Return the list of all of the JavaScript variables used by the most recent expression
     * @return <type>
     */
    public function GetJsVarsUsed()
    {
        if (is_null($this->varsUsed)){
            return array();
        }
        $names = array_unique($this->varsUsed);
        if (is_null($names)) {
            return array();
        }
        $jsNames = array();
        foreach ($names as $name)
        {
            if (isset($this->amVars[$name]['jsName']) && $this->amVars[$name]['jsName'] != '')
            {
                $jsNames[] = $this->amVars[$name]['jsName'];
            }
        }
        return array_unique($jsNames);
    }

    /**
     * Return the JavaScript variable name for a named variable
     * @param <type> $name
     * @return <type>
     */
    public function GetJsVarFor($name)
    {
        if (isset($this->amVars[$name]) && isset($this->amVars[$name]['jsName']))
        {
            return $this->amVars[$name]['jsName'];
        }
        return '';
    }

    /**
     * Returns array of all variables used when parsing a string via sProcessStringContainingExpressions
     * @return <type>
     */
    public function GetAllVarsUsed()
    {
        return array_unique($this->allVarsUsed);
    }

    /**
     * Return the result of evaluating the equation - NULL if  error
     * @return mixed
     */
    public function GetResult()
    {
        return $this->result[0];
    }

    /**
     * Return an array of errors
     * @return array
     */
    public function GetErrors()
    {
        return $this->errs;
    }

    public function GetJavaScriptEquivalentOfExpression()
    {
        if (!is_null($this->jsExpression))
        {
            return $this->jsExpression;
        }
        if ($this->HasErrors())
        {
            $this->jsExpression = '';
            return '';
        }
        $tokens = $this->tokens;
        $stringParts=array();
        $numTokens = count($tokens);
        for ($i=0;$i<$numTokens;++$i)
        {
            $token = $tokens[$i];
            // When do these need to be quoted?

            switch ($token[2])
            {
                case 'DQ_STRING':
                    $stringParts[] = '"' . addcslashes($token[0],'"') . '"'; // htmlspecialchars($token[0],ENT_QUOTES,'UTF-8',false) . "'";
                    break;
                case 'SQ_STRING':
                    $stringParts[] = "'" . addcslashes($token[0],"'") . "'"; // htmlspecialchars($token[0],ENT_QUOTES,'UTF-8',false) . "'";
                    break;
                case 'SGQA':
                case 'WORD':
                    if ($i+1<$numTokens && $tokens[$i+1][2] == 'LP')
                    {
                        // then word is a function name
                        $funcInfo = $this->amValidFunctions[$token[0]];
                        if ($funcInfo[1] == 'NA')
                        {
                            return '';  // to indicate that this is trying to use a undefined function.  Need more graceful solution
                        }
                        $stringParts[] = $funcInfo[1];
                    }
                    else if ($i+1<$numTokens && $tokens[$i+1][2] == 'ASSIGN')
                    {
                        $varInfo = $this->GetVarInfo($token[0]);
                        $jsName = $varInfo['jsName'];
                        $relevanceNum = (isset($varInfo['relevanceNum']) ? $varInfo['relevanceNum'] : '');
                        $stringParts[] = "document.getElementById('" . $jsName . "').value";
                        if ($tokens[$i+1][0] == '+=')
                        {
                            // Javascript does concatenation unless both left and right side are numbers, so refactor the equation
                            if (preg_match("/^.*\.NAOK$/",$token[0]))
                            {
                                $varName = preg_replace("/^(.*)\.NAOK$/", "$1", $token[0]);
                            }
                            else
                            {
                                $varName = $token[0];
                            }
                            $stringParts[] = " = LEMval('" . $varName . "') + ";
                            ++$i;
                        }
                    }
                    else
                    {
                        $varInfo = $this->GetVarInfo($token[0]);
                        $jsName = $varInfo['jsName'];
                        $relevanceNum = (isset($varInfo['relevanceNum']) ? $varInfo['relevanceNum'] : '');
                        if ($jsName != '')
                        {
                            if (preg_match("/^.*\.NAOK$/",$token[0]))
                            {
                                $varName = preg_replace("/^(.*)\.NAOK$/", "$1", $token[0]);
                            }
                            else
                            {
                                $varName = $token[0];
                            }
                            $stringParts[] = "LEMval('" . $varName . "') ";
                        }
                        else
                        {
                            $stringParts[] = is_numeric($varInfo['codeValue']) ? $varInfo['codeValue'] : ("'" . addcslashes($varInfo['codeValue'],"'") . "'"); // htmlspecialchars($varInfo['codeValue'],ENT_QUOTES,'UTF-8',false) . "'");
                        }
                    }
                    break;
                case 'LP':
                case 'RP':
                    $stringParts[] = $token[0];
                    break;
                case 'NUMBER':
                    $stringParts[] = is_numeric($token[0]) ? $token[0] : ("'" . $token[0] . "'");
                    break;
                case 'COMMA':
                    $stringParts[] = $token[0] . ' ';
                    break;
                default:
                    // don't need to check type of $token[2] here since already handling SQ_STRING and DQ_STRING above
                    switch ($token[0])
                    {
                        case 'and': $stringParts[] = ' && '; break;
                        case 'or':  $stringParts[] = ' || '; break;
                        case 'lt':  $stringParts[] = ' < '; break;
                        case 'le':  $stringParts[] = ' <= '; break;
                        case 'gt':  $stringParts[] = ' > '; break;
                        case 'ge':  $stringParts[] = ' >= '; break;
                        case 'eq':  case '==': $stringParts[] = ' == '; break;
                        case 'ne':  case '!=': $stringParts[] = ' != '; break;
                        default:    $stringParts[] = ' ' . $token[0] . ' '; break;
                    }
                    break;
            }
        }
        // for each variable that does not have a default value, add clause to throw error if any of them are NA
        $nonNAvarsUsed = array();
        foreach ($this->GetJsVarsUsed() as $var)
        {
            if (!preg_match("/^.*\.NAOK$/", $var))
            {
                $nonNAvarsUsed[] = $var;
            }
        }
        $mainClause = implode('', $stringParts);
        $varsUsed = implode("', '", $nonNAvarsUsed);
        if ($varsUsed != '')
        {
            $this->jsExpression = "LEMif(LEManyNA('" . $varsUsed . "'),''," . $mainClause . ")";
        }
        else
        {
            $this->jsExpression = '(' . $mainClause . ')';
        }
        return $this->jsExpression;
    }

    /**
     * JavaScript Test function - simply writes the result of the current JavaScriptEquivalentFunction to the output buffer.
     * @return <type>
     */
    public function GetJavascriptTestforExpression($expected,$num)
    {
        // assumes that the hidden variables have already been declared
        $expr = $this->GetJavaScriptEquivalentOfExpression();
        if (is_null($expr) || $expr == '') {
            $expr = "'NULL'";
        }
        $jsParts = array();
        $jsParts[] = "val = " . $expr . ";\n";
        $jsParts[] = "klass = (LEMeq(addslashes(val),'" . addslashes($expected) . "')) ? 'ok' : 'error';\n";
        $jsParts[] = "document.getElementById('test_" . $num . "').innerHTML=val;\n";
        $jsParts[] = "document.getElementById('test_" . $num . "').className=klass;\n";
        return implode('',$jsParts);
        
    }

    /**
     * Generate the function needed to dynamically change the value of a <span> section
     * @param <type> $name - the ID name for the function
     * @return <type>
     */
    public function GetJavaScriptFunctionForReplacement($questionNum, $name,$eqn)
    {
        $jsParts = array();
        $jsParts[] = "\n// Tailor Question " . $questionNum . " - " . $name . ": { " . $eqn . " }\n";
        $jsParts[] = "document.getElementById('" . $name . "').innerHTML=\n";
        $jsParts[] = $this->GetJavaScriptEquivalentOfExpression();
        $jsParts[] = ";\n";
        return implode('',$jsParts);
    }

    /**
     * Returns the most recent PrettyPrint string generated by sProcessStringContainingExpressions
     */
    public function GetLastPrettyPrintExpression()
    {
        return $this->prettyPrintSource;
    }

    /**
     * Color-codes Expressions (using HTML <span> tags), showing variable types and values.
     * @return <type>
     */
    public function GetPrettyPrintString()
    {
        // color code the equation, showing not only errors, but also variable attributes
        $errs = $this->errs;
        $tokens = $this->tokens;
        $errCount = count($errs);
        $errIndex = 0;
        if ($errCount > 0)
        {
            usort($errs,"cmpErrorTokens");
        }
        $errSpecificStyle= "style='border-style: solid; border-width: 2px; border-color: red;'";
        $stringParts=array();
        $numTokens = count($tokens);
        $globalErrs=array();
        while ($errIndex < $errCount)
        {
            if ($errs[$errIndex++][1][1]==0)
            {
                // General message, associated with position 0
                $globalErrs[] = $errs[$errIndex-1][0];
            }
            else
            {
                --$errIndex;
                break;
            }
        }
        for ($i=0;$i<$numTokens;++$i)
        {
            $token = $tokens[$i];
            $messages=array();
            $thisTokenHasError=false;
            if ($i==0 && count($globalErrs) > 0)
            {
                $messages = array_merge($messages,$globalErrs);
                $thisTokenHasError=true;
            }
            if ($errIndex < $errCount && $token[1] == $errs[$errIndex][1][1])
            {
                $messages[] = $errs[$errIndex][0];
                $thisTokenHasError=true;
            }
            if ($thisTokenHasError)
            {
                $stringParts[] = "<span title='" . implode('; ',$messages) . "' " . $errSpecificStyle . ">";
            }
            switch ($token[2])
            {
                case 'DQ_STRING':
//                    $messages[] = 'STRING';
                    $stringParts[] = "<span title='" . implode('; ',$messages) . "' style='color: gray'>\"";
                    $stringParts[] = $token[0]; // htmlspecialchars($token[0],ENT_QUOTES,'UTF-8',false);
                    $stringParts[] = "\"</span>";
                    break;
                case 'SQ_STRING':
//                    $messages[] = 'STRING';
                    $stringParts[] = "<span title='" . implode('; ',$messages) . "' style='color: gray'>'";
                    $stringParts[] = $token[0]; // htmlspecialchars($token[0],ENT_QUOTES,'UTF-8',false);
                    $stringParts[] = "'</span>";
                    break;
                case 'SGQA':
//                    $messages[] = 'SGQA Identifier';
                    $stringParts[] = "<span title='"  . implode('; ',$messages) . "' style='color: #4C88BE; font-weight: bold'>";
                    $stringParts[] = $token[0];
                    $stringParts[] = "</span>";
                    break;
                case 'WORD':
                    if ($i+1<$numTokens && $tokens[$i+1][2] == 'LP')
                    {
                        // then word is a function name
//                        $messages[] = 'Function';
                        $stringParts[] = "<span title='" . implode('; ',$messages) . "' style='color: blue; font-weight: bold'>";
                        $stringParts[] = $token[0];
                        $stringParts[] = "</span>";
                    }
                    else
                    {
                        $varInfo = $this->GetVarInfo($token[0]);
                        if (isset($varInfo['isOnCurrentPage']) && $varInfo['isOnCurrentPage']=='Y')
                        {
                            $messages[] = 'Variable that is set on current page';
                            if (strlen(trim($varInfo['jsName'])) > 0) {
                                $messages[] = $varInfo['jsName'];
                            }
                            if (strlen(trim($varInfo['codeValue'])) > 0) {
                                $messages[] = 'value=' . htmlspecialchars($varInfo['codeValue'],ENT_QUOTES,'UTF-8',false); 
                            }
                            $stringParts[] = "<span title='". implode('; ',$messages) . "' style='color: #a0522d; font-weight: bold'>";
                            $stringParts[] = $token[0];
                            $stringParts[] = "</span>";
                        }
                        else
                        {
                            if (strlen(trim($varInfo['jsName'])) > 0) {
                                $messages[] = $varInfo['jsName'];
                            }
                            if (strlen(trim($varInfo['codeValue'])) > 0) {
                                $messages[] = 'value=' . htmlspecialchars($varInfo['codeValue'],ENT_QUOTES,'UTF-8',false); 
                            }
                            $stringParts[] = "<span title='"  . implode('; ',$messages) . "' style='color: #228b22; font-weight: bold'>";
                            $stringParts[] = $token[0];
                            $stringParts[] = "</span>";
                        }
                    }
                    break;
                case 'ASSIGN':
                    $messages[] = 'Assigning a new value to a variable';
                    $stringParts[] = "<span title='" . implode('; ',$messages) . "' style='color: red; font-weight: bold'>";
                    $stringParts[] = $token[0];
                    $stringParts[] =  "</span>";
                    break;
                case 'LP':
                case 'RP':
                case 'COMMA':
                case 'NUMBER':
                    $stringParts[] = $token[0];
                    break;
                default:
                    $stringParts[] = ' ' . $token[0] . ' ';
                    break;
            }
            if ($thisTokenHasError)
            {
                $stringParts[] = "</span>";
                ++$errIndex;
            }
        }
        return "<span style='background-color: #eee8aa;'>" . implode('', $stringParts) . "</span>";
    }

    /**
     * Return an array of human-readable errors (message, offending token, offset of offending token within equation)
     * @return array
     */
    public function GetReadableErrors()
    {
        // Try to color code the equation
        // Surround whole message with a span, so can add ToolTip of generic error messages
        // Surround each identifiable error with a span so can color code it and attach its own ToolTip (e.g. unknown function or variable name)
        if (count($this->errs) == 0) {
            return '';
        }
        usort($this->errs,"cmpErrorTokens");    // sort errors in order of occurence in string
        $parts = array();
        $curpos = 0;
        $generalErrs = array();
        foreach ($this->errs as $err)
        {
            $token = $err[1];
            if (is_null($token)) {
                if (strlen($err[0]) > 0) {
                    $generalErrs[] = $err[0];
                }
                continue;
            }
            $pos = $token[1];
            if ($token[2] == 'DQ_STRING')
            {
                $tok = '"' . addslashes($token[0]) . '"';
            }
            else if ($token[2] == 'SQ_STRING')
            {
                $tok = "'" . addslashes($token[0]) . "'";
            }
            else
            {
                $tok =$token[0];
            }
            if (!is_numeric($pos)) {
                if (strlen($err[0]) > 0) {
                    $generalErrs[] = $err[0];
                }
                continue;
            }
            $parts[]= array(
                'OkString' => substr($this->expr,$curpos,($pos-$curpos)),
                'BadString' => $tok,
                'msg' => $err[0]
                );
            $curpos = $pos + strlen($tok);
        }
        if ($curpos < strlen($this->expr)) {
            $parts[] = array(
                'OkString' => substr($this->expr, $curpos, strlen($this->expr) - $curpos),
                'BadString' => '',
                'msg' => ''
            );
        }
        $msg = '';
        $errSpecificStyle= "style='border-style: solid; border-width: 2px; border-color: red;'";
        $errGeneralStyle = "style='background-color: #eee8aa;'";
        foreach ($parts as $part)
        {
            $msg .= $part['OkString'];
            if (isset($part['BadString']) && strlen($part['BadString']) > 0)
            {
                if (strlen($part['msg']) > 0) {
                    $msg .= "<span title='" . $part['msg'] . "' " . $errSpecificStyle . ">" . $part['BadString'] . "</span>";
                }
                else {
                    $msg .= "<span " . $errSpecificStyle . ">" . $part['BadString'] . "</span>";
                }
            }
        }
        $extraErrs = implode("; ", $generalErrs);
        $msg = "<span title='" . $extraErrs . "' " . $errGeneralStyle . ">" . $msg . "</span>";
        return $msg;
    }
    
    /**
     * Get information about the variable, including JavaScript name, read-write status, and whether set on current page.
     * @param <type> $varname
     * @return <type>
     */
    public function GetVarInfo($varname)
    {
        if (isset($this->amVars[$varname]))
        {
            return $this->amVars[$varname];
        }
        return NULL;
    }

    /**
     * Return array of the list of variables used  in the equation
     * @return array
     */
    public function GetVarsUsed()
    {
        return array_unique($this->varsUsed);
    }

    /**
     * Return true if there were syntax or processing errors
     * @return boolean
     */
    public function HasErrors()
    {
        return (count($this->errs) > 0);
    }

    /**
     * Return true if there are syntax errors
     * @return boolean
     */
    private function HasSyntaxErrors()
    {
        // check for bad tokens
        // check for unmatched parentheses
        // check for undefined variables
        // check for undefined functions (but can't easily check allowable # elements?)

        $nesting = 0;

        for ($i=0;$i<$this->count;++$i)
        {
            $token = $this->tokens[$i];
            switch ($token[2])
            {
                case 'LP':
                    ++$nesting;
                    break;
                case 'RP':
                    --$nesting;
                    if ($nesting < 0)
                    {
                        $this->AddError("Extra right parentheses detected", $token);
                    }
                    break;
                case 'WORD':
                case 'SGQA':
                    if ($i+1 < $this->count and $this->tokens[$i+1][2] == 'LP')
                    {
                        if (!$this->isValidFunction($token[0]))
                        {
                            $this->AddError("Undefined function", $token);
                        }
                    }
                    else
                    {
                        if (!($this->isValidVariable($token[0])))
                        {
                            $this->AddError("Undefined variable", $token);
                        }
                    }
                    break;
                case 'OTHER':
                    $this->AddError("Unsupported syntax", $token);
                    break;
                default:
                    break;
            }
        }
        if ($nesting != 0)
        {
            $this->AddError("Parentheses not balanced",NULL);
        }
        return (count($this->errs) > 0);
    }

    /**
     * Return true if the function name is registered
     * @param <type> $name
     * @return boolean
     */

    private function isValidFunction($name)
    {
        return array_key_exists($name,$this->amValidFunctions);
    }

    /**
     * Return true if the variable name is registered
     * @param <type> $name
     * @return boolean
     */
    private function isValidVariable($name)
    {
        return array_key_exists($name,$this->amVars);
    }

    /**
     * Return true if the variable name is writable
     * @param <type> $name
     * @return <type>
     */
    private function isWritableVariable($name)
    {
        $var = $this->amVars[$name];
        return ($var['readWrite'] == 'Y');
    }
    
    /**
     * Process an expression and return its boolean value
     * @param <type> $expr
     * @return <type>
     */
    public function ProcessBooleanExpression($expr)
    {
        $status = $this->Evaluate($expr);
        if (!$status) {
            return false;    // if there are errors in the expression, hide it?
        }
        $result = $this->GetResult();
        if (is_null($result)) {
            return false;    // if there are errors in the expression, hide it?
        }
        return (boolean) $result;
    }

    /**
     * Start processing a group of substitions - will be incrementally numbered
     */

    public function StartProcessingGroup()
    {
        $this->substitutionNum=0;
        $this->substitutionInfo=array(); // array of JavaScripts for managing each substitution
    }

    /**
     * Process multiple substitution iterations of a full string, containing multiple expressions delimited by {}, return a consolidated string
     * @param <type> $src
     * @param <type> $numRecursionLevels - number of levels of recursive substitution to perform
     * @param <type> $whichPrettyPrint - if recursing, specify which pretty-print iteration is desired
     * @return <type>
     */

    public function sProcessStringContainingExpressions($src, $questionNum=0, $numRecursionLevels=1, $whichPrettyPrintIteration=1)
    {
        // tokenize string by the {} pattern, properly dealing with strings in quotations, and escaped curly brace values
        $this->allVarsUsed = array();
        $result = $src;
        $prettyPrint = '';

        for($i=1;$i<=$numRecursionLevels;++$i)
        {
            // TODO - Since want to use <span> for dynamic substitution, what if there are recursive substititons?
            $result = $this->sProcessStringContainingExpressionsHelper(htmlspecialchars_decode($result,ENT_QUOTES),$questionNum);
            if ($i == $whichPrettyPrintIteration)
            {
                $prettyPrint = $this->prettyPrintSource;
            }
        }
        $this->prettyPrintSource = $prettyPrint;    // ensure that if doing recursive substition, can get original source to pretty print
        return $result;
    }

    /**
     * Process one substitution iteration of a full string, containing multiple expressions delimited by {}, return a consolidated string
     * @param <type> $src
     * @return <type>
     */

    public function sProcessStringContainingExpressionsHelper($src, $questionNum)
    {
        // tokenize string by the {} pattern, properly dealing with strings in quotations, and escaped curly brace values
        $stringParts = $this->asSplitStringOnExpressions($src);

        $resolvedParts = array();
        $prettyPrintParts = array();

        foreach ($stringParts as $stringPart)
        {
            if ($stringPart[2] == 'STRING') {
                $resolvedParts[] =  $stringPart[0];
                $prettyPrintParts[] = $stringPart[0];
            }
            else {
                ++$this->substitutionNum;
                if ($this->Evaluate(substr($stringPart[0],1,-1)))
                {
                    $resolvedPart = $this->GetResult();
                }
                else
                {
                    // show original and errors in-line
//                    $resolvedParts[] = $this->GetReadableErrors();
                    $resolvedPart = $this->GetPrettyPrintString();
                }
                $jsVarsUsed = $this->GetJsVarsUsed();
                $prettyPrintParts[] = $this->GetPrettyPrintString();
                $this->allVarsUsed = array_merge($this->allVarsUsed,$this->GetVarsUsed());

                // TODO Note, don't want these SPANS if they are part of a JavaScript substitution!
                if (count($jsVarsUsed) > 0)
                {
                    $idName = "LEMtailor_Q_" . $questionNum . "_" . $this->substitutionNum;
//                    $resolvedParts[] = "<span id='" . $idName . "' name='" . $idName . "'>" . htmlspecialchars($resolvedPart,ENT_QUOTES,'UTF-8',false) . "</span>"; // TODO - encode within SPAN?
                    $resolvedParts[] = "<span id='" . $idName . "' name='" . $idName . "'>" . $resolvedPart . "</span>";
                    $this->substitutionVars[$idName] = 1;
                    $this->substitutionInfo[] = array(
                        'questionNum' => $questionNum,
                        'num' => $this->substitutionNum,
                        'id' => $idName,
                        'raw' => $stringPart[0],
                        'result' => $resolvedPart,
                        'vars' => implode('|',$this->GetJsVarsUsed()),
                        'js' => $this->GetJavaScriptFunctionForReplacement($questionNum, $idName, substr($stringPart[0],1,-1)),
                    );
                }
                else
                {
                    $resolvedParts[] = $resolvedPart;
                }
            }
        }
        $result = implode('',$this->flatten_array($resolvedParts));
        $this->prettyPrintSource = implode('',$this->flatten_array($prettyPrintParts));
        return $result;    // recurse in case there are nested ones, avoiding infinite loops?
    }

    public function GetCurrentSubstitutionInfo()
    {
        return $this->substitutionInfo;
    }

    /**
     * Flatten out an array, keeping it in the proper order
     * @param array $a
     * @return array
     */

    private function flatten_array(array $a) {
        $i = 0;
        while ($i < count($a)) {
            if (is_array($a[$i])) {
                array_splice($a, $i, 1, $a[$i]);
            } else {
                $i++;
            }
        }
        return $a;
    }


    /**
     * Run a registered function
     * @param <type> $funcNameToken
     * @param <type> $params
     * @return boolean
     */
    private function RunFunction($funcNameToken,$params)
    {
        $name = $funcNameToken[0];
        if (!$this->isValidFunction($name))
        {
            return false;
        }
        $func = $this->amValidFunctions[$name];
        $funcName = $func[0];
        $numArgs = count($params);
        $result=1;  // default value for $this->onlyparse

        if (function_exists($funcName)) {
            $numArgsAllowed = array_slice($func, 3);
            $argsPassed = is_array($params) ? count($params) : 0;

            // for unlimited #  parameters
            try
            {
                if (in_array(-1, $numArgsAllowed)) {
                    if ($argsPassed == 0)
                    {
                        $this->AddError("Function must have at least one argument", $funcNameToken);
                        return false;
                    }
                    if (!$this->onlyparse) {
                        $result = $funcName($params);
                    }
                // Call  function with the params passed
                } elseif (in_array($argsPassed, $numArgsAllowed)) {

                    switch ($argsPassed) {
                    case 0:
                        if (!$this->onlyparse) {
                            $result = $funcName();
                        }
                        break;
                    case 1:
                        if (!$this->onlyparse) {
                            switch($funcName) {
                                case 'acos':
                                case 'asin':
                                case 'atan':
                                case 'cos':
                                case 'exp':
                                case 'is_nan':
                                case 'log':
                                case 'sin':
                                case 'sqrt':
                                case 'tan':
                                    if (is_float($params[0]))
                                    {
                                        $result = $funcName(floatval($params[0]));
                                    }
                                    else
                                    {
                                        $result = NAN;
                                    }
                                    break;
                                default:
                                    $result = $funcName($params[0]);
                                     break;
                            }
                        }
                        break;
                    case 2:
                        if (!$this->onlyparse) {
                            switch($funcName) {
                                case 'atan2':
                                    if (is_float($params[0]) && is_float($params[1]))
                                    {
                                        $result = $funcName(floatval($params[0]),floatval($params[1]));
                                    }
                                    else
                                    {
                                        $result = NAN;
                                    }
                                    break;
                                default:
                                    $result = $funcName($params[0], $params[1]);
                                     break;
                            }
                        }
                        break;
                    case 3:
                        if (!$this->onlyparse) {
                            $result = $funcName($params[0], $params[1], $params[2]);
                        }
                        break;
                    case 4:
                        if (!$this->onlyparse) {
                            $result = $funcName($params[0], $params[1], $params[2], $params[3]);
                        }
                        break;
                    case 5:
                        if (!$this->onlyparse) {
                            $result = $funcName($params[0], $params[1], $params[2], $params[3], $params[4]);
                        }
                        break;
                    case 6:
                        if (!$this->onlyparse) {
                            $result = $funcName($params[0], $params[1], $params[2], $params[3], $params[4], $params[5]);
                        }
                        break;
                    default:
                        $this->AddError("Unsupported number of arguments: " . $argsPassed, $funcNameToken);
                        return false;
                    }

                } else {
                    $this->AddError("Function does not support that number of arguments:  " . $argsPassed .
                            ".  Function supports this many arguments, where -1=unlimited: " . implode(',', $numArgsAllowed), $funcNameToken);
                    return false;
                }
            }
            catch (Exception $e)
            {
                $this->AddError($e->getMessage(),$funcNameToken);
                return false;
            }
            $token = array($result,$funcNameToken[1],'NUMBER');
            $this->StackPush($token);
            return true;
        }
    }

    /**
     * Add user functions to array of allowable functions within the equation.
     * $functions is an array of key to value mappings like this:
     * 'newfunc' => array('my_func_script', 1,3)
     * where 'newfunc' is the name of an allowable function wihtin the  expression, 'my_func_script' is the registered PHP function name,
     * and 1,3 are the list of  allowable numbers of paremeters (so my_func() can take 1 or 3 parameters.
     * 
     * @param array $functions 
     */

    public function RegisterFunctions(array $functions) {
        $this->amValidFunctions= array_merge($this->amValidFunctions, $functions);
    }

    /**
     * Add list of allowable variable names within the equation
     * $varnames is an array of key to value mappings like this:
     * 'myvar' => value
     * where value is optional (e.g. can be blank), and can be any scalar type (e.g. string, number, but not array)
     * the system will use the values as  fast lookup when doing calculations, but if it needs to set values, it will call
     * the interface function to set the values by name
     *
     * @param array $varnames
     */
    public function RegisterVarnamesUsingMerge(array $varnames) {
        $this->amVars = array_merge($this->amVars, $varnames);
    }

    public function RegisterVarnamesUsingReplace(array $varnames) {
        $this->amVars = array_merge(array(), $varnames);
    }

    /**
     * Set the value of a registered variable
     * @param $op - the operator (=,*=,/=,+=,-=)
     * @param <type> $name
     * @param <type> $value
     */
    private function setVariableValue($op,$name,$value)
    {
        // TODO - set this externally
        if ($this->onlyparse)
        {
            return 1;
        }
        switch($op)
        {
            case '=':
                $this->amVars[$name]['codeValue'] = $value;
                break;
            case '*=':
                $this->amVars[$name]['codeValue'] *= $value;
                break;
            case '/=':
                $this->amVars[$name]['codeValue'] /= $value;
                break;
            case '+=':
                $this->amVars[$name]['codeValue'] += $value;
                break;
            case '-=':
                $this->amVars[$name]['codeValue'] -= $value;
                break;
        }
        return $this->amVars[$name]['codeValue'];
    }

    public function asSplitStringOnExpressions($src)
    {
        // tokenize string by the {} pattern, propertly dealing with strings in quotations, and escaped curly brace values
        $tokens0 = preg_split($this->sExpressionRegex,$src,-1,(PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE));

        $tokens = array();
        // Add token_type to $tokens:  For each token, test each categorization in order - first match will be the best.
        for ($j=0;$j<count($tokens0);++$j)
        {
            $token = $tokens0[$j];
            if (preg_match($this->sExpressionRegex,$token[0]))
            {
                $token[2] = 'EXPRESSION';
            }
            else
            {
                $token[2] = 'STRING';    // does type matter here?
            }
            $tokens[] = $token;
        }
        return $tokens;
    }

    /**
     * Pop a value token off of the stack
     * @return token
     */

    private function StackPop()
    {
        if (count($this->stack) > 0)
        {
            return array_pop($this->stack);
        }
        else
        {
            $this->AddError("Tried to pop value off of empty stack", NULL);
            return NULL;
        }
    }

    /**
     * Stack only holds values (number, string), not operators
     * @param array $token
     */

    private function StackPush(array $token)
    {
        if ($this->onlyparse)
        {
            // If only parsing, still want to validate syntax, so use "1" for all variables
            switch($token[2])
            {
                case 'DQ_STRING':
                case 'SQ_STRING':
                    $this->stack[] = array(1,$token[1],$token[2]);
                    break;
                case 'NUMBER':
                default:
                    $this->stack[] = array(1,$token[1],'NUMBER');
                    break;
            }
        }
        else
        {
            $this->stack[] = $token;
        }
    }

    /**
     * Split the source string into tokens, removing whitespace, and categorizing them by type.
     *
     * @param $src
     * @return array
     */

    private function amTokenize($src)
    {
        // $tokens0 = array of tokens from equation, showing value and offset position.  Will include SPACE, which should be removed
        $tokens0 = preg_split($this->sTokenizerRegex,$src,-1,(PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE));

        // $tokens = array of tokens from equation, showing value, offsete position, and type.  Will not contain SPACE, but will contain OTHER
        $tokens = array();
        // Add token_type to $tokens:  For each token, test each categorization in order - first match will be the best.
        for ($j=0;$j<count($tokens0);++$j)
        {
            for ($i=0;$i<count($this->asCategorizeTokensRegex);++$i)
            {
                $token = $tokens0[$j][0];
                if (preg_match($this->asCategorizeTokensRegex[$i],$token))
                {
                    if ($this->asTokenType[$i] !== 'SPACE') {
                        $tokens0[$j][2] = $this->asTokenType[$i];
                        if ($this->asTokenType[$i] == 'DQ_STRING' || $this->asTokenType[$i] == 'SQ_STRING')
                        {
                            // remove outside quotes
                            $unquotedToken = stripslashes(substr($token,1,-1));
                            $tokens0[$j][0] = $unquotedToken;
                        }
                        $tokens[] = $tokens0[$j];   // get first matching non-SPACE token type and push onto $tokens array
                    }
                    break;  // only get first matching token type
                }
            }
        }
        return $tokens;
    }

    /**
     * Unit test the asSplitStringOnExpressions() function to ensure that accurately parses out all expressions
     * surrounded by curly braces, allowing for strings and escaped curly braces.
     */

    static function UnitTestStringSplitter()
    {
       $tests = <<<EOD
"this is a string that contains {something in curly braces)"
This example has escaped curly braces like \{this is not an equation\}
Should the parser check for unmatched { opening curly braces?
What about for unmatched } closing curly braces?
{name}, you said that you are {age} years old, and that you have {numKids} {if((numKids==1),'child','children')} and {numPets} {if((numPets==1),'pet','pets')} running around the house. So, you have {numKids + numPets} wild {if((numKids + numPets ==1),'beast','beasts')} to chase around every day.
Since you have more {if((INSERT61764X1X3 > INSERT61764X1X4),'children','pets')} than you do {if((INSERT61764X1X3 > INSERT61764X1X4),'pets','children')}, do you feel that the {if((INSERT61764X1X3 > INSERT61764X1X4),'pets','children')} are at a disadvantage?
EOD;

        $em = new ExpressionManager();

        foreach(explode("\n",$tests) as $test)
        {
            $tokens = $em->asSplitStringOnExpressions($test);
            print '<b>' . $test . '</b><hr/>';
            print '<code>';
            print implode("<br/>\n",explode("\n",print_r($tokens,TRUE)));
            print '</code><hr/>';
        }
    }

    /**
     * Unit test the Tokenizer - Tokenize and generate a HTML-compatible print-out of a comprehensive set of test cases
     */

    static function UnitTestTokenizer()
    {
        // Comprehensive test cases for tokenizing
        $tests = <<<EOD
        String:  "Can strings contain embedded \"quoted passages\" (and parentheses + other characters?)?"
        String:  "can single quoted strings" . 'contain nested \'quoted sections\'?';
        Parens:  upcase('hello');
        Numbers:  42 72.35 -15 +37 42A .5 0.7
        And_Or: (this and that or the other);  Sandles, sorting; (a && b || c)
        Words:  hi there, my name is C3PO!
        UnaryOps: ++a, --b !b
        BinaryOps:  (a + b * c / d)
        Comparators:  > >= < <= == != gt ge lt le eq ne (target large gents built agile less equal)
        Assign:  = += -= *= /=
        SGQA:  1X6X12 1X6X12ber1 1X6X12ber1_lab1 3583X84X249
        Errors: Apt # 10C; (2 > 0) ? 'hi' : 'there'; array[30]; >>> <<< /* this is not a comment */ // neither is this
EOD;

        $em = new ExpressionManager();

        foreach(explode("\n",$tests) as $test)
        {
            $tokens = $em->amTokenize($test);
            print '<b>' . $test . '</b><hr/>';
            print '<code>';
            print implode("<br/>\n",explode("\n",print_r($tokens,TRUE)));
            print '</code><hr/>';
        }
    }

    /**
     * Unit test the Evaluator, allowing for passing in of extra functions, variables, and tests
     */
    
    static function UnitTestEvaluator()
    {
        global $exprmgr_extraVars, $exprmgr_extraTests; // so can access variables from ExpressionManagerFunctions.php
        // Some test cases for Evaluator
        $vars = array(
'one' => array('codeValue'=>1, 'jsName'=>'java_one', 'readWrite'=>'Y', 'isOnCurrentPage'=>'N'),
'two' => array('codeValue'=>2, 'jsName'=>'java_two', 'readWrite'=>'Y', 'isOnCurrentPage'=>'N'),
'three' => array('codeValue'=>3, 'jsName'=>'java_three', 'readWrite'=>'Y', 'isOnCurrentPage'=>'N'),
'four' => array('codeValue'=>4, 'jsName'=>'java_four', 'readWrite'=>'Y', 'isOnCurrentPage'=>'N'),
'five' => array('codeValue'=>5, 'jsName'=>'java_five', 'readWrite'=>'Y', 'isOnCurrentPage'=>'N'),
'six' => array('codeValue'=>6, 'jsName'=>'java_six', 'readWrite'=>'Y', 'isOnCurrentPage'=>'N'),
'seven' => array('codeValue'=>7, 'jsName'=>'java_seven', 'readWrite'=>'Y', 'isOnCurrentPage'=>'N'),
'eight' => array('codeValue'=>8, 'jsName'=>'java_eight', 'readWrite'=>'Y', 'isOnCurrentPage'=>'N'),
'nine' => array('codeValue'=>9, 'jsName'=>'java_nine', 'readWrite'=>'Y', 'isOnCurrentPage'=>'N'),
'ten' => array('codeValue'=>10, 'jsName'=>'java_ten', 'readWrite'=>'Y', 'isOnCurrentPage'=>'N'),
'half' => array('codeValue'=>.5, 'jsName'=>'java_half', 'readWrite'=>'Y', 'isOnCurrentPage'=>'N'),
'hi' => array('codeValue'=>'there', 'jsName'=>'java_hi', 'readWrite'=>'Y', 'isOnCurrentPage'=>'N'),
'hello' => array('codeValue'=>"Tom", 'jsName'=>'java_hello', 'readWrite'=>'Y', 'isOnCurrentPage'=>'N'),
'a' => array('codeValue'=>0, 'jsName'=>'java_a', 'readWrite'=>'Y', 'isOnCurrentPage'=>'Y'),
'b' => array('codeValue'=>0, 'jsName'=>'java_b', 'readWrite'=>'Y', 'isOnCurrentPage'=>'Y'),
'c' => array('codeValue'=>0, 'jsName'=>'java_c', 'readWrite'=>'Y', 'isOnCurrentPage'=>'Y'),
'd' => array('codeValue'=>0, 'jsName'=>'java_d', 'readWrite'=>'Y', 'isOnCurrentPage'=>'Y'),
'eleven' => array('codeValue'=>11, 'jsName'=>'java_eleven', 'readWrite'=>'Y', 'isOnCurrentPage'=>'N'),
'twelve' => array('codeValue'=>12, 'jsName'=>'java_twelve', 'readWrite'=>'Y', 'isOnCurrentPage'=>'N'),
// Constants
'ADMINEMAIL' => array('codeValue'=>'value for {ADMINEMAIL}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'ADMINNAME' => array('codeValue'=>'value for {ADMINNAME}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'AID' => array('codeValue'=>'value for {AID}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'ANSWERSCLEARED' => array('codeValue'=>'value for {ANSWERSCLEARED}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'ANSWER' => array('codeValue'=>'value for {ANSWER}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'ASSESSMENTS' => array('codeValue'=>'value for {ASSESSMENTS}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'ASSESSMENT_CURRENT_TOTAL' => array('codeValue'=>'value for {ASSESSMENT_CURRENT_TOTAL}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'ASSESSMENT_HEADING' => array('codeValue'=>'"Can strings contain embedded \"quoted passages\" (and parentheses + other characters?)?"', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'CHECKJAVASCRIPT' => array('codeValue'=>'value for {CHECKJAVASCRIPT}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'CLEARALL' => array('codeValue'=>'value for {CLEARALL}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'CLOSEWINDOW' => array('codeValue'=>'value for {CLOSEWINDOW}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'COMPLETED' => array('codeValue'=>'value for {COMPLETED}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'DATESTAMP' => array('codeValue'=>'value for {DATESTAMP}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'EMAILCOUNT' => array('codeValue'=>'value for {EMAILCOUNT}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'EMAIL' => array('codeValue'=>'value for {EMAIL}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'EXPIRY' => array('codeValue'=>'value for {EXPIRY}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'FIRSTNAME' => array('codeValue'=>'value for {FIRSTNAME}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'GID' => array('codeValue'=>'value for {GID}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'GROUPDESCRIPTION' => array('codeValue'=>'value for {GROUPDESCRIPTION}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'GROUPNAME' => array('codeValue'=>'value for {GROUPNAME}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'INSERTANS:123X45X67' => array('codeValue'=>'value for {INSERTANS:123X45X67}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'INSERTANS:123X45X67ber' => array('codeValue'=>'value for {INSERTANS:123X45X67ber}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'INSERTANS:123X45X67ber_01a' => array('codeValue'=>'value for {INSERTANS:123X45X67ber_01a}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'LANGUAGECHANGER' => array('codeValue'=>'value for {LANGUAGECHANGER}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'LANGUAGE' => array('codeValue'=>'value for {LANGUAGE}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'LANG' => array('codeValue'=>'value for {LANG}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'LASTNAME' => array('codeValue'=>'value for {LASTNAME}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'LOADERROR' => array('codeValue'=>'value for {LOADERROR}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'LOADFORM' => array('codeValue'=>'value for {LOADFORM}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'LOADHEADING' => array('codeValue'=>'value for {LOADHEADING}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'LOADMESSAGE' => array('codeValue'=>'value for {LOADMESSAGE}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'NAME' => array('codeValue'=>'value for {NAME}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'NAVIGATOR' => array('codeValue'=>'value for {NAVIGATOR}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'NOSURVEYID' => array('codeValue'=>'value for {NOSURVEYID}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'NOTEMPTY' => array('codeValue'=>'value for {NOTEMPTY}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'NULL' => array('codeValue'=>'value for {NULL}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'NUMBEROFQUESTIONS' => array('codeValue'=>'value for {NUMBEROFQUESTIONS}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'OPTOUTURL' => array('codeValue'=>'value for {OPTOUTURL}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'PASSTHRULABEL' => array('codeValue'=>'value for {PASSTHRULABEL}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'PASSTHRUVALUE' => array('codeValue'=>'value for {PASSTHRUVALUE}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'PERCENTCOMPLETE' => array('codeValue'=>'value for {PERCENTCOMPLETE}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'PERC' => array('codeValue'=>'value for {PERC}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'PRIVACYMESSAGE' => array('codeValue'=>'value for {PRIVACYMESSAGE}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'PRIVACY' => array('codeValue'=>'value for {PRIVACY}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'QID' => array('codeValue'=>'value for {QID}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'QUESTIONHELPPLAINTEXT' => array('codeValue'=>'value for {QUESTIONHELPPLAINTEXT}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'QUESTIONHELP' => array('codeValue'=>'"can single quoted strings" . \'contain nested \'quoted sections\'?', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'QUESTION_CLASS' => array('codeValue'=>'value for {QUESTION_CLASS}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'QUESTION_CODE' => array('codeValue'=>'value for {QUESTION_CODE}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'QUESTION_ESSENTIALS' => array('codeValue'=>'value for {QUESTION_ESSENTIALS}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'QUESTION_FILE_VALID_MESSAGE' => array('codeValue'=>'value for {QUESTION_FILE_VALID_MESSAGE}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'QUESTION_HELP' => array('codeValue'=>'Can strings have embedded <tags> like <html>, or even unbalanced "quotes or entities without terminal semicolons like &amp and  &lt?', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'QUESTION_INPUT_ERROR_CLASS' => array('codeValue'=>'value for {QUESTION_INPUT_ERROR_CLASS}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'QUESTION_MANDATORY' => array('codeValue'=>'value for {QUESTION_MANDATORY}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'QUESTION_MAN_CLASS' => array('codeValue'=>'value for {QUESTION_MAN_CLASS}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'QUESTION_MAN_MESSAGE' => array('codeValue'=>'value for {QUESTION_MAN_MESSAGE}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'QUESTION_NUMBER' => array('codeValue'=>'value for {QUESTION_NUMBER}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'QUESTION_TEXT' => array('codeValue'=>'value for {QUESTION_TEXT}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'QUESTION_VALID_MESSAGE' => array('codeValue'=>'value for {QUESTION_VALID_MESSAGE}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'QUESTION' => array('codeValue'=>'value for {QUESTION}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'REGISTERERROR' => array('codeValue'=>'value for {REGISTERERROR}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'REGISTERFORM' => array('codeValue'=>'value for {REGISTERFORM}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'REGISTERMESSAGE1' => array('codeValue'=>'value for {REGISTERMESSAGE1}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'REGISTERMESSAGE2' => array('codeValue'=>'value for {REGISTERMESSAGE2}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'RESTART' => array('codeValue'=>'value for {RESTART}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'RETURNTOSURVEY' => array('codeValue'=>'value for {RETURNTOSURVEY}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SAVEALERT' => array('codeValue'=>'value for {SAVEALERT}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SAVEDID' => array('codeValue'=>'value for {SAVEDID}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SAVEERROR' => array('codeValue'=>'value for {SAVEERROR}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SAVEFORM' => array('codeValue'=>'value for {SAVEFORM}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SAVEHEADING' => array('codeValue'=>'value for {SAVEHEADING}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SAVEMESSAGE' => array('codeValue'=>'value for {SAVEMESSAGE}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SAVE' => array('codeValue'=>'value for {SAVE}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SGQ' => array('codeValue'=>'value for {SGQ}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SID' => array('codeValue'=>'value for {SID}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SITENAME' => array('codeValue'=>'value for {SITENAME}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SUBMITBUTTON' => array('codeValue'=>'value for {SUBMITBUTTON}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SUBMITCOMPLETE' => array('codeValue'=>'value for {SUBMITCOMPLETE}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SUBMITREVIEW' => array('codeValue'=>'value for {SUBMITREVIEW}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SURVEYCONTACT' => array('codeValue'=>'value for {SURVEYCONTACT}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SURVEYDESCRIPTION' => array('codeValue'=>'value for {SURVEYDESCRIPTION}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SURVEYFORMAT' => array('codeValue'=>'value for {SURVEYFORMAT}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SURVEYLANGAGE' => array('codeValue'=>'value for {SURVEYLANGAGE}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SURVEYLISTHEADING' => array('codeValue'=>'value for {SURVEYLISTHEADING}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SURVEYLIST' => array('codeValue'=>'value for {SURVEYLIST}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SURVEYNAME' => array('codeValue'=>'value for {SURVEYNAME}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'SURVEYURL' => array('codeValue'=>'value for {SURVEYURL}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'TEMPLATECSS' => array('codeValue'=>'value for {TEMPLATECSS}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'TEMPLATEURL' => array('codeValue'=>'value for {TEMPLATEURL}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'TEXT' => array('codeValue'=>'value for {TEXT}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'THEREAREXQUESTIONS' => array('codeValue'=>'value for {THEREAREXQUESTIONS}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'TIME' => array('codeValue'=>'value for {TIME}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'TOKEN:EMAIL' => array('codeValue'=>'value for {TOKEN:EMAIL}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'TOKEN:FIRSTNAME' => array('codeValue'=>'value for {TOKEN:FIRSTNAME}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'TOKEN:LASTNAME' => array('codeValue'=>'value for {TOKEN:LASTNAME}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'TOKENCOUNT' => array('codeValue'=>'value for {TOKENCOUNT}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'TOKEN_COUNTER' => array('codeValue'=>'value for {TOKEN_COUNTER}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'TOKEN' => array('codeValue'=>'value for {TOKEN}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'URL' => array('codeValue'=>'value for {URL}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'WELCOME' => array('codeValue'=>'value for {WELCOME}', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
// also include SGQA values and read-only variable attributes
'12X34X56'  => array('codeValue'=>5, 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'12X3X5lab1_ber'    => array('codeValue'=>10, 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'q5pointChoice.code'    => array('codeValue'=>5, 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'q5pointChoice.value'   => array('codeValue'=> 'Father', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'qArrayNumbers.ls1.min.code'    => array('codeValue'=> 7, 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'qArrayNumbers.ls1.min.value' => array('codeValue'=> 'I love LimeSurvey', 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
'12X3X5lab1_ber#2'  => array('codeValue'=> 15, 'jsName'=>'', 'readWrite'=>'N', 'isOnCurrentPage'=>'N'),
        );

        // Syntax for $tests is
        // expectedResult~expression
        // if the expected result is an error, use NULL for the expected result
        $tests  = <<<EOD
6~max(five,(one + (two * four)- three))
6~max((one + (two * four)- three))
212~5 + max(1,(2+3),(4 + (5 + 6)),((7 + 8) + 9),((10 + 11), 12),(13 + (14 * 15) - 16))
29~five + max(one, (two + three), (four + (five + six)),((seven + eight) + nine),((ten + eleven), twelve),(one + (two * three) - four))
1024~max(one,(two*three),pow(four,five),six)
1~(one * (two + (three - four) + five) / six)
2~max(one,two)
5~max(one,two,three,four,five)
1~min(five,four,one,two,three)
0~(a=rand())-a
3~floor(pi())
2.4~(one  * two) + (three * four) / (five * six)
1~sin(0.5 * pi())
50~12X34X56 * 12X3X5lab1_ber
3~a=three
3~c=a
12~c*=four
15~c+=a
5~c/=a
-1~c-=six
27~pow(3,3)
24~one * two * three * four
-4~five - four - three - two
0~two * three - two - two - two
4~two * three - two
1~pi() == pi() * 2 - pi()
1~sin(pi()/2)
1~sin(pi()/2) == sin(.5 * pi())
105~5 + 1, 7 * 15
7~7
15~10 + 5
24~12 * 2
10~13 - 3
3.5~14 / 4
5~3 + 1 * 2
1~one
there~hi
6.25~one * two - three / four + five
1~one + hi
1~two > one
1~two gt one
1~three >= two
1~three ge  two
0~four < three
0~four lt three
0~four <= three
0~four le three
0~four == three
0~four eq three
1~four != three
0~four ne four
0~one * hi
5~abs(-five)
0~acos(cos(pi()))-pi()
0~floor(asin(sin(pi())))
10~ceil(9.1)
9~floor(9.9)
15~sum(one,two,three,four,five)
5~intval(5.7)
1~is_float(pi())
0~is_float(5)
1~is_numeric(five)
0~is_numeric(hi)
1~is_string(hi)
0~one && 0
0~two and 0
1~five && 6
1~seven && eight
1~one or 0
1~one || 0
1~(one and 0) || (two and three)
NULL~hi(there);
NULL~(one * two + (three - four)
NULL~(one * two + (three - four)))
NULL~++a
NULL~--b
value for {INSERTANS:123X45X67}~INSERTANS:123X45X67
value for {QID}~QID
"Can strings contain embedded \"quoted passages\" (and parentheses + other characters?)?"~ASSESSMENT_HEADING
"can single quoted strings" . 'contain nested 'quoted sections'?~QUESTIONHELP
Can strings have embedded <tags> like <html>, or even unbalanced "quotes or entities without terminal semicolons like &amp and  &lt?~QUESTION_HELP
value for {TOKEN:FIRSTNAME}~TOKEN:FIRSTNAME
value for {THEREAREXQUESTIONS}~THEREAREXQUESTIONS
5~q5pointChoice.code
Father~q5pointChoice.value
7~qArrayNumbers.ls1.min.code
I love LimeSurvey~qArrayNumbers.ls1.min.value
15~12X3X5lab1_ber#2
NULL~*
NULL~three +
NULL~four * / seven
NULL~(five - three
NULL~five + three)
NULL~seven + = four
NULL~if(seven,three,four))
NULL~if(seven)
NULL~if(seven,three)
NULL~if(seven,three,four,five)
NULL~if(seven,three,)
NULL~>
NULL~five > > three
NULL~seven > = four
NULL~seven >=
NULL~three &&
NULL~three ||
NULL~three +
NULL~three >=
NULL~three +=
NULL~three !
0~!three
NULL~three *
NULL~five ! three
8~five + + three
2~five + - three
NULL~(5 + 7) = 8
NULL~&& four
NULL~min(
NULL~max three, four, five)
NULL~three four
NULL~max(three,four,five) six
NULL~WELCOME='Good morning'
NULL~TOKEN:FIRSTNAME='Tom'
NULL~NUMBEROFQUESTIONS+=3
NULL~NUMBEROFQUESTIONS*=4
NULL~NUMBEROFQUESTIONS/=5
NULL~NUMBEROFQUESTIONS-=6
NULL~'Tom'='tired'
NULL~max()
1|2|3|4|5~implode('|',one,two,three,four,five)
0, 1, 3, 5~list(0,one,'',three,'',five)
5~strlen(hi)
I love LimeSurvey~str_replace('like','love','I like LimeSurvey')
2~strpos('I like LimeSurvey','like')
<span id="d" style="border-style: solid; border-width: 2px; border-color: green">Hi there!</span>~d='<span id="d" style="border-style: solid; border-width: 2px; border-color: green">Hi there!</span>'
Hi there!~c=strip_tags(d)
Hi there!~c
+,-,*,/,!,,,and,&&,or,||,gt,>,lt,<,ge,>=,le,<=,eq,==,ne,!=~implode(',','+','-','*','/','!',',','and','&&','or','||','gt','>','lt','<','ge','>=','le','<=','eq','==','ne','!=')
HI THERE!~strtoupper(c)
hi there!~strtolower(c)
1~three == three
1~three == 3
1~c == 'Hi there!'
1~c == "Hi there!"
11~eleven
144~twelve * twelve
4~if(5 > 7,2,4)
there~if((one > two),'hi','there')
64~if((one < two),pow(2,6),pow(6,2))
1, 2, 3, 4, 5~list(one,two,three,min(four,five,six),max(three,four,five))
11, 12~list(eleven,twelve)
1~is_empty(0)
1~is_empty('')
0~is_empty(1)
1~is_empty(a==b)
0~if('',1,0)
1~if(' ',1,0)
0~!is_empty(a==b)
1~!is_empty(1)
1~is_bool(false)
0~is_bool(1)
&quot;Can strings contain embedded \&quot;quoted passages\&quot; (and parentheses + other characters?)?&quot;~a=htmlspecialchars(ASSESSMENT_HEADING)
&quot;can single quoted strings&quot; . &#039;contain nested &#039;quoted sections&#039;?~b=htmlspecialchars(QUESTIONHELP)
Can strings have embedded &lt;tags&gt; like &lt;html&gt;, or even unbalanced &quot;quotes or entities without terminal semicolons like &amp;amp and  &amp;lt?~c=htmlspecialchars(QUESTION_HELP)
1~c==htmlspecialchars(htmlspecialchars_decode(c))
1~b==htmlspecialchars(htmlspecialchars_decode(b))
1~a==htmlspecialchars(htmlspecialchars_decode(a))
&quot;Can strings contain embedded \\&quot;quoted passages\\&quot; (and parentheses + other characters?)?&quot;~addslashes(a)
&quot;can single quoted strings&quot; . &#039;contain nested &#039;quoted sections&#039;?~addslashes(b)
Can strings have embedded &lt;tags&gt; like &lt;html&gt;, or even unbalanced &quot;quotes or entities without terminal semicolons like &amp;amp and  &amp;lt?~addslashes(c)
"Can strings contain embedded \"quoted passages\" (and parentheses + other characters?)?"~html_entity_decode(a)
"can single quoted strings" . &#039;contain nested &#039;quoted sections&#039;?~html_entity_decode(b)
Can strings have embedded <tags> like <html>, or even unbalanced "quotes or entities without terminal semicolons like &amp and  &lt?~html_entity_decode(c)
&quot;Can strings contain embedded \&quot;quoted passages\&quot; (and parentheses + other characters?)?&quot;~htmlentities(a)
&quot;can single quoted strings&quot; . &#039;contain nested &#039;quoted sections&#039;?~htmlentities(b)
Can strings have embedded &lt;tags&gt; like &lt;html&gt;, or even unbalanced &quot;quotes or entities without terminal semicolons like &amp;amp and &amp;lt?~htmlentities(c)
"Can strings contain embedded \"quoted passages\" (and parentheses + other characters?)?"~htmlspecialchars_decode(a)
"can single quoted strings" . 'contain nested 'quoted sections'?~htmlspecialchars_decode(b)
Can strings have embedded like , or even unbalanced "quotes or entities without terminal semicolons like & and <?~htmlspecialchars_decode(c)
"Can strings contain embedded \"quoted passages\" (and parentheses + other characters?)?"~htmlspecialchars(a)
"can single quoted strings" . 'contain nested 'quoted sections'?~htmlspecialchars(b)
Can strings have embedded <tags> like <html>, or even unbalanced "quotes or entities without terminal semicolons like &amp and &lt?~htmlspecialchars(c)
I was trimmed   ~ltrim('     I was trimmed   ')
     I was trimmed~rtrim('     I was trimmed   ')
I was trimmed~trim('     I was trimmed   ')
1,234,567~number_format(1234567)
Hi There You~ucwords('hi there you')
1~checkdate(1,29,1967)
0~checkdate(2,29,1967)
1144191723~mktime(1,2,3,4,5,6)
April 5, 2006, 1:02 am~date('F j, Y, g:i a',mktime(1,2,3,4,5,6))
NULL~time()
NULL~date('F j, Y, g:i a',time())
EOD;

        $em = new ExpressionManager();
        $em->RegisterVarnamesUsingMerge($vars);

        if (isset($exprmgr_extraVars) && is_array($exprmgr_extraVars) and count($exprmgr_extraVars) > 0)
        {
            $em->RegisterVarnamesUsingMerge($exprmgr_extraVars);
        }
        if (isset($exprmgr_extraTests) && is_string($exprmgr_extraTests))
        {
            $tests .= "\n" . $exprmgr_extraTests;
        }

        // Find all variables used on this page
        $allJsVarnamesUsed = array();
        foreach(explode("\n",$tests) as $test)
        {
            $values = explode("~",$test);
            $expectedResult = array_shift($values);
            $expr = implode("~",$values);
            $status = $em->Evaluate($expr,false);   // test the equation without actually running it
            if ($status)
            {
                $allJsVarnamesUsed = array_merge($allJsVarnamesUsed,$em->GetJsVarsUsed());
            }
        }
        $allJsVarnamesUsed = array_unique($allJsVarnamesUsed);
        asort($allJsVarnamesUsed);
        print "All Javascript Variables Used<br/>";
        print '<table border="1"><tr><th>#</th><th>JsVarname</th><th>Value</th><th>Relevance</th></tr>';
        $i=0;
        $LEMvarNameAttr=array();
        $LEMalias2varName=array();
        foreach ($allJsVarnamesUsed as $jsVarName)
        {
            ++$i;
            print "<tr><td>" .  $i . "</td><td>" . $jsVarName;
            foreach($em->amVars as $k => $v) {
                if ($v['jsName'] == $jsVarName)
                {
                    $value = $v['codeValue'];
                }
            }
            print "</td><td>" . $value . "</td><td><input type='text' id='relevance" . $i . "' value='1' onchange='recompute()'/>\n";
            print "<input type='hidden' name='" . $jsVarName . "' id='" . $jsVarName . "' value='" . $value . "'/>\n";
            print "</td></tr>\n";
            $LEMalias2varName[] = "'" . substr($jsVarName,5) . "':{'jsName':'" . $jsVarName . "'}";
            $LEMalias2varName[] = "'" . $jsVarName . "':{'jsName':'" . $jsVarName . "'}";
            $LEMvarNameAttr[] = "'" . $jsVarName .  "': {"
                . "'jsName':'" . $jsVarName
                . "','code':'" . htmlspecialchars(preg_replace("/[[:space:]]/",' ',$value),ENT_QUOTES)
                . "','question':'"
                . "','qid':'" . $i . "'}";
        }
        print "</table>\n";

        print "<script type='text/javascript'>\n";
        print "<!--\n";
        print "var LEMalias2varName= {". implode(",\n", $LEMalias2varName) ."};\n";
        print "var LEMvarNameAttr= {" . implode(",\n", $LEMvarNameAttr) . "};\n";
        print "//-->\n</script>\n";

        print '<table border="1"><tr><th>Expression</th><th>Result</th><th>Expected</th><th>JavaScript Test</th><th>VarNames</th><th>JavaScript Eqn</th></tr>';
        $i=0;
        $javaScript = array();
        foreach(explode("\n",$tests)as $test)
        {
            ++$i;
            $values = explode("~",$test);
            $expectedResult = array_shift($values);
            $expr = implode("~",$values);
            $resultStatus = 'ok';
            $status = $em->Evaluate($expr);
            $result = $em->GetResult();
            $valToShow = $result;   // htmlspecialchars($result,ENT_QUOTES,'UTF-8',false);
            $expectedToShow = $expectedResult; // htmlspecialchars($expectedResult,ENT_QUOTES,'UTF-8',false);
            print "<tr>";
            print "<td>" . $em->GetPrettyPrintString() . "</td>\n";
            if (is_null($result)) {
                $valToShow = "NULL";
            }
            print '<td>' . $valToShow . "</td>\n";
            if ($valToShow != $expectedToShow)
            {
                $resultStatus = 'error';
            }
            print "<td class='" . $resultStatus . "'>" . $expectedToShow . "</td>\n";
            $javaScript[] = $em->GetJavascriptTestforExpression($expectedToShow, $i);
            print "<td id='test_" . $i . "'>&nbsp;</td>\n";
            $varsUsed = $em->GetVarsUsed();
            if (is_array($varsUsed) and count($varsUsed) > 0) {
                $varDesc = array();
                foreach ($varsUsed as $v) {
                    $varInfo = $em->GetVarInfo($v);
                    $varDesc[] = $v;
                }
                print '<td>' . implode(',<br/>', $varDesc) . "</td>\n";
            }
            else {
                print "<td>&nbsp;</td>\n";
            }
            $jsEqn = $em->GetJavaScriptEquivalentOfExpression();
            if ($jsEqn == '')
            {
                print "<td>&nbsp;</td>\n";
            }
            else
            {
                print '<td>' . $jsEqn . "</td>\n";
            }
            print '</tr>';
        }
        print '</table>';
        print "<script type='text/javascript'>\n";
        print "<!--\n";
        print "function recompute() {\n";
        print implode("\n",$javaScript);
        print "}\n//-->\n</script>\n";
    }
}

/**
 * Used by usort() to order Error tokens by their position within the string
 * @param <type> $a
 * @param <type> $b
 * @return <type>
 */
function cmpErrorTokens($a, $b)
{
    if (is_null($a[1])) {
        if (is_null($b[1])) {
            return 0;
        }
        return 1;
    }
    if (is_null($b[1])) {
        return -1;
    }
    if ($a[1][1] == $b[1][1]) {
        return 0;
    }
    return ($a[1][1] < $b[1][1]) ? -1 : 1;
}

/**
 * If $test is true, return $ok, else return $error
 * @param <type> $test
 * @param <type> $ok
 * @param <type> $error
 * @return <type>
 */
function exprmgr_if($test,$ok,$error)
{
    if ($test)
    {
        return $ok;
    }
    else
    {
        return $error;
    }
}

/**
 * Join together $args[0-N] with ', '
 * @param <type> $args
 * @return <type>
 */
function exprmgr_list($args)
{
    $result="";
    $j=1;    // keep track of how many non-null values seen
    foreach ($args as $arg)
    {
        if ($arg != '') {
            if ($j > 1) {
                $result .= ', ' . $arg;
            }
            else {
                $result .= $arg;
            }
            ++$j;
        }
    }
    return $result;
}

/**
 * Join together $args[1-N] with $arg[0]
 * @param <type> $args
 * @return <type>
 */
function exprmgr_implode($args)
{
    if (count($args) <= 1)
    {
        return "";
    }
    $joiner = array_shift($args);
    return implode($joiner,$args);
}

function exprmgr_empty($arg)
{
    return empty($arg);
}

/**
 * Compute the Sample Standard Deviation of a set of numbers ($args[0-N])
 * @param <type> $args
 * @return <type>
 */
function exprmgr_stddev($args)
{
    $vals = array();
    foreach ($args as $arg)
    {
        if (is_numeric($arg)) {
            $vals[] = $arg;
        }
    }
    $count = count($vals);
    if ($count <= 1) {
        return 0;   // what should default value be?
    }
    $sum = 0;
    foreach ($vals as $val) {
        $sum += $val;
    }
    $mean = $sum / $count;

    $sumsqmeans = 0;
    foreach ($vals as $val)
    {
        $sumsqmeans += ($val - $mean) * ($val - $mean);
    }
    $stddev = sqrt($sumsqmeans / ($count-1));
    return $stddev;
}

/**
 * Javascript equivalent does not cope well with ENT_QUOTES and related PHP constants, so set default to ENT_QUOTES
 * @param <type> $string
 * @return <type>
 */
function expr_mgr_htmlspecialchars($string)
{
    return htmlspecialchars($string,ENT_QUOTES);
}

/**
 * Javascript equivalent does not cope well with ENT_QUOTES and related PHP constants, so set default to ENT_QUOTES
 * @param <type> $string
 * @return <type>
 */
function expr_mgr_htmlspecialchars_decode($string)
{
    return htmlspecialchars_decode($string,ENT_QUOTES);
}

?>