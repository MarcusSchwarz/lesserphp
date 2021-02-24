<?php

namespace LesserPhp;

use LesserPhp\Exception\GeneralException;

/**
 * lesserphp
 * https://www.maswaba.de/lesserphp
 *
 * LESS CSS compiler, adapted from http://lesscss.org
 *
 * Copyright 2013, Leaf Corcoran <leafot@gmail.com>
 * Copyright 2016, Marcus Schwarz <github@maswaba.de>
 * Licensed under MIT or GPLv3, see LICENSE
 * @package LesserPhp
 * // responsible for taking a string of LESS code and converting it into a
 * // syntax tree
 */
class Parser
{
    protected static $nextBlockId = 0; // used to uniquely identify blocks

    protected static $precedence = [
        '=<' => 0,
        '>=' => 0,
        '=' => 0,
        '<' => 0,
        '>' => 0,

        '+' => 1,
        '-' => 1,
        '*' => 2,
        '/' => 2,
        '%' => 2,
    ];

    protected static $whitePattern;
    protected static $commentMulti;

    protected static $commentSingle = '//';
    protected static $commentMultiLeft = '/*';
    protected static $commentMultiRight = '*/';

    // regex string to match any of the operators
    protected static $operatorString;

    // these properties will supress division unless it's inside parenthases
    protected static $supressDivisionProps =
        ['/border-radius$/i', '/^font$/i'];

    private $blockDirectives = [
        'font-face',
        'keyframes',
        'page',
        '-moz-document',
        'viewport',
        '-moz-viewport',
        '-o-viewport',
        '-ms-viewport',
    ];
    private $lineDirectives = ['charset'];

    /**
     * if we are in parens we can be more liberal with whitespace around
     * operators because it must evaluate to a single value and thus is less
     * ambiguous.
     *
     * Consider:
     *     property1: 10 -5; // is two numbers, 10 and -5
     *     property2: (10 -5); // should evaluate to 5
     */
    protected $inParens = false;

    // caches preg escaped literals
    protected static $literalCache = [];
    /** @var int */
    public $count;
    /** @var int */
    private $line;
    /** @var array */
    private $seenComments;
    /** @var string */
    public $buffer;

    /** @var Block|null $env Block Stack */
    private $env;
    /** @var bool */
    private $inExp;
    /** @var string */
    private $currentProperty;

    /**
     * @var bool
     */
    private $writeComments = false;

    /**
     * Parser constructor.
     *
     * @param \LesserPhp\Compiler $lessc
     * @param string              $sourceName
     */
    public function __construct(Compiler $lessc, $sourceName = null)
    {
        $this->eatWhiteDefault = true;
        // reference to less needed for vPrefix, mPrefix, and parentSelector
        $this->lessc = $lessc;

        $this->sourceName = $sourceName; // name used for error messages

        if (!self::$operatorString) {
            self::$operatorString =
                '(' . implode('|', array_map([Compiler::class, 'pregQuote'], array_keys(self::$precedence))) . ')';

            $commentSingle = Compiler::pregQuote(self::$commentSingle);
            $commentMultiLeft = Compiler::pregQuote(self::$commentMultiLeft);
            $commentMultiRight = Compiler::pregQuote(self::$commentMultiRight);

            self::$commentMulti = $commentMultiLeft . '.*?' . $commentMultiRight;
            self::$whitePattern = '/' . $commentSingle . '[^\n]*\s*|(' . self::$commentMulti . ')\s*|\s+/Ais';
        }
    }

    /**
     * @param string $buffer
     *
     * @return Block
     * @throws \LesserPhp\Exception\GeneralException
     */
    public function parse($buffer)
    {
        $this->count = 0;
        $this->line = 1;

        $this->clearBlockStack();
        $this->buffer = $this->writeComments ? $buffer : $this->removeComments($buffer);
        $this->pushSpecialBlock('root');
        $this->eatWhiteDefault = true;
        $this->seenComments = [];

        $this->whitespace();

        // parse the entire file
        while (false !== $this->parseChunk()) {
            ;
        }

        if ($this->count !== strlen($this->buffer)) {
            //            var_dump($this->count);
//            var_dump($this->buffer);
            $this->throwError();
        }

        // TODO report where the block was opened
        if (!property_exists($this->env, 'parent') || $this->env->parent !== null) {
            throw new GeneralException('parse error: unclosed block');
        }

        return $this->env;
    }

    /**
     * Parse a single chunk off the head of the buffer and append it to the
     * current parse environment.
     * Returns false when the buffer is empty, or when there is an error.
     *
     * This function is called repeatedly until the entire document is
     * parsed.
     *
     * This parser is most similar to a recursive descent parser. Single
     * functions represent discrete grammatical rules for the language, and
     * they are able to capture the text that represents those rules.
     *
     * Consider the function \LesserPhp\Compiler::keyword(). (all parse functions are
     * structured the same)
     *
     * The function takes a single reference argument. When calling the
     * function it will attempt to match a keyword on the head of the buffer.
     * If it is successful, it will place the keyword in the referenced
     * argument, advance the position in the buffer, and return true. If it
     * fails then it won't advance the buffer and it will return false.
     *
     * All of these parse functions are powered by \LesserPhp\Compiler::match(), which behaves
     * the same way, but takes a literal regular expression. Sometimes it is
     * more convenient to use match instead of creating a new function.
     *
     * Because of the format of the functions, to parse an entire string of
     * grammatical rules, you can chain them together using &&.
     *
     * But, if some of the rules in the chain succeed before one fails, then
     * the buffer position will be left at an invalid state. In order to
     * avoid this, \LesserPhp\Compiler::seek() is used to remember and set buffer positions.
     *
     * Before parsing a chain, use $s = $this->seek() to remember the current
     * position into $s. Then if a chain fails, use $this->seek($s) to
     * go back where we started.
     * @throws \LesserPhp\Exception\GeneralException
     */
    protected function parseChunk()
    {
        if (empty($this->buffer)) {
            return false;
        }
        $s = $this->seek();

        if ($this->whitespace()) {
            return true;
        }

        // setting a property
        if ($this->keyword($key) && $this->assign() && $this->propertyValue($value, $key) && $this->end()) {
            $this->append(['assign', $key, $value], $s);

            return true;
        } else {
            $this->seek($s);
        }

        // look for special css blocks
        if ($this->literal('@', false)) {
            $this->count--;

            // media
            if ($this->literal('@media')) {
                return $this->handleLiteralMedia($s);
            }

            if ($this->literal('@', false) && $this->keyword($directiveName)) {
                if ($this->isDirective($directiveName, $this->blockDirectives)) {
                    if ($this->handleDirectiveBlock($directiveName) === true) {
                        return true;
                    }
                } elseif ($this->isDirective($directiveName, $this->lineDirectives)) {
                    if ($this->handleDirectiveLine($directiveName) === true) {
                        return true;
                    }
                } elseif ($this->literal(':', true)) {
                    if ($this->handleRulesetDefinition($directiveName) === true) {
                        return true;
                    }
                }
            }

            $this->seek($s);
        }

        if ($this->literal('&', false)) {
            $this->count--;
            if ($this->literal('&:extend')) {
                // hierauf folgt was in runden klammern, und zwar das element, das erweitert werden soll
                // heißt also, das was in klammern steht wird um die aktuellen klassen erweitert
                /*
Aus

nav ul {
  &:extend(.inline);
  background: blue;
}
.inline {
  color: red;
}


Wird:

nav ul {
  background: blue;
}
.inline,
nav ul {
  color: red;
}

                 */
//                echo "Here we go";
            }
        }


        // setting a variable
        if ($this->variable($var) && $this->assign() &&
            $this->propertyValue($value) && $this->end()
        ) {
            $this->append(['assign', $var, $value], $s);

            return true;
        } else {
            $this->seek($s);
        }

        if ($this->import($importValue)) {
            $this->append($importValue, $s);

            return true;
        }

        // opening parametric mixin
        if ($this->tag($tag, true) && $this->argumentDef($args, $isVararg) &&
            ($this->guards($guards) || true) &&
            $this->literal('{')
        ) {
            $block = $this->pushBlock($this->fixTags([$tag]));
            $block->args = $args;
            $block->isVararg = $isVararg;
            if (!empty($guards)) {
                $block->guards = $guards;
            }

            return true;
        } else {
            $this->seek($s);
        }

        // opening a simple block
        if ($this->tags($tags) && $this->literal('{', false)) {
            $tags = $this->fixTags($tags);
            $this->pushBlock($tags);

            return true;
        } else {
            $this->seek($s);
        }

        // closing a block
        if ($this->literal('}', false)) {
            try {
                $block = $this->pop();
            } catch (\Exception $e) {
                $this->seek($s);
                $this->throwError($e->getMessage());

                return false; // will never be reached, but silences the ide for now
            }

            $hidden = false;
            if ($block->type === null) {
                $hidden = true;
                if (!isset($block->args)) {
                    foreach ($block->tags as $tag) {
                        if (!is_string($tag) || $tag[0] !== $this->lessc->getMPrefix()) {
                            $hidden = false;
                            break;
                        }
                    }
                }

                foreach ($block->tags as $tag) {
                    if (is_string($tag)) {
                        $this->env->children[$tag][] = $block;
                    }
                }
            }

            if (!$hidden) {
                $this->append(['block', $block], $s);
            }

            // this is done here so comments aren't bundled into he block that
            // was just closed
            $this->whitespace();

            return true;
        }

        // mixin
        if ($this->mixinTags($tags) &&
            ($this->argumentDef($argv, $isVararg) || true) &&
            ($this->keyword($suffix) || true) && $this->end()
        ) {
            $tags = $this->fixTags($tags);
            $this->append(['mixin', $tags, $argv, $suffix], $s);

            return true;
        } else {
            $this->seek($s);
        }

        // spare ;
        if ($this->literal(';')) {
            return true;
        }

        return false; // got nothing, throw error
    }

    /**
     * @param string $directiveName
     * @param array  $directives
     *
     * @return bool
     */
    protected function isDirective($directiveName, array $directives)
    {
        // TODO: cache pattern in parser
        $pattern = implode('|', array_map([Compiler::class, 'pregQuote'], $directives));
        $pattern = '/^(-[a-z-]+-)?(' . $pattern . ')$/i';

        return (preg_match($pattern, $directiveName) === 1);
    }

    /**
     * @param array $tags
     *
     * @return array
     */
    protected function fixTags(array $tags)
    {
        // move @ tags out of variable namespace
        foreach ($tags as &$tag) {
            if ($tag[0] === $this->lessc->getVPrefix()) {
                $tag[0] = $this->lessc->getMPrefix();
            }
        }

        return $tags;
    }

    /**
     * a list of expressions
     *
     * @param $exps
     *
     * @return bool
     */
    protected function expressionList(&$exps)
    {
        $values = [];

        while ($this->expression($exp)) {
            $values[] = $exp;
        }

        if (count($values) === 0) {
            return false;
        }

        $exps = Compiler::compressList($values, ' ');

        return true;
    }

    /**
     * Attempt to consume an expression.
     * @link http://en.wikipedia.org/wiki/Operator-precedence_parser#Pseudo-code
     *
     * @param $out
     *
     * @return bool
     */
    protected function expression(&$out)
    {
        if ($this->value($lhs)) {
            $out = $this->expHelper($lhs, 0);

            // look for / shorthand
            if (!empty($this->env->supressedDivision)) {
                unset($this->env->supressedDivision);
                $s = $this->seek();
                if ($this->literal('/') && $this->value($rhs)) {
                    $out = [
                        'list',
                        '',
                        [$out, ['keyword', '/'], $rhs],
                    ];
                } else {
                    $this->seek($s);
                }
            }

            return true;
        }

        return false;
    }

    /**
     * recursively parse infix equation with $lhs at precedence $minP
     *
     * @param     $lhs
     * @param int $minP
     *
     * @return array
     */
    protected function expHelper($lhs, $minP)
    {
        $this->inExp = true;
        $ss = $this->seek();

        while (true) {
            $whiteBefore = isset($this->buffer[$this->count - 1]) && ctype_space($this->buffer[$this->count - 1]);

            // If there is whitespace before the operator, then we require
            // whitespace after the operator for it to be an expression
            $needWhite = $whiteBefore && !$this->inParens;

            if ($this->match(self::$operatorString . ($needWhite ? '\s' : ''), $m) &&
                self::$precedence[$m[1]] >= $minP
            ) {
                if (!$this->inParens &&
                    isset($this->env->currentProperty) &&
                    $m[1] === '/' &&
                    empty($this->env->supressedDivision)
                ) {
                    foreach (self::$supressDivisionProps as $pattern) {
                        if (preg_match($pattern, $this->env->currentProperty)) {
                            $this->env->supressedDivision = true;
                            break 2;
                        }
                    }
                }

                $whiteAfter = isset($this->buffer[$this->count - 1]) && ctype_space($this->buffer[$this->count - 1]);

                if (!$this->value($rhs)) {
                    break;
                }

                // peek for next operator to see what to do with rhs
                if ($this->peek(self::$operatorString, $next) &&
                    self::$precedence[$next[1]] > self::$precedence[$m[1]]
                ) {
                    $rhs = $this->expHelper($rhs, self::$precedence[$next[1]]);
                }

                $lhs = ['expression', $m[1], $lhs, $rhs, $whiteBefore, $whiteAfter];
                $ss = $this->seek();

                continue;
            }

            break;
        }

        $this->seek($ss);

        return $lhs;
    }

    /**
     * consume a list of values for a property
     *
     * @param        $value
     * @param string $keyName
     *
     * @return bool
     */
    public function propertyValue(&$value, $keyName = null)
    {
        $values = [];

        if ($keyName !== null) {
            $this->env->currentProperty = $keyName;
        }

        $s = null;
        while ($this->expressionList($v)) {
            $values[] = $v;
            $s = $this->seek();
            if (!$this->literal(',')) {
                break;
            }
        }

        if ($s) {
            $this->seek($s);
        }

        if ($keyName !== null) {
            unset($this->env->currentProperty);
        }

        if (count($values) === 0) {
            return false;
        }

        $value = Compiler::compressList($values, ', ');

        return true;
    }

    /**
     * @param $out
     *
     * @return bool
     */
    protected function parenValue(&$out)
    {
        $s = $this->seek();

        // speed shortcut
        if (isset($this->buffer[$this->count]) && $this->buffer[$this->count] !== '(') {
            return false;
        }

        $inParens = $this->inParens;
        if ($this->literal('(') &&
            ($this->inParens = true) && $this->expression($exp) &&
            $this->literal(')')
        ) {
            $out = $exp;
            $this->inParens = $inParens;

            return true;
        } else {
            $this->inParens = $inParens;
            $this->seek($s);
        }

        return false;
    }

    /**
     * a single value
     *
     * @param array $value
     *
     * @return bool
     */
    protected function value(&$value)
    {
        $s = $this->seek();

        // speed shortcut
        if (isset($this->buffer[$this->count]) && $this->buffer[$this->count] === '-') {
            // negation
            if ($this->literal('-', false) &&
                (($this->variable($inner) && $inner = ['variable', $inner]) ||
                    $this->unit($inner) ||
                    $this->parenValue($inner))
            ) {
                $value = ['unary', '-', $inner];

                return true;
            } else {
                $this->seek($s);
            }
        }

        if ($this->parenValue($value)) {
            return true;
        }
        if ($this->unit($value)) {
            return true;
        }
        if ($this->color($value)) {
            return true;
        }
        if ($this->func($value)) {
            return true;
        }
        if ($this->stringValue($value)) {
            return true;
        }

        if ($this->keyword($word)) {
            $value = ['keyword', $word];

            return true;
        }

        // try a variable
        if ($this->variable($var)) {
            $value = ['variable', $var];

            return true;
        }

        // unquote string (should this work on any type?
        if ($this->literal('~') && $this->stringValue($str)) {
            $value = ['escape', $str];

            return true;
        } else {
            $this->seek($s);
        }

        // css hack: \0
        if ($this->literal('\\') && $this->match('([0-9]+)', $m)) {
            $value = ['keyword', '\\' . $m[1]];

            return true;
        } else {
            $this->seek($s);
        }

        return false;
    }

    /**
     * an import statement
     *
     * @param array $out
     *
     * @return bool|null
     */
    protected function import(&$out)
    {
        if (!$this->literal('@import')) {
            return false;
        }

        // @import "something.css" media;
        // @import url("something.css") media;
        // @import url(something.css) media;

        if ($this->propertyValue($value)) {
            $out = ['import', $value];

            return true;
        }

        return false;
    }

    /**
     * @param $out
     *
     * @return bool
     */
    protected function mediaQueryList(&$out)
    {
        if ($this->genericList($list, 'mediaQuery', ',', false)) {
            $out = $list[2];

            return true;
        }

        return false;
    }

    /**
     * @param $out
     *
     * @return bool
     */
    protected function mediaQuery(&$out)
    {
        $s = $this->seek();

        $expressions = null;
        $parts = [];

        if (($this->literal('only') && ($only = true) || $this->literal('not') && ($not = true) || true) &&
            $this->keyword($mediaType)
        ) {
            $prop = ['mediaType'];
            if (isset($only)) {
                $prop[] = 'only';
            }
            if (isset($not)) {
                $prop[] = 'not';
            }
            $prop[] = $mediaType;
            $parts[] = $prop;
        } else {
            $this->seek($s);
        }


        if (!empty($mediaType) && !$this->literal('and')) {
            // ~
        } else {
            $this->genericList($expressions, 'mediaExpression', 'and', false);
            if (is_array($expressions)) {
                $parts = array_merge($parts, $expressions[2]);
            }
        }

        if (count($parts) === 0) {
            $this->seek($s);

            return false;
        }

        $out = $parts;

        return true;
    }

    /**
     * @param $out
     *
     * @return bool
     */
    protected function mediaExpression(&$out)
    {
        $s = $this->seek();
        $value = null;
        if ($this->literal('(') &&
            $this->keyword($feature) &&
            ($this->literal(':') && $this->expression($value) || true) &&
            $this->literal(')')
        ) {
            $out = ['mediaExp', $feature];
            if ($value) {
                $out[] = $value;
            }

            return true;
        } elseif ($this->variable($variable)) {
            $out = ['variable', $variable];

            return true;
        }

        $this->seek($s);

        return false;
    }

    /**
     * an unbounded string stopped by $end
     *
     * @param string   $end
     * @param          $out
     * @param null     $nestingOpen
     * @param string[] $rejectStrs
     *
     * @return bool
     */
    protected function openString($end, &$out, $nestingOpen = null, $rejectStrs = null)
    {
        $oldWhite = $this->eatWhiteDefault;
        $this->eatWhiteDefault = false;

        $stop = ["'", '"', '@{', $end];
        $stop = array_map([Compiler::class, 'pregQuote'], $stop);
        // $stop[] = self::$commentMulti;

        if ($rejectStrs !== null) {
            $stop = array_merge($stop, $rejectStrs);
        }

        $patt = '(.*?)(' . implode('|', $stop) . ')';

        $nestingLevel = 0;

        $content = [];
        while ($this->match($patt, $m, false)) {
            if (!empty($m[1])) {
                $content[] = $m[1];
                if ($nestingOpen) {
                    $nestingLevel += substr_count($m[1], $nestingOpen);
                }
            }

            $tok = $m[2];

            $this->count -= strlen($tok);
            if ($tok == $end) {
                if ($nestingLevel === 0) {
                    break;
                } else {
                    $nestingLevel--;
                }
            }

            if (($tok === "'" || $tok === '"') && $this->stringValue($str)) {
                $content[] = $str;
                continue;
            }

            if ($tok === '@{' && $this->interpolation($inter)) {
                $content[] = $inter;
                continue;
            }

            if (!empty($rejectStrs) && in_array($tok, $rejectStrs)) {
                break;
            }

            $content[] = $tok;
            $this->count += strlen($tok);
        }

        $this->eatWhiteDefault = $oldWhite;

        if (count($content) === 0) {
            return false;
        }

        // trim the end
        if (is_string(end($content))) {
            $content[count($content) - 1] = rtrim(end($content));
        }

        $out = ['string', '', $content];

        return true;
    }

    /**
     * @param $out
     *
     * @return bool
     */
    protected function stringValue(&$out)
    {
        $s = $this->seek();
        if ($this->literal('"', false)) {
            $delim = '"';
        } elseif ($this->literal("'", false)) {
            $delim = "'";
        } else {
            return false;
        }

        $content = [];

        // look for either ending delim , escape, or string interpolation
        $patt = '([^\n]*?)(@\{|\\\\|' .
            Compiler::pregQuote($delim) . ')';

        $oldWhite = $this->eatWhiteDefault;
        $this->eatWhiteDefault = false;

        while ($this->match($patt, $m, false)) {
            $content[] = $m[1];
            if ($m[2] === '@{') {
                $this->count -= strlen($m[2]);
                if ($this->interpolation($inter)) {
                    $content[] = $inter;
                } else {
                    $this->count += strlen($m[2]);
                    $content[] = '@{'; // ignore it
                }
            } elseif ($m[2] === '\\') {
                $content[] = $m[2];
                if ($this->literal($delim, false)) {
                    $content[] = $delim;
                }
            } else {
                $this->count -= strlen($delim);
                break; // delim
            }
        }

        $this->eatWhiteDefault = $oldWhite;

        if ($this->literal($delim)) {
            $out = ['string', $delim, $content];

            return true;
        }

        $this->seek($s);

        return false;
    }

    /**
     * @param $out
     *
     * @return bool
     */
    protected function interpolation(&$out)
    {
        $oldWhite = $this->eatWhiteDefault;
        $this->eatWhiteDefault = true;

        $s = $this->seek();
        if ($this->literal('@{') &&
            $this->openString('}', $interp, null, ["'", '"', ';']) &&
            $this->literal('}', false)
        ) {
            $out = ['interpolate', $interp];
            $this->eatWhiteDefault = $oldWhite;
            if ($this->eatWhiteDefault) {
                $this->whitespace();
            }

            return true;
        }

        $this->eatWhiteDefault = $oldWhite;
        $this->seek($s);

        return false;
    }

    /**
     * @param $unit
     *
     * @return bool
     */
    protected function unit(&$unit)
    {
        // speed shortcut
        if (isset($this->buffer[$this->count])) {
            $char = $this->buffer[$this->count];
            if (!ctype_digit($char) && $char !== '.') {
                return false;
            }
        }

        if ($this->match('([0-9]+(?:\.[0-9]*)?|\.[0-9]+)([%a-zA-Z]+)?', $m)) {
            $unit = ['number', $m[1], empty($m[2]) ? '' : $m[2]];

            return true;
        }

        return false;
    }


    /**
     * a # color
     *
     * @param $out
     *
     * @return bool
     */
    protected function color(&$out)
    {
        if ($this->match('(#(?:[0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{3}))', $m)) {
            if (strlen($m[1]) > 7) {
                $out = ['string', '', [$m[1]]];
            } else {
                $out = ['raw_color', $m[1]];
            }

            return true;
        }

        return false;
    }

    /**
     * consume an argument definition list surrounded by ()
     * each argument is a variable name with optional value
     * or at the end a ... or a variable named followed by ...
     * arguments are separated by , unless a ; is in the list, then ; is the
     * delimiter.
     *
     * @param $args
     * @param $isVararg
     *
     * @return bool
     * @throws \LesserPhp\Exception\GeneralException
     */
    protected function argumentDef(&$args, &$isVararg)
    {
        $s = $this->seek();
        if (!$this->literal('(')) {
            return false;
        }

        $values = [];
        $delim = ',';
        $method = 'expressionList';
        $value = [];
        $rhs = null;

        $isVararg = false;
        while (true) {
            if ($this->literal('...')) {
                $isVararg = true;
                break;
            }

            if ($this->$method($value)) {
                if ($value[0] === 'variable') {
                    $arg = ['arg', $value[1]];
                    $ss = $this->seek();

                    if ($this->assign() && $this->$method($rhs)) {
                        $arg[] = $rhs;
                    } else {
                        $this->seek($ss);
                        if ($this->literal('...')) {
                            $arg[0] = 'rest';
                            $isVararg = true;
                        }
                    }

                    $values[] = $arg;
                    if ($isVararg) {
                        break;
                    }
                    continue;
                } else {
                    $values[] = ['lit', $value];
                }
            }


            if (!$this->literal($delim)) {
                if ($delim === ',' && $this->literal(';')) {
                    // found new delim, convert existing args
                    $delim = ';';
                    $method = 'propertyValue';
                    $newArg = null;

                    // transform arg list
                    if (isset($values[1])) { // 2 items
                        $newList = [];
                        foreach ($values as $i => $arg) {
                            switch ($arg[0]) {
                                case 'arg':
                                    if ($i) {
                                        throw new GeneralException('Cannot mix ; and , as delimiter types');
                                    }
                                    $newList[] = $arg[2];
                                    break;
                                case 'lit':
                                    $newList[] = $arg[1];
                                    break;
                                case 'rest':
                                    throw new GeneralException('Unexpected rest before semicolon');
                            }
                        }

                        $newList = ['list', ', ', $newList];

                        switch ($values[0][0]) {
                            case 'arg':
                                $newArg = ['arg', $values[0][1], $newList];
                                break;
                            case 'lit':
                                $newArg = ['lit', $newList];
                                break;
                        }
                    } elseif ($values) { // 1 item
                        $newArg = $values[0];
                    }

                    if ($newArg !== null) {
                        $values = [$newArg];
                    }
                } else {
                    break;
                }
            }
        }

        if (!$this->literal(')')) {
            $this->seek($s);

            return false;
        }

        $args = $values;

        return true;
    }

    /**
     * consume a list of tags
     * this accepts a hanging delimiter
     *
     * @param array  $tags
     * @param bool   $simple
     * @param string $delim
     *
     * @return bool
     */
    protected function tags(&$tags, $simple = false, $delim = ',')
    {
        $tags = [];
        while ($this->tag($tt, $simple)) {
            $tags[] = $tt;
            if (!$this->literal($delim)) {
                break;
            }
        }

        return count($tags) !== 0;
    }

    /**
     * list of tags of specifying mixin path
     * optionally separated by > (lazy, accepts extra >)
     *
     * @param array $tags
     *
     * @return bool
     */
    protected function mixinTags(&$tags)
    {
        $tags = [];
        while ($this->tag($tt, true)) {
            $tags[] = $tt;
            $this->literal('>');
        }

        return count($tags) !== 0;
    }

    /**
     * a bracketed value (contained within in a tag definition)
     *
     * @param array $parts
     * @param bool  $hasExpression
     *
     * @return bool
     */
    protected function tagBracket(&$parts, &$hasExpression)
    {
        // speed shortcut
        if (isset($this->buffer[$this->count]) && $this->buffer[$this->count] !== '[') {
            return false;
        }

        $s = $this->seek();

        $hasInterpolation = false;

        if ($this->literal('[', false)) {
            $attrParts = ['['];
            // keyword, string, operator
            while (true) {
                if ($this->literal(']', false)) {
                    $this->count--;
                    break; // get out early
                }

                if ($this->match('\s+', $m)) {
                    $attrParts[] = ' ';
                    continue;
                }
                if ($this->stringValue($str)) {
                    // escape parent selector, (yuck)
                    foreach ($str[2] as &$chunk) {
                        if (is_string($chunk)) {
                            $chunk = str_replace($this->lessc->getParentSelector(), '$&$', $chunk);
                        }
                    }

                    $attrParts[] = $str;
                    $hasInterpolation = true;
                    continue;
                }

                if ($this->keyword($word)) {
                    $attrParts[] = $word;
                    continue;
                }

                if ($this->interpolation($inter)) {
                    $attrParts[] = $inter;
                    $hasInterpolation = true;
                    continue;
                }

                // operator, handles attr namespace too
                if ($this->match('[|-~\$\*\^=]+', $m)) {
                    $attrParts[] = $m[0];
                    continue;
                }

                break;
            }

            if ($this->literal(']', false)) {
                $attrParts[] = ']';
                foreach ($attrParts as $part) {
                    $parts[] = $part;
                }
                $hasExpression = $hasExpression || $hasInterpolation;

                return true;
            }
            $this->seek($s);
        }

        $this->seek($s);

        return false;
    }

    /**
     * a space separated list of selectors
     *
     * @param      $tag
     * @param bool $simple
     *
     * @return bool
     */
    protected function tag(&$tag, $simple = false)
    {
        if ($simple) {
            $chars = '^@,:;{}\][>\(\) "\'';
        } else {
            $chars = '^@,;{}["\'';
        }

        $s = $this->seek();

        $hasExpression = false;
        $parts = [];
        while ($this->tagBracket($parts, $hasExpression)) {
            ;
        }

        $oldWhite = $this->eatWhiteDefault;
        $this->eatWhiteDefault = false;

        while (true) {
            if ($this->match('([' . $chars . '0-9][' . $chars . ']*)', $m)) {
                $parts[] = $m[1];
                if ($simple) {
                    break;
                }

                while ($this->tagBracket($parts, $hasExpression)) {
                    ;
                }
                continue;
            }

            if (isset($this->buffer[$this->count]) && $this->buffer[$this->count] === '@') {
                if ($this->interpolation($interp)) {
                    $hasExpression = true;
                    $interp[2] = true; // don't unescape
                    $parts[] = $interp;
                    continue;
                }

                if ($this->literal('@')) {
                    $parts[] = '@';
                    continue;
                }
            }

            if ($this->unit($unit)) { // for keyframes
                $parts[] = $unit[1];
                $parts[] = $unit[2];
                continue;
            }

            break;
        }

        $this->eatWhiteDefault = $oldWhite;
        if (!$parts) {
            $this->seek($s);

            return false;
        }

        if ($hasExpression) {
            $tag = ['exp', ['string', '', $parts]];
        } else {
            $tag = trim(implode($parts));
        }

        $this->whitespace();

        return true;
    }

    /**
     * a css function
     *
     * @param array $func
     *
     * @return bool
     */
    protected function func(&$func)
    {
        $s = $this->seek();

        if ($this->match('(%|[\w\-_][\w\-_:\.]+|[\w_])', $m) && $this->literal('(')) {
            $fname = $m[1];

            $sPreArgs = $this->seek();

            $args = [];
            while (true) {
                $ss = $this->seek();
                // this ugly nonsense is for ie filter properties
                if ($this->keyword($name) && $this->literal('=') && $this->expressionList($value)) {
                    $args[] = ['string', '', [$name, '=', $value]];
                } else {
                    $this->seek($ss);
                    if ($this->expressionList($value)) {
                        $args[] = $value;
                    }
                }

                if (!$this->literal(',')) {
                    break;
                }
            }
            $args = ['list', ',', $args];

            if ($this->literal(')')) {
                $func = ['function', $fname, $args];

                return true;
            } elseif ($fname === 'url') {
                // couldn't parse and in url? treat as string
                $this->seek($sPreArgs);
                if ($this->openString(')', $string) && $this->literal(')')) {
                    $func = ['function', $fname, $string];

                    return true;
                }
            }
        }

        $this->seek($s);

        return false;
    }

    /**
     * consume a less variable
     *
     * @param $name
     *
     * @return bool
     */
    protected function variable(&$name)
    {
        $s = $this->seek();
        if ($this->literal($this->lessc->getVPrefix(), false) &&
            ($this->variable($sub) || $this->keyword($name))
        ) {
            if (!empty($sub)) {
                $name = ['variable', $sub];
            } else {
                $name = $this->lessc->getVPrefix() . $name;
            }

            return true;
        }

        $name = null;
        $this->seek($s);

        return false;
    }

    /**
     * Consume an assignment operator
     * Can optionally take a name that will be set to the current property name
     *
     * @param string $name
     *
     * @return bool
     */
    protected function assign($name = null)
    {
        if ($name !== null) {
            $this->currentProperty = $name;
        }

        return $this->literal(':') || $this->literal('=');
    }

    /**
     * consume a keyword
     *
     * @param $word
     *
     * @return bool
     */
    protected function keyword(&$word)
    {
        if ($this->match('([\w_\-\*!"][\w\-_"]*)', $m)) {
            $word = $m[1];

            return true;
        }

        return false;
    }

    /**
     * consume an end of statement delimiter
     *
     * @return bool
     */
    protected function end()
    {
        if ($this->literal(';', false)) {
            return true;
        } elseif ($this->count === strlen($this->buffer) || $this->buffer[$this->count] === '}') {
            // if there is end of file or a closing block next then we don't need a ;
            return true;
        }

        return false;
    }

    /**
     * @param $guards
     *
     * @return bool
     */
    protected function guards(&$guards)
    {
        $s = $this->seek();

        if (!$this->literal('when')) {
            $this->seek($s);

            return false;
        }

        $guards = [];

        while ($this->guardGroup($g)) {
            $guards[] = $g;
            if (!$this->literal(',')) {
                break;
            }
        }

        if (count($guards) === 0) {
            $guards = null;
            $this->seek($s);

            return false;
        }

        return true;
    }

    /**
     * a bunch of guards that are and'd together
     *
     * @param $guardGroup
     *
     * @return bool
     */
    protected function guardGroup(&$guardGroup)
    {
        $s = $this->seek();
        $guardGroup = [];
        while ($this->guard($guard)) {
            $guardGroup[] = $guard;
            if (!$this->literal('and')) {
                break;
            }
        }

        if (count($guardGroup) === 0) {
            $guardGroup = null;
            $this->seek($s);

            return false;
        }

        return true;
    }

    /**
     * @param $guard
     *
     * @return bool
     */
    protected function guard(&$guard)
    {
        $s = $this->seek();
        $negate = $this->literal('not');

        if ($this->literal('(') && $this->expression($exp) && $this->literal(')')) {
            $guard = $exp;
            if ($negate) {
                $guard = ['negate', $guard];
            }

            return true;
        }

        $this->seek($s);

        return false;
    }

    /* raw parsing functions */

    /**
     * @param string $what
     * @param bool   $eatWhitespace
     *
     * @return bool
     */
    protected function literal($what, $eatWhitespace = null)
    {
        if ($eatWhitespace === null) {
            $eatWhitespace = $this->eatWhiteDefault;
        }

        // shortcut on single letter
        if (!isset($what[1]) && isset($this->buffer[$this->count])) {
            if ($this->buffer[$this->count] === $what) {
                if (!$eatWhitespace) {
                    $this->count++;

                    return true;
                }
                // goes below...
            } else {
                return false;
            }
        }

        if (!isset(self::$literalCache[$what])) {
            self::$literalCache[$what] = Compiler::pregQuote($what);
        }

        return $this->match(self::$literalCache[$what], $m, $eatWhitespace);
    }

    /**
     * @param        $out
     * @param string $parseItem
     * @param string $delim
     * @param bool   $flatten
     *
     * @return bool
     */
    protected function genericList(&$out, $parseItem, $delim = "", $flatten = true)
    {
        // $parseItem is one of mediaQuery, mediaExpression
        $s = $this->seek();
        $items = [];
        $value = null;
        while ($this->$parseItem($value)) {
            $items[] = $value;
            if ($delim) {
                if (!$this->literal($delim)) {
                    break;
                }
            }
        }

        if (count($items) === 0) {
            $this->seek($s);

            return false;
        }

        if ($flatten && count($items) === 1) {
            $out = $items[0];
        } else {
            $out = ['list', $delim, $items];
        }

        return true;
    }

    /**
     * try to match something on head of buffer
     *
     * @param string $regex
     * @param        $out
     * @param bool   $eatWhitespace
     *
     * @return bool
     */
    protected function match($regex, &$out, $eatWhitespace = null)
    {
        if ($eatWhitespace === null) {
            $eatWhitespace = $this->eatWhiteDefault;
        }

        $r = '/' . $regex . ($eatWhitespace && !$this->writeComments ? '\s*' : '') . '/Ais';
        if (preg_match($r, $this->buffer, $out, null, $this->count)) {
            $this->count += strlen($out[0]);
            if ($eatWhitespace && $this->writeComments) {
                $this->whitespace();
            }

            return true;
        }

        return false;
    }

    /**
     * match some whitespace
     *
     * @return bool
     */
    protected function whitespace()
    {
        if ($this->writeComments) {
            $gotWhite = false;
            while (preg_match(self::$whitePattern, $this->buffer, $m, null, $this->count)) {
                if (isset($m[1]) && empty($this->seenComments[$this->count])) {
                    $this->append(['comment', $m[1]]);
                    $this->seenComments[$this->count] = true;
                }
                $this->count += mb_strlen($m[0]);
                $gotWhite = true;
            }

            return $gotWhite;
        }

        $this->match('', $m);

        return mb_strlen($m[0]) > 0;
    }

    /**
     * match something without consuming it
     *
     * @param string $regex
     * @param array  $out
     * @param int    $from
     *
     * @return int
     */
    protected function peek($regex, &$out = null, $from = null)
    {
        if ($from === null) {
            $from = $this->count;
        }
        $r = '/' . $regex . '/Ais';

        return preg_match($r, $this->buffer, $out, null, $from);
    }

    /**
     * seek to a spot in the buffer or return where we are on no argument
     *
     * @param int $where
     *
     * @return int
     */
    protected function seek($where = null)
    {
        if ($where !== null) {
            $this->count = $where;
        }

        return $this->count;
    }

    /* misc functions */

    /**
     * @param string $msg
     * @param int    $count
     *
     * @throws \LesserPhp\Exception\GeneralException
     */
    public function throwError($msg = 'parse error', $count = null)
    {
        $count = $count === null ? $this->count : $count;

        $line = $this->line + substr_count(substr($this->buffer, 0, $count), "\n");

        if (!empty($this->sourceName)) {
            $loc = "$this->sourceName on line $line";
        } else {
            $loc = "line: $line";
        }

        // TODO this depends on $this->count
        if ($this->peek("(.*?)(\n|$)", $m, $count)) {
            throw new GeneralException("$msg: failed at `$m[1]` $loc");
        } else {
            throw new GeneralException("$msg: $loc");
        }
    }

    /**
     * @param array|null  $selectors
     * @param string|null $type
     *
     * @return Block
     */
    protected function pushBlock(array $selectors = null, $type = null)
    {
        $this->env = Block::factory($this, self::$nextBlockId++, $this->count, $type, $selectors, $this->env);

        return $this->env;
    }

    /**
     * push a block that doesn't multiply tags
     *
     * @param string $type
     *
     * @return Block|Block\Directive|Block\Media
     */
    protected function pushSpecialBlock($type)
    {
        return $this->pushBlock(null, $type);
    }

    /**
     * append a property to the current block
     *
     * @param      $prop
     * @param int  $pos
     */
    protected function append($prop, $pos = null)
    {
        if ($pos !== null) {
            $prop[-1] = $pos;
        }

        $property = Property::factoryFromOldFormat($prop, $pos);

        $this->env->props[] = $property;
    }

    /**
     * pop something off the stack
     *
     * @return Block
     */
    protected function pop()
    {
        $old = $this->env;
        $this->env = $this->env->parent;

        return $old;
    }

    /**
     * remove comments from $text
     * todo: make it work for all functions, not just url
     *
     * @param string $text
     *
     * @return string
     */
    protected function removeComments($text)
    {
        $look = [
            'url(',
            '//',
            '/*',
            '"',
            "'",
        ];

        $out = '';
        $min = null;
        while (true) {
            // find the next item
            foreach ($look as $token) {
                $pos = mb_strpos($text, $token);
                if ($pos !== false) {
                    if ($min === null || $pos < $min[1]) {
                        $min = [$token, $pos];
                    }
                }
            }

            if ($min === null) {
                break;
            }

            $count = $min[1];
            $skip = 0;
            $newlines = 0;
            switch ($min[0]) {
                case 'url(':
                    if (preg_match('/url\(.*?\)/', $text, $m, 0, $count)) {
                        $count += mb_strlen($m[0]) - mb_strlen($min[0]);
                    }
                    break;
                case '"':
                case "'":
                    if (preg_match('/' . $min[0] . '.*?(?<!\\\\)' . $min[0] . '/', $text, $m, 0, $count)) {
                        $count += mb_strlen($m[0]) - 1;
                    }
                    break;
                case '//':
                    $skip = mb_strpos($text, "\n", $count);
                    if ($skip === false) {
                        $skip = mb_strlen($text) - $count;
                    } else {
                        $skip -= $count;
                    }
                    break;
                case '/*':
                    if (preg_match('/\/\*.*?\*\//s', $text, $m, 0, $count)) {
                        $skip = mb_strlen($m[0]);
                        $newlines = mb_substr_count($m[0], "\n");
                    }
                    break;
            }

            if ($skip === 0) {
                $count += mb_strlen($min[0]);
            }

            $out .= mb_substr($text, 0, $count) . str_repeat("\n", $newlines);
            $text = mb_substr($text, $count + $skip);

            $min = null;
        }

        return $out . $text;
    }

    /**
     * @param bool $writeComments
     */
    public function setWriteComments($writeComments)
    {
        $this->writeComments = $writeComments;
    }

    /**
     * @param int $s
     *
     * @return bool
     */
    protected function handleLiteralMedia($s)
    {
        // seriously, this || true is required for this statement to work!?
        if (($this->mediaQueryList($mediaQueries) || true) && $this->literal('{')) {
            $media = $this->pushSpecialBlock('media');
            $media->queries = $mediaQueries === null ? [] : $mediaQueries;

            return true;
        } else {
            $this->seek($s);
        }

        return false;
    }

    /**
     * @param string $directiveName
     *
     * @return bool
     */
    protected function handleDirectiveBlock($directiveName)
    {
        // seriously, this || true is required for this statement to work!?
        if (($this->openString('{', $directiveValue, null, [';']) || true) && $this->literal('{')) {
            $dir = $this->pushSpecialBlock('directive');
            $dir->name = $directiveName;
            if ($directiveValue !== null) {
                $dir->value = $directiveValue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param string $directiveName
     *
     * @return bool
     */
    protected function handleDirectiveLine($directiveName)
    {
        if ($this->propertyValue($directiveValue) && $this->end()) {
            $this->append(['directive', $directiveName, $directiveValue]);

            return true;
        }

        return false;
    }

    /**
     * @param string $directiveName
     *
     * @return bool
     */
    protected function handleRulesetDefinition($directiveName)
    {
        //Ruleset Definition
        $this->openString('{', $directiveValue, null, [';']);

        if ($this->literal('{')) {
            $dir = $this->pushBlock($this->fixTags(['@' . $directiveName]), 'ruleset');
            if (!$dir instanceof Block\Ruleset) {
                throw new \RuntimeException('Block factory did not produce a Ruleset');
            }

            $dir->name = $directiveName;
            if ($directiveValue !== null) {
                $dir->value = $directiveValue;
            }

            return true;
        }

        return false;
    }

    private function clearBlockStack()
    {
        $this->env = null;
    }
}
