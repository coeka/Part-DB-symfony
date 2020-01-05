<?php

declare(strict_types=1);

/**
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 * Copyright (C) 2019 Jan Böhmer (https://github.com/jbtronics)
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA
 */

namespace App\Tests\Services;

use App\Entity\Parts\MeasurementUnit;
use App\Services\AmountFormatter;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AmountFormatterTest extends WebTestCase
{
    /**
     * @var AmountFormatter
     */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp(); // TODO: Change the autogenerated stub

        //Get an service instance.
        self::bootKernel();
        $this->service = self::$container->get(AmountFormatter::class);
    }

    public function testFormatWithoutUnit(): void
    {
        $this->assertSame('2', $this->service->format(2.321));
        $this->assertSame('1002', $this->service->format(1002.356));
        $this->assertSame('1000454', $this->service->format(1000454.0));
        $this->assertSame('0', $this->service->format(0.01));
        $this->assertSame('0', $this->service->format(0));
    }

    public function testInvalidInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->format('test');
    }

    public function testFormatUnitWithoutSI(): void
    {
        $meters = new MeasurementUnit();
        $meters->setIsInteger(false)->setUseSIPrefix(false)->setUnit('m');

        $this->assertSame('0.32 m', $this->service->format(0.3245, $meters));
        $this->assertSame('10003.56 m', $this->service->format(10003.556, $meters));
        $this->assertSame('0.00 m', $this->service->format(0.0004, $meters));
    }

    public function testFormatUnitWithSI(): void
    {
        $meters = new MeasurementUnit();
        $meters->setIsInteger(false)->setUseSIPrefix(true)->setUnit('m');

        $this->assertSame('0.32 m', $this->service->format(0.3245, $meters));
        $this->assertSame('12.32 m', $this->service->format(12.323, $meters));
        $this->assertSame('120.32 km', $this->service->format(120320.45, $meters));

        $this->assertSame('0.32 mm', $this->service->format(0.00032, $meters));
    }

    public function testFormatMoreDigits(): void
    {
        $this->assertSame('12.12345', $this->service->format(12.1234532, null, ['is_integer' => false, 'decimals' => 5]));
        $this->assertSame('12.1', $this->service->format(12.1234532, null, ['is_integer' => false, 'decimals' => 1]));
    }

    public function testFormatOptionsOverride(): void
    {
        $meters = new MeasurementUnit();
        $meters->setIsInteger(false)->setUseSIPrefix(true)->setUnit('m');

        $this->assertSame('12.32', $this->service->format(12.323, $meters, ['unit' => '']));
        $this->assertSame('12002.32 m', $this->service->format(12002.32, $meters, ['show_prefix' => false]));
        $this->assertSame('123 m', $this->service->format(123.234, $meters, ['is_integer' => true]));
    }
}
