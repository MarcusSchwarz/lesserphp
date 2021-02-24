<?php

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
class ErrorHandlingTest extends \PHPUnit\Framework\TestCase
{

    /**
     * @var LesserPhp\Compiler
     */
    private $less;

    public function setUp() : void
    {
        $this->less = new \LesserPhp\Compiler();
    }

    public function compile()
    {
        $source = implode("\n", func_get_args());

        return $this->less->compile($source);
    }

    public function testRequiredParametersMissing()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('.parametric-mixin is undefined');
        $this->compile(
            '.parametric-mixin (@a, @b) { a: @a; b: @b; }',
            '.selector { .parametric-mixin(12px); }'
        );
    }

    public function testTooManyParameters()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('.parametric-mixin is undefined');
        $this->compile(
            '.parametric-mixin (@a, @b) { a: @a; b: @b; }',
            '.selector { .parametric-mixin(12px, 13px, 14px); }'
        );
    }

    public function testRequiredArgumentsMissing()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('unrecognised input');
        $this->compile('.selector { rule: e(); }');
    }

    public function testVariableMissing()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('variable @missing is undefined');
        $this->compile('.selector { rule: @missing; }');
    }

    public function testMixinMissing()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('.missing-mixin is undefined');
        $this->compile('.selector { .missing-mixin; }');
    }

    public function testGuardUnmatchedValue()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('.flipped is undefined');
        $this->compile(
            '.flipped(@x) when (@x =< 10) { rule: value; }',
            '.selector { .flipped(12); }'
        );
    }

    public function testGuardUnmatchedType()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('.colors-only is undefined');
        $this->compile(
            '.colors-only(@x) when (iscolor(@x)) { rule: value; }',
            '.selector { .colors-only("string value"); }'
        );
    }

    public function testMinNoArguments()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('expecting at least 1 arguments, got 0');
        $this->compile(
            '.selector{ min: min(); }'
        );
    }

    public function testMaxNoArguments()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('expecting at least 1 arguments, got 0');
        $this->compile(
            '.selector{ max: max(); }'
        );
    }

    public function testMaxIncompatibleTypes()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot convert % to px');
        $this->compile(
            '.selector{ max: max( 10px, 5% ); }'
        );
    }

    public function testConvertIncompatibleTypes()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot convert px to s');
        $this->compile(
            '.selector{ convert: convert( 10px, s ); }'
        );
    }
}
