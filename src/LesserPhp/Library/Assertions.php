<?php

namespace LesserPhp\Library;

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
 */
class Assertions
{
    private $coerce;

    /**
     * Assertions constructor.
     *
     * @param \LesserPhp\Library\Coerce $coerce
     */
    public function __construct(Coerce $coerce)
    {
        $this->coerce = $coerce;
    }

    /**
     * @param array  $value
     * @param string $error
     *
     * @return array
     * @throws \LesserPhp\Exception\GeneralException
     */
    public function assertColor(array $value, $error = 'expected color value')
    {
        $color = $this->coerce->coerceColor($value);
        if ($color === null) {
            throw new GeneralException($error);
        }

        return $color;
    }

    /**
     * @param array  $value
     * @param string $error
     *
     * @return mixed
     * @throws \LesserPhp\Exception\GeneralException
     */
    public function assertNumber(array $value, $error = 'expecting number')
    {
        if ($value[0] === 'number') {
            return $value[1];
        }
        throw new GeneralException($error);
    }

    /**
     * @param array  $value
     * @param int    $expectedArgs
     * @param string $name
     *
     * @return array
     * @throws \LesserPhp\Exception\GeneralException
     */
    public function assertArgs(array $value, $expectedArgs, $name = '')
    {
        if ($expectedArgs === 1) {
            return $value;
        } else {
            if ($value[0] !== 'list' || $value[1] !== ',') {
                throw new GeneralException('expecting list');
            }
            $values = $value[2];
            $numValues = count($values);
            if ($expectedArgs !== $numValues) {
                if ($name) {
                    $name .= ': ';
                }
                throw new GeneralException("${name}expecting $expectedArgs arguments, got $numValues");
            }

            return $values;
        }
    }

    /**
     * @param array  $value
     * @param int    $expectedMinArgs
     * @param string $name
     *
     * @return array
     * @throws \LesserPhp\Exception\GeneralException
     */
    public function assertMinArgs(array $value, $expectedMinArgs, $name = '')
    {
        if ($value[0] !== 'list' || $value[1] !== ',') {
            throw new GeneralException('expecting list');
        }
        $values = $value[2];
        $numValues = count($values);
        if ($expectedMinArgs > $numValues) {
            if ($name) {
                $name .= ': ';
            }

            throw new GeneralException("${name}expecting at least $expectedMinArgs arguments, got $numValues");
        }

        return $values;
    }
}
