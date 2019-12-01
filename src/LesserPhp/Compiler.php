<?php

namespace LesserPhp;

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
 */
use LesserPhp\Color\Converter;
use LesserPhp\Exception\GeneralException;
use LesserPhp\Library\Assertions;
use LesserPhp\Library\Coerce;
use LesserPhp\Library\Functions;

/**
 * The LESS compiler and parser.
 *
 * Converting LESS to CSS is a three stage process. The incoming file is parsed
 * by `lessc_parser` into a syntax tree, then it is compiled into another tree
 * representing the CSS structure by `lessc`. The CSS tree is fed into a
 * formatter, like `lessc_formatter` which then outputs CSS as a string.
 *
 * During the first compile, all values are *reduced*, which means that their
 * types are brought to the lowest form before being dump as strings. This
 * handles math equations, variable dereferences, and the like.
 *
 * The `parse` function of `lessc` is the entry point.
 *
 * In summary:
 *
 * The `lessc` class creates an instance of the parser, feeds it LESS code,
 * then transforms the resulting tree to a CSS tree. This class also holds the
 * evaluation context, such as all available mixins and variables at any given
 * time.
 *
 * The `lessc_parser` class is only concerned with parsing its input.
 *
 * The `lessc_formatter` takes a CSS tree, and dumps it to a formatted string,
 * handling things like indentation.
 */
class Compiler
{
    const VERSION = 'v0.5.1';

    public static $TRUE = ['keyword', 'true'];
    public static $FALSE = ['keyword', 'false'];

    /**
     * @var callable[]
     */
    private $libFunctions = [];

    /**
     * @var string[]
     */
    private $registeredVars = [];

    /**
     * @var bool
     */
    protected $preserveComments = false;

    /**
     * @var string $vPrefix prefix of abstract properties
     */
    private $vPrefix = '@';

    /**
     * @var string $mPrefix prefix of abstract blocks
     */
    private $mPrefix = '$';

    /**
     * @var string
     */
    private $parentSelector = '&';

    /**
     * @var bool $importDisabled disable @import
     */
    private $importDisabled = false;

    /**
     * @var string[]
     */
    private $importDirs = [];

    /**
     * @var int
     */
    private $numberPrecision;

    /**
     * @var string[]
     */
    private $allParsedFiles = [];

    /**
     * set to the parser that generated the current line when compiling
     * so we know how to create error messages
     * @var \LesserPhp\Parser
     */
    private $sourceParser;

    /**
     * @var integer $sourceLoc Lines of Code
     */
    private $sourceLoc;

    /**
     * @var int $nextImportId uniquely identify imports
     */
    private static $nextImportId = 0;

    /**
     * @var Parser
     */
    private $parser;

    /**
     * @var \LesserPhp\Formatter\FormatterInterface
     */
    private $formatter;

    /**
     * @var \LesserPhp\NodeEnv What's the meaning of "env" in this context?
     */
    private $env;

    /**
     * @var \LesserPhp\Library\Coerce
     */
    private $coerce;

    /**
     * @var \LesserPhp\Library\Assertions
     */
    private $assertions;

    /**
     * @var \LesserPhp\Library\Functions
     */
    private $functions;

    /**
     * @var mixed what's this exactly?
     */
    private $scope;

    /**
     * @var string
     */
    private $formatterName;

    /**
     * @var \LesserPhp\Color\Converter
     */
    private $converter;

    /**
     * Constructor.
     *
     * Hardwires dependencies for now
     */
    public function __construct()
    {
        $this->coerce = new Coerce();
        $this->assertions = new Assertions($this->coerce);
        $this->converter = new Converter();
        $this->functions = new Functions($this->assertions, $this->coerce, $this, $this->converter);
    }

    /**
     * @param array $items
     * @param       $delim
     *
     * @return array
     */
    public static function compressList(array $items, $delim)
    {
        if (!isset($items[1]) && isset($items[0])) {
            return $items[0];
        } else {
            return ['list', $delim, $items];
        }
    }

    /**
     * @param string $what
     *
     * @return string
     */
    public static function pregQuote($what)
    {
        return preg_quote($what, '/');
    }

    /**
     * @param array     $importPath
     * @param Block     $parentBlock
     * @param \stdClass $out
     *
     * @return array|false
     * @throws \LesserPhp\Exception\GeneralException
     */
    protected function tryImport(array $importPath, Block $parentBlock, \stdClass $out)
    {
        if ($importPath[0] === 'function' && $importPath[1] === 'url') {
            $importPath = $this->flattenList($importPath[2]);
        }

        $str = $this->coerce->coerceString($importPath);
        if ($str === null) {
            return false;
        }

        $url = $this->compileValue($this->functions->e($str));

        // don't import if it ends in css
        if (substr_compare($url, '.css', -4, 4) === 0) {
            return false;
        }

        $realPath = $this->functions->findImport($url);

        if ($realPath === null) {
            return false;
        }

        if ($this->isImportDisabled()) {
            return [false, '/* import disabled */'];
        }

        if (isset($this->allParsedFiles[realpath($realPath)])) {
            return [false, null];
        }

        $this->addParsedFile($realPath);
        $parser = $this->makeParser($realPath);
        $root = $parser->parse(file_get_contents($realPath));

        // set the parents of all the block props
        foreach ($root->props as $prop) {
            if ($prop instanceof Property\BlockProperty) {
                $prop->getBlock()->parent = $parentBlock;
            }
        }

        // copy mixins into scope, set their parents
        // bring blocks from import into current block
        // TODO: need to mark the source parser	these came from this file
        foreach ($root->children as $childName => $child) {
            if (isset($parentBlock->children[$childName])) {
                $parentBlock->children[$childName] = array_merge(
                    $parentBlock->children[$childName],
                    $child
                );
            } else {
                $parentBlock->children[$childName] = $child;
            }
        }

        $pi = pathinfo($realPath);
        $dir = $pi["dirname"];

        list($top, $bottom) = $this->sortProps($root->props, true);
        $this->compileImportedProps($top, $parentBlock, $out, $dir);

        return [true, $bottom, $parser, $dir];
    }

    /**
     * @param Property[] $props
     * @param Block      $block
     * @param \stdClass  $out
     * @param string     $importDir
     */
    protected function compileImportedProps(array $props, Block $block, \stdClass $out, $importDir)
    {
        $oldSourceParser = $this->sourceParser;

        $oldImport = $this->importDirs;

        array_unshift($this->importDirs, $importDir);

        foreach ($props as $prop) {
            $this->compileProp($prop, $block, $out);
        }

        $this->importDirs   = $oldImport;
        $this->sourceParser = $oldSourceParser;
    }

    /**
     * Recursively compiles a block.
     *
     * A block is analogous to a CSS block in most cases. A single LESS document
     * is encapsulated in a block when parsed, but it does not have parent tags
     * so all of it's children appear on the root level when compiled.
     *
     * Blocks are made up of props and children.
     *
     * Props are property instructions, array tuples which describe an action
     * to be taken, eg. write a property, set a variable, mixin a block.
     *
     * The children of a block are just all the blocks that are defined within.
     * This is used to look up mixins when performing a mixin.
     *
     * Compiling the block involves pushing a fresh environment on the stack,
     * and iterating through the props, compiling each one.
     *
     * See lessc::compileProp()
     *
     * @param Block $block
     *
     * @throws \LesserPhp\Exception\GeneralException
     */
    protected function compileBlock(Block $block)
    {
        switch ($block->type) {
            case "root":
                $this->compileRoot($block);
                break;
            case null:
                $this->compileCSSBlock($block);
                break;
            case "media":
                $this->compileMedia($block);
                break;
            case "directive":
                $name = "@" . $block->name;
                if (!empty($block->value)) {
                    $name .= " " . $this->compileValue($this->reduce($block->value));
                }

                $this->compileNestedBlock($block, [$name]);
                break;
            default:
                $block->parser->throwError("unknown block type: $block->type\n", $block->count);
        }
    }

    /**
     * @param Block $block
     *
     * @throws \LesserPhp\Exception\GeneralException
     */
    protected function compileCSSBlock(Block $block)
    {
        $env = $this->pushEnv($this->env);

        $selectors = $this->compileSelectors($block->tags);
        $env->setSelectors($this->multiplySelectors($selectors));
        $out = $this->makeOutputBlock(null, $env->getSelectors());

        $this->scope->children[] = $out;
        $this->compileProps($block, $out);

        $block->scope = $env; // mixins carry scope with them!
        $this->popEnv();
    }

    /**
     * @param Block\Media $media
     */
    protected function compileMedia(Block\Media $media)
    {
        $env = $this->pushEnv($this->env, $media);
        $parentScope = $this->mediaParent($this->scope);

        $query = $this->compileMediaQuery($this->multiplyMedia($env));

        $this->scope = $this->makeOutputBlock($media->type, [$query]);
        $parentScope->children[] = $this->scope;

        $this->compileProps($media, $this->scope);

        if (count($this->scope->lines) > 0) {
            $orphanSelelectors = $this->findClosestSelectors();
            if ($orphanSelelectors !== null) {
                $orphan = $this->makeOutputBlock(null, $orphanSelelectors);
                $orphan->lines = $this->scope->lines;
                array_unshift($this->scope->children, $orphan);
                $this->scope->lines = [];
            }
        }

        $this->scope = $this->scope->parent;
        $this->popEnv();
    }

    /**
     * @param $scope
     *
     * @return mixed
     */
    protected function mediaParent($scope)
    {
        while (!empty($scope->parent)) {
            if (!empty($scope->type) && $scope->type !== "media") {
                break;
            }
            $scope = $scope->parent;
        }

        return $scope;
    }

    /**
     * @param Block    $block
     * @param string[] $selectors
     */
    protected function compileNestedBlock(Block $block, array $selectors)
    {
        $this->pushEnv($this->env, $block);
        $this->scope = $this->makeOutputBlock($block->type, $selectors);
        $this->scope->parent->children[] = $this->scope;

        $this->compileProps($block, $this->scope);

        $this->scope = $this->scope->parent;
        $this->popEnv();
    }

    /**
     * @param Block $root
     */
    protected function compileRoot(Block $root)
    {
        $this->pushEnv($this->env);
        $this->scope = $this->makeOutputBlock($root->type);
        $this->compileProps($root, $this->scope);
        $this->popEnv();
    }

    /**
     * @param Block     $block
     * @param \stdClass $out
     *
     * @throws \LesserPhp\Exception\GeneralException
     */
    protected function compileProps(Block $block, \stdClass $out)
    {
        foreach ($this->sortProps($block->props) as $prop) {
            $this->compileProp($prop, $block, $out);
        }
        $out->lines = $this->deduplicate($out->lines);
    }

    /**
     * Deduplicate lines in a block. Comments are not deduplicated. If a
     * duplicate rule is detected, the comments immediately preceding each
     * occurence are consolidated.
     *
     * @param array $lines
     *
     * @return array
     */
    protected function deduplicate(array $lines)
    {
        $unique = [];
        $comments = [];

        foreach ($lines as $line) {
            if (strpos($line, '/*') === 0) {
                $comments[] = $line;
                continue;
            }
            if (!in_array($line, $unique)) {
                $unique[] = $line;
            }
            array_splice($unique, array_search($line, $unique), 0, $comments);
            $comments = [];
        }

        return array_merge($unique, $comments);
    }

    /**
     * @param Property[] $props
     * @param bool       $split
     *
     * @return Property[]
     */
    protected function sortProps(array $props, $split = false)
    {
        $vars    = [];
        $imports = [];
        $other   = [];
        $stack   = [];

        foreach ($props as $prop) {
            switch (true) {
                case $prop instanceof Property\CommentProperty:
                    $stack[] = $prop;
                    break;

                case $prop instanceof Property\AssignProperty:
                    $stack[] = $prop;
                    if ($prop->nameHasPrefix($this->vPrefix)) {
                        $vars = array_merge($vars, $stack);
                    } else {
                        $other = array_merge($other, $stack);
                    }
                    $stack = [];
                    break;

                case $prop instanceof Property\ImportProperty:
                    $id = self::$nextImportId++;
                    $prop->setId($id);

                    $stack[] = $prop;
                    $imports = array_merge($imports, $stack);
                    $other[] = Property::factoryFromOldFormat(["import_mixin", $id]);
                    $stack   = [];
                    break;

                default:
                    $stack[] = $prop;
                    $other   = array_merge($other, $stack);
                    $stack   = [];
                    break;
            }
        }

        $other = array_merge($other, $stack);

        if ($split) {
            return [array_merge($vars, $imports, $vars), $other];
        } else {
            return array_merge($vars, $imports, $vars, $other);
        }
    }

    /**
     * @param array $queries
     *
     * @return string
     */
    protected function compileMediaQuery(array $queries)
    {
        $compiledQueries = [];
        foreach ($queries as $query) {
            $parts = [];
            foreach ($query as $q) {
                switch ($q[0]) {
                    case "mediaType":
                        $parts[] = implode(" ", array_slice($q, 1));
                        break;
                    case "mediaExp":
                        if (isset($q[2])) {
                            $parts[] = "($q[1]: " .
                                $this->compileValue($this->reduce($q[2])) . ")";
                        } else {
                            $parts[] = "($q[1])";
                        }
                        break;
                    case "variable":
                        $parts[] = $this->compileValue($this->reduce($q));
                        break;
                }
            }

            if (count($parts) > 0) {
                $compiledQueries[] = implode(" and ", $parts);
            }
        }

        $out = "@media";
        if (!empty($parts)) {
            $out .= " " .
                implode($this->formatter->getSelectorSeparator(), $compiledQueries);
        }

        return $out;
    }

    /**
     * @param \LesserPhp\NodeEnv $env
     * @param array              $childQueries
     *
     * @return array
     */
    protected function multiplyMedia(NodeEnv $env = null, array $childQueries = null)
    {
        if ($env === null ||
            (!empty($env->getBlock()->type) && $env->getBlock()->type !== 'media')
        ) {
            return $childQueries;
        }

        // plain old block, skip
        if (empty($env->getBlock()->type)) {
            return $this->multiplyMedia($env->getParent(), $childQueries);
        }

        $out = [];
        $queries = $env->getBlock()->queries;
        if ($childQueries === null) {
            $out = $queries;
        } else {
            foreach ($queries as $parent) {
                foreach ($childQueries as $child) {
                    $out[] = array_merge($parent, $child);
                }
            }
        }

        return $this->multiplyMedia($env->getParent(), $out);
    }

    /**
     * @param $tag
     * @param $replace
     *
     * @return int
     */
    protected function expandParentSelectors(&$tag, $replace)
    {
        $parts = explode("$&$", $tag);
        $count = 0;
        foreach ($parts as &$part) {
            $part = str_replace($this->parentSelector, $replace, $part, $c);
            $count += $c;
        }
        $tag = implode($this->parentSelector, $parts);

        return $count;
    }

    /**
     * @return array|null
     */
    protected function findClosestSelectors()
    {
        $env = $this->env;
        $selectors = null;
        while ($env !== null) {
            if ($env->getSelectors() !== null) {
                $selectors = $env->getSelectors();
                break;
            }
            $env = $env->getParent();
        }

        return $selectors;
    }


    /**
     *  multiply $selectors against the nearest selectors in env
     *
     * @param array $selectors
     *
     * @return array
     */
    protected function multiplySelectors(array $selectors)
    {
        // find parent selectors

        $parentSelectors = $this->findClosestSelectors();
        if ($parentSelectors === null) {
            // kill parent reference in top level selector
            foreach ($selectors as &$s) {
                $this->expandParentSelectors($s, "");
            }

            return $selectors;
        }

        $out = [];
        foreach ($parentSelectors as $parent) {
            foreach ($selectors as $child) {
                $count = $this->expandParentSelectors($child, $parent);

                // don't prepend the parent tag if & was used
                if ($count > 0) {
                    $out[] = trim($child);
                } else {
                    $out[] = trim($parent . ' ' . $child);
                }
            }
        }

        return $out;
    }

    /**
     * reduces selector expressions
     *
     * @param array $selectors
     *
     * @return array
     * @throws \LesserPhp\Exception\GeneralException
     */
    protected function compileSelectors(array $selectors)
    {
        $out = [];

        foreach ($selectors as $s) {
            if (is_array($s)) {
                list(, $value) = $s;
                $out[] = trim($this->compileValue($this->reduce($value)));
            } else {
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * @param $left
     * @param $right
     *
     * @return bool
     */
    protected function equals($left, $right)
    {
        return $left == $right;
    }

    /**
     * @param $block
     * @param $orderedArgs
     * @param $keywordArgs
     *
     * @return bool
     */
    protected function patternMatch($block, $orderedArgs, $keywordArgs)
    {
        // match the guards if it has them
        // any one of the groups must have all its guards pass for a match
        if (!empty($block->guards)) {
            $groupPassed = false;
            foreach ($block->guards as $guardGroup) {
                foreach ($guardGroup as $guard) {
                    $this->pushEnv($this->env);
                    $this->zipSetArgs($block->args, $orderedArgs, $keywordArgs);

                    $negate = false;
                    if ($guard[0] === "negate") {
                        $guard = $guard[1];
                        $negate = true;
                    }

                    $passed = $this->reduce($guard) == self::$TRUE;
                    if ($negate) {
                        $passed = !$passed;
                    }

                    $this->popEnv();

                    if ($passed) {
                        $groupPassed = true;
                    } else {
                        $groupPassed = false;
                        break;
                    }
                }

                if ($groupPassed) {
                    break;
                }
            }

            if (!$groupPassed) {
                return false;
            }
        }

        if (empty($block->args)) {
            return $block->isVararg || empty($orderedArgs) && empty($keywordArgs);
        }

        $remainingArgs = $block->args;
        if ($keywordArgs) {
            $remainingArgs = [];
            foreach ($block->args as $arg) {
                if ($arg[0] === "arg" && isset($keywordArgs[$arg[1]])) {
                    continue;
                }

                $remainingArgs[] = $arg;
            }
        }

        $i = -1; // no args
        // try to match by arity or by argument literal
        foreach ($remainingArgs as $i => $arg) {
            switch ($arg[0]) {
                case "lit":
                    if (empty($orderedArgs[$i]) || !$this->equals($arg[1], $orderedArgs[$i])) {
                        return false;
                    }
                    break;
                case "arg":
                    // no arg and no default value
                    if (!isset($orderedArgs[$i]) && !isset($arg[2])) {
                        return false;
                    }
                    break;
                case "rest":
                    $i--; // rest can be empty
                    break 2;
            }
        }

        if ($block->isVararg) {
            return true; // not having enough is handled above
        } else {
            $numMatched = $i + 1;

            // greater than because default values always match
            return $numMatched >= count($orderedArgs);
        }
    }

    /**
     * @param array $blocks
     * @param       $orderedArgs
     * @param       $keywordArgs
     * @param array $skip
     *
     * @return array|null
     */
    protected function patternMatchAll(array $blocks, $orderedArgs, $keywordArgs, array $skip = [])
    {
        $matches = null;
        foreach ($blocks as $block) {
            // skip seen blocks that don't have arguments
            if (isset($skip[$block->id]) && !isset($block->args)) {
                continue;
            }

            if ($this->patternMatch($block, $orderedArgs, $keywordArgs)) {
                $matches[] = $block;
            }
        }

        return $matches;
    }

    /**
     * attempt to find blocks matched by path and args
     *
     * @param       $searchIn
     * @param array $path
     * @param       $orderedArgs
     * @param       $keywordArgs
     * @param array $seen
     *
     * @return array|null
     */
    protected function findBlocks($searchIn, array $path, $orderedArgs, $keywordArgs, array $seen = [])
    {
        if ($searchIn === null) {
            return null;
        }
        if (isset($seen[$searchIn->id])) {
            return null;
        }
        $seen[$searchIn->id] = true;

        $name = $path[0];

        if (isset($searchIn->children[$name])) {
            $blocks = $searchIn->children[$name];
            if (count($path) === 1) {
                $matches = $this->patternMatchAll($blocks, $orderedArgs, $keywordArgs, $seen);
                if (!empty($matches)) {
                    // This will return all blocks that match in the closest
                    // scope that has any matching block, like lessjs
                    return $matches;
                }
            } else {
                $matches = [];
                foreach ($blocks as $subBlock) {
                    $subMatches = $this->findBlocks(
                        $subBlock,
                        array_slice($path, 1),
                        $orderedArgs,
                        $keywordArgs,
                        $seen
                    );

                    if ($subMatches !== null) {
                        foreach ($subMatches as $sm) {
                            $matches[] = $sm;
                        }
                    }
                }

                return count($matches) > 0 ? $matches : null;
            }
        }
        if ($searchIn->parent === $searchIn) {
            return null;
        }

        return $this->findBlocks($searchIn->parent, $path, $orderedArgs, $keywordArgs, $seen);
    }

    /**
     * sets all argument names in $args to either the default value
     * or the one passed in through $values
     *
     * @param array $args
     * @param       $orderedValues
     * @param       $keywordValues
     *
     * @throws \LesserPhp\Exception\GeneralException
     */
    protected function zipSetArgs(array $args, $orderedValues, $keywordValues)
    {
        $assignedValues = [];

        $i = 0;
        foreach ($args as $a) {
            if ($a[0] === "arg") {
                if (isset($keywordValues[$a[1]])) {
                    // has keyword arg
                    $value = $keywordValues[$a[1]];
                } elseif (isset($orderedValues[$i])) {
                    // has ordered arg
                    $value = $orderedValues[$i];
                    $i++;
                } elseif (isset($a[2])) {
                    // has default value
                    $value = $a[2];
                } else {
                    throw new GeneralException('Failed to assign arg ' . $a[1]);
                }

                $value = $this->reduce($value);
                $this->set($a[1], $value);
                $assignedValues[] = $value;
            } else {
                // a lit
                $i++;
            }
        }

        // check for a rest
        $last = end($args);
        if ($last !== false && $last[0] === "rest") {
            $rest = array_slice($orderedValues, count($args) - 1);
            $this->set($last[1], $this->reduce(["list", " ", $rest]));
        }

        // wow is this the only true use of PHP's + operator for arrays?
        $this->env->setArguments($assignedValues + $orderedValues);
    }

    /**
     * compile a prop and update $lines or $blocks appropriately
     *
     * @param Property  $prop
     * @param Block     $block
     * @param \stdClass $out
     *
     * @throws \LesserPhp\Exception\GeneralException
     */
    protected function compileProp(Property $prop, Block $block, \stdClass $out)
    {
        $this->sourceLoc = ($prop->hasPos() ? $prop->getPos() : -1);

        if ($prop instanceof Property\CanCompile) {
            $out->lines[] = $prop->compile($this);

            return;
        }

        switch (true) {
            case $prop instanceof Property\AssignProperty:
                if ($prop->nameHasPrefix($this->vPrefix)) {
                    $this->set($prop->getName(), $prop->getValue());
                } else {
                    $out->lines[] = $this->formatter->property(
                        $prop->getName(),
                        $this->compileValue($this->reduce($prop->getValue()))
                    );
                }

                return;

            case $prop instanceof Property\BlockProperty:
                $this->compileBlock($prop->getBlock());

                return;

            case $prop instanceof Property\ImportProperty:
                $importPath = $this->reduce($prop->getPath());
                $result     = $this->tryImport($importPath, $block, $out);
                if ($result === false) {
                    $result = [false, "@import " . $this->compileValue($importPath) . ";"];
                }
                $this->env->addImports($prop->getId(), $result);

                return;

            case $prop instanceof Property\ImportMixinProperty:
                $import = $this->env->getImports($prop->getId());
                if ($import[0] === false) {
                    if (isset($import[1])) {
                        $out->lines[] = $import[1];
                    }
                } else {
                    $bottom    = $import[1];
                    $importDir = $import[3];
                    $this->compileImportedProps($bottom, $block, $out, $importDir);
                }

                return;

            case $prop instanceof Property\RulesetProperty:
            case $prop instanceof Property\MixinProperty:
                $path   = $prop->getPath();
                $args   = $prop->getArgs();
                $suffix = $prop->getSuffix();

                $orderedArgs = [];
                $keywordArgs = [];
                foreach ($args as $arg) {
                    switch ($arg[0]) {
                        case "arg":
                            if (!isset($arg[2])) {
                                $orderedArgs[] = $this->reduce(["variable", $arg[1]]);
                            } else {
                                $keywordArgs[$arg[1]] = $this->reduce($arg[2]);
                            }
                            break;

                        case "lit":
                            $orderedArgs[] = $this->reduce($arg[1]);
                            break;
                        default:
                            throw new GeneralException("Unknown arg type: " . $arg[0]);
                    }
                }

                $mixins = $this->findBlocks($block, $path, $orderedArgs, $keywordArgs);
                if ($mixins === null) {
                    $block->parser->throwError($prop->getPath()[0].' is undefined', $block->count);
                }

                if (strpos($path[0], "$") === 0) {
                    //Use Ruleset Logic - Only last element
                    $mixins = [array_pop($mixins)];
                }

                foreach ($mixins as $mixin) {
                    if ($mixin === $block && !$orderedArgs) {
                        continue;
                    }

                    $haveScope = false;
                    if (isset($mixin->parent->scope)) {
                        $haveScope                   = true;
                        $mixinParentEnv              = $this->pushEnv($this->env);
                        $mixinParentEnv->storeParent = $mixin->parent->scope;
                    }

                    $haveArgs = false;
                    if (isset($mixin->args)) {
                        $haveArgs = true;
                        $this->pushEnv($this->env);
                        $this->zipSetArgs($mixin->args, $orderedArgs, $keywordArgs);
                    }

                    $oldParent = $mixin->parent;
                    if ($mixin != $block) {
                        $mixin->parent = $block;
                    }

                    foreach ($this->sortProps($mixin->props) as $subProp) {
                        if ($suffix !== null &&
                            $subProp instanceof Property\AssignProperty &&
                            !$subProp->nameHasPrefix($this->vPrefix)
                        ) {
                            $subProp->setValue(['list', ' ', [$subProp->getValue(), ['keyword', $suffix]]]);
                        }

                        $this->compileProp($subProp, $mixin, $out);
                    }

                    $mixin->parent = $oldParent;

                    if ($haveArgs) {
                        $this->popEnv();
                    }
                    if ($haveScope) {
                        $this->popEnv();
                    }
                }

                return;

            default:
                $block->parser->throwError("unknown op: {$prop->getType()}\n", $block->count);
        }
    }


    /**
     * Compiles a primitive value into a CSS property value.
     *
     * Values in lessphp are typed by being wrapped in arrays, their format is
     * typically:
     *
     *     array(type, contents [, additional_contents]*)
     *
     * The input is expected to be reduced. This function will not work on
     * things like expressions and variables.
     *
     * @param array $value
     * @param array $options
     *
     * @return string
     * @throws GeneralException
     */
    public function compileValue(array $value, array $options = [])
    {
        try {
            if (!isset($value[0])) {
                throw new GeneralException('Missing value type');
            }

            $options = array_replace([
                'numberPrecision' => $this->numberPrecision,
                'compressColors'  => ($this->formatter ? $this->formatter->getCompressColors() : false),
            ], $options);

            $valueClass = \LesserPhp\Compiler\Value\AbstractValue::factory($this, $this->coerce, $options, $value);

            return $valueClass->getCompiled();
        } catch (\UnexpectedValueException $e) {
            throw new GeneralException($e->getMessage());
        }
    }

    /**
     * Helper function to get arguments for color manipulation functions.
     * takes a list that contains a color like thing and a percentage
     *
     * @param array $args
     *
     * @return array
     */
    public function colorArgs(array $args)
    {
        if ($args[0] !== 'list' || count($args[2]) < 2) {
            return [['color', 0, 0, 0], 0];
        }
        list($color, $delta) = $args[2];
        $color = $this->assertions->assertColor($color);
        $delta = (float) $delta[1];

        return [$color, $delta];
    }

    /**
     * Convert the rgb, rgba, hsl color literals of function type
     * as returned by the parser into values of color type.
     *
     * @param array $func
     *
     * @return bool|mixed
     */
    protected function funcToColor(array $func)
    {
        $fname = $func[1];
        if ($func[2][0] !== 'list') {
            return false;
        } // need a list of arguments
        /** @var array $rawComponents */
        $rawComponents = $func[2][2];

        if ($fname === 'hsl' || $fname === 'hsla') {
            $hsl = ['hsl'];
            $i = 0;
            foreach ($rawComponents as $c) {
                $val = $this->reduce($c);
                $val = isset($val[1]) ? (float) $val[1] : 0;

                if ($i === 0) {
                    $clamp = 360;
                } elseif ($i < 3) {
                    $clamp = 100;
                } else {
                    $clamp = 1;
                }

                $hsl[] = $this->converter->clamp($val, $clamp);
                $i++;
            }

            while (count($hsl) < 4) {
                $hsl[] = 0;
            }

            return $this->converter->toRGB($hsl);
        } elseif ($fname === 'rgb' || $fname === 'rgba') {
            $components = [];
            $i = 1;
            foreach ($rawComponents as $c) {
                $c = $this->reduce($c);
                if ($i < 4) {
                    if ($c[0] === "number" && $c[2] === "%") {
                        $components[] = 255 * ($c[1] / 100);
                    } else {
                        $components[] = (float) $c[1];
                    }
                } elseif ($i === 4) {
                    if ($c[0] === "number" && $c[2] === "%") {
                        $components[] = 1.0 * ($c[1] / 100);
                    } else {
                        $components[] = (float) $c[1];
                    }
                } else {
                    break;
                }

                $i++;
            }
            while (count($components) < 3) {
                $components[] = 0;
            }
            array_unshift($components, 'color');

            return $this->fixColor($components);
        }

        return false;
    }

    /**
     * @param array $value
     * @param bool  $forExpression
     *
     * @return array|bool|mixed|null // <!-- dafuq?
     */
    public function reduce(array $value, $forExpression = false)
    {
        switch ($value[0]) {
            case "interpolate":
                $reduced = $this->reduce($value[1]);
                $var = $this->compileValue($reduced);
                $res = $this->reduce(["variable", $this->vPrefix . $var]);

                if ($res[0] === "raw_color") {
                    $res = $this->coerce->coerceColor($res);
                }

                if (empty($value[2])) {
                    $res = $this->functions->e($res);
                }

                return $res;
            case "variable":
                $key = $value[1];
                if (is_array($key)) {
                    $key = $this->reduce($key);
                    $key = $this->vPrefix . $this->compileValue($this->functions->e($key));
                }

                $seen =& $this->env->seenNames;

                if (!empty($seen[$key])) {
                    $this->throwError("infinite loop detected: $key");
                }

                $seen[$key] = true;
                $out = $this->reduce($this->get($key));
                $seen[$key] = false;

                return $out;
            case "list":
                foreach ($value[2] as &$item) {
                    $item = $this->reduce($item, $forExpression);
                }

                return $value;
            case "expression":
                return $this->evaluate($value);
            case "string":
                foreach ($value[2] as &$part) {
                    if (is_array($part)) {
                        $strip = $part[0] === "variable";
                        $part = $this->reduce($part);
                        if ($strip) {
                            $part = $this->functions->e($part);
                        }
                    }
                }

                return $value;
            case "escape":
                list(, $inner) = $value;

                return $this->functions->e($this->reduce($inner));
            case "function":
                $color = $this->funcToColor($value);
                if ($color) {
                    return $color;
                }

                list(, $name, $args) = $value;
                if ($name === "%") {
                    $name = "_sprintf";
                }

                // user functions
                $f = null;
                if (isset($this->libFunctions[$name]) && is_callable($this->libFunctions[$name])) {
                    $f = $this->libFunctions[$name];
                }

                $func = str_replace('-', '_', $name);

                if ($f !== null || method_exists($this->functions, $func)) {
                    if ($args[0] === 'list') {
                        $args = self::compressList($args[2], $args[1]);
                    }

                    if ($f !== null) {
                        $ret = $f($this->reduce($args, true), $this);
                    } else {
                        $ret = $this->functions->$func($this->reduce($args, true), $this);
                    }
                    if ($ret === null) {
                        return [
                            "string",
                            "",
                            [
                                $name,
                                "(",
                                $args,
                                ")",
                            ],
                        ];
                    }

                    // convert to a typed value if the result is a php primitive
                    if (is_numeric($ret)) {
                        $ret = ['number', $ret, ""];
                    } elseif (!is_array($ret)) {
                        $ret = ['keyword', $ret];
                    }

                    return $ret;
                }

                // plain function, reduce args
                $value[2] = $this->reduce($value[2]);

                return $value;
            case "unary":
                list(, $op, $exp) = $value;
                $exp = $this->reduce($exp);

                if ($exp[0] === "number") {
                    switch ($op) {
                        case "+":
                            return $exp;
                        case "-":
                            $exp[1] *= -1;

                            return $exp;
                    }
                }

                return ["string", "", [$op, $exp]];
        }

        if ($forExpression) {
            switch ($value[0]) {
                case "keyword":
                    $color = $this->coerce->coerceColor($value);
                    if ($color !== null) {
                        return $color;
                    }
                    break;
                case "raw_color":
                    return $this->coerce->coerceColor($value);
            }
        }

        return $value;
    }

    /**
     * turn list of length 1 into value type
     *
     * @param array $value
     *
     * @return array
     */
    protected function flattenList(array $value)
    {
        if ($value[0] === 'list' && count($value[2]) === 1) {
            return $this->flattenList($value[2][0]);
        }

        return $value;
    }

    /**
     * evaluate an expression
     *
     * @param array $exp
     *
     * @return array
     */
    protected function evaluate($exp)
    {
        list(, $op, $left, $right, $whiteBefore, $whiteAfter) = $exp;

        $left = $this->reduce($left, true);
        $right = $this->reduce($right, true);

        $leftColor = $this->coerce->coerceColor($left);
        if ($leftColor !== null) {
            $left = $leftColor;
        }

        $rightColor = $this->coerce->coerceColor($right);
        if ($rightColor !== null) {
            $right = $rightColor;
        }

        $ltype = $left[0];
        $rtype = $right[0];

        // operators that work on all types
        if ($op === "and") {
            return $this->functions->toBool($left == self::$TRUE && $right == self::$TRUE);
        }

        if ($op === "=") {
            return $this->functions->toBool($this->equals($left, $right));
        }

        $str = $this->stringConcatenate($left, $right);
        if ($op === "+" && $str !== null) {
            return $str;
        }

        // type based operators
        $fname = "op_${ltype}_${rtype}";
        if (is_callable([$this, $fname])) {
            $out = $this->$fname($op, $left, $right);
            if ($out !== null) {
                return $out;
            }
        }

        // make the expression look it did before being parsed
        $paddedOp = $op;
        if ($whiteBefore) {
            $paddedOp = " " . $paddedOp;
        }
        if ($whiteAfter) {
            $paddedOp .= " ";
        }

        return ["string", "", [$left, $paddedOp, $right]];
    }

    /**
     * @param array $left
     * @param array $right
     *
     * @return array|null
     */
    protected function stringConcatenate(array $left, array $right)
    {
        $strLeft = $this->coerce->coerceString($left);
        if ($strLeft !== null) {
            if ($right[0] === "string") {
                $right[1] = "";
            }
            $strLeft[2][] = $right;

            return $strLeft;
        }

        $strRight = $this->coerce->coerceString($right);
        if ($strRight !== null) {
            array_unshift($strRight[2], $left);

            return $strRight;
        }

        return null;
    }


    /**
     * make sure a color's components don't go out of bounds
     *
     * @param array $c
     *
     * @return mixed
     */
    public function fixColor(array $c)
    {
        foreach (range(1, 3) as $i) {
            if ($c[$i] < 0) {
                $c[$i] = 0;
            }
            if ($c[$i] > 255) {
                $c[$i] = 255;
            }
        }

        return $c;
    }

    /**
     * @param string $op
     * @param array  $lft
     * @param array  $rgt
     *
     * @return array|null
     * @throws \LesserPhp\Exception\GeneralException
     */
    protected function op_number_color($op, array $lft, array $rgt)
    {
        if ($op === '+' || $op === '*') {
            return $this->op_color_number($op, $rgt, $lft);
        }

        return null;
    }

    /**
     * @param string $op
     * @param array  $lft
     * @param array  $rgt
     *
     * @return array
     * @throws \LesserPhp\Exception\GeneralException
     */
    protected function op_color_number($op, array $lft, array $rgt)
    {
        if ($rgt[0] === '%') {
            $rgt[1] /= 100;
        }

        return $this->op_color_color(
            $op,
            $lft,
            array_fill(1, count($lft) - 1, $rgt[1])
        );
    }

    /**
     * @param string $op
     * @param        array
     * $left
     * @param array  $right
     *
     * @return array
     * @throws \LesserPhp\Exception\GeneralException
     */
    protected function op_color_color($op, array $left, array $right)
    {
        $out = ['color'];
        $max = count($left) > count($right) ? count($left) : count($right);
        foreach (range(1, $max - 1) as $i) {
            $lval = isset($left[$i]) ? $left[$i] : 0;
            $rval = isset($right[$i]) ? $right[$i] : 0;
            switch ($op) {
                case '+':
                    $out[] = $lval + $rval;
                    break;
                case '-':
                    $out[] = $lval - $rval;
                    break;
                case '*':
                    $out[] = $lval * $rval;
                    break;
                case '%':
                    $out[] = $lval % $rval;
                    break;
                case '/':
                    if ($rval == 0) {
                        throw new GeneralException("evaluate error: can't divide by zero");
                    }
                    $out[] = $lval / $rval;
                    break;
                default:
                    throw new GeneralException('evaluate error: color op number failed on op ' . $op);
            }
        }

        return $this->fixColor($out);
    }

    /**
     * operator on two numbers
     *
     * @param string $op
     * @param array  $left
     * @param array  $right
     *
     * @return array
     * @throws \LesserPhp\Exception\GeneralException
     */
    protected function op_number_number($op, $left, $right)
    {
        $unit = empty($left[2]) ? $right[2] : $left[2];

        $value = 0;
        switch ($op) {
            case '+':
                $value = $left[1] + $right[1];
                break;
            case '*':
                $value = $left[1] * $right[1];
                break;
            case '-':
                $value = $left[1] - $right[1];
                break;
            case '%':
                $value = $left[1] % $right[1];
                break;
            case '/':
                if ($right[1] == 0) {
                    throw new GeneralException('parse error: divide by zero');
                }
                $value = $left[1] / $right[1];
                break;
            case '<':
                return $this->functions->toBool($left[1] < $right[1]);
            case '>':
                return $this->functions->toBool($left[1] > $right[1]);
            case '>=':
                return $this->functions->toBool($left[1] >= $right[1]);
            case '=<':
                return $this->functions->toBool($left[1] <= $right[1]);
            default:
                throw new GeneralException('parse error: unknown number operator: ' . $op);
        }

        return ['number', $value, $unit];
    }

    /**
     * @param      $type
     * @param null $selectors
     *
     * @return \stdClass
     */
    protected function makeOutputBlock($type, $selectors = null)
    {
        $b = new \stdClass();
        $b->lines = [];
        $b->children = [];
        $b->selectors = $selectors;
        $b->type = $type;
        $b->parent = $this->scope;

        return $b;
    }

    /**
     * @param \LesserPhp\NodeEnv $parent
     * @param Block|null $block
     *
     * @return \LesserPhp\NodeEnv
     */
    protected function pushEnv($parent, Block $block = null)
    {
        $e = new NodeEnv();
        $e->setParent($parent);
        $e->setBlock($block);
        $e->setStore([]);

        $this->env = $e;

        return $e;
    }

    /**
     * pop something off the stack
     *
     * @return \LesserPhp\NodeEnv
     */
    protected function popEnv()
    {
        $old = $this->env;
        $this->env = $this->env->getParent();

        return $old;
    }

    /**
     * set something in the current env
     *
     * @param $name
     * @param $value
     */
    protected function set($name, $value)
    {
        $this->env->addStore($name, $value);
    }

    /**
     * get the highest occurrence entry for a name
     *
     * @param $name
     *
     * @return array
     * @throws \LesserPhp\Exception\GeneralException
     */
    protected function get($name)
    {
        $current = $this->env;

        // track scope to evaluate
        $scopeSecondary = [];

        $isArguments = $name === $this->vPrefix . 'arguments';
        while ($current) {
            if ($isArguments && count($current->getArguments()) > 0) {
                return ['list', ' ', $current->getArguments()];
            }

            if (isset($current->getStore()[$name])) {
                return $current->getStore()[$name];
            }
            // has secondary scope?
            if (isset($current->storeParent)) {
                $scopeSecondary[] = $current->storeParent;
            }

            if ($current->getParent() !== null) {
                $current = $current->getParent();
            } else {
                $current = null;
            }
        }

        while (count($scopeSecondary)) {
            // pop one off
            $current = array_shift($scopeSecondary);
            while ($current) {
                if ($isArguments && isset($current->arguments)) {
                    return ['list', ' ', $current->arguments];
                }

                if (isset($current->store[$name])) {
                    return $current->store[$name];
                }

                // has secondary scope?
                if (isset($current->storeParent)) {
                    $scopeSecondary[] = $current->storeParent;
                }

                if (isset($current->parent)) {
                    $current = $current->parent;
                } else {
                    $current = null;
                }
            }
        }

        throw new GeneralException("variable $name is undefined");
    }

    /**
     * inject array of unparsed strings into environment as variables
     *
     * @param string[] $args
     *
     * @throws \LesserPhp\Exception\GeneralException
     */
    protected function injectVariables(array $args)
    {
        $this->pushEnv($this->env);
        $parser = new Parser($this, __METHOD__);
        foreach ($args as $name => $strValue) {
            if ($name[0] !== '@') {
                $name = '@' . $name;
            }
            $parser->count = 0;
            $parser->buffer = (string) $strValue;
            if (!$parser->propertyValue($value)) {
                throw new GeneralException("failed to parse passed in variable $name: $strValue");
            }

            $this->set($name, $value);
        }
    }

    /**
     * @param string $string
     * @param string $name
     *
     * @return string
     * @throws \LesserPhp\Exception\GeneralException
     */
    public function compile($string, $name = null)
    {
        $locale = setlocale(LC_NUMERIC, 0);
        setlocale(LC_NUMERIC, 'C');

        $this->parser = $this->makeParser($name);
        $root = $this->parser->parse($string);

        $this->env = null;
        $this->scope = null;
        $this->allParsedFiles = [];

        $this->formatter = $this->newFormatter();

        if (!empty($this->registeredVars)) {
            $this->injectVariables($this->registeredVars);
        }

        $this->sourceParser = $this->parser; // used for error messages
        $this->compileBlock($root);

        ob_start();
        $this->formatter->block($this->scope);
        $out = ob_get_clean();
        setlocale(LC_NUMERIC, $locale);

        return $out;
    }

    /**
     * @param string $fname
     * @param string $outFname
     *
     * @return int|string
     * @throws \LesserPhp\Exception\GeneralException
     */
    public function compileFile($fname, $outFname = null)
    {
        if (!is_readable($fname)) {
            throw new GeneralException('load error: failed to find ' . $fname);
        }

        $pi = pathinfo($fname);

        $oldImport = $this->importDirs;

        $this->importDirs[] = $pi['dirname'] . '/';

        $this->addParsedFile($fname);

        $out = $this->compile(file_get_contents($fname), $fname);

        $this->importDirs = $oldImport;

        if ($outFname !== null) {
            return file_put_contents($outFname, $out);
        }

        return $out;
    }

    /**
     * Based on explicit input/output files does a full change check on cache before compiling.
     *
     * @param string  $in
     * @param string  $out
     * @param boolean $force
     *
     * @return string Compiled CSS results
     * @throws GeneralException
     */
    public function checkedCachedCompile($in, $out, $force = false)
    {
        if (!is_file($in) || !is_readable($in)) {
            throw new GeneralException('Invalid or unreadable input file specified.');
        }
        if (is_dir($out) || !is_writable(file_exists($out) ? $out : dirname($out))) {
            throw new GeneralException('Invalid or unwritable output file specified.');
        }

        $outMeta = $out . '.meta';
        $metadata = null;
        if (!$force && is_file($outMeta)) {
            $metadata = unserialize(file_get_contents($outMeta));
        }

        $output = $this->cachedCompile($metadata ?: $in);

        if (!$metadata || $metadata['updated'] != $output['updated']) {
            $css = $output['compiled'];
            unset($output['compiled']);
            file_put_contents($out, $css);
            file_put_contents($outMeta, serialize($output));
        } else {
            $css = file_get_contents($out);
        }

        return $css;
    }

    /**
     * compile only if changed input has changed or output doesn't exist
     *
     * @param string $in
     * @param string $out
     *
     * @return bool
     * @throws \LesserPhp\Exception\GeneralException
     */
    public function checkedCompile($in, $out)
    {
        if (!is_file($out) || filemtime($in) > filemtime($out)) {
            $this->compileFile($in, $out);

            return true;
        }

        return false;
    }

    /**
     * Execute lessphp on a .less file or a lessphp cache structure
     *
     * The lessphp cache structure contains information about a specific
     * less file having been parsed. It can be used as a hint for future
     * calls to determine whether or not a rebuild is required.
     *
     * The cache structure contains two important keys that may be used
     * externally:
     *
     * compiled: The final compiled CSS
     * updated: The time (in seconds) the CSS was last compiled
     *
     * The cache structure is a plain-ol' PHP associative array and can
     * be serialized and unserialized without a hitch.
     *
     * @param mixed $in    Input
     * @param bool  $force Force rebuild?
     *
     * @return array lessphp cache structure
     * @throws \LesserPhp\Exception\GeneralException
     */
    public function cachedCompile($in, $force = false)
    {
        // assume no root
        $root = null;

        if (is_string($in)) {
            $root = $in;
        } elseif (is_array($in) && isset($in['root'])) {
            if ($force || !isset($in['files'])) {
                // If we are forcing a recompile or if for some reason the
                // structure does not contain any file information we should
                // specify the root to trigger a rebuild.
                $root = $in['root'];
            } elseif (isset($in['files']) && is_array($in['files'])) {
                foreach ($in['files'] as $fname => $ftime) {
                    if (!file_exists($fname) || filemtime($fname) > $ftime) {
                        // One of the files we knew about previously has changed
                        // so we should look at our incoming root again.
                        $root = $in['root'];
                        break;
                    }
                }
            }
        } else {
            // TODO: Throw an exception? We got neither a string nor something
            // that looks like a compatible lessphp cache structure.
            return null;
        }

        if ($root !== null) {
            // If we have a root value which means we should rebuild.
            $out = [];
            $out['root'] = $root;
            $out['compiled'] = $this->compileFile($root);
            $out['files'] = $this->allParsedFiles;
            $out['updated'] = time();

            return $out;
        } else {
            // No changes, pass back the structure
            // we were given initially.
            return $in;
        }
    }

    /**
     * parse and compile buffer
     * This is deprecated
     *
     * @param null $str
     * @param null $initialVariables
     *
     * @return int|string
     * @throws \LesserPhp\Exception\GeneralException
     * @deprecated
     */
    public function parse($str = null, $initialVariables = null)
    {
        if (is_array($str)) {
            $initialVariables = $str;
            $str = null;
        }

        $oldVars = $this->registeredVars;
        if ($initialVariables !== null) {
            $this->setVariables($initialVariables);
        }

        if ($str === null) {
            throw new GeneralException('nothing to parse');
        } else {
            $out = $this->compile($str);
        }

        $this->registeredVars = $oldVars;

        return $out;
    }

    /**
     * @param string $name
     *
     * @return \LesserPhp\Parser
     */
    protected function makeParser($name)
    {
        $parser = new Parser($this, $name);
        $parser->setWriteComments($this->preserveComments);

        return $parser;
    }

    /**
     * @param string $name
     */
    public function setFormatter($name)
    {
        $this->formatterName = $name;
    }

    public function setFormatterClass($formatter)
    {
        $this->formatter = $formatter;
    }

    /**
     * @return \LesserPhp\Formatter\FormatterInterface
     */
    protected function newFormatter()
    {
        $className = 'Lessjs';
        if (!empty($this->formatterName)) {
            if (!is_string($this->formatterName)) {
                return $this->formatterName;
            }
            $className = $this->formatterName;
        }

        $className = '\LesserPhp\Formatter\\' . $className;

        return new $className;
    }

    /**
     * @param bool $preserve
     */
    public function setPreserveComments($preserve)
    {
        $this->preserveComments = $preserve;
    }

    /**
     * @param string   $name
     * @param callable $func
     */
    public function registerFunction($name, callable $func)
    {
        $this->libFunctions[$name] = $func;
    }

    /**
     * @param string $name
     */
    public function unregisterFunction($name)
    {
        unset($this->libFunctions[$name]);
    }

    /**
     * @param array $variables
     */
    public function setVariables(array $variables)
    {
        $this->registeredVars = array_merge($this->registeredVars, $variables);
    }

    /**
     * @param $name
     */
    public function unsetVariable($name)
    {
        unset($this->registeredVars[$name]);
    }

    /**
     * @param string[] $dirs
     */
    public function setImportDirs(array $dirs)
    {
        $this->importDirs = $dirs;
    }

    /**
     * @param string $dir
     */
    public function addImportDir($dir)
    {
        $this->importDirs[] = $dir;
    }

    /**
     * @return string[]
     */
    public function getImportDirs()
    {
        return $this->importDirs;
    }

    /**
     * @param string $file
     */
    public function addParsedFile($file)
    {
        $this->allParsedFiles[realpath($file)] = filemtime($file);
    }

    /**
     * Uses the current value of $this->count to show line and line number
     *
     * @param string $msg
     *
     * @throws GeneralException
     */
    public function throwError($msg = null)
    {
        if ($this->sourceLoc >= 0) {
            $this->sourceParser->throwError($msg, $this->sourceLoc);
        }
        throw new GeneralException($msg);
    }

    /**
     * compile file $in to file $out if $in is newer than $out
     * returns true when it compiles, false otherwise
     *
     * @param                          $in
     * @param                          $out
     * @param \LesserPhp\Compiler|null $less
     *
     * @return bool
     * @throws \LesserPhp\Exception\GeneralException
     */
    public static function ccompile($in, $out, Compiler $less = null)
    {
        if ($less === null) {
            $less = new self;
        }

        return $less->checkedCompile($in, $out);
    }

    /**
     * @param                          $in
     * @param bool                     $force
     * @param \LesserPhp\Compiler|null $less
     *
     * @return array
     * @throws \LesserPhp\Exception\GeneralException
     */
    public static function cexecute($in, $force = false, Compiler $less = null)
    {
        if ($less === null) {
            $less = new self;
        }

        return $less->cachedCompile($in, $force);
    }

    /**
     * prefix of abstract properties
     *
     * @return string
     */
    public function getVPrefix()
    {
        return $this->vPrefix;
    }

    /**
     * prefix of abstract blocks
     *
     * @return string
     */
    public function getMPrefix()
    {
        return $this->mPrefix;
    }

    /**
     * @return string
     */
    public function getParentSelector()
    {
        return $this->parentSelector;
    }

    /**
     * @param int $numberPresicion
     */
    protected function setNumberPrecision($numberPresicion = null)
    {
        $this->numberPrecision = $numberPresicion;
    }

    /**
     * @return \LesserPhp\Library\Coerce
     */
    protected function getCoerce()
    {
        return $this->coerce;
    }

    public function setImportDisabled()
    {
        $this->importDisabled = true;
    }

    /**
     * @return bool
     */
    public function isImportDisabled()
    {
        return $this->importDisabled;
    }
}
