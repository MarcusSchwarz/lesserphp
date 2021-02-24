<?php
namespace Test\Base;

/**
 * lesserphp
 * https://www.maswaba.de/lesserphp
 *
 * LESS CSS compiler, adapted from http://lesscss.org
 *
 * Copyright 2013, Leaf Corcoran <leafot@gmail.com>
 * Copyright 2016, Marcus Schwarz <github@maswaba.de>
 * Copyright 2017, Stefan Pöhner <github@poe-php.de>
 * Licensed under MIT or GPLv3, see LICENSE
 *
 * @package LesserPhp
 */
use LesserPhp\Compiler;
use LesserPhp\Exception\GeneralException;
use LesserPhp\Formatter\FormatterInterface;

class CompilerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @param array     $input
     * @param           $expected
     * @param bool|null $compressColors
     *
     * @dataProvider valueProvider
     */
    public function testCompileValue(array $input, $expected, $compressColors = null)
    {
        $formatter = $this->createMock(FormatterInterface::class);
        $formatter->expects($this->any())->method('getCompressColors')->willReturn($compressColors);

        $subject = new Compiler();
        $subject->setFormatterClass($formatter);
        $compiled = $subject->compileValue($input);
        $this->assertSame($expected, $compiled);
    }

    public function valueProvider()
    {
        return [
            [['list', ' ', [['number', '1', 'px'], ['keyword', 'solid'], ['raw_color', '#fff']]], '1px solid #fff'],
            [['raw_color', '#ffffff'], '#ffffff', false],
            [['raw_color', '#ffffff'], '#fff', true],
            [['keyword', 'word'], 'word'],
            [['number', '12', 'px'], '12px'],
            [['number', '10.1', '%'], '10.1%'],
            [['string', ':', ['foobar']], ':foobar:'],
            [['string', ':', ['foo', 'bar']], ':foobar:'],
            [['string', ' ', [['raw_color', '#fff'], 'solid']], ' #fffsolid '],
            [['color', 10.1, 11, 12], '#0a0b0c', false],
            [['color', 0, 0, 0, 1], '#000000', false],
            [['color', 0, 0, 0, 1], '#000', true],
            [['color', 10, 11, 12, .3], 'rgba(10,11,12,0.3)'],
            [['function', 'calc', ['raw_color', '#fff']], 'calc(#fff)'],
        ];
    }

    public function testUnknownType()
    {
        $this->expectException(GeneralException::class);
        $this->expectExceptionMessage('unknown value type: something');

        $subject = new Compiler();
        $subject->compileValue(['something', 1]);
    }
}
